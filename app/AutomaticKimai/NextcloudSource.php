<?php
declare(strict_types=1);
namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use RuntimeException;
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;

/**
 * Opt-in Nextcloud metadata readers.
 * Calendar attendance and message timestamps cannot certify worked hours.
 */
final class NextcloudSource
{
    public function __construct(private readonly HttpTransport $http) {}

    private static function base(string $url): string
    {
        // Support installations mounted beneath an HTTPS origin.
        return NextcloudLoginFlow::normalizeServer($url);
    }

    private function xml(string $method,string $url,string $payload,array $auth): DOMXPath
    {
        $response=$this->http->request($method,$url,[
            'Depth'=>'1','Content-Type'=>'application/xml; charset=utf-8'
        ],$payload,$auth);
        if ($response['status']!==207)throw new RuntimeException('CalDAV response not successful');
        $body=$response['body'];
        if (!is_string($body)||strlen($body)>5_000_000)throw new RuntimeException('Oversized CalDAV response');
        $doc=new DOMDocument();
        $doc->resolveExternals=false;$doc->substituteEntities=false;
        if (!@$doc->loadXML($body,LIBXML_NONET))throw new RuntimeException('Invalid CalDAV XML');
        $xpath=new DOMXPath($doc);
        $xpath->registerNamespace('d','DAV:');
        $xpath->registerNamespace('c','urn:ietf:params:xml:ns:caldav');
        return $xpath;
    }

    public static function parseEvents(string $ics,Profile $profile,
                                       DateTimeImmutable $from,DateTimeImmutable $until): array
    {
        if (strlen($ics)>2_000_000)throw new RuntimeException('Calendar event too large');
        try {
            $calendar=Reader::read($ics);
            if (!$calendar instanceof VCalendar)throw new RuntimeException('Invalid calendar document');
            // Expansion handles RRULE, EXDATE, exceptions and moved instances.
            $expanded=$calendar->expand($from,$until,$profile->zone);
        } catch (\Throwable) {
            // Unsupported recurrence must not silently become a work signal.
            throw new RuntimeException('Calendar recurrence requires review');
        }
        $result=[];
        foreach ($expanded->VEVENT??[] as $item) {
            if (strtoupper((string)($item->STATUS??''))==='CANCELLED')continue;
            if (!isset($item->DTSTART)||!isset($item->DTEND))continue;
            try {
                $start=$item->DTSTART->getDateTime($profile->zone)->setTimezone($profile->zone);
                $end=$item->DTEND->getDateTime($profile->zone)->setTimezone($profile->zone);
                $start=DateTimeImmutable::createFromInterface($start);
                $end=DateTimeImmutable::createFromInterface($end);
            } catch (\Throwable) {continue;}
            if (TimeMath::ts($start)>=TimeMath::ts($end))continue;
            $summary=(string)($item->SUMMARY??'');
            $private=(bool)preg_match('/pessoal|privad[oa]|dentista|m[eé]dic|fam[ií]lia|treino|academia|consulta|personal|private|dentist|doctor|family|gym|vacation|therapy/iu',$summary);
            $result[]=[
                'date'=>$start->format('Y-m-d'),
                'start_local'=>TimeMath::iso($start),'end_local'=>TimeMath::iso($end),
                'all_day'=>!$item->DTSTART->hasTime(),
                'category'=>$private?'personal_excluded':'possible_meeting_unclassified',
                'scheduled_only'=>true,'rsvp_for_worker'=>'not_verified',
            ];
        }
        if (count($result)>5000)throw new RuntimeException('Too many calendar events');
        return $result;
    }

    public function calendar(Profile $profile,array $credentials,DateTimeImmutable $now): array
    {
        $base=self::base((string)($credentials['NEXTCLOUD_URL']??''));
        $username=(string)($credentials['NEXTCLOUD_USERNAME']??'');
        $password=(string)($credentials['NEXTCLOUD_APP_PASSWORD']??'');
        if ($username===''||$password===''||strpbrk($username,"/\\\r\n")!==false) {
            throw new InvalidArgumentException('Nextcloud account password or username missing');
        }
        $auth=[(string)($credentials['NEXTCLOUD_LOGIN_NAME']??$username),$password];
        $prefix=(string)(parse_url($base,PHP_URL_PATH) ?: '');
        $origin=substr($base,0,strlen($base)-strlen($prefix));
        $root=$prefix.'/remote.php/dav/calendars/'.rawurlencode($username).'/';
        $find='<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:displayname/><c:supported-calendar-component-set/></d:prop></d:propfind>';
        $xpath=$this->xml('PROPFIND',$origin.$root,$find,$auth);
        $paths=[];
        foreach ($xpath->query('//d:response') as $node) {
            if ($xpath->query('.//c:comp[@name="VEVENT"]',$node)->length===0)continue;
            $href=$xpath->evaluate('string(d:href)',$node);
            $path=parse_url($href,PHP_URL_PATH);
            if (!is_string($path)||!str_starts_with($path,$root)||!str_ends_with($path,'/'))continue;
            $paths[]=$path;
        }
        if (count($paths)>30)throw new RuntimeException('Too many calendars');
        $from=$now->setTimezone($profile->zone)->modify('-'.($profile->get('lookback_days')+1).' days')->setTime(0,0);
        $until=$now->setTimezone($profile->zone)->modify('+3 days')->setTime(0,0);
        $fromUTC=$from->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $untilUTC=$until->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        $query='<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:time-range start="'.$fromUTC.'" end="'.$untilUTC.'"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
        $events=[];$seen=[];
        foreach ($paths as $path) {
            $items=$this->xml('REPORT',$origin.$path,$query,$auth);
            foreach ($items->query('//c:calendar-data') as $data) {
                foreach (self::parseEvents($data->textContent,$profile,$from,$until) as $item) {
                    if ($item['date']<$from->format('Y-m-d')||$item['date']>$until->format('Y-m-d'))continue;
                    $key=implode('|',[$item['start_local'],$item['end_local'],$item['category']]);
                    if (isset($seen[$key]))continue;
                    $seen[$key]=true;$events[]=$item;
                }
            }
        }
        return [
            'generated_utc'=>gmdate('c'),'events'=>$events,
            'calendars_scanned'=>count($paths),
            'notes'=>'No summaries or attendee identities saved; recurrence expanded',
        ];
    }

    public function talk(Profile $profile,array $credentials): array
    {
        $base=self::base((string)($credentials['NEXTCLOUD_URL']??''));
        $username=(string)($credentials['NEXTCLOUD_USERNAME']??'');
        $password=(string)($credentials['NEXTCLOUD_APP_PASSWORD']??'');
        if ($username===''||$password==='')throw new InvalidArgumentException('Nextcloud credentials required');
        $auth=[(string)($credentials['NEXTCLOUD_LOGIN_NAME']??$username),$password];
        $query=function(string $endpoint,array $params=[])use($auth,$base) {
            $params=array_merge(['format'=>'json'],$params);
            $url=$base.$endpoint.'?'.http_build_query($params);
            $data=$this->http->json('GET',$url,[
                'OCS-APIRequest'=>'true','Accept'=>'application/json'
            ],$auth);
            if (!in_array($data['ocs']['meta']['statuscode']??null,[100,200],true)) {
                throw new RuntimeException('Nextcloud OCS returned error');
            }
            return $data['ocs']['data']??null;
        };
        $identity=$query('/ocs/v2.php/cloud/user');
        if (!is_array($identity)||($identity['id']??null)!==$username) {
            throw new RuntimeException('Nextcloud account differs from the configured user');
        }
        $rooms=$query('/ocs/v2.php/apps/spreed/api/v4/room',['noStatusUpdate'=>1]);
        if (!is_array($rooms))throw new RuntimeException('Talk rooms unavailable');
        $safe=[];$actions=[];$missing=0;$analyzed=0;
        $total=count($rooms);
        // Match the legacy Talk scanner's 120-room discovery bound.
        // Rooms without recent activity do not require a chat request.
        $truncated=$total>120;
        $cutoff=time()-$profile->get('lookback_days')*86400;
        foreach (array_slice($rooms,0,120) as $room) {
            $token=(string)($room['token']??'');
            if (!preg_match('/^[A-Za-z0-9]{5,100}$/D',$token))continue;
            $safe[]=[
                'room_id_hash'=>substr(hash('sha256',$token),0,24),
                'last_activity_timestamp'=>$room['lastActivity']??null,
                'has_call'=>(bool)($room['hasCall']??false),
                'counts_as_human_work'=>false,
            ];
            if (is_int($room['lastActivity']??null) && $room['lastActivity']<$cutoff)continue;
            try {
                $analyzed++;
                $rows=$query('/ocs/v2.php/apps/spreed/api/v1/chat/'.$token,[
                    'lookIntoFuture'=>0,'limit'=>100,'setReadMarker'=>0,
                    'noStatusUpdate'=>1,'markNotificationsAsRead'=>0
                ]);
                if (!is_array($rows))throw new RuntimeException('Talk chat metadata unavailable');
                foreach ($rows as $message) {
                    $t=$message['timestamp']??null;
                    if (is_int($t)&&$t>=$cutoff&&(string)($message['actorId']??'')===$username) {
                        $actions[]=[
                            'utc'=>gmdate('Y-m-d\TH:i:s\Z',$t),
                            'source'=>'nextcloud_talk','verified_human_duration'=>false,
                        ];
                    }
                }
            } catch(\Throwable){$missing++;}
        }
        return [
            'fetched_at'=>gmdate('c'),'rooms'=>$safe,'own_actions'=>$actions,
            'rooms_total'=>$total,'rooms_scanned'=>count($safe),
            'rooms_analyzed'=>$analyzed,'rooms_truncated'=>$truncated,
            'rooms_unavailable'=>$missing,
            'eligible_for_sync'=>false,
            'privacy'=>'No room titles, message bodies or participant identities persisted',
        ];
    }
}
