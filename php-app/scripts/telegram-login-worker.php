<?php
declare(strict_types=1);

// This script is launched only by the localhost Telegram UI controller.
// No credentials or login code are accepted via CLI arguments.
if (PHP_SAPI!=='cli'||($_SERVER['argc']??0)!==1)exit(2);
umask(0077);
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $worker=new App\AutomaticKimai\TelegramLoginWorker(new App\AutomaticKimai\LocalStore());
    exit($worker->run());
} catch (Throwable) {
    exit(1);
}
