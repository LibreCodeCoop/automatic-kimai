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
