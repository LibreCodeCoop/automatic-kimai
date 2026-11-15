<?php
declare(strict_types=1);

/**
 * Explicit, read-only migration shadow. Imports one existing local profile and
 * API token after GET verification, with ALL Kimai write modes forced off.
 *
 * This script must never be bundled as an installer hook or run unattended.
 * Source paths are CLI arguments, not hard-coded organizational identities.
 */
use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$argv=$_SERVER['argv'];
if (count($argv)!==4 || $argv[1]!=='--read-only-shadow') {
    fwrite(STDERR,"Usage: php scripts/import-existing-profile.php --read-only-shadow PROFILE_JSON LEGACY_ENV\n");
    exit(2);
}
[,,$profilePath,$envPath]=$argv;
if (is_link($profilePath)||is_link($envPath)||
    !is_file($profilePath)||!is_file($envPath)) {
    fwrite(STDERR,"Input profiles must be existing regular files\n");
    exit(2);
}
try {
    $source=json_decode(file_get_contents($profilePath),true,512,JSON_THROW_ON_ERROR);
    $keys=['profile_id','timezone','customer_id','project_id','activity_id',
       'lookback_days','long_idle_minutes','max_bridge_minutes','min_session_minutes',
       'min_context_minutes','max_session_hours','settle_minutes','capture_freshness_minutes',
       'work_topic_hints','work_domains','work_messenger_labels','allow_snapshots',
       'alert_on_unrecorded_weekdays','auto_min_work_minutes',
       'auto_max_unknown_fraction','auto_max_session_hours'];
    $allowed=array_intersect_key($source,array_flip($keys));
    $allowed['sync_mode']='review_only';
    $allowed['allow_auto_nonbillable_preview']=false;
    $profile=new Profile($allowed);
    $credentials=[];
    foreach (explode("\n",file_get_contents($envPath)) as $line) {
        if (!preg_match('/^\s*(KIMAI_BASE_URL|KIMAI_API_TOKEN)=(.*)$/D',trim($line),$match))continue;
        $credentials[$match[1]]=trim(trim($match[2]),"\"'");
    }
    if (empty($credentials['KIMAI_BASE_URL'])||empty($credentials['KIMAI_API_TOKEN'])) {
        throw new RuntimeException('Expected individual Kimai credentials not found');
    }
    $api=new KimaiClient($credentials['KIMAI_BASE_URL'],$credentials['KIMAI_API_TOKEN']);
    $user=$api->me();
    if (!is_int($user['id']??null)||($user['timezone']??null)!==$profile->get('timezone')) {
        throw new RuntimeException('User identity or timezone differs');
    }
    $api->validateCatalog($profile);
    $store=new LocalStore();
    if ($store->profile()!==null) {
        throw new RuntimeException('Shadow profile already exists; refusing overwrite');
    }
    $store->saveCredentials([
        'KIMAI_BASE_URL'=>$credentials['KIMAI_BASE_URL'],
        'KIMAI_API_TOKEN'=>$credentials['KIMAI_API_TOKEN'],
        'KIMAI_WRITE_ENABLED'=>'false',
        'KIMAI_AUTO_PREVIEW_OPT_IN'=>'false',
        'KIMAI_MANUAL_PREVIEW_ENABLED'=>'false',
    ]);
    $store->saveProfile($profile);
    $store->setSources([
        'activitywatch'=>true,'github'=>false,'nextcloud_calendar'=>false,
        'nextcloud_talk'=>false,'telegram'=>false,
        'chatgpt_browser'=>true,'claude_browser'=>true,
    ]);
    echo "PHP_SHADOW_READY profile_verified=true remote_writes_allowed=false\n";
} catch (Throwable $e) {
    // Do not expose credentials, URL or private file paths in logs.
    fwrite(STDERR,"PHP_SHADOW_REFUSED ".get_class($e)."\n");
    exit(1);
}
