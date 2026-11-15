<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Persistent, isolated Telegram MTProto scope and read-position changes. */
final class TelegramCollector
{
    public function __construct(private readonly LocalStore $store) {}

    public function scan(?object $client=null,?DateTimeImmutable $now=null): array
    {
        $profile=$this->store->profile();
        if (!$profile)throw new RuntimeException('Configure individual Kimai profile first');
        $sources=$this->store->sources();
        if (!$sources['telegram'])throw new RuntimeException('Telegram must be explicitly opted in');
        $labels=$profile->get('work_messenger_labels');
        if ($labels===[])throw new InvalidArgumentException('Approved Telegram work-chat labels missing');
        $creds=$this->store->credentials();
        if ($client===null) {
            if (!isset($creds['TELEGRAM_API_ID'],$creds['TELEGRAM_API_HASH'])) {
                throw new RuntimeException('Telegram API credentials have not been configured');
            }
            $client=new MadelineTelegramAdapter(
                $this->store->root().'/telegram-user.session',
                (int)$creds['TELEGRAM_API_ID'],
                $creds['TELEGRAM_API_HASH'],
            );
        }
        $first=$this->store->read('telegram_scoped_history.json');
        $days=14;
        $now??=new DateTimeImmutable();
        $snapshot=(new TelegramSource())->scan($client,$labels,$days,600,$now);
        // The original history and read markers are stored privately, with
        // contents reduced to metadata. Never persist chat titles or bodies.
        $changes=TelegramReadProgress::compute($first,$snapshot);
        $oldChanges=$this->store->read('telegram_read_progress.json');
        $progress=TelegramReadProgress::retain($oldChanges,$changes,$now);
        $this->store->write('telegram_scoped_history.json',$snapshot);
        $this->store->write('telegram_read_progress.json',$progress);
        if (method_exists($client,'close'))$client->close();
        return [
            'status'=>'ok','matched_dialogs'=>$snapshot['matched_dialog_count'],
            'scoped_messages'=>array_sum(array_map(static fn($row)=>count($row['observations']),
                $snapshot['scoped_client_chats'])),
            'read_position_advances'=>count($changes),
            'remote_writes'=>0,
            'body_persisted'=>false,
        ];
    }
}
