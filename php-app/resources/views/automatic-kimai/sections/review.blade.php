<section>
<div class="section-heading">
<div><h2>Períodos para conferir</h2><p class="muted">Os dados abaixo são sugestões, não horas confirmadas. Uma aba aberta ou uma reunião agendada não prova que você trabalhou durante todo o período.</p></div>
</div>
@forelse(($report['days'] ?? []) as $day)
<details class="day-card" @if($loop->last) open @endif>
<summary>
<span class="day-title">{{ $day['day'] }}</span>
<span class="day-subtitle">{{ count($day['review_intervals'] ?? []) }} intervalos a conferir · {{ count($day['calendar_gap_alerts'] ?? []) }} alertas de agenda</span>
</summary>
<div class="day-body">
@if(!empty($day['warning']))<p class="warn">{{ $day['warning'] }}</p>@endif
@forelse(($day['review_intervals'] ?? []) as $entry)
<div class="review-entry">
<div>
<strong>{{substr($entry['start']??'',11,5)}} às {{substr($entry['end']??'',11,5)}}</strong>
<p>Atividade observada por {{ $entry['observed_nonafk_minutes']??0 }} min. Confirme se foi trabalho e desconte pausas.</p>
</div>
<span class="review-chip">Revisar</span>
</div>
@empty
<p class="muted">Nenhum intervalo de atividade não classificada neste dia.</p>
@endforelse
@foreach(($day['calendar_gap_alerts'] ?? []) as $entry)
<div class="review-entry">
<div><strong>Compromisso na agenda · {{ substr($entry['start']??'',11,5) }} às {{ substr($entry['end']??'',11,5) }}</strong><p>{{ $entry['uncovered_schedule_minutes']??0 }} minutos previstos sem registros confirmados. Uma agenda não comprova presença.</p></div>
<span class="review-chip">Verificar</span>
</div>
@endforeach
@if(count($day['sessions']??[])>0)
<details class="technical-details">
<summary>Ver detalhes da análise ({{ count($day['sessions']) }} períodos)</summary>
<div class="tablewrap">
<table>
<thead><tr><th>Início</th><th>Fim</th><th>Situação</th><th>Motivo da classificação</th></tr></thead>
<tbody>
@foreach(($day['sessions']??[]) as $session)
<tr><td>{{ substr($session['begin']??'',11,5) }}</td><td>{{ substr($session['end']??'',11,5) }}</td><td>{{ $session['action']??'revisar' }}</td><td>{{ $session['reason']??'Sem detalhes' }}</td></tr>
@endforeach
</tbody>
</table>
</div>
</details>
@endif
</div>
</details>
@empty
<div class="empty-state">
<h3>Nenhuma análise disponível</h3>
<p>Conecte sua conta Kimai e execute uma análise no painel. A ausência de dados não significa ausência de trabalho.</p>
<a class="button" href="{{ route('kimai.home',['view'=>'overview']) }}">Ir para o painel</a>
</div>
@endforelse
</section>
