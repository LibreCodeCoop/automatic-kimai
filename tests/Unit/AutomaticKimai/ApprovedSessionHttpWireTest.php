<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\ApprovedSessionPublisher;
use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\ReviewQueue;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Real HTTP (curl / POST / GET / DELETE) against a throwaway loopback stub.
 * The stub is intentionally NOT a substitute for testing against real Kimai.
 */
final class ApprovedSessionHttpWireTest extends TestCase
{
    private string $dir;
    private string $stateFile;
    private string $token;
    private string $url;
    private mixed $server=null;
    private ?ReviewQueue $queue=null;
    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-http-wire-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700);
        $this->stateFile=$this->dir.'/fake-remote.json';
        $this->token=bin2hex(random_bytes(20));
        $this->writeState([
            'timesheets'=>[],'next_id'=>100,'post_count'=>0,
            'delete_count'=>0,'fail_after_commit'=>false,
        ]);
        $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$err);
        if (!is_resource($socket))throw new RuntimeException('No loopback port available');
        $address=stream_socket_get_name($socket,false);
        fclose($socket);
        $this->url='http://'.$address;
        $router=dirname(__DIR__,2).'/Fixtures/kimai-http-router.php';
        $this->server=proc_open(
            [PHP_BINARY,'-S',$address,$router],
            [
                0=>['pipe','r'],
                1=>['file',$this->dir.'/stdout.log','a'],
                2=>['file',$this->dir.'/stderr.log','a'],
            ],$pipes,null,[
                'KIMAI_QA_STATE'=>$this->stateFile,
                'KIMAI_QA_TOKEN'=>$this->token,
                'PATH'=>getenv('PATH')?:'/usr/bin:/bin',
            ],['bypass_shell'=>true]
        );
        if (!is_resource($this->server))throw new RuntimeException('QA HTTP server failed to spawn');
        fclose($pipes[0]);
        $ready=false;
        for ($n=0;$n<30;$n++) {
            $conn=@stream_socket_client($address,$eno,$msg,0.15);
            if (is_resource($conn)) {
                fclose($conn);$ready=true;break;
            }
            usleep(70_000);
        }
        if (!$ready)throw new RuntimeException('Disposable HTTP server did not bind');
        $this->profile=new Profile([
            'profile_id'=>'test-worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
        ]);
        $this->queue=new ReviewQueue($this->dir.'/review.sqlite3');
        $this->queue->synchronize([[
            'id'=>'aw-httpwire0000000001','date'=>'2026-11-12',
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T10:00:00-03:00',
            'ongoing'=>false,'end_stable'=>true,
            'remote_intersections'=>[],
            'observed_seconds_by_class'=>['work_context_observed'=>3500],
            'no_aw_data_seconds'=>100,
        ]],[]);
        $row=$this->queue->rows()[0];
        $this->queue->approve($row['id'],[
            'source_digest'=>$row['source_digest'],
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T10:00:00-03:00',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'billable'=>false,'human_duration_confirmed'=>true,
            'project_confirmed'=>true,'billing_confirmed'=>true,
            'description'=>'QA-only code review and technical coordination.',
        ],$this->profile,1,new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
    }

    protected function tearDown(): void
    {
        if ($this->queue!==null)$this->queue->close();
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->dir.'/*')?:[] as $file)@unlink($file);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile,
            json_encode($state,JSON_THROW_ON_ERROR));
        chmod($this->stateFile,0600);
    }

    private function readState(): array
    {
        return json_decode((string)file_get_contents($this->stateFile),true,512,
            JSON_THROW_ON_ERROR);
    }

    private function publisher(): ApprovedSessionPublisher
    {
        return new ApprovedSessionPublisher(
            new KimaiClient($this->url,$this->token),
            $this->profile,$this->dir.'/review.sqlite3',$this->dir.'/ledger.sqlite3');
    }

    private function deleteFakeRemote(int $id): int
    {
        $curl=curl_init($this->url.'/api/timesheets/'.$id);
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CUSTOMREQUEST=>'DELETE',
            CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$this->token],
        ]);
        curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status;
    }

    public function test_real_http_post_get_delete_and_no_silent_resurrection(): void
    {
        $sent=$this->publisher()->run(true);
        self::assertSame('posted_and_verified',$sent['results'][0]['status']);
        self::assertSame(1,$sent['confirmed_writes']);
        self::assertSame(1,$this->readState()['post_count']);
        self::assertCount(1,$this->readState()['timesheets']);
        $id=$this->readState()['timesheets'][0]['id'];
        self::assertSame(204,$this->deleteFakeRemote($id));
        self::assertSame([],$this->readState()['timesheets']);
        $again=$this->publisher()->run(true);
        self::assertSame(0,$again['remote_writes']);
        self::assertSame(1,$this->readState()['post_count']);
        self::assertSame(1,$this->readState()['delete_count']);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
    }

    public function test_http_503_after_successful_commit_is_reconciled_by_get_only(): void
    {
        $state=$this->readState();
        $state['fail_after_commit']=true;
        $this->writeState($state);
        $sent=$this->publisher()->run(true);
        self::assertSame('unknown_no_retry',$sent['results'][0]['status']);
        self::assertSame('uncertain',$this->queue->rows()[0]['status']);
        self::assertSame(1,$this->readState()['post_count']);
        self::assertCount(1,$this->readState()['timesheets']);
        $reconciled=$this->publisher()->reconcileUnknown();
        self::assertSame('reconciled_by_get',$reconciled['results'][0]['status']);
        self::assertSame(0,$reconciled['remote_writes']);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
        self::assertSame(1,$this->readState()['post_count']);
    }
}
