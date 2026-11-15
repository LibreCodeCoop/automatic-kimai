<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\TelegramAudio;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TelegramAudioTest extends TestCase
{
    public function test_only_telegram_audio_graph_flags_survive(): void
    {
        $graph=[
            ['type'=>'PipeWire:Interface:Node','info'=>[
                'state'=>'running','props'=>[
                    'application.name'=>'TelegramDesktop','media.class'=>'Stream/Input/Audio',
                    'media.role'=>'Communication','application.process.binary'=>'telegram',
                    'object.serial'=>666,'stream.description'=>'Private person name'
                ]
            ]],
            ['type'=>'PipeWire:Interface:Node','info'=>[
                'state'=>'running','props'=>[
                    'application.name'=>'Firefox','media.class'=>'Stream/Output/Audio'
                ]
            ]],
        ];
        $answer=TelegramAudio::parse($graph);
        self::assertTrue($answer['telegram_capture']);
        self::assertTrue($answer['possible_call']);
        self::assertFalse($answer['telegram_playback']);
        self::assertFalse($answer['playback_only']);
        self::assertStringNotContainsString('Private person name',json_encode($answer));
    }

    public function test_possible_call_does_not_prove_user_work(): void
    {
        $items=[];
        foreach (['09:00:00','09:00:15','09:00:30','09:00:45'] as $hh) {
            $items[]=[
                'timestamp_utc'=>'2026-11-06T'.$hh.'+00:00',
                'probe_ok'=>true,'telegram_capture'=>true,
                'telegram_playback'=>true,'telegram_communication'=>false,
            ];
        }
        $sessions=TelegramAudio::candidates($items);
        self::assertCount(1,$sessions);
        self::assertSame(45.0,$sessions[0]['bounded_observed_seconds']);
        self::assertFalse($sessions[0]['eligible_for_sync']);
        self::assertNull($sessions[0]['billable']);
        self::assertNull($sessions[0]['client_id']);
    }

    public function test_audio_db_is_owner_only(): void
    {
        $dir=sys_get_temp_dir().'/kimai-audio-'.bin2hex(random_bytes(8));
        $file=$dir.'/telegram-audio.sqlite3';
        $db=TelegramAudio::open($file);
        try {
            TelegramAudio::record($db,[
                'telegram_capture'=>true,'telegram_playback'=>false,
                'telegram_communication'=>true,
            ],new DateTimeImmutable('2026-11-06T09:00:00+00:00'));
            $value=$db->query('SELECT telegram_capture,probe_ok FROM samples')->fetch(\PDO::FETCH_ASSOC);
            self::assertEquals(1,$value['telegram_capture']);
            self::assertEquals(1,$value['probe_ok']);
            self::assertSame(0,fileperms($file)&0077);
        } finally {
            unset($db);@unlink($file);@rmdir($dir);
        }
    }
}
