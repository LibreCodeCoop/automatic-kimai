<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\ReviewQueue;
use Tests\TestCase;

final class ReviewWorkflowFeatureTest extends TestCase
{
    private LocalStore $store;
    private string $folder;
    private array $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->folder=sys_get_temp_dir().'/kimai-review-web-'.bin2hex(random_bytes(8));
        $this->store=new LocalStore($this->folder);
        $this->app->instance(LocalStore::class,$this->store);
        $this->store->saveProfile(new Profile([
            'profile_id'=>'worker','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
        ]));
        $this->candidate=[
            'id'=>'aw-webreview0000001','date'=>'2026-11-12',
            'start'=>'2026-11-12T09:00:00-03:00',
            'end'=>'2026-11-12T10:00:00-03:00','ongoing'=>false,
            'end_stable'=>true,
            'remote_intersections'=>[],
            'observed_seconds_by_class'=>['work_context_observed'=>3000],
            'no_aw_data_seconds'=>600,
        ];
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        $queue->synchronize([$this->candidate],[]);
        $queue->close();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder.'/*')?:[] as $path) {
            if (is_file($path))@unlink($path);
        }
        @rmdir($this->folder);
        parent::tearDown();
    }

    public function test_review_screen_displays_candidates_without_enabling_remote_publication(): void
    {
        $this->get('http://localhost/?view=review')->assertOk()
            ->assertSee('Fila de revisão com decisão humana')
            ->assertSee('Rejeitar esta proposta')
            ->assertSee('aw-webreview0000001')
            ->assertSee('Catálogo Kimai indisponível')
            ->assertDontSee('name="kimai_token"',false);
        $this->post('http://localhost/publish')->assertNotFound();
    }

    public function test_worker_can_reject_candidate_without_credential_or_remote_write(): void
    {
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        $digest=$queue->rows()[0]['source_digest'];
        $queue->close();
        $this->post('http://localhost/review/reject',[
            'candidate_id'=>$this->candidate['id'],
            'source_digest'=>$digest,
        ])->assertRedirect();
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        self::assertSame('rejected',$queue->rows()[0]['status']);
        $queue->close();
        self::assertFileDoesNotExist($this->store->ledgerFile());
    }

    public function test_mutations_are_rejected_from_nonlocal_origins(): void
    {
        $this->post('http://outside.example/review/reject',[
            'candidate_id'=>$this->candidate['id'],
            'source_digest'=>str_repeat('a',64),
        ])->assertForbidden();
        $this->post('http://outside.example/review/approve',[
            'candidate_id'=>$this->candidate['id'],
            'source_digest'=>str_repeat('a',64),
        ])->assertForbidden();
    }
}
