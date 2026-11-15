<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\NextcloudLoginFlow;
use App\AutomaticKimai\LocalStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

final class NextcloudLoginController extends Controller
{
    public function __construct(
        private readonly NextcloudLoginFlow $flow,
        private readonly LocalStore $store,
    ) {}

    private function requireLocal(Request $request): void
    {
        if (!in_array(strtolower($request->getHost()),['localhost','127.0.0.1','::1'],true) ||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            abort(403,'Somente na interface local');
        }
    }

    private function requireSelectedSource(): void
    {
        $sources=$this->store->sources();
        if (!$sources['nextcloud_calendar'] && !$sources['nextcloud_talk']) {
            abort(403,'Habilite Agenda ou Talk em Fontes de dados antes de conectar o Nextcloud.');
        }
    }

    private static function reply(array $payload,int $status=200): JsonResponse
    {
        return response()->json($payload,$status)
            ->header('Cache-Control','no-store, max-age=0')
            ->header('Referrer-Policy','no-referrer')
            ->header('X-Content-Type-Options','nosniff')
            ->header('X-Frame-Options','DENY');
    }

    public function status(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        $this->requireSelectedSource();
        return self::reply($this->flow->status());
    }

    public function start(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        $this->requireSelectedSource();
        $data=$request->validate(['url'=>'required|string|max:250']);
        try { return self::reply($this->flow->start($data['url'])); }
        catch (Throwable $exception) {
            return self::reply(['status'=>'error','message'=>$exception->getMessage()],422);
        }
    }

    public function poll(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        $this->requireSelectedSource();
        try {return self::reply($this->flow->poll());}
        catch (Throwable $exception) {
            return self::reply(['status'=>'error','message'=>$exception->getMessage()],422);
        }
    }
}
