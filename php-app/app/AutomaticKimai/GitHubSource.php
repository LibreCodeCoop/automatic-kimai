<?php
declare(strict_types=1);
namespace App\AutomaticKimai;

use RuntimeException;

final class GitHubSource
{
    public function __construct(private readonly HttpTransport $http) {}

    /** Only sanitized metadata from current user's own contribution events. */
    public function collect(string $token): array
    {
        if ($token==='')return ['status'=>'not_configured','events'=>[]];
        $headers=[
            'Accept'=>'application/vnd.github+json',
            'Authorization'=>'Bearer '.$token,
            'X-GitHub-Api-Version'=>'2022-11-28',
            'User-Agent'=>'automatic-kimai',
        ];
        $user=$this->http->json('GET','https://api.github.com/user',$headers);
        $login=$user['login']??null;
        if (!is_string($login)||!preg_match('/^[A-Za-z0-9-]{1,39}$/D',$login)) {
            throw new RuntimeException('GitHub account identity unavailable');
        }
        $raw=$this->http->json('GET','https://api.github.com/users/'.rawurlencode($login).'/events?per_page=100',$headers);
        $events=[];
        foreach ($raw as $row) {
            if (!is_array($row)||!str_ends_with((string)($row['created_at']??''),'Z'))continue;
            $events[]=[
                'timestamp_utc'=>$row['created_at'],
                'kind'=>substr((string)($row['type']??''),0,64),
                'repo_hash'=>substr(hash('sha256',(string)($row['repo']['name']??'')),0,18),
                'event_id'=>substr((string)($row['id']??''),0,50),
                'human_duration_verified'=>false,
            ];
        }
        return [
            'status'=>'ok','user_hash'=>substr(hash('sha256',$login),0,18),
            'fetched_at'=>gmdate('c'),'events'=>$events,
            'eligible_for_sync'=>false,
            'privacy'=>'No titles, messages, commit bodies, repository names or URLs',
        ];
    }
}
