<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Pure PHP counterpart to the original Python segmenter.
 * Returning candidates does NOT authorize an automatic Kimai submission.
 */
final class Segmenter
{
    public static function classify(array $event, Profile $profile): string
    {
        if (($event['private'] ?? false) === true) return 'private';
        if (($event['idle_status_known'] ?? null) === false) return 'other';
        if (($event['afk'] ?? false) === true) return 'afk';
        if (($event['url_kind'] ?? '') === 'github') return 'work';

        $kind = $event['url_kind'] ?? '';
        if (in_array($kind, ['work_site','internal_domain'], true)) {
            $host = parse_url((string) ($event['url'] ?? ''), PHP_URL_HOST);
            if (is_string($host) && in_array($host, $profile->get('work_domains'), true)) return 'work';
        }
        $hint = $event['messenger_hint'] ?? null;
        if (is_array($hint) && in_array($hint['client_hint'] ?? null,
            $profile->workEvidenceLabels(), true)) return 'work';

        if ($kind === 'chat' || !empty($event['chat_context'])) {
            $topics = $event['chat_context']['topic_hints'] ?? [];
            foreach ($profile->get('work_topic_hints') as $expected) {
                foreach (is_array($topics) ? $topics : [] as $topic) {
                    if (is_string($topic) && stripos($topic,$expected) !== false) return 'work';
                }
            }
            return 'chat';
        }
        return 'other';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function segment(string $day, array $events, array $calendar, Profile $profile, DateTimeImmutable $now): array
    {
        $zone = $profile->zone;
        $floor = TimeMath::dayStart($day, $zone);
        $nextMidnight = $floor->modify('+1 day');
        $clock = $now->setTimezone($zone);
        $ceiling = TimeMath::ts($clock) < TimeMath::ts($nextMidnight) ? $clock : $nextMidnight;
        $rows = [];
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            try {
                $start = TimeMath::parse($event['start'], $zone);
                $end = TimeMath::parse($event['end'], $zone);
                $start = TimeMath::ts($start) < TimeMath::ts($floor) ? $floor : $start;
                $end = TimeMath::ts($end) > TimeMath::ts($ceiling) ? $ceiling : $end;
                if (TimeMath::ts($end) <= TimeMath::ts($start)) continue;
                $rows[] = [$start,$end,self::classify($event,$profile),$event];
            } catch (\Throwable) {
                continue;
            }
        }

        $afk = TimeMath::union(array_map(
            static fn ($row) => [$row[0],$row[1]],
            array_values(array_filter($rows, static fn($row) => $row[2] === 'afk'))
        ));
        $barriers = [];
        foreach ($afk as [$start,$end]) {
            if (TimeMath::seconds($start,$end) >= $profile->get('long_idle_minutes')*60) {
                $barriers[] = [$start,$end];
            }
        }
        foreach ($rows as [$a,$b,$kind,$event]) {
            if ($kind === 'private' || ($event['idle_status_known'] ?? null) === false) {
                $barriers[] = [$a,$b];
            }
        }
        foreach ($calendar as $item) {
            if (!is_array($item) || ($item['date'] ?? null) !== $day || ($item['all_day'] ?? false) ||
                !in_array($item['category'] ?? null, ['personal_excluded','calendar_declined_excluded'],true)) continue;
            try {
                $a = TimeMath::parse((string) $item['start_local'],$zone);
                $b = TimeMath::parse((string) $item['end_local'],$zone);
                if (TimeMath::ts($a) < TimeMath::ts($b)) $barriers[] = [$a,$b];
            } catch (\Throwable) {
                continue;
            }
        }
        $barriers = TimeMath::union($barriers);
        $sources = [];
        foreach ($rows as [$a,$b,$kind]) {
            if ($kind === 'work' || $kind === 'chat') $sources[] = [$a,$b];
        }
        $active = TimeMath::union(TimeMath::subtract($sources,$barriers));
        $groups = [];
        foreach ($active as [$a,$b]) {
            $index = count($groups)-1;
            $split = $index < 0;
            if (!$split) {
                $last = $groups[$index];
                $lastEnd = $last[count($last)-1][1];
                if (TimeMath::seconds($lastEnd,$a) > $profile->get('max_bridge_minutes')*60 ||
                    TimeMath::seconds($last[0][0],$b) > $profile->get('max_session_hours')*3600) {
                    $split = true;
                }
                foreach ($barriers as [$x,$y]) {
                    if (TimeMath::ts($x) < TimeMath::ts($a) &&
                        TimeMath::ts($y) > TimeMath::ts($lastEnd)) $split=true;
                }
            }
            if ($split) $groups[]=[[$a,$b]];
            else $groups[$index][] = [$a,$b];
        }
        $output = [];
        foreach ($groups as $group) {
            $first=$group[0][0];
            $last=$group[count($group)-1][1];
            $a=TimeMath::minuteCeil($first);
            $b=TimeMath::minuteFloor($last);
            // Midnight is an exclusive endpoint, not evidence of the next day.
            if (TimeMath::seconds($last,$nextMidnight) >= 0 &&
                TimeMath::seconds($last,$nextMidnight) <= 1.0 &&
                TimeMath::ts($b) === TimeMath::ts($nextMidnight->modify('-1 minute'))) {
                $b=$nextMidnight;
            }
            if (TimeMath::seconds($a,$b) < $profile->get('min_session_minutes')*60) continue;
            $crossesBarrier=false;
            foreach ($barriers as [$x,$y]) {
                if (TimeMath::ts($x)<TimeMath::ts($b) && TimeMath::ts($a)<TimeMath::ts($y)) {
                    $crossesBarrier=true;
                    break;
                }
            }
            if ($crossesBarrier) continue;

            $work=[];$chat=[];$topics=[];
            foreach ($rows as [$x,$y,$kind,$event]) {
                if ($kind==='work') $work[]=[$x,$y];
                if ($kind==='chat') $chat[]=[$x,$y];
                if (TimeMath::ts($x)<TimeMath::ts($b) && TimeMath::ts($a)<TimeMath::ts($y)) {
                    foreach (($event['chat_context']['topic_hints'] ?? []) as $topic) {
                        if (is_string($topic) && mb_strlen($topic)<64) $topics[]=$topic;
                    }
                }
            }
            $covered=TimeMath::coveredSeconds($group,$a,$b);
            if ($covered<$profile->get('min_context_minutes')*60) continue;
            $workSeconds=TimeMath::coveredSeconds($work,$a,$b);
            $chatSeconds=TimeMath::coveredSeconds($chat,$a,$b);
            $missing=max(0,(int) round(TimeMath::seconds($a,$b))-$covered);
            $topics=array_slice(array_values(array_unique($topics)),0,8);
            sort($topics,SORT_STRING);
            $canonical=[
                'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
                'work'=>$workSeconds,'chat'=>$chatSeconds,
                'missing'=>$missing,'topics'=>$topics,
            ];
            $anchor=$a->setTime((int)$a->format('H'),intdiv((int)$a->format('i'),15)*15);
            $id=substr(hash('sha256',$profile->get('profile_id').'|'.$day.'|'.TimeMath::iso($anchor)),0,24);
            $ongoing=($day===$clock->format('Y-m-d')) &&
                TimeMath::seconds($last,$clock)<$profile->get('settle_minutes')*60;
            $output[]=[
                'id'=>$id,'date'=>$day,'start'=>TimeMath::iso($a),
                'end'=>TimeMath::iso($b),'work_seconds'=>$workSeconds,
                'chat_unknown_seconds'=>$chatSeconds,
                'uncovered_seconds'=>$missing,'ongoing'=>$ongoing,
                'topics'=>$topics,
                'evidence_digest'=>CanonicalJson::digest($canonical),
                'human_duration_verified'=>false,'billable'=>false,
            ];
        }
        for($i=1;$i<count($output);$i++) {
            if (TimeMath::ts(TimeMath::parse($output[$i-1]['end'])) >
                TimeMath::ts(TimeMath::parse($output[$i]['start']))) {
                throw new InvalidArgumentException('Proposed sessions overlap');
            }
        }
        return $output;
    }
}
