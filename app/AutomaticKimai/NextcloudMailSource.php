<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use RuntimeException;

/**
 * Opt-in, read-only Nextcloud Mail sent-folder timestamps.
 * Mail's internal JSON routes vary by version: failures are explicitly
 * reported as unavailable, never silently converted into worked time.
 */
final class NextcloudMailSource
{
    public function __construct(private readonly HttpTransport $http) {}

    public function collect(Profile $profile,array $creds,DateTimeImmutable $now): array
    {
        $base=NextcloudLoginFlow::normalizeServer((string)($creds['NEXTCLOUD_URL']??''));
        $uid=(string)($creds['NEXTCLOUD_USERNAME']??'');
        $login=(string)($creds['NEXTCLOUD_LOGIN_NAME']??$uid);
        $password=(string)($creds['NEXTCLOUD_APP_PASSWORD']??'');
        if ($uid===''||$login===''||$password==='')throw new RuntimeException('Nextcloud authentication missing');
        $auth=[$login,$password];
        // Nextcloud rejects Mail's internal JSON GET routes with HTTP 412
        // unless OCS-APIRequest is present for app-password authentication.
        // Never substitute a browser session cookie or CSRF token.
        $headers=['Accept'=>'application/json','OCS-APIRequest'=>'true'];
        // These Mail app routes are internal, unlike Nextcloud's documented OCS Activity API.
        // Always use GET and omit message bodies, subjects and correspondents from storage.
        $accounts=$this->http->json('GET',$base.'/index.php/apps/mail/api/accounts',$headers,$auth);
        if (!array_is_list($accounts)||count($accounts)>40) {
            throw new RuntimeException('Unexpected Nextcloud Mail accounts response');
        }
        $cutoff=$now->getTimestamp()-$profile->get('lookback_days')*86400;
        $actions=[];$scanned=0;$partial=false;$seen=[];
        // Bound both the number of mailboxes and the pages requested. An
        // exactly-full page is NOT evidence of truncation: confirm by cursor.
        foreach (array_slice($accounts,0,5) as $account) {
            if (!is_array($account) || ($account['isDelegated']??null)!==false)continue;
            $sent=$account['sentMailboxId']??null;
            if (!is_int($sent)||$sent<=0)continue;
            $scanned++;
            $cursor=null;$complete=false;
            for ($page=0;$page<4;$page++) {
                $params=['mailboxId'=>$sent,'limit'=>100,'view'=>'singleton'];
                if ($cursor!==null)$params['cursor']=$cursor;
                $url=$base.'/index.php/apps/mail/api/messages?'.
                    http_build_query($params,'','&',PHP_QUERY_RFC3986);
                try {
                    $messages=$this->http->json('GET',$url,$headers,$auth);
                    if (!array_is_list($messages)||count($messages)>100) {
                        throw new RuntimeException('Unexpected Nextcloud Mail messages response');
                    }
                } catch (\Throwable) {
                    $partial=true;
                    break;
                }
                if ($messages===[]) {$complete=true;break;}
                foreach ($messages as $message) {
                    if (!is_array($message))continue;
                    $at=$message['dateInt']??null;
                    $id=$message['databaseId']??null;
                    if (!is_int($at)||!is_int($id)||$id<=0 ||
                        $at<$cutoff||$at>$now->getTimestamp()+300 ||
                        ($message['flags']['draft']??false)===true ||
                        ($message['flags']['deleted']??false)===true ||
                        isset($seen[$id]))continue;
                    $seen[$id]=true;
                    $actions[]=[
                        'utc'=>gmdate('Y-m-d\TH:i:s\Z',$at),
                        'source'=>'nextcloud_mail',
                        'kind'=>'sent_message',
                        'verified_human_duration'=>false,
                    ];
                }
                if (count($messages)<100) {$complete=true;break;}
                $last=end($messages);
                $next=$last['databaseId']??null;
                if (!is_int($next)||$next<=0||$next===$cursor)break;
                $cursor=$next;
            }
            if (!$complete)$partial=true;
        }
        if (count($accounts)>5)$partial=true;
        return [
            'fetched_at'=>gmdate('c'),
            'sent_actions'=>$actions,
            'accounts_scanned'=>$scanned,
            'partial'=>$partial,
            'eligible_for_auto_publication'=>false,
            'privacy'=>'Sent-folder timestamps only; no email subjects, bodies or recipients persisted',
        ];
    }
}
