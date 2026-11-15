<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;

/** Read-marker changes on any device are NOT proof of human reading duration. */
final class TelegramReadProgress
{
    public static function compute(array $before,array $after): array
    {
        $oldById=[];
        foreach ($before['scoped_client_chats']??[] as $row) {
            if (is_array($row)&&is_int($row['dialog_id']??null))$oldById[$row['dialog_id']]=$row;
        }
        try {
            $from=TimeMath::parse($before['collected_at']);
            $until=TimeMath::parse($after['collected_at']);
        } catch (\Throwable) {return [];}
        if (TimeMath::seconds($from,$until)<=0 ||
            TimeMath::seconds($from,$until)>4*3600) return [];
        $result=[];
        foreach ($after['scoped_client_chats']??[] as $current) {
            if (!is_array($current)||!is_int($current['dialog_id']??null))continue;
            $previous=$oldById[$current['dialog_id']]??null;
            if ($previous===null||($previous['client_hint']??null)!==($current['client_hint']??null))continue;
            $a=$previous['read_inbox_max_id']??null;
            $b=$current['read_inbox_max_id']??null;
            if (!is_int($a)||!is_int($b)||$a<0||$b<=$a)continue;
            $new=[];
            foreach ($current['observations']??[] as $item) {
                if (!is_array($item)||($item['kind']??null)!=='message' ||
                    ($item['outgoing']??null)!==false||
                    !is_int($item['message_id']??null))continue;
                if ($item['message_id']>$a&&$item['message_id']<=$b)$new[]=$item;
            }
            $words=0;
            foreach ($new as $row) {
                $words+=max(0,(int)($row['text_words']??0));
            }
            $key=substr(hash('sha256',implode('|',[
                (string)$current['dialog_id'],(string)$a,(string)$b,
                $before['collected_at'],$after['collected_at']
            ])),0,24);
            $result[]=[
                'evidence_id'=>$key,
                'client_hint'=>$current['client_hint'],
                'dialog_fingerprint'=>substr(hash('sha256',(string)$current['dialog_id']),0,24),
                'first_seen_utc'=>TimeMath::iso($from->setTimezone(new DateTimeZone('UTC'))),
                'next_seen_utc'=>TimeMath::iso($until->setTimezone(new DateTimeZone('UTC'))),
                'newly_marked_read_messages_in_cache'=>count($new),
                'newly_marked_read_words_in_cache'=>$words,
                'read_position_advanced'=>true,
                'from_message_id_exclusive'=>$a,
                'to_message_id_inclusive'=>$b,
                'reading_duration_seconds'=>null,
                'device_of_read_event'=>'unknown',
                'read_state_is_not_proof_of_reading'=>true,
                'eligible_for_sync'=>false,
            ];
        }
        return $result;
    }

    public static function retain(array $previous,array $fresh,?DateTimeImmutable $now=null,int $days=31): array
    {
        $now=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $cutoff=$now->modify('-'.$days.' days');
        $items=[];
        foreach ([...($previous['observations']??[]),...$fresh] as $item) {
            if (!is_array($item)||!is_string($item['evidence_id']??null))continue;
            try {
                if (TimeMath::ts(TimeMath::parse($item['next_seen_utc']))>=TimeMath::ts($cutoff)) {
                    $items[$item['evidence_id']]=$item;
                }
            } catch (\Throwable){}
        }
        $ordered=array_values($items);
        usort($ordered,static fn($a,$b)=>strcmp($a['next_seen_utc'],$b['next_seen_utc']));
        return [
            'collected_at'=>TimeMath::iso($now),
            'observations'=>array_slice($ordered,-500),
            'warning'=>'Read marker is a state change, not a measured reading duration.',
            'kimai_writes'=>0,
        ];
    }
}
