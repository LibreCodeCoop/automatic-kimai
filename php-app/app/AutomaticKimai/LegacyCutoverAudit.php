<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use PDO;
use RuntimeException;

/**
 * Read-only cross-runtime migration inventory. A successful inventory is
 * evidence of known local state, never authorization to cut over publishers.
 *
 * Only aggregated counts and machine-readable blocker codes leave this class.
 * Database identifiers, payloads and personal descriptions remain private.
 */
final class LegacyCutoverAudit
{
    /** @return array<string,mixed> */
    public function inspect(string $legacyCache,string $phpState): array
    {
        $legacy=rtrim($legacyCache,'/');
        $target=rtrim($phpState,'/');
        if ($legacy===''||$target===''||$legacy[0]!=='/'||$target[0]!=='/'||
            is_link($legacy)||is_link($target)||
            !is_dir($legacy)||!is_dir($target)) {
            throw new RuntimeException('Audit requires real absolute private directories');
        }
        $sources=[
            'python_queue'=>$legacy.'/session_review_queue.sqlite3',
            'python_outbox'=>$legacy.'/kimai_publish_outbox.sqlite3',
            'python_ledger'=>$legacy.'/cooperator_agent_state/ledger.sqlite3',
            'php_queue'=>$target.'/review_queue.sqlite3',
            'php_ledger'=>$target.'/ledger.sqlite3',
        ];
        $db=[];
        foreach ($sources as $key=>$filename) {
            $db[$key]=$this->openPrivate($filename);
        }
        // The legacy audio observer writes timestamps/stream metadata, not
        // recordings. An audio history is a parity requirement if present.
        $audioFile=$legacy.'/telegram_audio_metadata.sqlite3';
        $audioSampleCount=0;
        if (is_file($audioFile)||is_link($audioFile)) {
            $audio=$this->openPrivate($audioFile);
            $columns=array_column($audio->query('PRAGMA table_info("samples")')->fetchAll(),'name');
            if ($columns===[])throw new RuntimeException('Legacy audio metadata schema unavailable');
            $audioSampleCount=(int)$audio->query('SELECT COUNT(*) FROM samples')->fetchColumn();
            unset($audio);
        }
        $pQueue=$this->rows($db['python_queue'],'sessions',
            ['id','status','source_digest']);
        $newQueue=$this->rows($db['php_queue'],'sessions',
            ['id','status','source_digest']);
        $pOutbox=$this->rows($db['python_outbox'],'publications',
            ['idempotency_key','state']);
        $pIntents=$this->rows($db['python_ledger'],'intents',
            ['intent_id','state']);
        $newIntents=$this->rows($db['php_ledger'],'intents',
            ['intent_id','state']);
        $pSessions=$this->rows($db['python_ledger'],'sessions',
            ['session_id','remote_id']);
        $newSessions=$this->rows($db['php_ledger'],'sessions',
            ['session_id','remote_id']);
        $pDeclarations=$this->rows($db['python_ledger'],'human_declarations',
            ['session_id','payroll_approved']);
        $newDeclarations=$this->rows($db['php_ledger'],'human_declarations',
            ['session_id','payroll_approved']);

        $missingQueue=$this->missing($pQueue,$newQueue,'id');
        $missingIntents=$this->missing($pIntents,$newIntents,'intent_id');
        $missingLedgerSessions=$this->missing($pSessions,$newSessions,'session_id');
        $missingDeclarations=$this->missing($pDeclarations,$newDeclarations,'session_id');
        $divergentQueue=$this->diverged($pQueue,$newQueue,'id','source_digest');
        $divergentRemote=$this->diverged($pSessions,$newSessions,'session_id','remote_id');
        $unresolvedOutbox=array_filter($pOutbox,static fn($row)=>
            !in_array($row['state'],[
                'confirmed','confirmed_amendment','confirmed_preview',
                'confirmed_rounded','superseded',
            ],true));
        $pendingQueue=array_filter($pQueue,static fn($row)=>
            in_array($row['status'],['approved','uncertain','sending'],true));
        $pendingIntents=array_filter($pIntents,static fn($row)=>
            !in_array($row['state'],[
                'confirmed','covered','superseded','cancelled','rejected',
            ],true));
        $missingProvenance=count($pOutbox)>0 &&
            count($newIntents)===0;

        $checks=[
            'queue_missing'=>count($missingQueue),
            'queue_evidence_changed'=>count($divergentQueue),
            'ledger_intents_missing'=>count($missingIntents),
            'ledger_sessions_missing'=>count($missingLedgerSessions),
            'ledger_remote_id_differences'=>count($divergentRemote),
            'human_declarations_missing'=>count($missingDeclarations),
            'legacy_pending_queue'=>count($pendingQueue),
            'legacy_unresolved_intents'=>count($pendingIntents),
            'legacy_unknown_outbox'=>count($unresolvedOutbox),
            'outbox_not_yet_mapped'=>(int)$missingProvenance,
            'legacy_audio_metadata_needs_php_runtime'=>(int)($audioSampleCount>0),
        ];
        $blockers=array_keys(array_filter($checks,static fn($value)=>$value>0));
        return [
            'mode'=>'read_only',
            'writes_performed'=>0,
            'state_inventory_complete'=>true,
            'state_parity_verified'=>$blockers===[],
            'safe_to_disable_python_publisher'=>false,
            'additional_runtime_parity_required'=>true,
            'counts'=>[
                'python_queue'=>count($pQueue),
                'php_queue'=>count($newQueue),
                'python_outbox'=>count($pOutbox),
                'python_ledger_intents'=>count($pIntents),
                'php_ledger_intents'=>count($newIntents),
                'python_ledger_sessions'=>count($pSessions),
                'php_ledger_sessions'=>count($newSessions),
                'python_human_declarations'=>count($pDeclarations),
                'php_human_declarations'=>count($newDeclarations),
                'legacy_audio_metadata_samples'=>$audioSampleCount,
            ],
            'blocker_counts'=>$checks,
            'blocker_codes'=>$blockers,
        ];
    }

    private function openPrivate(string $filename): PDO
    {
        if (!is_file($filename)||is_link($filename)||is_link(dirname($filename))) {
            throw new RuntimeException('Migration database missing or symlinked');
        }
        if ((fileperms($filename)&0077)!==0) {
            throw new RuntimeException('Migration database must be owner-only');
        }
        $db=new PDO('sqlite:file:'.$filename.'?mode=ro',null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>10,
        ]);
        $db->exec('PRAGMA query_only = ON');
        if ($db->query('PRAGMA quick_check')->fetchColumn()!=='ok') {
            throw new RuntimeException('Migration database failed SQLite integrity check');
        }
        return $db;
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(PDO $db,string $table,array $columns): array
    {
        if (!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Invalid table');
        $available=$db->query('PRAGMA table_info("'.$table.'")')->fetchAll();
        $known=array_column($available,'name');
        if (array_diff($columns,$known)!==[]) {
            throw new RuntimeException('Migration database schema does not match');
        }
        foreach ($columns as $col) {
            if (!preg_match('/^[a-z_]+$/D',$col))throw new RuntimeException('Invalid column');
        }
        return $db->query('SELECT '.implode(',',$columns).' FROM "'.$table.'"')->fetchAll();
    }

    private function missing(array $old,array $new,string $key): array
    {
        $seen=array_fill_keys(array_column($new,$key),true);
        return array_filter($old,static fn($row)=>!isset($seen[$row[$key]]));
    }

    private function diverged(array $old,array $new,string $key,string $column): array
    {
        $byKey=[];
        foreach ($new as $row)$byKey[$row[$key]]=$row[$column];
        return array_filter($old,static fn($row)=>
            array_key_exists($row[$key],$byKey) &&
            (string)$byKey[$row[$key]]!==(string)$row[$column]);
    }
}
