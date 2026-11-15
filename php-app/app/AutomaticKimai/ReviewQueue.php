<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Private review queue compatible with the Python queue SQLite schema.
 * A proposal never proves attendance or automatically approves billing.
 */
final class ReviewQueue
{
    private PDO $db;

    public function __construct(string $path)
    {
        if (is_link($path)||is_link(dirname($path))) {
            throw new RuntimeException('Symlinked review queue forbidden');
        }
        if (!is_dir(dirname($path))&&!mkdir(dirname($path),0700,true)&&!is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create private queue directory');
        }
        if (!is_file($path)) {
            $f=fopen($path,'x');
            if (!$f)throw new RuntimeException('Unable to create queue');
            fclose($f);chmod($path,0600);
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($path)&0077)!==0) {
            throw new RuntimeException('Review queue must be owner-only');
        }
        $this->db=new PDO('sqlite:'.$path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>20,
        ]);
        $this->db->exec('PRAGMA busy_timeout=20000;PRAGMA journal_mode=DELETE;PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sessions(
            id TEXT PRIMARY KEY,day TEXT NOT NULL,start TEXT NOT NULL,end TEXT NOT NULL,
            status TEXT NOT NULL,proposed_json TEXT NOT NULL,source_digest TEXT NOT NULL,
            approval_json TEXT,remote_id INTEGER,reason TEXT,
            updated_utc TEXT NOT NULL,created_utc TEXT NOT NULL)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS sessions_by_day ON sessions(day,status)');
    }

    public static function fingerprint(array $proposal): string
    {
        $values=[];
        foreach (['date','start','end','remote_intersections','ongoing',
                  'observed_seconds_by_class','no_aw_data_seconds'] as $key) {
            $values[$key]=$proposal[$key]??null;
        }
        foreach (['source_parent_id','source_parent_digest','remote_coverage_digest'] as $key) {
            if (isset($proposal[$key]))$values[$key]=$proposal[$key];
        }
        return CanonicalJson::digest($values);
    }

    private static function observedEvidenceFingerprint(array $proposal): string
    {
        $values=[];
        foreach (['date','start','end','ongoing','end_stable',
            'observed_seconds_by_class','no_aw_data_seconds'] as $key) {
            $values[$key]=$proposal[$key]??null;
        }
        return CanonicalJson::digest($values);
    }

    private function one(string $id): ?array
    {
        $query=$this->db->prepare('SELECT * FROM sessions WHERE id=?');
        $query->execute([$id]);
        return $query->fetch()?:null;
    }

    private static function matchOverlap(array $old,array $new): bool
    {
        try {
            $a=TimeMath::parse($old['start']);$b=TimeMath::parse($old['end']);
            $c=TimeMath::parse($new['start']);$d=TimeMath::parse($new['end']);
            $left=max(TimeMath::ts($a),TimeMath::ts($c));
            $right=min(TimeMath::ts($b),TimeMath::ts($d));
            $overlap=max(0,$right-$left);
            $small=min(TimeMath::seconds($a,$b),TimeMath::seconds($c,$d));
            return $small>0&&$overlap/$small>=0.75;
        } catch (\Throwable) {return false;}
    }

    private function matchingIdentity(array $candidate,array $claimed): string
    {
        if ($this->one($candidate['id'])!==null && !isset($claimed[$candidate['id']])) {
            return $candidate['id'];
        }
        $query=$this->db->prepare("SELECT id,proposed_json,status FROM sessions WHERE day=? ORDER BY start");
        $query->execute([$candidate['date']]);
        foreach ($query as $row) {
            if (isset($claimed[$row['id']]) ||
                !in_array($row['status'],['draft','needs_reapproval','stale'],true))continue;
            try {$old=json_decode($row['proposed_json'],true,512,JSON_THROW_ON_ERROR);}
            catch (\Throwable) {continue;}
            if (self::matchOverlap($old,$candidate))return $row['id'];
        }
        return $candidate['id'];
    }

    /** @return array<string,int> */
    public function synchronize(array $proposals,array $remote): array
    {
        $expanded=[];
        // Split only precisely verified remote coverage; unknown/open timesheets
        // remain conflicts. No candidate becomes approved automatically.
        foreach ($proposals as $original) {
            if (isset($original['source_parent_id'])) {
                throw new RuntimeException('Nested derived review candidates are forbidden');
            }
            $plan=RemoteOverlapReconciler::analyze($original,$remote);
            if ($plan['kind']==='partial') {
                $original['remote_uncovered_spans']=$plan['uncovered_spans'];
                $original['remote_uncovered_seconds']=$plan['uncovered_seconds'];
                $original['remote_small_gap_seconds']=$plan['small_gap_seconds'];
                $original['remote_gap_candidates']=count($plan['reviewable_spans']);
                $original['remote_coverage_digest']=$plan['coverage_digest'];
                $original['remote_intersections']=$plan['remote_ids'];
            }
            $expanded[]=[$original,$plan];
            // Respect a previous human rejection or frozen approval: do not
            // fabricate new children when the parent is already decided.
            $before=$this->one($original['id']);
            if ($plan['kind']==='partial' &&
                ($before===null||in_array($before['status'],
                ['draft','conflict','reconciled_partial','covered','stale','needs_reapproval'],true))) {
                foreach ($plan['reviewable_spans'] as $span) {
                    $child=RemoteOverlapReconciler::child(
                        $original,$span,$plan['coverage_digest']);
                    $expanded[]=[$child,RemoteOverlapReconciler::analyze($child,$remote)];
                }
            }
        }
        usort($expanded,static fn($a,$b)=>strcmp($a[0]['date'].$a[0]['start'].$a[0]['id'],
                                                 $b[0]['date'].$b[0]['start'].$b[0]['id']));
        $claimed=[];$counts=[];
        $timestamp=(new DateTimeImmutable())->format('c');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            foreach ($expanded as [$original,$plan]) {
                $id=str_starts_with($original['id'],'gap-')
                    ?$original['id']:$this->matchingIdentity($original,$claimed);
                if (isset($claimed[$id]))throw new RuntimeException('Duplicated review candidate');
                $claimed[$id]=true;
                $candidate=array_replace($original,['id'=>$id]);
                $relation=$plan['kind'];
                $remoteId=$plan['kind']==='covered'?$plan['remote_id']:null;
                $candidate['proposed_action']=$relation;
                $candidate['existing_timesheet_id']=$remoteId;
                $digest=self::fingerprint($candidate);
                $before=$this->one($id);
                if ($before!==null) {
                    if ($before['status']==='posted') {
                        $counts['posted']=($counts['posted']??0)+1;
                        continue;
                    }
                    if ($before['status']==='uncertain') {
                        // A timed-out POST is never cleared by a subsequent
                        // overlap or refreshed segmentation. Reconcile by GET.
                        $status='uncertain';$reason='Remote outcome requires GET reconciliation';
                    } elseif ($before['status']==='rejected') {
                        // Automatic remote-coverage reconciliation must not
                        // revoke a human rejection if observed evidence itself
                        // is unchanged (only Kimai segment boundaries moved).
                        $previous=json_decode($before['proposed_json'],true,512,JSON_THROW_ON_ERROR);
                        $sameEvidence=is_array($previous) &&
                            self::observedEvidenceFingerprint($previous)===
                            self::observedEvidenceFingerprint($candidate);
                        $status=$sameEvidence?'rejected':'needs_reapproval';
                        $reason=$status==='rejected'?'Rejected by the worker':
                            'Observed evidence changed after prior rejection';
                    } elseif ($before['status']==='approved' && $before['source_digest']!==$digest) {
                        $status='needs_reapproval';$reason='Evidence or boundaries changed after approval';
                    } elseif ($relation==='covered') {
                        $status='covered';$reason='Already present in live Kimai';
                    } elseif ($relation==='conflict') {
                        $status='conflict';$reason='Live Kimai has an unfinished overlapping timesheet';
                    } elseif ($relation==='partial') {
                        $status='reconciled_partial';$reason='Remote coverage separated; residual work requires review';
                    } elseif ($before['status']==='approved') {
                        $status='approved';$reason=null;
                    } elseif ($before['status']==='needs_reapproval') {
                        $status='needs_reapproval';$reason='Updated evidence requires new approval';
                    } else {
                        $status='draft';$reason=null;
                    }
                    $sql='UPDATE sessions SET start=?,end=?,status=?,proposed_json=?,source_digest=?,reason=?,updated_utc=?
                          WHERE id=?';
                    $stmt=$this->db->prepare($sql);
                    $stmt->execute([
                        $candidate['start'],$candidate['end'],$status,
                        CanonicalJson::encode($candidate),$digest,$reason,$timestamp,$id
                    ]);
                } else {
                    $status=match($relation) {
                        'covered'=>'covered','conflict'=>'conflict',
                        'partial'=>'reconciled_partial',default=>'draft'
                    };
                    $stmt=$this->db->prepare('INSERT INTO sessions
                        (id,day,start,end,status,proposed_json,source_digest,approval_json,remote_id,reason,updated_utc,created_utc)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([
                        $id,$candidate['date'],$candidate['start'],$candidate['end'],$status,
                        CanonicalJson::encode($candidate),$digest,null,null,null,$timestamp,$timestamp
                    ]);
                }
                $counts[$status]=($counts[$status]??0)+1;
            }
            $days=array_unique(array_column($proposals,'date'));
            foreach ($days as $day) {
                $stmt=$this->db->prepare("SELECT id,status FROM sessions WHERE day=? AND
                    (status IN ('draft','conflict','reconciled_partial')
                     OR (id LIKE 'gap-%' AND status IN ('approved','needs_reapproval')))");
                $stmt->execute([$day]);
                foreach ($stmt as $row) {
                    if (isset($claimed[$row['id']]))continue;
                    // An obsolete frozen gap approval is no longer eligible
                    // for the publisher. Retain its approval JSON for audit.
                    $this->db->prepare("UPDATE sessions SET status='stale',reason=?,updated_utc=? WHERE id=?")
                        ->execute(['Not present in the current verified segmentation',$timestamp,$row['id']]);
                    $counts['stale']=($counts['stale']??0)+1;
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
        return $counts;
    }

    /**
     * Freeze an explicit worker decision. The browser must submit the exact
     * digest it reviewed: changes to evidence require another confirmation.
     * No remote operation occurs here.
     */
    public function approve(string $id,array $choice,Profile $profile,int $userId,
                            DateTimeImmutable $now): array
    {
        if ($userId<1||!preg_match('/^[A-Za-z0-9_-]{8,90}$/D',$id)||
            !is_string($choice['source_digest']??null)) {
            throw new RuntimeException('Review identity missing');
        }
        foreach (['human_duration_confirmed','project_confirmed'] as $key) {
            if (($choice[$key]??null)!==true) {
                throw new RuntimeException('Explicit human, project and billing confirmations required');
            }
        }
        foreach (['customer_id','project_id','activity_id'] as $key) {
            if (!is_int($choice[$key]??null)||$choice[$key]<1) {
                throw new RuntimeException('Explicit project and customer IDs required');
            }
        }
        $description=trim((string)($choice['description']??''));
        if (mb_strlen($description)<10||mb_strlen($description)>500||
            preg_match('/[<>\x00-\x1f\x7f]/u',$description)) {
            throw new RuntimeException('Concrete single-line activity description required');
        }
        $start=TimeMath::parse((string)($choice['start']??''),$profile->zone);
        $end=TimeMath::parse((string)($choice['end']??''),$profile->zone);
        $a=$start->setTimezone($profile->zone);
        $b=$end->setTimezone($profile->zone);
        $length=TimeMath::seconds($a,$b);
        if ($a->format('s.u')!=='00.000000'||$b->format('s.u')!=='00.000000'||
            $length<300||$length>21600||
            $a->format('Y-m-d')!==$b->format('Y-m-d')||
            TimeMath::ts($b)>TimeMath::ts($now)) {
            throw new RuntimeException('Invalid approved interval bounds');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=$this->one($id);
            if ($row===null||!in_array($row['status'],['draft','needs_reapproval'],true)||
                !hash_equals($row['source_digest'],$choice['source_digest'])) {
                throw new RuntimeException('Review candidate has changed or is not approvable');
            }
            $candidate=json_decode($row['proposed_json'],true,512,JSON_THROW_ON_ERROR);
            if (($candidate['ongoing']??true)!==false||
                ($candidate['end_stable']??false)!==true||
                ($candidate['proposed_action']??null)!=='new') {
                throw new RuntimeException('Ongoing, overlapping or conflicting candidate');
            }
            if ($row['day']!==$a->format('Y-m-d')) {
                throw new RuntimeException('Approved interval differs from candidate day');
            }
            $sourceStart=TimeMath::parse($candidate['start'],$profile->zone);
            $sourceEnd=TimeMath::parse($candidate['end'],$profile->zone);
            if (TimeMath::ts($a)<TimeMath::ts($sourceStart)-120||
                TimeMath::ts($b)>TimeMath::ts($sourceEnd)+120) {
                throw new RuntimeException('Approval extends beyond observed candidate');
            }
            $approval=[
                'candidate_id'=>$id,'source_digest'=>$row['source_digest'],
                'approved_at'=>$now->setTimezone(new \DateTimeZone('UTC'))->format('c'),
                'approved_by'=>'explicit_worker_review','user_id'=>$userId,
                'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
                'customer_id'=>$choice['customer_id'],
                'project_id'=>$choice['project_id'],
                'activity_id'=>$choice['activity_id'],
                'billable'=>false,
                'human_duration_confirmed'=>true,'project_confirmed'=>true,
                'billing_confirmed'=>false,
                'description'=>$description,
                'action'=>'new','existing_timesheet_id'=>null,
                'approved_for_payroll'=>false,
            ];
            $approval['approval_digest']=CanonicalJson::digest($approval);
            $query=$this->db->prepare("UPDATE sessions SET status='approved',
                approval_json=?,reason=NULL,updated_utc=?
                WHERE id=? AND source_digest=? AND status IN ('draft','needs_reapproval')");
            $query->execute([
                CanonicalJson::encode($approval),$now->format('c'),$id,$row['source_digest']
            ]);
            if ($query->rowCount()!==1)throw new RuntimeException('Concurrent review changed');
            $this->db->commit();
            return [
                'id'=>$id,'status'=>'approved','source_digest'=>$row['source_digest'],
                'approved_for_payroll'=>false,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    /** Explicitly discard an overlapping hypothesis without changing Kimai. */
    public function dismissConflict(string $id,string $sourceDigest,string $decision): void
    {
        if (!in_array($decision,['already_recorded','not_worked'],true)) {
            throw new RuntimeException('Explicit conflict decision required');
        }
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=$this->one($id);
            if (!$row||$row['status']!=='conflict'||
                !hash_equals($row['source_digest'],$sourceDigest)) {
                throw new RuntimeException('Conflict changed; refresh before deciding');
            }
            $reason=$decision==='already_recorded'
                ? 'Worker discarded overlap proposal; existing Kimai records kept unchanged'
                : 'Worker attested that this overlapping proposal is not worked time';
            $stmt=$this->db->prepare("UPDATE sessions SET status='rejected',approval_json=NULL,
                reason=?,updated_utc=? WHERE id=? AND status='conflict' AND source_digest=?");
            $stmt->execute([$reason,gmdate('c'),$id,$sourceDigest]);
            if ($stmt->rowCount()!==1)throw new RuntimeException('Concurrent conflict decision');
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    public function reject(string $id,string $sourceDigest): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row=$this->one($id);
            if (!$row||!in_array($row['status'],['draft','needs_reapproval'],true)||
                !hash_equals($row['source_digest'],$sourceDigest)) {
                throw new RuntimeException('Candidate changed; refresh before rejecting');
            }
            $this->db->prepare("UPDATE sessions SET status='rejected',approval_json=NULL,
                reason='Rejected by worker',updated_utc=? WHERE id=?")
                ->execute([gmdate('c'),$id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction())$this->db->rollBack();
            throw $e;
        }
    }

    /** Raw approval records are used only by the local approved-only publisher. */
    public function approvedRows(): array
    {
        return $this->db->query("SELECT id,day,start,end,status,source_digest,
            approval_json FROM sessions WHERE status='approved' ORDER BY day,start")
            ->fetchAll();
    }

    public function markUnknown(string $id,string $sourceDigest,?int $remoteId): void
    {
        $stmt=$this->db->prepare("UPDATE sessions SET status='uncertain',remote_id=?,
            reason='Remote result unknown; GET-only reconciliation required',
            updated_utc=? WHERE id=? AND source_digest=? AND status='approved'");
        $stmt->execute([$remoteId,gmdate('c'),$id,$sourceDigest]);
        if ($stmt->rowCount()!==1)throw new RuntimeException('Approval changed after remote mutation');
    }

    public function markPosted(string $id,string $sourceDigest,int $remoteId): void
    {
        if ($remoteId<1)throw new RuntimeException('Missing verified remote ID');
        $stmt=$this->db->prepare("UPDATE sessions SET status='posted',remote_id=?,
            reason=NULL,updated_utc=? WHERE id=? AND source_digest=? AND status='approved'");
        $stmt->execute([$remoteId,gmdate('c'),$id,$sourceDigest]);
        if ($stmt->rowCount()!==1)throw new RuntimeException('Approval changed after confirmation');
    }

    /**
     * Called only after an exact GET verified an already-reserved outbox
     * operation. Never turns an unverified unknown into a fresh POST.
     */
    public function markReconciled(string $id,string $sourceDigest,int $remoteId): void
    {
        if ($remoteId<1)throw new RuntimeException('Missing verified remote ID');
        $stmt=$this->db->prepare("UPDATE sessions SET status='posted',remote_id=?,
            reason=NULL,updated_utc=? WHERE id=? AND source_digest=?
            AND status IN ('approved','uncertain')");
        $stmt->execute([$remoteId,gmdate('c'),$id,$sourceDigest]);
        if ($stmt->rowCount()!==1)throw new RuntimeException('Approval changed during GET reconciliation');
    }

    public function uncertainRows(): array
    {
        return $this->db->query("SELECT id,day,start,end,status,source_digest,
            approval_json FROM sessions WHERE status='uncertain' ORDER BY day,start")
            ->fetchAll();
    }

    public function rows(bool $includeStale=false): array
    {
        $sql='SELECT id,day,status,proposed_json,reason,remote_id,approval_json,source_digest
              FROM sessions'.($includeStale?'':" WHERE status!='stale'").' ORDER BY day,start';
        $result=[];
        foreach ($this->db->query($sql) as $row) {
            try {$entry=json_decode($row['proposed_json'],true,512,JSON_THROW_ON_ERROR);}
            catch (\Throwable){continue;}
            $result[]=array_replace($entry,[
                'id'=>$row['id'],'status'=>$row['status'],
                'source_digest'=>$row['source_digest'],
                'hold_reason'=>$row['reason'],'posted_remote_id'=>$row['remote_id']
            ]);
        }
        return $result;
    }

    /** Only the currently frozen evidence may be used to request remote details. */
    public function conflict(string $id,string $digest): ?array
    {
        $row=$this->one($id);
        if (!$row||$row['status']!=='conflict'||!hash_equals($row['source_digest'],$digest)) {
            return null;
        }
        $proposal=json_decode($row['proposed_json'],true,512,JSON_THROW_ON_ERROR);
        return is_array($proposal)?$proposal:null;
    }

    /**
     * Historical conflicts need a bounded remote GET even when their day
     * falls outside the normal activity-window lookback. No status changes
     * until synchronize() has authoritative remote coverage.
     */
    public function olderConflicts(array $activeDays,int $limit=100): array
    {
        if ($limit<1||$limit>100)throw new RuntimeException('Unsafe backlog size');
        $rows=$this->db->query("SELECT id,day,proposed_json FROM sessions
            WHERE status='conflict' ORDER BY day,start LIMIT ".($limit+1))->fetchAll();
        if (count($rows)>$limit)throw new RuntimeException('Historical conflict backlog exceeds scan limit');
        $proposals=[];
        foreach ($rows as $row) {
            if (in_array($row['day'],$activeDays,true))continue;
            $item=json_decode($row['proposed_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($item)||($item['date']??null)!==$row['day'] ||
                ($item['id']??null)!==$row['id']||isset($item['source_parent_id'])) {
                throw new RuntimeException('Untrusted historical candidate');
            }
            $proposals[]=$item;
        }
        return $proposals;
    }

    public function countByStatus(): array
    {
        return $this->db->query('SELECT status,count(*) AS count FROM sessions GROUP BY status')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function close(): void
    {
        unset($this->db);
    }
}
