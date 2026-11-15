<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\TelegramCollector;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TelegramCollectorBudgetTest extends TestCase
{
    public function test_per_cycle_budget_does_not_depend_on_total_selected_groups(): void
    {
        self::assertSame(600,TelegramCollector::historyLimitPerGroup(1));
        self::assertSame(200,
            TelegramCollector::historyLimitPerGroup(TelegramCollector::GROUPS_PER_CYCLE));
        self::assertSame(4_800,
            TelegramCollector::GROUPS_PER_CYCLE*
            TelegramCollector::historyLimitPerGroup(TelegramCollector::GROUPS_PER_CYCLE));
        foreach (range(1,TelegramCollector::GROUPS_PER_CYCLE) as $count) {
            self::assertLessThanOrEqual(4_800,
                $count*TelegramCollector::historyLimitPerGroup($count));
        }
        self::assertSame(4,TelegramCollector::historyLimitPerGroup(1_000));
    }

    public function test_invalid_active_group_count_is_rejected(): void
    {
        foreach([0,-1] as $count){
            try {
                TelegramCollector::historyLimitPerGroup($count);
                self::fail('A scan batch must contain at least one group');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
