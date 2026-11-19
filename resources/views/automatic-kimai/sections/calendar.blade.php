<section class="week-calendar" aria-label="Calendário semanal de horas">
<div class="calendar-toolbar">
<div>
<h2>Semana de {{ \Carbon\Carbon::parse($calendar['start'])->format('d/m') }} a {{ \Carbon\Carbon::parse($calendar['end'])->format('d/m/Y') }}</h2>
<p class="muted">Os blocos azuis são lançamentos do Kimai. As demais cores são apenas evidências, não horas aprovadas.</p>
</div>
<div class="calendar-controls" aria-label="Navegar entre semanas">
<a class="button secondary calendar-refresh" href="{{route('kimai.home',['view'=>'calendar','week'=>$calendar['start'],'refresh'=>1])}}" aria-label="Atualizar dados do Kimai nesta semana">@include('automatic-kimai.components.icon',['name'=>'refresh-cw']) <span>Atualizar do Kimai</span></a>
<a class="button secondary" href="{{route('kimai.home',['view'=>'calendar','week'=>$calendar['previous']])}}" aria-label="Semana anterior">‹</a>
<a class="button secondary" href="{{route('kimai.home',['view'=>'calendar'])}}">Hoje</a>
<a class="button secondary" href="{{route('kimai.home',['view'=>'calendar','week'=>$calendar['next']])}}" aria-label="Próxima semana">›</a>
</div>
</div>
@if($calendar['error'])<div class="notice @if($calendar['outdated']) error @endif" role="status">{{ $calendar['error'] }}</div>@endif
<div class="calendar-summary" aria-label="Resumo da semana">
<div><span class="muted">Horas registradas, sem contar sobreposições</span><strong>{{\App\AutomaticKimai\WeeklyCalendar::hours($calendar['total_seconds'])}}</strong></div>
<div><span class="muted">Blocos registrados</span><strong>{{ $calendar['counts']['registered'] }}</strong></div>
<div><span class="muted">Atividades observadas</span><strong>{{ $calendar['counts']['observed'] }}</strong></div>
<div><span class="muted">Sinais de agenda</span><strong>{{ $calendar['counts']['scheduled'] }}</strong></div>
</div>
<div class="calendar-legend" aria-label="Legenda">
<span><i class="calendar-dot registered"></i> Registrado no Kimai</span>
<span><i class="calendar-dot observed"></i> Observação não confirmada</span>
<span><i class="calendar-dot scheduled"></i> Agenda não comprovada</span>
</div>
<div class="calendar-scroller" tabindex="0" aria-label="Grade da semana; role para visualizar horários">
<div class="calendar-grid">
<div class="calendar-axis"><div class="calendar-axis-title">Horário</div>
<div class="calendar-axis-body">
@for($h=0;$h<24;$h++)<span style="top:{{ $h/24*100 }}%">{{ sprintf('%02d:00',$h) }}</span>@endfor
</div></div>
@foreach($calendar['days'] as $day)
<div class="calendar-day @if($day===$calendar['today']) today @endif">
<div class="calendar-day-heading"><strong>{{ ['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'][$loop->index] }} {{substr($day,8,2)}}</strong><small>{{\App\AutomaticKimai\WeeklyCalendar::hours($calendar['daily'][$day]['seconds'])}} registrados</small></div>
<div class="calendar-day-body">
@for($h=0;$h<24;$h++)<div class="calendar-hour" style="top:{{ $h/24*100 }}%"></div>@endfor
@foreach($calendar['events'] as $event)
@if($event['day']===$day)
<button type="button" class="calendar-event {{ $event['type'] }} @if($event['seconds'] <= 15*60) calendar-event-short @endif" aria-label="{{ $event['start'] }} às {{ $event['end'] }} · {{ $event['title'] }} · {{ $event['details'] }}" style="top:{{ $event['top'] }}%;height:{{ $event['height'] }}%" title="{{ $event['start'] }}–{{ $event['end'] }} · {{ $event['title'] }} · {{ $event['details'] }}" data-calendar-time="{{ $event['start'] }}–{{ $event['end'] }}" data-calendar-title="{{ $event['title'] }}" data-calendar-details="{{ $event['details'] }}" data-calendar-type="{{ $event['type'] }}">
<b>{{ $event['start'] }}–{{ $event['end'] }}</b><span class="calendar-event-name">{{ $event['title'] }}</span>
</button>
@endif
@endforeach
</div></div>
@endforeach
</div></div>
<p class="muted calendar-footnote">Para períodos de até 15 minutos, o cartão é ampliado apenas para leitura; início, término e totalizadores preservam a duração real. Selecione qualquer atividade para ver seus detalhes.</p>
<dialog id="calendar-detail-dialog" class="calendar-detail-dialog" aria-labelledby="calendar-detail-title">
  <form method="dialog"><button class="secondary calendar-dialog-close" aria-label="Fechar detalhes">Fechar</button></form>
  <p class="eyebrow">Detalhes da atividade</p>
  <h3 id="calendar-detail-title"></h3>
  <p id="calendar-detail-time" class="calendar-detail-time"></p>
  <p id="calendar-detail-source"></p>
  <p id="calendar-detail-description" class="muted"></p>
</dialog>
<p class="muted calendar-footnote">Total calculado pela união dos intervalos registrados, evitando dupla contagem de sobreposições. Não inclui cronômetros em andamento. Observações e compromissos não são somados às horas.</p>
<div class="calendar-actions">
<a class="button secondary" href="{{route('kimai.home',['view'=>'review'])}}">Ver conciliação</a>
<a class="button secondary" href="{{route('kimai.home',['view'=>'manual'])}}">Informar horas</a>
@if(!empty($creds['KIMAI_BASE_URL']))
<a class="button secondary" href="{{rtrim($creds['KIMAI_BASE_URL'],'/')}}/pt_BR/calendar/" target="_blank" rel="noopener noreferrer">Abrir no Kimai</a>
@endif
</div>
</section>
