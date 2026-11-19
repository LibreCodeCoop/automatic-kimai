<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\AutomaticKimai\NextcloudSettingsDav;
use App\AutomaticKimai\SettingsSyncConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NextcloudSettingsDavTest extends TestCase
{
    private static function credentials(): array
    {
        return [
            'NEXTCLOUD_URL'=>'https://cloud.example.invalid',
            'NEXTCLOUD_USERNAME'=>'user-name',
            'NEXTCLOUD_LOGIN_NAME'=>'user-name',
            'NEXTCLOUD_APP_PASSWORD'=>'only-a-test-password',
        ];
    }

    public function test_creation_requires_conditional_put_and_never_exposes_app_password_in_url(): void
    {
        $records=[];
        $value=null;
        $fake=static function($method,$url,$headers,$body,$auth) use (&$records,&$value): array {
            $records[]=[$method,$url,$headers];
            self::assertStringNotContainsString('only-a-test-password',$url);
            if ($method==='MKCOL')return ['status'=>201,'body'=>''];
            if ($method==='PUT') {
                self::assertContains('If-None-Match: *',$headers);
                $value=$body;
                return ['status'=>201,'body'=>''];
            }
            if ($value===null)return ['status'=>404,'body'=>''];
            return ['status'=>200,'body'=>$value,'headers'=>['etag'=>'"r1"']];
        };
        $dav=new NextcloudSettingsDav($fake,static fn($c)=>['username'=>$c['NEXTCLOUD_USERNAME']]);
        self::assertNull($dav->read(self::credentials()));
        $remote=$dav->write(self::credentials(),'encrypted-fixture',null);
        self::assertSame('"r1"',$remote['etag']);
        self::assertSame('encrypted-fixture',$remote['raw']);
        self::assertStringContainsString('/remote.php/dav/files/user-name/Automatic%20Kimai/settings-v1.encrypted',$records[0][1]);
    }

    public function test_etag_conflicts_fail_closed_without_write_retry(): void
    {
        $writes=0;
        $fake=static function($method,$url,$headers,$body,$auth) use (&$writes): array {
            if ($method==='GET')return [
                'status'=>200,'body'=>'encrypted-fixture','headers'=>['etag'=>'"r2"'],
            ];
            $writes++;
            return ['status'=>412,'body'=>''];
        };
        $dav=new NextcloudSettingsDav($fake,static fn($c)=>['username'=>$c['NEXTCLOUD_USERNAME']]);
        try {
            $dav->write(self::credentials(),'other-fixture','"r1"');
            self::fail('Expected HTTP precondition to reject overwrite');
        } catch (SettingsSyncConflict) {
            self::assertSame(1,$writes);
        }
    }

    public function test_account_identity_must_be_verified_for_every_dav_access(): void
    {
        $called=false;
        $dav=new NextcloudSettingsDav(
            static function() use (&$called): array {$called=true;return ['status'=>200,'body'=>''];},
            static fn($c)=>['username'=>'someone-else']
        );
        $this->expectException(RuntimeException::class);
        try {$dav->read(self::credentials());}
        finally {self::assertFalse($called);}
    }
}
