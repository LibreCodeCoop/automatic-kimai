<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\AutomaticKimai\SettingsVault;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsVaultTest extends TestCase
{
    private const PASSPHRASE='a private recovery phrase of enough length';

    public function test_encrypted_backup_is_portable_but_bound_to_one_nextcloud_identity(): void
    {
        $scope=str_repeat('a',64);
        $salt=SettingsVault::generateSalt();
        $key=SettingsVault::derive(self::PASSPHRASE,$salt);
        $doc=['private_secret'=>'fixture-token-never-in-plaintext','schema'=>1];
        $sealed=SettingsVault::seal($doc,$key,$salt,$scope);
        self::assertStringNotContainsString('fixture-token-never-in-plaintext',$sealed);
        self::assertSame($doc,SettingsVault::open($sealed,$key,$scope));
        [$opened,$secondKey,$restoredSalt]=SettingsVault::unlock($sealed,self::PASSPHRASE,$scope);
        self::assertSame($doc,$opened);
        self::assertSame($key,$secondKey);
        self::assertSame($salt,$restoredSalt);
        self::assertNotSame($sealed,SettingsVault::seal($doc,$key,$salt,$scope),
            'New ciphertext must use a fresh nonce.');
        sodium_memzero($key);
        sodium_memzero($secondKey);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $salt=SettingsVault::generateSalt();
        $key=SettingsVault::derive(self::PASSPHRASE,$salt);
        $blob=SettingsVault::seal(['schema'=>1],$key,$salt,str_repeat('a',64));
        $this->expectException(RuntimeException::class);
        SettingsVault::unlock($blob,'this is a different recovery secret',str_repeat('a',64));
    }

    public function test_replaying_backup_under_another_account_is_rejected(): void
    {
        $salt=SettingsVault::generateSalt();
        $key=SettingsVault::derive(self::PASSPHRASE,$salt);
        $blob=SettingsVault::seal(['schema'=>1],$key,$salt,str_repeat('a',64));
        $this->expectException(RuntimeException::class);
        SettingsVault::open($blob,$key,str_repeat('b',64));
    }

    public function test_tampered_ciphertext_is_rejected(): void
    {
        $salt=SettingsVault::generateSalt();
        $key=SettingsVault::derive(self::PASSPHRASE,$salt);
        $blob=SettingsVault::seal(['schema'=>1],$key,$salt,str_repeat('a',64));
        $doc=json_decode($blob,true);
        $cipher=base64_decode($doc['ciphertext'],true);
        $cipher[0]=chr(ord($cipher[0])^0x20);
        $doc['ciphertext']=base64_encode($cipher);
        $this->expectException(RuntimeException::class);
        SettingsVault::open(json_encode($doc,JSON_THROW_ON_ERROR),$key,str_repeat('a',64));
    }

    public function test_low_entropy_passphrase_is_not_accepted(): void
    {
        $this->expectException(RuntimeException::class);
        SettingsVault::derive('1234',SettingsVault::generateSalt());
    }
}
