<section>
<div class="section-heading">
<div><h2>Observações acompanhadas automaticamente</h2><p class="muted">Acompanhamento informativo, sem tarefas obrigatórias de revisão manual. A ausência de lançamento não prova nem descarta trabalho; novos registros podem reconciliar estas observações.</p></div>
</div>
@forelse(($report['days'] ?? []) as $day)
<details class="day-card" @if($loop->last) open @endif>
<summary>
<span class="day-title">{{ $day['day'] }}</span>
<span class="day-subtitle">{{ count($day['review_intervals'] ?? []) }} observações de atividade · {{ count($day['calendar_gap_alerts'] ?? []) }} sinais de agenda</span>
</summary>
<div class="day-body">
@if(!empty($day['warning']))<p class="warn">{{ $day['warning'] }}</p>@endif
@forelse(($day['review_intervals'] ?? []) as $entry)
<div class="review-entry">
<div>
<strong>{{substr($entry['start']??'',11,5)}} às {{substr($entry['end']??'',11,5)}}</strong>
<p>Atividade observada por {{ $entry['observed_nonafk_minutes']??0 }} min; não comprova a duração de trabalho. A comparação com o Kimai continuará automaticamente.</p>
</div>
<span class="review-chip">Monitorado</span>
</div>
@empty
<p class="muted">Nenhum intervalo de atividade não classificada neste dia.</p>
@endforelse
@foreach(($day['calendar_gap_alerts'] ?? []) as $entry)
<div class="review-entry">
<div><strong>Compromisso na agenda · {{ substr($entry['start']??'',11,5) }} às {{ substr($entry['end']??'',11,5) }}</strong><p>{{ $entry['uncovered_schedule_minutes']??0 }} minutos previstos sem registros confirmados. Uma agenda não comprova presença.</p></div>
<span class="review-chip">Monitorado</span>
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
<h2>Resultado da revisão automática</h2>
<p class="muted">A análise automática concilia o que já consta do Kimai e monitora observações sem duração de trabalho comprovada. Uma observação sem comprovação não é contabilizada, descartada como não trabalhada nem enviada ao Kimai.</p>
</div>
</div>
@php
$manualQueueRows=array_values(array_filter($reviewRows ?? [], static fn($row)=>in_array(($row['status']??''),['draft','needs_reapproval','conflict','uncertain'],true)));
$auditedRows=array_values(array_filter($reviewRows ?? [], static fn($row)=>!in_array(($row['status']??''),['draft','needs_reapproval','conflict','uncertain'],true)));
$autoObservedRows=array_values(array_filter($reviewRows ?? [], static fn($row)=>($row['status']??'')==='auto_observed'));
$conflictsToReview=count(array_filter($reviewRows ?? [], static fn($row)=>($row['status']??'')==='conflict'));
$reconciledPeriods=count(array_filter($reviewRows ?? [], static fn($row)=>($row['status']??'')==='reconciled_partial'));
$remainingSmallSeconds=array_sum(array_map(static fn($row)=>(int)($row['remote_small_gap_seconds']??0),array_filter(
    $reviewRows ?? [],static fn($row)=>($row['status']??'')==='reconciled_partial')));
@endphp
@if($reconciledPeriods>0)
<p class="muted" role="status"><strong>{{ $reconciledPeriods }} propostas reconciliadas automaticamente com o Kimai.</strong>
Os trechos já registrados não serão duplicados. Existem {{ count($autoObservedRows) }} observações sem duração comprovada, mantidas para reavaliação automática;
{{ $remainingSmallSeconds }} segundos de margens residuais estão documentados, sem arredondamento para gerar horas.</p>
@endif
@if($conflictsToReview>0)
<p class="warn" role="status">{{ $conflictsToReview }} {{ $conflictsToReview===1?'sobreposição precisa':'sobreposições precisam' }} de decisão. Abra um período para comparar a sugestão com os registros existentes.</p>
@endif
@if(count($manualQueueRows)===0)
<p role="status"><strong>Nenhuma pendência de revisão manual.</strong> A fila de decisões está vazia. As observações não confirmadas permanecem no histórico, sem serem lançadas como horas.</p>
@else
<p class="warn">{{ count($manualQueueRows) }} {{ count($manualQueueRows)===1?'situação exige':'situações exigem' }} tratamento específico. Nenhuma será automaticamente aprovada sem duração verificada.</p>
@endif
@forelse($manualQueueRows as $candidate)
<details class="day-card" @if(in_array(($candidate['status'] ?? ''),['conflict','draft'],true) &&
    (isset($candidate['source_parent_id']) || ($candidate['status'] ?? '')==='conflict') &&
    !isset($firstActionOpened)) open @php $firstActionOpened=true; @endphp @endif>
<summary>
<span class="day-title">{{ $candidate['date'] ?? 'Período observado' }} · {{ substr($candidate['start'] ?? '',11,5) }}–{{ substr($candidate['end'] ?? '',11,5) }}</span>
<span class="day-subtitle">Situação: {{ match($candidate['status'] ?? '') {
    'conflict'=>'Conflito de horários', 'reconciled_partial'=>'Cobertura reconciliada',
    'draft'=>isset($candidate['source_parent_id'])?'Lacuna não registrada · confirmar':'Aguardando revisão',
    'needs_reapproval'=>'Revisão novamente necessária', 'rejected'=>'Descartado',
    'covered'=>'Registrado no Kimai', 'approved'=>'Aprovado localmente',
    'posted'=>'Publicado', 'uncertain'=>'Publicação a conciliar',
    default=>'Revisar'
} }}</span>
</summary>
<div class="day-body">
@if(isset($candidate['source_parent_id']))
<p class="warn">Trecho não coberto pelo Kimai, extraído automaticamente de uma proposta sobreposta. Não é prova de trabalho: confirme se foi realizado, desconte pausas e escolha o projeto antes de aprovar.</p>
@else
<p class="muted">Contexto observado não comprova duração de trabalho. Revise pausas, atividade e projeto antes de decidir. O faturamento não é controlado por esta tela.</p>
@endif
@if(($candidate['status'] ?? '')!=='conflict' && !empty($candidate['hold_reason']))
<p class="warn">{{ $candidate['hold_reason'] }}</p>
@endif
@if(($candidate['status'] ?? '')==='reconciled_partial')
<div class="review-conflict" role="group" aria-label="Resumo da reconciliação automática">
<h3>Horas já registradas foram preservadas</h3>
<p>O aplicativo comparou este intervalo aos lançamentos do Kimai e separou as lacunas. Esta proposta original não será lançada de novo.</p>
<p><strong>{{ count($candidate['remote_intersections'] ?? []) }} registros remotos associados.</strong>
<strong>{{ intdiv((int)($candidate['remote_uncovered_seconds']??0),60) }} minutos</strong> não cobertos integralmente.
{{ $candidate['remote_gap_candidates']??0 }} trechos foram preparados como propostas independentes e ainda precisam de confirmação.</p>
@if(($candidate['remote_small_gap_seconds']??0)>0)
<p class="muted">{{ $candidate['remote_small_gap_seconds'] }} segundos de margens inferiores a cinco minutos ou sem limite válido de publicação foram mantidos como evidência; não foram descartados nem considerados horas trabalhadas.</p>
@endif
<details class="technical-details"><summary>Conferir horários ainda não cobertos</summary>
<ul>
@foreach(($candidate['remote_uncovered_spans']??[]) as $span)
<li>{{ substr($span['start']??'',11,8) }}–{{ substr($span['end']??'',11,8) }}
 · {{ $span['seconds']??0 }} segundos não registrados</li>
@endforeach
</ul>
</details>
</div>
@elseif(($candidate['status'] ?? '')==='conflict')
<div class="review-conflict" role="group" aria-label="Decisão sobre sobreposição"
 data-conflict-details data-conflict-id="{{ $candidate['id'] }}"
 data-conflict-digest="{{ $candidate['source_digest'] }}">
<h3>Sobreposição com horas já registradas</h3>
<p class="warn">Este intervalo cruza um ou mais lançamentos existentes no Kimai. Não é seguro aprovar tudo nem substituir registros automaticamente.</p>
<div class="conflict-remote-list" aria-live="polite">
<p class="muted" data-conflict-status>Conferindo os horários existentes no Kimai...</p>
<ul data-conflict-rows></ul>
<button type="button" class="secondary" data-conflict-retry hidden>Tentar carregar registros</button>
</div>
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
<p class="muted">Não há decisões manuais aguardando nesta fila.</p>
@endforelse
@if(count($auditedRows)>0)
<details class="technical-details" id="automated-review-audit">
<summary>Histórico auditável da conciliação ({{ count($auditedRows) }} {{ count($auditedRows)===1?'registro':'registros' }})</summary>
<p class="muted">A classificação é informativa e será conferida novamente nas próximas sincronizações. A contagem de observações não equivale a horas trabalhadas. Você ainda pode lançar trabalho efetivamente realizado em Informar horas, sem depender desta fila.</p>
<div class="tablewrap"><table>
<thead><tr><th>Dia e horário</th><th>Resultado</th><th>Evidência no trecho</th></tr></thead>
<tbody>
@foreach($auditedRows as $item)
<tr>
<td>{{ $item['date'] ?? '' }} · {{ substr($item['start']??'',11,5) }}–{{ substr($item['end']??'',11,5) }}</td>
<td>{{ match($item['status']??'') {
  'auto_observed'=>'Observação avaliada automaticamente; sem horas comprovadas',
  'reconciled_partial'=>'Sobreposição conciliada',
  'covered'=>'Já registrado no Kimai',
  'posted'=>'Publicado e verificado',
  'approved'=>'Aprovado anteriormente, aguardando publicação',
  'rejected'=>'Rejeitado anteriormente',
  default=>'Histórico preservado'
} }}</td>
<td>
@if(($item['status']??'')==='auto_observed')
{{ intdiv((int)($item['auto_assessment']['work_context_seconds']??0),60) }} min de contexto profissional observado;
{{ (int)($item['auto_assessment']['own_action_markers']??0) }} ações pontuais. Duração humana não verificada.
@else
{{ $item['hold_reason']??'Registro mantido para auditoria' }}
@endif
</td>
</tr>
@endforeach
</tbody></table></div>
</details>
@endif
</section>
