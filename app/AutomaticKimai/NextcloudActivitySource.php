<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use RuntimeException;

/**
 * Activity's OCS feed, restricted to actions by the linked user.
 * No subjects, filenames, links or recipients are persisted.
 */
final class NextcloudActivitySource
{
    public function __construct(private readonly HttpTransport $http) {}

    public function collect(Profile $profile,array $creds,DateTimeImmutable $now): array
    {
        $base=NextcloudLoginFlow::normalizeServer((string)($creds['NEXTCLOUD_URL']??''));
        $uid=(string)($creds['NEXTCLOUD_USERNAME']??'');
        $login=(string)($creds['NEXTCLOUD_LOGIN_NAME']??$uid);
        $password=(string)($creds['NEXTCLOUD_APP_PASSWORD']??'');
        if ($uid===''||$login===''||$password==='')throw new RuntimeException('Nextcloud authentication missing');

        $cutoff=$now->getTimestamp()-$profile->get('lookback_days')*86400;
        $events=[];$seen=[];$cursor=null;$truncated=false;
        // Explicit bounds: the activity stream is not an unbounded history export.
        for ($page=0;$page<5;$page++) {
            $params=['format'=>'json','sort'=>'desc','limit'=>100,'actor'=>$uid];
            if ($cursor!==null)$params['since']=$cursor;
            $url=$base.'/ocs/v2.php/apps/activity/api/v2/activity?'.
                http_build_query($params,'','&',PHP_QUERY_RFC3986);
            $response=$this->http->request('GET',$url,[
                'OCS-APIRequest'=>'true','Accept'=>'application/json',
            ],null,[$login,$password]);
            if ($response['status']===204 || $response['status']===304)break;
            if ($response['status']!==200)throw new RuntimeException('Nextcloud Activity unavailable');
            $json=json_decode((string)$response['body'],true);
            if (!is_array($json) ||
                !in_array($json['ocs']['meta']['statuscode']??null,[100,200],true) ||
                !is_array($json['ocs']['data']??null)) {
                throw new RuntimeException('Unexpected Nextcloud Activity format');
            }
            $rows=$json['ocs']['data'];
            if (!array_is_list($rows) || count($rows)>100) {
                throw new RuntimeException('Unexpected Nextcloud Activity page');
            }
            $old=false;$next=null;
            foreach ($rows as $row) {
                if (!is_array($row))continue;
                $id=$row['activity_id']??null;
                $date=$row['datetime']??null;
                if (!is_int($id)||$id<=0 || !is_string($date))continue;
                $when=DateTimeImmutable::createFromFormat(DATE_ATOM,$date);
                if ($when===false)continue;
                $at=$when->getTimestamp();
                if (isset($seen[$id]))continue;
                $seen[$id]=true;
                $next=$id;
                if ($at<$cutoff) {$old=true;continue;}
                if ($at>$now->getTimestamp()+300 || ($row['user']??null)!==$uid)continue;
                $app=$row['app']??'';
                $type=$row['type']??'';
                // Whitelist metadata shapes, not the arbitrary translated subject.
                $events[]=[
                    'utc'=>gmdate('Y-m-d\TH:i:s\Z',$at),
                    'source'=>'nextcloud_activity',
                    'app'=>is_string($app)&&preg_match('/^[a-z0-9_]{1,80}$/D',$app)?$app:'other',
                    'kind'=>is_string($type)&&preg_match('/^[a-z0-9_]{1,80}$/D',$type)?$type:'other',
                    'verified_human_duration'=>false,
                ];
            }
            if (count($rows)<100 || $old || $next===null || $next===$cursor)break;
            $cursor=$next;
            if ($page===4)$truncated=true;
        }
        return [
            'fetched_at'=>gmdate('c'),
            'own_actions'=>$events,
            'truncated'=>$truncated,
            'eligible_for_auto_publication'=>false,
            'privacy'=>'Only own timestamps and event categories; no subjects, filenames or paths',
        ];
    }
}
