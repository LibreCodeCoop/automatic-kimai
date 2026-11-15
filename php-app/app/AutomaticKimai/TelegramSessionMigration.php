<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use danog\MadelineProto\Conversion;
use RuntimeException;

/**
 * Converts an already authorized Telethon SQLite session into a *separate*
 * MadelineProto session. Original bytes are never modified or deleted.
 * Do not run concurrently with the existing Telethon scanner.
 */
final class TelegramSessionMigration
{
    public function __construct(private readonly string $privateRoot) {}

    public static function validateSource(string $file): void
    {
        if (!is_file($file)||is_link($file)||strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='session') {
            throw new RuntimeException('An existing regular Telethon .session file is required');
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($file)&0077)!==0) {
            throw new RuntimeException('Telethon session is accessible to other users');
        }
        $sqlite=new \PDO('sqlite:file:'.$file.'?mode=ro',null,null,[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
        ]);
        $schema=$sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name='sessions'")->fetchColumn();
        if ($schema!=='sessions')throw new RuntimeException('Invalid Telethon session schema');
        $row=$sqlite->query('SELECT dc_id,length(auth_key) AS bytes FROM sessions LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if (!$row||!is_numeric($row['dc_id'])||(int)$row['dc_id']<1||(int)$row['dc_id']>5||
            (int)$row['bytes']!==256) {
            throw new RuntimeException('Telethon session DC/auth-key format invalid');
        }
    }

    public function import(string $telethonPath,int $apiId,string $apiHash): array
    {
        self::validateSource($telethonPath);
        $root=rtrim($this->privateRoot,'/');
        if (is_link($root))throw new RuntimeException('Symlinked private session directory');
        if (!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root)) {
            throw new RuntimeException('Unable to initialize private session directory');
        }
        chmod($root,0700);
        $target=$root.'/telegram-user.session';
        if (file_exists($target)||is_link($target)||
            file_exists($target.'.lock')||file_exists($target.'.safe.php')) {
            throw new RuntimeException('Destination session already exists; cannot overwrite authorization');
        }
        $copy=$root.'/telethon-import-'.bin2hex(random_bytes(8)).'.session';
        $sourceHash=hash_file('sha256',$telethonPath);
        if (!copy($telethonPath,$copy))throw new RuntimeException('Cannot copy existing authorization for import');
        chmod($copy,0600);
        if (hash_file('sha256',$copy)!==$sourceHash) {
            @unlink($copy);
            throw new RuntimeException('Telethon session changed during copy; retry after stopping the old scanner');
        }
        try {
            $old=umask(0077);
            try {
                $client=Conversion::telethon($copy,$target,
                    MadelineTelegramAdapter::settings($apiId,$apiHash,$root.'/telegram-user.log'));
            } finally {umask($old);}
            if (is_dir($target))chmod($target,0700);
            if (is_file($root.'/telegram-user.log'))chmod($root.'/telegram-user.log',0600);
            $me=$client->getSelf();
            if (!is_array($me)||($me['bot']??false)||
                !is_int($me['id']??null)||$me['id']<1||
                $client->getAuthorization()!==\danog\MadelineProto\API::LOGGED_IN) {
                throw new RuntimeException('Imported session did not establish authorized Telegram user state');
            }
            $expectedId=$me['id'];
            // A successful in-memory getSelf is not sufficient: importing a
            // reusable MTProto key may fail when the library persists state.
            unset($client);
            \danog\MadelineProto\API::finalize();
            $check=new \danog\MadelineProto\API($target,
                MadelineTelegramAdapter::settings($apiId,$apiHash,$root.'/telegram-user.log'));
            $again=$check->getSelf();
            if ($check->getAuthorization()!==\danog\MadelineProto\API::LOGGED_IN||
                !is_array($again)||($again['id']??null)!==$expectedId) {
                throw new RuntimeException('MadelineProto session failed reload verification; legacy session preserved');
            }
            unset($check);
            \danog\MadelineProto\API::finalize();
            return [
                'status'=>'authorized','user_id'=>$expectedId,
                'backend'=>'MadelineProto','source_unchanged'=>hash_file('sha256',$telethonPath)===$sourceHash,
            ];
        } finally {
            @unlink($copy);
        }
    }
}
