<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="color-scheme" content="light dark"/>
<title>Automatic Kimai — Revisão local de horas</title>
<meta name="theme-color" content="#17365e"/>
<script>
/* Set an explicit preference before painting to avoid a light-theme flash. */
try {
  const theme = localStorage.getItem('automatic-kimai:theme');
  if (theme === 'light' || theme === 'dark') {
    document.documentElement.dataset.theme = theme;
  }
} catch (_error) { /* Browser storage is optional. */ }
</script>
<link rel="stylesheet" href="{{ asset('css/automatic-kimai.css') }}?v=3">
</head>
<body>
<header>
<div class="header-inner">
<div><h1>Automatic Kimai</h1><p>Horas sob controle, com privacidade e confirmação humana.</p></div>
<div class="theme-control">
<label for="theme-select">Aparência</label>
<select id="theme-select" aria-label="Aparência do aplicativo">
<option value="system">Sistema</option>
<option value="light">Claro</option>
<option value="dark">Escuro</option>
</select>
</div>
</div>
</header>
<main>
@if (session('ok'))<div class="notice" role="status">{{session('ok')}}</div>@endif
@if ($errors->any())<div class="notice error" role="alert"><strong>Não foi possível concluir a operação.</strong>
<ul>@foreach($errors->all() as $message)<li>{{$message}}</li>@endforeach</ul></div>@endif
<div class="notice">
<strong>Versão PHP de avaliação — somente leitura.</strong>
O lançamento automático e o registro manual no Kimai permanecem bloqueados durante a migração.
O piloto Python continua ativo para impedir perda ou duplicação de horas.
</div>
<section>
<h2>Estado do monitoramento</h2>
<div class="grid">
<div><div class="muted">Situação</div><div class="metrics" style="font-size:20px">{{ $status['result'] ?? 'never_ran' }}</div></div>
<div><div class="muted">Lacunas observadas</div><div class="metrics">{{ $status['coverage_gap_count'] ?? 0 }}</div></div>
<div><div class="muted">Intervalos a revisar</div><div class="metrics">{{ $status['manual_review_interval_count'] ?? 0 }}</div></div>
<div><div class="muted">Agenda sem cobertura</div><div class="metrics">{{ $status['calendar_gap_count'] ?? 0 }}</div></div>
</div>
<p class="muted">Última análise: {{ $status['at_local'] ?? 'ainda não executada' }}. Horários de agenda e navegador não comprovam trabalho humano.</p>
@if ($profile)
<form method="post" action="{{route('kimai.analyze')}}">@csrf
<button type="submit">Atualizar análise em PHP (somente leitura)</button>
<button class="secondary" name="capture_only" value="1" type="submit">Coletar somente ActivityWatch</button>
</form>
@endif
</section>
<section>
<h2>1. Conectar sua conta Kimai</h2>
<form method="post" action="{{route('kimai.setup')}}">@csrf
<div class="grid">
<div><label>Identificação local</label><input name="profile_id" value="{{old('profile_id',$profile?->get('profile_id') ?? 'user')}}" required></div>
<div><label>Endereço HTTPS do Kimai</label><input id="kimai-url" name="kimai_url" placeholder="https://kimai.exemplo.org" value="{{old('kimai_url',$creds['KIMAI_BASE_URL'] ?? '')}}" required></div>
<div><label>Token pessoal de API</label><input id="kimai-token" type="password" name="kimai_token" autocomplete="off" placeholder="{{empty($creds['KIMAI_API_TOKEN'])?'Informe um token individual':'Deixe vazio para manter o existente'}}"></div>
<div><label>Fuso horário (igual ao Kimai)</label><input name="timezone" value="{{old('timezone',$profile?->get('timezone') ?? date_default_timezone_get())}}" required></div>
<div><label>Cliente (ID)</label><input id="customer-id" type="number" min="1" name="customer_id" value="{{old('customer_id',$profile?->get('customer_id') ?? '')}}" required></div>
<div><label>Projeto (ID)</label><input id="project-id" type="number" min="1" name="project_id" value="{{old('project_id',$profile?->get('project_id') ?? '')}}" required></div>
<div><label>Atividade (ID)</label><input id="activity-id" type="number" min="1" name="activity_id" value="{{old('activity_id',$profile?->get('activity_id') ?? '')}}" required></div>
<div><label>Domínios profissionais explicitamente autorizados (separados por vírgula)</label><input name="work_domains" value="{{old('work_domains',implode(', ',$profile?->get('work_domains') ?? []))}}" placeholder="projetos.exemplo.org"></div>
</div>
<div class="notice">
<button type="button" id="catalog-load" class="secondary">1. Listar clientes, projetos e atividades pelo nome</button>
<p id="catalog-message" class="muted" role="status">Digite o endereço e o token do Kimai para procurar suas opções.</p>
<div class="grid">
<div><label>Cliente</label><select id="catalog-customers"><option>Carregue o catálogo primeiro</option></select></div>
<div><label>Projeto</label><select id="catalog-projects"><option>Carregue o catálogo primeiro</option></select></div>
<div><label>Atividade</label><select id="catalog-activities"><option>Carregue o catálogo primeiro</option></select></div>
</div>
</div>
<button type="submit">2. Testar conexão e salvar perfil em modo revisão</button>
</form>
<p class="warn">Não use o token de outra pessoa. A conexão valida os IDs informados e o fuso horário. O token não aparece nas telas nem é enviado a terceiros.</p>
</section>
<section><h2>2. Fontes de informação</h2>
<form method="post" action="{{route('kimai.sources')}}">@csrf
@foreach($sources as $name=>$enabled)
@if ($name==='telegram') @continue @endif
<div class="source"><input type="checkbox" name="source_{{$name}}" value="1" id="source-{{$name}}" @checked($enabled) @disabled($name==='activitywatch')><label for="source-{{$name}}">{{[
'activitywatch'=>'ActivityWatch — aplicativos e inatividade (local)',
'github'=>'GitHub — metadados de eventos',
'nextcloud_talk'=>'Nextcloud Talk — ações próprias',
'nextcloud_calendar'=>'Nextcloud Calendar — compromissos previstos',
'telegram'=>'Telegram — ainda indisponível na versão PHP',
'chatgpt_browser'=>'ChatGPT — categoria da aba, não histórico privado',
'claude_browser'=>'Claude — categoria da aba, não histórico privado'
][$name]??$name}}</label></div>
@endforeach
<button type="submit">Salvar consentimentos</button>
</form>
<p class="muted">Ative somente as fontes que deseja utilizar. Se ativar calendário ou Talk, informe as credenciais Nextcloud na seção seguinte. Uma atividade agendada não comprova participação.</p>
</section>
<section><h2>2.1. Credenciais das integrações opcionais</h2>
<p class="muted">Todas as credenciais são armazenadas localmente, criptografadas e nunca incluídas nos relatórios. Deixe a senha vazia para manter a anterior.</p>
<form method="post" action="{{route('kimai.connectors')}}">@csrf
<div class="grid">
<div><label>Servidor Nextcloud (HTTPS)</label><input name="nextcloud_url" value="{{ $creds['NEXTCLOUD_URL']??'' }}" placeholder="https://nuvem.exemplo.org"></div>
<div><label>Usuário Nextcloud</label><input name="nextcloud_user" value="{{ $creds['NEXTCLOUD_USERNAME']??'' }}"></div>
<div><label>Senha de aplicativo Nextcloud</label><input name="nextcloud_password" type="password" autocomplete="off" placeholder="Deixe em branco para manter"></div>
<div><label>Token pessoal GitHub</label><input name="github_token" type="password" autocomplete="off" placeholder="Deixe em branco para manter"></div>
</div>
<button type="submit">Salvar conexões opcionais</button>
</form>
</section>
<section id="telegram-setup" aria-labelledby="telegram-heading">
<h2 id="telegram-heading">2.2. Telegram — autenticação MTProto</h2>
<p class="muted">A conexão usa MadelineProto no próprio computador. Apenas as conversas profissionais que você indicar poderão ser analisadas. Mensagens particulares não serão consultadas e nenhuma mensagem será enviada.</p>
<form method="post" action="{{route('kimai.telegram.configure')}}">@csrf
<div class="grid">
<div><label for="tg-api-id">ID da API do Telegram</label><input id="tg-api-id" name="telegram_api_id" inputmode="numeric" pattern="[0-9]{4,13}" autocomplete="off" placeholder="{{ !empty($creds['TELEGRAM_API_ID']) ? 'Já configurado; deixe vazio para manter' : 'Obtido em my.telegram.org' }}"></div>
<div><label for="tg-api-hash">Hash da API do Telegram</label><input id="tg-api-hash" name="telegram_api_hash" type="password" maxlength="32" autocomplete="off" placeholder="{{ !empty($creds['TELEGRAM_API_HASH']) ? 'Já configurado; deixe vazio para manter' : 'Hash de 32 caracteres' }}"></div>
<div><label for="tg-labels">Conversas profissionais autorizadas</label><input id="tg-labels" name="telegram_labels" maxlength="3000" value="{{ old('telegram_labels',implode(', ', $profile?->get('work_messenger_labels') ?? [])) }}" placeholder="Nome do grupo A, Nome do grupo B" required></div>
</div>
<p class="muted">A instalação Python anterior já possui credenciais da API; não é necessário copiá-las novamente se os campos acima mostrarem que estão configurados. Os nomes servem apenas para delimitar o que pode ser consultado.</p>
<button type="submit" class="secondary">Salvar configuração e conversas permitidas</button>
</form>
<div class="notice" style="margin-top:16px">
<p id="tg-status" role="status" aria-live="polite">Consultando o estado de autenticação...</p>
<div id="tg-controls">
<button type="button" id="tg-start">Gerar QR Code para entrar no Telegram</button>
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
<button type="button" id="tg-activate">Ativar coleta Telegram em modo de revisão</button>
</div>
</div>
<form method="post" action="{{route('kimai.telegram.disable')}}">@csrf
<button type="submit" class="secondary">Desativar coleta Telegram (preservar login)</button>
</form>
<p class="warn">A autenticação do Telegram não ativa publicação de horas. A versão PHP continua somente leitura até a substituição segura do piloto Python.</p>
</section>
<section>
<h2>3. Revisar intervalos e lacunas</h2>
@forelse(($report['days'] ?? []) as $day)
<details @if($loop->last) open @endif>
<summary><strong>{{ $day['day'] }}</strong> — {{count($day['sessions']??[])}} sugestões;
{{count($day['review_intervals']??[])}} intervalos a revisar;
{{count($day['calendar_gap_alerts']??[])}} alertas de agenda
@if($day['warning']) · {{$day['warning']}}@endif
</summary>
<div class="tablewrap"><table><thead><tr><th>Início</th><th>Fim</th><th>Decisão</th><th>Razão</th><th>Fontes profissionais</th><th>Conversa IA sem confirmação</th></tr></thead><tbody>
@foreach(($day['sessions']??[]) as $s)
<tr><td>{{substr($s['begin']??'',11,5)}}</td><td>{{substr($s['end']??'',11,5)}}</td>
<td>{{$s['action']??'review'}}</td><td>{{$s['reason']??''}}</td><td>{{round(($s['work_seconds']??0)/60)}} min</td>
<td>{{round(($s['chat_unknown_seconds']??0)/60)}} min</td></tr>
@endforeach
</tbody></table></div>
@foreach(($day['review_intervals']??[]) as $entry)
<p><strong>Revisar:</strong> {{substr($entry['start'],11,5)}}–{{substr($entry['end'],11,5)}} · {{$entry['observed_nonafk_minutes']??0}} minutos observados, não comprovados como trabalho.</p>
@endforeach
@foreach(($day['calendar_gap_alerts']??[]) as $entry)
<p><strong>Agenda:</strong> {{substr($entry['start'],11,5)}}–{{substr($entry['end'],11,5)}} · {{$entry['uncovered_schedule_minutes']??0}} minutos agendados sem cobertura.</p>
@endforeach
</details>
@empty
<p class="muted">Não há relatório. Salve um perfil válido e execute uma análise. O aplicativo não cria horas a partir da ausência de dados.</p>
@endforelse
</section>
<section><h2>4. Prévia de trabalho presencial (sem lançamento)</h2>
<p>Informe a participação real e as pausas. O aplicativo mostra os intervalos descontados antes de qualquer integração com o Kimai.</p>
<form method="post" action="{{route('kimai.attendance-preview')}}">@csrf
<div class="grid">
<div><label>Dia</label><input type="date" name="day" required></div>
<div><label>Atividade</label><input name="label" maxlength="64" required placeholder="Reunião presencial"></div>
<div><label>Início da jornada</label><input type="time" name="start" value="09:00" required></div>
<div><label>Fim da jornada</label><input type="time" name="end" value="17:00" required></div>
<div><label>Pausas (HH:MM-HH:MM, separadas por vírgula)</label><input name="breaks" placeholder="12:00-13:00 (deixe vazio se não houve pausa)"></div>
<div><label>Origem dos horários</label><input name="schedule_source" placeholder="Horários efetivos declarados" required></div>
</div>
<label><input type="checkbox" name="attended" value="1" required> Confirmo que participei e realizei trabalho humano neste compromisso.</label>
<button type="submit" class="secondary">Conferir períodos (não grava no Kimai)</button>
</form>
</section>
<section><h2>5. Declaração manual de horas</h2>
<p class="warn">O formulário está preparado, mas a publicação no Kimai está bloqueada até a troca controlada do runtime. Seus horários no Python continuam sendo registrados pela instalação anterior.</p>
<form method="post" action="{{route('kimai.manual')}}">@csrf
<div class="grid">
<div><label>Data (AAAA-MM-DD)</label><input type="date" name="day" required></div>
<div><label>Início real</label><input type="time" name="start" required></div>
<div><label>Fim real</label><input type="time" name="end" required></div>
<div><label>Descrição curta da atividade</label><input name="task" maxlength="64" placeholder="Revisão de documentos" required></div>
</div>
<label><input type="checkbox" name="confirmed" value="1"> Confirmo que realizei pessoalmente o trabalho, excluindo pausas e execução autônoma da IA.</label>
<button type="submit" disabled title="Disponível somente após migração segura de ledger e serviços">Registrar (aguardando homologação)</button>
</form>
</section>
<footer class="muted" style="margin:30px 0">Automatic Kimai · aplicativo local e privado · relatórios não aprovam remuneração nem faturamento.</footer>
</main>
<script>
(() => {
  const selector = document.getElementById('theme-select');
  if (!selector) return;
  try {
    const saved = localStorage.getItem('automatic-kimai:theme');
    selector.value = saved === 'light' || saved === 'dark' ? saved : 'system';
  } catch (_error) { selector.value = 'system'; }
  selector.addEventListener('change', () => {
    const theme = selector.value;
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.dataset.theme = theme;
      try { localStorage.setItem('automatic-kimai:theme', theme); } catch (_error) {}
    } else {
      delete document.documentElement.dataset.theme;
      try { localStorage.removeItem('automatic-kimai:theme'); } catch (_error) {}
    }
  });
})();
(() => {
  const button = document.getElementById('catalog-load');
  if (!button) return;
  const $ = (id) => document.getElementById(id);
  let data = null;
  const select = (target, rows, prompt) => {
    const element = $(target);
    element.replaceChildren();
    const first = document.createElement('option');
    first.value = '';
    first.textContent = prompt;
    element.appendChild(first);
    for (const row of rows) {
      const option = document.createElement('option');
      option.value = String(row.id);
      option.textContent = row.name || String(row.id);
      element.appendChild(option);
    }
  };
  const updateProjects = () => {
    const customer = Number($('catalog-customers').value);
    const available = data.projects.filter(item => item.customer_id === customer);
    select('catalog-projects', available, 'Escolha um projeto');
    select('catalog-activities', [], 'Escolha o projeto primeiro');
    $('customer-id').value = customer || '';
  };
  const updateActivities = () => {
    const project = Number($('catalog-projects').value);
    const available = data.activities.filter(item => item.project_id === null || item.project_id === project);
    select('catalog-activities', available, 'Escolha uma atividade');
    $('project-id').value = project || '';
  };
  $('catalog-customers').addEventListener('change', updateProjects);
  $('catalog-projects').addEventListener('change', updateActivities);
  $('catalog-activities').addEventListener('change', () => {
    $('activity-id').value = $('catalog-activities').value || '';
  });
  button.addEventListener('click', async () => {
    const message = $('catalog-message');
    message.textContent = 'Consultando sua instância Kimai...';
    button.disabled = true;
    try {
      const response = await fetch(@json(route('kimai.catalog-preview')), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': @json(csrf_token()),
        },
        body: new URLSearchParams({
          kimai_url: $('kimai-url').value,
          kimai_token: $('kimai-token').value,
        }),
      });
      const answer = await response.json();
      if (!response.ok || !Array.isArray(answer.customers) ||
          !Array.isArray(answer.projects) || !Array.isArray(answer.activities)) {
        throw new Error('Não foi possível consultar os projetos. Verifique o endereço e o token.');
      }
      data = answer;
      select('catalog-customers', answer.customers, 'Escolha um cliente');
      select('catalog-projects', [], 'Escolha um cliente primeiro');
      select('catalog-activities', [], 'Escolha um projeto primeiro');
      if (answer.timezone) {
        const timeField = document.querySelector('input[name="timezone"]');
        timeField.value = answer.timezone;
      }
      message.textContent = 'Catálogo carregado. Escolha cliente, projeto e atividade pelo nome.';
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
})();
(() => {
  const status = document.getElementById('tg-status');
  if (!status) return;
  const $ = id => document.getElementById(id);
  const urls = {
    status: @json(route('kimai.telegram.status')),
    qr: @json(route('kimai.telegram.qr')),
    twoFactor: @json(route('kimai.telegram.2fa')),
    activate: @json(route('kimai.telegram.activate')),
  };
  const csrf = @json(csrf_token());
  let poll = null;
  let expiresAt = 0;
  const stopPoll = () => {
    if (poll !== null) clearInterval(poll);
    poll = null;
  };
  const message = value => { status.textContent = value; };
  const post = async (url, payload={}) => {
    const response = await fetch(url,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
      body:JSON.stringify(payload),
    });
    const json = await response.json();
    if (!response.ok) {
      throw new Error(json.message || 'Operação não concluída; tente novamente.');
    }
    return json;
  };
  const applyState = data => {
    const s = data.status;
    const authorized = s === 'authorized';
    const needs2fa = s === 'password_required';
    const inQr = s === 'scan_qr';
    const enabled = data.enabled === true;
    $('tg-start').style.display = ['ready_for_qr','scan_qr','expired','error'].includes(s) ? '' : 'none';
    $('tg-2fa-form').style.display = needs2fa ? '' : 'none';
    $('tg-authorized-area').style.display = authorized && !enabled ? '' : 'none';
    if (inQr && typeof data.qr_svg_base64 === 'string') {
      $('tg-qr-img').src = 'data:image/svg+xml;base64,'+data.qr_svg_base64;
      $('tg-qr-area').style.display = '';
      expiresAt = Date.now() + Math.max(0,Number(data.expires_in)||0)*1000;
      $('tg-start').textContent = 'Gerar novo QR Code';
    } else if (authorized || needs2fa || s === 'not_configured' || s === 'expired' || s === 'error' || s === 'pending') {
      $('tg-qr-img').removeAttribute('src');
      $('tg-qr-area').style.display = 'none';
      stopPoll();
    }
    const labels = {
      not_configured:'Configure as credenciais da API e as conversas autorizadas.',
      ready_for_qr:'Credenciais configuradas. Clique em gerar QR Code para autenticar.',
      scan_qr:'QR Code gerado. Escaneie pelo aplicativo Telegram no celular.',
      password_required:'O Telegram solicitou a senha de verificação em duas etapas.',
      authorized: enabled
        ? 'Telegram autenticado e coleta de metadados ativada (somente revisão).'
        : 'Telegram autenticado. Ative a coleta de metadados abaixo.',
      pending:'Preparando a sessão ou validando a autenticação...',
      starting:'Iniciando o serviço local Telegram...',
      expired:'O QR Code expirou. Gere outro para continuar.',
      error:'Não foi possível concluir a autenticação. Verifique a conexão e tente novamente.',
    };
    message(data.message && s === 'password_required' ? data.message : (labels[s] || labels.error));
  };
  const check = async () => {
    try {
      const response = await fetch(urls.status,{
        method:'GET',credentials:'same-origin',cache:'no-store',
        headers:{'Accept':'application/json'},
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Falha na consulta');
      applyState(data);
      if (expiresAt && Date.now() >= expiresAt &&
          data.status === 'ready_for_qr') {
        $('tg-qr-area').style.display='none';
        message('O QR Code expirou. Clique em gerar novo QR Code.');
        stopPoll();
      }
    } catch (error) {
      message('Não foi possível consultar o Telegram. Verifique a conexão local e tente novamente.');
      stopPoll();
    }
  };
  $('tg-refresh').addEventListener('click',check);
  $('tg-start').addEventListener('click',async () => {
    const button=$('tg-start');
    button.disabled=true;
    message('Solicitando código QR ao Telegram...');
    try {
      const data=await post(urls.qr);
      applyState(data);
      if (data.status==='scan_qr' || data.status==='pending' || data.status==='starting') {
        stopPoll();
        poll=setInterval(check,4000);
      }
    } catch (error) {message(error.message);}
    finally {button.disabled=false;}
  });
  $('tg-2fa-form').addEventListener('submit',async event => {
    event.preventDefault();
    const input=$('tg-2fa-password');
    const password=input.value;
    input.value='';
    if (!password) return;
    const button=$('tg-2fa-form').querySelector('button');
    button.disabled=true;
    message('Validando a senha de duas etapas diretamente no Telegram...');
    try {
      const data=await post(urls.twoFactor,{password});
      applyState(data);
      stopPoll();
      poll=setInterval(check,3000);
      await check();
    } catch (error) {message(error.message);}
    finally {button.disabled=false;}
  });
  $('tg-activate').addEventListener('click',async () => {
    const button=$('tg-activate');
    button.disabled=true;
    try {
      const data=await post(urls.activate);
      applyState(data);
    } catch(error) {message(error.message);}
    finally {button.disabled=false;}
  });
  check();
})();
</script>
</body>
</html>
