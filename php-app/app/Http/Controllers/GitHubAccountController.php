<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\GitHubIdentity;
use App\AutomaticKimai\HttpTransport;
use App\AutomaticKimai\LocalStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Throwable;

final class GitHubAccountController extends Controller
{
    public function __construct(private readonly LocalStore $store) {}

    private function requireLocal(Request $request): void
    {
        if (!in_array(strtolower($request->getHost()),['localhost','127.0.0.1','::1'],true)||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            abort(403,'Somente na interface local');
        }
    }

    private static function noCache(array $value,int $status=200): JsonResponse
    {
        return response()->json($value,$status)
            ->header('Cache-Control','no-store, max-age=0')
            ->header('X-Content-Type-Options','nosniff')
            ->header('Referrer-Policy','no-referrer');
    }

    public function profile(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        try {
            return self::noCache((new GitHubIdentity(new HttpTransport()))->
                profile($this->store->credentials()));
        } catch(Throwable) {
            return self::noCache(['status'=>'unavailable'],503);
        }
    }

    public function avatar(Request $request): \Illuminate\Http\Response
    {
        $this->requireLocal($request);
        try {
            $image=(new GitHubIdentity(new HttpTransport()))->
                avatar($this->store->credentials());
            if ($image!==null) {
                return response($image['body'],200)
                    ->header('Content-Type',$image['mime'])
                    ->header('Cache-Control','private, no-store, max-age=0')
                    ->header('X-Content-Type-Options','nosniff')
                    ->header('Content-Security-Policy',"default-src 'none'; sandbox");
            }
        } catch(Throwable) {}
        return response('',404)->header('Cache-Control','no-store');
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->requireLocal($request);
        try {
            $this->store->disconnectGitHub();
            return self::noCache([
                'status'=>'disconnected',
                'message'=>'Token removido deste computador. Revogue o token também nas configurações do GitHub para invalidá-lo no servidor.',
            ]);
        } catch(Throwable) {
            return self::noCache(['status'=>'error','message'=>'Não foi possível desconectar o GitHub.'],422);
        }
    }
}
