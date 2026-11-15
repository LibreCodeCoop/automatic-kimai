<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Copy explicitly established legacy *policy*, never Python execution code,
 * credentials, or a writer enable flag. The PHP runtime remains read-only.
 */
final class AutomaticKimaiInheritLegacyPolicy extends Command
{
    protected $signature='automatic-kimai:inherit-legacy-policy
        {--legacy-profile= : Absolute path to the former private cooperator_agent_profile.json}
        {--apply-policy : Import policy into the existing PHP profile}';

    protected $description='Transfer previous explicit work-context and preview opt-ins without activating Kimai writes';

    public function handle(LocalStore $store): int
    {
        try {
            $path=$this->option('legacy-profile');
            if (!is_string($path) || !str_starts_with($path,'/') ||
                !is_file($path) || is_link($path) || is_link(dirname($path)) ||
                (DIRECTORY_SEPARATOR==='/' && (fileperms($path)&0077)!==0)) {
                throw new RuntimeException('Private legacy profile required');
            }
            $old=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
            $profile=$store->profile();
            if (!is_array($old)||$profile===null) {
                throw new RuntimeException('Missing source or target profile');
            }
            foreach (['profile_id','timezone','customer_id','project_id','activity_id'] as $key) {
                if (!array_key_exists($key,$old)||$old[$key]!==$profile->get($key)) {
                    throw new RuntimeException('Cross-account policy import forbidden');
                }
            }
            if (!is_array($old['work_messenger_labels']??null)) {
                throw new RuntimeException('Legacy work source policy missing');
            }
            $mode=$old['sync_mode']??'review_only';
            $optIn=$old['allow_auto_nonbillable_preview']??false;
            if (!in_array($mode,['review_only','preview_nonbillable'],true)||
                !is_bool($optIn) ||
                ($mode==='preview_nonbillable' && $optIn!==true)) {
                throw new RuntimeException('Legacy auto preview consent is malformed');
            }
            // An existing PHP-specific consent must not be revoked by a
            // stale Python file. Only initial read-only settings may inherit.
            if (($profile->get('sync_mode')!=='review_only' ||
                 $profile->get('allow_auto_nonbillable_preview')!==false) &&
                ($profile->get('sync_mode')!==$mode ||
                 $profile->get('allow_auto_nonbillable_preview')!==$optIn)) {
                throw new RuntimeException('Existing PHP publication preference differs');
            }
            $next=new Profile(array_replace($profile->toArray(),[
                'work_messenger_classification_labels'=>array_values($old['work_messenger_labels']),
                'sync_mode'=>$mode,
                'allow_auto_nonbillable_preview'=>$optIn,
            ]));
            $result=[
                'mode'=>$this->option('apply-policy')?'local_policy_import':'read_only',
                'classification_labels'=>count($next->workEvidenceLabels()),
                'telegram_selected_groups'=>count($next->get('work_messenger_dialog_ids')),
                'auto_preview_opt_in_preserved'=>$optIn,
                'remote_write_enabled_by_command'=>false,
            ];
            if ($this->option('apply-policy'))$store->saveProfile($next);
            $this->line(json_encode($result,JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('LEGACY_POLICY_IMPORT_FAILED '.get_class($e));
            return self::FAILURE;
        }
    }
}
