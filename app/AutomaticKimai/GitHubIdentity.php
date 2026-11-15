<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use RuntimeException;

/** Read-only GitHub identity, scoped to the already configured user token. */
final class GitHubIdentity
{
    public function __construct(private readonly HttpTransport $http) {}

    private function user(array $credentials): array
    {
        $token=(string)($credentials['GITHUB_TOKEN']??'');
        if ($token===''||strlen($token)>500)throw new RuntimeException('GitHub not connected');
        $json=$this->http->json('GET','https://api.github.com/user',[
            'Accept'=>'application/vnd.github+json',
            'Authorization'=>'Bearer '.$token,
            'X-GitHub-Api-Version'=>'2022-11-28',
            'User-Agent'=>'automatic-kimai',
        ]);
        $login=$json['login']??null;
        $id=$json['id']??null;
        if (!is_string($login)||!preg_match('/^[A-Za-z0-9-]{1,39}$/D',$login)||
            !is_int($id)||$id<1)throw new RuntimeException('GitHub identity unavailable');
        return $json;
    }

    public function profile(array $credentials): array
    {
        $user=$this->user($credentials);
        $name=$user['name']??null;
        return [
            'login'=>$user['login'],
            'display_name'=>is_string($name)&&trim($name)!==''&&mb_strlen($name)<=120
                ? trim($name) : $user['login'],
        ];
    }

    public function avatar(array $credentials): ?array
    {
        $user=$this->user($credentials);
        $id=$user['id'];
        // Do not trust arbitrary avatar_url or forward the credential to the CDN.
        $data=$this->http->request('GET',
            'https://avatars.githubusercontent.com/u/'.$id.'?s=96&v=4',
            ['Accept'=>'image/png, image/jpeg, image/webp','User-Agent'=>'automatic-kimai']
        );
        if ($data['status']!==200||!is_string($data['body'])||
            strlen($data['body'])>262144)return null;
        $size=@getimagesizefromstring($data['body']);
        if (!is_array($size)||($size[0]??0)>512||($size[1]??0)>512)return null;
        $mime=$size['mime']??null;
        if (!in_array($mime,['image/jpeg','image/png','image/webp'],true))return null;
        return ['mime'=>$mime,'body'=>$data['body']];
    }
}
