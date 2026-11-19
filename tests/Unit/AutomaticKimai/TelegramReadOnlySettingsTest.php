<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use PHPUnit\Framework\TestCase;

final class TelegramReadOnlySettingsTest extends TestCase
{
    public function test_read_only_clients_do_not_reconfigure_ipc_logger_on_every_request(): void
    {
        $root=dirname(__DIR__,3);
        $login=file_get_contents($root.'/app/AutomaticKimai/TelegramWebLogin.php');
        $adapter=file_get_contents($root.'/app/AutomaticKimai/MadelineTelegramAdapter.php');
        self::assertIsString($login);
        self::assertIsString($adapter);
        self::assertStringContainsString('$api=new API($session);',$login);
        self::assertStringContainsString('$this->api=new API($sessionPath);',$adapter);
        // Configuration remains available to the one-time login worker.
        self::assertStringContainsString('public static function settings(', $adapter);
    }
}
