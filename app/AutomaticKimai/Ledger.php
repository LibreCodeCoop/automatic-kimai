<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use PDO;
use RuntimeException;

/** Per-profile durable outbox. No POST retry after an ambiguous outcome. */
final class Ledger
{
    private PDO $db;
    public readonly string $file;

    public function __construct(string $file,int $userId,string $profileId)
    {
        if ($userId<1||$profileId==='')throw new RuntimeException('Invalid ledger identity');
        $dir=dirname($file);
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot initialize ledger directory');
        }
        if (is_link($file)||is_link($dir))throw new RuntimeException('Symlinked ledger forbidden');
        if (file_exists($file)) {
            if ((fileperms($file)&0077)!==0 && DIRECTORY_SEPARATOR==='/') {
                throw new RuntimeException('Ledger is accessible to other users');
            }
        } else {
            $handle=fopen($file,'x');
            if ($handle===false)throw new RuntimeException('Cannot create ledger');
            fclose($handle);
            chmod($file,0600);
        }
        $this->file=$file;
        $this->db=new PDO('sqlite:'.$file,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>20,
        ]);
        $this->db->exec('PRAGMA busy_timeout=20000');
        $this->db->exec('PRAGMA journal_mode=DELETE');
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS context (key TEXT PRIMARY KEY,value TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions (
            session_id TEXT PRIMARY KEY, remote_id INTEGER NOT NULL UNIQUE,
            remote_snapshot_json TEXT NOT NULL, evidence_digest TEXT NOT NULL,
            updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS intents (
            intent_id TEXT PRIMARY KEY, session_id TEXT NOT NULL,
            action TEXT NOT NULL, state TEXT NOT NULL, remote_id INTEGER,
            payload_json TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS human_declarations (
            session_id TEXT PRIMARY KEY, declared_utc TEXT NOT NULL,
            local_start TEXT NOT NULL, local_end TEXT NOT NULL,
            category TEXT NOT NULL,user_id INTEGER NOT NULL,
            basis TEXT NOT NULL,payroll_approved INTEGER NOT NULL DEFAULT 0)');
        $identity=CanonicalJson::digest(['user'=>$userId,'profile'=>$profileId],false);
        $row=$this->db->query("SELECT value FROM context WHERE key='identity'")->fetch();
        if ($row && $row['value']!==$identity)throw new RuntimeException('Ledger belongs to another Kimai account');
        if (!$row) {
            $stmt=$this->db->prepare("INSERT INTO context(key,value) VALUES('identity',?)");
            $stmt->execute([$identity]);
        }
    }

    public function known(string $sessionId): ?array
    {
        $stmt=$this->db->prepare('SELECT remote_id,remote_snapshot_json FROM sessions WHERE session_id=?');
        $stmt->execute([$sessionId]);
        $row=$stmt->fetch();
        return $row ? [
            'remote_id'=>(int)$row['remote_id'],
            'remote_snapshot'=>json_decode($row['remote_snapshot_json'],true,512,JSON_THROW_ON_ERROR)
        ] : null;
    }

    public function restorationAttempted(string $sessionId): bool
    {
        $stmt=$this->db->prepare("SELECT 1 FROM intents
            WHERE session_id=? AND action='restore'
            AND state IN ('sending','unknown','confirmed') LIMIT 1");
        $stmt->execute([$sessionId]);
        return $stmt->fetchColumn()!==false;
    }

    public function intent(string $key): ?array
    {
        $stmt=$this->db->prepare('SELECT state,remote_id FROM intents WHERE intent_id=?');
        $stmt->execute([$key]);
        $row=$stmt->fetch();
        return $row?:null;
    }

    public function pending(): array
    {
        $rows=$this->db->query("SELECT intent_id,session_id,action,state,remote_id,payload_json
            FROM intents WHERE state IN ('sending','unknown') ORDER BY updated_at")->fetchAll();
        return array_map(static fn($row)=>[
            'key'=>$row['intent_id'],'session_id'=>$row['session_id'],
            'action'=>$row['action'],'state'=>$row['state'],'remote_id'=>$row['remote_id'],
            'payload'=>json_decode($row['payload_json'],true,512,JSON_THROW_ON_ERROR)
        ],$rows);
    }

    private static function encode(array $value): string
    {
        return CanonicalJson::encode($value);
    }

    public function reserve(string $key,array $window,string $action,array $payload): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($this->intent($key)!==null)throw new RuntimeException('Intent already exists; no blind retry');
            $stmt=$this->db->prepare('INSERT INTO intents VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$key,$window['id'],$action,'sending',null,
                self::encode(['kimai'=>$payload,'evidence_digest'=>$window['evidence_digest']]),
                gmdate('c')]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function markUnknown(string $key,?int $remoteId=null): void
    {
        $stmt=$this->db->prepare("UPDATE intents SET state='unknown',remote_id=?,updated_at=?
            WHERE intent_id=? AND state='sending'");
        $stmt->execute([$remoteId,gmdate('c'),$key]);
    }

    public function confirm(string $key,array $window,array $remote): void
    {
        $this->persistConfirmed($key,$window['id'],$window['evidence_digest'],$remote,'sending');
    }

    public function recoverConfirmed(array $intent,array $remote): void
    {
        $this->persistConfirmed($intent['key'],$intent['session_id'],
            $intent['payload']['evidence_digest'],$remote,'pending');
    }

    private function persistConfirmed(string $key,string $sessionId,string $evidence,
                                     array $remote,string $state): void
    {
        $snapshot=Reconciler::snapshot($remote);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $query=$state==='pending'?
                "UPDATE intents SET state='confirmed',remote_id=?,updated_at=?
                  WHERE intent_id=? AND state IN ('unknown','sending')":
                "UPDATE intents SET state='confirmed',remote_id=?,updated_at=?
                  WHERE intent_id=? AND state='sending'";
            $stmt=$this->db->prepare($query);
            $stmt->execute([$snapshot['id'],gmdate('c'),$key]);
            if ($stmt->rowCount()!==1)throw new RuntimeException('Unexpected outbox state');
            $stmt=$this->db->prepare('INSERT INTO sessions
                (session_id,remote_id,remote_snapshot_json,evidence_digest,updated_at)
                VALUES(?,?,?,?,?) ON CONFLICT(session_id) DO UPDATE SET
                remote_id=excluded.remote_id,remote_snapshot_json=excluded.remote_snapshot_json,
                evidence_digest=excluded.evidence_digest,updated_at=excluded.updated_at');
            $stmt->execute([$sessionId,$snapshot['id'],self::encode($snapshot),$evidence,gmdate('c')]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function declaration(array $window,int $userId,string $basis): void
    {
        if (!in_array($basis,[
            'worker_explicit_confirmation','reported_attendance_scheduled_bounds',
            'reported_workday_observed_desktop_bounds'],true)) {
            throw new RuntimeException('Invalid human declaration provenance');
        }
        $stmt=$this->db->prepare('SELECT local_start,local_end,category,user_id,basis
            FROM human_declarations WHERE session_id=?');
        $stmt->execute([$window['id']]);
        $prior=$stmt->fetch();
        $topic=$window['topics'][0]??'';
        $expected=[
            'local_start'=>$window['start'],'local_end'=>$window['end'],
            'category'=>$topic,'user_id'=>$userId,'basis'=>$basis
        ];
        if ($prior) {
            $prior['user_id']=(int)$prior['user_id'];
            if ($prior!==$expected)throw new RuntimeException('Conflicting human declaration');
            return;
        }
        $stmt=$this->db->prepare('INSERT INTO human_declarations VALUES (?,?,?,?,?,?,?,0)');
        $stmt->execute([$window['id'],gmdate('c'),$window['start'],$window['end'],
            $topic,$userId,$basis]);
    }

    public function close(): void
    {
        unset($this->db);
    }
}
