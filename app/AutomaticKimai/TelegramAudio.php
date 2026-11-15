<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * PipeWire metadata-only sampling. Never opens microphone streams, records
 * audio or retains raw process names.
 */
final class TelegramAudio
{
    public static function parse(array $graph): array
    {
        $streams=[];
        foreach ($graph as $node) {
            if (!is_array($node)||!str_ends_with((string)($node['type']??''),':Node'))continue;
            $properties=$node['info']['props']??null;
            if (!is_array($properties))continue;
            $labels=[
                strtolower((string)($properties['application.name']??'')),
                strtolower((string)($properties['application.process.binary']??'')),
            ];
            if (!str_contains(implode(' ',$labels),'telegram'))continue;
            $class=(string)($properties['media.class']??'');
            if (!in_array($class,['Stream/Input/Audio','Stream/Output/Audio'],true))continue;
            $state=strtolower((string)($node['info']['state']??''));
            if (!in_array($state,['running','active'],true))continue;
            $streams[]=[$class,strtolower((string)($properties['media.role']??''))];
        }
        $capture=false;$playback=false;$communication=false;
        foreach ($streams as [$type,$role]) {
            if ($type==='Stream/Input/Audio')$capture=true;
            if ($type==='Stream/Output/Audio')$playback=true;
            if (in_array($role,['communication','phone','voip'],true))$communication=true;
        }
        return [
            'telegram_capture'=>$capture,
            'telegram_playback'=>$playback,
            'telegram_communication'=>$communication,
            'possible_call'=>$capture||$communication,
            'playback_only'=>$playback&&!($capture||$communication),
        ];
    }

    public static function probe(?callable $run=null): array
    {
        if ($run!==null)$output=$run();
        else {
            $process=proc_open(['pw-dump'],[
                0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
            ],$pipes,null,null,['bypass_shell'=>true]);
            if (!is_resource($process))throw new RuntimeException('PipeWire tool unavailable');
            fclose($pipes[0]);
            $output=stream_get_contents($pipes[1],16*1024*1024+1);
            fclose($pipes[1]);
            // Do not keep process logs; they can include private app names.
            stream_get_contents($pipes[2],1000);
            fclose($pipes[2]);
            $exit=proc_close($process);
            if ($exit!==0)throw new RuntimeException('PipeWire not available');
        }
        if (!is_string($output)||strlen($output)>16*1024*1024) {
            throw new RuntimeException('PipeWire metadata response oversized');
        }
        try {$data=json_decode($output,true,512,JSON_THROW_ON_ERROR);}
        catch (\Throwable){throw new RuntimeException('PipeWire malformed metadata');}
        if (!is_array($data)||!array_is_list($data))throw new RuntimeException('PipeWire graph invalid');
        return self::parse($data);
    }

    public static function open(string $file): PDO
    {
        $folder=dirname($file);
        if (is_link($folder)||is_link($file))throw new RuntimeException('Symlinked audio database forbidden');
        if (!is_dir($folder)&&!mkdir($folder,0700,true)&&!is_dir($folder)) {
            throw new RuntimeException('Unable to create private audio directory');
        }
        if (!file_exists($file)) {
            $h=fopen($file,'x');
            if (!$h)throw new RuntimeException('Unable to create private audio database');
            fclose($h);chmod($file,0600);
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($file)&0077)!==0) {
            throw new RuntimeException('Audio SQLite must be owner-only');
        }
        $pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout=5000;PRAGMA journal_mode=DELETE');
        $pdo->exec('CREATE TABLE IF NOT EXISTS samples(
            timestamp_utc TEXT PRIMARY KEY, telegram_capture INTEGER NOT NULL,
            telegram_playback INTEGER NOT NULL, telegram_communication INTEGER NOT NULL,
            probe_ok INTEGER NOT NULL)');
        return $pdo;
    }

    public static function record(PDO $pdo,?array $observation,?DateTimeImmutable $at=null): void
    {
        $at=($at??new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $time=$at->format('Y-m-d\TH:i:s.uP');
        $stmt=$pdo->prepare('INSERT OR IGNORE INTO samples VALUES (?,?,?,?,?)');
        $stmt->execute([
            $time,
            (int)($observation['telegram_capture']??false),
            (int)($observation['telegram_playback']??false),
            (int)($observation['telegram_communication']??false),
            (int)($observation!==null),
        ]);
    }

    public static function candidates(array $records,int $interval=15): array
    {
        if ($interval<10||$interval>120)throw new RuntimeException('Invalid audio polling interval');
        $groups=[];$active=[];
        foreach ($records as $entry) {
            if (!is_array($entry)||empty($entry['timestamp_utc']))continue;
            $time=TimeMath::parse($entry['timestamp_utc']);
            $good=($entry['probe_ok']??false)&&
                (($entry['telegram_capture']??false)||($entry['telegram_communication']??false));
            if (!$good) {
                if ($active!==[])$groups[]=$active;
                $active=[];continue;
            }
            $last=$active[count($active)-1]??null;
            if ($last!==null && TimeMath::seconds($last[0],$time)>$interval*2.5) {
                $groups[]=$active;$active=[];
            }
            $active[]=[$time,$entry];
        }
        if ($active!==[])$groups[]=$active;
        $out=[];
        foreach ($groups as $group) {
            $start=$group[0][0];
            $end=$group[count($group)-1][0];
            $seconds=TimeMath::seconds($start,$end);
            if (count($group)<3||$seconds<30)continue;
            $out[]=[
                'first_observed_utc'=>TimeMath::iso($start),
                'last_observed_utc'=>TimeMath::iso($end),
                'bounded_observed_seconds'=>$seconds,
                'samples'=>count($group),
                'client_id'=>null,'project_id'=>null,'billable'=>null,
                'eligible_for_sync'=>false,'status'=>'possible_telegram_call',
                'warning'=>'Stream activity is a call clue, not proof of a work call',
            ];
        }
        return $out;
    }
}
