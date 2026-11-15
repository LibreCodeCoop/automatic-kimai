<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;

/**
 * Autonomous review of an unregistered *hypothesis*, not labor certification.
 *
 * A foreground window or a remote action is evidence of context, never proof
 * that the whole interval was worked. This class cannot approve, reject as
 * "not worked", assign a project, or create a timesheet.
 */
final class AutomaticReviewAssessor
{
    public static function assess(array $candidate,array $events,Profile $profile,
                                  array $ownActions=[]): array
    {
        $a=TimeMath::parse((string)$candidate['start'],$profile->zone);
        $b=TimeMath::parse((string)$candidate['end'],$profile->zone);
        $spans=[];
        foreach ($events as $event) {
            if (!is_array($event) || !isset($event['start'],$event['end']))continue;
            try {
                $x=TimeMath::parse((string)$event['start'],$profile->zone);
                $y=TimeMath::parse((string)$event['end'],$profile->zone);
            } catch (\Throwable) {continue;}
            if (TimeMath::ts($x)>=TimeMath::ts($b)||TimeMath::ts($y)<=TimeMath::ts($a))continue;
            $kind=Segmenter::classify($event,$profile);
            $spans[$kind][]=[$x,$y];
        }
        $seconds=[];
        foreach (['work','chat','other','afk','private'] as $kind) {
            $seconds[$kind]=(int)TimeMath::coveredSeconds($spans[$kind]??[],$a,$b);
        }
        $actions=0;
        foreach ($ownActions as $time) {
            if (!$time instanceof DateTimeImmutable)continue;
            if (TimeMath::ts($time)>=TimeMath::ts($a)&&
                TimeMath::ts($time)<TimeMath::ts($b))$actions++;
        }
        // Deliberately no inference from the elapsed interval to human labor.
        // A qualifying professional-context signal may still be published
        // separately by the configured automatic preview writer, never here.
        return [
            'decision'=>'observation_without_verified_duration',
            'interval_elapsed_seconds_not_worked'=>(int)round(TimeMath::seconds($a,$b)),
            'work_context_seconds'=>$seconds['work'],
            'unverified_chat_seconds'=>$seconds['chat'],
            'other_context_seconds'=>$seconds['other'],
            'afk_seconds'=>$seconds['afk'],
            'private_seconds'=>$seconds['private'],
            'own_action_markers'=>$actions,
            'work_context_reaches_preview_threshold'=>
                $seconds['work']>=$profile->get('auto_min_work_minutes')*60,
            'human_duration_verified'=>false,
            'approved_for_publication'=>false,
            'remote_writes'=>0,
            'follow_up'=>'periodic_remote_reconciliation',
        ];
    }
}
