<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;
use App\AutomaticKimai\ManualTime;
use App\AutomaticKimai\Profile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

final class ManualTimeTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile(['profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25]);
    }

    public function test_manual_work_does_not_claim_sensor_evidence(): void
    {
        $w=ManualTime::window($this->profile(),'2026-11-06','15:00','17:00',
            'University presentation',new DateTimeImmutable('2026-11-07T09:00:00-03:00'));
        self::assertSame(0,$w['work_seconds']);
        self::assertSame(7200,$w['uncovered_seconds']);
        self::assertFalse($w['human_duration_verified']);
        self::assertSame(['University presentation'],$w['topics']);
    }

    public function test_attendance_breaks_are_subtracted(): void
    {
        $parts=ManualTime::attendanceSegments($this->profile(),[
            'day'=>'2026-11-06','start'=>'09:00','end'=>'17:00',
            'breaks'=>[['12:00','13:00'],['15:30','16:00']],
            'label'=>'Presentation','schedule_source'=>'User confirmed actual time',
            'attendance_attested'=>true,
        ],new DateTimeImmutable('2026-11-07T09:00:00-03:00'));
        self::assertSame([['09:00','12:00'],['13:00','15:30'],['16:00','17:00']],$parts);
    }

    public function test_scheduled_invitation_alone_cannot_assert_work(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ManualTime::attendanceSegments($this->profile(),[
            'day'=>'2026-11-06','start'=>'09:00','end'=>'17:00',
            'breaks'=>[],'label'=>'Scheduled event',
            'schedule_source'=>'Calendar','attendance_attested'=>false
        ],new DateTimeImmutable('2026-11-07T09:00:00-03:00'));
    }
}
