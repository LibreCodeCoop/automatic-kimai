<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\CanonicalJson;
use App\AutomaticKimai\ManualTime;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\Segmenter;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** SHA-256 golden vectors recorded by the released Python reference. */
final class PythonParityTest extends TestCase
{
    private static function profile(): Profile
    {
        return new Profile([
            'profile_id'=>'worker-test','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25
        ]);
    }

    public function test_same_session_identifier_and_digest_as_python(): void
    {
        $rows=(new Segmenter())->segment('2026-11-08',[[
            'start'=>'2026-11-08T23:05:00-03:00',
            'end'=>'2026-11-08T23:59:59.999999-03:00',
            'url_kind'=>'github','afk'=>false,'idle_status_known'=>true,'private'=>false,
        ]],[],self::profile(),new DateTimeImmutable('2026-11-09T03:00:00-03:00'));
        self::assertCount(1,$rows);
        self::assertSame('47a587155535a126e70fef5d',$rows[0]['id']);
        self::assertSame('43af086974281c3019f8afdd05cf457b1b504b1f75524e71dafc8dd28343efca',
            $rows[0]['evidence_digest']);
    }

    public function test_manual_declaration_digest_matches_python(): void
    {
        $window=ManualTime::window(
            self::profile(),'2026-11-06','15:00','17:00','University presentation',
            new DateTimeImmutable('2026-11-07T09:00:00-03:00')
        );
        self::assertSame('self69a539e4808a3451bfa58',$window['id']);
        self::assertSame('69a539e4808a3451bfa581d0a22198a6626e0aa94e33b1355913ed980e127d45',
            $window['evidence_digest']);
    }

    public function test_identity_and_action_checksums_match_python(): void
    {
        self::assertSame('0db08692a381dc52ec6614ece333377351a44fb26b2e58961bf6dc3898344515',
            CanonicalJson::digest(['user'=>1,'profile'=>'worker-test'],false));
        self::assertSame('c7d4a4dc2d5ce7c6d7554bc717ee2be3c1c572e3b698def1bfe993c3d0c82ee2',
            CanonicalJson::digest([
                'account'=>1,'profile'=>'worker-test',
                'session'=>'47a587155535a126e70fef5d',
                'action'=>'post','new_end'=>'2026-11-09T00:00:00'
            ],false));
    }
}
