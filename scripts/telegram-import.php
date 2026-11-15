<?php
declare(strict_types=1);

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\TelegramSessionMigration;

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (PHP_SAPI!=='cli'||($_SERVER['argc']??0)!==4||($_SERVER['argv'][1]??'')!=='--import-existing') {
    fwrite(STDERR,"Usage: php scripts/telegram-import.php --import-existing TELETHON_SESSION LEGACY_ENV\n");
    exit(2);
}

try {
    $old=$_SERVER['argv'][2];
    $env=$_SERVER['argv'][3];
    if (!is_file($env)||is_link($env))throw new RuntimeException('Legacy environment file unavailable');
    $raw=file($env,FILE_IGNORE_NEW_LINES);
    $config=[];
    foreach ($raw as $row) {
        if (preg_match('/^\s*(TELEGRAM_API_ID|TELEGRAM_API_HASH)=(.*)$/D',(string)$row,$match)) {
            $config[$match[1]]=trim(trim($match[2]),"\"'");
        }
    }
    if (!ctype_digit($config['TELEGRAM_API_ID']??'')||
        !preg_match('/^[a-f0-9]{32}$/iD',$config['TELEGRAM_API_HASH']??'')) {
        throw new RuntimeException('Private Telegram API credentials not configured');
    }
    $store=new LocalStore();
    $migration=new TelegramSessionMigration($store->root());
    $result=$migration->import($old,(int)$config['TELEGRAM_API_ID'],$config['TELEGRAM_API_HASH']);
    if (!$result['source_unchanged'])throw new RuntimeException('Legacy session changed during conversion');
    $store->saveCredentials([
        'TELEGRAM_API_ID'=>$config['TELEGRAM_API_ID'],
        'TELEGRAM_API_HASH'=>$config['TELEGRAM_API_HASH'],
    ]);
    echo "TELEGRAM_MTProto_AUTHORIZED=true, SOURCE_PRESERVED=true, PRIVATE_PHP_SESSION=true\n";
} catch (Throwable $e) {
    // Deliberately no credential or session detail in journal logs.
    fwrite(STDERR,"TELEGRAM_IMPORT_FAILED ".get_class($e)."\n");
    exit(1);
}
