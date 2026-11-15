<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Explicit approved-only publisher. No silent retries and no automatic
 * publication from ActivityWatch, the calendar, audio or autonomous agents.
 */
final class ApprovedSessionPublisher
{
    public function __construct(
        private readonly KimaiClient $api,
        private readonly Profile $profile,
        private readonly string $queueFile,
        private readonly string $ledgerFile,
    ) {}

    /**
     * $apply must only be true behind the CLI's explicit cutover gate.
     * A dry run performs GET validation but does not reserve an intent.
     */
    public function run(bool $apply=false): array
    {
        $lock=$this->lock();
        try {
            $user=$this->identity();
            $queue=new ReviewQueue($this->queueFile);
            $ledger=new Ledger($this->ledgerFile,$user,$this->profile->get('profile_id'));
            try {
                $records=$queue->approvedRows();
                if ($records===[])return ['mode'=>$apply?'explicit':'dry_run',
                    'approved'=>0,'remote_writes'=>0,'confirmed_writes'=>0,'results'=>[]];
                $pending=$ledger->pending();
                if ($apply && $pending!==[]) {
                    throw new RuntimeException('Uncertain outbox exists; GET reconciliation required');
                }
                $catalog=$this->api->catalog();
                $results=[];$attempts=0;$confirmed=0;
                foreach ($records as $row) {
                    try {
                        $entry=ApprovedSessionValidator::unpack($row,$user,$this->profile);
                        ApprovedSessionValidator::validateCatalog($catalog,$entry['approval']);
                        $id=$entry['window']['id'];
                        $key=$entry['key'];
                        $timesheets=$this->api->timesheets($user,[$row['day']],$this->profile->zone);
                        $already=$ledger->intent($key);
                        if ($already!==null) {
                            if ($already['state']==='confirmed') {
                                $matches=ApprovedSessionValidator::exactMatches(
                                    $timesheets,$entry['payload'],$user,$this->profile->zone);
                                if (count($matches)!==1||
                                    $matches[0]['id']!==(int)$already['remote_id']) {
                                    throw new RuntimeException('Historical posted result no longer matches');
                                }
                                if ($apply)$queue->markReconciled($row['id'],$row['source_digest'],
                                    $matches[0]['id']);
                                $results[]=['status'=>'already_confirmed','id'=>$row['id']];
                                continue;
                            }
                            throw new RuntimeException('Outbox operation already exists; never blind-retry');
                        }
                        if ($ledger->known($id)!==null) {
                            throw new RuntimeException('Candidate was previously published');
                        }
                        $a=TimeMath::parse($entry['approval']['start'],$this->profile->zone);
                        $b=TimeMath::parse($entry['approval']['end'],$this->profile->zone);
                        if (Reconciler::overlaps($timesheets,$a,$b)!==[]) {
                            throw new RuntimeException('Remote timesheet overlaps approval');
                        }
                        if (!$apply) {
                            $results[]=['status'=>'validated_dry_run','id'=>$row['id']];
                            continue;
                        }
                        // Reserve before POST; the key permanently prevents retry
                        // following any uncertain response or process crash.
                        $ledger->reserve($key,$entry['window'],'post',$entry['payload']);
                        $remoteId=null;
                        try {
                            // Count attempted mutations even on timeout: the
                            // remote server may have committed the POST.
                            $attempts++;
                            $remoteId=$this->api->createTimesheet($entry['payload']);
                        } catch (Throwable) {
                            $ledger->markUnknown($key);
                            $queue->markUnknown($row['id'],$row['source_digest'],null);
                            $results[]=['status'=>'unknown_no_retry','id'=>$row['id']];
                            break;
                        }
                        try {
                            $fresh=$this->api->timesheets($user,[$row['day']],$this->profile->zone);
                            $found=null;
                            foreach ($fresh as $remote)if (($remote['id']??null)===$remoteId)$found=$remote;
                            $verified=ApprovedSessionValidator::matches(
                                $found,$entry['payload'],$user,$this->profile->zone);
                        } catch (Throwable) {
                            $verified=false;
                        }
                        if (!$verified) {
                            $ledger->markUnknown($key,$remoteId);
                            $queue->markUnknown($row['id'],$row['source_digest'],$remoteId);
                            $results[]=['status'=>'unknown_no_retry','id'=>$row['id']];
                            break;
                        }
                        $ledger->confirm($key,$entry['window'],$found);
                        $confirmed++;
                        $queue->markPosted($row['id'],$row['source_digest'],$remoteId);
                        $results[]=['status'=>'posted_and_verified','id'=>$row['id']];
                    } catch (Throwable $e) {
                        $results[]=['status'=>'blocked','id'=>$row['id'],
                            'reason_type'=>get_class($e)];
                        // Do not skip over a failed approved entry when applying.
                        if ($apply)break;
                    }
                }
                return ['mode'=>$apply?'explicit':'dry_run','approved'=>count($records),
                    'remote_writes'=>$attempts,'confirmed_writes'=>$confirmed,
                    'results'=>$results];
            } finally {
                $queue->close();$ledger->close();
            }
        } finally {$this->unlock($lock);}
    }

    /**
     * GET-only reconciliation of unknown writes. Does not retry POST/PATCH,
     * including when a remote operation cannot be uniquely located.
     */
    public function reconcileUnknown(): array
    {
        $lock=$this->lock();
        try {
            $user=$this->identity();
            $queue=new ReviewQueue($this->queueFile);
            $ledger=new Ledger($this->ledgerFile,$user,$this->profile->get('profile_id'));
            try {
                $uncertain=[];
                foreach (array_merge($queue->uncertainRows(),$queue->approvedRows()) as $row) {
                    $uncertain[$row['id']]=$row;
                }
                $results=[];
                foreach ($ledger->pending() as $intent) {
                    if (!str_starts_with($intent['session_id'],'approved:'))continue;
                    $id=substr($intent['session_id'],strlen('approved:'));
                    if (!isset($uncertain[$id])) {
                        $results[]=['status'=>'candidate_not_uncertain','id'=>$id];
                        continue;
                    }
                    try {
                        $row=$uncertain[$id];
                        $entry=ApprovedSessionValidator::unpack($row,$user,$this->profile);
                        // SQLite canonical JSON sorts keys; PHP strict array
                        // equality would otherwise reject identical payloads
                        // whose insertion order differs after decoding.
                        if (!is_array($intent['payload']['kimai']??null)||
                            CanonicalJson::encode($intent['payload']['kimai'])!==
                                CanonicalJson::encode($entry['payload'])||
                            ($intent['payload']['evidence_digest']??null)!==$row['source_digest']||
                            ($intent['key']??null)!==$entry['key']) {
                            throw new RuntimeException('Outbox approval payload drift');
                        }
                        if ($row['status']==='approved') {
                            // Process crashes may occur after the durable
                            // outbox reservation and before queue state update.
                            // Mark uncertain BEFORE GET; never silently retry.
                            $queue->markUnknown($id,$row['source_digest'],
                                $intent['remote_id']===null?null:(int)$intent['remote_id']);
                        }
                        $remote=$this->api->timesheets($user,[$row['day']],$this->profile->zone);
                        $matches=ApprovedSessionValidator::exactMatches(
                            $remote,$entry['payload'],$user,$this->profile->zone);
                        if (count($matches)!==1||
                            ($intent['remote_id']!==null &&
                            (int)$intent['remote_id']!==$matches[0]['id'])) {
                            $results[]=['status'=>'unresolved_no_retry','id'=>$id];
                            continue;
                        }
                        $ledger->recoverConfirmed($intent,$matches[0]);
                        $queue->markReconciled($id,$row['source_digest'],$matches[0]['id']);
                        $results[]=['status'=>'reconciled_by_get','id'=>$id];
                    } catch (Throwable $e) {
                        $results[]=['status'=>'blocked','id'=>$id,
                            'reason_type'=>get_class($e)];
                    }
                }
                // A crash may also happen after ledger.confirm() commits
                // but before the queue is marked posted. Repair that split
                // state by GET and the previously confirmed exact remote ID.
                foreach ($queue->uncertainRows() as $row) {
                    $id=$row['id'];
                    $seen=false;
                    foreach ($results as $item) {
                        if (($item['id']??null)===$id) {$seen=true;break;}
                    }
                    if ($seen)continue;
                    try {
                        $entry=ApprovedSessionValidator::unpack($row,$user,$this->profile);
                        $intent=$ledger->intent($entry['key']);
                        if (($intent['state']??null)!=='confirmed'||
                            !is_numeric($intent['remote_id']??null)) {
                            $results[]=['status'=>'unresolved_no_retry','id'=>$id];
                            continue;
                        }
                        $remote=$this->api->timesheets($user,[$row['day']],$this->profile->zone);
                        $matches=ApprovedSessionValidator::exactMatches(
                            $remote,$entry['payload'],$user,$this->profile->zone);
                        if (count($matches)!==1 ||
                            $matches[0]['id']!==(int)$intent['remote_id']) {
                            $results[]=['status'=>'unresolved_no_retry','id'=>$id];
                            continue;
                        }
                        $queue->markReconciled($id,$row['source_digest'],$matches[0]['id']);
                        $results[]=['status'=>'reconciled_by_get','id'=>$id];
                    } catch (Throwable $e) {
                        $results[]=['status'=>'blocked','id'=>$id,
                            'reason_type'=>get_class($e)];
                    }
                }
                return ['mode'=>'get_only','remote_writes'=>0,'results'=>$results];
            } finally {$queue->close();$ledger->close();}
        } finally {$this->unlock($lock);}
    }

    private function identity(): int
    {
        $me=$this->api->me();
        if (!is_int($me['id']??null)||$me['id']<1||
            ($me['timezone']??null)!==$this->profile->get('timezone')) {
            throw new RuntimeException('Kimai user identity/timezone mismatch');
        }
        return $me['id'];
    }

    private function lock(): mixed
    {
        $path=dirname($this->ledgerFile).'/daily.lock';
        if (is_link($path))throw new RuntimeException('Symlinked publication lock forbidden');
        $fd=fopen($path,'c');
        if ($fd===false)throw new RuntimeException('Cannot lock publisher');
        chmod($path,0600);
        if (!flock($fd,LOCK_EX|LOCK_NB)) {
            fclose($fd);
            throw new RuntimeException('Another sync/review is running');
        }
        return $fd;
    }

    private function unlock(mixed $fd): void
    {
        flock($fd,LOCK_UN);
        fclose($fd);
    }
}
