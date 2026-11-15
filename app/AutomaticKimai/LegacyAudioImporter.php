<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Imports only PipeWire stream-state metadata (never speech or audio).
 * Re-running after more legacy samples are collected fills missing timestamps.
 * It does not infer work hours, attendance or payroll eligibility.
 */
final class LegacyAudioImporter
{
    public function import(string $sourceFile,string $targetFile): array
    {
        if (!is_file($sourceFile)||is_link($sourceFile)||is_link(dirname($sourceFile))||
            (DIRECTORY_SEPARATOR==='/'&&(fileperms($sourceFile)&0077)!==0)) {
            throw new RuntimeException('Legacy audio metadata must be owner-private');
        }
        $old=new PDO('sqlite:file:'.$sourceFile.'?mode=ro',null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>15,
        ]);
        $old->exec('PRAGMA query_only=ON');
        if ($old->query('PRAGMA quick_check')->fetchColumn()!=='ok') {
            throw new RuntimeException('Legacy audio DB failed integrity check');
        }
        $columns=['timestamp_utc','telegram_capture','telegram_playback',
            'telegram_communication','probe_ok'];
        $schema=array_column($old->query('PRAGMA table_info("samples")')->fetchAll(),'name');
        if (array_diff($columns,$schema)!==[]) {
            throw new RuntimeException('Audio metadata schema mismatch');
        }

        $dest=TelegramAudio::open($targetFile);
        $dest->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $existing=$dest->prepare('SELECT telegram_capture,telegram_playback,
            telegram_communication,probe_ok FROM samples WHERE timestamp_utc=?');
        $insert=$dest->prepare('INSERT INTO samples(
            timestamp_utc,telegram_capture,telegram_playback,
            telegram_communication,probe_ok) VALUES(?,?,?,?,?)');
        $copied=0;$seen=0;
        $dest->exec('BEGIN IMMEDIATE');
        try {
            $query=$old->query('SELECT '.implode(',',$columns).' FROM samples ORDER BY timestamp_utc');
            foreach ($query as $row) {
                $seen++;
                $when=(string)$row['timestamp_utc'];
                if ($when===''||strlen($when)>45)throw new RuntimeException('Invalid audio timestamp');
                foreach (array_slice($columns,1) as $flag) {
                    if (!in_array((string)$row[$flag],['0','1'],true)) {
                        throw new RuntimeException('Invalid legacy audio flag');
                    }
                }
                $existing->execute([$when]);
                $prior=$existing->fetch(PDO::FETCH_ASSOC);
                if ($prior!==false) {
                    foreach (array_slice($columns,1) as $flag) {
                        if ((string)$prior[$flag]!== (string)$row[$flag]) {
                            throw new RuntimeException('Conflicting audio provenance at same timestamp');
                        }
                    }
                    continue;
                }
                $insert->execute(array_map(static fn($key)=>$row[$key],$columns));
                $copied++;
            }
            $dest->commit();
        } catch (Throwable $e) {
            if ($dest->inTransaction())$dest->rollBack();
            throw $e;
        }
        return [
            'source_samples'=>$seen,'new_samples'=>$copied,
            'audio_recordings_copied'=>false,'counts_as_work'=>false,
        ];
    }
}
