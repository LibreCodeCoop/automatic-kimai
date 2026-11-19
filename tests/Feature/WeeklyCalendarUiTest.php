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

    public function test_calendar_refresh_keeps_selected_week_and_spinner_is_available(): void
    {
        $page=$this->get('http://localhost/?view=calendar&week=2026-11-19')->assertOk();
        $html=$page->getContent();
        self::assertStringContainsString('refresh=1',$html);
        self::assertStringContainsString('Atualizar do Kimai',$html);
        self::assertStringContainsString('id="page-loading-overlay"',$html);
        self::assertStringContainsString('AutomaticKimaiLoading',$html);
        self::assertStringContainsString('id="calendar-detail-dialog"',$html);
    }

    public function test_short_activity_gets_readable_focusable_card_without_changing_total(): void
    {
        $zone=new \DateTimeZone('America/Sao_Paulo');
        $calendar=\App\AutomaticKimai\WeeklyCalendar::week('2026-11-19',$zone);
        $calendar['events']=\App\AutomaticKimai\WeeklyCalendar::entries([[
            'begin'=>'2026-11-19T19:25:00-03:00','end'=>'2026-11-19T19:33:00-03:00',
            'project'=>['name'=>'Project A'],'activity'=>['name'=>'Testing'],
        ]],['days'=>[]],$calendar['days'],$zone);
        $calendar['error']=null;$calendar['outdated']=false;
        $calendar=\App\AutomaticKimai\WeeklyCalendar::decorate($calendar);
        self::assertSame(480,$calendar['total_seconds']);
        $html=view('automatic-kimai.sections.calendar',['calendar'=>$calendar,'creds'=>[]])->render();
        self::assertStringContainsString('calendar-event-short',$html);
        self::assertStringContainsString('19:25–19:33',$html);
        self::assertStringContainsString('data-calendar-title="Project A · Testing"',$html);
        self::assertStringContainsString('button type="button" class="calendar-event registered',$html);
    }

    public function test_partial_pause_is_rejected_before_any_remote_write(): void
    {
        $this->post('http://localhost/manual',[
            'day'=>'2026-11-18','start'=>'09:00','end'=>'17:00',
            'task'=>'Field research','pause_start'=>'12:00','confirmed'=>1
        ])->assertSessionHasErrors('pause_end');
    }
}
