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

    /** Only an already verified private MTProto session can enable collection. */
    private function verifySession(): bool
    {
        $secrets=$this->store->credentials();
        $session=$this->store->root().'/telegram-user.session';
        if (!is_dir($session)||is_link($session))return false;
        $old=umask(0077);
        try {
            $api=new API($session,MadelineTelegramAdapter::settings(
                (int)$secrets['TELEGRAM_API_ID'],
                (string)$secrets['TELEGRAM_API_HASH'],
                $this->store->root().'/telegram-user.log'
            ));
            $me=$api->getSelf();
            $valid=$api->getAuthorization()===API::LOGGED_IN &&
                is_array($me) && is_int($me['id']??null) &&
                ($me['bot']??false)===false;
            unset($api);
            return $valid;
        } catch (\Throwable) {
            return false;
        } finally {umask($old);}
    }

    public function status(): array
    {
        if (!$this->configured())return ['status'=>'not_configured'];
        $state=$this->readWorkerState();
        if ($state!==null) {
            if (($state['status']??null)==='authorized') {
                return $this->verifySession()?['status'=>'authorized']:['status'=>'ready_for_qr'];
            }
            if (self::activeWorker($state))return $this->publicState($state);
            if (in_array($state['status']??'', ['expired','error'],true)) {
                return $this->publicState($state);
            }
        }
        return $this->verifySession()?['status'=>'authorized']:['status'=>'ready_for_qr'];
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
        if ($this->status()['status']!=='authorized' || !$this->verifySession()) {
            throw new RuntimeException('Telegram ainda não autenticado neste dispositivo');
        }
        if ($activate) {
            $profile=$this->store->profile();
            if ($profile===null||$profile->get('work_messenger_labels')===[]) {
                throw new RuntimeException('Escolha as conversas profissionais autorizadas');
            }
            $this->store->setSources(['telegram'=>true]);
        }
        return ['status'=>'authorized','enabled'=>$this->store->sources()['telegram']];
    }
}
