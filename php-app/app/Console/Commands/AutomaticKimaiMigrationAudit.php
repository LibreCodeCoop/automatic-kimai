<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\LegacyCutoverAudit;
use App\AutomaticKimai\LocalStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only, privacy-preserving inventory of legacy Python and PHP SQLite
 * state before any publisher handoff. Does not import or mutate records.
 */
final class AutomaticKimaiMigrationAudit extends Command
{
    protected $signature='automatic-kimai:migration-audit
        {--legacy-cache= : Absolute path to the original Python .kimai_cache directory}
        {--strict : Return failure if any state parity blockers remain}';

    protected $description='Audit legacy Python review/outbox/ledger state without writes';

    public function handle(LocalStore $store): int
    {
        $path=$this->option('legacy-cache');
        if (!is_string($path)||$path==='') {
            $this->error('Specify --legacy-cache with a private read-only mounted directory.');
            return self::FAILURE;
        }
        try {
            $result=(new LegacyCutoverAudit())->inspect($path,$store->root());
            $this->line(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            return $this->option('strict') && !$result['state_parity_verified']
                ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            // Never disclose filesystem paths, payloads, tokens or row content.
            $this->error('MIGRATION_AUDIT_FAILED '.get_class($e));
            return self::FAILURE;
        }
    }
}
