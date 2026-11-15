<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use danog\MadelineProto\API;
use danog\DialogId\DialogId;
use danog\MadelineProto\Settings;
use RuntimeException;

/**
 * Read-only MadelineProto 8.21 adapter for an existing user session.
 * Never starts an interactive login in a background timer.
 */
final class MadelineTelegramAdapter
{
    private API $api;

    public static function settings(int $apiId,string $apiHash,string $logFile): Settings
    {
        if ($apiId<1||!preg_match('/^[a-f0-9]{32}$/iD',$apiHash)) {
            throw new RuntimeException('Telegram API ID/hash are invalid');
        }
        $settings=new Settings();
        // MadelineProto's own file logger does not inherit Laravel's private
        // storage conventions. Create it under restrictive umask before use.
        if (is_link($logFile))throw new RuntimeException('Symlinked Telegram log forbidden');
        $old=umask(0077);
        try {
            if (!file_exists($logFile))touch($logFile);
            chmod($logFile,0600);
        } finally {umask($old);}
        $settings->getAppInfo()->setApiId($apiId)->setApiHash($apiHash);
        $settings->getLogger()->setType(\danog\MadelineProto\Logger::FILE_LOGGER)
            ->setExtra($logFile)->setLevel(\danog\MadelineProto\Logger::ERROR);
        return $settings;
    }

    public function __construct(string $sessionPath,int $apiId,string $apiHash)
    {
        if (!is_dir($sessionPath) && !file_exists($sessionPath)) {
            throw new RuntimeException('MadelineProto session not imported; run the controlled migration first');
        }
        if (is_link($sessionPath))throw new RuntimeException('Symlinked Telegram session forbidden');
        $this->api=new API($sessionPath,self::settings($apiId,$apiHash,dirname($sessionPath).'/telegram-user.log'));
        $me=$this->api->getSelf();
        if (!is_array($me)||($me['bot']??false)) {
            throw new RuntimeException('Authorized individual Telegram user session required');
        }
    }

    public function selfId(): int
    {
        $me=$this->api->getSelf();
        if (!is_array($me)||!is_int($me['id']??null)) {
            throw new RuntimeException('Telegram identity unknown');
        }
        return $me['id'];
    }

    public function dialogs(): array
    {
        $raw=$this->api->getFullDialogs();
        if (count($raw)>4000)throw new RuntimeException('Telegram dialog limit exceeded');
        $result=[];
        foreach ($raw as $id=>$item) {
            if (!(is_int($id) || (is_string($id) && preg_match('/^-?[0-9]+$/D',$id))))continue;
            if (!is_array($item))continue;
            try {
                $info=$this->api->getInfo((int)$id);
            } catch (\Throwable) {continue;}
            $chat=$info['Chat']??$info['User']??$info;
            $group=self::groupInfoIsEligible($info);
            $title=(string)($chat['title']??trim((string)($chat['first_name']??'').' '.(string)($chat['last_name']??'')));
            if ($title==='')continue;
            $result[]=[
                'id'=>(int)$id,'peer'=>(int)$id,'title'=>$title,
                'is_group'=>$group,
                'read_inbox_max_id'=>$item['read_inbox_max_id']??null,
                'unread_count'=>$item['unread_count']??null,
            ];
        }
        return $result;
    }

    /** Only groups/supergroups are selectable; exclude direct chats, bots and broadcast channels. */
    public static function groupInfoIsEligible(array $info): bool
    {
        $chat=$info['Chat']??null;
        if (is_array($chat) &&
            (($chat['left']??false)||($chat['kicked']??false)||
             ($chat['deactivated']??false)))return false;
        $type=$info['type']??null;
        if (in_array($type,['chat','supergroup'],true))return true;
        if (in_array($type,['user','bot','channel'],true))return false;

        if (!is_array($chat))return false;
        $kind=$chat['_']??null;
        if ($kind==='chat')return true;
        return $kind==='channel' && ($chat['megagroup']??false)===true;
    }

    /**
     * Bounded Telegram dialogs page; never calls getFullDialogs() or getHistory().
     * The Telegram RPC returns at most PAGE_SIZE raw dialogs, including private
     * conversations, which are discarded before returning the response.
     */
    public const PAGE_SIZE=30;
    // Telegram includes pinned chats *in addition to* the requested limit
    // on the first page. Never reject valid responses because of this.
    public const MAX_PINNED=100;

    public function groupsPage(?array $offset=null,int $folder=0): array
    {
        if (!in_array($folder,[0,1],true)) {
            throw new RuntimeException('Invalid Telegram archive folder');
        }
        $raw=$this->api->messages->getDialogs(
            exclude_pinned:$offset!==null,
            folder_id:$folder,
            offset_date:(int)($offset['date']??0),
            offset_id:(int)($offset['id']??0),
            offset_peer:$offset['peer']??['_'=>'inputPeerEmpty'],
            limit:self::PAGE_SIZE,
            hash:0,
        );
        return self::filterDialogPage(
            $raw,
            fn(array $peer): int=>$this->api->getId($peer),
            self::PAGE_SIZE,$folder,
        );
    }

    /**
     * This parser deliberately relies only on chat metadata already present
     * in messages.getDialogs: no N individual getInfo calls per page.
     *
     * @param callable(array):int $peerId
     */
    public static function filterDialogPage(array $response,callable $peerId,
                                             int $limit=self::PAGE_SIZE,
                                             int $folder=0): array
    {
        $dialogs=$response['dialogs']??null;
        $chats=$response['chats']??null;
        if (!is_array($dialogs)||!is_array($chats)||
            count($dialogs)>$limit+self::MAX_PINNED) {
            throw new RuntimeException('Telegram returned an invalid dialogs page');
        }
        $pinned=0;$ordinary=0;
        foreach ($dialogs as $dialog) {
            if (!is_array($dialog))continue;
            if (($dialog['pinned']??false)===true)$pinned++;
            else $ordinary++;
        }
        if ($pinned>self::MAX_PINNED||$ordinary>$limit) {
            throw new RuntimeException('Telegram dialogs page exceeded bounds');
        }
        // MadelineProto normalizes peerChat/peerChannel constructors to
        // integer Bot API dialog IDs, including inside message.peer_id.
        // Resolve each chat's ID using the same library's DialogId mapping.
        $metadata=[];
        foreach ($chats as $chat) {
            if (!is_array($chat) || !is_int($chat['id']??null))continue;
            try {
                $id=$chat['id']<0 ? $chat['id'] : match($chat['_']??null) {
                    // The MadelineProto RPC wrapper often returns chat IDs
                    // already normalized to negative Bot API dialog IDs.
                    // Convert only raw positive MTProto IDs.
                    'chat' => DialogId::fromChatId($chat['id']),
                    'channel','channelForbidden' =>
                        DialogId::fromSupergroupOrChannelId($chat['id']),
                    default => null,
                };
                if ($id!==null)$metadata[$id]=$chat;
            } catch (\Throwable) {continue;}
        }

        $groups=[];
        foreach ($dialogs as $dialog) {
            if (!is_array($dialog))continue;
            $peer=$dialog['peer']??null;
            if (!(is_int($peer)||is_array($peer)))continue;
            $id=is_int($peer)?$peer:$peerId($peer);
            if (!is_int($id)||$id===0||!isset($metadata[$id]))continue;
            $chat=$metadata[$id];
            if (!self::isUsableGroupChat($chat))continue;
            $title=trim(mb_substr((string)($chat['title']??''),0,150));
            if ($title==='')continue;
            $username=self::publicUsername($chat);
            $groups[$id]=[
                'id'=>$id,
                'title'=>$title,
                'recommended'=>in_array(strtolower($username??''),['librecodecoop','libresign'],true),
                'archived'=>$folder===1,
            ];
        }
        // Pinned dialogs must NOT provide the offset: the next page uses
        // exclude_pinned=true, so continuation tracks the last ordinary one.
        $next=null;
        // Telegram inconsistently counts pinned dialogs toward the requested
        // page limit, depending on folder/response. A dialogsSlice response
        // can contain 28 regular + 2 pinned while 3,000+ dialogs remain.
        // Do not use `ordinary===limit` as an end-of-list test.
        $isSlice=($response['_']??null)==='messages.dialogsSlice';
        $mayContinue=$ordinary>0 && (
            count($dialogs)>=$limit ||
            ($isSlice && (int)($response['count']??0)>count($dialogs))
        );
        if ($mayContinue) {
            foreach (array_reverse($dialogs) as $last) {
                if (!is_array($last)||($last['pinned']??false)===true)continue;
                $rawPeer=$last['peer']??null;
                if (!(is_int($rawPeer)||is_array($rawPeer)))continue;
                $peer=is_int($rawPeer)?$rawPeer:$peerId($rawPeer);
                $messageId=$last['top_message']??0;
                if (!is_int($peer)||$peer===0||!is_int($messageId)||$messageId<=0)continue;
                $date=0;
                foreach (($response['messages']??[]) as $message) {
                    if (!is_array($message)||($message['id']??null)!==$messageId)continue;
                    $msgPeer=$message['peer_id']??null;
                    if (!(is_int($msgPeer)||is_array($msgPeer)))continue;
                    if ((is_int($msgPeer)?$msgPeer:$peerId($msgPeer))===$peer) {
                        $date=(int)($message['date']??0);
                        break;
                    }
                }
                $next=['date'=>max(0,$date),'id'=>$messageId,'peer'=>$peer];
                break;
            }
        }
        return [
            'groups'=>array_values($groups),
            'next_offset'=>$next,
            'scanned_dialog_count'=>count($dialogs),
        ];
    }

    /** A discoverable public address is not proof of membership by itself. */
    private static function isUsableGroupChat(array $chat): bool
    {
        if (($chat['left']??false)||($chat['kicked']??false)||
            ($chat['deactivated']??false))return false;
        return ($chat['_']??null)==='chat' ||
            (($chat['_']??null)==='channel' && ($chat['megagroup']??false)===true);
    }

    private static function publicUsername(array $chat): ?string
    {
        if (is_string($chat['username']??null)&&$chat['username']!=='') {
            return $chat['username'];
        }
        foreach (($chat['usernames']??[]) as $username) {
            if (is_array($username)&&($username['active']??true)===true &&
                is_string($username['username']??null))return $username['username'];
        }
        return null;
    }

    /**
     * Resolve only the two canonical public communities, without crawling
     * the account. Both must be joined supergroups verified by username.
     */
    public function recommendedGroups(): array
    {
        $groups=[];
        foreach (['LibreCodeCoop','LibreSign'] as $username) {
            try {
                $info=$this->api->getInfo('@'.$username);
                $group=self::verifiedRecommendation(
                    $info,$username,
                    fn(array $peer): int=>$this->api->getId($peer),
                );
                if ($group!==null)$groups[$group['id']]=$group;
            } catch (\Throwable) {
                // Public groups are optional; a network error never blocks
                // the ordinary account-scoped dialogs page.
                continue;
            }
        }
        return array_values($groups);
    }

    /** @param callable(array):int $peerId */
    public static function verifiedRecommendation(mixed $info,string $expectedUsername,
                                                  callable $peerId): ?array
    {
        if (!is_array($info)||!is_array($info['Chat']??null))return null;
        $chat=$info['Chat'];
        // Resolving an accessible public group is not proof of membership.
        // Telegram must explicitly report that the current account has joined.
        if (($chat['_']??null)!=='channel'||
            ($chat['megagroup']??false)!==true||
            ($chat['left']??false)===true||
            ($chat['kicked']??false)===true||
            strcasecmp(self::publicUsername($chat)??'',$expectedUsername)!==0) {
            return null;
        }
        $title=trim(mb_substr((string)($chat['title']??''),0,150));
        $channelId=$chat['id']??null;
        if ($title===''||!is_int($channelId)||$channelId===0)return null;
        // MadelineProto may already expose Bot API's negative dialog ID.
        $id=$channelId<0?$channelId:$peerId(['_'=>'peerChannel','channel_id'=>$channelId]);
        if (!is_int($id)||$id===0||!DialogId::isSupergroupOrChannel($id))return null;
        return ['id'=>$id,'title'=>$title,'recommended'=>true,'archived'=>null];
    }

    /** Validate only explicitly selected IDs, never re-enumerate all dialogs. */
    public function groupDetails(array $ids): array
    {
        $result=[];
        foreach ($ids as $id) {
            try {
                $info=$this->api->getInfo($id);
                if (!is_array($info)||!self::groupInfoIsEligible($info))continue;
                $chat=$info['Chat']??$info;
                $title=trim(mb_substr((string)($chat['title']??''),0,150));
                if ($title!=='')$result[$id]=['id'=>$id,'title'=>$title];
            } catch (\Throwable) {
                continue;
            }
        }
        return $result;
    }

    public function history(array|int|string $peer,int $limit): array
    {
        if ($limit<1||$limit>1000)throw new RuntimeException('Invalid Telegram page bound');
        $response=$this->api->messages->getHistory(peer:$peer,limit:$limit);
        if (!is_array($response)||!is_array($response['messages']??null)) {
            throw new RuntimeException('Telegram returned invalid message history');
        }
        if (count($response['messages'])>$limit) {
            throw new RuntimeException('Telegram returned excess messages');
        }
        return $response['messages'];
    }

    public function close(): void
    {
        // MadelineProto flushes IPC and session state through its own lifecycle.
        unset($this->api);
    }
}
