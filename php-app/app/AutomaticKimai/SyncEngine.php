<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * One deterministic local cycle: collect sanitized evidence -> GET user-bound
 * timesheets -> calculate decisions -> show warnings. Writes are opt-in only.
 * This process cannot share an outbox with the Python pilot.
 */
final class SyncEngine
{
    public function __construct(
        private readonly LocalStore $store,
        private readonly ?ActivityWatchClient $watcher=null,
        private readonly ?HttpTransport $providers=null,
        private readonly ?KimaiClient $kimai=null,
    ) {}

    private function day(string $date,Profile $profile,DateTimeImmutable $clock): array
    {
        $lower=TimeMath::dayStart($date,$profile->zone);
        $upper=$lower->modify('+1 day');
        $high=TimeMath::ts($clock)<TimeMath::ts($upper)?$clock:$upper;
        return [$lower,$high];
    }

    private function readEvidence(string $day): array
    {
        $data=$this->store->read('aw_sanitized_'.$day.'.json');
        if ($data===[])return [[], 'missing_activitywatch_file'];
        if (($data['local_date']??null)!==$day || !is_array($data['events']??null)) {
            throw new RuntimeException('ActivityWatch cache is inconsistent');
        }
        foreach ($data['events'] as $row) {
            if (!is_array($row) || !in_array($row['idle_status_known']??null,[true,false],true)) {
                return [[], 'legacy_activitywatch_without_afk_coverage'];
            }
        }
        return [$data['events'],null];
    }

    private function collect(Profile $profile,DateTimeImmutable $now,int $days): array
    {
        $watch=$this->watcher??new ActivityWatchClient();
        $captured=[];
        for($i=$days-1;$i>=0;$i--) {
            $day=$now->setTimezone($profile->zone)->modify('-'.$i.' days')->format('Y-m-d');
            [$start,$end]=$this->day($day,$profile,$now->setTimezone($profile->zone));
            if (TimeMath::ts($end)<=TimeMath::ts($start))continue;
            try {
                [$events,$stats]=$watch->collect(
                    $start->setTimezone(new DateTimeZone('UTC')),
                    $end->setTimezone(new DateTimeZone('UTC')),
                    $profile->get('work_domains'),
                );
                if (($stats['window_events']??0)<1 || ($stats['afk_events']??0)<1) {
                    $captured[$day]=['status'=>'insufficient_source','events'=>count($events)];
                    continue;
                }
                $this->store->write('aw_sanitized_'.$day.'.json',[
                    'local_date'=>$day,'fetched_at'=>gmdate('c'),
                    'source'=>'local ActivityWatch sanitized window, AFK and browser context',
                    'privacy'=>'No raw window titles or private URLs',
                    'stats'=>$stats,'events'=>$events,
                ]);
                $captured[$day]=[
                    'status'=>'stored','events'=>count($events),
                    'afk_events'=>$stats['afk_events']
                ];
            } catch(Throwable) {
                // Never erase good prior snapshots because the collector failed.
                $captured[$day]=['status'=>'unavailable_retained','events'=>null];
            }
        }
        return $captured;
    }

    private function external(Profile $profile,array $creds,DateTimeImmutable $now): array
    {
        $enabled=$this->store->sources();
        $http=$this->providers??new HttpTransport();
        $sources=['activitywatch'=>'local'];
        $nextcloudReady=!empty($creds['NEXTCLOUD_URL']) &&
            !empty($creds['NEXTCLOUD_USERNAME']) &&
            !empty($creds['NEXTCLOUD_APP_PASSWORD']);
        if ($enabled['nextcloud_calendar']) {
            if (!$nextcloudReady) {
                // Optional provider setup must not suppress otherwise valid
                // work evidence or prevent the next scheduled PHP shadow cycle.
                $sources['nextcloud_calendar']=['status'=>'needs_connection','events'=>0];
            } else {
                try {
                    $events=(new NextcloudSource($http))->calendar($profile,$creds,$now);
                    $this->store->write('calendar_work_context.json',$events);
                    $sources['nextcloud_calendar']=['status'=>'ok','events'=>count($events['events'])];
                } catch(Throwable) {
                    // Do not reuse stale calendar snapshots for work inference.
                    $sources['nextcloud_calendar']=['status'=>'unavailable','events'=>0];
                }
            }
        }
        if ($enabled['nextcloud_talk']) {
            if (!$nextcloudReady) {
                $sources['nextcloud_talk']=['status'=>'needs_connection','own_actions'=>0];
            } else {
                try {
                    $result=(new NextcloudSource($http))->talk($profile,$creds);
                    $this->store->write('nextcloud_talk_context.json',$result);
                    $sources['nextcloud_talk']=[
                        'status'=>($result['rooms_unavailable']>0||$result['rooms_truncated'])
                            ?'partial':'ok',
                        'own_actions'=>count($result['own_actions']??[]),
                        'rooms_total'=>$result['rooms_total'],
                        'rooms_analyzed'=>$result['rooms_analyzed'],
                        'rooms_unavailable'=>$result['rooms_unavailable'],
                        'rooms_truncated'=>$result['rooms_truncated'],
                    ];
                } catch(Throwable) {
                    $sources['nextcloud_talk']=['status'=>'unavailable','own_actions'=>0];
                }
            }
        }
        if ($enabled['nextcloud_activity']) {
            if (!$nextcloudReady) {
                $sources['nextcloud_activity']=['status'=>'needs_connection','own_actions'=>0];
            } else {
                try {
                    $result=(new NextcloudActivitySource($http))->collect($profile,$creds,$now);
                    $this->store->write('nextcloud_activity_context.json',$result);
                    $sources['nextcloud_activity']=[
                        'status'=>'ok','own_actions'=>count($result['own_actions']),
                        'truncated'=>$result['truncated'],
                    ];
                } catch(Throwable) {
                    $sources['nextcloud_activity']=['status'=>'unavailable','own_actions'=>0];
                }
            }
        }
        if ($enabled['nextcloud_mail']) {
            if (!$nextcloudReady) {
                $sources['nextcloud_mail']=['status'=>'needs_connection','sent_actions'=>0];
            } else {
                try {
                    $result=(new NextcloudMailSource($http))->collect($profile,$creds,$now);
                    $this->store->write('nextcloud_mail_context.json',$result);
                    $sources['nextcloud_mail']=[
                        'status'=>$result['accounts_scanned']===0 ? 'not_configured' :
                            ($result['partial'] ? 'partial' : 'ok'),
                        'sent_actions'=>count($result['sent_actions']),
                        'partial'=>$result['partial'],
                    ];
                } catch(Throwable) {
                    $sources['nextcloud_mail']=['status'=>'unavailable','sent_actions'=>0];
                }
            }
        }
        if ($enabled['github']) {
            try {
                $result=(new GitHubSource($http))->collect((string)($creds['GITHUB_TOKEN']??''));
                if (($result['status']??null)==='ok') {
                    $this->store->write('github_context.json',$result);
                }
                $sources['github']=['status'=>$result['status'],'events'=>count($result['events'])];
            } catch(Throwable){$sources['github']='unavailable_context_only';}
        }
        if ($enabled['telegram']) {
            // MTProto calls can block or be rate-limited for minutes. Their
            // dedicated collector runs outside the main Kimai reconciliation
            // cycle; only a fresh, account-scoped completed batch is read here.
            // Corrupt/stale optional metadata cannot break the main worklog
            // cycle. Never reuse it as if collection had succeeded.
            try {
                $history=$this->store->read('telegram_scoped_history.json');
                $cursor=$this->store->read('telegram_scan_cursor.json');
                $accountBinding=$this->store->read('telegram_selected_account.json');
            } catch (Throwable) {
                $history=[];$cursor=[];$accountBinding=[];
            }
            $ids=$profile->get('work_messenger_dialog_ids');
            $fingerprint=CanonicalJson::digest([
                'profile'=>$profile->get('profile_id'),'ids'=>$ids,
            ],false);
            $fresh=false;
            try {
                $stamp=TimeMath::parse((string)($cursor['last_success_utc']??''));
                $elapsed=$now->getTimestamp()-$stamp->getTimestamp();
                $accountId=$cursor['account_id']??null;
                $fresh=$elapsed>=-60 && $elapsed<=7200 &&
                    $ids!==[] && is_int($accountId) &&
                    TelegramGroupSelection::selectionMatchesAccount(
                        $accountBinding,$accountId,$profile) &&
                    ($cursor['selection_hash']??null)===$fingerprint &&
                    ($history['no_message_bodies_stored']??null)===true &&
                    is_array($history['scoped_client_chats']??null);
            } catch(Throwable) {}
            $sources['telegram']=[
                'status'=>$fresh?'ok':'needs_collection',
                'matched_dialogs'=>$fresh?(int)($history['matched_dialog_count']??0):0,
                'read_position_advances'=>0,
                'partial_round'=>$fresh && ($history['incomplete_round']??false)===true,
                'eligible_for_auto_publication'=>false,
            ];
        }
        foreach (['chatgpt_browser','claude_browser'] as $name) {
            if ($enabled[$name])$sources[$name]='ActivityWatch browser metadata only';
        }
        return $sources;
    }

    private function actionAnchors(Profile $profile,array $providerStatuses): array
    {
        $actions=[];
        $talk=(($providerStatuses['nextcloud_talk']['status']??'')==='ok')?
            $this->store->read('nextcloud_talk_context.json'):[];
        foreach (array_slice($talk['own_actions']??[],0,20_000) as $item) {
            try {$actions[]=TimeMath::parse((string)$item['utc'],$profile->zone);}
            catch(Throwable){}
        }
        // Weak temporal context only: an Activity event or sent-mail timestamp
        // can prompt review of an observed gap, never attest worked duration.
        foreach ([
            'nextcloud_activity'=>['nextcloud_activity_context.json','own_actions'],
            'nextcloud_mail'=>['nextcloud_mail_context.json','sent_actions'],
        ] as $source=>$config) {
            if (($providerStatuses[$source]['status']??'')!=='ok')continue;
            $context=$this->store->read($config[0]);
            foreach (array_slice($context[$config[1]]??[],0,500) as $item) {
                try {$actions[]=TimeMath::parse((string)$item['utc'],$profile->zone);}
                catch(Throwable){}
            }
        }
        $telegram=(($providerStatuses['telegram']['status']??'')==='ok')?
            $this->store->read('telegram_scoped_history.json'):[];
        foreach ($telegram['scoped_client_chats']??[] as $chat) {
            foreach (array_slice($chat['observations']??[],0,20_000) as $item) {
                if (($item['outgoing']??false)!==true)continue;
                try {$actions[]=TimeMath::parse((string)$item['timestamp_utc'],$profile->zone);}
                catch(Throwable){}
            }
        }
        $github=(($providerStatuses['github']['status']??'')==='ok')?
            $this->store->read('github_context.json'):[];
        foreach (array_slice($github['events']??[],0,1000) as $event) {
            if (!in_array($event['kind']??'',[
                'PullRequestReviewEvent','PullRequestReviewCommentEvent',
                'IssuesEvent','IssueCommentEvent','PullRequestEvent',
            ],true))continue;
            try {$actions[]=TimeMath::parse((string)$event['timestamp_utc'],$profile->zone);}
            catch(Throwable){}
        }
        return $actions;
    }

    private static function minimumEvidence(array $window,Profile $profile): bool
    {
        $a=TimeMath::parse($window['start']);$b=TimeMath::parse($window['end']);
        $duration=TimeMath::seconds($a,$b);
        return $window['work_seconds'] >= $profile->get('auto_min_work_minutes')*60 &&
            $duration >= $profile->get('min_session_minutes')*60 &&
            $duration <= $profile->get('auto_max_session_hours')*3600 &&
            $window['uncovered_seconds'] <= $duration*$profile->get('auto_max_unknown_fraction');
    }

    public function run(bool $apply=false,bool $captureOnly=false,?DateTimeImmutable $now=null): array
    {
        $profile=$this->store->profile();
        if ($profile===null)throw new RuntimeException('Set up the Kimai account before synchronizing');
        $clock=($now??new DateTimeImmutable('now',$profile->zone))->setTimezone($profile->zone);
        $sourceOpts=$this->store->sources();
        if (!$sourceOpts['activitywatch'])throw new RuntimeException('ActivityWatch required');
        $previous=$this->store->status();
        $lookback=$profile->get('lookback_days');
        if (!empty($previous['last_successful_utc'])) {
            try {
                $prior=TimeMath::parse($previous['last_successful_utc'],$profile->zone);
                $elapsed=(int)$clock->diff($prior)->days;
                $lookback=min(7,max($lookback,$elapsed+1));
            } catch(Throwable){}
        }
        $status=[
            'profile_id'=>$profile->get('profile_id'),'at_local'=>TimeMath::iso($clock),
            'sync_interval_minutes'=>30,'lookback_days'=>$lookback,
            'attempted_publication'=>$apply,'remote_writes'=>0,
            'remote_write_attempts'=>0,'remote_write_outcome_uncertain'=>false,
            'result'=>'started','last_successful_utc'=>$previous['last_successful_utc']??null,
        ];
        try {
            $status['collected']=$this->collect($profile,$clock,$lookback);
            if ($captureOnly) {
                $status['result']='capture_only';
                return $status;
            }
            $creds=$this->store->credentials();
            // Even explicit --apply cannot write without the supervised
            // production handoff, bound to this account and an imported ledger.
            if ($apply && !$profile->allowsAutomaticPublication()) {
                throw new RuntimeException('Automatic preview is not enabled for this profile');
            }
            if (empty($creds['KIMAI_BASE_URL'])||empty($creds['KIMAI_API_TOKEN'])) {
                throw new RuntimeException('Kimai URL or individual API token missing');
            }
            $sources=$this->external($profile,$creds,$clock);
            $api=$this->kimai??new KimaiClient($creds['KIMAI_BASE_URL'],$creds['KIMAI_API_TOKEN']);
            $me=$api->me();
            $userId=$me['id']??null;
            if (!is_int($userId)||$userId<1||($me['timezone']??'')!==$profile->get('timezone')) {
                throw new RuntimeException('Kimai profile or timezone does not match');
            }
            if ($apply) {
                PublicationGate::assertReady($this->store,$profile,$creds,$userId);
                if ($sourceOpts['nextcloud_calendar'] &&
                    ($sources['nextcloud_calendar']['status']??null)!=='ok') {
                    throw new RuntimeException('Calendar unavailable; automatic write blocked');
                }
            }
            $api->validateCatalog($profile);
            $days=[];
            for($i=$lookback-1;$i>=0;$i--) {
                $days[]=$clock->modify('-'.$i.' days')->format('Y-m-d');
            }
            $remote=$api->timesheets($userId,$days,$profile->zone);
            $lockPath=$this->store->root().'/daily.lock';
            if (is_link($lockPath))throw new RuntimeException('Symlinked lock forbidden');
            $lock=fopen($lockPath,'c');
            if (!$lock)throw new RuntimeException('Cannot acquire private reconciliation lock');
            chmod($lockPath,0600);
            if (!flock($lock,LOCK_EX|LOCK_NB)) {
                fclose($lock);
                throw new RuntimeException('Another reconciliation is running');
            }
            try {
                $ledger=new Ledger($this->store->ledgerFile(),$userId,$profile->get('profile_id'));
                try {
                    $engine=new Reconciler();
                    $pending=$engine->recover($api,$profile,$userId,$ledger,$days);
                    if ($apply && $pending['unresolved']!==[]) {
                        throw new RuntimeException('Unresolved old outbox; no automatic writes');
                    }
                    $calendar=(($sources['nextcloud_calendar']['status']??'')==='ok')?
                        ($this->store->read('calendar_work_context.json')['events']??[]):[];
                    $anchors=$this->actionAnchors($profile,$sources);
                    $restoreMissing=$apply &&
                        ($this->store->read('cutover.json')['restore_missing_nonbillable_previews']??false)===true;
                    $coverage=new Coverage();$segmenter=new Segmenter();
                    $candidateBuilder=new ReviewCandidateBuilder();
                    $reviewCandidates=[];
                    // Only explicit agent-run markers are considered. A local
                    // browser session or an idle gap cannot infer AI execution.
                    $agentFile=$this->store->root().'/agent_runs.sqlite3';
                    $agentConnected=is_file($agentFile);
                    $agentIntervals=[];
                    if ($agentConnected) {
                        $agent=new AgentRuns($agentFile,$profile->get('profile_id'),false);
                        try {
                            $agentIntervals=$agent->intervals(
                                TimeMath::dayStart($days[0],$profile->zone),$clock);
                        } finally {$agent->close();}
                    }
                    $report=[
                        'created_at'=>TimeMath::iso($clock),
                        'profile_id'=>$profile->get('profile_id'),'user_id'=>$userId,
                        'timezone'=>$profile->get('timezone'),
                        'mode'=>$profile->get('sync_mode'),'writes_enabled_for_this_run'=>$apply,
                        'pending_reconciliation'=>$pending,'sources'=>$sources,'days'=>[],
                        'billing_approved'=>false,'payroll_approved'=>false,
                        'agent_telemetry_status'=>$agentConnected?
                            'explicit_metadata_only':'no_integration_connected',
                        'agent_execution_intervals'=>array_map(static fn($run)=>[
                            'source'=>$run['source'],'start'=>$run['start'],
                            'end'=>$run['end'],'state'=>$run['state'],
                            'execution_seconds'=>$run['execution_seconds'],
                            'counts_as_human_work'=>false,
                        ],$agentIntervals),
                    ];
                    foreach ($days as $day) {
                        [$events,$warning]=$this->readEvidence($day);
                        $sessions=[];
                        if (!$warning) {
                            $reviewCandidates=array_merge($reviewCandidates,
                                $candidateBuilder->build($day,$events,$calendar,$remote,$profile,$clock));
                            foreach ($segmenter->segment($day,$events,$calendar,$profile,$clock) as $window) {
                                $decision=$engine->plan($window,$profile,$remote,
                                    $ledger->known($window['id']),$userId,$restoreMissing,
                                    $ledger->restorationAttempted($window['id']));
                                $overlap=AgentRuns::overlappingSeconds($window,$agentIntervals);
                                if (in_array($decision['action'],['post','patch','restore'],true)) {
                                    if ($overlap>=60) {
                                        $decision['action']='review';
                                        $decision['reason']='agent_execution_overlap_needs_human_attribution';
                                    } elseif (!self::minimumEvidence($window,$profile)) {
                                        $decision['action']='review';
                                        $decision['reason']='insufficient_independent_desktop_evidence';
                                    }
                                }
                                $result=['status'=>'dry_run'];
                                if ($apply && in_array($decision['action'],['post','patch','restore'],true)) {
                                    // Reconciler re-fetches from Kimai and reserves a
                                    // durable intent before every POST/PATCH.
                                    $status['remote_write_attempts']++;
                                    try {
                                        $result=$engine->execute($decision,$window,$api,
                                            $profile,$userId,$ledger,$restoreMissing);
                                    } catch (Throwable $error) {
                                        // A lost response can mean POST committed
                                        // even if the local cycle reports an error.
                                        $status['remote_write_outcome_uncertain']=true;
                                        throw $error;
                                    }
                                    if ($result['status']==='reconciled')$status['remote_writes']++;
                                }
                                $sessions[]=array_replace($window,$decision,[
                                    'result'=>$result,
                                    'agent_overlap_seconds'=>$overlap,
                                ]);
                            }
                        }
                        $report['days'][]=[
                            'day'=>$day,'warning'=>$warning,'sessions'=>$sessions,
                            'coverage_gaps'=>$warning?[]:$coverage->observedGaps($day,$events,$remote,$profile,$clock,$calendar,$anchors),
                            'review_intervals'=>$warning?[]:$coverage->reviewable($day,$events,$remote,$profile,$clock,$calendar,$anchors),
                            'calendar_gap_alerts'=>$coverage->calendarGaps($day,$calendar,$remote,$profile,$clock),
                            'missing_workday_alerts'=>$coverage->missingWorkday($day,$remote,$profile,$clock),
                        ];
                    }
                    if ($apply && $status['remote_writes']>0) {
                        // Recalculate missing-work alerts with a fresh GET
                        // after remote changes, mirroring the Python runtime.
                        $updated=$api->timesheets($userId,$days,$profile->zone);
                        foreach ($report['days'] as &$currentDay) {
                            [$events,$warning]=$this->readEvidence($currentDay['day']);
                            if (!$warning) {
                                $currentDay['coverage_gaps']=$coverage->observedGaps(
                                    $currentDay['day'],$events,$updated,$profile,$clock,$calendar,$anchors);
                                $currentDay['review_intervals']=$coverage->reviewable(
                                    $currentDay['day'],$events,$updated,$profile,$clock,$calendar,$anchors);
                            }
                            $currentDay['calendar_gap_alerts']=$coverage->calendarGaps(
                                $currentDay['day'],$calendar,$updated,$profile,$clock);
                            $currentDay['missing_workday_alerts']=$coverage->missingWorkday(
                                $currentDay['day'],$updated,$profile,$clock);
                        }
                        unset($currentDay);
                    }
                    $queue=new ReviewQueue($this->store->reviewQueueFile());
                    try {
                        $report['review_queue_states']=$queue->synchronize($reviewCandidates,$remote);
                        $report['review_queue_count']=count($queue->rows());
                    } finally {
                        $queue->close();
                    }
                    foreach (['coverage_gaps'=>'coverage_gap_count',
                        'review_intervals'=>'manual_review_interval_count',
                        'calendar_gap_alerts'=>'calendar_gap_count',
                        'missing_workday_alerts'=>'missing_workday_count'] as $field=>$counter) {
                        $report[$counter]=array_sum(array_map(static fn($day)=>count($day[$field]),$report['days']));
                    }
                    $report['overnight_review_count']=0;
                    $lastEnd=[];
                    foreach ($report['days'] as &$day) {
                        foreach ($day['sessions'] as &$session) {
                            if ($session['action']==='review' &&
                                substr($session['begin'],11,5)==='00:00' &&
                                isset($lastEnd[$session['begin']])) {
                                $session['possible_overnight_continuation']=true;
                                $report['overnight_review_count']++;
                            }
                            $start=TimeMath::parse($session['begin'],$profile->zone);
                            $end=TimeMath::parse($session['end'],$profile->zone);
                            if ($start->format('Y-m-d')!==$end->format('Y-m-d') &&
                                $end->format('H:i')==='00:00') {
                                $lastEnd[$session['end']]=true;
                            }
                        }
                        unset($session);
                    }
                    unset($day);
                    $this->store->write('review.json',$report);
                    $status['result']='success';
                    foreach (['coverage_gap_count','manual_review_interval_count',
                        'calendar_gap_count','missing_workday_count','overnight_review_count'] as $key) {
                        $status[$key]=$report[$key];
                    }
                    $status['review_count']=array_sum(array_map(static fn($day)=>
                        count(array_filter($day['sessions'],static fn($s)=>$s['action']==='review')),$report['days']));
                    $status['last_successful_utc']=$clock->setTimezone(new DateTimeZone('UTC'))->format('c');
                    return $status;
                } finally {$ledger->close();}
            } finally {flock($lock,LOCK_UN);fclose($lock);}
        } catch (Throwable $e) {
            $status['result']='failed_retry_next_cycle';
            $status['error_type']=$e::class;
            // The status is safe for UI; do not leak personal URLs/tokens.
            throw $e;
        } finally {
            $this->store->write('status.json',$status);
        }
    }
}
