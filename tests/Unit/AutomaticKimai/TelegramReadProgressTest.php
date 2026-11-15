<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\TelegramReadProgress;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TelegramReadProgressTest extends TestCase
{
    public function test_progress_is_not_assigned_as_working_duration(): void
    {
        $earlier=[
            'collected_at'=>'2026-11-06T09:00:00+00:00',
            'scoped_client_chats'=>[[
                'dialog_id'=>123,'client_hint'=>'Sample Client',
                'read_inbox_max_id'=>10,'observations'=>[]
            ]]
        ];
        $later=[
            'collected_at'=>'2026-11-06T09:30:00+00:00',
            'scoped_client_chats'=>[[
                'dialog_id'=>123,'client_hint'=>'Sample Client',
                'read_inbox_max_id'=>13,'observations'=>[
                    ['message_id'=>11,'outgoing'=>false,'kind'=>'message','text_words'=>28],
                    ['message_id'=>12,'outgoing'=>true,'kind'=>'message','text_words'=>50],
                    ['message_id'=>13,'outgoing'=>false,'kind'=>'message','text_words'=>12],
                ]
            ]]
        ];
        $changes=TelegramReadProgress::compute($earlier,$later);
        self::assertCount(1,$changes);
        self::assertSame(40,$changes[0]['newly_marked_read_words_in_cache']);
        self::assertSame(2,$changes[0]['newly_marked_read_messages_in_cache']);
        self::assertNull($changes[0]['reading_duration_seconds']);
        self::assertFalse($changes[0]['eligible_for_sync']);
        $saved=TelegramReadProgress::retain([], $changes,
            new DateTimeImmutable('2026-11-06T10:00:00+00:00'));
        self::assertSame(0,$saved['kimai_writes']);
        self::assertCount(1,$saved['observations']);
    }

    public function test_invalid_or_long_poll_gap_cannot_imply_read_activity(): void
    {
        $before=['collected_at'=>'2026-11-06T00:00:00+00:00',
                 'scoped_client_chats'=>[['dialog_id'=>1,'client_hint'=>'X','read_inbox_max_id'=>5]]];
        $after=['collected_at'=>'2026-11-06T10:00:00+00:00',
                 'scoped_client_chats'=>[['dialog_id'=>1,'client_hint'=>'X','read_inbox_max_id'=>10]]];
        self::assertSame([],TelegramReadProgress::compute($before,$after));
    }
}
