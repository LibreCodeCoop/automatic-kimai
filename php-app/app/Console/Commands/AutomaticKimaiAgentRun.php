<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\AgentRuns;
use App\AutomaticKimai\LocalStore;
use Illuminate\Console\Command;
use Throwable;

/**
 * Integration hook for explicitly attested external agent process lifecycle.
 * Neither a run nor its duration is human attendance or billable time.
 */
final class AutomaticKimaiAgentRun extends Command
{
    protected $signature='automatic-kimai:agent-run
        {--start= : Source: remote_desktop_mcp, chatgpt_work, codex_cli, other}
        {--finish= : Previously returned opaque run ID}
        {--state=completed : Completed, failed or cancelled}';

    protected $description='Record explicit agent execution separately from human working time';

    public function handle(LocalStore $store): int
    {
        $source=$this->option('start');$finish=$this->option('finish');
        if ((!is_string($source)||$source==='')===(!is_string($finish)||$finish==='')) {
            $this->error('Choose exactly one --start or --finish');
            return self::FAILURE;
        }
        try {
            $profile=$store->profile();
            if ($profile===null)throw new \RuntimeException('Worker profile required');
            $run=new AgentRuns($store->root().'/agent_runs.sqlite3',
                $profile->get('profile_id'));
            try {
                if (is_string($source)&&$source!=='') {
                    $id=$run->start($source);
                    $this->line('AGENT_RUN_STARTED '.$id);
                } else {
                    $run->finish((string)$finish,(string)$this->option('state'));
                    $this->line('AGENT_RUN_FINISHED');
                }
                $this->line('COUNTS_AS_HUMAN_WORK=false');
                return self::SUCCESS;
            } finally {$run->close();}
        } catch (Throwable $e) {
            $this->error('AGENT_RUN_FAILED '.get_class($e));
            return self::FAILURE;
        }
    }
}
