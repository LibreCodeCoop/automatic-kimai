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
        ]);
        $this->local->write('nextcloud_talk_context.json',[
            'own_actions'=>[['utc'=>'2026-11-14T11:00:00Z']],
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
        self::assertSame(0,$requests);
        $anchors=new ReflectionMethod(SyncEngine::class,'actionAnchors');
        self::assertSame([], $anchors->invoke($engine,$profile,$sourceStatuses),
            'Disabled or unavailable provider metadata must not imply human activity');
        $response=$this->get('http://localhost/?view=overview')->assertOk();
        $response->assertSee('Nextcloud ainda não conectado.');
        $response->assertSee('conecte sua conta pelo navegador');
    }

    public function test_nextcloud_cannot_be_enabled_from_ui_without_a_connected_account(): void
    {
        $this->post('http://localhost/nextcloud/sources',[
            'calendar'=>'1','talk'=>'1',
        ])->assertRedirect()->assertSessionHasErrors();
        self::assertFalse($this->local->sources()['nextcloud_calendar']);
        self::assertFalse($this->local->sources()['nextcloud_talk']);
    }
}
