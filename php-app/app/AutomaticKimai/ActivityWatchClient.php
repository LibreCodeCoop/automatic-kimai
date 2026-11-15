<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use SplPriorityQueue;

/**
 * Reads local ActivityWatch; stores only sanitized context (never raw titles,
 * private URLs, message content, passwords or personal browser history).
 */
final class ActivityWatchClient
{
    private string $baseUrl;
    private $request;

    public function __construct(string $baseUrl='http://127.0.0.1:5600', ?callable $request=null)
    {
        $parts=parse_url(rtrim($baseUrl,'/'));
        if (!is_array($parts) || ($parts['scheme']??'')!=='http' ||
            !in_array($parts['host']??null,['127.0.0.1','localhost','::1'],true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) ||
            isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)) {
            throw new InvalidArgumentException('ActivityWatch must use local HTTP');
        }
        $this->baseUrl=rtrim($baseUrl,'/').'/api/0';
        $this->request=$request;
    }

    private function get(string $resource,array $query=[]): array
    {
        if (!str_starts_with($resource,'/buckets/') || str_contains($resource,'..')) {
            throw new InvalidArgumentException('Invalid ActivityWatch route');
        }
        if ($this->request!==null) {
            $response=($this->request)($resource,$query);
            if (!is_array($response)) throw new RuntimeException('ActivityWatch response malformed');
            return $response;
        }
        $url=$this->baseUrl.$resource.($query!==[]?'?'.http_build_query($query):'');
        $handle=curl_init($url);
        if ($handle===false) throw new RuntimeException('ActivityWatch unavailable');
        curl_setopt_array($handle,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,
            CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
        ]);
        $body=curl_exec($handle);
        $code=curl_getinfo($handle,CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($body===false || $code!==200 || strlen((string)$body)>20_000_000) {
            throw new RuntimeException('ActivityWatch unavailable or response invalid');
        }
        try {$out=json_decode($body,true,512,JSON_THROW_ON_ERROR);}
        catch (\Throwable) {throw new RuntimeException('ActivityWatch malformed JSON');}
        if (!is_array($out)) throw new RuntimeException('ActivityWatch invalid JSON shape');
        return $out;
    }

    public function buckets(): array
    {
        return $this->get('/buckets/');
    }

    public function events(string $bucket,DateTimeImmutable $start,DateTimeImmutable $end): array
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,150}$/D',$bucket)) {
            throw new InvalidArgumentException('Unsafe bucket ID');
        }
        $raw=$this->get('/buckets/'.rawurlencode($bucket).'/events',[
            'start'=>TimeMath::iso($start),'end'=>TimeMath::iso($end),'limit'=>25000
        ]);
        if (!array_is_list($raw) || count($raw)>=25000) {
            throw new RuntimeException('ActivityWatch events malformed or truncated');
        }
        $output=[];
        foreach ($raw as $event) {
            if (!is_array($event)||!isset($event['timestamp'])||
                !is_numeric($event['duration']??null)||!is_array($event['data']??null)) continue;
            try {
                $a=TimeMath::parse((string)$event['timestamp']);
                $seconds=(float)$event['duration'];
                if ($seconds<=0 || $seconds>=7*86400) continue;
                $b=$a->modify('+'.(int)round($seconds*1_000_000).' microseconds');
                if (TimeMath::ts($a)<TimeMath::ts($start))$a=$start;
                if (TimeMath::ts($b)>TimeMath::ts($end))$b=$end;
                if (TimeMath::ts($a)<TimeMath::ts($b))$output[]=[$a,$b,$event['data']];
            } catch (\Throwable) {continue;}
        }
        usort($output,static fn($a,$b)=>TimeMath::ts($a[0])<=>TimeMath::ts($b[0]));
        return $output;
    }

    public static function safeUrl(mixed $input,array $domains=[]): array
    {
        $parts=is_string($input)?parse_url($input):false;
        if ($parts===false || !is_array($parts) ||
            ($parts['scheme']??null)!=='https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass'])) return ['','other'];
        $host=strtolower((string)$parts['host']);
        if (in_array($host,['chatgpt.com','chat.openai.com'],true))return ['https://chatgpt.com','chat'];
        if (in_array($host,['claude.ai','www.claude.ai'],true))return ['https://claude.ai','chat'];
        if (in_array($host,['slides.com','www.slides.com'],true))return ['https://slides.com','work_site'];
        if (in_array($host,$domains,true))return ['https://'.$host,'work_site'];
        if ($host==='github.com') {
            $segments=array_values(array_filter(explode('/',$parts['path']??''),'strlen'));
            if (count($segments)>=2 &&
                preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$segments[0]) &&
                preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$segments[1]) &&
                !in_array($segments[0],['.','..'],true) &&
                !in_array($segments[1],['.','..'],true)) {
                $path=$segments[0].'/'.$segments[1];
                if (count($segments)>=4 && in_array($segments[2],['pull','issues'],true) &&
                    ctype_digit((string)$segments[3])) {
                    $path.='/'.$segments[2].'/'.substr($segments[3],0,8);
                }
                return ['https://github.com/'.$path,'github'];
            }
        }
        return ['','other'];
    }

    private static function bestAt(array &$state,float $moment): ?array
    {
        /** @var SplPriorityQueue $heap */
        $heap=$state['heap'];
        while ($state['index']<count($state['events']) &&
            TimeMath::ts($state['events'][$state['index']][0])<=$moment) {
            $index=$state['index'];
            $span=$state['events'][$index];
            $heap->insert($span,[TimeMath::ts($span[0]),$index]);
            $state['index']++;
        }
        while (!$heap->isEmpty()) {
            $entry=$heap->top();
            if (TimeMath::ts($entry[1])>$moment)return $entry;
            $heap->extract();
        }
        return null;
    }

    public function collect(DateTimeImmutable $start,DateTimeImmutable $end,array $domains=[]): array
    {
        if (TimeMath::ts($start)>=TimeMath::ts($end)) {
            throw new InvalidArgumentException('ActivityWatch range must increase');
        }
        $source=$this->buckets();
        $data=['window'=>[],'afk'=>[],'web'=>[]];$counts=[];$browser=false;
        foreach ($source as $name=>$metadata) {
            if (!is_array($metadata))continue;
            $kind=strtolower((string)($metadata['type']??''));
            if ($kind==='currentwindow')$key='window';
            elseif ($kind==='afkstatus')$key='afk';
            elseif (str_starts_with($kind,'web.tab.')) {$key='web';$browser=true;}
            else continue;
            $events=$this->events((string)$name,$start,$end);
            $data[$key]=array_merge($data[$key],$events);
            $counts[$name]=count($events);
        }
        if (!$data['window'] || !$data['afk']) throw new RuntimeException('AW window/AFK watcher unavailable');
        $times=[];
        foreach ($data as $list) {
            foreach ($list as [$a,$b]) {
                $times[(string)TimeMath::ts($a)]=$a;
                $times[(string)TimeMath::ts($b)]=$b;
            }
        }
        $times=array_values($times);
        usort($times,static fn($a,$b)=>TimeMath::ts($a)<=>TimeMath::ts($b));
        if (count($times)>100000) throw new RuntimeException('AW event boundary limit exceeded');
        $states=[];
        foreach ($data as $key=>$events) {
            usort($events,static fn($a,$b)=>TimeMath::ts($a[0])<=>TimeMath::ts($b[0]));
            $heap=new SplPriorityQueue();
            $heap->setExtractFlags(SplPriorityQueue::EXTR_DATA);
            $states[$key]=['events'=>$events,'index'=>0,'heap'=>$heap];
        }
        $out=[];
        for($i=1;$i<count($times);$i++) {
            $a=$times[$i-1];$b=$times[$i];
            if (TimeMath::seconds($a,$b)<1)continue;
            $mid=(TimeMath::ts($a)+TimeMath::ts($b))/2;
            $window=self::bestAt($states['window'],$mid);
            if ($window===null)continue;
            $afk=self::bestAt($states['afk'],$mid);
            $status=$afk[2]['status']??null;
            $known=in_array($status,['afk','not-afk'],true);
            $inactive=$status==='afk';
            $app=substr((string)($window[2]['app']??'unknown'),0,100);
            $isBrowser=false;
            foreach (['firefox','chrome','chromium','edge','brave','safari','vivaldi','zen'] as $browserName) {
                if (stripos($app,$browserName)!==false){$isBrowser=true;break;}
            }
            $web=$isBrowser?self::bestAt($states['web'],$mid):null;
            $private=$web!==null && !empty($web[2]['incognito']??$web[2]['private']??false);
            [$safe,$kind]=$web!==null?self::safeUrl($web[2]['url']??'',$domains):['','none'];
            if ($inactive||$private){$safe='';$kind='none';}
            $chat=null;
            if ($kind==='chat'&&!$inactive&&$known) {
                $parts=parse_url((string)($web[2]['url']??''));
                $path=is_array($parts)?($parts['path']??''):'';
                $fingerprint=preg_match('#^/(?:c|chat)/([A-Za-z0-9-]{8,150})$#D',$path,$m) ?
                    substr(hash('sha256',$m[1]),0,20):null;
                $chat=[
                    'app_context'=>str_ends_with($safe,'chatgpt.com')?'chatgpt':'claude_browser',
                    'conversation_fingerprint'=>$fingerprint,
                    'topic_hints'=>[],'contains_message_text'=>false
                ];
            }
            $event=[
                'start'=>TimeMath::iso($a),'end'=>TimeMath::iso($b),
                'app'=>$app,'url_kind'=>$kind,'url'=>$safe,'afk'=>$inactive,
                'idle_status_known'=>$known,'private'=>$private,
                'source'=>'activitywatch','chat_context'=>$chat,
                'messenger_hint'=>null,'afk_reading_hint'=>null,'talk_context'=>null,
            ];
            $last=count($out)-1;
            $merge=$last>=0&&$out[$last]['end']===$event['start'];
            if ($merge) {
                foreach ($event as $key=>$value) {
                    if ($key==='start'||$key==='end')continue;
                    if ($out[$last][$key]!==$value){$merge=false;break;}
                }
            }
            if ($merge)$out[$last]['end']=$event['end'];
            else $out[]=$event;
        }
        foreach ($out as &$event) {
            $a=TimeMath::parse($event['start']);$b=TimeMath::parse($event['end']);
            $event['observed_seconds']=TimeMath::seconds($a,$b);
            $event['source_id']=substr(hash('sha256',implode('|',[
                $event['start'],$event['end'],$event['app'],$event['url_kind'],
                $event['afk']?'True':'False',$event['private']?'True':'False',
            ])),0,20);
        }
        unset($event);
        return [$out,[
            'buckets_seen'=>count($source),'active_buckets'=>$counts,
            'window_events'=>count($data['window']),'afk_events'=>count($data['afk']),
            'browser_events'=>count($data['web']),'sanitized_blocks'=>count($out),
            'browser_extension_detected'=>$browser,
        ]];
    }
}
