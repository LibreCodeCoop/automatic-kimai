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
            public int $validatedGroups=0;

            public function selfId(): int {return 103456;}

            public array $queriedFolders=[];
            public function groupsPage(?array $offset=null,int $folder=0): array
            {
                $this->catalogReads++;
                $this->queriedFolders[]=$folder;
                return [
                    'groups'=>[
                        ['id'=>-1001234567,'title'=>'Equipe de trabalho','recommended'=>false,'archived'=>$folder===1],
                        ['id'=>-1009876543,'title'=>'Equipe de trabalho','recommended'=>false,'archived'=>$folder===1],
                        ['id'=>-1005551234,'title'=>'Projeto cliente','recommended'=>false,'archived'=>$folder===1],
                    ],
                    'next_offset'=>null,'scanned_dialog_count'=>4,
                ];
            }

            public function groupDetails(array $ids): array
            {
                $names=[
                    -1001234567=>'Equipe de trabalho',
                    -1009876543=>'Equipe de trabalho',
                    -1005551234=>'Projeto cliente',
                ];
                $this->validatedGroups+=count($ids);
                $result=[];
                foreach ($ids as $id) {
                    if (isset($names[$id])) {
                        $result[$id]=['id'=>$id,'title'=>$names[$id]];
                    }
                }
                return $result;
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
        $data=(new TelegramGroupSelection($this->store))->page(null,$client);
        self::assertSame(1,$client->catalogReads);
        self::assertCount(3,$data['groups']);
        self::assertSame([], $data['selected_ids']);
        self::assertSame(['id','title','recommended','archived'],array_keys($data['groups'][0]));
        self::assertNull($data['next_cursor']);
        self::assertSame(4,$data['scanned_dialog_count']);
        self::assertSame(0,$data['folder']);
        self::assertFalse($data['suggestions_allowed']);
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
        self::assertTrue($this->store->profile()->get('work_messenger_recommendations_reviewed'));
        self::assertSame([
            '-1009876543'=>'Equipe de trabalho',
            '-1005551234'=>'Projeto cliente',
        ],$this->store->profile()->get('work_messenger_group_titles'));
        self::assertFalse($this->store->sources()['telegram']);
        self::assertSame(0,fileperms($this->dir.'/profile.json')&0077);
        self::assertSame([-1009876543,-1005551234],
            (new TelegramGroupSelection($this->store))->page(null,$client)['selected_ids']);
        self::assertSame(2,$client->validatedGroups);
        self::assertSame([-1009876543,-1005551234],
            array_column((new TelegramGroupSelection($this->store))->page(null,$client)['selected_groups'],'id'));
    }

    public function test_removing_selected_group_revokes_its_separate_work_classification_consent(): void
    {
        $client=$this->groupClient();
        $selection=new TelegramGroupSelection($this->store);
        $selection->select(['-1009876543','-1005551234'],$client);
        $profile=$this->store->profile();
        $settings=$profile->toArray();
        $settings['work_messenger_classification_labels']=[
            'Equipe de trabalho','Projeto cliente'
        ];
        $this->store->saveProfile(new Profile($settings));
        $selection->select(['-1009876543'],$client);
        self::assertSame(['Equipe de trabalho'],
            $this->store->profile()->workEvidenceLabels());
        self::assertSame([-1009876543],
            $this->store->profile()->get('work_messenger_dialog_ids'));
    }

    public function test_large_explicit_group_selection_is_unlimited_and_only_new_ids_are_revalidated(): void
    {
        $client=new class {
            public array $checked=[];
            public int $maxChunk=0;
            public int $account=103456;
            public bool $denyAll=false;
            public function selfId(): int {return $this->account;}
            public function groupDetails(array $ids): array
            {
                $this->maxChunk=max($this->maxChunk,count($ids));
                array_push($this->checked,...$ids);
                if ($this->denyAll)return [];
                $result=[];
                foreach ($ids as $id) {
                    $result[$id]=['id'=>$id,'title'=>'Equipe '.abs($id)];
                }
                return $result;
            }
        };
        $ids=array_map(static fn(int $n): string=>(string)(-100000-$n),range(1,250));
        $selection=new TelegramGroupSelection($this->store);
        $data=$selection->select($ids,$client);
        self::assertSame(250,$data['selected_count']);
        self::assertFalse($data['enabled']);
        self::assertCount(250,$this->store->profile()->get('work_messenger_dialog_ids'));
        self::assertCount(250,$this->store->profile()->get('work_messenger_labels'));
        self::assertCount(250,$this->store->profile()->get('work_messenger_group_titles'));
        self::assertLessThanOrEqual(32,$client->maxChunk);
        $client->checked=[];
        $data=$selection->select([...$ids,'-101000'],$client);
        self::assertSame(251,$data['selected_count']);
        self::assertSame([-101000],$client->checked);
        self::assertCount(251,$this->store->profile()->get('work_messenger_dialog_ids'));
        self::assertFalse($this->store->sources()['telegram']);
        self::assertTrue(TelegramGroupSelection::selectionMatchesAccount(
            $this->store->read('telegram_selected_account.json'),
            $client->account,$this->store->profile()));
        // An account switch must not trust old group-title cache: even the
        // same IDs must be revalidated against the newly logged-in account.
        $client->account=888555;
        $client->denyAll=true;
        $client->checked=[];
        try {
            $selection->select($ids,$client);
            self::fail('Another Telegram account must not inherit approvals');
        } catch (InvalidArgumentException) {
            self::assertNotEmpty($client->checked);
            self::assertFalse(TelegramGroupSelection::selectionMatchesAccount(
                $this->store->read('telegram_selected_account.json'),
                $client->account,$this->store->profile()));
            self::assertCount(251,$this->store->profile()->get('work_messenger_dialog_ids'));
        }
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

    public function test_no_public_group_is_preselected_by_the_application(): void
    {
        // The application must never resolve a predefined maintainer group.
        // Login, browsing and selection remain explicit user decisions.
        $fake=new class {
            public function selfId(): int {return 923123;}
            public function groupsPage(?array $offset=null): array {
                return ['groups'=>[['id'=>-10055,'title'=>'Sample work group',
                    'recommended'=>false]],'scanned_dialog_count'=>1,'next_offset'=>null];
            }
            public function recommendedGroups(): never {
                throw new \RuntimeException('Unexpected public-group discovery');
            }
        };
        $page=(new TelegramGroupSelection($this->store))->page(null,$fake);
        self::assertSame([], $page['recommended_groups']);
        self::assertFalse($page['suggestions_allowed']);
        self::assertSame([false],array_column($page['groups'],'recommended'));
    }

    public function test_catalog_pages_never_query_default_community_handles(): void
    {
        $fake=new class {
            public int $calls=0;
            public function selfId(): int{return 923123;}
            public function groupsPage(?array $offset=null): array {
                $this->calls++;
                return ['groups'=>[['id'=>-10055,'title'=>'Sample group',
                    'recommended'=>false]],'scanned_dialog_count'=>1,
                    'next_offset'=>$offset===null?
                        ['date'=>1794000123,'id'=>16,'peer'=>-10055]:null];
            }
            public function recommendedGroups(): never {
                throw new \RuntimeException('Must not fetch any suggested public group');
            }
            public function groups(): never {
                throw new \RuntimeException('Unbounded catalog scan forbidden');
            }
        };
        $selection=new TelegramGroupSelection($this->store);
        $first=$selection->page(null,$fake);
        self::assertCount(1,$first['groups']);
        self::assertSame([], $first['recommended_groups']);
        self::assertFalse($first['suggestions_allowed']);
        self::assertSame(1,$fake->calls);
        $more=$selection->page($first['next_cursor'],$fake);
        self::assertSame([], $more['recommended_groups']);
        self::assertSame(2,$fake->calls);
    }

    public function test_actual_madeline_proto_shape_handles_normalized_peers_and_extra_pinned_dialogs(): void
    {
        // The real MadelineProto wrapper returns negative int Bot API IDs in
        // both chats[].id and dialogs[].peer, even though the raw TL schema
        // documents peerChannel constructors. It also returns pinned entries
        // beyond the requested 30 ordinary dialogs.
        $first=-1000000000100;
        $dialogs=[
            ['_'=>'dialog','pinned'=>true,'peer'=>$first,'top_message'=>501],
            ['_'=>'dialog','pinned'=>true,'peer'=>123456,'top_message'=>502],
        ];
        $messages=[];
        for($i=0;$i<30;$i++) {
            $peer=$i===0?-42:-1000000000200-$i;
            $dialogs[]=['_'=>'dialog','pinned'=>false,
                'peer'=>$peer,'top_message'=>1000+$i];
            $messages[]=['_'=>'message','peer_id'=>$peer,
                'id'=>1000+$i,'date'=>1794000200+$i];
        }
        $raw=['_'=>'messages.dialogsSlice',
            'dialogs'=>$dialogs,'messages'=>$messages,
            'chats'=>[
                ['_'=>'channel','id'=>$first,'title'=>'SampleTeam oficial',
                    'megagroup'=>true,'left'=>false,'username'=>'SampleGroupA'],
                ['_'=>'chat','id'=>-42,'title'=>'Equipe interna'],
                ['_'=>'channel','id'=>-1000000000205,'title'=>'SampleGroupB equipe',
                    'megagroup'=>true,'left'=>false,'username'=>'SampleGroupB'],
                ['_'=>'channel','id'=>-1000000000208,'title'=>'Canal de notícias',
                    'megagroup'=>false,'broadcast'=>true],
            ]];
        $resolve=static function (array $peer): never {
            throw new \RuntimeException('No conversion is needed for normalized peer IDs');
        };
        $result=MadelineTelegramAdapter::filterDialogPage($raw,$resolve);
        self::assertSame(32,$result['scanned_dialog_count']);
        self::assertSame([$first,-42,-1000000000205],
            array_column($result['groups'],'id'));
        self::assertSame([false,false,false],
            array_column($result['groups'],'recommended'));
        self::assertSame([
            'date'=>1794000229,'id'=>1029,'peer'=>-1000000000229,
        ],$result['next_offset']);
        // The cursor must use the last ordinary dialog, never a pinned item.
    }

    public function test_pinned_items_within_limit_do_not_truncate_an_incomplete_folder(): void
    {
        $dialogs=[];
        $messages=[];
        for($i=0;$i<30;$i++){
            $peer=-1000000000500-$i;
            $dialogs[]=[
                '_'=>'dialog','peer'=>$peer,'top_message'=>800+$i,
                'pinned'=>$i<2,
            ];
            $messages[]=['_'=>'message','id'=>800+$i,
                'peer_id'=>$peer,'date'=>1794000800+$i];
        }
        $raw=[
            '_'=>'messages.dialogsSlice','count'=>3388,
            'dialogs'=>$dialogs,'messages'=>$messages,'chats'=>[],
        ];
        $result=MadelineTelegramAdapter::filterDialogPage(
            $raw,static fn(array $peer): never=>throw new \LogicException('IDs already normalized'),
        );
        self::assertSame(30,$result['scanned_dialog_count']);
        self::assertSame([
            'date'=>1794000829,'id'=>829,'peer'=>-1000000000529,
        ],$result['next_offset']);
    }

    public function test_parser_rejects_more_than_a_bounded_number_of_dialogs(): void
    {
        $oversized=array_fill(0,30+MadelineTelegramAdapter::MAX_PINNED+1,[
            '_'=>'dialog','peer'=>123,'top_message'=>1,'pinned'=>true,
        ]);
        $this->expectException(\RuntimeException::class);
        MadelineTelegramAdapter::filterDialogPage([
            'dialogs'=>$oversized,'chats'=>[],
        ],static fn(array $peer): int=>123);
    }

    public function test_telegram_dialogs_are_bounded_and_only_exact_public_group_usernames_are_suggested(): void
    {
        $chats=[
            ['_'=>'channel','id'=>10,'title'=>'SampleTeam Comunidade',
             'megagroup'=>true,'username'=>'SampleGroupA'],
            ['_'=>'channel','id'=>11,'title'=>'Equipe SampleGroupB',
             'megagroup'=>true,'username'=>'SampleGroupB'],
            ['_'=>'channel','id'=>12,'title'=>'SampleGroupB',
             'megagroup'=>true,'username'=>'impostorSampleGroupB'],
            ['_'=>'channel','id'=>13,'title'=>'SampleGroupB',
             'megagroup'=>false,'username'=>'SampleGroupB'],
            ['_'=>'channel','id'=>14,'title'=>'SampleTeam',
             'megagroup'=>true,'username'=>'SampleGroupA','left'=>true],
            ['_'=>'chat','id'=>15,'title'=>'Grupo de trabalho'],
        ];
        $dialogs=[];
        $messages=[];
        foreach(range(10,39) as $id){
            $peer=$id===15?['_'=>'peerChat','chat_id'=>15]:['_'=>'peerChannel','channel_id'=>$id];
            $dialogs[]=['_'=>'dialog','peer'=>$peer,'top_message'=>$id+100];
            $messages[]=['_'=>'message','peer_id'=>$peer,
                'id'=>$id+100,'date'=>1794000000+$id];
        }
        $raw=['dialogs'=>$dialogs,'messages'=>$messages,'chats'=>$chats];
        $resolve=static fn(array $peer): int=>
            ($peer['_']??null)==='peerChat'?
                -(int)$peer['chat_id']:-1000000000000-(int)$peer['channel_id'];
        $page=MadelineTelegramAdapter::filterDialogPage($raw,$resolve);
        self::assertSame(30,$page['scanned_dialog_count']);
        self::assertCount(4,$page['groups']);
        self::assertSame([
            false,false,false,false,
        ],array_column($page['groups'],'recommended'));
        self::assertSame(
            ['date'=>1794000039,'id'=>139,'peer'=>-1000000000039],
            $page['next_offset']
        );
        foreach($page['groups'] as $group){
            self::assertSame(['id','title','recommended','archived'],array_keys($group));
        }
    }

    public function test_large_catalog_is_retrieved_only_a_page_at_a_time(): void
    {
        $fake=new class {
            public int $calls=0;
            public int $account=38291;
            public function selfId(): int{return $this->account;}
            public function groupsPage(?array $offset=null): array
            {
                $this->calls++;
                $page=$offset===null?0:$offset['id'];
                $items=[];
                for($i=0;$i<30;$i++){
                    $id=-1000000000000-$page*30-$i-1;
                    $items[]=['id'=>$id,'title'=>"Grupo $page-$i",
                        'recommended'=>$page===0&&$i===5];
                }
                return [
                    'groups'=>$items,
                    'scanned_dialog_count'=>30,
                    'next_offset'=>['date'=>1794000000,'id'=>$page+1,'peer'=>-1000000000000],
                ];
            }
            public function groups(): never{
                throw new \RuntimeException('Full catalog must not be fetched');
            }
            public function history(): never{
                throw new \RuntimeException('Messages must not be fetched');
            }
        };
        $selection=new TelegramGroupSelection($this->store);
        $first=$selection->page(null,$fake);
        self::assertCount(30,$first['groups']);
        self::assertSame(1,$fake->calls);
        self::assertFalse($first['suggestions_allowed']);
        self::assertTrue($first['groups'][5]['recommended']);
        $next=$selection->page($first['next_cursor'],$fake);
        self::assertCount(30,$next['groups']);
        self::assertSame(2,$fake->calls);
        self::assertNotSame($first['groups'][0]['id'],$next['groups'][0]['id']);
        self::assertIsString($next['next_cursor']);
        // No need to allocate the other thousands of dialogs in the account.
        self::assertSame(60,
            count($first['groups'])+count($next['groups']));
    }

    public function test_repeated_pagination_cursor_is_detected_without_scanning_forever(): void
    {
        $fake=new class {
            public int $calls=0;
            public function selfId(): int{return 12345;}
            public function groupsPage(?array $offset=null): array{
                $this->calls++;
                return ['groups'=>[],'scanned_dialog_count'=>30,
                    'next_offset'=>['date'=>1794000000,'id'=>10,'peer'=>-10044]];
            }
        };
        $selection=new TelegramGroupSelection($this->store);
        $cursor=$selection->page(null,$fake)['next_cursor'];
        try {
            $selection->page($cursor,$fake);
            self::fail('An unchanged Telegram cursor must not cause unbounded page requests');
        } catch(\RuntimeException $e) {
            self::assertStringContainsString('não avançou',$e->getMessage());
            self::assertSame(2,$fake->calls);
        }
    }

    public function test_cursor_is_account_bound_and_rejects_tampering_and_expiry(): void
    {
        $fake=new class {
            public int $account=90210;
            public function selfId(): int{return $this->account;}
            public function groupsPage(?array $offset=null): array{
                return ['groups'=>[], 'scanned_dialog_count'=>30,
                    'next_offset'=>['date'=>1794000000,'id'=>12,'peer'=>-100123]];
            }
        };
        $selection=new TelegramGroupSelection($this->store);
        $cursor=$selection->page(null,$fake)['next_cursor'];
        self::assertIsString($cursor);
        $invalid=substr($cursor,0,-3).'bad';
        try {
            $selection->page($invalid,$fake);
            self::fail('Tampering must not be accepted');
        }catch(InvalidArgumentException){}
        $fake->account=90211;
        try {
            $selection->page($cursor,$fake);
            self::fail('Different accounts must not share cursors');
        }catch(InvalidArgumentException){}
        $fake->account=90210;
        $expired=\Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'v'=>1,'account'=>90210,'folder'=>0,'issued'=>time()-3600,
            'offset'=>['date'=>1794000000,'id'=>12,'peer'=>-100123],
        ],JSON_THROW_ON_ERROR));
        $this->expectException(InvalidArgumentException::class);
        $selection->page($expired,$fake);
    }

    public function test_existing_group_approval_never_adds_new_suggested_groups(): void
    {
        $fake=$this->groupClient();
        $profile=$this->store->profile()->toArray();
        $profile['work_messenger_dialog_ids']=[-1001234567];
        $this->store->saveProfile(new Profile($profile));
        $page=(new TelegramGroupSelection($this->store))->page(null,$fake);
        self::assertSame([-1001234567],$page['selected_ids']);
        self::assertFalse($page['suggestions_allowed'],
            'Older profiles can still receive one-time suggestions');
        $profile['work_messenger_recommendations_reviewed']=true;
        $this->store->saveProfile(new Profile($profile));
        $page=(new TelegramGroupSelection($this->store))->page(null,$fake);
        self::assertFalse($page['suggestions_allowed']);
    }

    public function test_folders_are_independently_paged_and_a_cursor_cannot_cross_archives(): void
    {
        $client=new class {
            public array $folderCalls=[];
            public function selfId(): int{return 112233;}
            public function groupsPage(?array $offset=null,int $folder=0): array
            {
                $this->folderCalls[]=$folder;
                return [
                    'groups'=>[['id'=>-100123,'title'=>'Projeto',
                        'recommended'=>false,'archived'=>$folder===1]],
                    'scanned_dialog_count'=>30,
                    'next_offset'=>['date'=>1794000000,
                        'id'=>(int)($offset['id']??0)+1,'peer'=>-100123],
                ];
            }
        };
        $picker=new TelegramGroupSelection($this->store);
        $active=$picker->page(null,$client,0);
        $archived=$picker->page(null,$client,1);
        self::assertSame([0,1],$client->folderCalls);
        self::assertFalse($active['groups'][0]['archived']);
        self::assertTrue($archived['groups'][0]['archived']);
        self::assertNotSame($active['next_cursor'],$archived['next_cursor']);
        self::assertSame(1,$archived['folder']);
        try {
            $picker->page($active['next_cursor'],$client,1);
            self::fail('A cursor for active chats must not browse archived chats');
        } catch (InvalidArgumentException) {
            self::assertSame([0,1],$client->folderCalls);
        }
    }

    public function test_explicit_empty_selection_disables_scanning_and_dismisses_suggestions(): void
    {
        $client=$this->groupClient();
        $this->store->setSources(['telegram'=>true]);
        $this->store->saveProfile(new Profile([
            'profile_id'=>'worker_1','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>4,'activity_id'=>6,
            'work_messenger_dialog_ids'=>[-1001234567],
            'work_messenger_group_titles'=>['-1001234567'=>'Equipe de trabalho'],
        ]));
        $saved=(new TelegramGroupSelection($this->store))->select([],$client);
        self::assertSame(0,$saved['selected_count']);
        self::assertFalse($this->store->sources()['telegram']);
        self::assertSame([],$this->store->profile()->get('work_messenger_dialog_ids'));
        self::assertSame([],$this->store->profile()->get('work_messenger_group_titles'));
        self::assertTrue($this->store->profile()->get('work_messenger_recommendations_reviewed'));
        $page=(new TelegramGroupSelection($this->store))->page(null,$client);
        self::assertFalse($page['suggestions_allowed']);
    }

    public function test_profile_group_title_cache_is_restricted_to_selected_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Profile([
            'profile_id'=>'worker_1','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>4,'activity_id'=>6,
            'work_messenger_dialog_ids'=>[-1001234567],
            'work_messenger_group_titles'=>['-1009999999'=>'Name from another chat'],
        ]);
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
