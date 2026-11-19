<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class WeeklyCalendarUiTest extends TestCase
{
    public function test_calendar_is_visible_without_connection_and_never_writes(): void
    {
        $this->get('http://localhost/?view=calendar&week=2026-11-19')->assertOk()
            ->assertSee('Calendário semanal')
            ->assertSee('Conecte uma conta Kimai')
            ->assertSee('Semana de 16/11 a 22/11/2026')
            ->assertSee('Horas registradas')
            ->assertDontSee('Aprovar somente na fila local');
    }

    public function test_invalid_week_query_fails_cleanly(): void
    {
        $this->get('http://localhost/?view=calendar&week=2026-02-30')->assertStatus(422);
        $this->get('http://localhost/?view=calendar&week=../../secrets')->assertStatus(422);
    }
}
