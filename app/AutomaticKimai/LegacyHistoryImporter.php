<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Lossless, resumable legacy state import. The original Python databases are
 * opened read-only; the PHP review candidates are NEVER overwritten.
 *
 * A successful import is NOT a publisher cutover or a new billing approval.
 * Run on consistent Python snapshots and repeat after freezing the old writer.
 */
final class LegacyHistoryImporter
{
    private const LEDGER_TABLES=[
        'sessions'=>['session_id','remote_id','remote_snapshot_json','evidence_digest','updated_at'],
        'intents'=>['intent_id','session_id','action','state','remote_id','payload_json','updated_at'],
        'human_declarations'=>['session_id','declared_utc','local_start','local_end','category','user_id','basis','payroll_approved'],
    ];
    private const REVIEW_COLUMNS=[
        'id','day','start','end','status','proposed_json','source_digest',
        'approval_json','remote_id','reason','updated_utc','created_utc',
    ];
    private const PUBLICATION_COLUMNS=[
        'idempotency_key','approval_id','state','payload_json','remote_id',
        'reason_code','created_utc','updated_utc',
    ];

    public function import(string $legacyCache,string $target,int $userId,string $profileId,
                           ?callable $verifyRemoteExtension=null): array
    {
        if (!str_starts_with($legacyCache,'/')||!str_starts_with($target,'/')||
            $legacyCache==='/'||$target==='/'||!is_dir($legacyCache)||!is_dir($target)||
            is_link($legacyCache)||is_link($target)||$userId<1||$profileId==='') {
            throw new RuntimeException('Import requires private absolute directories and identity');
        }

        // Same lock as SyncEngine and manual submit. Do not import while a
        // shadow cycle is running or another importer is changing local state.
        $lockFile=rtrim($target,'/').'/daily.lock';
        if (is_link($lockFile))throw new RuntimeException('Linked import lock forbidden');
        $lock=fopen($lockFile,'c');
        if (!$lock)throw new RuntimeException('Cannot open migration lock');
        chmod($lockFile,0600);
        if (!flock($lock,LOCK_EX|LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another PHP cycle is active');
        }
        try {
            return $this->lockedImport(rtrim($legacyCache,'/'),rtrim($target,'/'),
                $userId,$profileId,$verifyRemoteExtension);
        } finally {
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    private function lockedImport(string $source,string $target,int $userId,string $profileId,
                                  ?callable $verifyRemoteExtension): array
    {
        $oldLedger=$this->openSource($source.'/cooperator_agent_state/ledger.sqlite3');
        $oldQueue=$this->openSource($source.'/session_review_queue.sqlite3');
        $oldOutbox=$this->openSource($source.'/kimai_publish_outbox.sqlite3');
        $expected=CanonicalJson::digest(['user'=>$userId,'profile'=>$profileId],false);
        $actual=$oldLedger->query("SELECT value FROM context WHERE key='identity'")->fetchColumn();
        if (!is_string($actual)||!hash_equals($expected,$actual)) {
            throw new RuntimeException('Python ledger identity does not match this PHP worker');
        }

        // Validate all source tables before modifying any destination.
        $sourceRows=[];
        foreach (self::LEDGER_TABLES as $table=>$columns) {
            $sourceRows[$table]=$this->rows($oldLedger,$table,$columns);
        }
        $reviewRows=$this->rows($oldQueue,'sessions',self::REVIEW_COLUMNS);
        $publications=$this->rows($oldOutbox,'publications',self::PUBLICATION_COLUMNS);
        foreach (['sessions'=>'remote_snapshot_json','intents'=>'payload_json'] as $table=>$field) {
            foreach ($sourceRows[$table] as $row) {
                $this->validateJson((string)$row[$field]);
            }
        }
        foreach ($reviewRows as $row) {
            $this->validateJson((string)$row['proposed_json']);
            if ($row['approval_json']!==null)$this->validateJson((string)$row['approval_json']);
        }
        foreach ($publications as $row)$this->validateJson((string)$row['payload_json']);
        // Ambiguous outcomes must never become publishable through import.
        $unresolved=array_filter($sourceRows['intents'],
            static fn($r)=>!in_array($r['state'],['confirmed','superseded','cancelled','rejected'],true));
        $unresolvedPublications=array_filter($publications,
            static fn($r)=>!in_array($r['state'],[
                'confirmed','confirmed_amendment','confirmed_preview',
                'confirmed_rounded','superseded'
            ],true));

        $ledger=new Ledger($target.'/ledger.sqlite3',$userId,$profileId);
        $ledger->close();
        $queue=new ReviewQueue($target.'/review_queue.sqlite3');
        $queue->close();
        $newLedger=$this->openTarget($target.'/ledger.sqlite3');
        $newQueue=$this->openTarget($target.'/review_queue.sqlite3');
        $newLedger->exec('CREATE TABLE IF NOT EXISTS legacy_publications(
            idempotency_key TEXT PRIMARY KEY,approval_id TEXT NOT NULL UNIQUE,
            state TEXT NOT NULL,payload_json TEXT NOT NULL,remote_id INTEGER,
            reason_code TEXT,created_utc TEXT NOT NULL,updated_utc TEXT NOT NULL)');
        $newQueue->exec('CREATE TABLE IF NOT EXISTS legacy_review_sessions(
            id TEXT PRIMARY KEY,day TEXT NOT NULL,start TEXT NOT NULL,end TEXT NOT NULL,
            status TEXT NOT NULL,proposed_json TEXT NOT NULL,source_digest TEXT NOT NULL,
            approval_json TEXT,remote_id INTEGER,reason TEXT,
            updated_utc TEXT NOT NULL,created_utc TEXT NOT NULL)');
        // Preserve previous snapshots before refreshing an already-imported
        // mutable legacy row. These tables are private, append-only evidence.
        $newLedger->exec('CREATE TABLE IF NOT EXISTS legacy_session_versions(
            id TEXT NOT NULL,digest TEXT NOT NULL,row_json TEXT NOT NULL,
            archived_utc TEXT NOT NULL,PRIMARY KEY(id,digest))');
        $newQueue->exec('CREATE TABLE IF NOT EXISTS legacy_review_versions(
            id TEXT NOT NULL,digest TEXT NOT NULL,row_json TEXT NOT NULL,
            archived_utc TEXT NOT NULL,PRIMARY KEY(id,digest))');

        // All source/target conflicts are rejected rather than silently
        // replacing a newer PHP record or inventing a merged approval.
        $this->validateImport($newLedger,'sessions',self::LEDGER_TABLES['sessions'],
            $sourceRows['sessions'],$verifyRemoteExtension);
        $this->validateImport($newLedger,'intents',self::LEDGER_TABLES['intents'],$sourceRows['intents']);
        $this->validateImport($newLedger,'human_declarations',self::LEDGER_TABLES['human_declarations'],$sourceRows['human_declarations']);
        $this->validateImport($newLedger,'legacy_publications',self::PUBLICATION_COLUMNS,$publications);
        $this->validateImport($newQueue,'legacy_review_sessions',self::REVIEW_COLUMNS,$reviewRows);

        $imported=[];
        $newLedger->exec('BEGIN IMMEDIATE');
        try {
            foreach (self::LEDGER_TABLES as $table=>$columns) {
                $imported[$table]=$this->insertMissing($newLedger,$table,$columns,$sourceRows[$table]);
            }
            $imported['legacy_publications']=$this->insertMissing($newLedger,
                'legacy_publications',self::PUBLICATION_COLUMNS,$publications);
            $updatedLedger=$this->advanceVerifiedRows($newLedger,'sessions',
                self::LEDGER_TABLES['sessions'],$sourceRows['sessions']);
            $newLedger->commit();
        } catch (Throwable $e) {
            if ($newLedger->inTransaction())$newLedger->rollBack();
            throw $e;
        }
        // Independent SQLite files: if a crash occurs between commits the
        // next invocation finishes remaining rows without duplicating them.
        $newQueue->exec('BEGIN IMMEDIATE');
        try {
            $imported['legacy_review_sessions']=$this->insertMissing($newQueue,
                'legacy_review_sessions',self::REVIEW_COLUMNS,$reviewRows);
            $updatedReview=$this->advanceVerifiedRows($newQueue,'legacy_review_sessions',
                self::REVIEW_COLUMNS,$reviewRows);
            $newQueue->commit();
        } catch (Throwable $e) {
            if ($newQueue->inTransaction())$newQueue->rollBack();
            throw $e;
        }

        $audioReport=null;
        $oldAudio=$source.'/telegram_audio_metadata.sqlite3';
        if (is_file($oldAudio)||is_link($oldAudio)) {
            // Imported separately and idempotently. A crash here is safe:
            // rerunning imports only missing samples, never overwrite flags.
            $audioReport=(new LegacyAudioImporter())->import(
                $oldAudio,$target.'/telegram_audio_metadata.sqlite3');
        }
        return [
            'mode'=>'local_history_import',
            'python_databases_modified'=>false,'kimai_writes'=>0,
            'php_live_review_candidates_modified'=>false,
            'source_counts'=>[
                'sessions'=>count($sourceRows['sessions']),
                'intents'=>count($sourceRows['intents']),
                'human_declarations'=>count($sourceRows['human_declarations']),
                'legacy_publications'=>count($publications),
                'legacy_review_sessions'=>count($reviewRows),
            ],
            'newly_imported'=>$imported,
            'updated_history'=>[
                'sessions'=>$updatedLedger,
                'legacy_review_sessions'=>$updatedReview,
            ],
            'audio_metadata'=>$audioReport,
            'legacy_unknown_intents'=>count($unresolved),
            'legacy_unknown_publications'=>count($unresolvedPublications),
            'ready_for_cutover'=>false,
            'requires_final_snapshot_after_old_writer_stops'=>true,
        ];
    }

    private function openSource(string $path): PDO
    {
        if (!is_file($path)||is_link($path)||is_link(dirname($path))||
            (DIRECTORY_SEPARATOR==='/'&&(fileperms($path)&0077)!==0)) {
            throw new RuntimeException('Legacy source must exist and be private');
        }
        $pdo=new PDO('sqlite:file:'.$path.'?mode=ro',null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>20,
        ]);
        $pdo->exec('PRAGMA query_only=ON');
        if ($pdo->query('PRAGMA quick_check')->fetchColumn()!=='ok') {
            throw new RuntimeException('Legacy source integrity check failed');
        }
        return $pdo;
    }

    private function openTarget(string $path): PDO
    {
        return new PDO('sqlite:'.$path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>20,
        ]);
    }

    private function rows(PDO $db,string $table,array $columns): array
    {
        $available=array_column($db->query('PRAGMA table_info("'.$table.'")')->fetchAll(),'name');
        if ($available===[]||array_diff($columns,$available)!==[]) {
            throw new RuntimeException('Legacy schema mismatch');
        }
        return $db->query('SELECT '.implode(',',$columns).' FROM "'.$table.'"')->fetchAll();
    }

    private function validateJson(string $raw): void
    {
        if (!is_array(json_decode($raw,true,512,JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Legacy JSON must decode to an array or object');
        }
    }

    private function validateImport(PDO $target,string $table,array $columns,array $rows,
                                    ?callable $verifyRemoteExtension=null): void
    {
        $primary=$columns[0];
        $lookup=$target->prepare('SELECT '.implode(',',$columns).' FROM "'.$table.'" WHERE "'.$primary.'"=?');
        foreach ($rows as $source) {
            $lookup->execute([$source[$primary]]);
            $existing=$lookup->fetch();
            if ($existing===false||$this->sameValues($existing,$source,$columns))continue;
            if ($table==='sessions' && $this->verifiedSessionAdvance(
                $existing,$source,$verifyRemoteExtension))continue;
            if ($table==='legacy_review_sessions'&&
                self::safeReviewAdvance($existing,$source))continue;
            throw new RuntimeException('Existing PHP history conflicts with Python snapshot');
        }
    }

    /** A confirmed Kimai record may only extend, never change identity. */
    private function verifiedSessionAdvance(array $before,array $after,
                                            ?callable $verifyRemote): bool
    {
        if ($verifyRemote===null||
            (string)$before['remote_id']!==(string)$after['remote_id']||
            (int)$after['remote_id']<1||
            !self::nondecreasingTime($before['updated_at'],$after['updated_at']))return false;
        try {
            $old=json_decode((string)$before['remote_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $new=json_decode((string)$after['remote_snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($old)||!is_array($new)||
                ($new['id']??null)!==(int)$after['remote_id'])return false;
            foreach (['id','begin','user','project','activity','billable',
                      'exported','description'] as $field) {
                if (!array_key_exists($field,$old)||!array_key_exists($field,$new)||
                    $old[$field]!==$new[$field])return false;
            }
            $oldEnd=TimeMath::parse((string)$old['end']);
            $newEnd=TimeMath::parse((string)$new['end']);
            if ($newEnd<$oldEnd||!is_int($old['duration']??null)||
                !is_int($new['duration']??null)||
                $new['duration']<$old['duration'])return false;
            return $verifyRemote((int)$after['remote_id'],$new)===true;
        }catch (Throwable) {
            return false;
        }
    }

    /** Only unapproved hypotheses may evolve; frozen approvals never do. */
    private static function safeReviewAdvance(array $before,array $after): bool
    {
        if (!in_array($before['status']??null,['draft','conflict','covered'],true)||
            ($before['status']??null)!==($after['status']??null)||
            $before['approval_json']!==null||$after['approval_json']!==null||
            !self::nondecreasingTime($before['updated_utc'],$after['updated_utc']))return false;
        foreach (['id','day','start','created_utc','remote_id'] as $fixed) {
            if ((string)($before[$fixed]??'')!==(string)($after[$fixed]??''))return false;
        }
        return true;
    }

    private static function nondecreasingTime(string $before,string $after): bool
    {
        $older=strtotime($before);$newer=strtotime($after);
        return $older!==false&&$newer!==false&&$newer>=$older;
    }

    /** Record earlier versions before replacing a verified mutable record. */
    private function advanceVerifiedRows(PDO $db,string $table,array $columns,array $sourceRows): int
    {
        if (!in_array($table,['sessions','legacy_review_sessions'],true)) {
            throw new RuntimeException('Unsafe legacy table update');
        }
        $primary=$columns[0];
        $lookup=$db->prepare('SELECT '.implode(',',$columns).' FROM "'.$table.'" WHERE "'.$primary.'"=?');
        $updates=array_slice($columns,1);
        $sql='UPDATE "'.$table.'" SET '.implode(',',array_map(
            static fn($column)=>'"'.$column.'"=?',$updates)).
            ' WHERE "'.$primary.'"=?';
        $update=$db->prepare($sql);
        $versionTable=$table==='sessions'?'legacy_session_versions':'legacy_review_versions';
        $saveVersion=$db->prepare('INSERT OR IGNORE INTO "'.$versionTable.'"
            (id,digest,row_json,archived_utc) VALUES (?,?,?,?)');
        $advanced=0;
        foreach ($sourceRows as $row) {
            $lookup->execute([$row[$primary]]);
            $before=$lookup->fetch();
            if ($before===false||$this->sameValues($before,$row,$columns))continue;
            $changed=array_values(array_filter($columns,
                static fn($key)=>(string)($before[$key]??'')!==(string)($row[$key]??'')));
            if (array_diff($changed,['updated_utc'])!==[]) {
                $saveVersion->execute([
                    (string)$before[$primary],
                    CanonicalJson::digest($before,false),
                    CanonicalJson::encode($before),
                    gmdate('c'),
                ]);
            }
            $update->execute([
                ...array_map(static fn($field)=>$row[$field],$updates),$row[$primary],
            ]);
            if ($update->rowCount()!==1)throw new RuntimeException('Legacy update race');
            $advanced++;
        }
        return $advanced;
    }

    private function sameValues(array $a,array $b,array $columns): bool
    {
        foreach ($columns as $key) {
            if ((string)($a[$key]??'')!==(string)($b[$key]??''))return false;
        }
        return true;
    }

    private function insertMissing(PDO $db,string $table,array $columns,array $rows): int
    {
        $primary=$columns[0];
        $exists=$db->prepare('SELECT 1 FROM "'.$table.'" WHERE "'.$primary.'"=?');
        $sql='INSERT INTO "'.$table.'" ('.implode(',',$columns).
            ') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
        $stmt=$db->prepare($sql);
        $inserted=0;
        foreach ($rows as $row) {
            $exists->execute([$row[$primary]]);
            if ($exists->fetchColumn()!==false)continue;
            // A collision on a secondary UNIQUE index (e.g. remote_id)
            // is a fatal migration conflict, never a silently skipped row.
            $stmt->execute(array_map(static fn($col)=>$row[$col],$columns));
            $inserted++;
        }
        return $inserted;
    }
}
