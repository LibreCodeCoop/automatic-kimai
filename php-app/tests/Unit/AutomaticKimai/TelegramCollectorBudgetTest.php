<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\Profile;
use App\AutomaticKimai\TelegramCollector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TelegramCollectorBudgetTest extends TestCase
{
    public function test_larger_group_allowlist_does_not_expand_total_history_budget(): void
    {
        self::assertSame(600,TelegramCollector::historyLimitPerGroup(1));
        self::assertSame(600,TelegramCollector::historyLimitPerGroup(40));
        self::assertSame(585,TelegramCollector::historyLimitPerGroup(41));
        self::assertSame(300,TelegramCollector::historyLimitPerGroup(80));
        self::assertSame(240,TelegramCollector::historyLimitPerGroup(100));
        self::assertSame(100,Profile::MAX_MESSENGER_GROUPS);
        for($n=1;$n<=Profile::MAX_MESSENGER_GROUPS;$n++){
            self::assertLessThanOrEqual(24000,
                $n*TelegramCollector::historyLimitPerGroup($n));
        }
    }

    public function test_zero_and_out_of_range_group_counts_are_rejected(): void
    {
        foreach([0,-1,101] as $count){
            try {
                TelegramCollector::historyLimitPerGroup($count);
                self::fail('An unbounded group count was accepted');
            }catch(InvalidArgumentException){
                self::assertTrue(true);
            }
        }
    }
}
