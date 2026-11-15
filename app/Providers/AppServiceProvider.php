<?php

namespace App\Providers;

use App\AutomaticKimai\NativeBuildGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // NativePHP's packager copies local application storage even when
        // .gitignore hides it from Git. Fail BEFORE it starts building.
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            if ($event->command === 'native:build') {
                NativeBuildGuard::assertSafe(base_path());
            }
        });
    }
}
