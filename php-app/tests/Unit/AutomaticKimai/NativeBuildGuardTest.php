<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\NativeBuildGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NativeBuildGuardTest extends TestCase
{
    private function setupSource(): string
    {
        $dir = sys_get_temp_dir().'/kimai-native-'.bin2hex(random_bytes(8));
        mkdir($dir.'/storage/app/private',0700,true);
        file_put_contents($dir.'/storage/app/private/.gitignore',"*\n!.gitignore\n");
        return $dir;
    }

    private function cleanupSource(string $dir): void
    {
        foreach (glob($dir.'/storage/app/private/*')?:[] as $file) {
            if (is_file($file))unlink($file);
        }
        @unlink($dir.'/storage/app/private/.gitignore');
        @rmdir($dir.'/storage/app/private');
        @rmdir($dir.'/storage/app');
        @rmdir($dir.'/storage');
        @unlink($dir.'/.env');
        @unlink($dir.'/.env.production');
        @rmdir($dir);
    }

    public function test_clean_source_with_no_secret_may_pass_preflight(): void
    {
        $dir=$this->setupSource();
        try {
            file_put_contents($dir.'/.env',"APP_KEY=\nAPP_DEBUG=false\n");
            NativeBuildGuard::assertSafe($dir);
            self::assertTrue(true);
        } finally {$this->cleanupSource($dir);}
    }

    public function test_initialized_private_profile_is_not_redistributed(): void
    {
        $dir=$this->setupSource();
        try {
            file_put_contents($dir.'/storage/app/private/secrets.enc','secret-data');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('private user data exists');
            NativeBuildGuard::assertSafe($dir);
        } finally {$this->cleanupSource($dir);}
    }

    public function test_shared_app_key_is_never_embedded_in_installer(): void
    {
        $dir=$this->setupSource();
        try {
            file_put_contents($dir.'/.env',"APP_KEY=base64:unsafe-key\nAPP_DEBUG=false\n");
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('APP_KEY would be embedded');
            NativeBuildGuard::assertSafe($dir);
        } finally {$this->cleanupSource($dir);}
    }

    public function test_custom_production_env_blocks_package(): void
    {
        $dir=$this->setupSource();
        try {
            file_put_contents($dir.'/.env.production',"KIMAI_API_TOKEN=unsafe\n");
            $this->expectException(RuntimeException::class);
            NativeBuildGuard::assertSafe($dir);
        } finally {$this->cleanupSource($dir);}
    }
}
