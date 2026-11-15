<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\PublicationGate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PublicationGateTest extends TestCase
{
    private string $dir;
    private LocalStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir=sys_get_temp_dir().'/kimai-gate-'.bin2hex(random_bytes(7));
        mkdir($this->dir,0700);
        $this->store=new LocalStore($this->dir);
    }

    protected function tearDown(): void
    {
        foreach(glob($this->dir.'/*')?:[] as $path)@unlink($path);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function profile(bool $auto=true): Profile
    {
        return new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'sync_mode'=>$auto?'preview_nonbillable':'review_only',
            'allow_auto_nonbillable_preview'=>$auto,
        ]);
    }

    private function gate(): array
    {
        return [
            'writer'=>'php','supervised_cutover_completed'=>true,
            'legacy_publisher_stopped'=>true,'history_caught_up'=>true,
            'verified_user_id'=>1,'profile_id'=>'worker',
            'history_snapshot_sha256'=>str_repeat('a',64),
            'verified_at_utc'=>'2026-11-15T12:00:00+00:00',
        ];
    }

    public function test_default_is_read_only_even_with_automatic_opt_in(): void
    {
        $creds=['KIMAI_WRITE_ENABLED'=>'true','KIMAI_AUTO_PREVIEW_OPT_IN'=>'true'];
        $this->expectException(RuntimeException::class);
        PublicationGate::assertReady($this->store,$this->profile(),$creds,1);
    }

    public function test_explicit_verified_handoff_allows_selected_worker_only(): void
    {
        $this->store->write('cutover.json',$this->gate());
        $creds=['KIMAI_WRITE_ENABLED'=>'true','KIMAI_AUTO_PREVIEW_OPT_IN'=>'true'];
        PublicationGate::assertReady($this->store,$this->profile(),$creds,1);
        self::assertTrue(true);
    }

    public function test_incomplete_handoff_or_wrong_identity_fails_closed(): void
    {
        $creds=['KIMAI_WRITE_ENABLED'=>'true','KIMAI_AUTO_PREVIEW_OPT_IN'=>'true'];
        $badStates=[
            ['legacy_publisher_stopped'=>false],
            ['history_caught_up'=>false],
            ['writer'=>'python'],
            ['verified_user_id'=>2],
            ['profile_id'=>'other'],
            ['history_snapshot_sha256'=>'not-sha256'],
        ];
        foreach($badStates as $mutation) {
            $this->store->write('cutover.json',array_replace($this->gate(),$mutation));
            try {
                PublicationGate::assertReady($this->store,$this->profile(),$creds,1);
                self::fail('Unsafe handoff accepted');
            } catch(RuntimeException) {
                self::assertTrue(true);
            }
        }
        $this->store->write('cutover.json',$this->gate());
        foreach([['KIMAI_WRITE_ENABLED'=>'false','KIMAI_AUTO_PREVIEW_OPT_IN'=>'true'],
                 ['KIMAI_WRITE_ENABLED'=>'true','KIMAI_AUTO_PREVIEW_OPT_IN'=>'false']] as $flags) {
            try {
                PublicationGate::assertReady($this->store,$this->profile(),$flags,1);
                self::fail('Missing opt-in accepted');
            } catch(RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_manual_confirmation_is_separate_from_auto_writer_consent(): void
    {
        $this->store->write('cutover.json',$this->gate());
        PublicationGate::assertManualReady($this->store,$this->profile(false),
            ['KIMAI_WRITE_ENABLED'=>'true'],1);
        self::assertTrue(true);
    }
}
