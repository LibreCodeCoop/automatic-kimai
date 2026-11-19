<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\NextcloudSettingsDav;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\SettingsSyncService;
use RuntimeException;
use Tests\TestCase;

final class SettingsSyncServiceTest extends TestCase
{
    private array $folders=[];
    private object $remote;
    private const RECOVERY='a very long and unique test passphrase 2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->remote=(object)['value'=>null,'revision'=>0,'writes'=>0];
    }

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            foreach (glob($folder.'/*')?:[] as $file) {
                if (is_file($file))@unlink($file);
            }
            @rmdir($folder);
        }
        parent::tearDown();
    }

    private function store(string $nextcloudUid='same-user'): LocalStore
    {
        $dir=sys_get_temp_dir().'/ak-nextcloud-settings-'.bin2hex(random_bytes(6));
        $this->folders[]=$dir;
        $local=new LocalStore($dir);
        $local->saveCredentials([
            'NEXTCLOUD_URL'=>'https://cloud.example.invalid',
            'NEXTCLOUD_USERNAME'=>$nextcloudUid,
            'NEXTCLOUD_LOGIN_NAME'=>$nextcloudUid,
            'NEXTCLOUD_APP_PASSWORD'=>'this-is-device-specific-'.$dir,
        ]);
        return $local;
    }

    private function service(LocalStore $store): SettingsSyncService
    {
        $memory=$this->remote;
        $fake=static function($method,$url,$headers,$body,$auth) use ($memory): array {
            if ($method==='MKCOL')return ['status'=>201,'body'=>''];
            if ($method==='GET') {
                if ($memory->value===null)return ['status'=>404,'body'=>''];
                return ['status'=>200,'body'=>$memory->value,
                    'headers'=>['etag'=>'"rev'.$memory->revision.'"']];
            }
            if ($method==='PUT') {
                $current=$memory->value===null
                    ? 'If-None-Match: *'
                    : 'If-Match: "rev'.$memory->revision.'"';
                if (!in_array($current,$headers,true))return ['status'=>412,'body'=>''];
                $memory->value=$body;
                $memory->revision++;
                $memory->writes++;
                return ['status'=>204,'body'=>''];
            }
            throw new RuntimeException('Unexpected mock verb');
        };
        $dav=new NextcloudSettingsDav($fake,static fn($creds)=>[
            'username'=>$creds['NEXTCLOUD_USERNAME'],
        ]);
        return new SettingsSyncService($store,$dav);
    }

    private static function profile(string $id='test_user',int $lookback=3): Profile
    {
        return new Profile([
            'profile_id'=>$id,'timezone'=>'America/Sao_Paulo',
            'customer_id'=>1,'project_id'=>2,'activity_id'=>3,
            'lookback_days'=>$lookback,
            'work_messenger_dialog_ids'=>[-10020],
            'work_messenger_group_titles'=>['-10020'=>'Private group example'],
        ]);
    }

    public function test_second_device_restores_portable_connections_without_copying_device_sessions(): void
    {
        $a=$this->store();
        $a->saveProfile(self::profile());
        $a->setSources(['github'=>true,'telegram_setup'=>true,'telegram'=>true]);
        $a->saveCredentials([
            'KIMAI_BASE_URL'=>'https://kimai.example.invalid',
            'KIMAI_API_TOKEN'=>'kimai-test-token',
            'GITHUB_TOKEN'=>'github-test-token',
            'TELEGRAM_API_ID'=>'12345',
            'TELEGRAM_API_HASH'=>str_repeat('a',32),
            'KIMAI_WRITE_ENABLED'=>'true',
        ]);
        $originalNcPass=$a->credentials()['NEXTCLOUD_APP_PASSWORD'];
        $originalB=$this->store();
        $otherNcPass=$originalB->credentials()['NEXTCLOUD_APP_PASSWORD'];
        $serviceA=$this->service($a);
        $serviceB=$this->service($originalB);
        self::assertSame('created',$serviceA->enable(self::RECOVERY,true));
        self::assertStringNotContainsString('github-test-token',$this->remote->value);
        self::assertStringNotContainsString('kimai-test-token',$this->remote->value);
        self::assertStringNotContainsString('Private group example',$this->remote->value);
        self::assertSame('restored',$serviceB->enable(self::RECOVERY,true));
        $restored=$originalB->credentials();
        self::assertSame('kimai-test-token',$restored['KIMAI_API_TOKEN']);
        self::assertSame('github-test-token',$restored['GITHUB_TOKEN']);
        self::assertSame($otherNcPass,$restored['NEXTCLOUD_APP_PASSWORD']);
        self::assertSame($originalNcPass,$a->credentials()['NEXTCLOUD_APP_PASSWORD']);
        self::assertSame('false',$restored['KIMAI_WRITE_ENABLED']);
        self::assertSame('review_only',$originalB->profile()->get('sync_mode'));
        self::assertFalse($originalB->sources()['telegram']);
        self::assertTrue($originalB->sources()['telegram_setup']);
        self::assertSame([],$originalB->profile()->get('work_messenger_dialog_ids'));
        self::assertSame('unchanged',$serviceB->synchronize());
        self::assertSame('unchanged',$serviceA->synchronize());
    }

    public function test_changes_propagate_when_other_device_has_not_changed(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile());
        $sa=$this->service($a);$sb=$this->service($b);
        $sa->enable(self::RECOVERY,false);
        $sb->enable(self::RECOVERY,false);
        $a->saveProfile(self::profile('test_user',5));
        self::assertSame('uploaded',$sa->synchronize());
        self::assertSame('restored',$sb->synchronize());
        self::assertSame(5,$b->profile()->get('lookback_days'));
        self::assertSame('unchanged',$sb->synchronize());
    }

    public function test_concurrent_changes_require_explicit_resolution(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile());
        $sa=$this->service($a);$sb=$this->service($b);
        $sa->enable(self::RECOVERY,false);
        $sb->enable(self::RECOVERY,false);
        $a->saveProfile(self::profile('test_user',5));
        $b->saveProfile(self::profile('test_user',7));
        self::assertSame('uploaded',$sa->synchronize());
        $writes=$this->remote->writes;
        self::assertSame('conflict',$sb->synchronize());
        self::assertSame($writes,$this->remote->writes);
        self::assertSame(7,$b->profile()->get('lookback_days'));
        self::assertSame('needs_resolution',$sb->synchronize());
        self::assertSame('restored',$sb->resolve('remote'));
        self::assertSame(5,$b->profile()->get('lookback_days'));
    }

    public function test_existing_device_does_not_get_overwritten_during_first_enable(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile('test_user',5));
        $b->saveProfile(self::profile('test_user',7));
        $sa=$this->service($a);$sb=$this->service($b);
        $sa->enable(self::RECOVERY,false);
        self::assertSame('needs_resolution',$sb->enable(self::RECOVERY,false));
        self::assertSame(7,$b->profile()->get('lookback_days'));
        self::assertSame('needs_resolution',$sb->synchronize());
        self::assertSame('restored',$sb->resolve('remote'));
        self::assertSame(5,$b->profile()->get('lookback_days'));
    }

    public function test_bad_passphrase_and_other_account_do_not_modify_local_configuration(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile());
        $sa=$this->service($a);$sa->enable(self::RECOVERY,true);
        $sb=$this->service($b);
        try {$sb->enable('wrong recovery secret for this test',true);self::fail();}
        catch (RuntimeException) {}
        self::assertNull($b->profile());
        self::assertFalse($sb->status()['enabled']);

        $other=$this->store('someone-else');
        $sc=$this->service($other);
        try {$sc->enable(self::RECOVERY,true);self::fail();}
        catch (RuntimeException) {}
        self::assertNull($other->profile());
        self::assertFalse($sc->status()['enabled']);
    }

    public function test_existing_telegram_consent_and_local_session_are_preserved_on_explicit_restore(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile('test_user',5));
        $b->saveProfile(self::profile('test_user',7));
        $b->setSources(['telegram_setup'=>true,'telegram'=>true]);
        $b->write('telegram-login-state.json',['status'=>'authorized']);
        $sb=$this->service($b);
        $sa=$this->service($a);
        $sa->enable(self::RECOVERY,false);
        self::assertSame('needs_resolution',$sb->enable(self::RECOVERY,false));
        self::assertSame('restored',$sb->resolve('remote'));
        self::assertSame(5,$b->profile()->get('lookback_days'));
        self::assertTrue($b->sources()['telegram']);
        self::assertTrue($b->sources()['telegram_setup']);
        self::assertSame([-10020],$b->profile()->get('work_messenger_dialog_ids'));
        self::assertSame(['status'=>'authorized'],$b->read('telegram-login-state.json'));
    }

    public function test_a_new_remote_token_never_silently_replaces_an_existing_authenticated_connection(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile());
        $a->saveCredentials(['GITHUB_TOKEN'=>'original-github-token']);
        $sa=$this->service($a);$sb=$this->service($b);
        $sa->enable(self::RECOVERY,true);
        $sb->enable(self::RECOVERY,true);
        $a->saveCredentials(['GITHUB_TOKEN'=>'rotated-github-token']);
        self::assertSame('uploaded',$sa->synchronize());
        self::assertSame('conflict',$sb->synchronize(),
            'Token rotation must require manual review on other logged-in devices.');
        self::assertSame('original-github-token',$b->credentials()['GITHUB_TOKEN']);
        self::assertSame('restored',$sb->resolve('remote'));
        self::assertSame('rotated-github-token',$b->credentials()['GITHUB_TOKEN']);
    }

    public function test_explicit_remote_restore_does_not_touch_authenticated_telegram_api_credentials(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile('test_user',5));
        $b->saveProfile(self::profile('test_user',7));
        $a->saveCredentials(['TELEGRAM_API_ID'=>'10001',
            'TELEGRAM_API_HASH'=>str_repeat('a',32)]);
        $b->saveCredentials(['TELEGRAM_API_ID'=>'10002',
            'TELEGRAM_API_HASH'=>str_repeat('b',32)]);
        $b->setSources(['telegram_setup'=>true,'telegram'=>true]);
        $b->write('telegram-login-state.json',['status'=>'authorized']);
        $sa=$this->service($a);$sb=$this->service($b);
        $sa->enable(self::RECOVERY,true);
        self::assertSame('needs_resolution',$sb->enable(self::RECOVERY,true));
        self::assertSame('restored',$sb->resolve('remote'));
        self::assertSame('10002',$b->credentials()['TELEGRAM_API_ID']);
        self::assertSame(str_repeat('b',32),$b->credentials()['TELEGRAM_API_HASH']);
        self::assertTrue($b->sources()['telegram']);
        self::assertSame(['status'=>'authorized'],$b->read('telegram-login-state.json'));
    }

    public function test_remote_changed_again_requires_new_explicit_review(): void
    {
        $a=$this->store();$b=$this->store();
        $a->saveProfile(self::profile('test_user',5));
        $b->saveProfile(self::profile('test_user',7));
        $sa=$this->service($a);$sb=$this->service($b);
        $sa->enable(self::RECOVERY,false);
        self::assertSame('needs_resolution',$sb->enable(self::RECOVERY,false));
        // A remote backup changed while B was reading its conflict screen.
        $a->saveProfile(self::profile('test_user',6));
        self::assertSame('uploaded',$sa->synchronize());
        try {$sb->resolve('local');self::fail('Expected an ETag mismatch');}
        catch (\App\AutomaticKimai\SettingsSyncConflict) {
            self::assertSame(7,$b->profile()->get('lookback_days'));
        }
        self::assertSame('uploaded',$sb->resolve('local'));
        self::assertSame('restored',$sa->synchronize());
        self::assertSame(7,$a->profile()->get('lookback_days'));
    }

    public function test_disconnect_forgets_only_the_local_backup_key_and_does_not_delete_cloud_backup(): void
    {
        $a=$this->store();
        $sa=$this->service($a);
        $sa->enable(self::RECOVERY,false);
        $previous=$this->remote->value;
        $a->disconnectNextcloud();
        self::assertFalse($sa->status()['enabled']);
        self::assertSame([],$a->read('settings-sync-key.json'));
        self::assertSame($previous,$this->remote->value);
    }
}
