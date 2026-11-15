@php($nextcloudConnected = !empty($creds['NEXTCLOUD_URL']) && !empty($creds['NEXTCLOUD_USERNAME']) && !empty($creds['NEXTCLOUD_APP_PASSWORD']))
@if($nextcloudConnected)
<section class="nextcloud-account-card" id="nc-connected">
<div class="nextcloud-account-header">
<div class="nextcloud-avatar-wrap">
<img id="nc-avatar" class="nextcloud-avatar" src="{{ route('kimai.nextcloud.avatar') }}" width="68" height="68" alt="Avatar da conta Nextcloud" referrerpolicy="no-referrer">
<div id="nc-avatar-fallback" class="nextcloud-avatar-fallback" aria-hidden="true" hidden>{{ mb_strtoupper(mb_substr($creds['NEXTCLOUD_USERNAME'],0,1)) }}</div>
</div>
<div class="nextcloud-account-identity">
<p class="eyebrow">Nextcloud conectado</p>
<h2 id="nc-display-name">{{ $creds['NEXTCLOUD_LOGIN_NAME'] ?? $creds['NEXTCLOUD_USERNAME'] }}</h2>
<p class="muted">{{ '@'.$creds['NEXTCLOUD_USERNAME'] }} · {{ parse_url($creds['NEXTCLOUD_URL'],PHP_URL_HOST) }}</p>
</div>
<div class="nextcloud-account-status" id="nc-account-health">@include('automatic-kimai.components.icon',['name'=>'circle-check']) Conta vinculada</div>
</div>
<div class="nextcloud-account-actions">
<button type="button" id="nc-reconnect" class="secondary">Entrar com outra conta</button>
<button type="button" id="nc-disconnect" class="secondary">Desconectar deste computador</button>
</div>
<p class="muted" id="nc-disconnect-status" role="status" aria-live="polite"></p>
</section>
@endif
<section id="nc-connect-section" @if($nextcloudConnected) hidden @endif>
<h2>Conectar com Nextcloud</h2>
<p class="muted">Autorize o Automatic Kimai usando sua conta no navegador. A autorização gera uma senha de aplicativo própria, sem copiar sua sessão ou senha principal.</p>
<div class="setup-fields">
<div>
<label for="nc-url">Endereço do seu Nextcloud</label>
<input id="nc-url" type="url" autocomplete="url" placeholder="https://cloud.exemplo.org" value="{{ $creds['NEXTCLOUD_URL'] ?? ($desktopNextcloudServers[0]??'') }}" list="nc-known" required>
<datalist id="nc-known">@foreach($desktopNextcloudServers as $server)<option value="{{ $server }}"></option>@endforeach</datalist>
@if(!empty($desktopNextcloudServers))<small class="muted">Endereço sugerido pelo cliente Nextcloud deste computador. Nenhuma senha foi consultada.</small>@endif
</div>
</div>
<button id="nc-start" type="button">Conectar pelo navegador</button>
<p id="nc-status" role="status" aria-live="polite" class="muted">Use o botão para iniciar uma autorização segura.</p>
<div id="nc-approval" class="approval-step" hidden>
<p><strong>Conclua a autorização no navegador.</strong> O Automatic Kimai detectará a aprovação automaticamente.</p>
<a id="nc-login-link" class="button" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" href="#">Reabrir navegador</a>
<button id="nc-check" type="button" class="secondary">Já autorizei, verificar</button>
</div>
</section>
<section id="nc-sources">
<h2>Aplicativos Nextcloud</h2>
<p class="muted">Ative somente as fontes que deseja consultar. As mudanças são salvas imediatamente e não desconectam sua conta. Eventos e e-mails servem como contexto para revisão, não como horas comprovadas.</p>
@foreach([
  'nextcloud_calendar'=>['Agenda','Compromissos programados; não comprovam presença.'],
  'nextcloud_talk'=>['Talk','Horários de suas ações em conversas; sem armazenar mensagens.'],
  'nextcloud_activity'=>['Activity','Eventos realizados por sua conta; sem armazenar nomes de arquivos ou conteúdo.'],
  'nextcloud_mail'=>['Mail','Horários de mensagens enviadas na pasta Enviados; sem guardar assuntos, destinatários ou corpo.'],
] as $key=>$option)
<div class="nc-source-switch-row">
<div class="nc-source-copy">
<label for="nc-toggle-{{ $key }}">{{ $option[0] }}</label>
<p>{{ $option[1] }}</p>
</div>
<label class="nc-switch">
<input id="nc-toggle-{{ $key }}" type="checkbox" role="switch" data-nc-source="{{ $key }}" aria-label="Coletar dados do {{ $option[0] }}" @checked($sources[$key])>
<span class="nc-switch-track" aria-hidden="true"></span>
</label>
</div>
@endforeach
<p id="nc-sources-status" role="status" aria-live="polite" class="muted">Escolha as fontes que deseja manter ativas.</p>
</section>
<details class="settings-collapsible">
<summary>Configuração manual (opcional)</summary>
<p class="muted">Use somente se a autorização no navegador não estiver disponível. Gere uma senha de aplicativo no Nextcloud em Configurações pessoais → Segurança → Dispositivos e sessões.</p>
<form method="post" action="{{ route('kimai.connectors') }}">@csrf
<div class="setup-fields">
<div><label for="nc-manual-url">Endereço HTTPS</label><input id="nc-manual-url" name="nextcloud_url" type="url" value="{{ $creds['NEXTCLOUD_URL']??'' }}" placeholder="https://nuvem.exemplo.org" required></div>
<div><label for="nc-manual-user">Usuário Nextcloud</label><input id="nc-manual-user" name="nextcloud_user" value="{{ $creds['NEXTCLOUD_LOGIN_NAME']??($creds['NEXTCLOUD_USERNAME']??'') }}" required></div>
<div><label for="nc-manual-password">Senha de aplicativo</label><input id="nc-manual-password" name="nextcloud_password" type="password" autocomplete="off" placeholder="Não use a senha principal"></div>
</div>
<button type="submit" class="secondary">Salvar dados manuais</button>
</form>
</details>
