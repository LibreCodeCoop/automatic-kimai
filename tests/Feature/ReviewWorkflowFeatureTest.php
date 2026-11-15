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
            ->assertSee('Resultado da revisão automática')
            ->assertSee('Rejeitar esta proposta')
            ->assertSee('aw-webreview0000001')
            ->assertSee('Carregar projetos e atividades ao revisar o período')
            ->assertDontSee('name="kimai_token"',false);
        $this->post('http://localhost/publish')->assertNotFound();
    }

    public function test_review_renders_without_waiting_for_remote_catalog(): void
    {
        $this->store->saveCredentials([
            'KIMAI_BASE_URL'=>'https://kimai.example.invalid',
            'KIMAI_API_TOKEN'=>'fixture-token-for-test',
        ]);
        $this->get('http://localhost/?view=review')->assertOk()
            ->assertSee('Carregar projetos e atividades ao revisar o período')
            ->assertSee('data-review-catalog',false)
            ->assertSee('name="project_id" data-default',false)
            ->assertSee('required disabled',false)
            ->assertDontSee('Catálogo Kimai indisponível');
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

    public function test_autonomous_triage_leaves_no_manual_review_and_preserves_audit(): void
    {
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        $decision=$queue->autoAssess(['2026-11-12'=>[]],[],
            $this->store->profile(),[],['2026-11-12']);
        self::assertSame(1,$decision['newly_assessed']);
        self::assertSame('auto_observed',$queue->rows()[0]['status']);
        self::assertSame([], $queue->approvedRows());
        $queue->close();
        $this->get('http://localhost/?view=review')->assertOk()
            ->assertSee('Nenhuma pendência de revisão manual')
            ->assertSee('Histórico auditável da conciliação')
            ->assertSee('Observação avaliada automaticamente')
            ->assertDontSee('Rejeitar esta proposta')
            ->assertDontSee('Aprovar somente na fila local')
            ->assertDontSee('Revisar</span>',false);
        self::assertFileDoesNotExist($this->store->ledgerFile());
    }

    public function test_conflict_requires_explicit_discard_and_never_creates_remote_write(): void
    {
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        $this->candidate['remote_intersections']=[65];
        $queue->synchronize([$this->candidate],[
            ['id'=>65,'begin'=>'2026-11-12T09:30:00-03:00','end'=>null],
        ]);
        $candidate=$queue->rows()[0];
        self::assertSame('conflict',$candidate['status']);
        $queue->close();
        $this->get('http://localhost/?view=review')->assertOk()
            ->assertSee('Sobreposição com horas já registradas')
            ->assertSee('Confirmar descarte da sugestão')
            ->assertSee('Reanalisar sobreposições')
            ->assertSee('#65')
            ->assertDontSee('name="project_id" data-default',false);
        $details='http://localhost/review/conflict/details?candidate_id='.$candidate['id'].
            '&source_digest='.$candidate['source_digest'];
        $this->get($details)->assertStatus(422)
            ->assertJsonPath('error','Não foi possível confirmar os registros no Kimai. Tente novamente.');
        $this->get('http://outside.example/review/conflict/details?candidate_id='.$candidate['id'].
            '&source_digest='.$candidate['source_digest'])->assertForbidden();
        $url='http://localhost/review/conflict';
        $base=['candidate_id'=>$candidate['id'],'source_digest'=>$candidate['source_digest']];
        $this->post($url,$base+['decision'=>'already_recorded'])->assertSessionHasErrors('confirm_discard');
        $this->post($url,$base+['decision'=>'invented','confirm_discard'=>'1'])->assertSessionHasErrors('decision');
        $this->post($url,array_replace($base,['decision'=>'already_recorded','confirm_discard'=>'1',
            'source_digest'=>str_repeat('a',64)]))->assertSessionHasErrors('review');
        $this->post($url,$base+['decision'=>'already_recorded','confirm_discard'=>'1'])->assertRedirect();
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        self::assertSame('rejected',$queue->rows()[0]['status']);
        self::assertStringContainsString('existing Kimai records kept unchanged',
            $queue->rows()[0]['hold_reason']);
        $queue->synchronize([$this->candidate],[
            ['id'=>65,'begin'=>'2026-11-12T09:30:00-03:00','end'=>null],
        ]);
        self::assertSame('rejected',$queue->rows()[0]['status']);
        $queue->close();
        self::assertFileDoesNotExist($this->store->ledgerFile());
        $this->post($url,$base+['decision'=>'already_recorded','confirm_discard'=>'1'])->assertSessionHasErrors('review');
        $this->post('http://outside.example/review/conflict',
            $base+['decision'=>'already_recorded','confirm_discard'=>'1'])->assertForbidden();
    }

    public function test_partial_remote_coverage_becomes_visible_separate_reviewable_gap(): void
    {
        $queue=new ReviewQueue($this->store->reviewQueueFile());
        $queue->synchronize([$this->candidate],[
            ['id'=>81,'begin'=>'2026-11-12T09:00:00-03:00','end'=>'2026-11-12T09:15:00-03:00'],
            ['id'=>82,'begin'=>'2026-11-12T09:45:00-03:00','end'=>'2026-11-12T10:00:00-03:00'],
        ]);
        $rows=$queue->rows();
        self::assertSame(['reconciled_partial','draft'],array_column($rows,'status'));
        self::assertSame(1800,$rows[0]['remote_uncovered_seconds']);
        $queue->close();
        $this->get('http://localhost/?view=review')->assertOk()
            ->assertSee('propostas reconciliadas automaticamente com o Kimai')
            ->assertSee('Sobreposição conciliada')
            ->assertSee('Lacuna não registrada')
            ->assertSee('Trecho não coberto pelo Kimai')
            ->assertSee('09:15–09:45')
            ->assertSee('gap-',false)
            ->assertSee('data-review-catalog',false);
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
