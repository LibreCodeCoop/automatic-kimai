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

    public function test_caldav_can_use_server_subdirectory_and_separate_login_name(): void
    {
        $seen=[];
        $fake=new HttpTransport(static function($method,$url,$headers,$body,$basic) use (&$seen) {
            $seen[]=['method'=>$method,'url'=>$url,'login'=>$basic[0]??''];
            if ($method==='PROPFIND') {
                return ['status'=>207,'body'=>
                    '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'.
                    '<d:response><d:href>/cloud/remote.php/dav/calendars/worker/work/</d:href>'.
                    '<d:propstat><d:prop><c:supported-calendar-component-set>'.
                    '<c:comp name="VEVENT"/></c:supported-calendar-component-set>'.
                    '</d:prop></d:propstat></d:response></d:multistatus>'];
            }
            return ['status'=>207,'body'=>'<d:multistatus xmlns:d="DAV:"/>'];
        });
        $out=(new NextcloudSource($fake))->calendar($this->profile(),[
            'NEXTCLOUD_URL'=>'https://nextcloud.example.org/cloud',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_LOGIN_NAME'=>'worker@example.org',
            'NEXTCLOUD_APP_PASSWORD'=>'test-value',
        ],new DateTimeImmutable('2026-11-07T12:00:00-03:00'));
        self::assertCount(2,$seen);
        self::assertSame('https://nextcloud.example.org/cloud/remote.php/dav/calendars/worker/',$seen[0]['url']);
        self::assertSame('https://nextcloud.example.org/cloud/remote.php/dav/calendars/worker/work/',$seen[1]['url']);
        self::assertSame('worker@example.org',$seen[0]['login']);
        self::assertSame(1,$out['calendars_scanned']);
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

    public function test_github_collector_sends_required_user_agent_to_every_api_request(): void
    {
        $calls=[];
        $client=new HttpTransport(static function($method,$url,$headers) use (&$calls) {
            $calls[]=['method'=>$method,'url'=>$url,'headers'=>$headers];
            if (!isset($headers['User-Agent']) || trim($headers['User-Agent'])==='') {
                return ['status'=>403,'body'=>'{"message":"Missing user agent"}'];
            }
            if ($url==='https://api.github.com/user') {
                return ['status'=>200,'body'=>json_encode(['login'=>'example-user'])];
            }
            if ($url==='https://api.github.com/users/example-user/events?per_page=100') {
                return ['status'=>200,'body'=>'[]'];
            }
            throw new \RuntimeException('Unexpected external endpoint');
        });
        $result=(new GitHubSource($client))->collect('fake-test-token');
        self::assertSame('ok',$result['status']);
        self::assertCount(2,$calls);
        foreach ($calls as $call) {
            self::assertSame('GET',$call['method']);
            self::assertSame('Bearer fake-test-token',$call['headers']['Authorization']);
            self::assertNotEmpty($call['headers']['User-Agent']);
            self::assertSame('2022-11-28',$call['headers']['X-GitHub-Api-Version']);
        }
    }

    public function test_github_unconfigured_is_optional(): void
    {
        $record=(new GitHubSource(new HttpTransport()))->collect('');
        self::assertSame('not_configured',$record['status']);
    }
}
