<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\LegacyHistoryImporter;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Reconciler;
use Illuminate\Console\Command;
use Throwable;

/** Explicit one-time local import; never sends Kimai POST/PATCH. */
final class AutomaticKimaiImportLegacy extends Command
{
    protected $signature='automatic-kimai:import-legacy
        {--legacy-cache= : Private, consistent Python SQLite snapshot cache directory}
        {--apply-import : Import the verified state to PHP; defaults to no changes}';

    protected $description='Preserve original Python ledger, publication and review histories';

    public function handle(LocalStore $store): int
    {
        $legacy=$this->option('legacy-cache');
        if (!is_string($legacy)||$legacy==='') {
            $this->error('Private --legacy-cache directory is required.');
            return self::FAILURE;
        }
        if (!$this->option('apply-import')) {
            $this->info('READ_ONLY: Import not applied. Use --apply-import explicitly after verifying snapshots.');
            return self::SUCCESS;
        }
        try {
            $profile=$store->profile();
            $creds=$store->credentials();
            if (!$profile||empty($creds['KIMAI_BASE_URL'])||empty($creds['KIMAI_API_TOKEN'])) {
                throw new \RuntimeException('Missing local account profile or Kimai identity');
            }
            $api=new KimaiClient($creds['KIMAI_BASE_URL'],$creds['KIMAI_API_TOKEN']);
            $me=$api->me();
            if (!is_int($me['id']??null)||$me['id']<1||
                ($me['timezone']??null)!==$profile->get('timezone')) {
                throw new \RuntimeException('Remote Kimai account or timezone mismatched');
            }
            $byDay=[];
            $verify=static function(int $remoteId,array $expected)use(
                $api,$profile,$me,&$byDay):bool {
                if (!is_string($expected['begin']??null)||$remoteId<1)return false;
                try {
                    $day=(new \DateTimeImmutable($expected['begin']))
                        ->setTimezone($profile->zone)->format('Y-m-d');
                    if (!isset($byDay[$day])) {
                        $byDay[$day]=$api->timesheets($me['id'],[$day],$profile->zone);
                    }
                    foreach ($byDay[$day] as $row) {
                        if (($row['id']??null)!==$remoteId)continue;
                        $remote=Reconciler::snapshot($row);
                        // Require every field to match the authenticated
                        // user's live Kimai record exactly, not just its ID.
                        if (count($remote)!==count($expected))return false;
                        foreach ($remote as $key=>$value) {
                            if (!array_key_exists($key,$expected)||
                                $expected[$key]!==$value)return false;
                        }
                        return true;
                    }
                } catch (Throwable) {}
                return false;
            };
            $result=(new LegacyHistoryImporter())->import(
                $legacy,$store->root(),$me['id'],$profile->get('profile_id'),
                $verify
            );
            $this->line(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (Throwable $e) {
            // A credential or personal record must never reach a CLI log.
            $this->error('LEGACY_IMPORT_FAILED '.get_class($e));
            return self::FAILURE;
        }
    }
}
