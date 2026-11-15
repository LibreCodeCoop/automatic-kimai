<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\TelegramSource;
use App\AutomaticKimai\TelegramSessionMigration;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TelegramMigrationTest extends TestCase
{
    public function test_only_whitelisted_dialogs_have_history_queried(): void
    {
        $client=new class {
            public int $reads=0;
            public function dialogs(): array {
                return [
                    ['title'=>'Acme support','id'=>11,'peer'=>11,'read_inbox_max_id'=>40],
                    ['title'=>'Personal family','id'=>12,'peer'=>12],
                    ['title'=>'Acme and Other','id'=>13,'peer'=>13],
                ];
            }
            public function history($peer,$limit): array {
                $this->reads++;
                if ($peer!==11)throw new RuntimeException('Unauthorized dialog history');
                return [[
                    'id'=>12,'date'=>1793961000,'out'=>true,
                    'message'=>'Bug fixed during client meeting',
                    'media'=>['_'=>'messageMediaDocument'],
                ]];
            }
        };
        $answer=(new TelegramSource())->scan($client,['Acme','Other'],14,600,
            new DateTimeImmutable('@1793962000'));
        self::assertSame(1,$client->reads);
        self::assertSame(1,$answer['matched_dialog_count']);
        self::assertCount(1,$answer['scoped_client_chats'][0]['observations']);
        self::assertStringNotContainsString('Bug fixed',json_encode($answer));
        self::assertTrue($answer['no_message_bodies_stored']);
        self::assertFalse($answer['scoped_client_chats'][0]['eligible_for_sync']);
    }

    public function test_classification_does_not_include_private_message_body(): void
    {
        $result=TelegramSource::sanitizeMessage([
            'date'=>1793961000,'id'=>33,'out'=>true,'message'=>'Support call for a private client',
            'action'=>['_'=>'messageActionPhoneCall','duration'=>450,'video'=>true],
        ]);
        self::assertSame(450,$result['call_duration_seconds']);
        self::assertSame('direct_call',$result['kind']);
        self::assertTrue($result['video']);
        self::assertStringNotContainsString('private client',json_encode($result));
    }

    public function test_explicit_label_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TelegramSource())->scan(new \stdClass(),[],10);
    }

    public function test_session_import_requires_valid_private_sqlite_source(): void
    {
        $temp=sys_get_temp_dir().'/kimai-session-test-'.bin2hex(random_bytes(6));
        mkdir($temp,0700);
        $path=$temp.'/test.session';
        $sqlite=new \PDO('sqlite:'.$path);
        $sqlite->exec('CREATE TABLE sessions(dc_id INTEGER,auth_key BLOB)');
        $sqlite->exec('INSERT INTO sessions VALUES(2,randomblob(255))');
        unset($sqlite);
        chmod($path,0600);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('format invalid');
            TelegramSessionMigration::validateSource($path);
        } finally {
            @unlink($path);@rmdir($temp);
        }
    }
}
