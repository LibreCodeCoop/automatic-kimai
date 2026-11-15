<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\MadelineTelegramAdapter;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\TelegramGroupSelection;
use App\AutomaticKimai\TelegramWebLogin;
use InvalidArgumentException;
use Tests\TestCase;

final class TelegramDiscoveryTest extends TestCase
{
    private LocalStore $store;
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder=sys_get_temp_dir().'/telegram-discovery-'.bin2hex(random_bytes(8));
        $this->store=new LocalStore($this->folder);
        $this->app->instance(LocalStore::class,$this->store);
        $this->store->saveProfile(new Profile([
            'profile_id'=>'user1','timezone'=>'America/Sao_Paulo',
            'customer_id'=>1,'project_id'=>2,'activity_id'=>3,
        ]));
        $this->store->setSources(['telegram_setup'=>true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder.'/*')?:[] as $file) {
            if (is_file($file))@unlink($file);
        }
        @rmdir($this->folder);
        parent::tearDown();
    }

    private function fakeDialogs(): object
    {
        return new class {
            public int $calls=0;
            public int $userId=12345;
            public function selfId(): int {return $this->userId;}
            public function groupsPage(?array $offset=null,int $folder=0,int $limit=30): array
            {
                if($limit!==100)throw new \RuntimeException('Search must use bounded 100-dialog pages');
                $this->calls++;
                $page=$offset['id']??0;
                $group=$folder===0 ? [
                    'id'=>-1002000-$page,'title'=>$page===6?'SampleTeam remoto':
                        ($page===0?'SampleTeam equipe':'Grupo diverso '.$page),
                ] : [
                    'id'=>-1003000-$page,'title'=>$page===0?'SampleTeam arquivado':'Arquivado diverso',
                ];
                return [
                    'groups'=>[$group],
                    'scanned_dialog_count'=>100,
                    'next_offset'=>$page>=6?null:[
                        'date'=>1794500000,'id'=>$page+1,'peer'=>-1002000-$page,
                    ],
                ];
            }
            public function history(): never {throw new \RuntimeException('No message reads allowed');}
            public function getFullDialogs(): never {throw new \RuntimeException('No account-wide history enumeration');}
        };
    }

    public function test_catalog_fetches_one_page_per_request_and_includes_archive(): void
    {
        $client=$this->fakeDialogs();
        $picker=new TelegramGroupSelection($this->store);
        $first=$picker->catalog(null,$client,0);
        self::assertSame('ok',$first['status']);
        self::assertCount(1,$first['groups']);
        self::assertSame('SampleTeam equipe',$first['groups'][0]['title']);
        self::assertSame(['id','title','archived','recommended'],array_keys($first['groups'][0]));
        self::assertFalse($first['groups'][0]['archived']);
        self::assertIsString($first['next_cursor']);
        self::assertSame(100,$first['scanned_dialog_count']);
        self::assertSame(1,$client->calls,'One HTTP request must not trigger five MTProto RPCs');
        $second=$picker->catalog($first['next_cursor'],$client,0);
        self::assertSame(100,$second['scanned_dialog_count']);
        self::assertSame(2,$client->calls);
        $archived=$picker->catalog(null,$client,1);
        self::assertSame('SampleTeam arquivado',$archived['groups'][0]['title']);
        self::assertTrue($archived['groups'][0]['archived']);
        self::assertSame(3,$client->calls);
    }

    public function test_catalog_cursors_are_account_and_folder_scoped_and_reject_tampering(): void
    {
        $picker=new TelegramGroupSelection($this->store);
        $client=$this->fakeDialogs();
        $token=$picker->catalog(null,$client,0)['next_cursor'];
        $before=$client->calls;
        foreach ([1,2,-1] as $folder) {
            try {
                $picker->catalog($token,$client,$folder);
                self::fail('A mismatched or invalid folder was accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $client->userId=999;
        try {
            $picker->catalog($token,$client,0);
            self::fail('A different Telegram account reused an old catalog cursor');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $client->userId=12345;
        try {
            $picker->catalog('tampered.encrypted.cursor',$client,0);
            self::fail('An untrusted cursor was accepted');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        self::assertSame($before,$client->calls,
            'Rejected cursors must not make extra Telegram RPC calls');
    }

    public function test_catalog_never_returns_unrelated_telegram_metadata_or_reads_messages(): void
    {
        $client=new class {
            public function selfId(): int {return 12345;}
            public function groupsPage(?array $offset=null,int $folder=0,int $limit=30): array
            {
                if ($limit!==100)throw new \RuntimeException('Catalog must be bounded');
                return ['groups'=>[
                    ['id'=>-1001,'title'=>'SampleTeam','private_note'=>'do not leak',
                        'member_count'=>200,'recommended'=>false],
                    ['id'=>null,'title'=>'Invalid'],
                ],'next_offset'=>null,'scanned_dialog_count'=>2];
            }
            public function history(): never {throw new \RuntimeException('No history reads');}
            public function getFullDialogs(): never {throw new \RuntimeException('No full dialog enumeration');}
        };
        $result=(new TelegramGroupSelection($this->store))->catalog(null,$client,0);
        self::assertSame([[
            'id'=>-1001,'title'=>'SampleTeam','archived'=>false,'recommended'=>false,
        ]],$result['groups']);
        self::assertStringNotContainsString('do not leak',json_encode($result));
    }

    public function test_community_parser_only_returns_member_groups_and_no_bots_or_channels(): void
    {
        $id=static fn(array|int $peer): int=>is_int($peer)?$peer:$peer['id'];
        $community=['_'=>'community','id'=>-100777,'title'=>'SampleTeam','left'=>false];
        $full=[
            'full_chat'=>['_'=>'communityFull','linked_peers'=>[
                ['_'=>'communityPeer','peer'=>-1001,'can_view_history'=>true],
                ['_'=>'communityPeer','peer'=>-1002,'can_view_history'=>true],
                ['_'=>'communityPeer','peer'=>-1003,'can_view_history'=>true],
                ['_'=>'communityPeer','peer'=>-1004,'can_view_history'=>true],
                ['_'=>'communityPeer','peer'=>-1005,'can_view_history'=>false],
                ['_'=>'communityPeer','peer'=>-1006,'can_view_history'=>true],
            ]],
            'chats'=>[
                ['_'=>'channel','id'=>-1001,'title'=>'SampleTeam Projetos','megagroup'=>true],
                ['_'=>'chat','id'=>-1002,'title'=>'SampleTeam Eventos'],
                ['_'=>'channel','id'=>-1003,'title'=>'Canal de avisos','broadcast'=>true],
                ['_'=>'channel','id'=>-1004,'title'=>'Grupo que saiu','megagroup'=>true,'left'=>true],
                ['_'=>'channel','id'=>-1005,'title'=>'Oculto','megagroup'=>true],
            ],
            'users'=>[['_'=>'user','id'=>-1006,'bot'=>true]],
        ];
        $data=MadelineTelegramAdapter::communityFromFull($community,$full,$id);
        self::assertSame(-100777,$data['id']);
        self::assertSame('SampleTeam',$data['title']);
        self::assertSame([-1001,-1002],array_column($data['groups'],'id'));
        self::assertSame([-100777,-100777],array_column($data['groups'],'community_id'));
        self::assertNull(MadelineTelegramAdapter::communityFromFull(
            array_replace($community,['left'=>true]),$full,$id
        ));
        self::assertNull(MadelineTelegramAdapter::communityFromFull(
            $community,array_replace($full,['full_chat'=>['_'=>'channelFull']]),$id
        ));
    }

    public function test_status_identity_excludes_phone_and_secrets_and_avatar_accepts_raster_only(): void
    {
        $account=TelegramWebLogin::publicAccount([
            'id'=>12345,'first_name'=>'Pessoa','last_name'=>'Exemplo',
            'username'=>'example_dev','phone'=>'5511999999999','access_hash'=>'secret',
        ]);
        self::assertSame([
            'id'=>'12345','display_name'=>'Pessoa Exemplo','username'=>'example_dev',
        ],$account);
        self::assertStringNotContainsString('5511',json_encode($account));
        self::assertNull(TelegramWebLogin::publicAccount(['id'=>12,'bot'=>true]));
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL2WAAAAABJRU5ErkJggg==',true);
        self::assertSame('image/png',TelegramWebLogin::safeAvatar($png)['mime']);
        self::assertNull(TelegramWebLogin::safeAvatar('<svg><script>alert(1)</script></svg>'));
        self::assertNull(TelegramWebLogin::safeAvatar(str_repeat('X',270000)));
    }

    public function test_telegram_avatar_reads_only_the_authorized_users_bounded_raster_photo(): void
    {
        $this->store->saveCredentials([
            'TELEGRAM_API_ID'=>'123456',
            'TELEGRAM_API_HASH'=>str_repeat('a',32),
        ]);
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL2WAAAAABJRU5ErkJggg==',true);
        $client=new class($png) {
            public object $photos;
            public int $downloadCalls=0;
            private string $bytes;
            public function __construct(string $png)
            {
                $this->bytes=$png;
                $this->photos=new class {
                    public function getUserPhotos(
                        string $user_id,int $offset,int $max_id,int $limit
                    ): array {
                        if($user_id!=='me'||$limit!==1) {
                            throw new \RuntimeException('Other account queried');
                        }
                        return ['photos'=>[[
                            '_'=>'photo','id'=>123,
                            'sizes'=>[['_'=>'photoSize','w'=>64,'h'=>64]],
                        ]]];
                    }
                };
            }
            public function getSelf(): array
            {
                return ['id'=>123,'first_name'=>'Test','username'=>'test123'];
            }
            public function downloadToCallable(
                array $photo,callable $cb,?callable $progress,bool $seekable,
                int $offset,int $end
            ): void {
                $this->downloadCalls++;
                if($seekable||$offset!==0||$end!==262144) {
                    throw new \RuntimeException('Unbounded avatar');
                }
                $cb($this->bytes,0);
            }
        };
        $image=(new TelegramWebLogin($this->store))->avatar($client);
        self::assertIsArray($image);
        self::assertSame('image/png',$image['mime']);
        self::assertSame(1,$client->downloadCalls);
    }

    public function test_explicit_logout_revokes_session_and_clears_only_account_bound_data(): void
    {
        $this->store->setSources(['telegram'=>true,'github'=>true]);
        $settings=$this->store->profile()->toArray();
        $settings['work_messenger_dialog_ids']=[-100222];
        $settings['work_messenger_group_titles']=['-100222'=>'Old Team'];
        $settings['work_messenger_labels']=['Old Team'];
        $this->store->saveProfile(new Profile($settings));
        $this->store->write('telegram_scoped_history.json',['private'=>'old context']);
        $this->store->saveCredentials([
            'TELEGRAM_API_ID'=>'123456','TELEGRAM_API_HASH'=>str_repeat('a',32),
            'GITHUB_TOKEN'=>'test-only-other-provider',
        ]);
        $client=new class {
            public int $calls=0;
            public function logout(): void {$this->calls++;}
        };
        $result=(new TelegramWebLogin($this->store))->disconnect($client);
        self::assertSame('disconnected',$result['status']);
        self::assertSame(1,$client->calls);
        self::assertFalse($this->store->sources()['telegram']);
        self::assertTrue($this->store->sources()['github']);
        self::assertSame([], $this->store->profile()->get('work_messenger_dialog_ids'));
        self::assertSame([], $this->store->profile()->get('work_messenger_group_titles'));
        self::assertSame([], $this->store->read('telegram_scoped_history.json'));
        self::assertSame('test-only-other-provider',$this->store->credentials()['GITHUB_TOKEN']);
        self::assertSame('123456',$this->store->credentials()['TELEGRAM_API_ID']);
    }

    public function test_logout_failure_disables_collection_without_clearing_old_selection(): void
    {
        $this->store->setSources(['telegram'=>true]);
        $settings=$this->store->profile()->toArray();
        $settings['work_messenger_dialog_ids']=[-100222];
        $this->store->saveProfile(new Profile($settings));
        $client=new class {
            public function logout(): never {throw new \RuntimeException('Remote failure');}
        };
        try {
            (new TelegramWebLogin($this->store))->disconnect($client);
            self::fail('Logout failure should propagate');
        } catch (\RuntimeException) {
            self::assertFalse($this->store->sources()['telegram']);
            self::assertSame([-100222],$this->store->profile()->get('work_messenger_dialog_ids'));
        }
    }
}
