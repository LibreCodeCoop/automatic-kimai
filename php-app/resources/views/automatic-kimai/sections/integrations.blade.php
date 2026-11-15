<section class="hub-section">
<h2>Contas habilitadas nas fontes de dados</h2>
<p class="muted">Somente os serviços selecionados em Fontes de dados aparecem aqui. Uma fonte pode estar selecionada e ainda precisar de autenticação.</p>
<div class="hub-grid">
@if($connectionVisibility['nextcloud'])
<a class="hub-card" href="{{ route('kimai.home',['view'=>'nextcloud']) }}">
<div class="hub-icon">@include('automatic-kimai.components.brand-icon',['name'=>'nextcloud'])</div>
<div><h3>Nextcloud</h3><p>Agenda, Talk, Activity e Mail</p><small>{{ !empty($creds['NEXTCLOUD_URL']) && !empty($creds['NEXTCLOUD_USERNAME']) && !empty($creds['NEXTCLOUD_APP_PASSWORD']) ? 'Conectada: '.($creds['NEXTCLOUD_LOGIN_NAME'] ?? $creds['NEXTCLOUD_USERNAME']) : 'Conectar pelo navegador' }}</small></div>
@include('automatic-kimai.components.icon',['name'=>'chevron-right','class'=>'hub-arrow'])
</a>
@endif
@if($connectionVisibility['telegram'])
<a class="hub-card" href="{{ route('kimai.home',['view'=>'telegram']) }}">
<div class="hub-icon">@include('automatic-kimai.components.brand-icon',['name'=>'telegram'])</div>
<div><h3>Telegram</h3><p>Conversas profissionais autorizadas</p><small>{{ $sources['telegram'] ? 'Coleta ativada' : 'Autenticação necessária' }}</small></div>
@include('automatic-kimai.components.icon',['name'=>'chevron-right','class'=>'hub-arrow'])
</a>
@endif
@if($connectionVisibility['github'])
<a class="hub-card" href="{{ route('kimai.home',['view'=>'github']) }}">
<div class="hub-icon">@include('automatic-kimai.components.brand-icon',['name'=>'github'])</div>
<div><h3>GitHub</h3><p>Metadados de atividades técnicas</p><small>{{ !empty($creds['GITHUB_TOKEN']) ? 'Chave configurada' : 'Conexão pendente' }}</small></div>
@include('automatic-kimai.components.icon',['name'=>'chevron-right','class'=>'hub-arrow'])
</a>
@endif
</div>
<p class="muted">Quer ocultar ou ativar outra conexão? <a href="{{ route('kimai.home',['view'=>'sources']) }}">Editar fontes de dados</a>.</p>
</section>
