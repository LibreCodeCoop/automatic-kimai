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
        $response->assertSee('sincronizador Python');
        $response->assertDontSee('sk-test-token');
    }

    public function test_navigation_uses_separate_screens_without_other_forms(): void
    {
        $expectations=[
            'overview'=>['Resumo do trabalho','name="nextcloud_url"'],
            'review'=>['Períodos para conferir','name="label"'],
            'attendance'=>['Trabalho presencial','name="telegram_labels"'],
            'manual'=>['Informar horas manualmente','name="kimai_url"'],
            'kimai'=>['Buscar meus projetos','id="tg-api-id"'],
            'sources'=>['Fontes de informação','id="kimai-token"'],
            'integrations'=>['Escolha a conta que deseja conectar','name="github_token"'],
            'nextcloud'=>['Conectar pelo navegador','id="tg-api-id"'],
            'telegram'=>['Conectar pelo QR Code','id="kimai-token"'],
            'github'=>['Chave pessoal de acesso GitHub','id="tg-api-id"'],
            'about'=>['Migração e segurança','name="nextcloud_password"'],
        ];
        foreach ($expectations as $view=>[$visible,$absent]) {
            $response=$this->get('http://localhost/?view='.$view)->assertOk();
            $response->assertSee($visible)->assertDontSee($absent,false);
            $html=$response->getContent();
            self::assertSame(1,substr_count($html,'aria-current="page"'),$view);
        }
        // Existing saved bookmarks remain functional.
        $this->get('http://localhost/?view=settings')->assertOk()->assertSee('Buscar meus projetos');
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
