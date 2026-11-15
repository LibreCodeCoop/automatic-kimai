<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/** Bounded Kimai API transport. Fail closed, never follow redirects or log tokens. */
final class KimaiClient
{
    public readonly string $baseUrl;
    private string $token;
    private $transport;

    public function __construct(string $baseUrl, string $token, ?callable $transport=null)
    {
        $trimmed=rtrim(trim($baseUrl),'/');
        $url=parse_url($trimmed);
        if (!is_array($url)||empty($url['host'])||isset($url['user'])||isset($url['pass'])||
            isset($url['query'])||isset($url['fragment'])||preg_match('/\s/',$trimmed)) {
            throw new InvalidArgumentException('Invalid Kimai URL');
        }
        $localhost=in_array(strtolower($url['host']),['localhost','127.0.0.1','::1'],true);
        if (($url['scheme']??'')!=='https' && !(($url['scheme']??'')==='http'&&$localhost)) {
            throw new InvalidArgumentException('Kimai requires HTTPS except localhost');
        }
        if (str_ends_with($url['path']??'','/api')) {
            throw new InvalidArgumentException('Kimai base URL must not include /api');
        }
        if (!is_string($token)||$token==='') {
            throw new InvalidArgumentException('Individual Kimai API token is required');
        }
        $this->baseUrl=$trimmed;
        $this->token=$token;
        $this->transport=$transport;
    }

    /**
     * @return array{data:array,headers:array}
     */
    public function request(string $method,string $path,array $params=[],?array $payload=null): array
    {
        if (!in_array($method,['GET','POST','PATCH'],true) ||
            !preg_match('#^/(?:ping|version|users/me|customers|projects|activities|timesheets(?:/[1-9][0-9]*)?)$#D',$path)) {
            throw new InvalidArgumentException('Unsupported Kimai endpoint');
        }
        if ($method!=='GET' && !preg_match('#^/timesheets(?:/[1-9][0-9]*)?$#D',$path)) {
            throw new InvalidArgumentException('Only timesheet writes supported');
        }
        if ($method==='GET' && $path==='/timesheets') {
            foreach (['user','begin','end'] as $key) {
                if (!array_key_exists($key,$params)) throw new InvalidArgumentException('Bounded user timesheet query required');
            }
            if (!is_int($params['user']) || $params['user']<1 ||
                !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/D',(string)$params['begin']) ||
                !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/D',(string)$params['end'])) {
                throw new InvalidArgumentException('Invalid bounded timesheet query');
            }
        }
        if ($method!=='GET' && $payload===null) throw new InvalidArgumentException('Mutation payload required');
        $url=$this->baseUrl.'/api'.$path;
        if ($params!==[])$url.='?'.http_build_query($params);
        if ($this->transport!==null) {
            $result=($this->transport)($method,$path,$params,$payload);
            if (!is_array($result)||!isset($result['status'])) {
                throw new RuntimeException('Invalid Kimai transport response');
            }
            $status=(int)$result['status'];
            $resultData=$result['body']??[];
            $headers=$result['headers']??[];
        } else {
            $handle=curl_init($url);
            if ($handle===false)throw new RuntimeException('Kimai connection unavailable');
            $received=[];
            $options=[
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>18,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROXY=>'',
                CURLOPT_HTTPHEADER=>[
                    'Authorization: Bearer '.$this->token,
                    'Accept: application/json','Content-Type: application/json',
                    'User-Agent: automatic-kimai/php-alpha',
                ],
                CURLOPT_HEADERFUNCTION=>static function($curl,$line) use (&$received) {
                    $pos=strpos($line,':');
                    if ($pos!==false)$received[strtolower(trim(substr($line,0,$pos)))]=trim(substr($line,$pos+1));
                    return strlen($line);
                },
            ];
            if ($method!=='GET') {
                $options[CURLOPT_CUSTOMREQUEST]=$method;
                $options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR);
            }
            curl_setopt_array($handle,$options);
            $body=curl_exec($handle);
            $status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($body===false) throw new RuntimeException('Kimai connection failed; remote outcome may be unknown');
            if (strlen((string)$body)>8_000_000)throw new RuntimeException('Kimai response too large');
            if ($status===204){$resultData=[];}
            else {
                try {$resultData=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);}
                catch (\Throwable){throw new RuntimeException('Kimai returned invalid JSON');}
            }
            $headers=$received;
        }
        if ($status<200||$status>=300) {
            // Never include response body: may contain secrets or user data.
            throw new RuntimeException('Kimai API non-success HTTP '.$status);
        }
        if (!is_array($resultData))throw new RuntimeException('Kimai response is not JSON object/array');
        return ['data'=>$resultData,'headers'=>$headers];
    }

    public function me(): array
    {
        return $this->request('GET','/users/me')['data'];
    }

    public function listEntities(string $path): array
    {
        if (!in_array($path,['/customers','/projects','/activities'],true)) {
            throw new InvalidArgumentException('Invalid catalog endpoint');
        }
        $items=[];
        for($page=1;$page<=200;$page++) {
            $response=$this->request('GET',$path,['page'=>$page,'size'=>500]);
            $data=$response['data'];
            $rows=array_is_list($data)?$data:($data['data']??null);
            if (!is_array($rows)) throw new RuntimeException('Invalid catalog response');
            $items=array_merge($items,$rows);
            $h=array_change_key_case($response['headers'],CASE_LOWER);
            $total=isset($h['x-total-pages'])?(int)$h['x-total-pages']:null;
            if ($total===null) {
                if (count($rows)>=500)throw new RuntimeException('Catalog may be truncated');
                break;
            }
            if ($page >= $total)break;
            if ($rows===[])throw new RuntimeException('Catalog missing a page');
            if ($page===200)throw new RuntimeException('Catalog pagination limit');
        }
        $ids=array_map(static fn($r)=>$r['id']??null,$items);
        if (count(array_unique($ids,SORT_REGULAR))!==count($ids)) {
            throw new RuntimeException('Duplicated catalog IDs');
        }
        return $items;
    }

    public function catalog(): array
    {
        $customers=$this->listEntities('/customers');
        $projects=$this->listEntities('/projects');
        $activities=$this->listEntities('/activities');
        $id=static fn($x)=>is_array($x)?($x['id']??null):$x;
        return [
            'customers'=>array_map(static fn($r)=>[
                'id'=>$r['id'],'name'=>$r['name']??'', 'visible'=>$r['visible']??null
            ],$customers),
            'projects'=>array_map(static fn($r)=>[
                'id'=>$r['id'],'name'=>$r['name']??'',
                'customer_id'=>$id($r['customer']??null), 'visible'=>$r['visible']??null
            ],$projects),
            'activities'=>array_map(static fn($r)=>[
                'id'=>$r['id'],'name'=>$r['name']??'',
                'project_id'=>$id($r['project']??null), 'visible'=>$r['visible']??null
            ],$activities),
        ];
    }

    public function validateCatalog(Profile $profile): void
    {
        $cat=$this->catalog();
        $project=null;$activity=null;
        foreach ($cat['projects'] as $p)if ($p['id']===$profile->get('project_id'))$project=$p;
        foreach ($cat['activities'] as $a)if ($a['id']===$profile->get('activity_id'))$activity=$a;
        if ($project===null||$project['customer_id']!==$profile->get('customer_id') ||
            $project['visible']===false) {
            throw new RuntimeException('Configured Kimai project not available');
        }
        if ($activity===null||!in_array($activity['project_id'],[null,$profile->get('project_id')],true) ||
            $activity['visible']===false) throw new RuntimeException('Configured Kimai activity not available');
    }

    public function timesheets(int $user,array $days,DateTimeZone $zone): array
    {
        if ($user<1||$days===[])return [];
        sort($days);
        $start=TimeMath::dayStart($days[0],$zone)->format('Y-m-d\TH:i:s');
        $end=TimeMath::dayStart(end($days),$zone)->setTime(23,59,59)->format('Y-m-d\TH:i:s');
        $items=[];
        for($page=1;$page<=20;$page++) {
            $response=$this->request('GET','/timesheets',[
                'user'=>$user,'begin'=>$start,'end'=>$end,'page'=>$page,'size'=>500
            ]);
            $rows=$response['data'];
            $rows=array_is_list($rows)?$rows:($rows['data']??null);
            if (!is_array($rows))throw new RuntimeException('Timesheet response malformed');
            foreach ($rows as $entry) {
                $owner=$entry['user']??null;
                $owner=is_array($owner)?($owner['id']??null):$owner;
                if ($owner!==null && $owner!==$user) {
                    throw new RuntimeException('Kimai returned another user timesheet; refusing cross-account data');
                }
            }
            $items=array_merge($items,$rows);
            $headers=array_change_key_case($response['headers'],CASE_LOWER);
            $total=isset($headers['x-total-pages'])?(int)$headers['x-total-pages']:null;
            if ($total===null) {
                if (count($rows)>=500)throw new RuntimeException('Timesheet pagination may be truncated');
                break;
            }
            if ($page>=$total)break;
            if ($rows===[])throw new RuntimeException('Missing timesheet page');
            if ($page===20)throw new RuntimeException('Timesheet pagination limit');
        }
        return $items;
    }

    public function createTimesheet(array $payload): int
    {
        $response=$this->request('POST','/timesheets',[],$payload);
        $id=$response['data']['id']??null;
        if (!is_int($id)||$id<1)throw new RuntimeException('Kimai write returned invalid ID');
        return $id;
    }

    public function extendTimesheet(int $id,string $end): void
    {
        if ($id<1||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/D',$end)) {
            throw new InvalidArgumentException('Invalid timesheet extension');
        }
        $this->request('PATCH','/timesheets/'.$id,[],['end'=>$end]);
    }
}
