<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\LegacyCutoverAudit;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\PublicationGate;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Operator-only read-only preflight: the systemd installer must never
 * arm --apply merely because old units happen to be stopped at this instant.
 */
final class AutomaticKimaiVerifyWriter extends Command
{
    protected $signature='automatic-kimai:verify-writer
        {--legacy-cache= : Frozen, private original Python state directory}';

    protected $description='Check identity, explicit consent, cutover latch and frozen-state parity without writes';

    public function handle(LocalStore $store): int
    {
        try {
            $source=$this->option('legacy-cache');
            if (!is_string($source)||!str_starts_with($source,'/')) {
                throw new RuntimeException('A frozen legacy-cache path is required');
            }
            $profile=$store->profile();
            if ($profile===null)throw new RuntimeException('PHP profile missing');
            $creds=$store->credentials();
            $api=new KimaiClient((string)($creds['KIMAI_BASE_URL']??''),
                (string)($creds['KIMAI_API_TOKEN']??''));
            $me=$api->me();
            if (!is_int($me['id']??null)||$me['id']<1||
                ($me['timezone']??null)!==$profile->get('timezone')) {
                throw new RuntimeException('Kimai identity or timezone mismatch');
            }
            PublicationGate::assertReady($store,$profile,$creds,$me['id']);
            $audit=(new LegacyCutoverAudit())->inspect($source,$store->root());
            if (($audit['state_parity_verified']??null)!==true ||
                ($audit['blocker_counts']['legacy_unknown_outbox']??0)>0) {
                throw new RuntimeException('History parity or remote outcome unresolved');
            }
            $this->line(json_encode([
                'mode'=>'read_only','writer_preflight_verified'=>true,
                'identity_bound'=>true,'state_parity_verified'=>true,
                'kimai_writes'=>0,
            ],JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('WRITER_PREFLIGHT_BLOCKED '.get_class($e));
            return self::FAILURE;
        }
    }
}
