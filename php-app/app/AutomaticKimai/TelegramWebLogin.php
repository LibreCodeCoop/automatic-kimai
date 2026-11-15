<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use danog\MadelineProto\API;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Localhost-only browser control of a separate PHP MTProto login worker.
 * The worker remains running while the user scans QR and submits Telegram 2FA.
 */
final class TelegramWebLogin
{
    public function __construct(private readonly LocalStore $store) {}

    public function configured(): bool
    {
        $creds=$this->store->credentials();
        return ctype_digit((string)($creds['TELEGRAM_API_ID']??'')) &&
            (int)$creds['TELEGRAM_API_ID']>0 &&
            preg_match('/^[a-f0-9]{32}$/iD',(string)($creds['TELEGRAM_API_HASH']??''))===1;
    }

    private function publicState(array $raw): array
    {
        $status=$raw['status']??'ready_for_qr';
        $allowed=['starting','pending','scan_qr','password_required','authorized','expired','error'];
        if (!in_array($status,$allowed,true))$status='ready_for_qr';
        $result=['status'=>$status];
        if ($status==='scan_qr') {
            $exp=(int)($raw['expires_at']??0);
            if ($exp<=time()) {
                return ['status'=>'pending']; // Worker will rotate expired QR.
            }
            $result['expires_in']=max(0,$exp-time());
            $svg=$raw['qr_svg_base64']??null;
            if (is_string($svg)&&strlen($svg)<120000)$result['qr_svg_base64']=$svg;
        }
        if ($status==='password_required'&&
            in_array($raw['message']??'',['Senha não aceita; tente novamente','O Telegram não confirmou a senha'],true)) {
            $result['message']=$raw['message'];
        }
        return $result;
    }

    private function readWorkerState(): ?array
    {
        $raw=$this->store->read('telegram-login-state.json');
        if ($raw===[])return null;
        try {
            $stamp=strtotime((string)($raw['updated_utc']??''));
            if (!$stamp||time()-$stamp>330)return null;
        } catch (\Throwable) {return null;}
        return $raw;
    }

    private static function activeWorker(array $state): bool
    {
        $pid=(int)($state['worker_pid']??0);
        return $pid>0 && function_exists('posix_kill') && @posix_kill($pid,0);
    }

    /** Authorized profile information, without phone, contacts or API secrets. */
    public static function publicAccount(array $self): ?array
    {
        if (!is_int($self['id']??null)||$self['id']<1||($self['bot']??false)===true) {
            return null;
        }
        $name=trim((string)($self['first_name']??'').' '.(string)($self['last_name']??''));
        $username=(string)($self['username']??'');
        if (mb_strlen($username)>64||preg_match('/^[a-zA-Z0-9_]*$/D',$username)!==1)$username='';
        return [
            'id'=>(string)$self['id'],
            'display_name'=>mb_substr($name!==''?$name:'Usuário Telegram',0,120),
            'username'=>$username,
        ];
    }

    /** Only an already verified private MTProto session can enable collection. */
    private function verifiedAccount(): ?array
    {
        $secrets=$this->store->credentials();
        $session=$this->store->root().'/telegram-user.session';
        if (!is_dir($session)||is_link($session)||
            empty($secrets['TELEGRAM_API_ID'])||empty($secrets['TELEGRAM_API_HASH']))return null;
        $old=umask(0077);
        try {
            $api=new API($session,MadelineTelegramAdapter::settings(
                (int)$secrets['TELEGRAM_API_ID'],
                (string)$secrets['TELEGRAM_API_HASH'],
                $this->store->root().'/telegram-user.log'
            ));
            $me=$api->getSelf();
            $valid=$api->getAuthorization()===API::LOGGED_IN &&
                is_array($me)?self::publicAccount($me):null;
            unset($api);
            return $valid;
        } catch (\Throwable) {
            return null;
        } finally {umask($old);}
    }

    public function status(): array
    {
        if (!$this->configured())return ['status'=>'not_configured'];
        $state=$this->readWorkerState();
        if ($state!==null) {
            if (($state['status']??null)==='authorized') {
                $account=$this->verifiedAccount();
                return $account ? ['status'=>'authorized','account'=>$account] : ['status'=>'ready_for_qr'];
            }
            if (self::activeWorker($state))return $this->publicState($state);
            if (in_array($state['status']??'', ['expired','error'],true)) {
                return $this->publicState($state);
            }
        }
        $account=$this->verifiedAccount();
        return $account ? ['status'=>'authorized','account'=>$account] : ['status'=>'ready_for_qr'];
    }

    public function startQr(): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('Configure a API Telegram antes de gerar QR Code');
        }
        $previous=$this->status();
        if ($previous['status']==='authorized')return $previous;
        $live=$this->readWorkerState();
        if ($live!==null&&self::activeWorker($live)) {
            return $this->publicState($live);
        }
        // No sensitive or user-controlled parameters are passed to the shell.
        $file=base_path('scripts/telegram-login-worker.php');
        if (!is_file($file)||is_link($file)) {
            throw new RuntimeException('O componente de login Telegram não está instalado');
        }
        $this->store->write('telegram-login-state.json',[
            'status'=>'starting','updated_utc'=>gmdate('c'),'worker_pid'=>0,
        ]);
        $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($file).' > /dev/null 2>&1 &';
        exec($command);
        for ($i=0;$i<34;$i++) {
            usleep(250000);
            $raw=$this->readWorkerState();
            if ($raw===null)continue;
            $s=$raw['status']??'starting';
            if (in_array($s,['scan_qr','password_required','authorized','error'],true)) {
                return $this->publicState($raw);
            }
        }
        return ['status'=>'pending'];
    }

    /**
     * Download only this account's small profile picture. No file is persisted,
     * and SVG/unknown image formats are never proxied into the desktop UI.
     */
    public function avatar(?object $client=null): ?array
    {
        if (!$this->configured())return null;
        $session=$this->store->root().'/telegram-user.session';
        if ((!is_dir($session)&&$client===null)||is_link($session))return null;
        $credentials=$this->store->credentials();
        $own=$client===null;
        $old=umask(0077);
        try {
            $client??=new API($session,MadelineTelegramAdapter::settings(
                (int)$credentials['TELEGRAM_API_ID'],(string)$credentials['TELEGRAM_API_HASH'],
                $this->store->root().'/telegram-user.log'
            ));
            $self=$client->getSelf();
            if (!is_array($self)||self::publicAccount($self)===null) return null;
            $list=$client->photos->getUserPhotos(
                user_id:'me',offset:0,max_id:0,limit:1
            );
            $photo=$list['photos'][0]??null;
            if (!is_array($photo)||($photo['_']??null)!=='photo')return null;
            $small=[];
            foreach ($photo['sizes']??[] as $size) {
                if (!is_array($size)||!is_int($size['w']??null)||
                    !is_int($size['h']??null)||
                    $size['w']<1||$size['h']<1||
                    $size['w']>320||$size['h']>320)continue;
                if ($small===[]||$size['w']*$size['h']>$small['w']*$small['h'])$small=$size;
            }
            if ($small===[])return null;
            $photo['sizes']=[$small];
            $bytes='';
            $client->downloadToCallable($photo,static function(string $chunk,int $offset)use(&$bytes) {
                if ($offset!==strlen($bytes)||strlen($bytes)+strlen($chunk)>262144) {
                    throw new RuntimeException('Telegram avatar exceeds safe download bounds');
                }
                $bytes.=$chunk;
            },null,false,0,262144);
            return self::safeAvatar($bytes);
        } catch (\Throwable) {
            return null;
        } finally {
            if ($own)unset($client);
            umask($old);
        }
    }

    public static function safeAvatar(string $content): ?array
    {
        if ($content===''||strlen($content)>262144)return null;
        $size=@getimagesizefromstring($content);
        if ($size===false||($size[0]??0)>512||($size[1]??0)>512)return null;
        $mime=$size['mime']??null;
        if (!in_array($mime,['image/jpeg','image/png','image/webp'],true))return null;
        return ['mime'=>$mime,'body'=>$content];
    }

    /**
     * Explicit local logout and remote session revocation. The Telegram app
     * ID/hash are kept for future QR login; group selections are account-bound
     * and must not carry over to another Telegram identity.
     */
    public function disconnect(?object $client=null): array
    {
        $this->store->setSources(['telegram'=>false]);
        $worker=$this->readWorkerState();
        if ($client===null && $worker!==null && self::activeWorker($worker)) {
            throw new RuntimeException('Há uma autenticação Telegram em andamento');
        }
        $session=$this->store->root().'/telegram-user.session';
        if (is_link($session))throw new RuntimeException('Sessão Telegram insegura');
        if (is_dir($session)||$client!==null) {
            $creds=$this->store->credentials();
            $own=$client===null;
            $old=umask(0077);
            try {
                $client??=new API($session,MadelineTelegramAdapter::settings(
                    (int)$creds['TELEGRAM_API_ID'],(string)$creds['TELEGRAM_API_HASH'],
                    $this->store->root().'/telegram-user.log'
                ));
                $client->logout();
            } finally {
                if ($own)unset($client);
                umask($old);
            }
        }
        $this->store->write('telegram-login-state.json',[]);
        $profile=$this->store->profile();
        if ($profile!==null) {
            $settings=$profile->toArray();
            $settings['work_messenger_dialog_ids']=[];
            $settings['work_messenger_group_titles']=[];
            $settings['work_messenger_labels']=[];
            $settings['work_messenger_recommendations_reviewed']=false;
            $this->store->saveProfile(new Profile($settings));
        }
        $this->store->write('telegram_scoped_history.json',[]);
        $this->store->write('telegram_read_progress.json',[]);
        return ['status'=>'disconnected','enabled'=>false];
    }

    /** Only the worker decrypts this; the web process never handles Telegram RPC. */
    public function complete2fa(#[\SensitiveParameter] string $password): array
    {
        if (strlen($password)<1||strlen($password)>1024) {
            throw new RuntimeException('Senha de duas etapas inválida');
        }
        $state=$this->readWorkerState();
        if ($state===null||($state['status']??'')!=='password_required'||!self::activeWorker($state)) {
            throw new RuntimeException('A autenticação QR expirou ou a senha ainda não foi solicitada');
        }
        $path=$this->store->root().'/telegram-login-2fa.enc';
        if (is_link($path)||file_exists($path)) {
            throw new RuntimeException('Uma tentativa de senha já está em andamento');
        }
        $old=umask(0077);
        try {
            $handle=fopen($path,'x');
            if (!$handle)throw new RuntimeException('Não foi possível proteger a senha da sessão');
            chmod($path,0600);
            $value=Crypt::encryptString($password);
            fwrite($handle,$value);fflush($handle);fclose($handle);
            unset($value);
            return ['status'=>'pending'];
        } finally {umask($old);}
    }

    public function verifyAndActivate(bool $activate=true): array
    {
        if ($this->status()['status']!=='authorized') {
            throw new RuntimeException('Telegram ainda não autenticado neste dispositivo');
        }
        if ($activate) {
            $profile=$this->store->profile();
            if ($profile===null || ($profile->get('work_messenger_labels')===[] &&
                $profile->get('work_messenger_dialog_ids')===[])) {
                throw new RuntimeException('Escolha seus grupos profissionais autorizados');
            }
            $this->store->setSources(['telegram_setup'=>true,'telegram'=>true]);
        }
        return ['status'=>'authorized','enabled'=>$this->store->sources()['telegram']];
    }
}
