<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\ActivityWatchClient;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActivityWatchClientTest extends TestCase
{
    public function test_local_only_endpoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ActivityWatchClient('https://example.com:5600');
    }

    public function test_private_urls_are_never_saved(): void
    {
        self::assertSame(['','other'],ActivityWatchClient::safeUrl('https://my-bank.example/balance'));
        self::assertSame(['https://chatgpt.com','chat'],
            ActivityWatchClient::safeUrl('https://chatgpt.com/c/123456789012?secret=123'));
        self::assertSame(['https://github.com/example-org/project/pull/123','github'],
            ActivityWatchClient::safeUrl('https://github.com/example-org/project/pull/123/files?token=secret'));
        self::assertSame(['','other'],
            ActivityWatchClient::safeUrl('https://sub.projects.example.net/path',['projects.example.net']));
    }

    public function test_browser_and_afk_intersections_are_sanitized(): void
    {
        $start=new DateTimeImmutable('2026-11-06T09:00:00-03:00');
        $end=new DateTimeImmutable('2026-11-06T09:30:00-03:00');
        $source=[
            'aw-watcher-window_host'=>['type'=>'currentwindow'],
            'aw-watcher-afk_host'=>['type'=>'afkstatus'],
            'aw-watcher-web-host'=>['type'=>'web.tab.current'],
        ];
        $raw=[
            'aw-watcher-window_host'=>[
                ['timestamp'=>'2026-11-06T09:00:00-03:00','duration'=>1800,
                 'data'=>['app'=>'Firefox','title'=>'Secret patient name']]],
            'aw-watcher-afk_host'=>[
                ['timestamp'=>'2026-11-06T09:00:00-03:00','duration'=>1800,
                 'data'=>['status'=>'not-afk']]],
            'aw-watcher-web-host'=>[
                ['timestamp'=>'2026-11-06T09:00:00-03:00','duration'=>1800,
                 'data'=>['url'=>'https://chatgpt.com/c/abcdefghijklmnop?token=super-secret']]],
        ];
        $client=new ActivityWatchClient('http://127.0.0.1:5600',
            static function($path,$params) use ($source,$raw) {
                if ($path==='/buckets/')return $source;
                foreach ($raw as $bucket=>$events) {
                    if ($path==='/buckets/'.$bucket.'/events')return $events;
                }
                return [];
            });
        [$events,$stats]=$client->collect($start,$end,[]);
        self::assertCount(1,$events);
        self::assertSame('chat',$events[0]['url_kind']);
        self::assertSame('https://chatgpt.com',$events[0]['url']);
        self::assertFalse($events[0]['chat_context']['contains_message_text']);
        self::assertArrayNotHasKey('title',$events[0]);
        self::assertStringNotContainsString('abcdefghijklmnop',json_encode($events));
        self::assertStringNotContainsString('patient',json_encode($events));
        self::assertSame(1,$stats['sanitized_blocks']);
    }

    public function test_afk_flags_prevent_work_classification(): void
    {
        $source=['aw-window'=>['type'=>'currentwindow'],'aw-afk'=>['type'=>'afkstatus']];
        $client=new ActivityWatchClient('http://localhost:5600',static function($route) use($source) {
            if ($route==='/buckets/')return $source;
            if ($route==='/buckets/aw-window/events')return [[
                'timestamp'=>'2026-11-06T09:00:00+00:00',
                'duration'=>900,'data'=>['app'=>'Firefox','title'=>'untrusted']
            ]];
            if ($route==='/buckets/aw-afk/events')return [[
                'timestamp'=>'2026-11-06T09:00:00+00:00',
                'duration'=>900,'data'=>['status'=>'afk']
            ]];
            return [];
        });
        [$rows]=$client->collect(new DateTimeImmutable('2026-11-06T09:00:00+00:00'),
            new DateTimeImmutable('2026-11-06T09:15:00+00:00'));
        self::assertTrue($rows[0]['afk']);
    }
}
