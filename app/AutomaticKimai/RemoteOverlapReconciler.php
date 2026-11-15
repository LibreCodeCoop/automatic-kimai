<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use RuntimeException;

/**
 * Deterministic read-only remote coverage. Never infers that a missing
 * timesheet is work, and never changes the remote timesheet.
 */
final class RemoteOverlapReconciler
{
    public static function analyze(array $candidate,array $remote): array
    {
        $start=TimeMath::parse((string)$candidate['start']);
        $end=TimeMath::parse((string)$candidate['end']);
        if (TimeMath::ts($end)<=TimeMath::ts($start)) {
            throw new RuntimeException('Invalid candidate interval');
        }

        $coverage=[];$intersections=[];$unsafe=false;$snapshot=[];
        foreach ($remote as $row) {
            if (!is_array($row)||!isset($row['begin'])) {
                throw new RuntimeException('Invalid remote timesheet');
            }
            $a=TimeMath::parse((string)$row['begin']);
            if (!isset($row['end'])||$row['end']===null||$row['end']==='') {
                if (TimeMath::ts($a)<TimeMath::ts($end))$unsafe=true;
                continue;
            }
            $b=TimeMath::parse((string)$row['end']);
            if (TimeMath::ts($b)<=TimeMath::ts($a)) {
                throw new RuntimeException('Invalid remote timesheet bounds');
            }
            if (TimeMath::ts($a)>=TimeMath::ts($end)||
                TimeMath::ts($start)>=TimeMath::ts($b))continue;
            if (!is_int($row['id']??null)||$row['id']<1) {
                throw new RuntimeException('Remote timesheet lacks verifiable ID');
            }
            $coverage[]=[$a,$b];
            $intersections[]=$row['id'];
            $snapshot[]=['id'=>$row['id'],'begin'=>TimeMath::iso($a),'end'=>TimeMath::iso($b)];
        }

        sort($intersections,SORT_NUMERIC);
        if (count($intersections)!==count(array_unique($intersections))) {
            throw new RuntimeException('Duplicated remote timesheet identity');
        }
        usort($snapshot,static fn($a,$b)=>$a['id']<=>$b['id']);
        $missing=TimeMath::subtract([[$start,$end]],$coverage);
        $uncoveredSeconds=(int)round(array_sum(array_map(
            static fn($span)=>TimeMath::seconds($span[0],$span[1]),$missing)));
        $result=[
            'kind'=>$unsafe?'conflict':($coverage===[]?'new':($missing===[]?'covered':'partial')),
            'remote_ids'=>array_values(array_unique($intersections)),
            'remote_id'=>count($intersections)===1?$intersections[0]:null,
            'coverage_digest'=>CanonicalJson::digest($snapshot,false),
            'uncovered_seconds'=>$uncoveredSeconds,
            'uncovered_spans'=>array_map(static fn($span)=>[
                'start'=>TimeMath::iso($span[0]),
                'end'=>TimeMath::iso($span[1]),
                'seconds'=>(int)round(TimeMath::seconds($span[0],$span[1])),
            ],$missing),
            'reviewable_spans'=>[],
            'small_gap_seconds'=>0,
        ];
        if ($unsafe)return $result;
        foreach ($missing as [$a,$b]) {
            $roundedStart=TimeMath::minuteCeil($a);
            $roundedEnd=TimeMath::minuteFloor($b);
            $whole=(int)TimeMath::seconds($roundedStart,$roundedEnd);
            if ($whole<300 || $roundedStart->format('Y-m-d')!==$candidate['date'] ||
                $roundedEnd->format('Y-m-d')!==$candidate['date']) {
                $result['small_gap_seconds']+=(int)round(TimeMath::seconds($a,$b));
                continue;
            }
            // Approval is restricted to at most six hours per child proposal.
            $next=$roundedStart;
            while (TimeMath::seconds($next,$roundedEnd)>=300) {
                $until=TimeMath::seconds($next,$roundedEnd)>21600
                    ?$next->modify('+6 hours'):$roundedEnd;
                if (TimeMath::seconds($next,$until)<300)break;
                $result['reviewable_spans'][]=[
                    'start'=>TimeMath::iso($next),'end'=>TimeMath::iso($until),
                    'seconds'=>(int)TimeMath::seconds($next,$until),
                ];
                $next=$until;
            }
            $result['small_gap_seconds']+=(int)round(TimeMath::seconds($a,$b))
                -$whole+(int)TimeMath::seconds($next,$roundedEnd);
        }
        return $result;
    }

    /** Never manufacture confirmed duration or evidence for a clipped span. */
    public static function child(array $parent,array $span,string $digest): array
    {
        $seconds=$span['seconds'];
        return array_replace($parent,[
            'id'=>'gap-'.substr(hash('sha256',$parent['id'].'|'.$span['start'].'|'.$span['end']),0,24),
            'start'=>$span['start'],'end'=>$span['end'],
            'interval_elapsed_seconds_not_worked'=>$seconds,
            'observed_seconds_by_class'=>[],
            'no_aw_data_seconds'=>$seconds,
            'remote_intersections'=>[],
            'source_parent_id'=>$parent['id'],
            'source_parent_digest'=>ReviewQueue::fingerprint($parent),
            'remote_coverage_digest'=>$digest,
            'uncertainty'=>'Uncovered remote fragment; not proof of work or confirmed duration',
            'eligible_for_publication'=>false,'human_work_seconds_confirmed'=>null,
            'requires_user_approval'=>true,
        ]);
    }
}
