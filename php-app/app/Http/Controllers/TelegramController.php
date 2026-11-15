<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\TelegramWebLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Throwable;

/** Local browser onboarding. Telegram login credentials are never in URLs. */
final class TelegramController extends Controller
{
    public function __construct(private readonly LocalStore $store) {}

    private function local(Request $request): void
    {
        if (!in_array(strtolower($request->getHost()),['localhost','127.0.0.1','::1'],true) ||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            abort(403,'Somente na interface local');
        }
    }

    private function requireSelectedSource(): void
    {
        $sources=$this->store->sources();
        if (!$sources['telegram_setup'] && !$sources['telegram']) {
            abort(403,'Habilite Telegram em Fontes de dados antes de conectar a conta.');
        }
    }

    private static function noCache(array $data,int $status=200): JsonResponse
    {
        return response()->json($data,$status)
            ->header('Cache-Control','no-store, max-age=0')
            ->header('Referrer-Policy','no-referrer')
            ->header('X-Content-Type-Options','nosniff')
            ->header('X-Frame-Options','DENY');
    }

    public function configure(Request $request): RedirectResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        $input=$request->validate([
            'telegram_api_id'=>'nullable|regex:/^[1-9][0-9]{3,12}$/D',
            'telegram_api_hash'=>'nullable|regex:/^[a-fA-F0-9]{32}$/D',
            'telegram_labels'=>'nullable|string|max:3000',
        ]);
        try {
            $profile=$this->store->profile();
            if ($profile===null)throw new RuntimeException('Conecte sua conta Kimai primeiro');
            $creds=$this->store->credentials();
            $new=[];
            foreach (['telegram_api_id'=>'TELEGRAM_API_ID','telegram_api_hash'=>'TELEGRAM_API_HASH'] as $key=>$name) {
                if (isset($input[$key])&&$input[$key]!=='')$new[$name]=$input[$key];
            }
            $combined=array_replace($creds,$new);
            if (!isset($combined['TELEGRAM_API_ID'],$combined['TELEGRAM_API_HASH'])) {
                throw new RuntimeException('Informe o ID e hash da API do Telegram');
            }
            $session=$this->store->root().'/telegram-user.session';
            if (is_dir($session)) {
                foreach ($new as $name=>$value) {
                    if ($value!==($creds[$name]??null)) {
                        throw new RuntimeException('Sessão já iniciada. Mantenha o mesmo ID e hash de API');
                    }
                }
            }
            $labels=[];
            foreach (preg_split('/[,\r\n]+/u',$input['telegram_labels']??'')?:[] as $label) {
                $label=trim($label);
                if ($label==='')continue;
                if (strlen($label)>80||strlen($label)<2)throw new RuntimeException('Nome de conversa fora do limite');
                $labels[$label]=$label;
            }
            if (!$labels)throw new RuntimeException('Informe pelo menos uma conversa profissional autorizada');
            if (count($labels)>40)throw new RuntimeException('Limite de 40 conversas profissionais');
            $settings=$profile->toArray();
            $settings['work_messenger_labels']=array_values($labels);
            $updated=new Profile($settings);
            $this->store->saveCredentials($new);
            $this->store->saveProfile($updated);
            return back()->with('ok','Preferências Telegram salvas. Use o QR Code abaixo para autenticar a conta.');
        } catch(Throwable $e) {
            return back()->withErrors(['telegram'=>$e->getMessage()]);
        }
    }

    public function status(Request $request,TelegramWebLogin $login): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        try {
            $state=$login->status();
            $state['enabled']=$this->store->sources()['telegram'];
            return self::noCache($state);
        } catch(Throwable $e) {
            return self::noCache(['status'=>'error','message'=>'Não foi possível verificar a sessão Telegram'],503);
        }
    }

    public function qr(Request $request,TelegramWebLogin $login): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        try {
            return self::noCache($login->startQr());
        } catch(Throwable $e) {
            return self::noCache(['status'=>'error','message'=>'Não foi possível gerar o QR Code. Confira a configuração e tente novamente.'],422);
        }
    }

    public function twoFactor(Request $request,TelegramWebLogin $login): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        $input=$request->validate(['password'=>'required|string|max:1024']);
        try {
            $state=$login->complete2fa($input['password']);
            return self::noCache($state);
        } catch(Throwable $e) {
            return self::noCache(['status'=>'error','message'=>'A senha não foi aceita ou a autenticação expirou.'],422);
        }
    }

    public function activate(Request $request,TelegramWebLogin $login): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        try {
            return self::noCache($login->verifyAndActivate());
        } catch(Throwable $e) {
            return self::noCache(['status'=>'error','message'=>'Conclua a autenticação do Telegram e configure as conversas antes de ativar.'],422);
        }
    }

    public function disable(Request $request): RedirectResponse
    {
        $this->local($request);
        $this->store->setSources(['telegram'=>false]);
        return back()->with('ok','Coleta Telegram desativada. Sua sessão permanece no dispositivo.');
    }
}
