<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\HttpTransport;
use App\AutomaticKimai\NextcloudSource;
use App\AutomaticKimai\Profile;
use PHPUnit\Framework\TestCase;

final class NextcloudTalkCoverageTest extends TestCase
{
    private function profile(): Profile
    {
        return new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'lookback_days'=>7,
        ]);
    }

    private function collect(int $roomCount): array
    {
        $requests=0;
        $transport=new HttpTransport(static function($method,$url,$headers,$body,$auth) use (&$requests,$roomCount) {
            if ($method!=='GET'||($headers['OCS-APIRequest']??null)!=='true') {
                throw new \RuntimeException('Unsafe Nextcloud request');
            }
            $requests++;
            $data=null;
            if (str_contains($url,'/ocs/v2.php/cloud/user')) {
                $data=['id'=>'worker'];
            } elseif (str_contains($url,'/ocs/v2.php/apps/spreed/api/v4/room')) {
                $data=[];
                for ($i=1;$i<=$roomCount;$i++) {
                    $data[]=['token'=>'room'.str_pad((string)$i,5,'0',STR_PAD_LEFT),
                        'lastActivity'=>time(),'hasCall'=>false,'displayName'=>'Sensitive title'];
                }
            } elseif (str_contains($url,'/ocs/v2.php/apps/spreed/api/v1/chat/')) {
                if (!str_contains($url,'setReadMarker=0')||
                    !str_contains($url,'markNotificationsAsRead=0')||
                    !str_contains($url,'noStatusUpdate=1')) {
                    throw new \RuntimeException('Talk call must not mark as read');
                }
                $last='room'.str_pad((string)$roomCount,5,'0',STR_PAD_LEFT);
                $data=str_contains($url,'/'.$last.'?')?[
                    ['timestamp'=>time(),'actorId'=>'worker','message'=>'Sensitive body'],
                ]:[];
            } else {
                throw new \RuntimeException('Unexpected provider endpoint');
            }
            return ['status'=>200,'body'=>json_encode([
                'ocs'=>['meta'=>['statuscode'=>200],'data'=>$data],
            ],JSON_THROW_ON_ERROR)];
        });
        $out=(new NextcloudSource($transport))->talk($this->profile(),[
            'NEXTCLOUD_URL'=>'https://cloud.example.org',
            'NEXTCLOUD_USERNAME'=>'worker',
            'NEXTCLOUD_LOGIN_NAME'=>'worker@example.org',
            'NEXTCLOUD_APP_PASSWORD'=>'fixture-app-password',
        ]);
        $out['test_request_count']=$requests;
        return $out;
    }

    public function test_talk_scans_recent_rooms_beyond_old_first_thirty_limit(): void
    {
        $data=$this->collect(35);
        self::assertSame(35,$data['rooms_total']);
        self::assertSame(35,$data['rooms_scanned']);
        self::assertSame(35,$data['rooms_analyzed']);
        self::assertFalse($data['rooms_truncated']);
        self::assertSame(0,$data['rooms_unavailable']);
        self::assertCount(1,$data['own_actions']);
        self::assertSame(37,$data['test_request_count']);
        self::assertStringNotContainsString('Sensitive body',json_encode($data));
        self::assertStringNotContainsString('Sensitive title',json_encode($data));
    }

    public function test_talk_marks_room_limit_explicitly_partial(): void
    {
        $data=$this->collect(121);
        self::assertSame(121,$data['rooms_total']);
        self::assertSame(120,$data['rooms_scanned']);
        self::assertTrue($data['rooms_truncated']);
        self::assertSame(120,$data['rooms_analyzed']);
        self::assertSame(0,$data['rooms_unavailable']);
    }
}
