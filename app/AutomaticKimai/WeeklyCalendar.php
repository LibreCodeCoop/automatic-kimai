<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Pure, read-only projection of remote timesheets and locally observed signals. */
final class WeeklyCalendar
{
    public static function week(string $day, DateTimeZone $zone): array
    {
        $date=TimeMath::dayStart($day,$zone);
        $monday=$date->modify('-'.((int)$date->format('N')-1).' days');
        $days=[];
        for ($i=0;$i<7;$i++)$days[]=$monday->modify('+'.$i.' days')->format('Y-m-d');
        return [
            'days'=>$days,
            'start'=>$days[0],
            'end'=>$days[6],
            'previous'=>$monday->modify('-7 days')->format('Y-m-d'),
            'next'=>$monday->modify('+7 days')->format('Y-m-d'),
            'today'=>(new DateTimeImmutable('now',$zone))->format('Y-m-d'),
        ];
    }

    private static function entity(mixed $value): string
    {
        if (is_array($value))return (string)($value['name']??'');
        return '';
    }

    public static function entries(array $remote, array $report, array $days, DateTimeZone $zone): array
    {
        if ($days===[])return [];
        $weekStart=TimeMath::dayStart($days[0],$zone);
        $weekEnd=TimeMath::dayStart(end($days),$zone)->modify('+1 day');
        $events=[];
        $add=static function(string $type,string $start,string $end,string $title,string $details='') use (&$events,$zone,$weekStart,$weekEnd): void {
            try {
                $a=(new DateTimeImmutable($start,$zone))->setTimezone($zone);
                $b=(new DateTimeImmutable($end,$zone))->setTimezone($zone);
            } catch (Throwable) {return;}
            if ($b <= $a || $b <= $weekStart || $a >= $weekEnd)return;
            if ($a < $weekStart)$a=$weekStart;
            if ($b > $weekEnd)$b=$weekEnd;
            // Split midnight-crossing entries, accounting for local DST boundaries.
            while ($a < $b) {
                $midnight=TimeMath::dayStart($a->modify('+1 day')->format('Y-m-d'),$zone);
                $stop=$b < $midnight ? $b : $midnight;
                if ($stop <= $a)break;
                $day=$a->format('Y-m-d');
                $minutes=(int)$a->format('H')*60+(int)$a->format('i');
                $finish=$stop===$midnight ? 1440 : (int)$stop->format('H')*60+(int)$stop->format('i');
                if ($stop->format('Y-m-d')!==$day)$finish=1440;
                $events[]=[
                    'type'=>$type,'day'=>$day,'start'=>$a->format('H:i'),
                    'end'=>$stop->format('Y-m-d')!==$day?'24:00':$stop->format('H:i'),
                    'title'=>$title,'details'=>$details,
                    'top'=>round($minutes/1440*100,4),
                    'height'=>round(max(1,$finish-$minutes)/1440*100,4),
                    'seconds'=>(int)($stop->getTimestamp()-$a->getTimestamp()),
                    'start_ts'=>$a->getTimestamp(),'end_ts'=>$stop->getTimestamp(),
                ];
                $a=$stop;
            }
        };
        foreach ($remote as $entry) {
            if (!is_array($entry) || !is_string($entry['begin']??null))continue;
            $end=$entry['end']??null;
            if (!is_string($end)||$end==='')continue; // Running timer cannot be presented as a completed interval.
            $project=self::entity($entry['project']??null);
            $activity=self::entity($entry['activity']??null);
            $customer=self::entity($entry['customer']??null);
            $title=trim($project.' · '.$activity,' ·') ?: 'Atividade registrada';
            $detail=trim($customer.' — '.(string)($entry['description']??''),' —');
            $add('registered',$entry['begin'],$end,$title,$detail);
        }
        foreach ($report['days']??[] as $day) {
            if (!is_array($day)||!in_array($day['day']??null,$days,true))continue;
            foreach ($day['review_intervals']??[] as $row) {
                if (is_string($row['start']??null)&&is_string($row['end']??null))
                    $add('observed',$row['start'],$row['end'],'Atividade observada','Duração de trabalho não confirmada');
            }
            foreach ($day['calendar_gap_alerts']??[] as $row) {
                if (is_string($row['start']??null)&&is_string($row['end']??null))
                    $add('scheduled',$row['start'],$row['end'],'Sinal de agenda','Compromisso previsto; presença não comprovada');
            }
        }
        usort($events,static fn($x,$y)=>[$x['start_ts'],$x['type']]<=>[$y['start_ts'],$y['type']]);
        return $events;
    }

    public static function decorate(array $calendar): array
    {
        $total=0;
        $counts=['registered'=>0,'observed'=>0,'scheduled'=>0];
        $daily=[];
        foreach ($calendar['days'] as $day) {
            $spans=[];$seen=['registered'=>0,'observed'=>0,'scheduled'=>0];
            foreach ($calendar['events'] as $event) {
                if ($event['day']!==$day)continue;
                $seen[$event['type']]++;
                if ($event['type']==='registered')$spans[]=[$event['start_ts'],$event['end_ts']];
            }
            usort($spans,static fn($a,$b)=>$a[0]<=>$b[0]);
            $merged=[];
            foreach ($spans as [$a,$b]) {
                $i=count($merged)-1;
                if ($i<0||$a>$merged[$i][1])$merged[]=[$a,$b];
                elseif ($b>$merged[$i][1])$merged[$i][1]=$b;
            }
            $seconds=array_sum(array_map(static fn($v)=>$v[1]-$v[0],$merged));
            $total+=$seconds;
            foreach ($seen as $key=>$value)$counts[$key]+=$value;
            $daily[$day]=['seconds'=>$seconds,'counts'=>$seen];
        }
        $calendar['total_seconds']=$total;
        $calendar['counts']=$counts;
        $calendar['daily']=$daily;
        return $calendar;
    }

    public static function hours(int $seconds): string
    {
        return sprintf('%dh%02d',intdiv($seconds,3600),intdiv($seconds%3600,60));
    }
}
