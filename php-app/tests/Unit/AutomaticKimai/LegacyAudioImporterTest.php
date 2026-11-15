<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\LegacyAudioImporter;
use App\AutomaticKimai\TelegramAudio;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LegacyAudioImporterTest extends TestCase
{
    private string $dir;
    private string $legacy;
    private string $new;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-audio-migration-'.bin2hex(random_bytes(8));
        mkdir($this->dir,0700);
        $this->legacy=$this->dir.'/old.sqlite3';
        $this->new=$this->dir.'/new.sqlite3';
        $source=TelegramAudio::open($this->legacy);
        $source->exec("INSERT INTO samples VALUES
            ('2026-11-12T11:00:00.000000+00:00',1,0,1,1),
            ('2026-11-12T11:00:15.000000+00:00',1,1,1,1),
            ('2026-11-12T11:00:30.000000+00:00',0,0,0,0)");
        unset($source);
    }

    protected function tearDown(): void
    {
        @unlink($this->legacy);
        @unlink($this->new);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_audio_metadata_is_imported_losslessly_without_inferred_work(): void
    {
        $hash=hash_file('sha256',$this->legacy);
        $importer=new LegacyAudioImporter();
        $first=$importer->import($this->legacy,$this->new);
        self::assertSame(3,$first['source_samples']);
        self::assertSame(3,$first['new_samples']);
        self::assertFalse($first['counts_as_work']);
        self::assertFalse($first['audio_recordings_copied']);
        self::assertSame($hash,hash_file('sha256',$this->legacy));
        $second=$importer->import($this->legacy,$this->new);
        self::assertSame(0,$second['new_samples']);
        $target=TelegramAudio::open($this->new);
        self::assertSame(3,(int)$target->query('SELECT count(*) FROM samples')->fetchColumn());
        $rows=$target->query('SELECT * FROM samples ORDER BY timestamp_utc')->fetchAll(PDO::FETCH_ASSOC);
        // Two consecutive capture samples are insufficient to establish a
        // possible call; much less proof of human work.
        self::assertSame([],TelegramAudio::candidates($rows,15));
    }

    public function test_a_conflicting_sample_is_rejected_transactionally(): void
    {
        $target=TelegramAudio::open($this->new);
        $target->exec("INSERT INTO samples VALUES
            ('2026-11-12T11:00:15.000000+00:00',0,0,0,1)");
        unset($target);
        try {
            (new LegacyAudioImporter())->import($this->legacy,$this->new);
            self::fail('Import must reject mismatched source provenance');
        } catch (RuntimeException $e) {
            $check=TelegramAudio::open($this->new);
            self::assertSame(1,(int)$check->query('SELECT count(*) FROM samples')->fetchColumn());
        }
    }

    public function test_refuses_world_readable_audio_history(): void
    {
        chmod($this->legacy,0644);
        $this->expectException(RuntimeException::class);
        (new LegacyAudioImporter())->import($this->legacy,$this->new);
    }
}
