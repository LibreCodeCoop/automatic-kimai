<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\NativeBuildGuard;
use Illuminate\Console\Command;
use RuntimeException;

final class AutomaticKimaiPackagePreflight extends Command
{
    protected $signature = 'automatic-kimai:package-preflight';
    protected $description = 'Refuse distribution of a build containing local credentials or user data';

    public function handle(): int
    {
        try {
            NativeBuildGuard::assertSafe(base_path());
        } catch (RuntimeException $error) {
            // Never print the contents of any secret.
            $this->error($error->getMessage());
            return self::FAILURE;
        }
        $this->info('PACKAGE_PREFLIGHT_OK');
        return self::SUCCESS;
    }
}
