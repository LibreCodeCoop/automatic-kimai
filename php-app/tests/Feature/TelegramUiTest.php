<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use Tests\TestCase;

final class TelegramUiTest extends TestCase
{
    private string $privateDir;
    private LocalStore $private;

    protected function setUp(): void
    {
        parent::setUp();
        $this->privateDir=sys_get_temp_dir().'/kimai-telegram-ui-'.bin2hex(random_bytes(8));
        $this->private=new LocalStore($this->privateDir);
        $this->app->instance(LocalStore::class,$this->private);
        $this->private->setSources(['telegram_setup'=>true]);
    }

    protected function tearDown(): void
    {
        if (isset($this->privateDir)&&is_dir($this->privateDir)) {
            foreach (glob($this->privateDir.'/*')?:[] as $path) {
                if (is_file($path))@unlink($path);
            }
            @rmdir($this->privateDir);
        }
        parent::tearDown();
    }

    private function seedProfile(): void
    {
        $this->private->saveProfile(new Profile([
            'profile_id'=>'worker_1',
            'timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>4,'activity_id'=>6,
            'work_messenger_labels'=>['Client A']
        ]));
    }

    public function test_panel_exposes_telegram_onboarding_but_no_credentials(): void
    {
        $this->get('http://localhost/?view=telegram')->assertOk()
            ->assertSee('Conecte sua conta no celular')
            ->assertSee('id="tg-groups-step" hidden',false)
            ->assertDontSee('name="telegram_labels"',false)
            ->assertSee('Conectar pelo QR Code')
            ->assertDontSee('TELEGRAM_API_HASH');
    }

    public function test_status_is_private_uncached_and_does_not_leak_secrets(): void
    {
        $response=$this->get('http://localhost/telegram/status');
        $response->assertOk()->assertJsonPath('status','not_configured');
        self::assertStringContainsString('no-store',
            (string)$response->headers->get('Cache-Control'));
        $response->assertDontSee('api_hash');
    }

    public function test_telegram_configuration_does_not_save_or_request_group_names_before_login(): void
    {
        $this->seedProfile();
        $apiHash=str_repeat('a',32);
        $this->post('http://localhost/telegram/configure',[
            'telegram_api_id'=>'123456','telegram_api_hash'=>$apiHash,
            'telegram_labels'=>'Client A, Client B'
        ])->assertRedirect();
        self::assertSame(['Client A'],
            $this->private->profile()->get('work_messenger_labels'));
        self::assertSame($apiHash,
            $this->private->credentials()['TELEGRAM_API_HASH']);
        self::assertSame(false,$this->private->sources()['telegram']);
    }

    public function test_groups_cannot_be_listed_or_selected_without_authentication(): void
    {
        $this->get('http://localhost/telegram/groups')
            ->assertForbidden()->assertDontSee('groups');
        $this->postJson('http://localhost/telegram/groups',[
            'groups'=>['-1001234567'],
        ])->assertForbidden();
        $this->private->setSources(['telegram_setup'=>false]);
        $this->get('http://localhost/telegram/groups')->assertForbidden();
        $this->postJson('http://localhost/telegram/groups',[
            'groups'=>['-1001234567'],
        ])->assertForbidden();
    }

    public function test_password_submission_rejected_without_active_qr_handshake(): void
    {
        $this->postJson('http://localhost/telegram/2fa',['password'=>'not-for-logs'])
            ->assertStatus(422)
            ->assertDontSee('not-for-logs');
        $this->postJson('http://localhost/telegram/activate')
            ->assertStatus(422);
        self::assertFalse($this->private->sources()['telegram']);
    }

    public function test_disabled_telegram_source_prevents_authentication_requests(): void
    {
        $this->private->setSources(['telegram_setup'=>false]);
        $this->postJson('http://localhost/telegram/qr')->assertForbidden();
        $this->postJson('http://localhost/telegram/activate')->assertForbidden();
        $this->post('http://localhost/telegram/configure')->assertForbidden();
    }

    public function test_telegram_endpoints_block_nonlocal_requests(): void
    {
        $this->get('http://public.example/telegram/status')->assertForbidden();
        $this->post('http://public.example/telegram/configure')->assertForbidden();
        $this->postJson('http://public.example/telegram/qr')->assertForbidden();
        $this->get('http://public.example/telegram/groups')->assertForbidden();
    }
}
