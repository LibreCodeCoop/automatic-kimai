<details class="runtime-note">
<summary>Como seus registros estão sendo protegidos durante a migração</summary>
<p>O sincronizador Python continua ativo para registrar o tempo trabalhado. Esta interface PHP permite analisar e revisar dados, mas ainda não publica novos apontamentos. Essa separação evita duplicidade enquanto a equivalência entre os programas é validada.</p>
</details>
@if(($sources['nextcloud_calendar'] || $sources['nextcloud_talk']) &&
    (empty($creds['NEXTCLOUD_URL']) || empty($creds['NEXTCLOUD_APP_PASSWORD'])))
<div class="notice">
<strong>Nextcloud ainda não conectado.</strong> Para incluir compromissos da agenda ou ações no Talk,
<a href="{{route('kimai.home',['view'=>'nextcloud'])}}">conecte sua conta pelo navegador</a>.
As outras fontes continuam sendo analisadas normalmente.
</div>
@endif
@if(($report['sources']['nextcloud_calendar']['status']??null)==='unavailable' ||
    ($report['sources']['nextcloud_talk']['status']??null)==='unavailable')
<div class="notice">
<strong>Não foi possível consultar todas as fontes do Nextcloud.</strong>
O monitoramento continua funcionando, mas Agenda ou Talk podem estar incompletos.
<a href="{{route('kimai.home',['view'=>'nextcloud'])}}">Verificar conexão Nextcloud</a>.
</div>
@endif
<section>
<h2>Resumo do trabalho</h2>
<div class="grid">
<div><div class="muted">Situação</div><div class="metrics" style="font-size:20px">@if(($status['result']??'')==='success')Atualizado@else{{ ($status['result']??'never_ran')==='never_ran'?'Sem análise':'Verificar' }}@endif</div></div>
<div><div class="muted">Lacunas observadas</div><div class="metrics">{{ $status['coverage_gap_count'] ?? 0 }}</div></div>
<div><div class="muted">Períodos para conferir</div><div class="metrics">{{ $status['manual_review_interval_count'] ?? 0 }}</div></div>
<div><div class="muted">Alertas de agenda</div><div class="metrics">{{ $status['calendar_gap_count'] ?? 0 }}</div></div>
</div>
<p class="muted">Última análise: {{ $status['at_local'] ?? 'ainda não executada' }}. Horários de agenda e navegador não comprovam trabalho humano.</p>
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
<a href="{{ route('kimai.home',['view'=>'review']) }}">Revisar intervalos e lacunas <span aria-hidden="true">›</span></a>
<a href="{{ route('kimai.home',['view'=>'kimai']) }}">Conta Kimai <span aria-hidden="true">›</span></a>
<a href="{{ route('kimai.home',['view'=>'integrations']) }}">Conectar outros serviços <span aria-hidden="true">›</span></a>
</div>
</section>
