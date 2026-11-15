<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use PDO;
use RuntimeException;

/**
 * One-time, read-only SQLite snapshots for cross-runtime migration. Online
 * VACUUM INTO is SQLite-consistent without mutating the Python databases.
 */
final class StateMigrator
{
    public static function snapshot(string $source,string $target,array $requiredTables): array
    {
        if (!is_file($source)||is_link($source)||is_link(dirname($source))) {
            throw new RuntimeException('Refusing missing or linked source database');
        }
        if (file_exists($target)||is_link($target)||is_link(dirname($target))) {
            throw new RuntimeException('Never overwrite a previously verified migration snapshot');
        }
        if (DIRECTORY_SEPARATOR==='/' && (fileperms($source)&0077)!==0) {
            throw new RuntimeException('Source database must be owner-only');
        }
        $parent=dirname($target);
        if (!is_dir($parent)&&!mkdir($parent,0700,true)&&!is_dir($parent)) {
            throw new RuntimeException('Cannot create private snapshot directory');
        }
        chmod($parent,0700);
        $input=new PDO('sqlite:file:'.$source.'?mode=ro',null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT=>15,
        ]);
        $sourceTables=[];
        $available=$input->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($requiredTables as $name) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,60}$/D',$name)||!in_array($name,$available,true)) {
                throw new RuntimeException('Legacy migration schema does not match expected tables');
            }
            $sourceTables[$name]=(int)$input->query('SELECT COUNT(*) FROM "'.$name.'"')->fetchColumn();
        }
        $old=umask(0077);
        try {
            $input->exec('VACUUM INTO '.$input->quote($target));
        } finally {umask($old);}
        chmod($target,0600);
        unset($input);
        $copy=new PDO('sqlite:file:'.$target.'?mode=ro');
        if ($copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok') {
            throw new RuntimeException('SQLite migration snapshot failed integrity_check');
        }
        $result=[];
        foreach ($sourceTables as $name=>$expected) {
            $count=(int)$copy->query('SELECT COUNT(*) FROM "'.$name.'"')->fetchColumn();
            if ($count<$expected)throw new RuntimeException('SQLite snapshot contains fewer rows than source');
            $result[$name]=$count;
        }
        unset($copy);
        return [
            'snapshot_verified'=>true,
            'tables'=>$result,
            'bytes'=>filesize($target),
            'mutated_source'=>false,
        ];
    }
}
