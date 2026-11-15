<?php
declare(strict_types=1);
namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\StateMigrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StateMigratorTest extends TestCase
{
    public function test_database_is_snapshotted_consistently_without_modifying_original(): void
    {
        $dir=sys_get_temp_dir().'/kimai-migrate-'.bin2hex(random_bytes(8));
        mkdir($dir,0700);
        $source=$dir.'/original.sqlite3';
        $target=$dir.'/archive/readonly-snapshot.sqlite3';
        $db=new PDO('sqlite:'.$source);
        $db->exec('CREATE TABLE sessions (id INTEGER PRIMARY KEY, status TEXT)');
        $db->exec("INSERT INTO sessions VALUES (1,'approved')");
        unset($db);chmod($source,0600);
        $before=hash_file('sha256',$source);
        try {
            $result=StateMigrator::snapshot($source,$target,['sessions']);
            self::assertTrue($result['snapshot_verified']);
            self::assertFalse($result['mutated_source']);
            self::assertSame(1,$result['tables']['sessions']);
            self::assertSame($before,hash_file('sha256',$source));
            self::assertSame(0,fileperms($target)&0077);
            $this->expectException(RuntimeException::class);
            StateMigrator::snapshot($source,$target,['sessions']);
        } finally {
            @unlink($target);@rmdir(dirname($target));@unlink($source);@rmdir($dir);
        }
    }
}
