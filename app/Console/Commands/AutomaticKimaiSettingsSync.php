<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\SettingsSyncService;
use Illuminate\Console\Command;
use Throwable;

final class AutomaticKimaiSettingsSync extends Command
{
    protected $signature='automatic-kimai:settings-sync';
    protected $description='Synchronize opted-in encrypted Nextcloud settings, without remote writes on conflicts';

    public function handle(SettingsSyncService $sync): int
    {
        if (!($sync->status()['enabled']??false))return self::SUCCESS;
        try {
            $result=$sync->synchronize();
            if (in_array($result,['needs_resolution','conflict'],true)) {
                $this->warn('SETTINGS_SYNC_CONFLICT: review required in local settings');
            }
            return self::SUCCESS;
        } catch (Throwable) {
            // Do not log remote URLs, user names, provider responses or tokens.
            $this->warn('SETTINGS_SYNC_UNAVAILABLE: local settings remain intact');
            return self::FAILURE;
        }
    }
}
