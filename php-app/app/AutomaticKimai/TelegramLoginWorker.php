<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use danog\MadelineProto\API;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Runs in a separate, short-lived PHP process so the MadelineProto instance
 * survives browser requests while scanning QR and confirming Telegram 2FA.
 * No Python, Telethon, message send or Kimai mutation is performed here.
 */
final class TelegramLoginWorker
{
    private const STATE='telegram-login-state.json';
    private const PASSWORD='telegram-login-2fa.enc';
    private const MAX_SECONDS=300;

    public function __construct(private readonly LocalStore $store) {}

    public function save(array $public): void
    {
        $this->store->write(self::STATE,array_replace($public,[
            'updated_utc'=>gmdate('c'),
            'worker_pid'=>getmypid(),
        ]));
    }

    private function api(): API
    {
        $credentials=$this->store->credentials();
        $id=(int)($credentials['TELEGRAM_API_ID']??0);
        $hash=(string)($credentials['TELEGRAM_API_HASH']??'');
        $settings=MadelineTelegramAdapter::settings(
            $id,$hash,$this->store->root().'/telegram-user.log'
        );
        return new API($this->store->root().'/telegram-user.session',$settings);
    }

    /** Waits in the worker only; never stores 2FA password as plaintext. */
    private function finish2fa(API $api,int $deadline): bool
    {
        $this->save(['status'=>'password_required']);
        $secretFile=$this->store->root().'/'.self::PASSWORD;
        while (time()<$deadline) {
            if (is_link($secretFile))throw new RuntimeException('Unsafe password channel');
            if (is_file($secretFile)) {
                $encrypted=file_get_contents($secretFile);
                @unlink($secretFile);
                if (!is_string($encrypted)||strlen($encrypted)>10000) {
                    $this->save(['status'=>'password_required','message'=>'Senha inválida']);
                    continue;
                }
                try {
                    $password=Crypt::decryptString($encrypted);
                    unset($encrypted);
                    $api->complete2faLogin($password);
                    $password=str_repeat("\0",strlen($password));
                    unset($password);
                    if ($api->getAuthorization()===API::LOGGED_IN) return true;
                    $this->save(['status'=>'password_required','message'=>'O Telegram não confirmou a senha']);
                } catch (Throwable) {
                    unset($password,$encrypted);
                    $this->save(['status'=>'password_required','message'=>'Senha não aceita; tente novamente']);
                }
            }
            usleep(350000);
        }
        return false;
    }

    public function run(): int
    {
        $old=umask(0077);
        $lockFile=$this->store->root().'/telegram-login-worker.lock';
        if (is_link($lockFile))throw new RuntimeException('Unsafe Telegram login lock');
        $lock=fopen($lockFile,'c');
        if (!$lock)throw new RuntimeException('Unable to lock Telegram onboarding');
        chmod($lockFile,0600);
        if (!flock($lock,LOCK_EX|LOCK_NB)) {
            fclose($lock);return 0;
        }
        try {
            $this->save(['status'=>'starting']);
            $deadline=time()+self::MAX_SECONDS;
            $api=$this->api();
            $qr=null;
            while(time()<$deadline) {
                $state=$api->getAuthorization();
                if ($state===API::LOGGED_IN) {
                    $user=$api->getSelf();
                    if (!is_array($user)||($user['bot']??false)||
                        !is_int($user['id']??null)||$user['id']<1) {
                        throw new RuntimeException('Telegram identity verification failed');
                    }
                    $userId=$user['id'];
                    unset($api);
                    API::finalize();
                    // Do not claim a working login until it survived a reload.
                    $again=$this->api();
                    $self=$again->getSelf();
                    if ($again->getAuthorization()!==API::LOGGED_IN||
                        !is_array($self)||($self['id']??null)!==$userId) {
                        throw new RuntimeException('Telegram authorization did not persist');
                    }
                    unset($again);
                    API::finalize();
                    $session=$this->store->root().'/telegram-user.session';
                    if (is_dir($session))chmod($session,0700);
                    $this->save(['status'=>'authorized']);
                    return 0;
                }
                if ($state===API::WAITING_PASSWORD) {
                    if (!$this->finish2fa($api,$deadline))break;
                    continue;
                }
                if ($state!==API::NOT_LOGGED_IN) {
                    $this->save(['status'=>'pending']);
                    usleep(400000);
                    continue;
                }
                $qr=$api->qrLogin();
                if ($api->getAuthorization()===API::WAITING_PASSWORD)continue;
                if ($qr===null) {
                    $this->save(['status'=>'pending']);
                    usleep(500000);
                    continue;
                }
                $svg=$qr->getQRSvg(320,4);
                if (strlen($svg)>80000)throw new RuntimeException('Telegram QR too large');
                $this->save([
                    'status'=>'scan_qr','qr_svg_base64'=>base64_encode($svg),
                    'expires_at'=>$qr->expiry,
                ]);
                // The library's async wait must remain active so the
                // QR-login event is delivered and the 2FA state is retained.
                $qr->waitForLoginOrQrCodeExpiration();
            }
            $this->save(['status'=>'expired']);
            return 2;
        } catch (Throwable) {
            $this->save(['status'=>'error','message'=>'Não foi possível concluir o login Telegram']);
            return 1;
        } finally {
            @unlink($this->store->root().'/'.self::PASSWORD);
            flock($lock,LOCK_UN);fclose($lock);
            umask($old);
        }
    }
}
