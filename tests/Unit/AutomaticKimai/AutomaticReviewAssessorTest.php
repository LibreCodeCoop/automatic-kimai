<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\AutomaticReviewAssessor;
use App\AutomaticKimai\Profile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AutomaticReviewAssessorTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>3,'activity_id'=>4,
            'auto_min_work_minutes'=>7,
        ]);
    }

    public function test_assessment_clips_evidence_and_never_infers_worked_time(): void
    {
        $candidate=[
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T09:15:00-03:00',
        ];
        $events=[
            ['start'=>'2026-11-12T08:55:00-03:00',
             'end'=>'2026-11-12T09:05:00-03:00','url_kind'=>'github',
             'afk'=>false,'idle_status_known'=>true],
            ['start'=>'2026-11-12T09:02:00-03:00',
             'end'=>'2026-11-12T09:05:00-03:00','url_kind'=>'github',
             'afk'=>false,'idle_status_known'=>true],
            ['start'=>'2026-11-12T09:05:00-03:00',
             'end'=>'2026-11-12T09:12:00-03:00','url_kind'=>'chat',
             'afk'=>false,'idle_status_known'=>true],
            ['start'=>'2026-11-12T09:12:00-03:00',
             'end'=>'2026-11-12T09:15:00-03:00','afk'=>true,
             'idle_status_known'=>true],
        ];
        $markers=[new DateTimeImmutable('2026-11-12T09:04:00-03:00'),
            new DateTimeImmutable('2026-11-12T09:14:00-03:00')];
        $analysis=AutomaticReviewAssessor::assess($candidate,$events,$this->profile(),$markers);
        self::assertSame(900,$analysis['interval_elapsed_seconds_not_worked']);
        self::assertSame(300,$analysis['work_context_seconds']);
        self::assertSame(420,$analysis['unverified_chat_seconds']);
        self::assertSame(180,$analysis['afk_seconds']);
        self::assertSame(2,$analysis['own_action_markers']);
        self::assertFalse($analysis['work_context_reaches_preview_threshold']);
        self::assertFalse($analysis['human_duration_verified']);
        self::assertFalse($analysis['approved_for_publication']);
        self::assertSame(0,$analysis['remote_writes']);
        self::assertArrayNotHasKey('description',$analysis);
        self::assertArrayNotHasKey('customer_id',$analysis);
    }
}
