<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use Tests\TestCase;

final class SourcesNavigationTest extends TestCase
{
    private string $dir;
    private LocalStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-source-nav-'.bin2hex(random_bytes(6));
        $this->store=new LocalStore($this->dir);
        $this->app->instance(LocalStore::class,$this->store);
        $this->store->setSources([
            'github'=>false,'nextcloud_talk'=>false,'nextcloud_calendar'=>false,
            'telegram'=>false,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $file)if (is_file($file))@unlink($file);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function href(string $view): string
    {
        return 'href="http://localhost?view='.$view.'"';
    }

    public function test_disabled_integrations_are_absent_from_sidebar_and_connections_hub(): void
    {
        foreach (['overview','integrations','sources'] as $view) {
            $html=$this->get('http://localhost/?view='.$view)->assertOk()->getContent();
            foreach (['nextcloud','telegram','github'] as $provider) {
                self::assertStringNotContainsString($this->href($provider),$html);
            }
        }
        // Nextcloud login is available independently for encrypted backup,
        // but opening the connection UI never enables any data collector.
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Conectar pelo navegador')
            ->assertSee('Configurar backup e sincronização')
            ->assertDontSee('name="github_token"',false);
        foreach (['telegram','github'] as $provider) {
            $this->get('http://localhost/?view='.$provider)->assertOk()
                ->assertSee('Fontes de dados')
                ->assertDontSee('name="nextcloud_password"',false)
                ->assertDontSee('name="github_token"',false);
        }
        self::assertFalse($this->store->sources()['nextcloud_calendar']);
        self::assertFalse($this->store->sources()['nextcloud_talk']);
    }

    public function test_nextcloud_becomes_visible_if_either_calendar_or_talk_is_enabled(): void
    {
        foreach (['nextcloud_calendar','nextcloud_talk','nextcloud_activity','nextcloud_mail'] as $key) {
            $this->store->setSources([
                'nextcloud_calendar'=>false,'nextcloud_talk'=>false,
                'nextcloud_activity'=>false,'nextcloud_mail'=>false,
            ]);
            $this->store->setSources([$key=>true]);
            $html=$this->get('http://localhost/?view=integrations')->assertOk()->getContent();
            self::assertStringContainsString($this->href('nextcloud'),$html);
            self::assertStringNotContainsString($this->href('github'),$html);
            $this->get('http://localhost/?view=nextcloud')->assertOk()->assertSee('Conectar pelo navegador');
        }
    }

    public function test_sources_controls_connections_without_starting_telegram_collection(): void
    {
        $this->post('http://localhost/sources',[
            'source_github'=>'1',
            'source_nextcloud_calendar'=>'1',
            'source_telegram_setup'=>'1',
        ])->assertRedirect();
        $flags=$this->store->sources();
        self::assertTrue($flags['github']);
        self::assertTrue($flags['nextcloud_calendar']);
        self::assertTrue($flags['telegram_setup']);
        self::assertFalse($flags['telegram'],'Telegram requires verified authentication first');
        $html=$this->get('http://localhost/?view=integrations')->getContent();
        foreach (['github','nextcloud','telegram'] as $view) {
            self::assertStringContainsString($this->href($view),$html);
        }

        // Turning off the source removes its screen but retains credentials for later re-enable.
        $this->store->saveCredentials(['GITHUB_TOKEN'=>'test-not-a-real-token']);
        $this->post('http://localhost/sources',[])->assertRedirect();
        self::assertFalse($this->store->sources()['github']);
        self::assertFalse($this->store->sources()['telegram_setup']);
        self::assertFalse($this->store->sources()['telegram']);
        self::assertSame('test-not-a-real-token',$this->store->credentials()['GITHUB_TOKEN']);
        $this->get('http://localhost/?view=github')->assertOk()
            ->assertDontSee('name="github_token"',false);
    }

    public function test_disabled_connections_cannot_save_new_credentials_or_show_old_alerts(): void
    {
        $this->store->write('review.json',[
            'days'=>[], 'sources'=>['nextcloud_talk'=>['status'=>'unavailable']],
        ]);
        $this->get('http://localhost/?view=overview')->assertOk()
            ->assertDontSee('Verificar conexão Nextcloud');
        $this->post('http://localhost/connectors',[
            'github_token'=>'placeholder-test-token',
        ])->assertRedirect()->assertSessionHasErrors();
        $this->post('http://localhost/connectors',[
            'nextcloud_url'=>'https://cloud.example.org',
            'nextcloud_user'=>'staff',
            'nextcloud_password'=>'placeholder-test-token',
        ])->assertRedirect()->assertSessionHasErrors();
        self::assertSame([], $this->store->credentials());
    }

    public function test_legacy_authenticated_telegram_remains_visible_until_explicit_opt_out(): void
    {
        // Older profiles had just the authenticated collector switch.
        $this->store->write('sources.json',[
            'activitywatch'=>true,'telegram'=>true,
            'nextcloud_calendar'=>false,'nextcloud_talk'=>false,'github'=>false,
        ]);
        self::assertTrue($this->store->sources()['telegram_setup']);
        $page=$this->get('http://localhost/?view=integrations')->assertOk();
        self::assertStringContainsString($this->href('telegram'),$page->getContent());
        $this->post('http://localhost/sources',[])->assertRedirect();
        self::assertFalse($this->store->sources()['telegram_setup']);
        self::assertFalse($this->store->sources()['telegram']);
        $page=$this->get('http://localhost/?view=integrations')->assertOk();
        self::assertStringNotContainsString($this->href('telegram'),$page->getContent());
    }

    public function test_current_page_has_one_active_link_and_sidebar_control(): void
    {
        $this->store->setSources(['github'=>true]);
        $page=$this->get('http://localhost/?view=github')->assertOk();
        $page->assertSee('id="sidebar-toggle"',false)
            ->assertSee('aria-controls="sidebar-navigation"',false)
            ->assertSee('nav-children',false);
        self::assertSame(1,substr_count($page->getContent(),'aria-current="page"'));
    }
}
