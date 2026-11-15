<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\Coverage;
use App\AutomaticKimai\Profile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CoverageTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile([
            'profile_id'=>'worker-test', 'timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'alert_on_unrecorded_weekdays'=>true
        ]);
    }

    private function calendar(): array
    {
        return [
            'date'=>'2026-11-06','start_local'=>'2026-11-06T15:00:00-03:00',
            'end_local'=>'2026-11-06T17:00:00-03:00',
            'all_day'=>false,'scheduled_only'=>true,
            'category'=>'possible_meeting_unclassified',
            'private_summary'=>'should never appear in report',
        ];
    }

    public function test_ufrj_scheduled_gap_even_when_other_work_exists(): void
    {
        $remote=[['begin'=>'2026-11-06T12:00:00-03:00','end'=>'2026-11-06T13:38:00-03:00']];
        $gaps=(new Coverage())->calendarGaps('2026-11-06',[$this->calendar()],$remote,
            $this->profile(),new DateTimeImmutable('2026-11-07T15:00:00-03:00'));
        self::assertCount(1,$gaps);
        self::assertSame(120.0,$gaps[0]['uncovered_schedule_minutes']);
        self::assertSame(0,$gaps[0]['hours_inferred']);
        self::assertFalse($gaps[0]['payroll_approved']);
        self::assertArrayNotHasKey('private_summary',$gaps[0]);
    }

    public function test_gap_resolved_after_existing_timesheet_and_duplicates_ignored(): void
    {
        $existing=[['begin'=>'2026-11-06T15:00:00-03:00',
                    'end'=>'2026-11-06T17:00:00-03:00']];
        self::assertSame([],(new Coverage())->calendarGaps('2026-11-06',
            [$this->calendar(),$this->calendar()],$existing,
            $this->profile(),new DateTimeImmutable('2026-11-07T15:00:00-03:00')));
    }

    public function test_partial_coverage(): void
    {
        $existing=[['begin'=>'2026-11-06T15:00:00-03:00',
                    'end'=>'2026-11-06T16:00:00-03:00']];
        $alerts=(new Coverage())->calendarGaps('2026-11-06',
            [$this->calendar()],$existing,$this->profile(),
            new DateTimeImmutable('2026-11-07T15:00:00-03:00'));
        self::assertSame(60.0,$alerts[0]['uncovered_schedule_minutes']);
        self::assertSame('2026-11-06T16:00:00-03:00',
            $alerts[0]['uncovered_intervals'][0]['start']);
    }

    public function test_calendar_does_not_prove_a_person_worked(): void
    {
        $item=$this->calendar();
        $item['category']='personal_excluded';
        self::assertSame([],(new Coverage())->calendarGaps('2026-11-06',[$item],[],
            $this->profile(),new DateTimeImmutable('2026-11-07T15:00:00-03:00')));
        $item=$this->calendar();
        self::assertSame([],(new Coverage())->calendarGaps('2026-11-06',[$item],[],
            $this->profile(),new DateTimeImmutable('2026-11-06T16:30:00-03:00')));
    }

    public function test_workday_without_kimai_is_only_a_review(): void
    {
        $r=(new Coverage())->missingWorkday('2026-11-06',[],$this->profile(),
            new DateTimeImmutable('2026-11-07T15:00:00-03:00'));
        self::assertCount(1,$r);
        self::assertSame(0,$r[0]['hours_inferred']);
    }

    public function test_unrecorded_browser_activity_remains_review(): void
    {
        $event=[
            'start'=>'2026-11-06T09:00:00-03:00','end'=>'2026-11-06T10:00:00-03:00',
            'url_kind'=>'chat','idle_status_known'=>true,'afk'=>false,'private'=>false
        ];
        $coverage=new Coverage();
        $gaps=$coverage->observedGaps('2026-11-06',[$event],[],
            $this->profile(),new DateTimeImmutable('2026-11-07T15:00:00-03:00'));
        self::assertCount(1,$gaps);
        self::assertSame(60.0,$gaps[0]['unverified_chat_minutes']);
        self::assertFalse($gaps[0]['human_duration_verified']);
        $reviews=$coverage->reviewable('2026-11-06',[$event],[],
            $this->profile(),new DateTimeImmutable('2026-11-07T15:00:00-03:00'));
        self::assertCount(1,$reviews);
        self::assertTrue($reviews[0]['requires_explicit_human_confirmation']);
    }
}
