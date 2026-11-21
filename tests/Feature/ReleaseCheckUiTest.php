<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\AutomaticKimai\OfficialRelease;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ReleaseCheckUiTest extends TestCase
{
    public function test_footer_always_identifies_unpackaged_build_and_provides_check_button(): void
    {
        $res=$this->get('http://localhost/?view=overview')->assertOk();
        $res->assertSee('id="app-version-label"',false)
            ->assertSee('Prévia local')
            ->assertSee('id="app-check-updates"',false)
            ->assertSee('Verificar atualizações')
            ->assertSee('id="updates-panel"',false);
        $this->get('http://localhost/?view=settings_sync')->assertOk()
            ->assertSee('id="app-version-label"',false);
    }

    public function test_public_release_check_returns_only_validated_version_without_secrets(): void
    {
        Http::preventStrayRequests();
        Http::fake([OfficialRelease::API_URL=>Http::response([
            'tag_name'=>'v0.7.0','draft'=>false,'prerelease'=>false,
            'body'=>'Private data must not be echoed', 'assets'=>[],
        ])]);
        $res=$this->getJson('http://localhost/updates/latest')->assertOk()
            ->assertJsonPath('version','0.7.0')
            ->assertJsonPath('url',OfficialRelease::RELEASES_URL);
        $res->assertDontSee('Private data must not be echoed');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) =>
            $request->url()===OfficialRelease::API_URL &&
            !$request->hasHeader('Authorization') &&
            !$request->hasHeader('Cookie')
        );
    }

    public function test_pre_releases_and_non_semver_tags_are_not_accepted(): void
    {
        foreach ([
            ['tag_name'=>'v0.8.0','draft'=>true,'prerelease'=>false],
            ['tag_name'=>'v0.8.0','draft'=>false,'prerelease'=>true],
            ['tag_name'=>'v0.8.0<script>','draft'=>false,'prerelease'=>false],
        ] as $release) {
            Http::fake([OfficialRelease::API_URL=>Http::response($release)]);
            $this->getJson('http://localhost/updates/latest')->assertStatus(503)
                ->assertJson(['error'=>'release_unavailable']);
        }
    }

    public function test_remote_failure_is_generic_and_does_not_expose_provider_body(): void
    {
        Http::fake([OfficialRelease::API_URL=>Http::response('provider-internal-error',502)]);
        $this->getJson('http://localhost/updates/latest')->assertStatus(503)
            ->assertJson(['error'=>'release_unavailable'])
            ->assertDontSee('provider-internal-error');
    }

    public function test_release_notes_dialog_and_manual_reopen_control_are_available(): void
    {
        $this->get('http://localhost/?view=overview')->assertOk()
            ->assertSee('id="release-notes-dialog"', false)
            ->assertSee('id="updates-release-notes"', false)
            ->assertSee('id="release-notes-content"', false)
            ->assertSee('Ver novidades desta versão');
    }
}
