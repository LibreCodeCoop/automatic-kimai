<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\HttpTransport;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\SyncEngine;
use DateTimeImmutable;
use ReflectionMethod;
use Tests\TestCase;

final class OptionalNextcloudSourcesTest extends TestCase
{
    private string $dir;
    private LocalStore $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-optional-nextcloud-'.bin2hex(random_bytes(6));
        $this->local=new LocalStore($this->dir);
        $this->app->instance(LocalStore::class,$this->local);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $file) {
            if (is_file($file))@unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_unconfigured_optional_sources_are_reported_without_network_or_cycle_failure(): void
    {
        $this->local->setSources([
            'nextcloud_calendar'=>true,
            'nextcloud_talk'=>true,
            'nextcloud_mail'=>true,
            'nextcloud_activity'=>true,
        ]);
        $this->local->write('nextcloud_talk_context.json',[
            'own_actions'=>[['utc'=>'2026-11-14T11:00:00Z']],
        ]);
        $this->local->write('nextcloud_activity_context.json',[
            'own_actions'=>[['utc'=>'2026-11-14T11:00:00Z']],
        ]);
        $this->local->write('nextcloud_mail_context.json',[
            'sent_actions'=>[['utc'=>'2026-11-14T12:00:00Z']],
        ]);
        $this->local->write('telegram_scoped_history.json',[
            'scoped_client_chats'=>[
                ['observations'=>[['timestamp_utc'=>'2026-11-14T10:00:00Z','outgoing'=>true]]],
            ],
        ]);
        $this->local->write('github_context.json',[
            'events'=>[['timestamp_utc'=>'2026-11-14T10:00:00Z','kind'=>'IssueCommentEvent']],
        ]);
        $requests=0;
        $http=new HttpTransport(static function()use(&$requests) {
            $requests++;
            throw new \RuntimeException('Unexpected request');
        });
        $engine=new SyncEngine($this->local,null,$http);
        $profile=new Profile([
            'profile_id'=>'worker',
            'timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
        ]);
        $external=new ReflectionMethod(SyncEngine::class,'external');
        $sourceStatuses=$external->invoke($engine,$profile,[],
            new DateTimeImmutable('2026-11-14T12:00:00-03:00'));
        self::assertSame(
            ['status'=>'needs_connection','events'=>0],
            $sourceStatuses['nextcloud_calendar']
        );
        self::assertSame(
            ['status'=>'needs_connection','own_actions'=>0],
            $sourceStatuses['nextcloud_talk']
        );
        self::assertSame(
            ['status'=>'needs_connection','own_actions'=>0],
            $sourceStatuses['nextcloud_activity']
        );
        self::assertSame(
            ['status'=>'needs_connection','sent_actions'=>0],
            $sourceStatuses['nextcloud_mail']
        );
        self::assertSame(0,$requests);
        $anchors=new ReflectionMethod(SyncEngine::class,'actionAnchors');
        self::assertSame([], $anchors->invoke($engine,$profile,$sourceStatuses),
            'Disabled or unavailable provider metadata must not imply human activity');
        $response=$this->get('http://localhost/?view=overview')->assertOk();
        $response->assertSee('Nextcloud ainda não conectado.');
        $response->assertSee('conecte sua conta pelo navegador');
    }

    public function test_new_sources_collect_only_on_opt_in_and_are_never_used_from_stale_cache(): void
    {
        $this->local->saveCredentials([
            'NEXTCLOUD_URL'=>'https://cloud.example.org',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_LOGIN_NAME'=>'worker@example.org',
            'NEXTCLOUD_APP_PASSWORD'=>'test-only-secret',
        ]);
        $this->local->write('nextcloud_activity_context.json',[
            'own_actions'=>[['utc'=>'2026-11-14T11:00:00Z']],
        ]);
        $this->local->write('nextcloud_mail_context.json',[
            'sent_actions'=>[['utc'=>'2026-11-14T12:00:00Z']],
        ]);
        $requests=0;
        $http=new HttpTransport(static function($method,$url)use(&$requests) {
            $requests++;
            if (str_contains($url,'apps/activity'))return [
                'status'=>200,'body'=>json_encode([
                    'ocs'=>['meta'=>['statuscode'=>200],'data'=>[
                        ['activity_id'=>9,'datetime'=>gmdate(DATE_ATOM,1794654000),'user'=>'worker',
                            'app'=>'files','type'=>'file_changed','subject'=>'private'],
                    ]],
                ],JSON_THROW_ON_ERROR),
            ];
            if (str_contains($url,'/api/accounts'))return [
                'status'=>200,'body'=>json_encode([[
                    'sentMailboxId'=>5,'isDelegated'=>false,
                ]],JSON_THROW_ON_ERROR),
            ];
            if (str_contains($url,'mailboxId=5'))return [
                'status'=>200,'body'=>json_encode([[
                    'databaseId'=>8,'dateInt'=>1794654000,
                    'subject'=>'private subject','previewText'=>'private preview',
                ]],JSON_THROW_ON_ERROR),
            ];
            throw new \RuntimeException('Unexpected read');
        });
        $engine=new SyncEngine($this->local,null,$http);
        $profile=new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
        ]);
        $time=new DateTimeImmutable('2026-11-15T12:00:00Z');
        $external=new ReflectionMethod(SyncEngine::class,'external');
        $anchors=new ReflectionMethod(SyncEngine::class,'actionAnchors');
        $this->local->setSources(['nextcloud_activity'=>true,'nextcloud_mail'=>true]);
        $status=$external->invoke($engine,$profile,$this->local->credentials(),$time);
        self::assertSame('ok',$status['nextcloud_activity']['status']);
        self::assertSame('ok',$status['nextcloud_mail']['status']);
        self::assertSame(3,$requests);
        self::assertCount(2,$anchors->invoke($engine,$profile,$status));
        self::assertStringNotContainsString('private',
            json_encode($this->local->read('nextcloud_activity_context.json')));
        self::assertStringNotContainsString('private',
            json_encode($this->local->read('nextcloud_mail_context.json')));
        $this->local->setSources(['nextcloud_activity'=>false,'nextcloud_mail'=>false]);
        $requests=0;
        $disabled=$external->invoke($engine,$profile,$this->local->credentials(),$time);
        self::assertArrayNotHasKey('nextcloud_activity',$disabled);
        self::assertArrayNotHasKey('nextcloud_mail',$disabled);
        self::assertSame(0,$requests);
        self::assertSame([],$anchors->invoke($engine,$profile,$disabled));
    }

    public function test_source_selection_can_precede_nextcloud_login_without_creating_credentials(): void
    {
        $this->post('http://localhost/sources',['source_nextcloud_talk'=>'1'])
            ->assertRedirect();
        self::assertTrue($this->local->sources()['nextcloud_talk']);
        self::assertSame([], $this->local->credentials());
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Conectar pelo navegador');
    }

}
