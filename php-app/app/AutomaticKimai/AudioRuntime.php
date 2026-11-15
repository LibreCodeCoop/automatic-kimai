<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/** Private, source-specific evidence that a PHP PipeWire sampler is running. */
final class AudioRuntime
{
    private const STATE_FILE='telegram_audio_php_runtime.json';

    public static function record(LocalStore $store,DateTimeImmutable $at): void
    {
        $stamp=$at->setTimezone(new DateTimeZone('UTC'))->format('c');
        $prior=$store->read(self::STATE_FILE);
        $last=is_string($prior['last_at_utc']??null)?
            strtotime($prior['last_at_utc']):false;
        $now=$at->getTimestamp();
        $continuous=($prior['source']??null)==='php_pipewire' &&
            is_int($last)&&$last<=$now&&$now-$last<=120;
        $count=$continuous ? min(100000,(int)($prior['consecutive_samples']??0)+1):1;
        $first=$continuous && is_string($prior['first_at_utc']??null)?
            $prior['first_at_utc']:$stamp;
        $store->write(self::STATE_FILE,[
            'source'=>'php_pipewire',
            'first_at_utc'=>$first,
            'last_at_utc'=>$stamp,
            'consecutive_samples'=>$count,
            'human_work_attested'=>false,
        ]);
    }

    /** No side effects; never trust imported Python metadata as PHP liveness. */
    public static function healthy(string $root,?DateTimeImmutable $now=null): bool
    {
        $stateFile=rtrim($root,'/').'/'.self::STATE_FILE;
        $dbFile=rtrim($root,'/').'/telegram_audio_metadata.sqlite3';
        if (!is_file($stateFile)||is_link($stateFile)||
            !is_file($dbFile)||is_link($dbFile)||
            (fileperms($stateFile)&0077)!==0||
            (fileperms($dbFile)&0077)!==0)return false;
        try {
            $data=json_decode((string)file_get_contents($stateFile),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($data)||($data['source']??null)!=='php_pipewire'||
                ($data['human_work_attested']??null)!==false||
                !is_int($data['consecutive_samples']??null)||
                $data['consecutive_samples']<3||
                !is_string($data['first_at_utc']??null)||
                !is_string($data['last_at_utc']??null))return false;
            $first=strtotime($data['first_at_utc']);
            $last=strtotime($data['last_at_utc']);
            $current=($now??new DateTimeImmutable())->getTimestamp();
            if ($first===false||$last===false||$last-$first<25||
                $last>$current+10||$last<$current-75)return false;
            $db=new PDO('sqlite:file:'.$dbFile.'?mode=ro',null,null,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            ]);
            $db->exec('PRAGMA query_only=ON');
            $row=$db->query('SELECT timestamp_utc,probe_ok FROM samples
                ORDER BY timestamp_utc DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['probe_ok']!==1)return false;
            $recorded=strtotime((string)$row['timestamp_utc']);
            return $recorded!==false&&abs($recorded-$last)<=8;
        } catch(Throwable) {
            return false;
        }
    }
}
