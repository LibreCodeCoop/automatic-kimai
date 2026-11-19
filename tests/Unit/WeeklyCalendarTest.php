<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\AutomaticKimai\WeeklyCalendar;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class WeeklyCalendarTest extends TestCase
{
    public function test_week_starts_on_monday_and_handles_year_boundary(): void
    {
        $week=WeeklyCalendar::week('2027-01-01',new DateTimeZone('America/Sao_Paulo'));
        self::assertSame('2026-12-28',$week['start']);
        self::assertSame('2027-01-03',$week['end']);
        self::assertSame('2026-12-21',$week['previous']);
        self::assertSame('2027-01-04',$week['next']);
    }

    public function test_intervals_crossing_midnight_are_split_without_double_counting(): void
    {
        $zone=new DateTimeZone('America/Sao_Paulo');
        $week=WeeklyCalendar::week('2026-11-19',$zone);
        $remote=[
            ['begin'=>'2026-11-18T23:00:00-03:00','end'=>'2026-11-19T01:00:00-03:00',
                'project'=>['name'=>'Project A'],'activity'=>['name'=>'Development']],
            ['begin'=>'2026-11-18T23:30:00-03:00','end'=>'2026-11-18T23:45:00-03:00'],
            ['begin'=>'2026-11-19T10:00:00-03:00','end'=>null],
        ];
        $report=['days'=>[['day'=>'2026-11-19',
            'review_intervals'=>[['start'=>'2026-11-19T09:00:00-03:00','end'=>'2026-11-19T09:30:00-03:00']],
            'calendar_gap_alerts'=>[['start'=>'2026-11-19T12:00:00-03:00','end'=>'2026-11-19T12:40:00-03:00']],
        ]]];
        $week['events']=WeeklyCalendar::entries($remote,$report,$week['days'],$zone);
        $result=WeeklyCalendar::decorate($week);
        self::assertSame(7200,$result['total_seconds']);
        self::assertSame(3600,$result['daily']['2026-11-18']['seconds']);
        self::assertSame(3600,$result['daily']['2026-11-19']['seconds']);
        self::assertSame(3,$result['counts']['registered']);
        self::assertSame(1,$result['counts']['observed']);
        self::assertSame(1,$result['counts']['scheduled']);
        self::assertSame('2h00',WeeklyCalendar::hours($result['total_seconds']));
    }

    public function test_invalid_day_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WeeklyCalendar::week('2026-02-30',new DateTimeZone('UTC'));
    }

    public function test_data_outside_week_and_running_timers_are_ignored(): void
    {
        $zone=new DateTimeZone('UTC');
        $week=WeeklyCalendar::week('2026-11-19',$zone);
        $events=WeeklyCalendar::entries([
            ['begin'=>'2026-11-08T08:00:00+00:00','end'=>'2026-11-08T09:00:00+00:00'],
            ['begin'=>'2026-11-19T08:00:00+00:00','end'=>null],
        ],['days'=>[]],$week['days'],$zone);
        self::assertSame([],$events);
    }
}
