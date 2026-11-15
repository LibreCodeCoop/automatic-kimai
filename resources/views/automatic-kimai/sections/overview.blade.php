@if(($sources['nextcloud_calendar'] || $sources['nextcloud_talk'] ||
    $sources['nextcloud_activity'] || $sources['nextcloud_mail']) &&
    (empty($creds['NEXTCLOUD_URL']) || empty($creds['NEXTCLOUD_APP_PASSWORD'])))
<div class="notice">
<strong>Nextcloud ainda não conectado.</strong> Para consultar as fontes Nextcloud selecionadas,
<a href="{{route('kimai.home',['view'=>'nextcloud'])}}">conecte sua conta pelo navegador</a>.
As outras fontes continuam sendo analisadas normalmente.
</div>
@endif
@if(($sources['nextcloud_calendar'] && ($report['sources']['nextcloud_calendar']['status']??null)==='unavailable') ||
    ($sources['nextcloud_talk'] && ($report['sources']['nextcloud_talk']['status']??null)==='unavailable') ||
    ($sources['nextcloud_activity'] && ($report['sources']['nextcloud_activity']['status']??null)==='unavailable') ||
    ($sources['nextcloud_mail'] && in_array(
        $report['sources']['nextcloud_mail']['status']??null,
        ['unavailable','partial','not_configured'],true)))
<div class="notice">
<strong>Não foi possível consultar todas as fontes do Nextcloud.</strong>
O monitoramento continua funcionando, mas uma das fontes selecionadas pode estar incompleta.
<a href="{{route('kimai.home',['view'=>'nextcloud'])}}">Verificar conexão Nextcloud</a>.
</div>
@endif
<section>
<h2>Resumo do trabalho</h2>
<div class="grid overview-metrics">
<div><div class="muted">Situação</div><div class="metrics" style="font-size:20px">{{ $statusLabel }}</div></div>
<div><div class="muted">Lacunas observadas</div><div class="metrics">{{ $status['coverage_gap_count'] ?? 0 }}</div></div>
<div><div class="muted">Revisões manuais pendentes</div><div class="metrics">{{ $status['manual_queue_pending_count'] ?? 0 }}</div></div>
<div><div class="muted">Alertas de agenda</div><div class="metrics">{{ $status['calendar_gap_count'] ?? 0 }}</div></div>
</div>
<p class="muted">Última análise: {{ $lastAnalysis }}. Horários de agenda e navegador não comprovam trabalho humano.</p>
@if ($profile)
<form method="post" action="{{route('kimai.analyze')}}">@csrf
<button type="submit">Atualizar análise</button>
<button class="secondary" name="capture_only" value="1" type="submit">Atualizar dados do computador</button>
</form>
@endif
</section>
<section class="next-action">
<h2>O que deseja fazer?</h2>
<div class="action-links">
<a href="{{ route('kimai.home',['view'=>'review']) }}">Acompanhar conciliação de horas @include('automatic-kimai.components.icon',['name'=>'chevron-right'])</a>
<a href="{{ route('kimai.home',['view'=>'kimai']) }}">Conta Kimai @include('automatic-kimai.components.icon',['name'=>'chevron-right'])</a>
<a href="{{ route('kimai.home',['view'=>in_array(true,$connectionVisibility,true)?'integrations':'sources']) }}">
{{ in_array(true,$connectionVisibility,true)?'Ver conexões habilitadas':'Escolher fontes de dados' }}
@include('automatic-kimai.components.icon',['name'=>'chevron-right'])
</a>
</div>
</section>
