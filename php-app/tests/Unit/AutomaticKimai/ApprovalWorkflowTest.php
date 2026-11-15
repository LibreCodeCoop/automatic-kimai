<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\Profile;
use App\AutomaticKimai\ReviewQueue;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApprovalWorkflowTest extends TestCase
{
    private string $dir;
    private ReviewQueue $queue;
    private Profile $profile;
    private array $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-approval-'.bin2hex(random_bytes(7));
        mkdir($this->dir,0700);
        $this->queue=new ReviewQueue($this->dir.'/review.sqlite3');
        $this->profile=new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
        ]);
        $this->candidate=[
            'id'=>'aw-testapproval0000001','date'=>'2026-11-12',
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T10:00:00-03:00',
            'ongoing'=>false,'end_stable'=>true,
            'remote_intersections'=>[],
            'observed_seconds_by_class'=>['work_context_observed'=>3000],
            'no_aw_data_seconds'=>600,
        ];
        $this->queue->synchronize([$this->candidate],[]);
    }

    protected function tearDown(): void
    {
        $this->queue->close();
        foreach (glob($this->dir.'/*')?:[] as $file)@unlink($file);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function choice(): array
    {
        return [
            'source_digest'=>$this->queue->rows()[0]['source_digest']??'',
            'start'=>'2026-11-12T09:01:00-03:00',
            'end'=>'2026-11-12T09:59:00-03:00',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'billable'=>false,
            'human_duration_confirmed'=>true,
            'project_confirmed'=>true,
            'billing_confirmed'=>true,
            'description'=>'Review of application services and issues.',
        ];
    }

    public function test_approval_is_frozen_only_after_all_explicit_confirmations(): void
    {
        $id=$this->candidate['id'];
        $selection=$this->choice();
        $selection['billing_confirmed']=false;
        try {
            $this->queue->approve($id,$selection,$this->profile,1,
                new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
            self::fail('Approval without billing decision must fail');
        } catch (RuntimeException) {
            self::assertSame('draft',$this->queue->rows()[0]['status']);
        }
        $approved=$this->queue->approve($id,$this->choice(),$this->profile,1,
            new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
        self::assertSame('approved',$approved['status']);
        self::assertFalse($approved['approved_for_payroll']);
        self::assertSame('approved',$this->queue->rows()[0]['status']);
        $rows=$this->queue->approvedRows();
        self::assertCount(1,$rows);
        $raw=json_decode($rows[0]['approval_json'],true,512,JSON_THROW_ON_ERROR);
        self::assertFalse($raw['billable']);
        self::assertSame('explicit_worker_review',$raw['approved_by']);
        self::assertTrue($raw['human_duration_confirmed']);
        self::assertNotEmpty($raw['approval_digest']);
        $this->expectException(RuntimeException::class);
        $this->queue->approve($id,$this->choice(),$this->profile,1,
            new DateTimeImmutable('2026-11-13T12:01:00-03:00'));
    }

    public function test_stale_browser_digest_or_remote_overlap_blocks_approval(): void
    {
        $data=$this->choice();
        $data['source_digest']='old-digest';
        $this->expectException(RuntimeException::class);
        $this->queue->approve($this->candidate['id'],$data,$this->profile,1,
            new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
    }

    public function test_approval_with_contradictory_or_future_bounds_is_rejected(): void
    {
        $selected=$this->choice();
        $selected['end']='2026-11-12T10:05:00-03:00';
        $this->expectException(RuntimeException::class);
        $this->queue->approve($this->candidate['id'],$selected,$this->profile,1,
            new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
    }

    public function test_rejection_sticks_until_evidence_changes_and_approval_cannot_resurrect(): void
    {
        $id=$this->candidate['id'];
        $digest=$this->queue->rows()[0]['source_digest'];
        $this->queue->reject($id,$digest);
        self::assertSame('rejected',$this->queue->rows()[0]['status']);
        $this->queue->synchronize([$this->candidate],[]);
        self::assertSame('rejected',$this->queue->rows()[0]['status']);
        $new=$this->candidate;$new['no_aw_data_seconds']=900;
        $this->queue->synchronize([$new],[]);
        self::assertSame('needs_reapproval',$this->queue->rows()[0]['status']);
    }

    public function test_uncertain_remote_result_remains_blocked_even_if_overlap_appears(): void
    {
        $this->queue->approve($this->candidate['id'],$this->choice(),$this->profile,1,
            new DateTimeImmutable('2026-11-13T12:00:00-03:00'));
        $digest=$this->queue->rows()[0]['source_digest'];
        $this->queue->markUnknown($this->candidate['id'],$digest,null);
        $this->queue->synchronize([$this->candidate],[
            ['id'=>775,'begin'=>'2026-11-12T08:59:00-03:00',
                'end'=>'2026-11-12T10:01:00-03:00'],
        ]);
        self::assertSame('uncertain',$this->queue->rows()[0]['status']);
        self::assertSame([],$this->queue->approvedRows());
    }
}
