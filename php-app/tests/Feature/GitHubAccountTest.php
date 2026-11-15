<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\GitHubIdentity;
use App\AutomaticKimai\HttpTransport;
use App\AutomaticKimai\LocalStore;
use Tests\TestCase;

final class GitHubAccountTest extends TestCase
{
    private LocalStore $store;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-gh-'.bin2hex(random_bytes(7));
        $this->store=new LocalStore($this->dir);
        $this->app->instance(LocalStore::class,$this->store);
        $this->store->setSources(['github'=>true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $file) {
            if(is_file($file))@unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_profile_and_avatar_are_bound_to_individual_token_with_no_remote_token_leak(): void
    {
        $calls=[];
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL2WAAAAABJRU5ErkJggg==',true);
        $identity=new GitHubIdentity(new HttpTransport(static function($method,$url,$headers)use(&$calls,$png){
            $calls[]=[$url,$headers];
            if($url==='https://api.github.com/user')return [
                'status'=>200,'body'=>json_encode([
                    'id'=>1234,'login'=>'example-dev','name'=>'Pessoa Exemplo',
                    'avatar_url'=>'https://attacker.invalid/track',
                    'email'=>'private@host.invalid',
                ],JSON_THROW_ON_ERROR),
            ];
            if($url==='https://avatars.githubusercontent.com/u/1234?s=96&v=4') {
                return ['status'=>200,'body'=>$png];
            }
            throw new \RuntimeException('Untrusted avatar URL used');
        }));
        $creds=['GITHUB_TOKEN'=>'test-private-github-token'];
        self::assertSame(['login'=>'example-dev','display_name'=>'Pessoa Exemplo'],
            $identity->profile($creds));
        self::assertSame('image/png',$identity->avatar($creds)['mime']);
        self::assertCount(3,$calls);
        self::assertSame('Bearer test-private-github-token',$calls[0][1]['Authorization']);
        self::assertArrayNotHasKey('Authorization',$calls[2][1]);
        self::assertStringNotContainsString('private@host.invalid',
            json_encode($identity->profile($creds)));
    }

    public function test_disconnect_clears_only_github_secret_and_keeps_nextcloud_and_sources(): void
    {
        $this->store->saveCredentials([
            'GITHUB_TOKEN'=>'sample-token',
            'NEXTCLOUD_URL'=>'https://cloud.example.com',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_APP_PASSWORD'=>'test-only',
        ]);
        $this->get('http://localhost/?view=github')->assertOk()
            ->assertSee('github-connected')
            ->assertSee('Desconectar deste computador')
            ->assertDontSee('sample-token');
        $this->postJson('http://localhost/github/disconnect')->assertOk()
            ->assertJsonPath('status','disconnected');
        $creds=$this->store->credentials();
        self::assertArrayNotHasKey('GITHUB_TOKEN',$creds);
        self::assertSame('test-only',$creds['NEXTCLOUD_APP_PASSWORD']);
        self::assertTrue($this->store->sources()['github']);
        $this->get('http://localhost/?view=github')->assertOk()
            ->assertSee('Nenhuma conta GitHub conectada.')
            ->assertDontSee('id="github-connected"',false);
        $this->get('http://localhost/github/profile')->assertStatus(503);
        $this->get('http://localhost/github/avatar')->assertNotFound();
    }

    public function test_github_account_endpoints_reject_nonlocal_requests(): void
    {
        $this->get('http://public.example/github/profile')->assertForbidden();
        $this->get('http://public.example/github/avatar')->assertForbidden();
        $this->postJson('http://public.example/github/disconnect')->assertForbidden();
    }
}
