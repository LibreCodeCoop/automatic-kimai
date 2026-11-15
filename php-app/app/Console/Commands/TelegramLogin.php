<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\MadelineTelegramAdapter;
use danog\MadelineProto\API;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Interactive, local-only Telegram QR / 2FA onboarding.
 *
 * The existing Telethon authorization is NOT revoked. Login codes and
 * passwords never pass through browser endpoints, systemd, or log output.
 */
final class TelegramLogin extends Command
{
    protected $signature='automatic-kimai:telegram-login {--verify : Only check an already imported and authorized session}';
    protected $description='Authorize an individual Telegram MTProto session using local terminal QR and 2FA';

    private static function promptHidden(string $label): string
    {
        if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            throw new RuntimeException('A real local terminal is required for 2FA');
        }
        fwrite(STDOUT, $label);
        // The secret is never passed as a shell argument or logged.
        $shellState = trim((string) shell_exec('stty -g < /dev/tty 2>/dev/null'));
        if ($shellState==='')throw new RuntimeException('Terminal echo cannot be controlled');
        $shellSafe=escapeshellarg($shellState);
        try {
            shell_exec('stty -echo < /dev/tty 2>/dev/null');
            $password = fgets(STDIN, 4096);
        } finally {
            shell_exec('stty '.$shellSafe.' < /dev/tty 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        }
        if (!is_string($password) || trim($password)==='') {
            throw new RuntimeException('2FA password was not entered');
        }
        return rtrim($password,"\r\n");
    }

    public function handle(LocalStore $store): int
    {
        if (!defined('STDIN') || !function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            $this->error('Abra um terminal local para autenticar o Telegram. Não envie senha ou código ao ChatGPT.');
            return self::FAILURE;
        }
        $creds=$store->credentials();
        $session=$store->root().'/telegram-user.session';
        try {
            $settings=MadelineTelegramAdapter::settings(
                (int)($creds['TELEGRAM_API_ID']??0),
                (string)($creds['TELEGRAM_API_HASH']??''),
                $store->root().'/telegram-user.log'
            );
            umask(0077);
            $api=new API($session,$settings);
            if (!$this->option('verify')) {
                if ($api->getAuthorization()===API::NOT_LOGGED_IN) {
                    $this->line('Abra Telegram no celular: Configurações → Dispositivos → Conectar dispositivo.');
                    $start=time();
                    do {
                        $qr=$api->qrLogin();
                        if ($qr!==null) {
                            $this->line($qr->getQRText());
                            $qr->waitForLoginOrQrCodeExpiration();
                        }
                        if (time()-$start>=240) {
                            throw new RuntimeException('Login QR expirou sem concluir autenticação');
                        }
                    } while ($api->getAuthorization()===API::NOT_LOGGED_IN);
                }
                if ($api->getAuthorization()===API::WAITING_PASSWORD) {
                    $password=self::promptHidden('Senha da verificação em duas etapas (não exibida): ');
                    try {
                        $api->complete2faLogin($password);
                    } finally {
                        // Clear the local variable; PHP is not secure memory,
                        // so never keep the password in a string property.
                        $password=str_repeat("\0",strlen($password));
                        unset($password);
                    }
                }
            }
            $me=$api->getSelf();
            if ($api->getAuthorization()!==API::LOGGED_IN ||
                !is_array($me)||!is_int($me['id']??null)||($me['bot']??false)) {
                throw new RuntimeException('Telegram ainda não confirmou autenticação do usuário');
            }
            if (is_dir($session))chmod($session,0700);
            $this->info('Telegram: autenticação individual confirmada. Nenhuma mensagem enviada.');
            unset($api);
            API::finalize();
            return self::SUCCESS;
        } catch(Throwable $e) {
            $this->error('Telegram: autenticação incompleta ('.get_class($e).'). Sessão Python mantida.');
            return self::FAILURE;
        }
    }
}
