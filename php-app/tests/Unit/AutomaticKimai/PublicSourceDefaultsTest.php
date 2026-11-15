<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use PHPUnit\Framework\TestCase;

/** Public builds must never ship a user's debugging/logging preferences. */
final class PublicSourceDefaultsTest extends TestCase
{
    public function test_example_environment_does_not_enable_debug_or_message_logging(): void
    {
        $file=dirname(__DIR__,3).'/.env.example';
        $contents=(string)file_get_contents($file);
        self::assertStringContainsString('APP_DEBUG=false',$contents);
        self::assertStringContainsString('LOG_LEVEL=warning',$contents);
        self::assertStringContainsString('MAIL_MAILER=array',$contents);
        self::assertStringContainsString('SESSION_ENCRYPT=true',$contents);
        self::assertStringContainsString('BROADCAST_CONNECTION=null',$contents);
        self::assertStringNotContainsString('APP_KEY=base64:',$contents);
    }

    public function test_organization_specific_group_suggestions_do_not_exist(): void
    {
        $source=(string)file_get_contents(dirname(__DIR__,3).
            '/app/AutomaticKimai/TelegramGroupSelection.php');
        self::assertStringNotContainsString('recommendedGroups()',$source);
        self::assertStringContainsString('$recommendations=[];',$source);
        $adapter=(string)file_get_contents(dirname(__DIR__,3).
            '/app/AutomaticKimai/MadelineTelegramAdapter.php');
        self::assertStringNotContainsString("getInfo('@'",$adapter);
        self::assertStringContainsString("'recommended'=>false",$adapter);
    }
}
