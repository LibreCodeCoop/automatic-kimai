<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;

/** Schedule and computer activity are review evidence, never payable hours. */
final class Coverage
{
    private static function clipped(string $day, Profile $profile, DateTimeImmutable $now): array
    {
        $floor=TimeMath::dayStart($day,$profile->zone);
        $midnight=$floor->modify('+1 day');
        $clock=$now->setTimezone($profile->zone);
        return [$floor, TimeMath::ts($midnight)<TimeMath::ts($clock)?$midnight:$clock];
    }

    private static function interval(array $row,string $left,string $right,Profile $profile,
                                     DateTimeImmutable $min,DateTimeImmutable $max): ?array
    {
        try {
            $a=TimeMath::parse((string)$row[$left],$profile->zone);
            $b=TimeMath::parse((string)$row[$right],$profile->zone);
            $a=TimeMath::ts($a)<TimeMath::ts($min)?$min:$a;
            $b=TimeMath::ts($b)>TimeMath::ts($max)?$max:$b;
            return TimeMath::ts($a)<TimeMath::ts($b)?[$a,$b]:null;
        } catch (\Throwable) { return null; }
    }

    private static function recorded(array $remote,Profile $profile,DateTimeImmutable $a,DateTimeImmutable $b): array
    {
        $spans=[];
        foreach ($remote as $row) {
            if (!is_array($row)) continue;
            $span=self::interval($row,'begin','end',$profile,$a,$b);
            if ($span!==null) $spans[]=$span;
        }
        return TimeMath::union($spans);
    }

    private static function personal(array $calendar,string $day,Profile $profile,
                                     DateTimeImmutable $a,DateTimeImmutable $b): array
    {
        $excluded=[];
        foreach ($calendar as $event) {
            if (($event['date']??null)!==$day || ($event['all_day']??false) ||
                !in_array($event['category']??null,['personal_excluded','calendar_declined_excluded'],true)) continue;
            $span=self::interval($event,'start_local','end_local',$profile,$a,$b);
            if ($span!==null) $excluded[]=$span;
        }
        return TimeMath::union($excluded);
    }

    /** @return list<array<string,mixed>> */
    public function calendarGaps(string $day,array $calendar,array $remote,Profile $profile,
                                 DateTimeImmutable $now,int $minEvent=15,int $minMissing=5): array
    {
        $floor=TimeMath::dayStart($day,$profile->zone);
        $midnight=$floor->modify('+1 day');
        if (TimeMath::ts($floor)>=TimeMath::ts($now)) return [];
        $recorded=self::recorded($remote,$profile,$floor,$midnight);
        $seen=[];$warnings=[];
        $allowed=['work_candidate','work_event_latinoware','possible_meeting_unclassified',
                  'unclassified_not_work_proof'];
        foreach ($calendar as $event) {
            if (!is_array($event) || ($event['all_day']??null)!==false ||
                !in_array($event['category']??null,$allowed,true) ||
                ($event['scheduled_only']??true)===false) continue;
            $original=self::interval($event,'start_local','end_local',$profile,
                $floor,$midnight);
            if ($original===null) continue;
            try {
                $eventEnd=TimeMath::parse((string)$event['end_local'],$profile->zone);
                if (TimeMath::ts($eventEnd)>TimeMath::ts($now)) continue;
            } catch (\Throwable) {continue;}
            [$a,$b]=$original;
            $duration=TimeMath::seconds($a,$b);
            if ($duration<$minEvent*60) continue;
            $key=TimeMath::iso($a).'|'.TimeMath::iso($b);
            if (isset($seen[$key])) continue;
            $seen[$key]=true;
            $holes=TimeMath::subtract([[$a,$b]],$recorded);
            $missing=array_sum(array_map(static fn($span) =>
                TimeMath::seconds($span[0],$span[1]),$holes));
            if ($missing<$minMissing*60) continue;
            $warnings[]=[
                'day'=>$day,'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
                'category'=>$event['category'],'scheduled_minutes'=>round($duration/60,1),
                'uncovered_schedule_minutes'=>round($missing/60,1),
                'recorded_overlap_minutes'=>round(($duration-$missing)/60,1),
                'uncovered_intervals'=>array_map(static fn($i) =>
                    ['start'=>TimeMath::iso($i[0]),'end'=>TimeMath::iso($i[1])],$holes),
                'reason'=>'scheduled_event_not_fully_covered_by_kimai',
                'source'=>'calendar_schedule_not_attendance_proof',
                'scheduled_only'=>true,'attendance_verified'=>false,'hours_inferred'=>0,
                'requires_human_review'=>true,'billable'=>false,'payroll_approved'=>false,
            ];
        }
        usort($warnings,static fn($a,$b)=>strcmp($a['start'],$b['start']));
        return $warnings;
    }

    public function missingWorkday(string $day,array $remote,Profile $profile,DateTimeImmutable $now): array
    {
        $date=TimeMath::dayStart($day,$profile->zone);
        if (!$profile->get('alert_on_unrecorded_weekdays') ||
            (int)$date->format('N')>=6 ||
            $day >= $now->setTimezone($profile->zone)->format('Y-m-d')) return [];
        foreach ($remote as $entry) {
            try {
                if (TimeMath::parse($entry['begin'],$profile->zone)->format('Y-m-d')===$day) return [];
            } catch (\Throwable) {continue;}
        }
        return [[
            'day'=>$day,'reason'=>'completed_weekday_without_kimai_timesheets',
            'source'=>'expected_workday_policy_not_attendance_proof',
            'activitywatch_may_be_offline'=>true,'hours_inferred'=>0,
            'requires_human_review'=>true,'payroll_approved'=>false
        ]];
    }

    /**
     * Reports unexplained observed activity across ANY already recorded day.
     * A gap is never an automatic timesheet.
     */
    public function observedGaps(string $day,array $events,array $remote,Profile $profile,
                                 DateTimeImmutable $now,array $calendar=[],
                                 array $anchors=[],int $minGap=30,int $minActive=10): array
    {
        [$floor,$ceiling]=self::clipped($day,$profile,$now);
        if (TimeMath::ts($ceiling)<=TimeMath::ts($floor)) return [];
        $visible=[];$work=[];$chat=[];$unknown=[];
        foreach ($events as $event) {
            $span=self::interval($event,'start','end',$profile,$floor,$ceiling);
            if ($span===null || ($event['private']??false) || ($event['afk']??false)) continue;
            if (($event['idle_status_known']??null)!==true) {$unknown[]=$span;continue;}
            $visible[]=$span;
            $kind=Segmenter::classify($event,$profile);
            if ($kind==='work') $work[]=$span;
            if ($kind==='chat') $chat[]=$span;
        }
        $visible=TimeMath::union($visible);
        $actions=[];
        foreach ($anchors as $value) {
            try {
                $t=TimeMath::parse($value,$profile->zone);
                if (TimeMath::ts($t)>=TimeMath::ts($floor) &&
                    TimeMath::ts($t)<TimeMath::ts($ceiling)) $actions[]=$t;
            } catch (\Throwable) {continue;}
        }
        usort($actions,static fn($a,$b)=>TimeMath::ts($a)<=>TimeMath::ts($b));
        if ($visible===[] && count($actions)<2) return [];
        $starts=$visible!==[]?[$visible[0][0]]:[];
        $ends=$visible!==[]?[$visible[count($visible)-1][1]]:[];
        if ($actions!==[]) {
            $starts[]=$actions[0];
            $last=$actions[count($actions)-1]->modify('+1 second');
            $ends[]=TimeMath::ts($last)<TimeMath::ts($ceiling)?$last:$ceiling;
        }
        usort($starts,static fn($a,$b)=>TimeMath::ts($a)<=>TimeMath::ts($b));
        usort($ends,static fn($a,$b)=>TimeMath::ts($b)<=>TimeMath::ts($a));
        $low=$starts[0];$high=$ends[0];
        if (TimeMath::ts($high)<=TimeMath::ts($low)) return [];
        $excluded=self::personal($calendar,$day,$profile,$low,$high);
        $recorded=self::recorded($remote,$profile,$low,$high);
        $holes=TimeMath::subtract([[$low,$high]],TimeMath::union([...$excluded,...$recorded]));
        $warnings=[];
        foreach ($holes as [$a,$b]) {
            $elapsed=TimeMath::seconds($a,$b);
            if ($elapsed<$minGap*60) continue;
            $observed=TimeMath::coveredSeconds($visible,$a,$b);
            $own=count(array_filter($actions,static fn($x)=>
                TimeMath::ts($x)>=TimeMath::ts($a)&&TimeMath::ts($x)<TimeMath::ts($b)));
            if ($observed<$minActive*60 && $own<2) continue;
            $direct=TimeMath::coveredSeconds($work,$a,$b);
            $context=TimeMath::coveredSeconds($chat,$a,$b);
            $unknownSeconds=TimeMath::coveredSeconds($unknown,$a,$b);
            $warnings[]=[
                'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
                'elapsed_minutes'=>round($elapsed/60,1),
                'observed_nonafk_minutes'=>round($observed/60,1),
                'approved_work_context_minutes'=>round($direct/60,1),
                'unverified_chat_minutes'=>round($context/60,1),
                'other_active_minutes'=>round(max(0,$observed-$direct-$context)/60,1),
                'unknown_idle_minutes'=>round($unknownSeconds/60,1),
                'outgoing_external_actions'=>$own,
                'reason'=>$direct>=$profile->get('auto_min_work_minutes')*60 ?
                    'unpublished_approved_context' :
                    ($observed<$minActive*60&&$own>=2 ?
                      'remote_actions_without_measured_duration' : 'unattributed_observed_activity'),
                'needs_human_review'=>true,'human_duration_verified'=>false,'billable'=>false,
            ];
        }
        return $warnings;
    }

    /** Suggest observed unrecorded intervals, not measured human hours. */
    public function reviewable(string $day,array $events,array $remote,Profile $profile,
                               DateTimeImmutable $now,array $calendar=[],array $anchors=[]): array
    {
        [$floor,$ceiling]=self::clipped($day,$profile,$now);
        $rows=[];$blocks=[];$barriers=[];
        foreach ($events as $event) {
            $span=self::interval($event,'start','end',$profile,$floor,$ceiling);
            if ($span===null) continue;
            [$a,$b]=$span;$category=Segmenter::classify($event,$profile);
            $rows[]=[$a,$b,$category];
            if ($category==='private'||($event['idle_status_known']??null)!==true) $barriers[]=$span;
            elseif ($category==='afk') $blocks[]=$span;
        }
        foreach (TimeMath::union($blocks) as $span) {
            if (TimeMath::seconds(...$span)>=$profile->get('long_idle_minutes')*60) $barriers[]=$span;
        }
        $barriers=TimeMath::union([...$barriers,...self::personal($calendar,$day,$profile,$floor,$ceiling)]);
        $possible=[];
        foreach ($rows as [$a,$b,$kind]) {
            if (in_array($kind,['work','chat','other'],true)) $possible[]=[$a,$b];
        }
        $observed=TimeMath::union(TimeMath::subtract($possible,$barriers));
        $groups=[];
        foreach ($observed as [$a,$b]) {
            $last=count($groups)-1;
            $split=$last<0;
            if (!$split) {
                $prev=$groups[$last];
                if (TimeMath::seconds($prev[1],$a)>$profile->get('max_bridge_minutes')*60 ||
                    TimeMath::seconds($prev[0],$b)>$profile->get('max_session_hours')*3600) $split=true;
            }
            if ($split) $groups[]=[$a,$b];
            else $groups[$last][1]=$b;
        }
        $excluded=TimeMath::union([...self::recorded($remote,$profile,$floor,$ceiling),...$barriers]);
        $result=[];$actions=[];
        foreach ($anchors as $t) {
            try {$actions[]=TimeMath::parse($t,$profile->zone);}catch(\Throwable){}
        }
        foreach ($groups as $group) {
            foreach (TimeMath::subtract([$group],$excluded) as [$a,$b]) {
                $a=TimeMath::minuteCeil($a);$b=TimeMath::minuteFloor($b);
                if (TimeMath::ts($a)>=TimeMath::ts($b)) continue;
                $seen=TimeMath::coveredSeconds($observed,$a,$b);
                if ($seen<300) continue;
                $work=[];$chat=[];$other=[];
                foreach ($rows as [$x,$y,$kind]) {
                    if ($kind==='work')$work[]=[$x,$y];
                    if ($kind==='chat')$chat[]=[$x,$y];
                    if ($kind==='other')$other[]=[$x,$y];
                }
                $count=count(array_filter($actions,static fn($t)=>TimeMath::ts($t)>=TimeMath::ts($a)&&TimeMath::ts($t)<TimeMath::ts($b)));
                $result[]=[
                    'day'=>$day,'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
                    'observed_nonafk_minutes'=>round($seen/60,1),
                    'approved_work_context_minutes'=>round(TimeMath::coveredSeconds($work,$a,$b)/60,1),
                    'unverified_chat_minutes'=>round(TimeMath::coveredSeconds($chat,$a,$b)/60,1),
                    'other_active_minutes'=>round(TimeMath::coveredSeconds($other,$a,$b)/60,1),
                    'outgoing_external_actions'=>$count,
                    'includes_unobserved_minutes'=>$seen<TimeMath::seconds($a,$b)-60,
                    'requires_explicit_human_confirmation'=>true,
                    'counts_as_payable_hours'=>false,'reason'=>'active_desktop_context_not_yet_recorded',
                ];
            }
        }
        return $result;
    }
}
