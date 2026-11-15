<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Sanitized, opt-in Telegram MTProto metadata. No message body or dialog
 * name is stored; only explicitly approved work chats are scanned.
 */
final class TelegramSource
{
    /** @return array{client_hint:string,product_hint:?string}|null */
    public static function allowedDialog(string $title,array $labels): ?array
    {
        if (mb_strlen($title)>500) $title=mb_substr($title,0,500);
        $matches=[];
        foreach ($labels as $label) {
            if (!is_string($label)||mb_strlen($label)<2||mb_strlen($label)>80)continue;
            // Require a full normalized token match to avoid matching customer
            // "Acme" inside another customer's unrelated name.
            if (preg_match('~(?<![\\p{L}\\p{N}])'.preg_quote($label,'~').'(?![\\p{L}\\p{N}])~iu',$title)) {
                $matches[]=$label;
            }
        }
        if (count($matches)!==1)return null;
        $product=null;
        foreach (['LibreSign','e-Cidade','Nextcloud','SuiteCRM'] as $knownProduct) {
            if (mb_stripos($title,$knownProduct)!==false) {
                $product=$knownProduct;
                break;
            }
        }
        return ['client_hint'=>$matches[0],'product_hint'=>$product];
    }

    /** No message bodies, ids of users or raw links are stored. */
    public static function sanitizeMessage(array $message): array
    {
        $body=is_string($message['message']??null)?mb_substr($message['message'],0,10000):'';
        $kind='message';$callDuration=null;$phase=null;$fingerprint=null;
        $action=$message['action']??null;
        $type=is_array($action)?(string)($action['_']??''):'';
        if ($type==='messageActionPhoneCall') {
            $kind='direct_call';
            $duration=$action['duration']??null;
            if (is_int($duration)&&$duration>0&&$duration<=86400)$callDuration=$duration;
        } elseif ($type==='messageActionGroupCall') {
            $kind='group_call_event';
            $duration=$action['duration']??null;
            $phase=is_int($duration)&&$duration>0&&$duration<=86400?'ended':'started';
            if ($phase==='ended')$callDuration=$duration;
            $rawId=$action['call']['id']??null;
            if (is_int($rawId)||is_string($rawId)) {
                $fingerprint=substr(hash('sha256',(string)$rawId),0,24);
            }
        } elseif ($type==='messageActionGroupCallScheduled') {
            $kind='scheduled_group_call';
        } elseif ($type!=='') {
            $kind='service_event';
        }
        $media=$message['media']??null;
        $mediaType=is_array($media)?($media['_']??''):'';
        $hints=[];
        foreach ([
            'technical_support'=>'~\\b(?:erro|bug|falha|problema|log|exce[cç][aã]o)\\b~iu',
            'development'=>'~\\b(?:issue|pull request|c[oó]digo|deploy|branch|commit|testes?)\\b~iu',
            'meeting'=>'~\\b(?:reuni[aã]o|chamada|videochamada|videoconfer[eê]ncia|call)\\b~iu',
            'customer_service'=>'~\\b(?:suporte|atendimento|cliente|solu[cç][aã]o)\\b~iu',
            'administrative'=>'~\\b(?:contrato|fatura|nota fiscal|pagamento|or[cç]amento)\\b~iu',
        ] as $label=>$pattern) {
            if (preg_match($pattern,$body))$hints[]=$label;
        }
        $products=[];
        foreach ([
            'LibreSign'=>'~\\blibresign\\b~iu',
            'e-Cidade'=>'~\\be[- ]?cidade\\b~iu',
            'Nextcloud'=>'~\\bnextcloud\\b~iu',
            'SuiteCRM'=>'~\\bsuitecrm\\b~iu',
        ] as $label=>$pattern) {
            if (preg_match($pattern,$body))$products[]=$label;
        }
        $date=$message['date']??null;
        if (is_int($date)) {
            $timestamp=(new DateTimeImmutable('@'.$date))->setTimezone(new DateTimeZone('UTC'));
        } elseif (is_string($date)) {
            $timestamp=TimeMath::parse($date)->setTimezone(new DateTimeZone('UTC'));
        } else {
            throw new InvalidArgumentException('Telegram message requires a valid timestamp');
        }
        $result=[
            'timestamp_utc'=>TimeMath::iso($timestamp),
            'message_id'=>is_int($message['id']??null)?$message['id']:null,
            'outgoing'=>($message['out']??null)===true,
            'kind'=>$kind,
            'text_characters'=>mb_strlen($body),
            'text_words'=>count(preg_split('/\\s+/u',trim($body),-1,PREG_SPLIT_NO_EMPTY)?:[]),
            'has_media'=>$media!==null,
            'media_type'=>match($mediaType){
                'messageMediaPhoto'=>'photo','messageMediaDocument'=>'document',
                default=>$media!==null?'other':null,
            },
            'forwarded'=>isset($message['fwd_from']),
            'reply_to_message'=>isset($message['reply_to']),
            'edited'=>isset($message['edit_date']),
            'work_content_hints'=>$hints,
            'product_hints'=>$products,
        ];
        if ($kind==='direct_call') {
            if ($callDuration!==null)$result['call_duration_seconds']=$callDuration;
            $result['video']=($action['video']??false)===true;
        } elseif ($kind==='group_call_event') {
            $result['call_phase']=$phase;
            if ($callDuration!==null)$result['group_call_duration_seconds']=$callDuration;
            if ($fingerprint!==null)$result['group_call_fingerprint']=$fingerprint;
        }
        return $result;
    }

    /**
     * Consumes a library-independent adapter, including a FakeTelegramAdapter in
     * unit tests. Peer names and IDs exist only in process memory/private store.
     */
    public function scan(object $api,array $labels,int $lookbackDays=14,int $limit=600,
                         ?DateTimeImmutable $now=null,array $approvedDialogIds=[]): array
    {
        if ($labels===[] && $approvedDialogIds===[]) {
            throw new InvalidArgumentException('Explicit approved Telegram work groups are required');
        }
        foreach ($approvedDialogIds as $approvedId) {
            if (!is_int($approvedId)||$approvedId===0) {
                throw new InvalidArgumentException('Invalid approved group identifier');
            }
        }
        if ($lookbackDays<1||$lookbackDays>31||$limit<1||$limit>1000) {
            throw new InvalidArgumentException('Invalid bounded Telegram scan options');
        }
        $now=($now??new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $cutoff=$now->modify('-'.$lookbackDays.' days');
        $dialogs=$api->dialogs();
        if (!is_array($dialogs)||count($dialogs)>4000)throw new RuntimeException('Telegram dialog enumeration invalid');
        $result=[];
        foreach ($dialogs as $dialog) {
            if (!is_array($dialog))continue;
            $id=$dialog['id']??null;
            if ($approvedDialogIds!==[]) {
                // Exact peer IDs prevent accidental collection from an unrelated
                // group with a similar or identical title.
                if (!is_int($id) || !in_array($id,$approvedDialogIds,true))continue;
                $title=(string)($dialog['title']??'');
                $hint=self::allowedDialog($title,$labels);
                if ($hint===null) {
                    $fallback=trim(mb_substr($title,0,80));
                    $hint=['client_hint'=>mb_strlen($fallback)>=2?$fallback:'Grupo Telegram',
                           'product_hint'=>null];
                }
            } else {
                // Legacy profiles with manually attested group names retain
                // their old behavior until selection by stable IDs.
                $hint=self::allowedDialog((string)($dialog['title']??''),$labels);
                if ($hint===null)continue;
            }
            $peer=$dialog['peer']??null;
            if (!(is_int($peer)||is_string($peer)||is_array($peer)))continue;
            $rows=$api->history($peer,$limit);
            if (!is_array($rows)||count($rows)>$limit) {
                throw new RuntimeException('Telegram history exceeded allowed message limit');
            }
            $observations=[];
            foreach ($rows as $item) {
                if (!is_array($item)||!isset($item['date']))continue;
                $entry=self::sanitizeMessage($item);
                if (TimeMath::ts(TimeMath::parse($entry['timestamp_utc']))<
                    TimeMath::ts($cutoff))continue;
                $observations[]=$entry;
            }
            $result[]=[
                'dialog_id'=>$dialog['id']??null, // only in the private local file
                'read_inbox_max_id'=>is_int($dialog['read_inbox_max_id']??null)?$dialog['read_inbox_max_id']:null,
                'unread_count'=>is_int($dialog['unread_count']??null)?$dialog['unread_count']:null,
                'client_hint'=>$hint['client_hint'],
                'product_hint'=>$hint['product_hint'],
                'kimai_project_candidates'=>[],
                'observations'=>$observations,
                'limited_to_last_days'=>$lookbackDays,
                'max_messages_per_chat'=>$limit,
                'complete'=>count($rows)<$limit,
                'billable'=>null,'eligible_for_sync'=>false,
            ];
        }
        return [
            'collected_at'=>TimeMath::iso($now),
            'matched_dialog_count'=>count($result),
            'scoped_client_chats'=>$result,
            'no_message_bodies_stored'=>true,
            'api_calls_are_read_only'=>true,
            'no_billing_assignments'=>true,
        ];
    }
}
