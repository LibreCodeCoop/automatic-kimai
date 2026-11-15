<?php
declare(strict_types=1);

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\StateMigrator;

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
umask(0077);
if (PHP_SAPI!=='cli'||($_SERVER['argc']??0)!==3||
    ($_SERVER['argv'][1]??'')!=='--read-only-snapshot') {
    fwrite(STDERR,"Usage: php scripts/snapshot-legacy-state.php --read-only-snapshot OLD_CACHE_DIR\n");
    exit(2);
}
try {
    $source=realpath($_SERVER['argv'][2]);
    if ($source===false||!is_dir($source)||is_link($_SERVER['argv'][2])) {
        throw new RuntimeException('Legacy SQLite directory unavailable');
    }
    $store=new LocalStore();
    $destination=$store->root().'/migration-snapshots/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (!mkdir($destination,0700,true))throw new RuntimeException('Cannot create private snapshot folder');
    $tables=[
        'session_review_queue.sqlite3'=>['sessions'],
        'kimai_publish_outbox.sqlite3'=>['publications'],
        'cooperator_agent_state/ledger.sqlite3'=>['context','sessions','intents','human_declarations'],
        'telegram_audio_metadata.sqlite3'=>['samples'],
    ];
    $manifest=[];
    foreach ($tables as $file=>$expected) {
        $snapshotFile=$destination.'/'.str_replace('/','-',$file);
        $res=StateMigrator::snapshot($source.'/'.$file,$snapshotFile,$expected);
        $manifest[$file]=['tables'=>$res['tables'],'bytes'=>$res['bytes'],
                           'verified'=>$res['snapshot_verified']];
    }
    $store->write('migration_snapshot_status.json',[
        'created_utc'=>gmdate('c'),'schema'=>$manifest,
        'source_unchanged'=>true,
        'remote_writes'=>0,'status'=>'snapshot_only_no_cutover'
    ]);
    echo "SQLITE_SNAPSHOT_VERIFIED=true DATABASES=".count($manifest)."\n";
    foreach ($manifest as $name=>$info) {
        echo "LEGACY_DATABASE=".basename($name)." ROWS=".
            array_sum($info['tables'])."\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR,"SQLITE_SNAPSHOT_FAILED ".get_class($e)."\n");
    exit(1);
}
