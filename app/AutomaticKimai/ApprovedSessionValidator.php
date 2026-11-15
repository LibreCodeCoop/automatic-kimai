<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeZone;
use RuntimeException;

/** Immutable approved payload validation, separate from remote transport. */
final class ApprovedSessionValidator
{
    public static function unpack(array $row,int $userId,Profile $profile): array
    {
        $raw=json_decode((string)($row['approval_json']??''),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($raw))throw new RuntimeException('Approval record malformed');
        $digest=$raw['approval_digest']??null;
        unset($raw['approval_digest']);
        if (!is_string($digest)||!hash_equals($digest,CanonicalJson::digest($raw))) {
            throw new RuntimeException('Frozen approval checksum mismatch');
        }
        if (!in_array(($row['status']??null),['approved','uncertain'],true)||
            ($row['source_digest']??null)!==($raw['source_digest']??null)||
            ($row['id']??null)!==($raw['candidate_id']??null)||
            ($raw['user_id']??null)!==$userId||
            ($raw['approved_by']??null)!=='explicit_worker_review'||
            ($raw['action']??null)!=='new'||($raw['existing_timesheet_id']??null)!==null||
            ($raw['human_duration_confirmed']??null)!==true||
            ($raw['project_confirmed']??null)!==true||
            !is_bool($raw['billable']??null)) {
            throw new RuntimeException('Stale, unsupported or nonhuman approval');
        }
        foreach (['customer_id','project_id','activity_id'] as $field) {
            if (!is_int($raw[$field]??null)||$raw[$field]<1) {
                throw new RuntimeException('Approved contract identity missing');
            }
        }
        $a=TimeMath::parse((string)$raw['start'],$profile->zone);
        $b=TimeMath::parse((string)$raw['end'],$profile->zone);
        $length=TimeMath::seconds($a,$b);
        if ($length<300||$length>21600||
            $a->format('Y-m-d')!==$b->format('Y-m-d')||
            $a->format('Y-m-d')!==$row['day']||
            $a->format('s.u')!=='00.000000'||$b->format('s.u')!=='00.000000') {
            throw new RuntimeException('Invalid reviewed bounds');
        }
        $description=(string)($raw['description']??'');
        if (mb_strlen($description)<10||mb_strlen($description)>500||
            preg_match('/[<>\x00-\x1f\x7f]/u',$description)) {
            throw new RuntimeException('Description missing or malformed');
        }
        $payload=[
            'begin'=>$a->format('Y-m-d\TH:i:s'),
            'end'=>$b->format('Y-m-d\TH:i:s'),
            'project'=>$raw['project_id'],'activity'=>$raw['activity_id'],
            'billable'=>$raw['billable'],'exported'=>false,
            'description'=>$description,
        ];
        $outboxKey=CanonicalJson::digest([
            'version'=>1,'user'=>$userId,'candidate_id'=>$raw['candidate_id'],
            'source_digest'=>$raw['source_digest'],'approval_digest'=>$digest,
            'payload'=>$payload,
        ],false);
        $window=['id'=>'approved:'.$raw['candidate_id'],
            'evidence_digest'=>$raw['source_digest']];
        return ['approval'=>$raw,'payload'=>$payload,'window'=>$window,'key'=>$outboxKey];
    }

    public static function validateCatalog(array $catalog,array $approval): void
    {
        $project=null;$activity=null;
        foreach ($catalog['projects']??[] as $candidate) {
            if (($candidate['id']??null)===$approval['project_id'])$project=$candidate;
        }
        foreach ($catalog['activities']??[] as $candidate) {
            if (($candidate['id']??null)===$approval['activity_id'])$activity=$candidate;
        }
        if (!$project||!$activity||
            ($project['visible']??null)===false||
            ($activity['visible']??null)===false||
            ($project['customer_id']??null)!==$approval['customer_id']||
            !in_array($activity['project_id']??null,[null,$approval['project_id']],true)) {
            throw new RuntimeException('Approved project and activity not in live catalog');
        }
    }

    public static function matches(?array $remote,array $payload,int $userId,DateTimeZone $zone): bool
    {
        if (!$remote||!is_int($remote['id']??null)||empty($remote['end']))return false;
        try {
            $a=TimeMath::parse($remote['begin'],$zone);
            $b=TimeMath::parse($remote['end'],$zone);
            return $a->format('Y-m-d\TH:i:s')===$payload['begin']&&
                $b->format('Y-m-d\TH:i:s')===$payload['end']&&
                ($remote['duration']??null)===(int)round(TimeMath::seconds($a,$b))&&
                in_array(Reconciler::linkedId($remote['user']??null),[null,$userId],true)&&
                Reconciler::linkedId($remote['project']??null)===$payload['project']&&
                Reconciler::linkedId($remote['activity']??null)===$payload['activity']&&
                ($remote['billable']??null)===$payload['billable']&&
                ($remote['exported']??null)===false&&
                ($remote['description']??null)===$payload['description'];
        } catch (\Throwable) {
            return false;
        }
    }

    public static function exactMatches(array $remote,array $payload,int $userId,DateTimeZone $zone): array
    {
        return array_values(array_filter($remote,
            static fn($row)=>is_array($row)&&self::matches($row,$payload,$userId,$zone)));
    }
}
