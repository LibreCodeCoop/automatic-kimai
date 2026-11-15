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

<section class="review-workflow">
<div class="section-heading">
<div>
<h2>Fila de revisão com decisão humana</h2>
<p class="muted">A revisão registra sua decisão na fila local. O publicador envia apenas sessões aprovadas e verificadas, quando a política de publicação estiver ativa.</p>
</div>
</div>
@php
$conflictsToReview=count(array_filter($reviewRows ?? [], static fn($row)=>($row['status']??'')==='conflict'));
@endphp
@if($conflictsToReview>0)
<p class="warn" role="status">{{ $conflictsToReview }} {{ $conflictsToReview===1?'sobreposição precisa':'sobreposições precisam' }} de decisão. Abra um período para comparar a sugestão com os registros existentes.</p>
@endif
@forelse(($reviewRows ?? []) as $candidate)
<details class="day-card" @if(($candidate['status'] ?? '')==='conflict' && !isset($firstConflictOpened)) open @php $firstConflictOpened=true; @endphp @endif>
<summary>
<span class="day-title">{{ $candidate['date'] ?? 'Período observado' }} · {{ substr($candidate['start'] ?? '',11,5) }}–{{ substr($candidate['end'] ?? '',11,5) }}</span>
<span class="day-subtitle">Situação: {{ match($candidate['status'] ?? '') {
    'conflict'=>'Conflito de horários', 'draft'=>'Aguardando revisão',
    'needs_reapproval'=>'Revisão novamente necessária', 'rejected'=>'Descartado',
    'covered'=>'Registrado no Kimai', 'approved'=>'Aprovado localmente',
    'posted'=>'Publicado', 'uncertain'=>'Publicação a conciliar',
    default=>'Revisar'
} }}</span>
</summary>
<div class="day-body">
<p class="muted">Contexto observado não comprova duração de trabalho. Revise pausas, atividade e projeto antes de decidir. O faturamento não é controlado por esta tela.</p>
@if(!empty($candidate['hold_reason']))<p class="warn">{{ $candidate['hold_reason'] }}</p>@endif
@if(($candidate['status'] ?? '')==='conflict')
<div class="review-conflict" role="group" aria-label="Decisão sobre sobreposição">
<h3>Sobreposição com horas já registradas</h3>
<p class="warn">Este intervalo cruza um ou mais lançamentos existentes no Kimai. Não é seguro aprovar tudo nem substituir registros automaticamente.</p>
@if(!empty($candidate['remote_intersections']))
<p><strong>Registros no Kimai:</strong> #{{ implode(', #',array_map('intval',$candidate['remote_intersections'])) }}</p>
@endif
<p>Se todo o trabalho já estiver contabilizado, descarte a sugestão. Se não trabalhou nesse intervalo, também pode descartá-la. Se há trabalho adicional não registrado, consulte os horários originais e informe <strong>somente o trecho não coberto</strong> em Informar horas, após a ativação segura do publicador PHP.</p>
<form method="post" action="{{ route('kimai.review-conflict') }}">
@csrf
<input type="hidden" name="candidate_id" value="{{ $candidate['id'] }}"/>
<input type="hidden" name="source_digest" value="{{ $candidate['source_digest'] }}"/>
<label>Minha decisão
<select name="decision" required>
<option value="">Selecione uma decisão</option>
<option value="already_recorded">O trabalho já está registrado; descartar sugestão</option>
<option value="not_worked">Não foi trabalho realizado; descartar sugestão</option>
</select></label>
<p><label><input type="checkbox" name="confirm_discard" value="1" required/> Confirmo o descarte da sugestão, sem apagar ou alterar lançamentos existentes.</label></p>
<button type="submit" class="secondary">Confirmar descarte da sugestão</button>
</form>
<div class="review-conflict-actions">
<a class="button secondary" href="{{ route('kimai.home',['view'=>'manual']) }}">Conferir período não registrado</a>
<form method="post" action="{{route('kimai.analyze')}}">@csrf
<button type="submit" class="secondary">Reanalisar sobreposições</button></form>
</div>
</div>
@elseif(in_array($candidate['status'] ?? '',['draft','needs_reapproval'],true))
@if(($candidate['ongoing'] ?? true)===false && ($candidate['end_stable'] ?? false)===true &&
    ($candidate['proposed_action'] ?? null)==='new')
<form method="post" action="{{ route('kimai.review-approve') }}" class="review-approval-form" data-review-catalog>
@csrf
<p class="muted" data-catalog-status role="status">Carregar projetos e atividades ao revisar o período.</p>
<button type="button" class="secondary" data-catalog-retry hidden>Tentar carregar novamente</button>
<input type="hidden" name="candidate_id" value="{{ $candidate['id'] }}"/>
<input type="hidden" name="source_digest" value="{{ $candidate['source_digest'] }}"/>
<input type="hidden" name="day" value="{{ $candidate['date'] }}"/>
<div class="setup-fields">
<label>Início confirmado (HH:MM)
<input type="time" name="start" value="{{ substr($candidate['start'] ?? '',11,5) }}" required/></label>
<label>Fim confirmado, descontadas as pausas (HH:MM)
<input type="time" name="end" value="{{ substr($candidate['end'] ?? '',11,5) }}" required/></label>
<label>Projeto de destino no Kimai
<select name="project_id" data-default="{{ $profile->get('project_id') }}" required disabled>
<option value="">Carregando projetos...</option>
</select></label>
<label>Tipo de atividade
<select name="activity_id" data-default="{{ $profile->get('activity_id') }}" required disabled>
<option value="">Carregando atividades...</option>
</select></label>
<label>Descrição concreta da atividade
<textarea name="description" rows="2" maxlength="500" minlength="10" required placeholder="O que foi realizado neste período?"></textarea>
</label>
</div>
<p><label><input type="checkbox" name="confirm_duration" value="1" required/> Confirmo que trabalhei durante o intervalo informado, descontadas as pausas.</label></p>
<p><label><input type="checkbox" name="confirm_project" value="1" required/> Confirmei o projeto e a atividade de destino.</label></p>
<button type="submit" data-catalog-submit disabled>Aprovar somente na fila local</button>
</form>
@else
<p class="warn">Período em andamento, incompleto ou com conflito remoto; não pode ser aprovado sem nova análise.</p>
@endif
<form method="post" action="{{ route('kimai.review-reject') }}">
@csrf
<input type="hidden" name="candidate_id" value="{{ $candidate['id'] }}"/>
<input type="hidden" name="source_digest" value="{{ $candidate['source_digest'] }}"/>
<button type="submit" class="secondary">Rejeitar esta proposta</button>
</form>
@elseif(($candidate['status'] ?? '')==='uncertain')
<p class="warn">Uma escrita pode ter ocorrido; não repetir a publicação sem conciliação remota exata.</p>
@elseif(($candidate['status'] ?? '')==='posted')
<p>Registro publicado e verificado anteriormente, sem nova aprovação automática.</p>
@endif
</div>
</details>
@empty
<p class="muted">A fila local ainda não possui propostas. Execute uma análise para construir as hipóteses de jornada.</p>
@endforelse
</section>
