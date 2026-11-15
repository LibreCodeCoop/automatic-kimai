<section class="hub-section">
<h2>Escolha a conta que deseja conectar</h2>
<p class="muted">Cada conexão tem uma tela própria. Não é necessário preencher tudo para começar a acompanhar seu trabalho.</p>
<div class="hub-grid">
<a class="hub-card" href="{{ route('kimai.home',['view'=>'nextcloud']) }}">
<div class="hub-icon" aria-hidden="true">☁</div>
<div><h3>Nextcloud</h3><p>Agenda e ações realizadas no Talk</p>
<small>{{ !empty($creds['NEXTCLOUD_APP_PASSWORD']) ? 'Conta configurada' : 'Conectar pelo navegador' }}</small></div>
<span class="hub-arrow" aria-hidden="true">›</span>
</a>
<a class="hub-card" href="{{ route('kimai.home',['view'=>'telegram']) }}">
<div class="hub-icon" aria-hidden="true">✈</div>
<div><h3>Telegram</h3><p>Conversas profissionais autorizadas</p>
<small>{{ $sources['telegram'] ? 'Coleta ativada' : 'Configurar conversa' }}</small></div>
<span class="hub-arrow" aria-hidden="true">›</span>
</a>
<a class="hub-card" href="{{ route('kimai.home',['view'=>'github']) }}">
<div class="hub-icon" aria-hidden="true">⌘</div>
<div><h3>GitHub</h3><p>Atividade técnica dos repositórios</p>
<small>{{ !empty($creds['GITHUB_TOKEN']) ? 'Chave configurada' : 'Conexão opcional' }}</small></div>
<span class="hub-arrow" aria-hidden="true">›</span>
</a>
</div>
</section>
