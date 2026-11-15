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
        // Enable connectors only in an isolated test directory.
        $path=sys_get_temp_dir().'/kimai-ui-nav-'.bin2hex(random_bytes(6));
        $store=new \App\AutomaticKimai\LocalStore($path);
        $this->app->instance(\App\AutomaticKimai\LocalStore::class,$store);
        $store->setSources([
            'github'=>true,'nextcloud_calendar'=>true,'telegram_setup'=>true
        ]);
        $expectations=[
            'overview'=>['Resumo do trabalho','name="nextcloud_url"'],
            'review'=>['Períodos para conferir','name="label"'],
            'attendance'=>['Trabalho presencial','name="telegram_labels"'],
            'manual'=>['Informar horas manualmente','name="kimai_url"'],
            'kimai'=>['Buscar meus projetos','id="tg-api-id"'],
            'sources'=>['Escolha quais dados serão usados','id="kimai-token"'],
            'integrations'=>['Contas habilitadas nas fontes de dados','name="github_token"'],
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
        foreach (glob($path.'/*')?:[] as $file)if(is_file($file))@unlink($file);
        @rmdir($path);
    }

    public function test_dashboard_formats_status_and_timestamp_for_people(): void
    {
        $root=sys_get_temp_dir().'/kimai-ui-status-'.bin2hex(random_bytes(6));
        $store=new \App\AutomaticKimai\LocalStore($root);
        $this->app->instance(\App\AutomaticKimai\LocalStore::class,$store);
        try {
            $store->write('status.json',[
                'result'=>'success','at_local'=>'2026-11-15T00:08:45.324543-03:00',
            ]);
            $this->get('http://localhost/?view=overview')->assertOk()
                ->assertSee('Atualizado')
                ->assertSee('15/11/2026 às 00:08')
                ->assertDontSee('Atualizado@elseVerificar');
        } finally {
            foreach(glob($root.'/*')?:[] as $file)if(is_file($file))@unlink($file);
            @rmdir($root);
        }
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
