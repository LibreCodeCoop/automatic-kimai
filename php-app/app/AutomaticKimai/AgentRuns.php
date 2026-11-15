<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Explicit agent-run telemetry, separately from human work. Requires an
 * attested emitter; does not infer agent activity from idle status or browser.
 */
final class AgentRuns
{
    private ?PDO $db=null;
    private const SOURCES=['remote_desktop_mcp','chatgpt_work','codex_cli','other'];
    private const TERMINAL=['completed','failed','cancelled'];

    public function __construct(string $filename,string $profileId,bool $create=true)
    {
        if (is_link($filename)||is_link(dirname($filename))) {
            throw new RuntimeException('Symlinked agent telemetry forbidden');
        }
        if (!is_file($filename)&&!$create)return;
        if (!is_dir(dirname($filename)))mkdir(dirname($filename),0700,true);
        if (!is_file($filename)) {
            $f=fopen($filename,'x');
            if (!$f)throw new RuntimeException('Cannot initialize agent run store');
            fclose($f);
            chmod($filename,0600);
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($filename)&0077)!==0) {
            throw new RuntimeException('Agent run database must be private');
        }
        $this->db=new PDO('sqlite:'.$filename,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT=>10,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout=10000;PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT NOT NULL)');
        $this->db->exec("CREATE TABLE IF NOT EXISTS runs(
            run_id TEXT PRIMARY KEY, source TEXT NOT NULL,
            start_utc TEXT NOT NULL, end_utc TEXT,
            state TEXT NOT NULL CHECK(state IN ('running','completed','failed','cancelled')))");
        $key=hash('sha256',$profileId);
        $row=$this->db->query("SELECT value FROM metadata WHERE key='worker'")->fetch();
        if ($row && $row['value']!==$key)throw new RuntimeException('Agent telemetry belongs to another profile');
        if (!$row)$this->db->prepare("INSERT INTO metadata VALUES('worker',?)")->execute([$key]);
    }

    public function start(string $source,?string $runId=null,?DateTimeImmutable $time=null): string
    {
        if (!$this->db)throw new RuntimeException('Agent telemetry unavailable');
        if (!in_array($source,self::SOURCES,true))throw new RuntimeException('Invalid agent source');
        $runId??=bin2hex(random_bytes(16));
        if (!preg_match('/^[A-Za-z0-9_-]{12,70}$/D',$runId))throw new RuntimeException('Invalid opaque run ID');
        $instant=($time??new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $this->db->prepare("INSERT INTO runs VALUES(?,?,?,NULL,'running')")
            ->execute([$runId,$source,TimeMath::iso($instant)]);
        return $runId;
    }

    public function finish(string $runId,string $state,?DateTimeImmutable $time=null): void
    {
        if (!$this->db || !in_array($state,self::TERMINAL,true))throw new RuntimeException('Invalid agent transition');
        $stmt=$this->db->prepare('SELECT start_utc,state FROM runs WHERE run_id=?');
        $stmt->execute([$runId]);
        $prior=$stmt->fetch();
        if (!$prior||$prior['state']!=='running')throw new RuntimeException('Agent run missing or already completed');
        $instant=($time??new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        if (TimeMath::ts($instant)<TimeMath::ts(TimeMath::parse($prior['start_utc']))) {
            throw new RuntimeException('Agent run cannot finish before it starts');
        }
        $stmt=$this->db->prepare("UPDATE runs SET end_utc=?,state=? WHERE run_id=? AND state='running'");
        $stmt->execute([TimeMath::iso($instant),$state,$runId]);
        if ($stmt->rowCount()!==1)throw new RuntimeException('Concurrent agent run update');
    }

    public function intervals(DateTimeImmutable $since,DateTimeImmutable $until): array
    {
        if (TimeMath::ts($since)>=TimeMath::ts($until))throw new RuntimeException('Invalid agent query range');
        if (!$this->db)return [];
        $clock=new DateTimeImmutable();
        $limit=TimeMath::ts($until)<TimeMath::ts($clock)?$until:$clock;
        $out=[];
        foreach ($this->db->query('SELECT * FROM runs') as $run) {
            $a=TimeMath::parse($run['start_utc']);
            $b=$run['end_utc']?TimeMath::parse($run['end_utc']):$limit;
            $left=TimeMath::ts($a)<TimeMath::ts($since)?$since:$a;
            $right=TimeMath::ts($b)>TimeMath::ts($until)?$until:$b;
            if (TimeMath::ts($left)>=TimeMath::ts($right))continue;
            $out[]=[
                'run_id'=>$run['run_id'],'source'=>$run['source'],
                'start'=>TimeMath::iso($left),'end'=>TimeMath::iso($right),
                'state'=>$run['state'],
                'execution_seconds'=>(int)floor(TimeMath::seconds($left,$right)),
                'counts_as_human_work'=>false,'attribution'=>'agent_runtime_only',
            ];
        }
        usort($out,static fn($a,$b)=>strcmp($a['start'].$a['run_id'],$b['start'].$b['run_id']));
        return $out;
    }

    public static function overlappingSeconds(array $window,array $runs): int
    {
        $wStart=TimeMath::parse($window['start']);$wEnd=TimeMath::parse($window['end']);
        $spans=[];
        foreach ($runs as $run) {
            $a=TimeMath::parse($run['start']);$b=TimeMath::parse($run['end']);
            $left=TimeMath::ts($a)<TimeMath::ts($wStart)?$wStart:$a;
            $right=TimeMath::ts($b)>TimeMath::ts($wEnd)?$wEnd:$b;
            if (TimeMath::ts($left)<TimeMath::ts($right))$spans[]=[$left,$right];
        }
        return TimeMath::coveredSeconds($spans,$wStart,$wEnd);
    }

    public function close(): void {$this->db=null;}
}
