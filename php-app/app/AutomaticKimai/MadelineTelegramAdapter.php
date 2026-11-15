<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use danog\MadelineProto\API;
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
            if (!is_int($id) && !ctype_digit((string)$id))continue;
            if (!is_array($item))continue;
            try {
                $info=$this->api->getInfo((int)$id);
            } catch (\Throwable) {continue;}
            $chat=$info['Chat']??$info['User']??$info;
            $title=(string)($chat['title']??trim((string)($chat['first_name']??'').' '.(string)($chat['last_name']??'')));
            if ($title==='')continue;
            $result[]=[
                'id'=>(int)$id,'peer'=>(int)$id,'title'=>$title,
                'read_inbox_max_id'=>$item['read_inbox_max_id']??null,
                'unread_count'=>$item['unread_count']??null,
            ];
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
