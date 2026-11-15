<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\AudioRuntime;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\TelegramAudio;
use App\AutomaticKimai\LegacyCutoverAudit;
use PDO;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyCutoverAuditTest extends TestCase
{
    private string $dir;
    private string $legacy;
    private string $php;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-cutover-audit-'.bin2hex(random_bytes(8));
        $this->legacy=$this->dir.'/old';
        $this->php=$this->dir.'/new';
        foreach ([$this->dir,$this->legacy,$this->legacy.'/cooperator_agent_state',
            $this->php] as $path) {
            mkdir($path,0700);
        }
        $this->database($this->legacy.'/session_review_queue.sqlite3','queue');
        $this->database($this->legacy.'/kimai_publish_outbox.sqlite3','outbox');
        $this->database($this->legacy.'/telegram_audio_metadata.sqlite3','audio');
        $this->database($this->legacy.'/cooperator_agent_state/ledger.sqlite3','ledger');
        $this->database($this->php.'/review_queue.sqlite3','queue');
        $this->database($this->php.'/ledger.sqlite3','ledger');
    }

    private function database(string $path,string $kind): void
    {
        $db=new PDO('sqlite:'.$path);
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        if ($kind==='queue') {
            $db->exec('CREATE TABLE sessions(id TEXT,status TEXT,source_digest TEXT)');
        } elseif ($kind==='outbox') {
            $db->exec('CREATE TABLE publications(idempotency_key TEXT,state TEXT)');
        } elseif ($kind==='audio') {
            $db->exec('CREATE TABLE samples(id INTEGER PRIMARY KEY)');
        } else {
            $db->exec('CREATE TABLE intents(intent_id TEXT,state TEXT)');
            $db->exec('CREATE TABLE sessions(session_id TEXT,remote_id INTEGER)');
            $db->exec('CREATE TABLE human_declarations(session_id TEXT,payroll_approved INTEGER)');
        }
        unset($db);
        chmod($path,0600);
    }

    private function insert(string $name,string $sql): void
    {
        $db=new PDO('sqlite:'.$name);
        $db->exec($sql);
        unset($db);
    }

    protected function tearDown(): void
    {
        foreach ([$this->legacy,$this->legacy.'/cooperator_agent_state',$this->php] as $dir) {
            foreach (glob($dir.'/*')?:[] as $item) {
                if (is_file($item)||is_link($item))@unlink($item);
            }
        }
        @rmdir($this->legacy.'/cooperator_agent_state');
        @rmdir($this->legacy);
        @rmdir($this->php);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_incomplete_history_reports_all_missing_state_without_writing(): void
    {
        $this->insert($this->legacy.'/session_review_queue.sqlite3',
            "INSERT INTO sessions VALUES('q1','draft','digest-a'),('q2','approved','digest-b')");
        $this->insert($this->php.'/review_queue.sqlite3',
            "INSERT INTO sessions VALUES('q1','draft','digest-different')");
        $this->insert($this->legacy.'/kimai_publish_outbox.sqlite3',
            "INSERT INTO publications VALUES('pub1','confirmed_preview'),('pub2','unknown')");
        $this->insert($this->legacy.'/telegram_audio_metadata.sqlite3',
            "INSERT INTO samples VALUES(1),(2)");
        $this->insert($this->legacy.'/cooperator_agent_state/ledger.sqlite3',
            "INSERT INTO intents VALUES('i1','confirmed'),('i2','sending');
             INSERT INTO sessions VALUES('s1',44);
             INSERT INTO human_declarations VALUES('s1',1)");
        $this->insert($this->php.'/ledger.sqlite3',
            "INSERT INTO intents VALUES('i1','confirmed')");
        $paths=[
            $this->legacy.'/session_review_queue.sqlite3',
            $this->legacy.'/kimai_publish_outbox.sqlite3',
            $this->legacy.'/cooperator_agent_state/ledger.sqlite3',
            $this->legacy.'/telegram_audio_metadata.sqlite3',
            $this->php.'/review_queue.sqlite3',
            $this->php.'/ledger.sqlite3',
        ];
        $hashes=array_map('hash_file',array_fill(0,count($paths),'sha256'),$paths);
        $result=(new LegacyCutoverAudit())->inspect($this->legacy,$this->php);
        self::assertSame($hashes,array_map('hash_file',
            array_fill(0,count($paths),'sha256'),$paths));
        self::assertSame('read_only',$result['mode']);
        self::assertSame(0,$result['writes_performed']);
        self::assertFalse($result['state_parity_verified']);
        self::assertFalse($result['safe_to_disable_python_publisher']);
        self::assertSame(2,$result['counts']['python_queue']);
        self::assertSame(1,$result['counts']['php_queue']);
        self::assertSame(1,$result['blocker_counts']['queue_missing']);
        self::assertSame(1,$result['blocker_counts']['queue_evidence_changed']);
        self::assertSame(1,$result['blocker_counts']['legacy_pending_queue']);
        self::assertSame(1,$result['blocker_counts']['legacy_unresolved_intents']);
        self::assertSame(1,$result['blocker_counts']['legacy_unknown_outbox']);
        self::assertSame(1,$result['blocker_counts']['ledger_intents_missing']);
        self::assertSame(1,$result['blocker_counts']['ledger_sessions_missing']);
        self::assertSame(1,$result['blocker_counts']['human_declarations_missing']);
        self::assertSame(2,$result['counts']['legacy_audio_metadata_samples']);
        self::assertSame(1,$result['blocker_counts']['legacy_audio_metadata_needs_php_runtime']);
        foreach (['q1','q2','pub1','pub2','i1','i2','s1','digest-a','digest-b'] as $secret) {
            self::assertStringNotContainsString($secret,json_encode($result));
        }
    }

    public function test_even_matching_local_state_cannot_declare_runtime_cutover_safe(): void
    {
        $audit=(new LegacyCutoverAudit())->inspect($this->legacy,$this->php);
        self::assertSame([],$audit['blocker_codes']);
        self::assertTrue($audit['state_parity_verified']);
        self::assertFalse($audit['safe_to_disable_python_publisher']);
        self::assertTrue($audit['additional_runtime_parity_required']);
    }

    public function test_recent_consecutive_php_audio_samples_satisfy_audio_runtime_inventory(): void
    {
        $this->insert($this->legacy.'/telegram_audio_metadata.sqlite3',
            'INSERT INTO samples VALUES(1),(2)');
        $store=new LocalStore($this->php);
        $db=TelegramAudio::open($this->php.'/telegram_audio_metadata.sqlite3');
        $now=new DateTimeImmutable('2026-11-15T16:00:31+00:00');
        foreach (['16:00:00','16:00:15','16:00:30'] as $time) {
            $sample=new DateTimeImmutable('2026-11-15T'.$time.'+00:00');
            TelegramAudio::record($db,[
                'telegram_capture'=>false,'telegram_playback'=>false,
                'telegram_communication'=>false,
            ],$sample);
            AudioRuntime::record($store,$sample);
        }
        $db=null;
        self::assertTrue(AudioRuntime::healthy($this->php,$now));
        // LegacyCutoverAudit uses the actual current clock and is deliberately
        // strict about stale audio; positive inventory coverage is below.
        self::assertFalse(AudioRuntime::healthy($this->php,
            new DateTimeImmutable('2026-11-15T16:02:30+00:00')));
        $state=$store->read('telegram_audio_php_runtime.json');
        self::assertSame(3,$state['consecutive_samples']);
        self::assertFalse($state['human_work_attested']);
        $state['consecutive_samples']=2;
        $store->write('telegram_audio_php_runtime.json',$state);
        self::assertFalse(AudioRuntime::healthy($this->php,$now));
    }

    public function test_audit_identifies_live_php_audio_without_authorizing_cutover(): void
    {
        $this->insert($this->legacy.'/telegram_audio_metadata.sqlite3',
            'INSERT INTO samples VALUES(1)');
        $store=new LocalStore($this->php);
        $db=TelegramAudio::open($this->php.'/telegram_audio_metadata.sqlite3');
        $now=new DateTimeImmutable('now',new \DateTimeZone('UTC'));
        foreach ([30,15,0] as $seconds) {
            $at=$now->modify('-'.$seconds.' seconds');
            TelegramAudio::record($db,[
                'telegram_capture'=>false,'telegram_playback'=>false,
                'telegram_communication'=>false,
            ],$at);
            AudioRuntime::record($store,$at);
        }
        unset($db);
        $report=(new LegacyCutoverAudit())->inspect($this->legacy,$this->php);
        self::assertSame([], $report['blocker_codes']);
        self::assertTrue($report['php_audio_runtime_recent']);
        self::assertTrue($report['state_parity_verified']);
        self::assertFalse($report['safe_to_disable_python_publisher']);
        self::assertTrue($report['additional_runtime_parity_required']);
    }

    public function test_rejects_world_readable_legacy_database(): void
    {
        chmod($this->legacy.'/session_review_queue.sqlite3',0644);
        $this->expectException(RuntimeException::class);
        (new LegacyCutoverAudit())->inspect($this->legacy,$this->php);
    }
}
