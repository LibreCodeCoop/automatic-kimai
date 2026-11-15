@if($hiddenConnection)
<div class="notice" role="status">
<strong>{{ $hiddenConnection }} não está habilitado.</strong> Ative a fonte correspondente abaixo para que a configuração apareça no menu.
</div>
@endif
<section>
<h2>Escolha quais dados serão usados</h2>
<p class="muted">Essas opções controlam a coleta e também quais conexões são exibidas no aplicativo. Uma fonte habilitada sem credenciais não coleta dados até ser autenticada.</p>
<form method="post" action="{{route('kimai.sources')}}">@csrf
<div class="source-category">
<h3>Atividade neste computador</h3>
<div class="source-option">
<input type="checkbox" checked disabled id="source-activitywatch" aria-describedby="aw-hint">
<div><label for="source-activitywatch">ActivityWatch</label><p id="aw-hint">Atividade e inatividade local — necessária para identificar períodos observados.</p></div>
</div>
<div class="source-option">
<input type="checkbox" name="source_chatgpt_browser" id="source-chatgpt_browser" value="1" @checked($sources['chatgpt_browser'])>
<div><label for="source-chatgpt_browser">ChatGPT no navegador</label><p>Categoria da aba aberta, sem coletar o conteúdo da conversa.</p></div>
</div>
<div class="source-option">
<input type="checkbox" name="source_claude_browser" id="source-claude_browser" value="1" @checked($sources['claude_browser'])>
<div><label for="source-claude_browser">Claude no navegador</label><p>Categoria da aba aberta, sem coletar o conteúdo da conversa.</p></div>
</div>
</div>
<div class="source-category">
<h3>Nextcloud</h3>
<div class="source-option">
<input type="checkbox" name="source_nextcloud_calendar" id="source-nextcloud_calendar" value="1" @checked($sources['nextcloud_calendar'])>
<div><label for="source-nextcloud_calendar">Agenda</label><p>Horários previstos de compromissos. Não comprova presença.</p></div>
</div>
<div class="source-option">
<input type="checkbox" name="source_nextcloud_talk" id="source-nextcloud_talk" value="1" @checked($sources['nextcloud_talk'])>
<div><label for="source-nextcloud_talk">Talk</label><p>Metadados de ações próprias, sem conteúdo de mensagens.</p></div>
</div>
@if($connectionVisibility['nextcloud'])
<p class="source-connection">@include('automatic-kimai.components.icon',['name'=>'link-2'])
<a href="{{route('kimai.home',['view'=>'nextcloud'])}}">{{ !empty($creds['NEXTCLOUD_APP_PASSWORD']) ? 'Ver conexão Nextcloud' : 'Conectar Nextcloud' }}</a></p>
@endif
</div>
<div class="source-category">
<h3>Outros serviços</h3>
<div class="source-option">
<input type="checkbox" name="source_github" id="source-github" value="1" @checked($sources['github'])>
<div><label for="source-github">GitHub</label><p>Metadados de eventos e atividades técnicas.</p>
@if($connectionVisibility['github'])<p class="source-connection"><a href="{{route('kimai.home',['view'=>'github'])}}">{{ !empty($creds['GITHUB_TOKEN']) ? 'Ver conexão GitHub' : 'Configurar GitHub' }}</a></p>@endif</div>
</div>
<div class="source-option">
<input type="checkbox" name="source_telegram_setup" id="source-telegram_setup" value="1" @checked($sources['telegram_setup'])>
<div><label for="source-telegram_setup">Telegram</label><p>Exibe a conexão para autorizar somente conversas profissionais. A coleta só começa após autenticação QR/2FA e confirmação de ativação.</p>
@if($connectionVisibility['telegram'])<p class="source-connection"><a href="{{route('kimai.home',['view'=>'telegram'])}}">{{ $sources['telegram'] ? 'Ver Telegram conectado' : 'Configurar Telegram' }}</a></p>@endif</div>
</div>
</div>
<button type="submit">@include('automatic-kimai.components.icon',['name'=>'check']) Salvar fontes de dados</button>
</form>
<p class="muted">Desativar uma fonte interrompe sua leitura e oculta a conexão, mas não revoga automaticamente as credenciais já autorizadas no serviço externo.</p>
</section>
