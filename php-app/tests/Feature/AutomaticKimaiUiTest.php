<?php

namespace Tests\Feature;

use Tests\TestCase;

class AutomaticKimaiUiTest extends TestCase
{
    public function test_dashboard_opens_without_private_credentials(): void
    {
        $response=$this->get('http://localhost/');
        $response->assertOk();
        $response->assertSee('Automatic Kimai');
        $response->assertSee('somente leitura');
        $response->assertDontSee('sk-test-token');
    }

    public function test_each_workspace_page_exposes_only_its_relevant_controls(): void
    {
        $home=$this->get('http://localhost/?view=overview')->assertOk();
        $home->assertSee('Resumo do trabalho')->assertDontSee('id="tg-api-id"',false);
        $review=$this->get('http://localhost/?view=review')->assertOk();
        $review->assertSee('Intervalos que precisam de revisão')->assertDontSee('id="kimai-token"',false);
        $settings=$this->get('http://localhost/?view=settings')->assertOk();
        $settings->assertSee('Buscar meus projetos')->assertSee('id="kimai-url"',false)
            ->assertDontSee('id="tg-api-id"',false);
        $integrations=$this->get('http://localhost/?view=integrations')->assertOk();
        $integrations->assertSee('id="telegram-setup"',false)->assertDontSee('id="kimai-token"',false);
    }

    public function test_unknown_page_falls_back_to_overview(): void
    {
        $this->get('http://localhost/?view=invalid')->assertOk()->assertSee('Resumo do trabalho');
    }

    public function test_public_network_host_is_blocked(): void
    {
        $this->get('http://evil.example/')->assertForbidden();
    }

    public function test_automatic_publication_is_not_exposed_as_http_action(): void
    {
        $this->post('http://localhost/publish')->assertNotFound();
    }
}
