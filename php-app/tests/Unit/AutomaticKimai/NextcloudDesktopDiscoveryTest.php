<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\NextcloudDesktopDiscovery;
use PHPUnit\Framework\TestCase;

final class NextcloudDesktopDiscoveryTest extends TestCase
{
    public function test_discovers_only_distinct_https_server_urls_not_private_account_fields(): void
    {
        $file=tempnam(sys_get_temp_dir(),'nextcloud-client-');
        self::assertIsString($file);
        try {
            file_put_contents($file,<<<'CFG'
[General]
autoUpdateCheckInterval=30
[Accounts]
0\url=https://cloud.example.org
0\user=private-login
0\password=not-for-display
1\url=https://cloud.example.org
2\url=http://insecure.example.org
3\url=https://cloud.example.org/nextcloud
[Other]
url=https://not-an-account.example.com
CFG);
            $servers=NextcloudDesktopDiscovery::fromConfig($file);
            self::assertSame([
                'https://cloud.example.org',
                'https://cloud.example.org/nextcloud',
            ],$servers);
            self::assertStringNotContainsString('private-login',json_encode($servers));
            self::assertStringNotContainsString('not-for-display',json_encode($servers));
        } finally {
            @unlink($file);
        }
    }
}
