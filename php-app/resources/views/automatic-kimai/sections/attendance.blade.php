<section><h2>Trabalho presencial</h2>
<p>Registre sua participação presencial com horários reais. As pausas informadas serão descontadas e os períodos confirmados serão registrados como prévias internas não faturáveis no Kimai.</p>
<form method="post" action="{{route('kimai.attendance-submit')}}">@csrf
<div class="grid">
<div><label>Dia</label><input type="date" name="day" required></div>
<div><label>Atividade</label><input name="label" maxlength="64" required placeholder="Reunião presencial"></div>
<div><label>Início da jornada</label><input type="time" name="start" value="09:00" required></div>
<div><label>Fim da jornada</label><input type="time" name="end" value="17:00" required></div>
<div><label for="pause-start">Início da pausa (se houver)</label><input id="pause-start" type="time" name="pause_start"></div>
<div><label for="pause-end">Fim da pausa</label><input id="pause-end" type="time" name="pause_end"></div>
</div>
<details class="advanced-settings">
<summary>Mais opções de horário</summary>
<div class="grid">
<div><label for="extra-breaks">Outras pausas (opcional)</label><input id="extra-breaks" name="breaks" placeholder="Ex.: 15:00-15:15, 17:00-17:10"></div>
<div><label for="schedule-source">Como você sabe os horários?</label><input id="schedule-source" name="schedule_source" value="Horários declarados por mim" required></div>
</div>
</details>
<label><input type="checkbox" name="attended" value="1" required> Confirmo que participei e realizei trabalho humano neste compromisso.</label>
<button type="submit">Registrar presença no Kimai</button>
</form>
</section>
