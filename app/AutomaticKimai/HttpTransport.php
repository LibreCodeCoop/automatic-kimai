<?php
declare(strict_types=1);
namespace App\AutomaticKimai;

use RuntimeException;

/** HTTPS-only bounded transport for opt-in provider metadata. */
final class HttpTransport
{
    private $fake;

    public function __construct(?callable $fake=null) {$this->fake=$fake;}

    public function request(string $method,string $url,array $headers=[],?string $body=null,
                            ?array $basicAuth=null): array
    {
        if (!in_array($method,['GET','PROPFIND','REPORT'],true))throw new RuntimeException('Read-only provider transport');
        $parts=parse_url($url);
        if (!is_array($parts)||($parts['scheme']??null)!=='https'||empty($parts['host'])||
            isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])) {
            throw new RuntimeException('Provider requires HTTPS without embedded credentials');
        }
        if ($this->fake!==null) {
            $response=($this->fake)($method,$url,$headers,$body,$basicAuth);
            if (!is_array($response)||!is_int($response['status']??null))throw new RuntimeException('Invalid test response');
            return $response;
        }
        $curl=curl_init($url);
        if (!$curl)throw new RuntimeException('Provider connection unavailable');
        $opts=[
            CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>18,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>array_map(static fn($k,$v)=>$k.': '.$v,
                array_keys($headers),array_values($headers)),
            CURLOPT_PROXY=>'',
        ];
        if ($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
        if ($basicAuth!==null) {
            if (count($basicAuth)!==2)throw new RuntimeException('Invalid provider auth');
            $opts[CURLOPT_HTTPAUTH]=CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD]=$basicAuth[0].':'.$basicAuth[1];
        }
        curl_setopt_array($curl,$opts);
        $content=curl_exec($curl);
        $code=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($content===false||strlen((string)$content)>8_000_000) {
            throw new RuntimeException('Provider transport failed or response exceeded limit');
        }
        return ['status'=>$code,'body'=>$content];
    }

    public function json(string $method,string $url,array $headers=[],?array $basicAuth=null): array
    {
        $response=$this->request($method,$url,$headers,null,$basicAuth);
        if ($response['status']!==200)throw new RuntimeException('Provider returned non-success HTTP');
        if (is_array($response['body']))return $response['body'];
        try {$data=json_decode($response['body'],true,512,JSON_THROW_ON_ERROR);}
        catch (\Throwable){throw new RuntimeException('Provider returned malformed JSON');}
        if (!is_array($data))throw new RuntimeException('Provider returned invalid JSON type');
        return $data;
    }
}
