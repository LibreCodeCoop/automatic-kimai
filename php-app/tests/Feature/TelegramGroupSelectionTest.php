<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\MadelineTelegramAdapter;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\TelegramGroupSelection;
use App\AutomaticKimai\TelegramSource;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class TelegramGroupSelectionTest extends TestCase
{
    private LocalStore $store;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-group-selection-'.bin2hex(random_bytes(8));
        $this->store=new LocalStore($this->dir);
        $this->app->instance(LocalStore::class,$this->store);
        $this->store->saveProfile(new Profile([
            'profile_id'=>'worker_1','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>4,'activity_id'=>6,
        ]));
        $this->store->setSources(['telegram_setup'=>true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $f) {
            if (is_file($f))@unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function groupClient(): object
    {
        return new class {
            public int $catalogReads=0;
            public function groups(): array
            {
                $this->catalogReads++;
                return [
                    ['id'=>-1001234567,'title'=>'Equipe de trabalho'],
                    ['id'=>-1009876543,'title'=>'Equipe de trabalho'],
                    ['id'=>-1005551234,'title'=>'Projeto cliente'],
                ];
            }
            public function history(): never
            {
                throw new \RuntimeException('Group enumeration must never read messages');
            }
        };
    }

    public function test_private_direct_and_broadcast_dialogs_are_excluded_by_type(): void
    {
        self::assertTrue(MadelineTelegramAdapter::groupInfoIsEligible(['type'=>'chat']));
        self::assertTrue(MadelineTelegramAdapter::groupInfoIsEligible(['type'=>'supergroup']));
        self::assertFalse(MadelineTelegramAdapter::groupInfoIsEligible(['type'=>'user']));
        self::assertFalse(MadelineTelegramAdapter::groupInfoIsEligible(['type'=>'bot']));
        self::assertFalse(MadelineTelegramAdapter::groupInfoIsEligible(['type'=>'channel']));
        self::assertTrue(MadelineTelegramAdapter::groupInfoIsEligible(
            ['Chat'=>['_'=>'channel','megagroup'=>true]]));
        self::assertFalse(MadelineTelegramAdapter::groupInfoIsEligible(
            ['Chat'=>['_'=>'channel','broadcast'=>true]]));
        self::assertFalse(MadelineTelegramAdapter::groupInfoIsEligible(
            ['Chat'=>['_'=>'user','first_name'=>'Private']]));
    }

    public function test_list_is_only_group_names_and_ids_and_never_fetches_messages(): void
    {
        $client=$this->groupClient();
        $data=(new TelegramGroupSelection($this->store))->available($client);
        self::assertSame(1,$client->catalogReads);
        self::assertCount(3,$data['groups']);
        self::assertSame([], $data['selected_ids']);
        self::assertSame(['id','title'],array_keys($data['groups'][0]));
        self::assertFalse($this->store->sources()['telegram']);
    }

    public function test_selected_groups_are_exact_peer_ids_and_never_enable_collection_implicitly(): void
    {
        $client=$this->groupClient();
        $this->store->setSources(['telegram'=>true]);
        $selected=(new TelegramGroupSelection($this->store))
            ->select(['-1009876543','-1005551234'],$client);
        self::assertSame(['status'=>'saved','selected_count'=>2,'enabled'=>false],$selected);
        self::assertSame([-1009876543,-1005551234],
            $this->store->profile()->get('work_messenger_dialog_ids'));
        self::assertSame(['Equipe de trabalho','Projeto cliente'],
            $this->store->profile()->get('work_messenger_labels'));
        self::assertFalse($this->store->sources()['telegram']);
        self::assertSame(0,fileperms($this->dir.'/profile.json')&0077);
        self::assertSame([-1009876543,-1005551234],
            (new TelegramGroupSelection($this->store))->available($client)['selected_ids']);
    }

    public function test_unknown_groups_are_rejected_without_changes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TelegramGroupSelection($this->store))
            ->select(['-1001234567','-1001111111'],$this->groupClient());
    }

    public function test_duplicate_or_invalid_groups_are_rejected(): void
    {
        foreach ([
            ['-1001234567','-1001234567'],
            ['-1001234567','0'],
            ['-1001234567','001'],
            ['-1001234567','1 OR 1=1'],
        ] as $invalid) {
            try {
                (new TelegramGroupSelection($this->store))->select($invalid,$this->groupClient());
                self::fail('Invalid group should be rejected');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->store->profile()->get('work_messenger_dialog_ids'));
            }
        }
    }

    public function test_scanner_matches_only_selected_id_even_when_titles_are_identical(): void
    {
        $fake=new class {
            public array $queried=[];
            public function dialogs(): array
            {
                return [
                    ['id'=>-1001234567,'peer'=>-1001234567,'title'=>'Equipe de trabalho'],
                    ['id'=>-1009876543,'peer'=>-1009876543,'title'=>'Equipe de trabalho'],
                    ['id'=>-1003333333,'peer'=>-1003333333,'title'=>'Conversa pessoal'],
                ];
            }
            public function history($peer,$limit): array
            {
                $this->queried[]=$peer;
                if($peer!==-1009876543)throw new \RuntimeException('Unauthorized group accessed');
                return [];
            }
        };
        $data=(new TelegramSource())->scan(
            $fake,['Equipe de trabalho'],14,600,
            new DateTimeImmutable('@1793962000'),[-1009876543]
        );
        self::assertSame([-1009876543],$fake->queried);
        self::assertSame(1,$data['matched_dialog_count']);
    }
}
