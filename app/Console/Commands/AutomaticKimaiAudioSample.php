<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\AudioRuntime;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\TelegramAudio;
use Illuminate\Console\Command;
use DateTimeImmutable;
use Throwable;

/**
 * One metadata-only PipeWire probe for a scheduler. This command is never
 * automatically started by PHP shadow and requires Telegram opt-in.
 */
final class AutomaticKimaiAudioSample extends Command
{
    protected $signature='automatic-kimai:audio-sample
        {--status : Read local sample counts without scanning PipeWire}';

    protected $description='Collect Telegram PipeWire stream-state metadata without audio recordings';

    public function handle(LocalStore $store): int
    {
        try {
            if ($store->profile()===null)throw new \RuntimeException('Worker profile missing');
            $path=$store->root().'/telegram_audio_metadata.sqlite3';
            if ($this->option('status')) {
                if (!is_file($path)) {
                    $this->line('AUDIO_SAMPLES=0');
                    return self::SUCCESS;
                }
                $pdo=TelegramAudio::open($path);
                $this->line('AUDIO_SAMPLES='.$pdo->query('SELECT count(*) FROM samples')->fetchColumn());
                return self::SUCCESS;
            }
            if (!$store->sources()['telegram']) {
                $this->line('AUDIO_SAMPLE_SKIPPED source_not_activated');
                return self::SUCCESS;
            }
            if (PHP_OS_FAMILY!=='Linux') {
                throw new \RuntimeException('PipeWire audio probe requires Linux');
            }
            try {
                $observation=TelegramAudio::probe();
            } catch (Throwable $e) {
                // Failure is explicit, never manufactured as inactivity.
                TelegramAudio::record(TelegramAudio::open($path),null);
                $this->error('AUDIO_SAMPLE_UNAVAILABLE '.get_class($e));
                return self::FAILURE;
            }
            $now=new DateTimeImmutable('now',new \DateTimeZone('UTC'));
            TelegramAudio::record(TelegramAudio::open($path),$observation,$now);
            AudioRuntime::record($store,$now);
            $this->line('AUDIO_SAMPLE_METADATA_ONLY counts_as_work=false');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('AUDIO_SAMPLE_FAILED '.get_class($e));
            return self::FAILURE;
        }
    }
}
