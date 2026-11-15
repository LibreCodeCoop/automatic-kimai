<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\TelegramWebLogin;
use App\AutomaticKimai\TelegramGroupSelection;
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
        ]);
        try {
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
            if ($new!==[])$this->store->saveCredentials($new);
            return back()->with('ok','Credenciais de conexão salvas. Agora conecte pelo QR Code.');
        } catch(Throwable $e) {
            return back()->withErrors(['telegram'=>$e->getMessage()]);
        }
    }

    public function groups(Request $request,TelegramWebLogin $login,
                           TelegramGroupSelection $selection): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        if (($login->status()['status']??null)!=='authorized') {
            return self::noCache(['status'=>'error','message'=>'Conecte sua conta antes de listar grupos'],403);
        }
        $request->validate([
            'cursor'=>'sometimes|string|max:2048',
            'folder'=>'sometimes|in:0,1',
        ]);
        try {
            return self::noCache(array_replace(
                ['status'=>'ok'],
                $selection->page($request->query('cursor'),null,
                    (int)$request->query('folder',0)),
            ));
        } catch(\InvalidArgumentException) {
            return self::noCache(['status'=>'error','message'=>'Lista expirada. Atualize para começar novamente.'],422);
        } catch(Throwable) {
            return self::noCache(['status'=>'error','message'=>'Não foi possível consultar os grupos. Tente novamente.'],503);
        }
    }

    public function catalog(Request $request,TelegramWebLogin $login,
                            TelegramGroupSelection $selection): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        // The adapter authenticates the user session while obtaining selfId.
        // Avoid a second MadelineProto session handshake via status() here.
        if (!$login->configured()) {
            return self::noCache(['status'=>'error','message'=>'Conecte o Telegram para listar grupos'],403);
        }
        $input=$request->validate([
            'cursor'=>'sometimes|string|max:2048',
            'folder'=>'sometimes|in:0,1',
        ]);
        try {
            return self::noCache($selection->catalog(
                $input['cursor']??null,null,(int)($input['folder']??0)
            ));
        } catch (\InvalidArgumentException) {
            return self::noCache(['status'=>'error',
                'message'=>'Catálogo expirado. Atualize a busca para reiniciar.'],422);
        } catch (Throwable) {
            return self::noCache(['status'=>'error',
                'message'=>'Não foi possível consultar esta página do Telegram.'],503);
        }
    }

    public function communities(Request $request,TelegramWebLogin $login,
                                TelegramGroupSelection $selection): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        if (($login->status()['status']??null)!=='authorized') {
            return self::noCache(['status'=>'error','message'=>'Telegram não conectado'],403);
        }
        try {
            return self::noCache([
                'status'=>'ok','communities'=>$selection->communities()
            ]);
        } catch(Throwable) {
            return self::noCache(['status'=>'error',
                'message'=>'Não foi possível consultar as comunidades do Telegram.'],503);
        }
    }

    public function avatar(Request $request,TelegramWebLogin $login): \Illuminate\Http\Response
    {
        $this->local($request);
        $this->requireSelectedSource();
        $image=$login->avatar();
        if ($image===null)return response('',404)->header('Cache-Control','no-store');
        return response($image['body'],200)
            ->header('Content-Type',$image['mime'])
            ->header('Cache-Control','private, no-store, max-age=0')
            ->header('X-Content-Type-Options','nosniff')
            ->header('Content-Security-Policy',"default-src 'none'; sandbox");
    }

    public function disconnect(Request $request,TelegramWebLogin $login): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        try {
            return self::noCache($login->disconnect());
        } catch(Throwable) {
            return self::noCache(['status'=>'error',
                'message'=>'Não foi possível encerrar a sessão no Telegram. A análise local foi suspensa.'],503);
        }
    }

    public function selectGroups(Request $request,TelegramWebLogin $login,
                                 TelegramGroupSelection $selection): JsonResponse
    {
        $this->local($request);
        $this->requireSelectedSource();
        if (($login->status()['status']??null)!=='authorized') {
            return self::noCache(['status'=>'error','message'=>'Conecte sua conta antes de escolher grupos'],403);
        }
        $input=$request->validate([
            'groups'=>'present|array',
            'groups.*'=>'required|string|regex:/^-?[1-9][0-9]*$/D',
        ]);
        try {
            return self::noCache($selection->select($input['groups']));
        } catch(Throwable) {
            return self::noCache(['status'=>'error','message'=>'Verifique se todos os grupos marcados pertencem à sua conta.'],422);
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
