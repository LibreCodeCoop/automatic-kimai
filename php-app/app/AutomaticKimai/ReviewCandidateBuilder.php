<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Plausible ActivityWatch review envelopes. These are not measurements of
 * paid hours, and do not assign a customer, project or billability.
 *
 * The stable candidate ID follows the Python workday_session_engine format.
 */
final class ReviewCandidateBuilder
{
    private const MAX_ANCHOR_GAP = 1200;
    private const MAX_ENVELOPE = 21600;
    private const MIN_ENVELOPE = 900;
    private const MIN_CONTEXT = 480;
    private const END_SETTLE = 720;

    /**
     * Candidate-only classification, configured by the user rather than by
     * hard-coded organization or customer names. This intentionally differs
     * from the automatic publication classifier.
     */
    private static function candidateKind(array $event,Profile $profile): string
    {
        if (($event['private']??false)===true)return 'private';
        if (($event['idle_status_known']??null)===false)return 'other_or_unknown';
        if (($event['afk']??false)===true)return 'afk_unresolved';
        if (($event['url_kind']??null)==='github')return 'work_context_observed';
        $hint=$event['messenger_hint']['client_hint']??null;
        if (is_string($hint)&&in_array($hint,$profile->get('work_messenger_labels'),true)) {
            return 'work_context_observed';
        }
        if (!empty($event['talk_context']['room_fingerprint']))return 'work_context_observed';
        if (($event['url_kind']??null)==='chat'||!empty($event['chat_context'])) {
            foreach ($event['chat_context']['topic_hints']??[] as $topic) {
                if (is_string($topic)&&in_array(mb_strtolower(trim($topic)),
                    array_map('mb_strtolower',$profile->get('work_topic_hints')),true)) {
                    return 'work_context_observed';
                }
            }
            return 'chatgpt_unknown_purpose';
        }
        return 'other_or_unknown';
    }

    public function build(string $day,array $events,array $calendar,
                          array $remote,Profile $profile,DateTimeImmutable $now): array
    {
        $startOfDay=TimeMath::dayStart($day,$profile->zone);
        $nextDay=$startOfDay->modify('+1 day');
        $now=$now->setTimezone($profile->zone);
        $ceiling=TimeMath::ts($now)<TimeMath::ts($nextDay)?$now:$nextDay;
        if (TimeMath::ts($ceiling)<=TimeMath::ts($startOfDay))return [];
        $rows=[];$barriers=[];$active=[];
        foreach ($events as $event) {
            if (!is_array($event)||!isset($event['start'],$event['end']))continue;
            try {
                $a=TimeMath::parse($event['start'],$profile->zone);
                $b=TimeMath::parse($event['end'],$profile->zone);
                $a=TimeMath::ts($a)<TimeMath::ts($startOfDay)?$startOfDay:$a;
                $b=TimeMath::ts($b)>TimeMath::ts($ceiling)?$ceiling:$b;
                if (TimeMath::ts($b)<=TimeMath::ts($a))continue;
                $category=self::candidateKind($event,$profile);
                $rows[]=[$a,$b,$category,$event];
                if ($category==='private'||($event['idle_status_known']??null)===false ||
                    ($category==='afk_unresolved'&&TimeMath::seconds($a,$b)>=600)) {
                    $barriers[]=[$a,$b];
                } elseif (in_array($category,['work_context_observed','chatgpt_unknown_purpose'],true)) {
                    $active[]=[$a,$b];
                }
            } catch (\Throwable) {continue;}
        }
        foreach ($calendar as $entry) {
            if (!is_array($entry)||($entry['date']??null)!==$day||
                ($entry['all_day']??false)===true||
                !in_array($entry['category']??null,['personal_excluded','calendar_declined_excluded'],true))continue;
            try {
                $a=TimeMath::parse($entry['start_local'],$profile->zone);
                $b=TimeMath::parse($entry['end_local'],$profile->zone);
                if (TimeMath::ts($a)<TimeMath::ts($b))$barriers[]=[$a,$b];
            } catch (\Throwable){}
        }
        $barriers=TimeMath::union($barriers);
        $active=TimeMath::union(TimeMath::subtract($active,$barriers));
        // The Python queue cuts single long observations into bounded
        // envelopes. Never assume that one continuous browser window is work.
        $sliced=[];
        foreach ($active as [$a,$b]) {
            while (TimeMath::seconds($a,$b)>self::MAX_ENVELOPE) {
                $next=$a->modify('+6 hours');
                $sliced[]=[$a,$next];
                $a=$next;
            }
            if (TimeMath::ts($a)<TimeMath::ts($b))$sliced[]=[$a,$b];
        }
        $groups=[];
        foreach ($sliced as [$a,$b]) {
            $last=count($groups)-1;$split=$last<0;
            if (!$split) {
                $prior=$groups[$last];
                $tail=$prior[count($prior)-1][1];
                $blocked=false;
                foreach ($barriers as [$left,$right]) {
                    if (TimeMath::ts($left)<TimeMath::ts($a) &&
                        TimeMath::ts($tail)<TimeMath::ts($right) &&
                        TimeMath::ts($right)>TimeMath::ts($tail) &&
                        TimeMath::ts($left)<TimeMath::ts($a)) {
                        $blocked=true;break;
                    }
                }
                $split=$blocked || TimeMath::seconds($tail,$a)>self::MAX_ANCHOR_GAP ||
                    TimeMath::seconds($prior[0][0],$b)>self::MAX_ENVELOPE;
            }
            if ($split)$groups[]=[[$a,$b]];
            else $groups[$last][]=[$a,$b];
        }
        $result=[];
        foreach ($groups as $group) {
            $first=$group[0][0];$last=$group[count($group)-1][1];
            if (TimeMath::seconds($first,$last)<self::MIN_ENVELOPE)continue;
            if (array_sum(array_map(static fn($x)=>TimeMath::seconds($x[0],$x[1]),$group))<self::MIN_CONTEXT)continue;
            $blocked=false;
            foreach ($barriers as [$a,$b]) {
                if (TimeMath::ts($a)<TimeMath::ts($last)&&TimeMath::ts($first)<TimeMath::ts($b)) {
                    $blocked=true;break;
                }
            }
            if ($blocked)continue;
            $metrics=array_fill_keys([
                'work_context_observed','chatgpt_unknown_purpose','afk_unresolved',
                'private','other_or_unknown'
            ],0);
            $spans=[];$sourceCount=[];$topics=[];
            foreach ($rows as [$a,$b,$category,$event]) {
                if (!(TimeMath::ts($a)<TimeMath::ts($last)&&TimeMath::ts($first)<TimeMath::ts($b)))continue;
                $left=TimeMath::ts($a)<TimeMath::ts($first)?$first:$a;
                $right=TimeMath::ts($b)>TimeMath::ts($last)?$last:$b;
                $metrics[$category]+=TimeMath::seconds($left,$right);
                $spans[]=[$left,$right];
                if ($category==='work_context_observed'||$category==='chatgpt_unknown_purpose') {
                    $label='activitywatch_'.$category;
                    $sourceCount[$label]=($sourceCount[$label]??0)+1;
                }
                if (($event['url_kind']??null)==='github') {
                    $sourceCount['github_foreground']=($sourceCount['github_foreground']??0)+1;
                }
                if (!empty($event['messenger_hint']['client_hint'])) {
                    $sourceCount['telegram_foreground']=($sourceCount['telegram_foreground']??0)+1;
                }
                if (!empty($event['talk_context']['room_fingerprint'])) {
                    $sourceCount['talk_foreground']=($sourceCount['talk_foreground']??0)+1;
                }
                foreach ($event['chat_context']['topic_hints']??[] as $topic) {
                    if (!is_string($topic)||mb_strlen($topic)>=80)continue;
                    $topics[$topic]=($topics[$topic]??0)+1;
                }
            }
            $intersections=[];
            foreach ($remote as $item) {
                try {
                    $a=TimeMath::parse($item['begin'],$profile->zone);
                    $b=TimeMath::parse($item['end'],$profile->zone);
                    if (TimeMath::ts($a)<TimeMath::ts($last)&&TimeMath::ts($first)<TimeMath::ts($b) &&
                        is_int($item['id']??null))$intersections[]=$item['id'];
                } catch (\Throwable){}
            }
            $cut=$first->setTime((int)$first->format('H'),intdiv((int)$first->format('i'),15)*15);
            $id='aw-'.substr(hash('sha256',$day.'|aw|'.TimeMath::iso($cut)),0,20);
            $ongoing=TimeMath::seconds($last,$now)<=self::END_SETTLE;
            arsort($topics,SORT_NUMERIC);
            $outTopics=array_slice(array_keys($topics),0,6);
            $totalCovered=TimeMath::coveredSeconds($spans,$first,$last);
            foreach ($metrics as &$value)$value=(int)round($value);
            unset($value);
            $result[]=[
                'id'=>$id,'date'=>$day,'start'=>TimeMath::iso($first),'end'=>TimeMath::iso($last),
                'interval_elapsed_seconds_not_worked'=>(int)round(TimeMath::seconds($first,$last)),
                'observed_seconds_by_class'=>$metrics,
                'no_aw_data_seconds'=>max(0,(int)round(TimeMath::seconds($first,$last)-$totalCovered)),
                'observer'=>'activitywatch_sanitized','confirmed_some_work_on_day'=>false,
                'human_work_seconds_confirmed'=>null,
                'project_id'=>null,'customer_id'=>null,'activity_id'=>null,'billable'=>null,
                'project_options_unverified'=>[],
                'topic_hints'=>$outTopics,'sources'=>$sourceCount,
                'remote_intersections'=>array_values(array_unique($intersections)),
                'requires_remote_reconciliation'=>true,'requires_user_approval'=>true,
                'ready_for_payroll'=>false,'eligible_for_publication'=>false,
                'session_kind'=>'aw_session_candidate',
                'ongoing'=>$ongoing,'end_stable'=>!$ongoing,
                'uncertainty'=>'Work/chat foreground and context gaps are not measured human labor; shared account attribution needs review',
            ];
        }
        usort($result,static fn($a,$b)=>strcmp($a['start'],$b['start']));
        for ($i=1;$i<count($result);$i++) {
            if (TimeMath::ts(TimeMath::parse($result[$i-1]['end']))>
                TimeMath::ts(TimeMath::parse($result[$i]['start']))) {
                throw new RuntimeException('Review candidates must not overlap');
            }
        }
        return $result;
    }
}
