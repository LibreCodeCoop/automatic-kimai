<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Nextcloud Login Flow v2: the existing default browser authenticates the user,
 * and this client receives its own revocable app password. Never read browser
 * cookies, desktop keyrings or a Nextcloud client's existing app password.
 *
 * https://docs.nextcloud.com/server/stable/developer_manual/client_apis/LoginFlow/
 */
final class NextcloudLoginFlow
{
    private const TTL_SECONDS=1200;
    private const STATE_FILE='nextcloud-login.json';

    public function __construct(private readonly LocalStore $store) {}

    public static function normalizeServer(string $url): string
    {
        $url=trim($url);
        if (strlen($url)>250 || str_contains($url,"\\") || preg_match('/[\x00-\x20\x7f]/',$url)) {
            throw new RuntimeException('Informe um endereço HTTPS válido do Nextcloud.');
        }
        $parts=parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme']??'')!=='https' ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Informe somente o endereço HTTPS do Nextcloud.');
        }
        $host=strtolower($parts['host']);
        $literal=trim($host,'[]');
        if ($literal==='localhost' || str_ends_with($literal,'.localhost') ||
            $literal==='::1' || $literal==='0.0.0.0' || str_starts_with($literal,'127.') ||
            str_starts_with($literal,'169.254.')) {
            throw new RuntimeException('Informe o endereço HTTPS do servidor Nextcloud, não um serviço local.');
        }
        $path=$parts['path']??'';
        $decoded=rawurldecode($path);
        if (str_contains($decoded,"\\") || preg_match('~/(?:\.\.?)(?:/|$)~',$decoded) ||
            str_contains($decoded,'//')) {
            throw new RuntimeException('O caminho do endereço Nextcloud é inválido.');
        }
        $port=isset($parts['port']) ? ':'.$parts['port'] : '';
        return 'https://'.$host.$port.rtrim($path,'/');
    }

    private static function returnedUrl(string $url,string $base,string $kind): string
    {
        if (strlen($url)>1200 || preg_match('/[\x00-\x20\x7f]/',$url) || str_contains($url,"\\")) {
            throw new RuntimeException('O servidor retornou um endereço de autenticação inválido.');
        }
        $parts=parse_url($url);
        $server=parse_url($base);
        if (!is_array($parts) || !is_array($server) ||
            strtolower($parts['scheme']??'')!=='https' ||
            strtolower($parts['host']??'')!==strtolower($server['host']??'') ||
            ($parts['port']??443)!==($server['port']??443) ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('O Nextcloud retornou um endereço de outro servidor.');
        }
        $prefix=rtrim($server['path']??'','/');
        $path=(string)($parts['path']??'');
        if (!str_starts_with($path,$prefix.'/')) {
            throw new RuntimeException('Endereço fora da instalação Nextcloud selecionada.');
        }
        $suffix=substr($path,strlen($prefix));
        $pattern=$kind==='poll'
            ? '~^/(?:index\.php/)?login/v2/poll$~D'
            : '~^/(?:index\.php/)?login/v2/flow/[A-Za-z0-9_-]{16,300}$~D';
        if (!preg_match($pattern,$suffix)) {
            throw new RuntimeException('O Nextcloud retornou uma rota de autenticação inesperada.');
        }
        return $url;
    }

    private static function request()
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent'=>'AutomaticKimai/0.2 (Nextcloud Login Flow v2)'])
            ->withOptions(['allow_redirects'=>false,'verify'=>true,'proxy'=>''])
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function pending(): ?array
    {
        $pending=$this->store->read(self::STATE_FILE);
        if ($pending===[])return null;
        $time=$pending['started_at']??0;
        if (!is_int($time) || time()-$time>=self::TTL_SECONDS || $time>time()+10) {
            $this->store->write(self::STATE_FILE,[]);
            return null;
        }
        foreach (['server','poll_endpoint','poll_token','login_url'] as $key) {
            if (!is_string($pending[$key]??null) || $pending[$key]==='')return null;
        }
        return $pending;
    }

    public function status(): array
    {
        $creds=$this->store->credentials();
        $connected=isset($creds['NEXTCLOUD_URL'],$creds['NEXTCLOUD_USERNAME'],$creds['NEXTCLOUD_APP_PASSWORD']);
        $pending=$this->pending();
        return [
            'status'=>$connected?'connected':($pending?'waiting':'not_connected'),
            'connected'=>$connected,
            'server'=>$creds['NEXTCLOUD_URL']??null,
            'username'=>$creds['NEXTCLOUD_USERNAME']??null,
            'waiting'=>$pending!==null,
            'login_url'=>$pending['login_url']??null,
        ];
    }

    public function start(string $url): array
    {
        $server=self::normalizeServer($url);
        $existing=$this->pending();
        if ($existing && $existing['server']===$server) {
            return ['status'=>'waiting','login_url'=>$existing['login_url']];
        }

        $response=self::request()->post($server.'/index.php/login/v2');
        if ($response->status()!==200) {
            throw new RuntimeException('O Nextcloud não iniciou a conexão. Confira o endereço e tente novamente.');
        }
        $data=$response->json();
        if (!is_array($data)||!is_array($data['poll']??null)) {
            throw new RuntimeException('O servidor não suporta o fluxo de conexão esperado.');
        }
        $token=$data['poll']['token']??null;
        if (!is_string($token)||strlen($token)<24||strlen($token)>400 ||
            !preg_match('/^[A-Za-z0-9_-]+$/D',$token)) {
            throw new RuntimeException('O servidor retornou uma sessão de autenticação inválida.');
        }
        $login=self::returnedUrl((string)($data['login']??''),$server,'login');
        $poll=self::returnedUrl((string)($data['poll']['endpoint']??''),$server,'poll');
        $this->store->write(self::STATE_FILE,[
            'server'=>$server,'poll_endpoint'=>$poll,'poll_token'=>$token,
            'login_url'=>$login,'started_at'=>time(),
        ]);
        return ['status'=>'waiting','login_url'=>$login];
    }

    /** Confirm the account UID using the app password, which can differ from the login name. */
    public static function verifyIdentity(string $url,string $login,string $password): string
    {
        $base=self::normalizeServer($url);
        if ($login==='' || strlen($login)>200 || $password==='' || strlen($password)>1024) {
            throw new RuntimeException('A conta do Nextcloud precisa de credenciais válidas.');
        }
        $identity=self::request()
            ->withBasicAuth($login,$password)
            ->withHeaders(['OCS-APIREQUEST'=>'true'])
            ->get($base.'/ocs/v2.php/cloud/user?format=json');
        $json=$identity->json();
        $uid=$json['ocs']['data']['id']??null;
        if ($identity->status()!==200 ||
            !in_array($json['ocs']['meta']['statuscode']??null,[100,200],true) ||
            !is_string($uid) || $uid==='' || strlen($uid)>200 ||
            strpbrk($uid,"/\\\r\n")!==false) {
            throw new RuntimeException('O Nextcloud não confirmou sua identidade.');
        }
        return $uid;
    }

    public function poll(): array
    {
        $state=$this->pending();
        if (!$state)throw new RuntimeException('A conexão expirou ou não foi iniciada. Clique em Conectar novamente.');
        $response=self::request()->asForm()->post($state['poll_endpoint'],['token'=>$state['poll_token']]);
        if ($response->status()===404)return ['status'=>'waiting'];
        if ($response->status()!==200) {
            throw new RuntimeException('O Nextcloud não confirmou a autorização. Tente novamente.');
        }
        $data=$response->json();
        $server=$data['server']??null;
        $login=$data['loginName']??null;
        $password=$data['appPassword']??null;
        if (!is_string($server)||self::normalizeServer($server)!==$state['server'] ||
            !is_string($login)||strlen($login)>200||$login==='' ||
            !is_string($password)||strlen($password)>1024||strlen($password)<8) {
            $this->store->write(self::STATE_FILE,[]);
            throw new RuntimeException('A conta autorizada não corresponde ao servidor selecionado.');
        }
        try {
            $id=self::verifyIdentity($server,$login,$password);
            $this->store->saveCredentials([
                'NEXTCLOUD_URL'=>$server,
                'NEXTCLOUD_USERNAME'=>$id,
                'NEXTCLOUD_LOGIN_NAME'=>$login,
                'NEXTCLOUD_APP_PASSWORD'=>$password,
            ]);
            $this->store->write(self::STATE_FILE,[]);
            return ['status'=>'connected','server'=>$server,'username'=>$id];
        } catch (\Throwable $exception) {
            $this->store->write(self::STATE_FILE,[]);
            throw new RuntimeException('Não foi possível validar a conta. Inicie uma nova conexão no Nextcloud.',0,$exception);
        }
    }
}
