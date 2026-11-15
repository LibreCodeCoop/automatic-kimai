<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\ApprovedSessionPublisher;
use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\Ledger;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\ReviewQueue;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovedSessionPublisherTest extends TestCase
{
    private string $dir;
    private string $queuePath;
    private string $ledgerPath;
    private ReviewQueue $queue;
    private Profile $profile;
    private array $candidate;
    private int $postCalls=0;
    private array $remote=[];
    private bool $failPost=false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-approved-publisher-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700);
        $this->queuePath=$this->dir.'/review.sqlite3';
        $this->ledgerPath=$this->dir.'/ledger.sqlite3';
        $this->profile=new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
        ]);
        $this->candidate=[
            'id'=>'aw-testpublisher000001','date'=>'2026-11-12',
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T10:00:00-03:00',
            'ongoing'=>false,'end_stable'=>true,
            'remote_intersections'=>[],'observed_seconds_by_class'=>[
                'work_context_observed'=>3600
            ],'no_aw_data_seconds'=>0,
        ];
        $this->queue=new ReviewQueue($this->queuePath);
        $this->queue->synchronize([$this->candidate],[]);
        $row=$this->queue->rows()[0];
        $this->queue->approve($row['id'],[
            'source_digest'=>$row['source_digest'],
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T10:00:00-03:00',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'billable'=>false,
            'human_duration_confirmed'=>true,
            'project_confirmed'=>true,'billing_confirmed'=>true,
            'description'=>'Technical coordination and code review for application.',
        ],$this->profile,1,new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
    }

    protected function tearDown(): void
    {
        $this->queue->close();
        foreach (glob($this->dir.'/*')?:[] as $file)@unlink($file);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function client(): KimaiClient
    {
        $transport=function(string $method,string $path,array $params,?array $payload): array {
            if ($method==='GET') {
                $body=match($path) {
                    '/users/me'=>['id'=>1,'timezone'=>'America/Sao_Paulo'],
                    '/customers'=>[['id'=>2,'name'=>'Customer','visible'=>true]],
                    '/projects'=>[['id'=>41,'name'=>'Project','customer'=>2,'visible'=>true]],
                    '/activities'=>[['id'=>25,'name'=>'Development','project'=>41,'visible'=>true]],
                    '/timesheets'=>$this->remote,
                    default=>throw new RuntimeException('Unexpected fake endpoint'),
                };
                return ['status'=>200,'body'=>$body];
            }
            if ($method==='POST'&&$path==='/timesheets'&&$payload!==null) {
                $this->postCalls++;
                if ($this->failPost)throw new RuntimeException('Synthetic timeout');
                $remote=$this->record($payload,778);
                $this->remote[]=$remote;
                return ['status'=>201,'body'=>['id'=>778]];
            }
            throw new RuntimeException('Unexpected mutation');
        };
        return new KimaiClient('http://localhost:4999','fixture-token',$transport);
    }

    private function record(array $payload,int $id): array
    {
        return [
            'id'=>$id,'user'=>1,'project'=>$payload['project'],
            'activity'=>$payload['activity'],'billable'=>$payload['billable'],
            'exported'=>false,'description'=>$payload['description'],
            'begin'=>$payload['begin'].'-03:00','end'=>$payload['end'].'-03:00',
            'duration'=>(new DateTimeImmutable($payload['end'].'-03:00'))
                ->getTimestamp()-(new DateTimeImmutable($payload['begin'].'-03:00'))->getTimestamp(),
        ];
    }

    private function publisher(KimaiClient $client): ApprovedSessionPublisher
    {
        return new ApprovedSessionPublisher($client,$this->profile,
            $this->queuePath,$this->ledgerPath);
    }

    public function test_dry_run_validates_remote_contract_without_creating_outbox_or_posting(): void
    {
        $result=$this->publisher($this->client())->run();
        self::assertSame('dry_run',$result['mode']);
        self::assertSame(0,$result['remote_writes']);
        self::assertSame('validated_dry_run',$result['results'][0]['status']);
        self::assertSame(0,$this->postCalls);
        $ledger=new Ledger($this->ledgerPath,1,'worker');
        self::assertSame([],$ledger->pending());
        $ledger->close();
        self::assertSame('approved',$this->queue->rows()[0]['status']);
    }

    public function test_explicit_once_only_post_is_verified_by_get_and_never_duplicated(): void
    {
        $client=$this->client();
        $result=$this->publisher($client)->run(true);
        self::assertSame(1,$result['remote_writes']);
        self::assertSame('posted_and_verified',$result['results'][0]['status']);
        self::assertSame(1,$this->postCalls);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
        self::assertSame(778,$this->queue->rows()[0]['posted_remote_id']);
        $again=$this->publisher($client)->run(true);
        self::assertSame(0,$again['approved']);
        self::assertSame(0,$again['remote_writes']);
        self::assertSame(1,$this->postCalls);
    }

    public function test_deleted_remote_timesheet_is_not_silently_recreated(): void
    {
        // This regression models the destructive operation the user proposed,
        // but it touches ONLY the fake disposable transport, never real Kimai.
        $client=$this->client();
        $published=$this->publisher($client)->run(true);
        self::assertSame('posted_and_verified',$published['results'][0]['status']);
        self::assertCount(1,$this->remote);
        self::assertSame(1,$this->postCalls);
        // An external user removes the earlier timesheet intentionally.
        $this->remote=[];
        $result=$this->publisher($client)->run(true);
        self::assertSame(0,$result['approved']);
        self::assertSame(0,$result['remote_writes']);
        self::assertSame(1,$this->postCalls);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
        // A GET-only recovery is not allowed to re-POST it either.
        $recovered=$this->publisher($client)->reconcileUnknown();
        self::assertSame(0,$recovered['remote_writes']);
        self::assertSame(1,$this->postCalls);
    }

    public function test_timeout_is_unknown_never_retried_and_get_only_reconciliation_recovers(): void
    {
        $client=$this->client();
        $this->failPost=true;
        $result=$this->publisher($client)->run(true);
        self::assertSame('unknown_no_retry',$result['results'][0]['status']);
        self::assertSame('uncertain',$this->queue->rows()[0]['status']);
        self::assertSame(1,$this->postCalls);
        $this->failPost=false;
        $second=$this->publisher($client)->run(true);
        self::assertSame(0,$second['approved']);
        self::assertSame(1,$this->postCalls);
        $firstRecovery=$this->publisher($client)->reconcileUnknown();
        self::assertSame('unresolved_no_retry',$firstRecovery['results'][0]['status'],json_encode($firstRecovery));
        self::assertSame('uncertain',$this->queue->rows()[0]['status']);
        $approved=json_decode(
            $this->queue->uncertainRows()[0]['approval_json'],true,512,JSON_THROW_ON_ERROR);
        $payload=[
            'begin'=>substr($approved['start'],0,19),
            'end'=>substr($approved['end'],0,19),
            'project'=>$approved['project_id'],'activity'=>$approved['activity_id'],
            'billable'=>$approved['billable'],'exported'=>false,
            'description'=>$approved['description']
        ];
        $this->remote[]=$this->record($payload,779);
        $recovered=$this->publisher($client)->reconcileUnknown();
        self::assertSame('reconciled_by_get',$recovered['results'][0]['status']);
        self::assertSame(0,$recovered['remote_writes']);
        self::assertSame(1,$this->postCalls);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
    }

    public function test_crash_after_outbox_reservation_before_request_requires_get_and_never_reposts(): void
    {
        $row=$this->queue->approvedRows()[0];
        $entry=\App\AutomaticKimai\ApprovedSessionValidator::unpack($row,1,$this->profile);
        $ledger=new Ledger($this->ledgerPath,1,'worker');
        $ledger->reserve($entry['key'],$entry['window'],'post',$entry['payload']);
        $ledger->close();
        $client=$this->client();
        try {
            $this->publisher($client)->run(true);
            self::fail('A pending outbox must block a second POST');
        } catch (RuntimeException) {
            self::assertSame(0,$this->postCalls);
        }
        $unknown=$this->publisher($client)->reconcileUnknown();
        self::assertSame('unresolved_no_retry',$unknown['results'][0]['status']);
        self::assertSame('uncertain',$this->queue->rows()[0]['status']);
        self::assertSame(0,$this->postCalls);
        $this->remote[]=$this->record($entry['payload'],787);
        $recovered=$this->publisher($client)->reconcileUnknown();
        self::assertSame('reconciled_by_get',$recovered['results'][0]['status']);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
        self::assertSame(787,$this->queue->rows()[0]['posted_remote_id']);
        self::assertSame(0,$this->postCalls);
    }

    public function test_crash_after_ledger_confirm_before_queue_update_repairs_by_get(): void
    {
        $row=$this->queue->approvedRows()[0];
        $entry=\App\AutomaticKimai\ApprovedSessionValidator::unpack($row,1,$this->profile);
        $this->remote[]=$this->record($entry['payload'],799);
        $ledger=new Ledger($this->ledgerPath,1,'worker');
        $ledger->reserve($entry['key'],$entry['window'],'post',$entry['payload']);
        $ledger->confirm($entry['key'],$entry['window'],$this->remote[0]);
        $ledger->close();
        $this->queue->markUnknown($row['id'],$row['source_digest'],799);
        $restored=$this->publisher($this->client())->reconcileUnknown();
        self::assertSame('reconciled_by_get',$restored['results'][0]['status']);
        self::assertSame(0,$restored['remote_writes']);
        self::assertSame(0,$this->postCalls);
        self::assertSame('posted',$this->queue->rows()[0]['status']);
        self::assertSame(799,$this->queue->rows()[0]['posted_remote_id']);
    }

    public function test_remote_overlap_blocks_publication_before_reserving_intent(): void
    {
        $client=$this->client();
        $this->remote[]=$this->record([
            'begin'=>'2026-11-12T09:30:00','end'=>'2026-11-12T10:30:00',
            'project'=>41,'activity'=>25,'billable'=>false,
            'description'=>'Existing unrelated user confirmed work.'
        ],888);
        $result=$this->publisher($client)->run(true);
        self::assertSame('blocked',$result['results'][0]['status']);
        self::assertSame(0,$result['remote_writes']);
        self::assertSame(0,$this->postCalls);
    }

    public function test_tampered_approval_is_rejected_before_remote_mutation(): void
    {
        $db=new \PDO('sqlite:'.$this->queuePath);
        $db->exec("UPDATE sessions SET approval_json='{\"candidate_id\":\"forged\",\"approval_digest\":\"bad\"}'");
        unset($db);
        $result=$this->publisher($this->client())->run(true);
        self::assertSame('blocked',$result['results'][0]['status']);
        self::assertSame(0,$result['remote_writes']);
        self::assertSame(0,$this->postCalls);
    }
}
