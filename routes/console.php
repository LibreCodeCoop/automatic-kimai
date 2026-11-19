<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Runs only while the application scheduler is active; the command exits
// without connecting anywhere unless the user enabled encrypted sync.
// SettingsSyncService already takes an exclusive device-local file lock.
// Avoid the database cache lock: a clean NativePHP install may have no DB.
Schedule::useCache('file');
Schedule::command('automatic-kimai:settings-sync')->everyFiveMinutes();
