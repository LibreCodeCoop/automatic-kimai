<?php
declare(strict_types=1);
namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Human-confirmed work, never inferred from scheduled attendance or AI time. */
final class ManualTime
{
    public static function bounds(Profile $profile,string $day,string $start,string $end,
                                  DateTimeImmutable $now): array
    {
        $a=TimeMath::dateTime($day,$start,$profile->zone);
        $b=TimeMath::dateTime($day,$end,$profile->zone);
        $elapsed=TimeMath::seconds($a,$b);
        if ($elapsed<300||$elapsed>$profile->get('max_session_hours')*3600) {
            throw new InvalidArgumentException('Confirm at least 5 minutes, within the session limit');
        }
        $clock=$now->setTimezone($profile->zone);
        if (TimeMath::seconds($b,$clock)<60) {
            throw new InvalidArgumentException('Cannot report work in the future');
        }
        if (TimeMath::seconds($a,$clock)>7*86400) {
            throw new InvalidArgumentException('Older work needs separate historic review');
        }
        return [$a,$b];
    }

    public static function window(Profile $profile,string $day,string $start,string $end,
                                  string $task,DateTimeImmutable $now): array
    {
        [$a,$b]=self::bounds($profile,$day,$start,$end,$now);
        $task=preg_replace('/\s+/u',' ',trim($task))??'';
        if (mb_strlen($task)<3||mb_strlen($task)>64||
            preg_match('/[<>\r\n\x{2028}\x{2029}]/u',$task)) {
            throw new InvalidArgumentException('Short work category required (3-64 characters)');
        }
        $material=[
            'profile'=>$profile->get('profile_id'),'method'=>'worker_explicit_confirmation',
            'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),'task'=>$task,
        ];
        ksort($material);
        $digest=CanonicalJson::digest($material,false);
        return [
            'id'=>'self'.substr($digest,0,21),'date'=>$day,
            'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
            'work_seconds'=>0,'chat_unknown_seconds'=>0,
            'uncovered_seconds'=>(int)round(TimeMath::seconds($a,$b)),
            'ongoing'=>false,'topics'=>[$task],'evidence_digest'=>$digest,
            'human_duration_verified'=>false,'billable'=>false,
        ];
    }

    /**
     * Returns work fragments after subtracting all declared breaks.
     * A schedule alone is NOT attendance confirmation.
     */
    public static function attendanceSegments(Profile $profile,array $plan,DateTimeImmutable $now): array
    {
        if (($plan['attendance_attested']??false)!==true) {
            throw new InvalidArgumentException('Worker must confirm actual attendance');
        }
        if (!is_string($plan['schedule_source']??null) || trim($plan['schedule_source'])==='') {
            throw new InvalidArgumentException('Source of work times is required');
        }
        $day=(string)($plan['day']??'');
        $label=trim((string)($plan['label']??''));
        if (mb_strlen($label)<3||mb_strlen($label)>64)throw new InvalidArgumentException('Invalid attendance label');
        $a=TimeMath::dateTime($day,(string)($plan['start']??''),$profile->zone);
        $b=TimeMath::dateTime($day,(string)($plan['end']??''),$profile->zone);
        $elapsed=TimeMath::seconds($a,$b);
        if ($elapsed<=0||$elapsed>12*3600 ||
            TimeMath::seconds($b,$now->setTimezone($profile->zone))<60||
            TimeMath::seconds($a,$now->setTimezone($profile->zone))>7*86400) {
            throw new InvalidArgumentException('Invalid attendance day or bounds');
        }
        $spans=[[$a,$b]];
        foreach ($plan['breaks']??[] as $break) {
            if (!is_array($break)||count($break)!==2)throw new InvalidArgumentException('Break must be [start,end]');
            $x=TimeMath::dateTime($day,(string)$break[0],$profile->zone);
            $y=TimeMath::dateTime($day,(string)$break[1],$profile->zone);
            if (TimeMath::ts($x)<TimeMath::ts($a)||TimeMath::ts($x)>=TimeMath::ts($y)||
                TimeMath::ts($y)>TimeMath::ts($b))throw new InvalidArgumentException('Break outside attendance');
            $spans=TimeMath::subtract($spans,[[$x,$y]]);
        }
        if (!$spans)throw new InvalidArgumentException('No work remains after breaks');
        $parts=[];
        foreach ($spans as [$left,$right]) {
            self::bounds($profile,$day,$left->format('H:i'),$right->format('H:i'),$now);
            $parts[]=[$left->format('H:i'),$right->format('H:i')];
        }
        return $parts;
    }

    /**
     * Performs one human-confirmed Kimai preview with serialized ledger.
     * No manual write happens unless settings explicitly enable manual posting.
     */
    public function submit(Profile $profile,KimaiClient $api,string $ledgerFile,
                           bool $manualEnabled,string $day,string $start,string $end,
                           string $task,bool $confirmed,DateTimeImmutable $now,
                           string $basis='worker_explicit_confirmation'): array
    {
        if (!$confirmed||!$manualEnabled)throw new RuntimeException('Manual preview not authorized');
        if (!in_array($basis,[
            'worker_explicit_confirmation','reported_attendance_scheduled_bounds',
            'reported_workday_observed_desktop_bounds'],true)) {
            throw new InvalidArgumentException('Unrecognized declaration source');
        }
        $window=self::window($profile,$day,$start,$end,$task,$now);
        $me=$api->me();$user=$me['id']??null;
        if (!is_int($user)||$user<1||($me['timezone']??null)!==$profile->get('timezone')) {
            throw new RuntimeException('Kimai account/timezone does not match local profile');
        }
        $api->validateCatalog($profile);
        $lockName=dirname($ledgerFile).'/daily.lock';
        if (!is_dir(dirname($lockName)))mkdir(dirname($lockName),0700,true);
        if (is_link($lockName))throw new RuntimeException('Symlinked synchronization lock forbidden');
        $lock=fopen($lockName,'c');
        if (!$lock)throw new RuntimeException('Cannot acquire local synchronization lock');
        chmod($lockName,0600);
        if (!flock($lock,LOCK_EX|LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another synchronization is running');
        }
        try {
            $ledger=new Ledger($ledgerFile,$user,$profile->get('profile_id'));
            try {
                $pending=$ledger->pending();
                $days=[$day];
                foreach ($pending as $item) {
                    $date=substr((string)($item['payload']['kimai']['begin']??''),0,10);
                    TimeMath::dayStart($date,$profile->zone);
                    $days[]=$date;
                }
                $reconcile=new Reconciler();
                $recovery=$reconcile->recover($api,$profile,$user,$ledger,array_unique($days));
                if ($recovery['unresolved']!==[])throw new RuntimeException('Uncertain remote write; review before retry');
                $remote=$api->timesheets($user,[$day],$profile->zone);
                $decision=$reconcile->plan($window,$profile,$remote,$ledger->known($window['id']),$user);
                if ($decision['action']==='unchanged') {
                    $ledger->declaration($window,$user,$basis);
                    return ['status'=>'already_recorded','remote_id'=>$decision['remote_id'],
                            'minutes'=>TimeMath::seconds(TimeMath::parse($window['start']),TimeMath::parse($window['end']))/60];
                }
                if ($decision['action']!=='post')throw new RuntimeException('Existing timesheet overlap: '.$decision['reason']);
                $ledger->declaration($window,$user,$basis);
                $authorized=new Profile(array_replace($profile->toArray(),[
                    'sync_mode'=>'preview_nonbillable','allow_auto_nonbillable_preview'=>true
                ]));
                $result=$reconcile->execute($decision,$window,$api,$authorized,$user,$ledger);
                return array_replace($result,[
                    'day'=>$day,'minutes'=>(int)round(TimeMath::seconds(
                        TimeMath::parse($window['start']),TimeMath::parse($window['end']))/60),
                    'basis'=>$basis,'bounds_estimated'=>$basis!=='worker_explicit_confirmation',
                    'billable'=>false,'payroll_approved'=>false
                ]);
            } finally {$ledger->close();}
        } finally {
            flock($lock,LOCK_UN);fclose($lock);
        }
    }
}
