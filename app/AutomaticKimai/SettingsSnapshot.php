<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use InvalidArgumentException;

/** Explicit portable allowlists, never entire storage directories. */
final class SettingsSnapshot
{
    private const SHARED_CREDENTIALS=[
        'KIMAI_BASE_URL','KIMAI_API_TOKEN','GITHUB_TOKEN',
        'TELEGRAM_API_ID','TELEGRAM_API_HASH',
    ];
    private const SOURCES=[
        'activitywatch','github','nextcloud_calendar','nextcloud_talk',
        'nextcloud_activity','nextcloud_mail','telegram_setup',
        'chatgpt_browser','claude_browser',
    ];
    private const LOCAL_PROFILE=[
        'work_messenger_dialog_ids','work_messenger_group_titles',
        'work_messenger_labels','work_messenger_classification_labels',
        'work_messenger_recommendations_reviewed',
        'cache_dir','state_dir',
    ];

    public static function fromStore(LocalStore $store,bool $includeSecrets): array
    {
        $profile=$store->profile()?->toArray();
        if ($profile!==null) {
            foreach (self::LOCAL_PROFILE as $key)unset($profile[$key]);
            $profile['sync_mode']='review_only';
            $profile['allow_auto_nonbillable_preview']=false;
        }
        $sources=array_intersect_key($store->sources(),array_flip(self::SOURCES));
        $sources['telegram_setup']=$sources['telegram_setup']??false;
        $credentials=$includeSecrets?array_intersect_key(
            $store->credentials(),array_flip(self::SHARED_CREDENTIALS)
        ):[];
        return [
            'schema'=>1,'include_credentials'=>$includeSecrets,
            'profile'=>$profile,'sources'=>$sources,'credentials'=>$credentials,
        ];
    }

    /** Check remote shape BEFORE any local state changes. */
    public static function validated(array $raw,bool $includeSecrets): array
    {
        if (count($raw)!==5 || array_diff(array_keys($raw),['schema','include_credentials','profile','sources','credentials'])!==[] ||
            $raw['schema']!==1 || $raw['include_credentials']!==$includeSecrets ||
            !is_array($raw['sources']) || !is_array($raw['credentials']) ||
            ($raw['profile']!==null && !is_array($raw['profile']))) {
            throw new InvalidArgumentException('O backup não corresponde às opções de compartilhamento autorizadas.');
        }
        if (array_diff(array_keys($raw['sources']),self::SOURCES)!==[] ||
            array_diff(array_keys($raw['credentials']),self::SHARED_CREDENTIALS)!==[]) {
            throw new InvalidArgumentException('Backup contém configurações não suportadas.');
        }
        foreach ($raw['sources'] as $enabled) {
            if (!is_bool($enabled))throw new InvalidArgumentException('Fonte de dados inválida.');
        }
        // The collector is a mandatory invariant. Reject the entire backup
        // before touching credentials/profile to avoid partial restoration.
        if (($raw['sources']['activitywatch']??true)!==true) {
            throw new InvalidArgumentException('Backup contém fontes incompatíveis.');
        }
        foreach ($raw['credentials'] as $value) {
            if (!is_string($value) || $value==='' || strlen($value)>1200) {
                throw new InvalidArgumentException('Credencial importada inválida.');
            }
        }
        if (!$includeSecrets && $raw['credentials']!==[]) {
            throw new InvalidArgumentException('Credenciais não autorizadas no backup.');
        }
        $profile=$raw['profile'];
        if ($profile!==null) {
            foreach (self::LOCAL_PROFILE as $key) {
                if (array_key_exists($key,$profile)) {
                    throw new InvalidArgumentException('Backup contém dados exclusivos de outro dispositivo.');
                }
            }
            $profile['sync_mode']='review_only';
            $profile['allow_auto_nonbillable_preview']=false;
            // Restore per-device Telegram group selection only after a new authorization.
            // Validate through Profile but retain only portable fields from the
            // encrypted document. Adding Profile defaults would change the
            // canonical remote digest on every read and create false conflicts.
            new Profile(array_replace($profile,[
                'work_messenger_dialog_ids'=>[],
                'work_messenger_group_titles'=>[],
                'work_messenger_labels'=>[],
                'work_messenger_classification_labels'=>[],
                'work_messenger_recommendations_reviewed'=>false,
            ]));
        }
        return [
            'schema'=>1,'include_credentials'=>$includeSecrets,
            'profile'=>$profile,'sources'=>$raw['sources'],
            'credentials'=>$raw['credentials'],
        ];
    }

    public static function digest(array $data): string
    {
        return CanonicalJson::digest($data,false);
    }

    public static function hasLocalConfiguration(LocalStore $store): bool
    {
        if ($store->profile()!==null)return true;
        $credentials=$store->credentials();
        foreach (self::SHARED_CREDENTIALS as $key) {
            if (!empty($credentials[$key]))return true;
        }
        $sources=$store->sources();
        foreach (self::SOURCES as $key) {
            if (($sources[$key]??false)===true && !in_array($key,[
                'activitywatch','chatgpt_browser','claude_browser',
            ],true))return true;
        }
        return false;
    }

    /** Token changes on another device must not silently replace an active login. */
    public static function wouldReplaceActiveCredentials(LocalStore $store,array $snapshot): bool
    {
        $local=$store->credentials();
        $telegramActive=$store->sources()['telegram']??false;
        foreach ($snapshot['credentials'] as $key=>$remoteValue) {
            if (empty($local[$key]) || $local[$key]===$remoteValue)continue;
            if (in_array($key,['GITHUB_TOKEN','KIMAI_API_TOKEN','KIMAI_BASE_URL'],true)) {
                return true;
            }
            if ($telegramActive && in_array($key,['TELEGRAM_API_ID','TELEGRAM_API_HASH'],true)) {
                return true;
            }
        }
        return false;
    }

    public static function restore(LocalStore $store,array $snapshot): void
    {
        $profile=$snapshot['profile'];
        $credentials=$snapshot['credentials'];
        $sources=$snapshot['sources'];
        // Do not alter active Telegram session or its per-device consent.
        $oldSources=$store->sources();
        unset($sources['telegram']);
        if ($oldSources['telegram']??false) {
            $sources['telegram_setup']=true;
            // This device already owns a distinct MTProto session. Importing
            // a different app API identity could invalidate its IPC state.
            unset($credentials['TELEGRAM_API_ID'],$credentials['TELEGRAM_API_HASH']);
        }
        if ($profile!==null) {
            $existing=$store->profile()?->toArray()??[];
            foreach (self::LOCAL_PROFILE as $key) {
                if (array_key_exists($key,$existing))$profile[$key]=$existing[$key];
            }
            $profile=new Profile($profile);
        }
        $updates=$sources;
        if ($credentials!==[])$store->saveCredentials(array_replace($credentials,[
            'KIMAI_WRITE_ENABLED'=>'false','KIMAI_AUTO_PREVIEW_OPT_IN'=>'false',
            'KIMAI_MANUAL_PREVIEW_ENABLED'=>'false',
        ]));
        if ($profile!==null)$store->saveProfile($profile);
        $store->setSources($updates);
    }
}
