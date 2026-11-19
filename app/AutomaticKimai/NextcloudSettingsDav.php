<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use RuntimeException;

/**
 * Personal Nextcloud Files WebDAV folder: one encrypted, versioned document.
 * Only conditional writes are permitted; concurrent device changes never
 * silently overwrite each other. No server-side Nextcloud app is necessary.
 */
final class NextcloudSettingsDav
{
    private const MAX_RESPONSE=250_000;
    private $fake;
    private $checkIdentity;

    public function __construct(?callable $fake=null,?callable $checkIdentity=null)
    {
        $this->fake=$fake;
        $this->checkIdentity=$checkIdentity;
    }

    public static function scope(array $credentials): string
    {
        [$base,$uid]=self::address($credentials);
        return hash('sha256',$base."\n".$uid);
    }

    private static function address(array $creds): array
    {
        $base=NextcloudLoginFlow::normalizeServer((string)($creds['NEXTCLOUD_URL']??''));
        $uid=(string)($creds['NEXTCLOUD_USERNAME']??'');
        $login=(string)($creds['NEXTCLOUD_LOGIN_NAME']??$uid);
        $password=(string)($creds['NEXTCLOUD_APP_PASSWORD']??'');
        if ($uid==='' || strlen($uid)>200 || strpbrk($uid,"/\\\r\n")!==false ||
            $login==='' || str_contains($login,':') || $password==='') {
            throw new RuntimeException('Conecte sua conta Nextcloud neste computador primeiro.');
        }
        return [$base,$uid,[$login,$password]];
    }

    private function endpoints(array $creds): array
    {
        [$base,$uid,$auth]=self::address($creds);
        $dir=$base.'/remote.php/dav/files/'.rawurlencode($uid).'/Automatic%20Kimai';
        return [$dir,$dir.'/settings-v1.encrypted',$auth];
    }

    private function verified(array $creds): void
    {
        if ($this->checkIdentity!==null) {
            $account=($this->checkIdentity)($creds);
        } else {
            $account=(new NextcloudIdentity(new HttpTransport()))->profile($creds);
        }
        if (!is_array($account) ||
            ($account['username']??null)!==($creds['NEXTCLOUD_USERNAME']??null)) {
            throw new RuntimeException('A conta Nextcloud autenticada não corresponde ao backup.');
        }
    }

    private static function etag(mixed $etag): string
    {
        if (!is_string($etag) ||
            !preg_match('/^"[A-Za-z0-9._:-]{1,160}"$/D',$etag)) {
            throw new RuntimeException('O servidor não forneceu um ETag forte e seguro.');
        }
        return $etag;
    }

    private function request(string $method,string $url,array $headers,array $auth,?string $body=null): array
    {
        if (!in_array($method,['GET','PUT','MKCOL'],true) ||
            !str_starts_with($url,'https://') || strlen($url)>800) {
            throw new RuntimeException('Operação WebDAV inválida.');
        }
        if ($this->fake!==null) {
            $response=($this->fake)($method,$url,$headers,$body,$auth);
        } else {
            $curl=curl_init($url);
            if (!$curl)throw new RuntimeException('Conexão WebDAV indisponível.');
            $content='';
            $headerMap=[];
            $opts=[
                CURLOPT_CUSTOMREQUEST=>$method,
                CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_PROXY=>'',CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
                CURLOPT_USERPWD=>$auth[0].':'.$auth[1],
                CURLOPT_HTTPHEADER=>array_merge(['Accept: application/octet-stream'],$headers),
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$content): int {
                    if (strlen($content)+strlen($chunk)>self::MAX_RESPONSE)return 0;
                    $content.=$chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$headerMap): int {
                    if (str_starts_with(strtolower($line),'etag:')) {
                        $headerMap['etag']=trim(substr($line,5));
                    }
                    return strlen($line);
                },
            ];
            if ($method==='PUT' && $body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
            curl_setopt_array($curl,$opts);
            $result=curl_exec($curl);
            $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($result===false || strlen($content)>self::MAX_RESPONSE) {
                throw new RuntimeException('Falha de transferência WebDAV.');
            }
            $response=['status'=>$status,'body'=>$content,'headers'=>$headerMap];
        }
        if (!is_array($response)||!is_int($response['status']??null) ||
            !is_string($response['body']??null) ||
            strlen($response['body'])>self::MAX_RESPONSE) {
            throw new RuntimeException('Resposta WebDAV inválida.');
        }
        return $response;
    }

    /** @return array{etag:string,raw:string}|null */
    public function read(array $credentials): ?array
    {
        $this->verified($credentials);
        [, $file, $auth]=$this->endpoints($credentials);
        $response=$this->request('GET',$file,[],$auth);
        if ($response['status']===404)return null;
        if ($response['status']!==200)throw new RuntimeException('Não foi possível ler o backup do Nextcloud.');
        return [
            'etag'=>self::etag($response['headers']['etag']??null),
            'raw'=>$response['body'],
        ];
    }

    /**
     * Precondition prevents clobbering a backup modified by another device.
     * When the first backup is created, If-None-Match:* prevents races.
     */
    public function write(array $credentials,string $encrypted,?string $expectedEtag): array
    {
        if (strlen($encrypted)>self::MAX_RESPONSE)throw new RuntimeException('Backup muito grande.');
        $this->verified($credentials);
        [$dir,$file,$auth]=$this->endpoints($credentials);
        if ($expectedEtag===null) {
            $folder=$this->request('MKCOL',$dir,[],$auth);
            if (!in_array($folder['status'],[201,405],true)) {
                throw new RuntimeException('Não foi possível preparar a pasta de backup.');
            }
            $condition='If-None-Match: *';
        } else {
            $condition='If-Match: '.self::etag($expectedEtag);
        }
        $response=$this->request('PUT',$file,[
            'Content-Type: application/octet-stream',
            $condition,
        ],$auth,$encrypted);
        if (in_array($response['status'],[409,412,423],true)) {
            throw new SettingsSyncConflict('Outro dispositivo modificou o backup. Revise o conflito.');
        }
        if (!in_array($response['status'],[200,201,204],true)) {
            throw new RuntimeException('O Nextcloud não aceitou a gravação condicionada.');
        }
        $current=$this->read($credentials);
        if ($current===null || !hash_equals($encrypted,$current['raw'])) {
            throw new SettingsSyncConflict('O backup mudou durante a gravação; nenhum estado foi confirmado.');
        }
        return $current;
    }
}
