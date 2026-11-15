<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\TelegramCollector;
use App\AutomaticKimai\TelegramGroupSelection;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramUnlimitedSelectionTest extends TestCase
{
    private string $dir;
    private LocalStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-tg-unlimited-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700);
        $this->store=new LocalStore($this->dir);
        $this->store->setSources(['telegram_setup'=>true,'telegram'=>true]);
        $ids=array_map(static fn(int $i)=>-1000000-$i,range(1,250));
        $titles=[];
        foreach ($ids as $id)$titles[(string)$id]='Equipe '.abs($id);
        $this->store->saveProfile(new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'work_messenger_dialog_ids'=>$ids,
            'work_messenger_labels'=>array_values($titles),
            'work_messenger_group_titles'=>$titles,
        ]));
        $this->store->write('telegram_selected_account.json',[
            'account_id'=>103456,
            'selection_digest'=>TelegramGroupSelection::accountSelectionDigest(
                103456,$this->store->profile()),
            'verified_at_utc'=>'2026-11-15T15:00:00+00:00',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $file) {
            if (is_file($file))@unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function fake(): object
    {
        return new class {
            public array $queries=[];
            public int $failOn=0;
            public int $account=103456;
            public function selfId(): int {return $this->account;}
            public function dialogs(): array
            {
                throw new RuntimeException('Must not enumerate unrelated chats');
            }
            public function history(int $id,int $limit): array
            {
                if ($this->failOn!==0 && $this->failOn===$id) {
                    throw new RuntimeException('Fake network outage');
                }
                $this->queries[]=['id'=>$id,'limit'=>$limit];
                return [[
                    'id'=>123,'date'=>1794750000,'out'=>true,
                    'message'=>'Confidential business message never persisted',
                    'media'=>null,
                ]];
            }
            public function readHistory(): never
            {
                throw new RuntimeException('Read receipts are forbidden');
            }
        };
    }

    public function test_250_groups_are_scanned_in_bounded_rotating_batches_without_read_receipts(): void
    {
        $client=$this->fake();
        $now=new DateTimeImmutable('2026-11-15T16:00:00+00:00');
        $last=null;
        for ($cycle=0;$cycle<11;$cycle++) {
            $last=(new TelegramCollector($this->store))->scan($client,
                $now->modify('+'.$cycle.' minutes'));
            self::assertLessThanOrEqual(TelegramCollector::GROUPS_PER_CYCLE,
                $last['groups_scanned_in_cycle']);
            self::assertSame(250,$last['selected_groups']);
            self::assertFalse($last['read_receipts_sent']);
            self::assertSame(0,$last['remote_writes']);
        }
        self::assertCount(250,$client->queries);
        self::assertCount(250,array_unique(array_column($client->queries,'id')));
        foreach ($client->queries as $query)self::assertLessThanOrEqual(600,$query['limit']);
        self::assertSame(0,$last['remaining_in_round']);
        $snapshot=$this->store->read('telegram_scoped_history.json');
        self::assertSame(250,$snapshot['matched_dialog_count']);
        self::assertCount(250,$snapshot['scoped_client_chats']);
        self::assertTrue($snapshot['no_message_bodies_stored']);
        self::assertFalse($snapshot['read_receipts_sent']);
        self::assertStringNotContainsString('Confidential business',
            json_encode($snapshot));
        self::assertSame(0,fileperms($this->dir.'/telegram_scan_cursor.json')&0077);
    }

    public function test_network_failure_does_not_advance_scan_cursor_or_store_partial_snapshot(): void
    {
        $client=$this->fake();
        $now=new DateTimeImmutable('2026-11-15T16:00:00+00:00');
        (new TelegramCollector($this->store))->scan($client,$now);
        $cursor=$this->store->read('telegram_scan_cursor.json');
        $old=$this->store->read('telegram_scoped_history.json');
        $client->failOn=-1000000-25;
        try {
            (new TelegramCollector($this->store))->scan($client,$now->modify('+1 minute'));
            self::fail('Network failure must stop this page');
        } catch (RuntimeException) {
            self::assertSame($cursor,$this->store->read('telegram_scan_cursor.json'));
            self::assertSame($old,$this->store->read('telegram_scoped_history.json'));
        }
        $client->failOn=0;
        $result=(new TelegramCollector($this->store))->scan($client,$now->modify('+2 minutes'));
        self::assertSame(24,$result['groups_scanned_in_cycle']);
        self::assertCount(48,$this->store->read('telegram_scoped_history.json')['scoped_client_chats']);
    }

    public function test_revoking_consent_during_inflight_history_discards_batch(): void
    {
        $client=new class($this->store) {
            public function __construct(private LocalStore $store) {}
            public function selfId(): int {return 103456;}
            public function history(int $id,int $limit): array
            {
                $this->store->setSources(['telegram'=>false]);
                return [['id'=>123,'date'=>1794750000,'out'=>true,
                    'message'=>'Must never be stored after consent revocation']];
            }
            public function dialogs(): never
            {
                throw new RuntimeException('Unrelated dialogs forbidden');
            }
        };
        try {
            (new TelegramCollector($this->store))->scan($client,
                new DateTimeImmutable('2026-11-15T16:00:00+00:00'));
            self::fail('Consent revocation must abort data persistence');
        } catch (RuntimeException) {
            self::assertFalse($this->store->sources()['telegram']);
            self::assertSame([],$this->store->read('telegram_scoped_history.json'));
            self::assertSame([],$this->store->read('telegram_scan_cursor.json'));
        }
    }

    public function test_another_telegram_account_cannot_inherit_the_selected_groups(): void
    {
        $client=$this->fake();
        $client->account=999888;
        try {
            (new TelegramCollector($this->store))->scan($client,
                new DateTimeImmutable('2026-11-15T16:00:00+00:00'));
            self::fail('A different account must not reuse selected peer IDs');
        } catch (RuntimeException) {
            self::assertSame([],$client->queries);
            self::assertSame([],$this->store->read('telegram_scoped_history.json'));
        }
    }

    public function test_opt_out_stops_all_collection_without_network_access(): void
    {
        $client=$this->fake();
        $this->store->setSources(['telegram'=>false]);
        $this->expectException(RuntimeException::class);
        (new TelegramCollector($this->store))->scan($client);
    }
}
