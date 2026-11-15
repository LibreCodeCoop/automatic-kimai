<?php

namespace App\Console\Commands;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\SyncEngine;
use Illuminate\Console\Command;
use Throwable;

/** One bounded, read-only PHP cycle for OS-native scheduling. */
final class AutomaticKimaiSync extends Command
{
    protected $signature='automatic-kimai:sync
        {--capture-only : Only refresh sanitized local ActivityWatch evidence}
        {--status : Return the last private status without a network request}
        {--apply : Reserved for a future validated, explicitly opted-in cutover}';

    protected $description='Run one local Automatic Kimai cycle (safe read-only default)';

    public function handle(LocalStore $store): int
    {
        if ($this->option('status')) {
            $status=$store->status();
            unset($status['collected']);
            $this->line(json_encode($status,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        if ($this->option('apply')) {
            $this->error('Unattended PHP writes are blocked until production parity and outbox migration are verified.');
            return self::FAILURE;
        }
        try {
            $result=(new SyncEngine($store))->run(false,(bool)$this->option('capture-only'));
            $this->info('PHP_CYCLE '.$result['result'].' remote_writes=0 reviews='.($result['review_count']??0));
            return self::SUCCESS;
        } catch (Throwable $e) {
            // Exception type only. Do not echo provider URLs, responses or tokens.
            $this->error('PHP_CYCLE_FAILED '.get_class($e).'; local evidence retained');
            return self::FAILURE;
        }
    }
}
