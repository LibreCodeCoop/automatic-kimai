<section id="telegram-setup" aria-labelledby="telegram-heading">
<h2 id="telegram-heading">Telegram</h2>
<p class="muted">Conecte seu Telegram pelo celular, escolhendo antes quais conversas de trabalho podem ser consultadas. Nenhuma mensagem será enviada.</p>
<form method="post" action="{{route('kimai.telegram.configure')}}">@csrf
<p class="muted">Se o aplicativo já tiver credenciais de conexão configuradas, basta indicar as conversas e seguir para o QR Code.</p>
<details class="advanced-settings" @if(empty($creds['TELEGRAM_API_ID']) || empty($creds['TELEGRAM_API_HASH'])) open @endif>
<summary>Credenciais de conexão do Telegram (necessárias uma vez por instalação)</summary>
<p>O Telegram exige uma identificação de aplicativo para criar o QR Code. Você pode gerar a sua em <a href="https://my.telegram.org/apps" target="_blank" rel="noopener noreferrer">my.telegram.org/apps</a>. Não é sua senha nem seu número de telefone.</p>
<div class="grid">
<div><label for="tg-api-id">ID da API do Telegram</label><input id="tg-api-id" name="telegram_api_id" inputmode="numeric" pattern="[0-9]{4,13}" autocomplete="off" placeholder="{{ !empty($creds['TELEGRAM_API_ID']) ? 'Já configurado; deixe vazio para manter' : 'Obtido em my.telegram.org' }}"></div>
<div><label for="tg-api-hash">Hash da API do Telegram</label><input id="tg-api-hash" name="telegram_api_hash" type="password" maxlength="32" autocomplete="off" placeholder="{{ !empty($creds['TELEGRAM_API_HASH']) ? 'Já configurado; deixe vazio para manter' : 'Hash de 32 caracteres' }}"></div>
</div>
</details>
<div class="setup-fields">
<div><label for="tg-labels">Conversas profissionais autorizadas</label><input id="tg-labels" name="telegram_labels" maxlength="3000" value="{{ old('telegram_labels',implode(', ', $profile?->get('work_messenger_labels') ?? [])) }}" placeholder="Nome do grupo A, Nome do grupo B" required></div>
</div>
<p class="muted">Informe os nomes dos grupos de trabalho separados por vírgula. Sem autorização explícita, essas conversas não serão coletadas.</p>
<button type="submit" class="secondary">Salvar configuração e conversas permitidas</button>
</form>
<div class="notice" style="margin-top:16px">
<p id="tg-status" role="status" aria-live="polite">Consultando o estado de autenticação...</p>
<div id="tg-controls">
<button type="button" id="tg-start">Conectar pelo QR Code</button>
<button type="button" id="tg-refresh" class="secondary">Verificar autenticação</button>
</div>
<div id="tg-qr-area" style="display:none">
<p><strong>1.</strong> Abra o Telegram no celular. Em <strong>Configurações → Dispositivos → Conectar dispositivo</strong>, escaneie o código abaixo.</p>
<img id="tg-qr-img" width="320" height="320" alt="QR Code temporário para autenticar esta instalação">
<p id="tg-qr-expiry" class="muted"></p>
</div>
<form id="tg-2fa-form" style="display:none" autocomplete="off">
<p><strong>2.</strong> O Telegram solicitou a senha de verificação em duas etapas da sua conta.</p>
<label for="tg-2fa-password">Senha de duas etapas</label>
<input id="tg-2fa-password" type="password" maxlength="1024" autocomplete="off">
<button type="submit">Concluir autenticação</button>
<p class="muted">A senha é utilizada somente na comunicação local com o Telegram, não fica gravada no aplicativo e não deve ser compartilhada neste chat.</p>
</form>
<div id="tg-authorized-area" style="display:none">
<p>Conta Telegram autenticada neste computador. Habilite a leitura apenas das conversas profissionais informadas acima.</p>
<button type="button" id="tg-activate">Ativar conversas autorizadas</button>
</div>
</div>
<form method="post" action="{{route('kimai.telegram.disable')}}">@csrf
<button type="submit" class="secondary">Desativar coleta Telegram (preservar login)</button>
</form>
<p class="warn">A autenticação do Telegram não ativa publicação de horas. A versão PHP continua somente leitura até a substituição segura do piloto Python.</p>
</section>
