<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class LegacyPolicyImportTest extends TestCase
{
    private LocalStore $private;
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        // The TestCase already isolates the real desktop state.
        $this->private=new LocalStore(sys_get_temp_dir().'/kimai-legacy-policy-'.bin2hex(random_bytes(8)));
        $this->app->instance(LocalStore::class,$this->private);
        $this->private->saveProfile(new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'work_messenger_dialog_ids'=>[-100991],
            'work_messenger_group_titles'=>['-100991'=>'Team A'],
            'work_messenger_labels'=>['Team A'],
        ]));
        $this->source=$this->private->root().'/legacy_profile.json';
        file_put_contents($this->source,json_encode([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'sync_mode'=>'preview_nonbillable','allow_auto_nonbillable_preview'=>true,
            'work_messenger_labels'=>['Historical A']
        ],JSON_THROW_ON_ERROR));
        chmod($this->source,0600);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->private->root().'/*')?:[] as $file)if(is_file($file))@unlink($file);
        @rmdir($this->private->root());
        parent::tearDown();
    }

    public function test_policy_import_is_explicit_and_preserves_selected_groups_without_enabling_writes(): void
    {
        self::assertSame(0,Artisan::call('automatic-kimai:inherit-legacy-policy',[
            '--legacy-profile'=>$this->source,
        ]));
        self::assertSame('review_only',$this->private->profile()->get('sync_mode'));
        self::assertSame([], $this->private->profile()->workEvidenceLabels());
        self::assertSame(0,Artisan::call('automatic-kimai:inherit-legacy-policy',[
            '--legacy-profile'=>$this->source,'--apply-policy'=>true
        ]));
        $profile=$this->private->profile();
        self::assertTrue($profile->allowsAutomaticPublication());
        self::assertSame(['Historical A'],$profile->workEvidenceLabels());
        self::assertSame([-100991],$profile->get('work_messenger_dialog_ids'));
        self::assertSame(['Team A'],$profile->get('work_messenger_labels'));
        self::assertSame([], $this->private->credentials());
        self::assertFileDoesNotExist($this->private->root().'/cutover.json');
        self::assertSame(0,Artisan::call('automatic-kimai:inherit-legacy-policy',[
            '--legacy-profile'=>$this->source,'--apply-policy'=>true
        ]));
    }

    public function test_writer_preflight_remains_locked_without_supervised_handoff(): void
    {
        self::assertSame(1,Artisan::call('automatic-kimai:verify-writer',[
            '--legacy-cache'=>$this->private->root(),
        ]));
        self::assertSame([], $this->private->credentials());
        self::assertFileDoesNotExist($this->private->root().'/cutover.json');
        self::assertFileDoesNotExist($this->private->root().'/ledger.sqlite3');
    }

    public function test_import_rejects_wrong_legacy_identity(): void
    {
        $bad=json_decode((string)file_get_contents($this->source),true,512,JSON_THROW_ON_ERROR);
        $bad['profile_id']='another';
        file_put_contents($this->source,json_encode($bad,JSON_THROW_ON_ERROR));
        self::assertSame(1,Artisan::call('automatic-kimai:inherit-legacy-policy',[
            '--legacy-profile'=>$this->source,'--apply-policy'=>true
        ]));
        self::assertSame('review_only',$this->private->profile()->get('sync_mode'));
    }
}
