<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\NextcloudLoginFlow;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\NextcloudIdentity;
use App\AutomaticKimai\HttpTransport;
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
        return self::reply($this->flow->status());
    }

    public function start(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        $data=$request->validate(['url'=>'required|string|max:250']);
        try { return self::reply($this->flow->start($data['url'])); }
        catch (Throwable $exception) {
            return self::reply(['status'=>'error','message'=>$exception->getMessage()],422);
        }
    }

    /** An individual Nextcloud permission toggle cannot alter unrelated sources. */
    public function source(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        $data=$request->validate([
            'source'=>'required|in:nextcloud_calendar,nextcloud_talk,nextcloud_mail,nextcloud_activity',
            'enabled'=>'required|boolean',
        ]);
        try {
            $this->store->setSources([$data['source']=>(bool)$data['enabled']]);
            return self::reply(['status'=>'ok','sources'=>array_intersect_key(
                $this->store->sources(),array_flip([
                    'nextcloud_calendar','nextcloud_talk','nextcloud_mail','nextcloud_activity'
                ])
            )]);
        } catch (Throwable) {
            return self::reply(['status'=>'error','message'=>'Não foi possível salvar esta fonte.'],422);
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        try {
            $this->store->disconnectNextcloud();
            return self::reply([
                'status'=>'disconnected',
                'message'=>'Conta desconectada deste computador. Revogue o acesso de aplicativo na página Segurança do Nextcloud para invalidar a senha remota.',
            ]);
        } catch (Throwable) {
            return self::reply(['status'=>'error','message'=>'Não foi possível desconectar a conta.'],422);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        try {
            return self::reply((new NextcloudIdentity(new HttpTransport()))
                ->profile($this->store->credentials()));
        } catch (Throwable) {
            return self::reply(['status'=>'unavailable'],503);
        }
    }

    public function avatar(Request $request): \Illuminate\Http\Response
    {
        $this->requireLocal($request);
        try {
            $image=(new NextcloudIdentity(new HttpTransport()))
                ->avatar($this->store->credentials());
            if ($image!==null) {
                return response($image['body'],200)
                    ->header('Content-Type',$image['type'])
                    ->header('Cache-Control','private, no-store, max-age=0')
                    ->header('X-Content-Type-Options','nosniff')
                    ->header('Content-Security-Policy',"default-src 'none'; sandbox");
            }
        } catch (Throwable) {}
        return response('',404)->header('Cache-Control','no-store')
            ->header('X-Content-Type-Options','nosniff');
    }

    public function poll(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        try {return self::reply($this->flow->poll());}
        catch (Throwable $exception) {
            return self::reply(['status'=>'error','message'=>$exception->getMessage()],422);
        }
    }
}
