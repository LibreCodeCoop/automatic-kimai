<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use RuntimeException;

final class Reconciler
{
    public static function linkedId(mixed $value): ?int
    {
        if (is_array($value))$value=$value['id']??null;
        return is_int($value)?$value:null;
    }

    public static function snapshot(array $row): array
    {
        if (empty($row['end'])||!isset($row['id'])||!is_int($row['id'])) {
            throw new RuntimeException('Finished timesheet with integer ID required');
        }
        return [
            'id'=>$row['id'],
            'begin'=>TimeMath::parse($row['begin'])->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            'end'=>TimeMath::parse($row['end'])->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            'user'=>self::linkedId($row['user']??null),
            'project'=>self::linkedId($row['project']??null),
            'activity'=>self::linkedId($row['activity']??null),
            'billable'=>$row['billable']??null,
            'exported'=>$row['exported']??null,
            'description'=>$row['description']??null,
            'duration'=>$row['duration']??null,
        ];
    }

    public static function overlaps(array $remote,DateTimeImmutable $a,DateTimeImmutable $b,?int $ignore=null): array
    {
        $ids=[];
        foreach ($remote as $entry) {
            if (!isset($entry['begin'])||($entry['id']??null)===$ignore)continue;
            try {
                $x=TimeMath::parse($entry['begin']);
                $y=empty($entry['end'])?null:TimeMath::parse($entry['end']);
                if (TimeMath::ts($x)<TimeMath::ts($b)&&
                    ($y===null||TimeMath::ts($a)<TimeMath::ts($y)))$ids[]=$entry['id'];
            } catch (\Throwable){}
        }
        return $ids;
    }

    private static function decision(string $action,array $window,?int $remoteId,string $reason): array
    {
        return [
            'action'=>$action,'remote_id'=>$remoteId,'reason'=>$reason,
            'session_id'=>$window['id'],'date'=>$window['date'],
            'begin'=>$window['start'],'end'=>$window['end'],
            'human_duration_verified'=>false,'billable'=>false,
        ];
    }

    /**
     * Never changes the server; pure decision based on one authenticated user.
     */
    public function plan(array $window,Profile $profile,array $remote,?array $known,int $userId,
                         bool $allowRestore=false,bool $restoredBefore=false): array
    {
        $a=TimeMath::parse($window['start'],$profile->zone);
        $b=TimeMath::parse($window['end'],$profile->zone);
        if ($window['date']!==$a->format('Y-m-d')||
            !TimeMath::validDailyInterval($a,$b,$profile->zone)) {
            return self::decision('review',$window,null,'invalid_interval');
        }
        if ($window['ongoing']&&!$profile->get('allow_snapshots')) {
            return self::decision('hold',$window,null,'session_still_running');
        }
        if ($known===null) {
            if (self::overlaps($remote,$a,$b)!==[]) {
                return self::decision('review',$window,null,'existing_remote_timesheet_overlap');
            }
            return self::decision('post',$window,null,'new_nonbillable_internal_preview');
        }
        $id=(int)$known['remote_id'];
        $row=null;
        foreach ($remote as $entry)if (($entry['id']??null)===$id)$row=$entry;
        if ($row===null) {
            // Only a specifically authorized repair may reissue a former,
            // nonbillable/nonexported preview. An absent entry may otherwise
            // represent an intentional human deletion.
            $old=$known['remote_snapshot']??[];
            if (!$allowRestore||$restoredBefore||!is_array($old)||
                ($old['id']??null)!==$id||
                !is_bool($old['billable']??null)||($old['exported']??null)!==false||
                ($old['project']??null)!==$profile->get('project_id')||
                ($old['activity']??null)!==$profile->get('activity_id')||
                !in_array($old['user']??null,[null,$userId],true)||
                !is_string($old['description']??null)||trim($old['description'])===''||
                self::overlaps($remote,$a,$b)!==[]) {
                return self::decision('review',$window,$id,'previously_managed_timesheet_missing');
            }
            try {
                if (TimeMath::ts(TimeMath::parse((string)$old['begin']))!==TimeMath::ts($a)||
                    TimeMath::ts(TimeMath::parse((string)$old['end']))!==TimeMath::ts($b)) {
                    return self::decision('review',$window,$id,'restoration_requires_identical_evidence');
                }
            } catch (\Throwable) {
                return self::decision('review',$window,$id,'invalid_saved_timesheet_snapshot');
            }
            return self::decision('restore',$window,$id,
                'previously_managed_preview_missing_explicit_repair');
        }
        $current=self::snapshot($row);
        if ($current!=$known['remote_snapshot']) {
            return self::decision('review',$window,$id,'remote_was_edited_outside_agent');
        }
        if (!in_array($current['user'],[null,$userId],true)||
            $current['project']!==$profile->get('project_id') ||
            $current['activity']!==$profile->get('activity_id') ||
            !is_bool($current['billable']??null)||$current['exported']!==false) {
            return self::decision('review',$window,$id,'contract_or_export_guard');
        }
        $oldStart=TimeMath::parse($row['begin']);$oldEnd=TimeMath::parse($row['end']);
        if (TimeMath::ts($a)!==TimeMath::ts($oldStart)) {
            return self::decision('review',$window,$id,'start_shift_requires_human');
        }
        if (TimeMath::ts($b)<TimeMath::ts($oldEnd)) {
            return self::decision('review',$window,$id,'shrink_or_split_requires_human');
        }
        if (TimeMath::ts($b)===TimeMath::ts($oldEnd)) {
            return self::decision('unchanged',$window,$id,'remote_matches_stable_evidence');
        }
        if (self::overlaps($remote,$oldStart,$b,$id)!==[]) {
            return self::decision('review',$window,$id,'extension_would_overlap_other_timesheet');
        }
        return self::decision('patch',$window,$id,'extend_same_record_preserve_description');
    }

    public static function description(array $window): string
    {
        $topics=array_values(array_filter($window['topics']??[],static fn($value)=>is_string($value)&&trim($value)!==''));
        if ($topics!==[]&&str_starts_with($window['id'],'self'))return rtrim($topics[0],'.').'.';
        if ($topics!==[])return 'Análise técnica e acompanhamento de atividades relacionadas a '.implode(', ',array_slice($topics,0,3)).'.';
        return 'Análise e acompanhamento de atividades técnicas de desenvolvimento e infraestrutura.';
    }

    public static function payload(array $window,Profile $profile): array
    {
        $a=TimeMath::parse($window['start'],$profile->zone);
        $b=TimeMath::parse($window['end'],$profile->zone);
        if (!TimeMath::validDailyInterval($a,$b,$profile->zone)) {
            throw new RuntimeException('Invalid daily timesheet interval');
        }
        return [
            'begin'=>$a->format('Y-m-d\TH:i:s'),
            'end'=>$b->format('Y-m-d\TH:i:s'),
            'project'=>$profile->get('project_id'),
            'activity'=>$profile->get('activity_id'),
            'billable'=>false,'exported'=>false,
            'description'=>self::description($window),
        ];
    }

    public static function verified(?array $row,array $expected,Profile $profile,int $userId): bool
    {
        if (!$row||empty($row['end']))return false;
        try {
            $a=TimeMath::parse($row['begin'],$profile->zone);
            $b=TimeMath::parse($row['end'],$profile->zone);
            return $a->format('Y-m-d\TH:i:s')===$expected['begin']&&
                $b->format('Y-m-d\TH:i:s')===$expected['end'] &&
                ($row['duration']??null)===(int)round(TimeMath::seconds($a,$b))&&
                in_array(self::linkedId($row['user']??null),[null,$userId],true)&&
                self::linkedId($row['project']??null)===$profile->get('project_id') &&
                self::linkedId($row['activity']??null)===$profile->get('activity_id')&&
                ($row['billable']??null)===($expected['billable']??null)&&
                ($row['exported']??null)===false&&
                ($row['description']??null)===$expected['description'];
        } catch (\Throwable) {return false;}
    }

    public function recover(KimaiClient $api,Profile $profile,int $userId,
                            Ledger $ledger,array $days): array
    {
        $pending=$ledger->pending();
        if ($pending===[])return ['recovered'=>[],'unresolved'=>[]];
        $remote=$api->timesheets($userId,$days,$profile->zone);
        $recovered=[];$unresolved=[];
        foreach ($pending as $item) {
            $matches=[];
            foreach ($remote as $row) {
                if (self::verified($row,$item['payload']['kimai'],$profile,$userId) &&
                    ($item['remote_id']===null || (int)$item['remote_id']===$row['id']))$matches[]=$row;
            }
            if (count($matches)!==1) {
                $unresolved[]=[
                    'session_id'=>$item['session_id'],'action'=>$item['action'],
                    'reason'=>'unmatched_or_ambiguous_remote_outcome'
                ];
                continue;
            }
            $ledger->recoverConfirmed($item,$matches[0]);
            $recovered[]=['session_id'=>$item['session_id'],
                'remote_id'=>$matches[0]['id'],'action'=>$item['action']];
        }
        return ['recovered'=>$recovered,'unresolved'=>$unresolved];
    }

    /**
     * A mutation is intentionally unreachable without explicit opt-in,
     * a durable reserved intent and a fresh preflight GET.
     */
    public function execute(array $decision,array $window,KimaiClient $api,
                            Profile $profile,int $userId,Ledger $ledger,
                            bool $allowRestore=false): array
    {
        if (!in_array($decision['action'],['post','patch','restore'],true)) {
            return ['status'=>'skipped','reason'=>$decision['reason']];
        }
        if (!$profile->allowsAutomaticPublication()) {
            throw new RuntimeException('Automatic preview opt-in missing');
        }
        $days=[$window['date']];
        $remote=$api->timesheets($userId,$days,$profile->zone);
        $known=$ledger->known($window['id']);
        $current=$this->plan($window,$profile,$remote,$known,$userId,
            $allowRestore,$ledger->restorationAttempted($window['id']));
        if ($current['action']!==$decision['action']||
            $current['remote_id']!==$decision['remote_id']) {
            throw new RuntimeException('Remote changed after planning; abort');
        }
        $proposed=self::payload($window,$profile);
        $intentIdentity=[
            'account'=>$userId,'profile'=>$profile->get('profile_id'),
            'session'=>$window['id'],'action'=>$decision['action'],
            'new_end'=>$proposed['end'],
        ];
        if ($decision['action']==='restore') {
            $intentIdentity['previous_remote_id']=$decision['remote_id'];
        }
        $key=CanonicalJson::digest($intentIdentity,false);
        if ($ledger->intent($key)!==null) {
            throw new RuntimeException('Existing outbox intent, no blind retry');
        }
        if ($decision['action']==='restore') {
            if (!$allowRestore||$known===null) {
                throw new RuntimeException('Repair must be specifically authorized');
            }
            // Preserve historical fields exactly: the Kimai billable flag is
            // metadata, not evidence of work or production-cooperative pay.
            $proposed['description']=$known['remote_snapshot']['description'];
            $proposed['billable']=$known['remote_snapshot']['billable'];
        }
        if ($decision['action']==='patch') {
            foreach ($remote as $row) {
                if (($row['id']??null)===$decision['remote_id']) {
                    $proposed['description']=$row['description'];
                    $proposed['billable']=$row['billable'];
                    break;
                }
            }
        }
        $ledger->reserve($key,$window,$decision['action'],$proposed);
        try {
            if (in_array($decision['action'],['post','restore'],true)) {
                $remoteId=$api->createTimesheet($proposed);
            } else {
                $remoteId=$decision['remote_id'];
                $api->extendTimesheet($remoteId,$proposed['end']);
            }
        } catch (\Throwable) {
            $ledger->markUnknown($key,$decision['action']==='restore'?null:$decision['remote_id']);
            throw new RuntimeException('Remote result unknown; GET reconciliation required, NO retry');
        }
        try {
            $fresh=$api->timesheets($userId,$days,$profile->zone);
            $found=null;
            foreach ($fresh as $row)if (($row['id']??null)===$remoteId)$found=$row;
            $valid=self::verified($found,$proposed,$profile,$userId);
        } catch (\Throwable) {$valid=false;}
        if (!$valid) {
            $ledger->markUnknown($key,$remoteId);
            throw new RuntimeException('Remote GET mismatch; no blind retry');
        }
        $ledger->confirm($key,$window,$found);
        return ['status'=>'reconciled','action'=>$decision['action'],'remote_id'=>$remoteId];
    }
}
