<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Incremental Telegram metadata collector. The allowlist has no arbitrary
 * group-count cap; network work is limited independently, per scan.
 * Never calls Telegram readHistory/readMessageContents or posts to Kimai.
 */
final class TelegramCollector
{
    private const MAX_HISTORY_ENTRIES_PER_CYCLE=4_800;
    public const GROUPS_PER_CYCLE=24;
    private const LOOKBACK_DAYS=14;

    public function __construct(private readonly LocalStore $store) {}

    public static function historyLimitPerGroup(int $activeCount): int
    {
        if ($activeCount<1) {
            throw new InvalidArgumentException('Telegram scan needs at least one group');
        }
        return min(600,max(1,intdiv(self::MAX_HISTORY_ENTRIES_PER_CYCLE,$activeCount)));
    }

    public function scan(?object $client=null,?DateTimeImmutable $now=null): array
    {
        $profile=$this->store->profile();
        if (!$profile)throw new RuntimeException('Configure individual Kimai profile first');
        if (!$this->store->sources()['telegram']) {
            throw new RuntimeException('Telegram must be explicitly opted in');
        }
        $labels=$profile->get('work_messenger_labels');
        $ids=$profile->get('work_messenger_dialog_ids');
        if ($labels===[] && $ids===[]) {
            throw new InvalidArgumentException('Approved Telegram work groups missing');
        }
        $creds=$this->store->credentials();
        $owned=$client===null;
        if ($owned) {
            if (!isset($creds['TELEGRAM_API_ID'],$creds['TELEGRAM_API_HASH'])) {
                throw new RuntimeException('Telegram API credentials have not been configured');
            }
            $client=new MadelineTelegramAdapter(
                $this->store->root().'/telegram-user.session',
                (int)$creds['TELEGRAM_API_ID'],
                $creds['TELEGRAM_API_HASH'],
            );
        }
        try {
            $now??=new DateTimeImmutable();
            $prior=$this->store->read('telegram_scoped_history.json');
            $nextState=null;
            if ($ids!==[]) {
                // A Telegram session can switch accounts between cycles.
                // The selected IDs belong to the account that approved them.
                // Reject an unbound/changed account before fetching history.
                $account=$client->selfId();
                if (!is_int($account)||!TelegramGroupSelection::selectionMatchesAccount(
                    $this->store->read('telegram_selected_account.json'),
                    $account,$profile)) {
                    throw new RuntimeException(
                        'Telegram group authorization belongs to another account; reselect groups');
                }
                // Stable selection order and fingerprint. If the selection
                // changes, restart the cycle without resurrecting removed IDs.
                $fingerprint=CanonicalJson::digest([
                    'profile'=>$profile->get('profile_id'),'ids'=>$ids,
                ],false);
                $state=$this->store->read('telegram_scan_cursor.json');
                $offset=($state['selection_hash']??null)===$fingerprint &&
                    is_int($state['next_index']??null) &&
                    $state['next_index']>=0 &&
                    $state['next_index']<count($ids)?
                        $state['next_index']:0;
                $batch=array_slice($ids,$offset,self::GROUPS_PER_CYCLE);
                $limit=self::historyLimitPerGroup(count($batch));
                $part=(new TelegramSource())->scanApprovedBatch($client,$batch,
                    $profile->get('work_messenger_group_titles'),
                    self::LOOKBACK_DAYS,$limit,$now);
                $snapshot=$this->mergeSnapshot($prior,$part,$ids,$now);
                $next=($offset+count($batch))%count($ids);
                $nextState=['selection_hash'=>$fingerprint,
                    'account_id'=>$account,
                    'next_index'=>$next,'selected_groups'=>count($ids),
                    'last_success_utc'=>$part['collected_at']];
                $processed=count($batch);
                $remaining=count($ids)-($offset+count($batch));
                // Incomplete history is a review warning, never a reason to
                // claim that an unseen day contains zero work.
                $partial=$remaining>0;
            } else {
                // Legacy name-only configuration retains previous behavior
                // until the person explicitly selects exact group IDs.
                $limit=self::historyLimitPerGroup(count($labels));
                $snapshot=(new TelegramSource())->scan(
                    $client,$labels,self::LOOKBACK_DAYS,$limit,$now);
                $processed=$snapshot['matched_dialog_count'];
                $remaining=0;$partial=false;
            }
            // A person may revoke Telegram consent or remove a group while
            // a slow MTProto batch is in flight. Recheck the exact allowlist
            // BEFORE writing any of the returned metadata to disk.
            $currentProfile=$this->store->profile();
            $currentSources=$this->store->sources();
            if (!$currentSources['telegram']||$currentProfile===null||
                $currentProfile->get('work_messenger_dialog_ids')!==$ids||
                $currentProfile->get('work_messenger_labels')!==$labels) {
                throw new RuntimeException('Telegram selection changed during scan; discard this batch');
            }
            $newChanges=TelegramReadProgress::compute($prior,$snapshot);
            $progress=TelegramReadProgress::retain(
                $this->store->read('telegram_read_progress.json'),$newChanges,$now);
            // The cursor advances only after both local metadata files are
            // committed. A crash before then repeats the same read-only page.
            $this->store->write('telegram_scoped_history.json',$snapshot);
            $this->store->write('telegram_read_progress.json',$progress);
            if ($nextState!==null) {
                $this->store->write('telegram_scan_cursor.json',$nextState);
            }
            return [
                'status'=>'ok','matched_dialogs'=>$snapshot['matched_dialog_count'],
                'scoped_messages'=>array_sum(array_map(
                    static fn($row)=>count($row['observations']??[]),
                    $snapshot['scoped_client_chats'])),
                'read_position_advances'=>count($newChanges),
                'selected_groups'=>count($ids),
                'groups_scanned_in_cycle'=>$processed,
                'remaining_in_round'=>$remaining,
                'partial_round'=>$partial,
                'remote_writes'=>0,
                'read_receipts_sent'=>false,
                'body_persisted'=>false,
            ];
        } finally {
            if ($owned&&method_exists($client,'close'))$client->close();
        }
    }

    private function mergeSnapshot(array $before,array $current,array $allowedIds,
                                   DateTimeImmutable $now): array
    {
        $allow=array_fill_keys($allowedIds,true);
        $fresh=[];
        foreach ($current['scoped_client_chats'] as $entry) {
            $fresh[$entry['dialog_id']]=$entry;
        }
        $cutoff=$now->modify('-'.self::LOOKBACK_DAYS.' days');
        $old=[];
        foreach ($before['scoped_client_chats']??[] as $entry) {
            if (!is_array($entry)||!is_int($entry['dialog_id']??null))continue;
            $id=$entry['dialog_id'];
            if (!isset($allow[$id])||isset($fresh[$id]))continue;
            try {
                $scanned=TimeMath::parse((string)(
                    $entry['last_scanned_at']??$before['collected_at']??''));
                if (TimeMath::ts($scanned)<TimeMath::ts($cutoff))continue;
            } catch (Throwable) {continue;}
            $old[$id]=$entry;
        }
        $all=$fresh+$old;
        $ordered=[];
        foreach ($allowedIds as $id) {
            if (isset($all[$id]))$ordered[]=$all[$id];
        }
        return array_replace($current,[
            'matched_dialog_count'=>count($ordered),
            'scoped_client_chats'=>$ordered,
            'selected_groups_count'=>count($allowedIds),
            'incomplete_round'=>count($ordered)<count($allowedIds),
            'read_receipts_sent'=>false,
        ]);
    }
}
