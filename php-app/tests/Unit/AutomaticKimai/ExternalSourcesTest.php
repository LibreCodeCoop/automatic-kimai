<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\NextcloudSource;
use App\AutomaticKimai\GitHubSource;
use App\AutomaticKimai\HttpTransport;
use App\AutomaticKimai\Profile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ExternalSourcesTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile(['profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25]);
    }

    public function test_caldav_recurrence_and_private_event_keep_only_times(): void
    {
        $text="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:person.example\r\nDTSTART:20261106T150000\r\nDTEND:20261106T170000\r\nSUMMARY:Presentation about confidential customer\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $events=NextcloudSource::parseEvents($text,$this->profile(),
            new DateTimeImmutable('2026-11-05T00:00:00-03:00'),
            new DateTimeImmutable('2026-11-08T00:00:00-03:00'));
        self::assertCount(1,$events);
        self::assertSame('possible_meeting_unclassified',$events[0]['category']);
        self::assertSame('2026-11-06T15:00:00-03:00',$events[0]['start_local']);
        self::assertStringNotContainsString('customer',json_encode($events));
    }

    public function test_github_only_releases_hashes_and_event_timestamps(): void
    {
        $client=new HttpTransport(static function($method,$url){
            if ($url==='https://api.github.com/user')return ['status'=>200,'body'=>json_encode(['login'=>'sample-user'])];
            return ['status'=>200,'body'=>json_encode([[
                'created_at'=>'2026-11-06T09:00:00Z','type'=>'PushEvent',
                'id'=>'123','repo'=>['name'=>'sensitive-company/private-repo'],
                'payload'=>['commits'=>[['message'=>'Secret commit text']]]
            ]])];
        });
        $record=(new GitHubSource($client))->collect('not-a-real-secret');
        self::assertSame('ok',$record['status']);
        self::assertCount(1,$record['events']);
        self::assertStringNotContainsString('sensitive-company',json_encode($record));
        self::assertStringNotContainsString('Secret commit',json_encode($record));
        self::assertFalse($record['events'][0]['human_duration_verified']);
    }

    public function test_github_unconfigured_is_optional(): void
    {
        $record=(new GitHubSource(new HttpTransport()))->collect('');
        self::assertSame('not_configured',$record['status']);
    }
}
