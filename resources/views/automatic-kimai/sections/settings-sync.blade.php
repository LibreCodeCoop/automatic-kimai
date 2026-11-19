@php
  $backupEnabled=($settingsSyncStatus['enabled']??false)===true;
  $backupState=(string)($settingsSyncStatus['state']??'disabled');
  $connectedNextcloud=!empty($creds['NEXTCLOUD_URL']) &&
      !empty($creds['NEXTCLOUD_USERNAME']) && !empty($creds['NEXTCLOUD_APP_PASSWORD']);
@endphp
<section class="settings-sync-content" aria-label="Backup e sincronização das configurações">
<div class="settings-sync-overview">
<h2>Suas configurações, em outros computadores</h2>
<p>Este recurso mantém uma cópia <strong>criptografada antes do envio</strong> nos seus Arquivos do Nextcloud. Ao entrar com a mesma conta em outro computador, informe a senha de recuperação para restaurar as configurações.</p>
<p>O backup inclui as preferências de análise e conexão. Credenciais do Kimai, GitHub e identificadores da API Telegram são opcionais. O <strong>token do Nextcloud, a sessão Telegram, as horas registradas, os bancos locais e as decisões pendentes</strong> nunca são enviados.</p>
<div class="notice" role="note">
<strong>Atenção às sessões autenticadas</strong>
<p>O Nextcloud requer autenticação individual em cada computador. O Telegram também exige autorização própria (QR Code/2FA) antes da coleta. Não reutilizamos uma sessão Telegram em dois dispositivos.</p>
</div>
</div>
@if(!$connectedNextcloud)
<section>
<h3>1. Conectar ao Nextcloud</h3>
<p>Entre na sua conta Nextcloud neste computador para ativar o backup. Não é necessário autorizar a coleta de Agenda ou Talk para utilizar o armazenamento.</p>
<a class="button" href="{{route('kimai.home',['view'=>'nextcloud'])}}">Conectar minha conta Nextcloud</a>
</section>
@elseif(!$backupEnabled)
<section>
<h3>Ativar backup criptografado</h3>
<p class="muted">Crie uma senha de recuperação <strong>diferente da senha da sua conta Nextcloud</strong>. Ela será necessária para ativar a sincronização em outro dispositivo. Se for perdida, o backup remoto não poderá ser recuperado.</p>
<form method="post" action="{{route('kimai.settings-sync.enable')}}" class="settings-sync-form" autocomplete="off">
@csrf
<div class="setup-fields">
<div>
<label for="backup-recovery-password">Senha de recuperação do backup (mínimo de 16 caracteres)</label>
<input id="backup-recovery-password" name="recovery_password" type="password" autocomplete="new-password" minlength="16" maxlength="1024" required>
</div>
</div>
<label class="settings-sync-checkbox">
<input type="checkbox" name="include_credentials" value="1">
<span><strong>Incluir credenciais das integrações</strong> para restaurar as conexões Kimai e GitHub e a configuração técnica do Telegram. O arquivo permanece criptografado no Nextcloud, mas qualquer dispositivo com essa senha poderá recuperar essas credenciais.</span>
</label>
<label class="settings-sync-checkbox">
<input type="checkbox" name="confirmed" value="1" required>
<span>Entendo que a sincronização exige a senha de recuperação no primeiro acesso de cada dispositivo e que sessões do Telegram e do Nextcloud não serão transferidas.</span>
</label>
<button type="submit">Ativar backup e sincronização</button>
</form>
</section>
@else
<section>
<h3>Sincronização ativada</h3>
<p><strong>Estado:</strong>
@switch($backupState)
@case('synced') Sincronizado @break
@case('conflict') Conflito de configurações @break
@case('needs_resolution') Aguardando escolha de versão @break
@default Aguardando sincronização @endswitch
</p>
@if(!empty($settingsSyncStatus['synced_at']))
<p class="muted">Última sincronização confirmada: {{ \Carbon\Carbon::parse($settingsSyncStatus['synced_at'])->format('d/m/Y H:i') }} (UTC)</p>
@endif
<p class="muted">{{ !empty($settingsSyncStatus['include_credentials']) ? 'O backup inclui credenciais compartilháveis, mediante consentimento.' : 'O backup contém somente preferências e identificadores não secretos.' }} As atualizações são verificadas periodicamente enquanto o aplicativo está ativo.</p>
<form method="post" action="{{route('kimai.settings-sync.run')}}">
@csrf
<button type="submit">Sincronizar agora</button>
</form>
</section>
@if(in_array($backupState,['needs_resolution','conflict'],true))
<section class="settings-sync-conflict">
<h3>Há alterações nos dois lados</h3>
<p>As configurações deste computador e as do Nextcloud são diferentes. Para não sobrescrever informações sem sua decisão, interrompemos a sincronização automática.</p>
<div class="settings-sync-conflict-actions">
<form method="post" action="{{route('kimai.settings-sync.resolve')}}">
@csrf
<input type="hidden" name="decision" value="remote">
<label class="settings-sync-checkbox"><input type="checkbox" name="confirmed" value="1" required> <span>Confirmo que quero aplicar as configurações do backup a este dispositivo, preservando minhas sessões locais.</span></label>
<button type="submit">Usar configurações do Nextcloud</button>
</form>
<form method="post" action="{{route('kimai.settings-sync.resolve')}}">
@csrf
<input type="hidden" name="decision" value="local">
<label class="settings-sync-checkbox"><input type="checkbox" name="confirmed" value="1" required> <span>Confirmo que desejo substituir o backup remoto com as configurações atuais deste dispositivo.</span></label>
<button class="secondary" type="submit">Usar configurações deste computador</button>
</form>
</div>
</section>
@endif
<section>
<details class="settings-collapsible">
<summary>Desativar sincronização neste computador</summary>
<p>Desativa verificações automáticas e elimina a chave de backup armazenada localmente. O arquivo criptografado continuará no Nextcloud.</p>
<form method="post" action="{{route('kimai.settings-sync.disable')}}">
@csrf
<label class="settings-sync-checkbox"><input type="checkbox" name="confirmed" value="1" required><span>Confirmo a desativação somente neste computador.</span></label>
<button type="submit" class="secondary">Desativar sincronização</button>
</form>
</details>
</section>
@endif
</section>
