<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\TelegramCollector;
use Illuminate\Console\Command;
use Throwable;

/** Independent MTProto cycle; never delays worktime reconciliation. */
final class AutomaticKimaiTelegramScan extends Command
{
    protected $signature='automatic-kimai:telegram-scan';
    protected $description='Collect consented Telegram metadata independently of Kimai';

    public function handle(LocalStore $store): int
    {
        try {
            if (!$store->sources()['telegram']) {
                $this->line('TELEGRAM_SCAN_SKIPPED source_not_activated');
                return self::SUCCESS;
            }
            if ($store->profile()===null) {
                throw new \RuntimeException('Worker profile missing');
            }
            if (!extension_loaded('gmp')) {
                $this->error('TELEGRAM_SCAN_GMP_REQUIRED fast native runtime unavailable');
                return self::FAILURE;
            }
            $result=(new TelegramCollector($store))->scan();
            $this->line(json_encode([
                'status'=>$result['status'],
                'groups_scanned_in_cycle'=>$result['groups_scanned_in_cycle'],
                'remaining_in_round'=>$result['remaining_in_round'],
                'partial_round'=>$result['partial_round'],
                'remote_writes'=>0,
                'read_receipts_sent'=>false,
                'messages_persisted'=>false,
            ],JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch(Throwable $e) {
            // Logs must not leak peer IDs, user names or message bodies.
            $this->error('TELEGRAM_SCAN_UNAVAILABLE '.get_class($e));
            return self::FAILURE;
        }
    }
}
