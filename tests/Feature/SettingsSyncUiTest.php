<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\SettingsSyncService;
use Tests\TestCase;

final class SettingsSyncUiTest extends TestCase
{
    private string $privateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateDir=sys_get_temp_dir().'/ak-sync-ui-'.bin2hex(random_bytes(5));
        $this->app->instance(LocalStore::class,new LocalStore($this->privateDir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->privateDir.'/*')?:[] as $f)if (is_file($f))@unlink($f);
        @rmdir($this->privateDir);
        parent::tearDown();
    }

    public function test_settings_are_visible_even_without_nextcloud_connection(): void
    {
        $this->get('http://localhost/?view=settings_sync')->assertOk()
            ->assertSee('Backup e sincronização')
            ->assertSee('Conectar minha conta Nextcloud')
            ->assertSee('Telegram')
            ->assertDontSee('name="recovery_password"',false);
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Conectar pelo navegador')
            ->assertSee('Configurar backup e sincronização');
    }

    public function test_enabled_page_and_posts_are_protected_without_exposing_secrets(): void
    {
        $store=$this->app->make(LocalStore::class);
        $store->saveCredentials([
            'NEXTCLOUD_URL'=>'https://cloud.example.invalid',
            'NEXTCLOUD_USERNAME'=>'example','NEXTCLOUD_LOGIN_NAME'=>'example',
            'NEXTCLOUD_APP_PASSWORD'=>'private-app-password-for-fixtures',
        ]);
        $this->get('http://localhost/?view=settings_sync')->assertOk()
            ->assertSee('name="recovery_password"',false)
            ->assertSee('Incluir credenciais das integrações')
            ->assertDontSee('private-app-password-for-fixtures');

        $this->post('http://localhost/settings-sync/enable',[
            'recovery_password'=>'weak','confirmed'=>'1',
        ])->assertSessionHasErrors('recovery_password');
        self::assertFalse($this->app->make(SettingsSyncService::class)->status()['enabled']);
        $this->post('http://public.example/settings-sync/enable',[
            'recovery_password'=>str_repeat('x',20),'confirmed'=>1,
        ])->assertForbidden();
        $this->post('http://localhost/settings-sync/run')->assertRedirect()
            ->assertSessionHasErrors('backup');
    }
}
