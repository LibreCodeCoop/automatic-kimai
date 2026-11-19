<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * User-local profile and encrypted credentials. Never store secrets in
 * repository files, page HTML, review logs or timesheet descriptions.
 */
final class LocalStore
{
    private string $root;

    public function __construct(?string $root=null)
    {
        $this->root=rtrim($root??storage_path('app/private/automatic-kimai'),'/');
        $this->ensure($this->root);
    }

    public function root(): string {return $this->root;}

    private function ensure(string $dir): void
    {
        if (is_link($dir))throw new RuntimeException('Symlinked profile storage forbidden');
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to initialize private profile storage');
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($dir)&0007)!==0)chmod($dir,0700);
    }

    private function path(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$name))throw new RuntimeException('Invalid local filename');
        $path=$this->root.'/'.$name;
        if (is_link($path))throw new RuntimeException('Refusing linked private file');
        return $path;
    }

    public function read(string $name,array $default=[]): array
    {
        $path=$this->path($name);
        if (!is_file($path))return $default;
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($path)&0077)!==0) {
            throw new RuntimeException('Private file must be chmod 600');
        }
        $raw=file_get_contents($path);
        if ($raw===false)throw new RuntimeException('Unable to read private file');
        $value=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($value))throw new RuntimeException('Invalid private JSON');
        return $value;
    }

    public function write(string $name,array $data): void
    {
        $path=$this->path($name);
        $payload=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $file=fopen($tmp,'x');
        if (!$file)throw new RuntimeException('Unable to reserve private file');
        chmod($tmp,0600);
        try {
            if (fwrite($file,$payload)===false)throw new RuntimeException('Unable to write JSON');
            fflush($file);
        } finally {fclose($file);}
        if (!rename($tmp,$path))throw new RuntimeException('Unable to publish private JSON');
        chmod($path,0600);
    }

    public function profile(): ?Profile
    {
        $raw=$this->read('profile.json');
        return $raw===[]?null:new Profile($raw);
    }

    public function saveProfile(Profile $profile): void
    {
        $this->write('profile.json',$profile->toArray());
    }

    public function credentials(): array
    {
        $path=$this->path('secrets.enc');
        if (!is_file($path))return [];
        $encoded=file_get_contents($path);
        if ($encoded===false)throw new RuntimeException('Cannot read credentials');
        try {
            $data=json_decode(Crypt::decryptString($encoded),true,512,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {throw new RuntimeException('Cannot decrypt local credentials with this app key');}
        if (!is_array($data))throw new RuntimeException('Invalid decrypted credentials');
        return $data;
    }

    public function saveCredentials(array $new): void
    {
        $approved=[
            'KIMAI_BASE_URL','KIMAI_API_TOKEN','NEXTCLOUD_URL',
            'NEXTCLOUD_USERNAME','NEXTCLOUD_LOGIN_NAME','NEXTCLOUD_APP_PASSWORD',
            'GITHUB_TOKEN','TELEGRAM_API_ID','TELEGRAM_API_HASH',
            'TELEGRAM_SESSION','KIMAI_WRITE_ENABLED','KIMAI_AUTO_PREVIEW_OPT_IN',
            'KIMAI_MANUAL_PREVIEW_ENABLED',
        ];
        if (array_diff(array_keys($new),$approved)!==[]) {
            throw new RuntimeException('Unknown secret or setting');
        }
        $merged=$this->credentials();
        foreach ($new as $key=>$value) {
            if ($value===null || $value==='')continue;
            if (!is_scalar($value))throw new RuntimeException('Invalid credential');
            $merged[$key]=(string)$value;
        }
        $path=$this->path('secrets.enc');
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $encrypted=Crypt::encryptString(json_encode($merged,JSON_THROW_ON_ERROR));
        $file=fopen($tmp,'x');
        if (!$file)throw new RuntimeException('Cannot create private credential file');
        chmod($tmp,0600);
        try {
            fwrite($file,$encrypted);
            fflush($file);
        } finally {fclose($file);}
        if (!rename($tmp,$path))throw new RuntimeException('Cannot update credentials');
        chmod($path,0600);
    }

    /** Discard only the named secrets, using the same atomic encrypted store. */
    private function forgetCredentials(array $keys): void
    {
        $credentials=$this->credentials();
        foreach ($keys as $key)unset($credentials[$key]);
        $path=$this->path('secrets.enc');
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $file=fopen($tmp,'x');
        if (!$file)throw new RuntimeException('Cannot clear selected credentials');
        chmod($tmp,0600);
        try {
            fwrite($file,Crypt::encryptString(json_encode($credentials,JSON_THROW_ON_ERROR)));
            fflush($file);
        } finally {fclose($file);}
        if (!rename($tmp,$path))throw new RuntimeException('Cannot publish cleared credentials');
        chmod($path,0600);
    }

    /** Local disconnect; remote GitHub token revocation is managed on GitHub. */
    public function disconnectGitHub(): void
    {
        $this->forgetCredentials(['GITHUB_TOKEN']);
    }

    /** Discard only this device's Nextcloud app password, preserving all other integrations. */
    public function disconnectNextcloud(): void
    {
        $this->forgetCredentials([
            'NEXTCLOUD_URL','NEXTCLOUD_USERNAME','NEXTCLOUD_LOGIN_NAME','NEXTCLOUD_APP_PASSWORD'
        ]);
        $this->write('nextcloud-login.json',[]);
        $this->write('settings-sync-state.json',['enabled'=>false,'state'=>'disabled']);
        $this->write('settings-sync-key.json',[]);
    }

    public function sources(): array
    {
        $saved=$this->read('sources.json');
        // Existing authenticated Telegram profiles remain discoverable after
        // the UI introduces its separate, non-collecting setup preference.
        return array_replace([
            'activitywatch'=>true,'github'=>false,'nextcloud_talk'=>false,
            'nextcloud_calendar'=>false,'nextcloud_activity'=>false,
            'nextcloud_mail'=>false,'telegram'=>false,
            'telegram_setup'=>(bool)($saved['telegram']??false),
            'chatgpt_browser'=>true,'claude_browser'=>true,
        ],$saved);
    }

    public function setSources(array $sources): void
    {
        $initial=$this->sources();
        if (array_diff(array_keys($sources),array_keys($initial))!==[])throw new RuntimeException('Unknown source');
        foreach ($sources as $enabled)if (!is_bool($enabled))throw new RuntimeException('Source opt-in must be boolean');
        $merged=array_replace($initial,$sources);
        if (!$merged['activitywatch'])throw new RuntimeException('ActivityWatch is required for automatic classification');
        // Hiding Telegram setup is also an explicit opt-out of collection.
        if (!$merged['telegram_setup'])$merged['telegram']=false;
        $this->write('sources.json',$merged);
    }

    public function ledgerFile(): string {return $this->path('ledger.sqlite3');}
    public function reviewQueueFile(): string {return $this->path('review_queue.sqlite3');}
    public function status(): array {return $this->read('status.json',['result'=>'never_ran']);}
    public function report(): array {return $this->read('review.json',['days'=>[]]);}
}
