<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\Profile;
use App\AutomaticKimai\Segmenter;
use App\AutomaticKimai\TimeMath;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SegmenterTest extends TestCase
{
    private static function profile(array $changes=[]): Profile
    {
        return new Profile(array_replace([
            'profile_id'=>'worker-test', 'timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'min_session_minutes'=>15,'min_context_minutes'=>8,
        ],$changes));
    }

    private static function event(string $start,string $end,string $kind='github',array $extra=[]): array
    {
        return array_replace([
            'start'=>$start,'end'=>$end,'url_kind'=>$kind,
            'afk'=>false,'idle_status_known'=>true,'private'=>false,
        ],$extra);
    }

    public function test_segmenter_recovers_last_minute_at_midnight(): void
    {
        $events=[self::event('2026-11-08T23:05:00-03:00','2026-11-08T23:59:59.999999-03:00')];
        $window=(new Segmenter())->segment(
            '2026-11-08',$events,[],self::profile(),
            new DateTimeImmutable('2026-11-09T03:00:00-03:00')
        );
        self::assertCount(1,$window);
        self::assertSame('2026-11-08T23:05:00-03:00',$window[0]['start']);
        self::assertSame('2026-11-09T00:00:00-03:00',$window[0]['end']);
        self::assertTrue(TimeMath::validDailyInterval(
            new DateTimeImmutable($window[0]['start']),
            new DateTimeImmutable($window[0]['end']),
            new DateTimeZone('America/Sao_Paulo')
        ));
    }

    public function test_does_not_fabricate_unobserved_minute(): void
    {
        $events=[self::event('2026-11-08T23:05:00-03:00','2026-11-08T23:59:10-03:00')];
        $window=(new Segmenter())->segment(
            '2026-11-08',$events,[],self::profile(),
            new DateTimeImmutable('2026-11-09T03:00:00-03:00')
        );
        self::assertSame('2026-11-08T23:59:00-03:00',$window[0]['end']);
    }

    public function test_chat_only_source_is_not_verified_human_work(): void
    {
        $events=[self::event('2026-11-09T00:00:00-03:00',
            '2026-11-09T01:01:00-03:00','chat')];
        $windows=(new Segmenter())->segment('2026-11-09',$events,[],self::profile(),
            new DateTimeImmutable('2026-11-09T03:00:00-03:00'));
        self::assertCount(1,$windows);
        self::assertSame(0,$windows[0]['work_seconds']);
        self::assertSame(61*60,$windows[0]['chat_unknown_seconds']);
        self::assertFalse($windows[0]['human_duration_verified']);
    }

    public function test_telegram_collection_consent_does_not_classify_all_selected_groups_as_work(): void
    {
        $selected=self::profile([
            'work_messenger_dialog_ids'=>[-10091,-10092],
            'work_messenger_group_titles'=>[
                '-10091'=>'Team A','-10092'=>'Team B'
            ],
            'work_messenger_labels'=>['Team A','Team B'],
        ]);
        $event=['url_kind'=>'telegram','idle_status_known'=>true,
            'messenger_hint'=>['client_hint'=>'Team A']];
        self::assertSame([], $selected->workEvidenceLabels());
        self::assertSame('other', Segmenter::classify($event,$selected));

        $explicit=self::profile(array_replace($selected->toArray(),[
            'work_messenger_classification_labels'=>['Team A'],
        ]));
        self::assertSame('work',Segmenter::classify($event,$explicit));
        $event['messenger_hint']['client_hint']='Team B';
        self::assertSame('other',Segmenter::classify($event,$explicit));

        $legacy=self::profile(['work_messenger_labels'=>['Team B']]);
        self::assertSame('work',Segmenter::classify($event,$legacy));
    }

    public function test_approved_exact_workplace_domain_only(): void
    {
        $profile=self::profile(['work_domains'=>['projects.example.net']]);
        self::assertSame('work',Segmenter::classify([
            'url_kind'=>'work_site','url'=>'https://projects.example.net','idle_status_known'=>true
        ],$profile));
        self::assertSame('other',Segmenter::classify([
            'url_kind'=>'work_site','url'=>'https://other.example.net','idle_status_known'=>true
        ],$profile));
        self::assertSame('other',Segmenter::classify([
            'url_kind'=>'github','idle_status_known'=>false
        ],$profile));
    }

    public function test_rejects_unapproved_publication_or_missing_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::profile(['sync_mode'=>'preview_nonbillable',
            'allow_auto_nonbillable_preview'=>false]);
    }

    public function test_half_open_timezone_safety(): void
    {
        $z=new DateTimeZone('America/Sao_Paulo');
        $a=new DateTimeImmutable('2026-11-08T23:05:00-03:00');
        self::assertFalse(TimeMath::validDailyInterval($a,
            new DateTimeImmutable('2026-11-09T00:01:00-03:00'),$z));
        self::assertFalse(TimeMath::validDailyInterval($a,
            new DateTimeImmutable('2026-11-08T23:00:00-03:00'),$z));
    }
}
