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

    public function test_public_network_host_is_blocked(): void
    {
        $this->get('http://evil.example/')->assertForbidden();
    }

    public function test_automatic_publication_is_not_exposed_as_http_action(): void
    {
        $this->post('http://localhost/publish')->assertNotFound();
    }
}
