<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use RuntimeException;

/** Display only the currently authorized Nextcloud account, never an arbitrary user. */
final class NextcloudIdentity
{
    public function __construct(private readonly HttpTransport $http) {}

    private static function account(array $creds): array
    {
        $server=NextcloudLoginFlow::normalizeServer((string)($creds['NEXTCLOUD_URL']??''));
        $uid=(string)($creds['NEXTCLOUD_USERNAME']??'');
        $login=(string)($creds['NEXTCLOUD_LOGIN_NAME']??$uid);
        $password=(string)($creds['NEXTCLOUD_APP_PASSWORD']??'');
        if ($uid==='' || strlen($uid)>200 || strpbrk($uid,"/\\\r\n")!==false ||
            $login==='' || $password==='') {
            throw new RuntimeException('Conta Nextcloud não configurada');
        }
        return [$server,$uid,[$login,$password]];
    }

    public function profile(array $creds): array
    {
        [$server,$uid,$auth]=self::account($creds);
        $data=$this->http->json('GET',$server.'/ocs/v2.php/cloud/user?format=json',[
            'OCS-APIRequest'=>'true','Accept'=>'application/json',
        ],$auth);
        $ocs=$data['ocs']??[];
        if (!in_array($ocs['meta']['statuscode']??null,[100,200],true) ||
            ($ocs['data']['id']??null)!==$uid) {
            throw new RuntimeException('Nextcloud retornou uma identidade diferente');
        }
        $display=$ocs['data']['displayname']??null;
        return [
            'username'=>$uid,
            'display_name'=>is_string($display)&&$display!==''&&mb_strlen($display)<=200
                ? $display : $uid,
        ];
    }

    /** Raster avatar only; never proxy SVG/HTML or arbitrary remote content to localhost. */
    public function avatar(array $creds): ?array
    {
        [$server,$uid,$auth]=self::account($creds);
        $response=$this->http->request('GET',$server.'/avatar/'.rawurlencode($uid).'/64',[
            'Accept'=>'image/png, image/jpeg, image/webp',
        ],null,$auth);
        if ($response['status']!==200 || !is_string($response['body']) ||
            strlen($response['body'])>512_000) return null;
        $image=@getimagesizefromstring($response['body']);
        $type=$image['mime']??null;
        if (!in_array($type,['image/png','image/jpeg','image/webp'],true))return null;
        if (($image[0]??0)>512 || ($image[1]??0)>512)return null;
        return ['body'=>$response['body'],'type'=>$type];
    }
}
