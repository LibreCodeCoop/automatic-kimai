<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\SettingsSyncService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Http\RedirectResponse;
use Throwable;

final class SettingsSyncController extends Controller
{
    public function __construct(private readonly SettingsSyncService $sync) {}

    private static function local(Request $request): void
    {
        if (!in_array(strtolower($request->getHost()),['localhost','127.0.0.1','::1'],true)||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            abort(403,'Somente no aplicativo local.');
        }
    }

    private static function message(string $action): string
    {
        return match ($action) {
            'created'=>'Backup criptografado criado na sua conta Nextcloud.',
            'restored'=>'Configurações restauradas. Autorize o Telegram separadamente neste dispositivo.',
            'needs_resolution','conflict'=>'Conflito identificado. Escolha qual configuração deverá prevalecer.',
            'uploaded'=>'Suas configurações foram enviadas de forma criptografada.',
            'unchanged'=>'As configurações já estão sincronizadas.',
            default=>'Operação concluída.',
        };
    }

    public function enable(Request $request): RedirectResponse
    {
        self::local($request);
        $data=$request->validate([
            'recovery_password'=>'required|string|min:16|max:1024',
            'include_credentials'=>'sometimes|boolean',
            'confirmed'=>'accepted',
        ]);
        try {
            $result=$this->sync->enable(
                $data['recovery_password'],(bool)($data['include_credentials']??false)
            );
            return redirect()->route('kimai.home',['view'=>'settings_sync'])->with('ok',self::message($result));
        } catch (Throwable $e) {
            return redirect()->route('kimai.home',['view'=>'settings_sync'])
                ->withErrors(['backup'=>self::safeError($e)]);
        }
    }

    public function synchronize(Request $request): RedirectResponse
    {
        self::local($request);
        try {$result=$this->sync->synchronize();}
        catch (Throwable $e) {
            return redirect()->route('kimai.home',['view'=>'settings_sync'])
                ->withErrors(['backup'=>self::safeError($e)]);
        }
        return redirect()->route('kimai.home',['view'=>'settings_sync'])->with('ok',self::message($result));
    }

    public function resolve(Request $request): RedirectResponse
    {
        self::local($request);
        $data=$request->validate([
            'decision'=>'required|in:remote,local',
            'confirmed'=>'accepted',
        ]);
        try {$result=$this->sync->resolve($data['decision']);}
        catch (Throwable $e) {
            return redirect()->route('kimai.home',['view'=>'settings_sync'])
                ->withErrors(['backup'=>self::safeError($e)]);
        }
        return redirect()->route('kimai.home',['view'=>'settings_sync'])->with('ok',self::message($result));
    }

    public function disable(Request $request): RedirectResponse
    {
        self::local($request);
        $request->validate(['confirmed'=>'accepted']);
        try {$this->sync->disable();}
        catch (Throwable $e) {
            return redirect()->route('kimai.home',['view'=>'settings_sync'])
                ->withErrors(['backup'=>self::safeError($e)]);
        }
        return redirect()->route('kimai.home',['view'=>'settings_sync'])
            ->with('ok','Sincronização desativada neste dispositivo. O backup remoto foi preservado.');
    }

    private static function safeError(Throwable $error): string
    {
        // Only messages from our domain code are safe to show. Never expose
        // cURL/auth/SQL exceptions that may include private hosts or tokens.
        return $error instanceof \RuntimeException || $error instanceof \InvalidArgumentException
            ? $error->getMessage()
            : 'Operação indisponível. Confira a conexão e tente novamente.';
    }
}
