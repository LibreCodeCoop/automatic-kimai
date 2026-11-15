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
        self::assertFalse($this->local->sources()['nextcloud_calendar']);
        self::assertFalse($this->local->sources()['nextcloud_talk']);
        $this->get('http://localhost/nextcloud/status')->assertOk()
            ->assertJsonPath('connected',true)->assertDontSee('secure-app-password')
            ->assertDontSee('abcdefghijklmnopqrstuvwxyz1234567890');
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

    public function test_server_side_flow_blocks_remote_hosts(): void
    {
        Http::fake();
        $this->postJson('http://public.example/nextcloud/start',['url'=>'https://cloud.example.com'])
            ->assertForbidden();
        Http::assertNothingSent();
    }
}
