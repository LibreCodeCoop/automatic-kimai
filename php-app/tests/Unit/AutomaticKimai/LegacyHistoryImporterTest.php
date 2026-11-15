<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\CanonicalJson;
use App\AutomaticKimai\LegacyHistoryImporter;
use App\AutomaticKimai\Ledger;
use App\AutomaticKimai\ReviewQueue;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyHistoryImporterTest extends TestCase
{
    private string $root;
    private string $legacy;
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root=sys_get_temp_dir().'/kimai-legacy-import-'.bin2hex(random_bytes(7));
        $this->legacy=$this->root.'/old';
        $this->target=$this->root.'/new';
        foreach ([$this->root,$this->legacy,$this->target,
                  $this->legacy.'/cooperator_agent_state'] as $dir)mkdir($dir,0700);
        $ledger=new Ledger($this->legacy.'/cooperator_agent_state/ledger.sqlite3',1,'worker');
        $ledger->close();
        $ledger=new Ledger($this->target.'/ledger.sqlite3',1,'worker');
        $ledger->close();
        $review=new ReviewQueue($this->legacy.'/session_review_queue.sqlite3');
        $review->close();
        $review=new ReviewQueue($this->target.'/review_queue.sqlite3');
        $review->close();
        $outbox=$this->open($this->legacy.'/kimai_publish_outbox.sqlite3');
        $outbox->exec('CREATE TABLE publications(
            idempotency_key TEXT PRIMARY KEY,approval_id TEXT UNIQUE,state TEXT,
            payload_json TEXT,remote_id INTEGER,reason_code TEXT,
            created_utc TEXT,updated_utc TEXT)');
    }

    private function open(string $file): PDO
    {
        if (!is_file($file))touch($file);
        chmod($file,0600);
        $db=new PDO('sqlite:'.$file,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
        return $db;
    }

    private function fixture(): void
    {
        $db=$this->open($this->legacy.'/cooperator_agent_state/ledger.sqlite3');
        $db->exec("INSERT INTO sessions VALUES ('work-1',777,'{\"id\":777}','evidence', '2026-11-10T14:00:00Z')");
        $db->exec("INSERT INTO intents VALUES ('intent-1','work-1','post','confirmed',777,'{\"kimai\":{\"begin\":\"2026-11-10T09:00:00\"}}','2026-11-10T14:00:00Z')");
        $db->exec("INSERT INTO human_declarations VALUES ('work-1','2026-11-10T14:00:00Z',
            '2026-11-10T09:00:00-03:00','2026-11-10T10:00:00-03:00','Meeting',
            1,'worker_explicit_confirmation',0)");
        $queue=$this->open($this->legacy.'/session_review_queue.sqlite3');
        $queue->exec("INSERT INTO sessions VALUES (
            'aw-a','2026-11-10','2026-11-10T09:00:00-03:00',
            '2026-11-10T10:00:00-03:00','covered','{\"date\":\"2026-11-10\"}',
            'old-evidence',NULL,777,NULL,'2026-11-10T14:00:00Z','2026-11-10T14:00:00Z')");
        $outbox=$this->open($this->legacy.'/kimai_publish_outbox.sqlite3');
        $outbox->exec("INSERT INTO publications VALUES (
            'publish-1','approval-1','confirmed', '{\"description\":\"fixture\"}',
            777,NULL,'2026-11-10T14:00:00Z','2026-11-10T14:00:00Z')");
    }

    protected function tearDown(): void
    {
        $items=new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $file) {
            if ($file->isDir()&&!$file->isLink())rmdir((string)$file);
            else unlink((string)$file);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_import_preserves_active_php_review_without_touching_python_and_is_idempotent(): void
    {
        $this->fixture();
        $phpQueue=$this->open($this->target.'/review_queue.sqlite3');
        $phpQueue->exec("INSERT INTO sessions VALUES (
            'aw-a','2026-11-10','2026-11-10T09:05:00-03:00',
            '2026-11-10T10:05:00-03:00','draft','{\"date\":\"2026-11-10\"}',
            'new-evidence',NULL,NULL,NULL,'2026-11-10T15:00:00Z','2026-11-10T15:00:00Z')");
        unset($phpQueue);
        $files=[
            $this->legacy.'/cooperator_agent_state/ledger.sqlite3',
            $this->legacy.'/session_review_queue.sqlite3',
            $this->legacy.'/kimai_publish_outbox.sqlite3',
        ];
        $hashes=array_map('hash_file',array_fill(0,count($files),'sha256'),$files);
        $importer=new LegacyHistoryImporter();
        $first=$importer->import($this->legacy,$this->target,1,'worker');
        self::assertSame(['sessions'=>1,'intents'=>1,'human_declarations'=>1,
            'legacy_publications'=>1,'legacy_review_sessions'=>1],$first['newly_imported']);
        self::assertSame(0,$first['kimai_writes']);
        self::assertFalse($first['ready_for_cutover']);
        self::assertFalse($first['php_live_review_candidates_modified']);
        self::assertSame($hashes,array_map('hash_file',
            array_fill(0,count($files),'sha256'),$files));

        $second=$importer->import($this->legacy,$this->target,1,'worker');
        self::assertSame(0,array_sum($second['newly_imported']));
        $db=$this->open($this->target.'/ledger.sqlite3');
        self::assertSame(1,(int)$db->query('SELECT count(*) FROM sessions')->fetchColumn());
        self::assertSame(1,(int)$db->query('SELECT count(*) FROM intents')->fetchColumn());
        self::assertSame(1,(int)$db->query('SELECT count(*) FROM legacy_publications')->fetchColumn());
        $active=$this->open($this->target.'/review_queue.sqlite3');
        self::assertSame('new-evidence',$active->query(
            "SELECT source_digest FROM sessions WHERE id='aw-a'")->fetchColumn());
        self::assertSame('old-evidence',$active->query(
            "SELECT source_digest FROM legacy_review_sessions WHERE id='aw-a'")->fetchColumn());
    }


    public function test_incremental_catchup_preserves_prior_versions_and_verified_extension(): void
    {
        $this->fixture();
        $ledger=$this->open($this->legacy.'/cooperator_agent_state/ledger.sqlite3');
        $old=[
            'id'=>777,'user'=>1,'project'=>41,'activity'=>25,
            'begin'=>'2026-11-10T09:00:00-03:00',
            'end'=>'2026-11-10T10:00:00-03:00','duration'=>3600,
            'description'=>'Confirmed professional work','billable'=>true,'exported'=>false,
        ];
        $set=$ledger->prepare("UPDATE sessions SET remote_snapshot_json=? WHERE session_id='work-1'");
        $set->execute([json_encode($old,JSON_THROW_ON_ERROR)]);
        $importer=new LegacyHistoryImporter();
        $importer->import($this->legacy,$this->target,1,'worker');

        $next=$old;
        $next['end']='2026-11-10T10:05:00-03:00';
        $next['duration']=3900;
        $ledger->prepare("UPDATE sessions SET remote_snapshot_json=?,evidence_digest=?,updated_at=?
            WHERE session_id='work-1'")->execute([
            json_encode($next,JSON_THROW_ON_ERROR),'evidence-v2','2026-11-10T15:00:00Z',
        ]);
        $queue=$this->open($this->legacy.'/session_review_queue.sqlite3');
        $queue->exec("UPDATE sessions SET end='2026-11-10T10:05:00-03:00',
            proposed_json='{\"date\":\"2026-11-10\",\"work_seconds\":3900}',
            source_digest='new-evidence',updated_utc='2026-11-10T15:00:00Z'
            WHERE id='aw-a'");
        $validated=0;
        $verify=static function(int $remoteId,array $snapshot)use(&$validated):bool{
            $validated++;
            return $remoteId===777 && $snapshot['duration']===3900 &&
                $snapshot['billable']===true;
        };
        $out=$importer->import($this->legacy,$this->target,1,'worker',$verify);
        self::assertSame(1,$validated);
        self::assertSame(['sessions'=>1,'legacy_review_sessions'=>1],$out['updated_history']);
        self::assertSame(0,array_sum($out['newly_imported']));
        $newLedger=$this->open($this->target.'/ledger.sqlite3');
        $newQueue=$this->open($this->target.'/review_queue.sqlite3');
        self::assertSame('evidence-v2',$newLedger->query(
            "SELECT evidence_digest FROM sessions WHERE session_id='work-1'")->fetchColumn());
        self::assertSame('new-evidence',$newQueue->query(
            "SELECT source_digest FROM legacy_review_sessions WHERE id='aw-a'")->fetchColumn());
        self::assertSame(1,(int)$newLedger->query(
            'SELECT COUNT(*) FROM legacy_session_versions')->fetchColumn());
        self::assertSame(1,(int)$newQueue->query(
            'SELECT COUNT(*) FROM legacy_review_versions')->fetchColumn());
        self::assertSame(0,array_sum($importer->import(
            $this->legacy,$this->target,1,'worker',$verify)['updated_history']));
    }

    public function test_changed_published_snapshot_is_rejected_without_verified_get(): void
    {
        $this->fixture();
        $ledger=$this->open($this->legacy.'/cooperator_agent_state/ledger.sqlite3');
        $base=[
            'id'=>777,'user'=>1,'project'=>41,'activity'=>25,
            'begin'=>'2026-11-10T09:00:00-03:00',
            'end'=>'2026-11-10T10:00:00-03:00','duration'=>3600,
            'description'=>'Confirmed professional work','billable'=>false,'exported'=>false,
        ];
        $ledger->prepare("UPDATE sessions SET remote_snapshot_json=? WHERE session_id='work-1'")
            ->execute([json_encode($base,JSON_THROW_ON_ERROR)]);
        $importer=new LegacyHistoryImporter();
        $importer->import($this->legacy,$this->target,1,'worker');
        $base['end']='2026-11-10T10:05:00-03:00';
        $base['duration']=3900;
        $ledger->prepare("UPDATE sessions SET remote_snapshot_json=?,evidence_digest=?,updated_at=?
            WHERE session_id='work-1'")->execute([
            json_encode($base,JSON_THROW_ON_ERROR),'evidence-v2','2026-11-10T15:00:00Z',
        ]);
        try {
            $importer->import($this->legacy,$this->target,1,'worker',
                static fn(int $id,array $snap):bool=>false);
            self::fail('Unverified changes must not migrate');
        } catch (RuntimeException) {
            $target=$this->open($this->target.'/ledger.sqlite3');
            self::assertSame('evidence',$target->query(
                "SELECT evidence_digest FROM sessions WHERE session_id='work-1'")->fetchColumn());
        }
        $base['project']=999;
        $ledger->prepare("UPDATE sessions SET remote_snapshot_json=? WHERE session_id='work-1'")
            ->execute([json_encode($base,JSON_THROW_ON_ERROR)]);
        $this->expectException(RuntimeException::class);
        $importer->import($this->legacy,$this->target,1,'worker',
            static fn(int $id,array $snap):bool=>true);
    }

    public function test_conflicting_remote_mapping_is_rejected_without_new_state(): void
    {
        $this->fixture();
        $db=$this->open($this->target.'/ledger.sqlite3');
        $db->exec("INSERT INTO sessions VALUES (
            'another-session',777,'{\"id\":777}','different','2026-11-10T11:00:00Z')");
        $this->expectException(\PDOException::class);
        (new LegacyHistoryImporter())->import($this->legacy,$this->target,1,'worker');
    }

    public function test_wrong_worker_identity_is_rejected_before_import(): void
    {
        $this->fixture();
        $this->expectException(RuntimeException::class);
        (new LegacyHistoryImporter())->import($this->legacy,$this->target,2,'worker');
    }

    public function test_ambiguous_legacy_outcome_is_archived_but_never_approved(): void
    {
        $this->fixture();
        $db=$this->open($this->legacy.'/kimai_publish_outbox.sqlite3');
        $db->exec("UPDATE publications SET state='unknown' WHERE idempotency_key='publish-1'");
        $out=(new LegacyHistoryImporter())->import($this->legacy,$this->target,1,'worker');
        self::assertSame(1,$out['legacy_unknown_publications']);
        self::assertFalse($out['ready_for_cutover']);
        $new=$this->open($this->target.'/ledger.sqlite3');
        self::assertSame('unknown',$new->query(
            "SELECT state FROM legacy_publications WHERE idempotency_key='publish-1'")->fetchColumn());
    }
}
