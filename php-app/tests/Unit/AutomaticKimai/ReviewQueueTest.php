<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\CanonicalJson;
use App\AutomaticKimai\ReviewCandidateBuilder;
use App\AutomaticKimai\ReviewQueue;
use App\AutomaticKimai\Profile;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReviewQueueTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile([
            'profile_id'=>'sample','timezone'=>'America/Sao_Paulo',
            'customer_id'=>5,'project_id'=>8,'activity_id'=>3,
            'work_topic_hints'=>['sample project'],
            'work_messenger_labels'=>['Sample Client'],
        ]);
    }

    private function candidates(): array
    {
        $events=[
            ['start'=>'2026-11-06T09:00:00-03:00',
             'end'=>'2026-11-06T10:00:00-03:00',
             'idle_status_known'=>true,'private'=>false,'afk'=>false,
             'url_kind'=>'github','url'=>'https://github.com/sample/project'],
        ];
        return (new ReviewCandidateBuilder())->build(
            '2026-11-06',$events,[] ,[],$this->profile(),
            new DateTimeImmutable('2026-11-07T12:00:00-03:00')
        );
    }

    public function test_python_review_candidate_format_stable(): void
    {
        $c=$this->candidates();
        self::assertCount(1,$c);
        self::assertMatchesRegularExpression('/^aw-[0-9a-f]{20}$/D',$c[0]['id']);
        self::assertSame('2026-11-06T09:00:00-03:00',$c[0]['start']);
        self::assertSame(3600,$c[0]['interval_elapsed_seconds_not_worked']);
        self::assertFalse($c[0]['eligible_for_publication']);
        self::assertNull($c[0]['human_work_seconds_confirmed']);
        self::assertNull($c[0]['customer_id']);
    }

    public function test_idempotent_queue_reopen_and_remote_overlap_fail_closed(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $first=$queue->synchronize($candidate,[]);
            self::assertSame(1,$first['draft']);
            self::assertCount(1,$queue->rows());
            $second=$queue->synchronize($candidate,[]);
            self::assertSame(1,$second['draft']);
            self::assertCount(1,$queue->rows());
            $remote=[['id'=>55,'begin'=>'2026-11-06T08:30:00-03:00',
                      'end'=>'2026-11-06T10:30:00-03:00']];
            $queue->synchronize($candidate,$remote);
            self::assertSame('covered',$queue->rows()[0]['status']);
            $queue->close();
            $queue=new ReviewQueue($file);
            self::assertCount(1,$queue->rows());
            self::assertSame(0,fileperms($file)&0077);
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_adjacent_remote_timesheets_cover_entire_candidate_without_conflict(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $adjacent=[
                ['id'=>91,'begin'=>'2026-11-06T09:00:00-03:00',
                 'end'=>'2026-11-06T09:30:00-03:00'],
                ['id'=>92,'begin'=>'2026-11-06T09:30:00-03:00',
                 'end'=>'2026-11-06T10:00:00-03:00'],
            ];
            $queue->synchronize($candidate,$adjacent);
            self::assertSame('covered',$queue->rows()[0]['status']);
            self::assertNull($queue->rows()[0]['existing_timesheet_id']);
            $withGap=$adjacent;
            $withGap[1]['begin']='2026-11-06T09:30:01-03:00';
            $queue->synchronize($candidate,$withGap);
            self::assertSame('reconciled_partial',$queue->rows()[0]['status']);
            self::assertSame(1,$queue->rows()[0]['remote_small_gap_seconds']);
            self::assertSame(0,$queue->rows()[0]['remote_gap_candidates']);
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_partial_overlap_auto_reconciles_and_creates_idempotent_unapproved_gap(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $remote=[
                ['id'=>91,'begin'=>'2026-11-06T09:00:00-03:00',
                 'end'=>'2026-11-06T09:15:00-03:00'],
                ['id'=>92,'begin'=>'2026-11-06T09:45:00-03:00',
                 'end'=>'2026-11-06T10:00:00-03:00'],
            ];
            $queue->synchronize($candidate,$remote);
            $rows=$queue->rows();
            self::assertCount(2,$rows);
            self::assertSame(['reconciled_partial','draft'],
                array_column($rows,'status'));
            self::assertSame(1800,$rows[0]['remote_uncovered_seconds']);
            self::assertSame(1,$rows[0]['remote_gap_candidates']);
            self::assertSame('2026-11-06T09:15:00-03:00',$rows[1]['start']);
            self::assertSame('2026-11-06T09:45:00-03:00',$rows[1]['end']);
            self::assertSame($candidate[0]['id'],$rows[1]['source_parent_id']);
            self::assertSame([], $queue->approvedRows());
            $gapId=$rows[1]['id'];
            $gapDigest=$rows[1]['source_digest'];
            $queue->synchronize($candidate,array_reverse($remote));
            $rows=$queue->rows();
            self::assertCount(2,$rows);
            self::assertSame($gapId,$rows[1]['id']);
            self::assertSame($gapDigest,$rows[1]['source_digest']);
            $remote[0]['end']='2026-11-06T09:45:00-03:00';
            $queue->synchronize($candidate,$remote);
            self::assertSame('covered',$queue->rows()[0]['status']);
            self::assertCount(1,$queue->rows());
            self::assertSame([], $queue->approvedRows());
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_older_backlog_is_read_only_until_remote_reconciliation(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $queue->synchronize($candidate,[
                ['id'=>90,'begin'=>'2026-11-06T09:00:00-03:00','end'=>null]
            ]);
            self::assertSame('conflict',$queue->rows()[0]['status']);
            self::assertSame([],$queue->olderConflicts(['2026-11-06']));
            $history=$queue->olderConflicts(['2026-11-07']);
            self::assertCount(1,$history);
            self::assertSame('conflict',$queue->rows()[0]['status']);
            $queue->synchronize($history,[
                ['id'=>90,'begin'=>'2026-11-06T09:00:00-03:00',
                 'end'=>'2026-11-06T10:00:00-03:00']
            ]);
            self::assertSame('covered',$queue->rows()[0]['status']);
            self::assertSame([],$queue->olderConflicts(['2026-11-07']));
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_prior_human_rejection_survives_automatic_coverage_refresh(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $remote=[['id'=>91,'begin'=>'2026-11-06T09:30:00-03:00',
                       'end'=>'2026-11-06T10:00:00-03:00']];
            // The original decision predates automatic segmentation.
            $queue->synchronize($candidate,[[
                'id'=>91,'begin'=>'2026-11-06T09:30:00-03:00','end'=>null]]);
            $row=$queue->rows()[0];
            $queue->dismissConflict($row['id'],$row['source_digest'],'not_worked');
            $queue->synchronize($candidate,$remote);
            self::assertCount(1,$queue->rows());
            self::assertSame('rejected',$queue->rows()[0]['status']);
            self::assertSame([], $queue->approvedRows());
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_approved_derived_gap_becomes_stale_when_remote_coverage_changes(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $remote=[
                ['id'=>91,'begin'=>'2026-11-06T09:00:00-03:00',
                 'end'=>'2026-11-06T09:15:00-03:00'],
                ['id'=>92,'begin'=>'2026-11-06T09:45:00-03:00',
                 'end'=>'2026-11-06T10:00:00-03:00'],
            ];
            $queue->synchronize($candidate,$remote);
            $gap=$queue->rows()[1];
            $queue->approve($gap['id'],[
                'source_digest'=>$gap['source_digest'],
                'start'=>$gap['start'],'end'=>$gap['end'],
                'customer_id'=>5,'project_id'=>8,'activity_id'=>3,
                'human_duration_confirmed'=>true,'project_confirmed'=>true,
                'description'=>'Confirmed independent work'
            ],$this->profile(),1,new DateTimeImmutable('2026-11-07T12:00:00-03:00'));
            self::assertCount(1,$queue->approvedRows());
            $remote[0]['end']='2026-11-06T09:45:00-03:00';
            $queue->synchronize($candidate,$remote);
            self::assertSame([], $queue->approvedRows());
            $db=new PDO('sqlite:'.$file);
            $stmt=$db->prepare('SELECT status,approval_json FROM sessions WHERE id=?');
            $stmt->execute([$gap['id']]);
            $archived=$stmt->fetch(PDO::FETCH_ASSOC);
            self::assertSame('stale',$archived['status']);
            self::assertNotEmpty($archived['approval_json']);
            $db=null;
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_unfinished_remote_record_cannot_be_auto_reconciled(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $queue->synchronize($this->candidates(),[
                ['id'=>94,'begin'=>'2026-11-06T09:30:00-03:00','end'=>null],
            ]);
            self::assertSame('conflict',$queue->rows()[0]['status']);
            self::assertCount(1,$queue->rows());
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_approved_frozen_digest_becomes_needs_reapproval_when_evidence_changes(): void
    {
        $dir=sys_get_temp_dir().'/kimai-review-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $file=$dir.'/queue.sqlite3';
        try {
            $queue=new ReviewQueue($file);
            $candidate=$this->candidates();
            $queue->synchronize($candidate,[]);
            $queue->close();
            $db=new PDO('sqlite:'.$file);
            $db->prepare("UPDATE sessions SET status='approved',approval_json=?")
               ->execute([json_encode(['explicit'=>true],JSON_THROW_ON_ERROR)]);
            $db=null;
            $candidate[0]['no_aw_data_seconds']=120;
            $queue=new ReviewQueue($file);
            $queue->synchronize($candidate,[]);
            self::assertSame('needs_reapproval',$queue->rows()[0]['status']);
            self::assertSame(1,count($queue->rows()));
            $queue->close();
        } finally {@unlink($file);@rmdir($dir);}
    }

    public function test_legacy_python_approval_digest_uses_canonical_json(): void
    {
        $candidate=$this->candidates()[0];
        $expected=ReviewQueue::fingerprint($candidate);
        $keys=['date','start','end','remote_intersections','ongoing',
               'observed_seconds_by_class','no_aw_data_seconds'];
        $subset=array_intersect_key($candidate,array_flip($keys));
        self::assertSame(CanonicalJson::digest($subset),$expected);
    }
}
