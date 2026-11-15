<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\HttpTransport;
use App\AutomaticKimai\NextcloudActivitySource;
use App\AutomaticKimai\NextcloudMailSource;
use App\AutomaticKimai\NextcloudIdentity;
use App\AutomaticKimai\Profile;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NextcloudMetadataTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile([
            'profile_id'=>'worker',
            'timezone'=>'America/Sao_Paulo',
            'lookback_days'=>7,
            'customer_id'=>1,'project_id'=>2,'activity_id'=>3,
        ]);
    }

    private function credentials(): array
    {
        return [
            'NEXTCLOUD_URL'=>'https://cloud.example.org/nextcloud',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_LOGIN_NAME'=>'worker@example.org',
            'NEXTCLOUD_APP_PASSWORD'=>'private-fixture-password',
        ];
    }

    public function test_activity_keeps_only_own_event_timestamps_and_categories(): void
    {
        $requests=[];
        $transport=new HttpTransport(static function($method,$url,$headers,$body,$auth)use(&$requests) {
            $requests[]=[$method,$url,$headers,$auth];
            return [
                'status'=>200,
                'body'=>json_encode(['ocs'=>[
                    'meta'=>['statuscode'=>200],
                    'data'=>[
                        ['activity_id'=>102,'datetime'=>gmdate(DATE_ATOM,1794654000),'user'=>'worker',
                            'app'=>'files','type'=>'file_changed',
                            'subject'=>'Secret patient record','object_name'=>'/secret/patient.pdf'],
                        ['activity_id'=>101,'datetime'=>gmdate(DATE_ATOM,1794654000),'user'=>'other-person',
                            'app'=>'files','type'=>'file_changed',
                            'subject'=>'Other user private information'],
                    ],
                ]],JSON_THROW_ON_ERROR),
            ];
        });
        $result=(new NextcloudActivitySource($transport))->collect(
            $this->profile(),$this->credentials(),new DateTimeImmutable('2026-11-15T12:00:00Z')
        );
        self::assertCount(1,$result['own_actions']);
        self::assertSame('nextcloud_activity',$result['own_actions'][0]['source']);
        self::assertSame('files',$result['own_actions'][0]['app']);
        self::assertFalse($result['own_actions'][0]['verified_human_duration']);
        self::assertFalse($result['eligible_for_auto_publication']);
        self::assertStringNotContainsString('Secret patient',json_encode($result));
        self::assertStringNotContainsString('patient.pdf',json_encode($result));
        self::assertStringContainsString('/nextcloud/ocs/v2.php/apps/activity/api/v2/activity?',
            $requests[0][1]);
        self::assertStringContainsString('actor=worker',$requests[0][1]);
        self::assertSame(['worker@example.org','private-fixture-password'],$requests[0][3]);
        self::assertSame('true',$requests[0][2]['OCS-APIRequest']);
    }

    public function test_mail_uses_sent_folder_only_and_discards_message_contents(): void
    {
        $requests=[];
        $transport=new HttpTransport(static function($method,$url,$headers,$body,$auth)use(&$requests) {
            $requests[]=$url;
            if (str_contains($url,'/api/accounts')) {
                return ['status'=>200,'body'=>json_encode([
                    ['accountId'=>7,'sentMailboxId'=>42,'isDelegated'=>false],
                    ['accountId'=>8,'sentMailboxId'=>43,'isDelegated'=>true],
                ],JSON_THROW_ON_ERROR)];
            }
            if (str_contains($url,'mailboxId=42')) {
                return ['status'=>200,'body'=>json_encode([
                    ['databaseId'=>99,'dateInt'=>1794654000,'subject'=>'Sensitive client info',
                        'to'=>[['email'=>'client@secret.example']],
                        'previewText'=>'private message body'],
                    ['databaseId'=>98,'dateInt'=>1794654000,'flags'=>['draft'=>true]],
                ],JSON_THROW_ON_ERROR)];
            }
            throw new RuntimeException('Unexpected request');
        });
        $result=(new NextcloudMailSource($transport))->collect(
            $this->profile(),$this->credentials(),new DateTimeImmutable('2026-11-15T12:00:00Z')
        );
        self::assertSame(1,$result['accounts_scanned']);
        self::assertCount(1,$result['sent_actions']);
        self::assertSame('nextcloud_mail',$result['sent_actions'][0]['source']);
        self::assertSame('sent_message',$result['sent_actions'][0]['kind']);
        self::assertFalse($result['eligible_for_auto_publication']);
        self::assertCount(2,$requests);
        self::assertStringNotContainsString('mailboxId=43',implode(' ',$requests));
        foreach (['Sensitive','client@secret','private message body'] as $needle) {
            self::assertStringNotContainsString($needle,json_encode($result));
        }
    }

    public function test_mail_failure_does_not_return_stale_or_invented_events(): void
    {
        $transport=new HttpTransport(static fn()=>[
            'status'=>404,'body'=>'Mail app not found',
        ]);
        $this->expectException(RuntimeException::class);
        (new NextcloudMailSource($transport))->collect(
            $this->profile(),$this->credentials(),new DateTimeImmutable('2026-11-15T12:00:00Z')
        );
    }

    public function test_identity_fetches_only_the_authorized_profile_and_raster_avatar(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL2WAAAAABJRU5ErkJggg==',true);
        $calls=[];
        $transport=new HttpTransport(static function($method,$url,$headers,$body,$auth)use(&$calls,$png) {
            $calls[]=[$url,$auth];
            if (str_contains($url,'/avatar/')) {
                return ['status'=>200,'body'=>$png];
            }
            return ['status'=>200,'body'=>json_encode([
                'ocs'=>['meta'=>['statuscode'=>200],
                    'data'=>['id'=>'worker','displayname'=>'Worker Example']],
            ],JSON_THROW_ON_ERROR)];
        });
        $identity=new NextcloudIdentity($transport);
        self::assertSame(['username'=>'worker','display_name'=>'Worker Example'],
            $identity->profile($this->credentials()));
        self::assertSame('image/png',$identity->avatar($this->credentials())['type']);
        self::assertStringContainsString('/nextcloud/avatar/worker/64',$calls[1][0]);
        self::assertSame(['worker@example.org','private-fixture-password'],$calls[1][1]);
    }

    public function test_avatar_rejects_svg_and_identity_mismatch(): void
    {
        $evil=new NextcloudIdentity(new HttpTransport(static function($method,$url) {
            if (str_contains($url,'/avatar/')) {
                return ['status'=>200,'body'=>'<svg xmlns="http://www.w3.org/2000/svg"></svg>'];
            }
            return ['status'=>200,'body'=>json_encode([
                'ocs'=>['meta'=>['statuscode'=>200],'data'=>['id'=>'another-account']],
            ],JSON_THROW_ON_ERROR)];
        }));
        self::assertNull($evil->avatar($this->credentials()));
        $this->expectException(RuntimeException::class);
        $evil->profile($this->credentials());
    }
}
