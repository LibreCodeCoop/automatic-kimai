<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\NextcloudLoginFlow;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NextcloudLoginTest extends TestCase
{
    private string $dir;
    private LocalStore $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-nextcloud-login-'.bin2hex(random_bytes(6));
        $this->local=new LocalStore($this->dir);
        $this->app->instance(LocalStore::class,$this->local);
        $this->local->setSources(['nextcloud_calendar'=>true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $file) {
            if (is_file($file))@unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function flowResponse(string $origin='https://cloud.example.com'): array
    {
        return [
            'poll'=>[
                'token'=>'abcdefghijklmnopqrstuvwxyz1234567890',
                'endpoint'=>$origin.'/login/v2/poll',
            ],
            'login'=>$origin.'/login/v2/flow/'.str_repeat('Z',40),
        ];
    }

    public function test_flow_starts_in_web_browser_without_password_or_cookies(): void
    {
        Http::fake([
            'https://cloud.example.com/index.php/login/v2'=>Http::response($this->flowResponse()),
        ]);
        $this->postJson('http://localhost/nextcloud/start',[
            'url'=>'https://cloud.example.com'
        ])->assertOk()->assertJsonPath('status','waiting')
            ->assertJsonPath('login_url','https://cloud.example.com/login/v2/flow/'.str_repeat('Z',40))
            ->assertDontSee('abcdefghijklmnopqrstuvwxyz1234567890');
        $pending=$this->local->read('nextcloud-login.json');
        self::assertArrayHasKey('poll_token',$pending);
        self::assertFileExists($this->local->root().'/nextcloud-login.json');
        self::assertSame(0,fileperms($this->local->root().'/nextcloud-login.json')&0077);
        Http::assertSentCount(1);
        $this->postJson('http://localhost/nextcloud/start',[
            'url'=>'https://cloud.example.com'
        ])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_poll_waits_then_verifies_identity_and_saves_per_device_credentials(): void
    {
        Http::fake([
            'https://cloud.example.com/index.php/login/v2'=>Http::response($this->flowResponse()),
            'https://cloud.example.com/login/v2/poll'=>Http::sequence()
                ->push('Not found',404)
                ->push(['server'=>'https://cloud.example.com','loginName'=>'person@example.com',
                    'appPassword'=>'secure-app-password'],200),
            'https://cloud.example.com/ocs/v2.php/cloud/user?format=json'=>Http::response([
                'ocs'=>['meta'=>['statuscode'=>200],'data'=>['id'=>'person']],
            ]),
        ]);
        $this->postJson('http://localhost/nextcloud/start',['url'=>'https://cloud.example.com'])->assertOk();
        $this->postJson('http://localhost/nextcloud/poll')->assertOk()->assertJsonPath('status','waiting');
        $this->postJson('http://localhost/nextcloud/poll')->assertOk()->assertJsonPath('status','connected')
            ->assertJsonPath('username','person')->assertDontSee('secure-app-password');
        $creds=$this->local->credentials();
        self::assertSame('person',$creds['NEXTCLOUD_USERNAME']);
        self::assertSame('person@example.com',$creds['NEXTCLOUD_LOGIN_NAME']);
        self::assertSame('secure-app-password',$creds['NEXTCLOUD_APP_PASSWORD']);
        self::assertSame([], $this->local->read('nextcloud-login.json'));
        self::assertTrue($this->local->sources()['nextcloud_calendar']);
        self::assertFalse($this->local->sources()['nextcloud_talk']);
        $this->get('http://localhost/nextcloud/status')->assertOk()
            ->assertJsonPath('connected',true)->assertDontSee('secure-app-password')
            ->assertDontSee('abcdefghijklmnopqrstuvwxyz1234567890');
    }

    public function test_connected_account_is_primary_and_reconnection_is_collapsed(): void
    {
        $this->local->saveCredentials([
            'NEXTCLOUD_URL'=>'https://cloud.example.com',
            'NEXTCLOUD_USERNAME'=>'member-uid',
            'NEXTCLOUD_LOGIN_NAME'=>'member@example.com',
            'NEXTCLOUD_APP_PASSWORD'=>'private-test-app-password',
        ]);
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Conta Nextcloud conectada')
            ->assertSee('member@example.com')
            ->assertSee('https://cloud.example.com')
            ->assertSee('Entrar com outra conta')
            ->assertSee('id="nc-reconnect"',false)
            ->assertDontSee('private-test-app-password');
        $this->get('http://localhost/?view=integrations')->assertOk()
            ->assertSee('Conectada: member@example.com')
            ->assertDontSee('private-test-app-password');
    }

    public function test_nextcloud_switches_save_individually_and_preserve_unrelated_sources(): void
    {
        $this->local->setSources(['github'=>true,'nextcloud_talk'=>true]);
        foreach (['nextcloud_activity','nextcloud_mail'] as $key) {
            $this->postJson('http://localhost/nextcloud/source',[
                'source'=>$key,'enabled'=>true,
            ])->assertOk()->assertJsonPath('sources.'.$key,true);
        }
        self::assertTrue($this->local->sources()['github']);
        self::assertTrue($this->local->sources()['nextcloud_calendar']);
        self::assertTrue($this->local->sources()['nextcloud_talk']);
        $this->postJson('http://localhost/nextcloud/source',[
            'source'=>'nextcloud_talk','enabled'=>false,
        ])->assertOk()->assertJsonPath('sources.nextcloud_talk',false)
            ->assertJsonPath('sources.nextcloud_activity',true)
            ->assertJsonPath('sources.nextcloud_mail',true);
        self::assertTrue($this->local->sources()['github']);
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Coletar dados do Activity')
            ->assertSee('Coletar dados do Mail')
            ->assertSee('role="switch"',false)
            ->assertDontSee('Alterar fontes de dados');
    }

    public function test_source_switch_rejects_invalid_choices_and_remote_hosts(): void
    {
        $this->postJson('http://localhost/nextcloud/source',[
            'source'=>'telegram','enabled'=>true,
        ])->assertUnprocessable();
        $this->postJson('http://localhost/nextcloud/source',[
            'source'=>'nextcloud_activity','enabled'=>'not-a-boolean',
        ])->assertUnprocessable();
        $this->postJson('http://public.example/nextcloud/source',[
            'source'=>'nextcloud_mail','enabled'=>true,
        ])->assertForbidden();
        self::assertFalse($this->local->sources()['nextcloud_mail']);
        self::assertFalse($this->local->sources()['nextcloud_activity']);
    }

    public function test_disconnect_removes_only_local_nextcloud_credentials_and_pending_login(): void
    {
        $this->local->setSources(['nextcloud_activity'=>true,'nextcloud_mail'=>true,'github'=>true]);
        $this->local->saveCredentials([
            'NEXTCLOUD_URL'=>'https://cloud.example.com',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_LOGIN_NAME'=>'worker@example.com',
            'NEXTCLOUD_APP_PASSWORD'=>'private-test-password',
            'GITHUB_TOKEN'=>'github-test-not-real',
            'KIMAI_API_TOKEN'=>'kimai-test-not-real',
        ]);
        $this->local->write('nextcloud-login.json',['login_url'=>'https://example.com/pending']);
        $this->postJson('http://localhost/nextcloud/disconnect')->assertOk()
            ->assertJsonPath('status','disconnected')
            ->assertDontSee('private-test-password');
        $credentials=$this->local->credentials();
        self::assertArrayNotHasKey('NEXTCLOUD_URL',$credentials);
        self::assertArrayNotHasKey('NEXTCLOUD_APP_PASSWORD',$credentials);
        self::assertArrayNotHasKey('NEXTCLOUD_LOGIN_NAME',$credentials);
        self::assertSame('github-test-not-real',$credentials['GITHUB_TOKEN']);
        self::assertSame('kimai-test-not-real',$credentials['KIMAI_API_TOKEN']);
        self::assertTrue($this->local->sources()['nextcloud_mail']);
        self::assertTrue($this->local->sources()['nextcloud_activity']);
        self::assertSame([],$this->local->read('nextcloud-login.json'));
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Conectar pelo navegador')->assertDontSee('id="nc-connected"',false);
    }

    public function test_missing_account_profile_and_avatar_fail_without_network_or_secrets(): void
    {
        $this->get('http://localhost/nextcloud/profile')->assertStatus(503)
            ->assertDontSee('password');
        $this->get('http://localhost/nextcloud/avatar')->assertNotFound();
        $this->get('http://public.example/nextcloud/avatar')->assertForbidden();
    }

    public function test_existing_account_remains_manageable_with_all_nextcloud_sources_disabled(): void
    {
        $this->local->saveCredentials([
            'NEXTCLOUD_URL'=>'https://cloud.example.com',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_APP_PASSWORD'=>'test-only-password',
        ]);
        $this->local->setSources([
            'nextcloud_calendar'=>false,'nextcloud_talk'=>false,
            'nextcloud_activity'=>false,'nextcloud_mail'=>false,
        ]);
        $this->get('http://localhost/?view=nextcloud')->assertOk()
            ->assertSee('Entrar com outra conta');
        $this->get('http://localhost/nextcloud/status')->assertOk()
            ->assertJsonPath('connected',true);
        Http::fake(['https://cloud.example.com/index.php/login/v2'=>
            Http::response($this->flowResponse())]);
        $this->postJson('http://localhost/nextcloud/start',[
            'url'=>'https://cloud.example.com',
        ])->assertOk()->assertJsonPath('status','waiting');
    }

    public function test_refuses_unsafe_origins_and_cross_site_login_links(): void
    {
        Http::fake([
            'https://cloud.example.com/index.php/login/v2'=>Http::response(
                $this->flowResponse('https://evil.example.org')),
        ]);
        foreach ([
            'http://cloud.example.com',
            'https://user:pass@cloud.example.com',
            'https://cloud.example.com?token=abc',
            'https://localhost',
        ] as $url) {
            $this->postJson('http://localhost/nextcloud/start',['url'=>$url])->assertStatus(422);
        }
        $this->postJson('http://localhost/nextcloud/start',[
            'url'=>'https://cloud.example.com'
        ])->assertStatus(422)->assertDontSee('abcdefghijklmnopqrstuvwxyz1234567890');
        self::assertSame([], $this->local->read('nextcloud-login.json'));
    }

    public function test_rejects_ipv6_loopback_before_network_access(): void
    {
        $this->expectException(\RuntimeException::class);
        NextcloudLoginFlow::normalizeServer('https://[::1]');
    }

    public function test_manual_app_password_is_verified_and_stored_separately_from_dav_uid(): void
    {
        Http::fake([
            'https://cloud.example.com/ocs/v2.php/cloud/user?format=json'=>Http::response([
                'ocs'=>['meta'=>['statuscode'=>200],'data'=>['id'=>'member']],
            ]),
        ]);
        $this->post('http://localhost/connectors',[
            'nextcloud_url'=>'https://cloud.example.com',
            'nextcloud_user'=>'member@example.com',
            'nextcloud_password'=>'app-password-for-tests',
        ])->assertRedirect();
        $creds=$this->local->credentials();
        self::assertSame('member',$creds['NEXTCLOUD_USERNAME']);
        self::assertSame('member@example.com',$creds['NEXTCLOUD_LOGIN_NAME']);
        self::assertSame('app-password-for-tests',$creds['NEXTCLOUD_APP_PASSWORD']);
    }

    public function test_manual_nextcloud_login_for_backup_does_not_activate_collection_sources(): void
    {
        $this->local->setSources([
            'nextcloud_calendar'=>false,'nextcloud_talk'=>false,
            'nextcloud_activity'=>false,'nextcloud_mail'=>false,
        ]);
        Http::fake([
            'https://cloud.example.com/ocs/v2.php/cloud/user?format=json'=>Http::response([
                'ocs'=>['meta'=>['statuscode'=>200],'data'=>['id'=>'member']],
            ]),
        ]);
        $this->post('http://localhost/connectors',[
            'nextcloud_url'=>'https://cloud.example.com',
            'nextcloud_user'=>'member@example.com',
            'nextcloud_password'=>'app-password-for-tests',
        ])->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('member',$this->local->credentials()['NEXTCLOUD_USERNAME']);
        self::assertFalse($this->local->sources()['nextcloud_calendar']);
        self::assertFalse($this->local->sources()['nextcloud_talk']);
    }

    public function test_manual_app_password_does_not_save_an_unverified_connection(): void
    {
        Http::fake([
            'https://cloud.example.com/ocs/v2.php/cloud/user?format=json'=>Http::response('Unauthorized',401),
        ]);
        $this->post('http://localhost/connectors',[
            'nextcloud_url'=>'https://cloud.example.com',
            'nextcloud_user'=>'member',
            'nextcloud_password'=>'invalid-value',
        ])->assertRedirect()->assertSessionHasErrors();
        self::assertSame([], $this->local->credentials());
    }

    public function test_unknown_or_expired_flow_never_polls(): void
    {
        Http::fake();
        $this->postJson('http://localhost/nextcloud/poll')->assertStatus(422);
        $this->local->write('nextcloud-login.json',[
            'server'=>'https://cloud.example.com',
            'poll_endpoint'=>'https://cloud.example.com/login/v2/poll',
            'poll_token'=>'secret',
            'login_url'=>'https://cloud.example.com/login/v2/flow/token',
            'started_at'=>time()-1210,
        ]);
        $this->postJson('http://localhost/nextcloud/poll')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_nextcloud_can_authenticate_for_backup_without_enabling_collection(): void
    {
        $this->local->setSources(['nextcloud_calendar'=>false,'nextcloud_talk'=>false]);
        Http::fake([
            'https://cloud.example.com/index.php/login/v2'=>Http::response($this->flowResponse()),
            'https://cloud.example.com/login/v2/poll'=>Http::response('pending',404),
        ]);
        $this->postJson('http://localhost/nextcloud/start',['url'=>'https://cloud.example.com'])
            ->assertOk()->assertJsonPath('status','waiting');
        $this->get('http://localhost/nextcloud/status')->assertOk()->assertJsonPath('connected',false);
        $this->postJson('http://localhost/nextcloud/poll')
            ->assertOk()->assertJsonPath('status','waiting');
        self::assertFalse($this->local->sources()['nextcloud_calendar']);
        self::assertFalse($this->local->sources()['nextcloud_talk']);
    }

    public function test_server_side_flow_blocks_remote_hosts(): void
    {
        Http::fake();
        $this->postJson('http://public.example/nextcloud/start',['url'=>'https://cloud.example.com'])
            ->assertForbidden();
        Http::assertNothingSent();
    }
}
