<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\AgentRuns;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentRunsTest extends TestCase
{
    public function test_explicit_agent_markers_are_never_claimed_as_human_work(): void
    {
        $dir=sys_get_temp_dir().'/kimai-runs-'.bin2hex(random_bytes(8));
        $file=$dir.'/agent_runs.sqlite3';
        $store=new AgentRuns($file,'worker-test');
        try {
            $id=$store->start('chatgpt_work','test-run-123456',
                new DateTimeImmutable('2026-11-06T13:00:00+00:00'));
            self::assertSame('test-run-123456',$id);
            $store->finish($id,'completed',
                new DateTimeImmutable('2026-11-06T13:20:00+00:00'));
            $runs=$store->intervals(new DateTimeImmutable('2026-11-06T12:00:00+00:00'),
                new DateTimeImmutable('2026-11-06T15:00:00+00:00'));
            self::assertCount(1,$runs);
            self::assertFalse($runs[0]['counts_as_human_work']);
            self::assertSame(1200,$runs[0]['execution_seconds']);
            $w=['start'=>'2026-11-06T13:10:00+00:00',
                'end'=>'2026-11-06T13:30:00+00:00'];
            self::assertSame(600,AgentRuns::overlappingSeconds($w,$runs));
            $this->expectException(RuntimeException::class);
            $store->finish($id,'completed',
                new DateTimeImmutable('2026-11-06T13:40:00+00:00'));
        } finally {$store->close();@unlink($file);@rmdir($dir);}
    }

    public function test_existing_agent_store_cannot_be_reused_for_other_profile(): void
    {
        $dir=sys_get_temp_dir().'/kimai-runs-'.bin2hex(random_bytes(8));
        $file=$dir.'/runs.sqlite3';
        $store=new AgentRuns($file,'worker-1');$store->close();
        try {
            $this->expectException(RuntimeException::class);
            new AgentRuns($file,'worker-2');
        } finally {@unlink($file);@rmdir($dir);}
    }
}
