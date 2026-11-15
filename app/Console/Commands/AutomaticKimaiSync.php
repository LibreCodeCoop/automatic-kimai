<?php

namespace App\Console\Commands;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\SyncEngine;
use Illuminate\Console\Command;
use Throwable;

/** One bounded PHP cycle; --apply requires an independently verified writer cutover. */
final class AutomaticKimaiSync extends Command
{
    protected $signature='automatic-kimai:sync
        {--capture-only : Only refresh sanitized local ActivityWatch evidence}
        {--status : Return the last private status without a network request}
        {--apply : Publish eligible worked-time records after verified cutover}';

    protected $description='Run a bounded Automatic Kimai cycle; default is read-only';

    public function handle(LocalStore $store): int
    {
        if ($this->option('status')) {
            $status=$store->status();
            unset($status['collected']);
            $this->line(json_encode($status,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
         try {
            $apply=(bool)$this->option('apply');
            if ($apply && (bool)$this->option('capture-only')) {
                throw new \RuntimeException('--apply cannot be combined with --capture-only');
            }
            $result=(new SyncEngine($store))->run($apply,(bool)$this->option('capture-only'));
            $this->info('PHP_CYCLE '.$result['result'].' remote_writes='.
                ($result['remote_writes']??0).' reviews='.($result['review_count']??0));
            return self::SUCCESS;
        } catch (Throwable $e) {
            // Exception type only. Do not echo provider URLs, responses or tokens.
            $this->error('PHP_CYCLE_FAILED '.get_class($e).'; local evidence retained');
            return self::FAILURE;
        }
    }
}
