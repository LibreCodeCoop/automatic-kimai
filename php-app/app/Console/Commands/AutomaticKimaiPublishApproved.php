<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\ApprovedSessionPublisher;
use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\LocalStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Approved-only publication with a fail-closed cutover latch. The scheduler
 * never passes --post-approved; HTTP controllers expose no publishing route.
 */
final class AutomaticKimaiPublishApproved extends Command
{
    protected $signature='automatic-kimai:publish-approved
        {--post-approved : Explicitly publish frozen and approved records after cutover}
        {--reconcile-unknown : GET-only recovery of uncertain POST outcomes}';

    protected $description='Validate or publish explicitly approved work, never auto-infer human time';

    public function handle(LocalStore $store): int
    {
        if ($this->option('post-approved')&&$this->option('reconcile-unknown')) {
            $this->error('Choose only one operation');
            return self::FAILURE;
        }
        try {
            $profile=$store->profile();
            $creds=$store->credentials();
            if ($profile===null||empty($creds['KIMAI_BASE_URL'])||
                empty($creds['KIMAI_API_TOKEN'])) {
                throw new \RuntimeException('Kimai account is not configured');
            }
            $apply=(bool)$this->option('post-approved');
            if ($apply) {
                $cutover=$store->read('cutover.json');
                if (($creds['KIMAI_WRITE_ENABLED']??'')!=='true' ||
                    ($cutover['supervised_cutover_completed']??null)!==true||
                    ($cutover['legacy_publisher_stopped']??null)!==true||
                    ($cutover['history_caught_up']??null)!==true||
                    !is_int($cutover['verified_user_id']??null)||
                    !is_string($cutover['verified_at_utc']??null)||
                    !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\+\d\d:\d\d|Z)$/D',
                        $cutover['verified_at_utc'])) {
                    throw new \RuntimeException('Supervised cutover and write opt-in not verified');
                }
                // A stale local authorization is not a permanent permission.
                $at=new \DateTimeImmutable($cutover['verified_at_utc']);
                if (abs(time()-$at->getTimestamp())>86400) {
                    throw new \RuntimeException('Cutover verification expired');
                }
            }

            $api=new KimaiClient($creds['KIMAI_BASE_URL'],$creds['KIMAI_API_TOKEN']);
            if ($apply) {
                $me=$api->me();
                if (($me['id']??null)!==$cutover['verified_user_id']||
                    ($me['timezone']??null)!==$profile->get('timezone')) {
                    throw new \RuntimeException('Cutover authorization belongs to another worker');
                }
            }
            $publisher=new ApprovedSessionPublisher($api,$profile,
                $store->reviewQueueFile(),$store->ledgerFile());
            $result=$this->option('reconcile-unknown')?
                $publisher->reconcileUnknown():$publisher->run($apply);
            $this->line(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $bad=array_filter($result['results']??[],static fn($row)=>
                in_array(($row['status']??''),['blocked','unknown_no_retry',
                    'unresolved_no_retry','candidate_not_uncertain'],true));
            return $bad===[]?self::SUCCESS:self::FAILURE;
        } catch (Throwable $e) {
            // Exceptions can embed provider paths, response bodies and secrets.
            $this->error('APPROVED_PUBLISHER_FAILED '.get_class($e));
            return self::FAILURE;
        }
    }
}
