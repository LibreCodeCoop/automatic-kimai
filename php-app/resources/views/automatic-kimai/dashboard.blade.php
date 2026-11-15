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
<link rel="stylesheet" href="{{ asset('css/automatic-kimai.css') }}?v=5">
</head>
<body>
<header>
<div class="header-inner">
<div><h1>Automatic Kimai</h1><p>Horas sob controle, com privacidade e confirmação humana.</p></div>
<nav class="app-nav" aria-label="Menu principal">
<a href="{{ route('kimai.home', ['view'=>'overview']) }}" class="nav-link @if($view==='overview') is-current @endif" @if($view==='overview') aria-current="page" @endif>Início</a>
<a href="{{ route('kimai.home', ['view'=>'review']) }}" class="nav-link @if($view==='review') is-current @endif" @if($view==='review') aria-current="page" @endif>Revisar horas</a>
<a href="{{ route('kimai.home', ['view'=>'settings']) }}" class="nav-link @if($view==='settings') is-current @endif" @if($view==='settings') aria-current="page" @endif>Configurações</a>
<a href="{{ route('kimai.home', ['view'=>'integrations']) }}" class="nav-link @if($view==='integrations') is-current @endif" @if($view==='integrations') aria-current="page" @endif>Integrações</a>
</nav>
<button type="button" id="theme-switch" class="theme-switch" aria-label="Alternar entre tema claro e escuro" title="Alternar tema" aria-pressed="false">
<svg class="theme-icon moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z"/></svg>
<svg class="theme-icon sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
</button>
</div>
</header>
<main>
@if (session('ok'))<div class="notice" role="status">{{session('ok')}}</div>@endif
@if ($errors->any())<div class="notice error" role="alert"><strong>Não foi possível concluir a operação.</strong>
<ul>@foreach($errors->all() as $message)<li>{{$message}}</li>@endforeach</ul></div>@endif
<div class="page-intro"><div>
<p class="eyebrow">Automatic Kimai</p>
<h2>
@if($view==='overview')
Visão geral
@elseif($view==='review')
Revisar minhas horas
@elseif($view==='settings')
Configurações
@else
Integrações
@endif
</h2>
<p class="muted">
@if($view==='overview')
Acompanhe a análise do seu tempo e identifique o que precisa de atenção.
@elseif($view==='review')
Confira lacunas e informe atividades que ocorreram longe do computador.
@elseif($view==='settings')
Ajuste sua conta e escolha quais fontes podem ser usadas.
@else
Conecte serviços opcionais de forma privada.
@endif
</p>
</div>
@if(!$profile)<a class="button" href="{{ route('kimai.home',['view'=>'settings']) }}">Configurar minha conta</a>@endif
</div>
@if($view==='overview')
<div class="notice">
<strong>Versão PHP de avaliação — somente leitura.</strong>
O lançamento automático e o registro manual no Kimai permanecem bloqueados durante a migração.
O piloto Python continua ativo para impedir perda ou duplicação de horas.
</div>
<section>
<h2>Resumo do trabalho</h2>
<div class="grid">
<div><div class="muted">Situação</div><div class="metrics" style="font-size:20px">{{ $status['result'] ?? 'never_ran' }}</div></div>
<div><div class="muted">Lacunas observadas</div><div class="metrics">{{ $status['coverage_gap_count'] ?? 0 }}</div></div>
<div><div class="muted">Intervalos a revisar</div><div class="metrics">{{ $status['manual_review_interval_count'] ?? 0 }}</div></div>
<div><div class="muted">Agenda sem cobertura</div><div class="metrics">{{ $status['calendar_gap_count'] ?? 0 }}</div></div>
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
<a href="{{ route('kimai.home',['view'=>'settings']) }}">Configurar minha conta <span aria-hidden="true">›</span></a>
<a href="{{ route('kimai.home',['view'=>'integrations']) }}">Conectar outros serviços <span aria-hidden="true">›</span></a>
</div>
</section>
@elseif($view==='review')
<section>
<h2>Intervalos que precisam de revisão</h2>
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
<section><h2>Trabalho presencial</h2>
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
<section><h2>Informar horas manualmente</h2>
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
@elseif($view==='settings')
<section>
<h2>Conectar ao Kimai</h2>
<form method="post" action="{{route('kimai.setup')}}">@csrf
<p class="muted">Conecte sua conta e escolha onde suas horas serão organizadas. São três passos; você não precisa descobrir códigos de cliente ou projeto.</p>
<div class="setup-fields">
<div><label for="kimai-url">Endereço do Kimai</label><input id="kimai-url" name="kimai_url" type="url" placeholder="https://kimai.exemplo.org" value="{{old('kimai_url',$creds['KIMAI_BASE_URL'] ?? '')}}" required></div>
<div><label for="kimai-token">Chave de acesso pessoal</label><input id="kimai-token" type="password" name="kimai_token" autocomplete="off" placeholder="{{empty($creds['KIMAI_API_TOKEN'])?'Cole aqui o token da sua conta':'Já conectada; deixe vazio para manter'}}"></div>
</div>
<details class="advanced-settings">
<summary>Opções avançadas de identificação e fuso horário</summary>
<div class="grid">
<div><label for="profile-id">Identificação local</label><input id="profile-id" name="profile_id" value="{{old('profile_id',$profile?->get('profile_id') ?? 'user')}}" required></div>
<div><label for="timezone">Fuso horário da conta</label><input id="timezone" name="timezone" value="{{old('timezone',$profile?->get('timezone') ?? date_default_timezone_get())}}" required></div>
<div><label for="work-domains">Sites profissionais autorizados</label><input id="work-domains" name="work_domains" value="{{old('work_domains',implode(', ',$profile?->get('work_domains') ?? []))}}" placeholder="projetos.exemplo.org"></div>
</div>
</details>
<input type="hidden" id="customer-id" name="customer_id" value="{{old('customer_id',$profile?->get('customer_id') ?? '')}}">
<input type="hidden" id="project-id" name="project_id" value="{{old('project_id',$profile?->get('project_id') ?? '')}}">
<input type="hidden" id="activity-id" name="activity_id" value="{{old('activity_id',$profile?->get('activity_id') ?? '')}}">
<div class="catalog-step">
<button type="button" id="catalog-load" class="secondary">Buscar meus projetos</button>
<p id="catalog-message" class="muted" role="status">Informe o endereço e a chave acima. Depois selecione onde registrar suas horas.</p>
<div class="grid">
<div><label for="catalog-customers">Cliente</label><select id="catalog-customers"><option>Carregue o catálogo primeiro</option></select></div>
<div><label for="catalog-projects">Projeto</label><select id="catalog-projects"><option>Carregue o catálogo primeiro</option></select></div>
<div><label for="catalog-activities">Tipo de atividade</label><select id="catalog-activities"><option>Carregue o catálogo primeiro</option></select></div>
</div>
</div>
<button type="submit">Salvar conexão</button>
</form>
<p class="warn">Use apenas a chave de acesso da sua própria conta. Os dados são guardados localmente.</p>
</section>
<section><h2>Fontes de informação</h2>
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
<p class="muted">Escolha somente as fontes que deseja usar. Conecte as contas na aba Integrações. Compromissos de agenda não comprovam trabalho.</p>
</section>
@elseif($view==='integrations')
<section><h2>Nextcloud e GitHub</h2>
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
@endif
<footer class="muted" style="margin:30px 0">Automatic Kimai · aplicativo local e privado · relatórios não aprovam remuneração nem faturamento.</footer>
</main>
<script>
(() => {
  const button = document.getElementById('theme-switch');
  if (!button) return;
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  const key = 'automatic-kimai:theme';
  const preference = () => {
    try { return localStorage.getItem(key); }
    catch (_error) { return null; }
  };
  const paint = () => {
    const stored = preference();
    const dark = stored === 'dark' || (stored !== 'light' && system.matches);
    const icon = dark ? 'sun' : 'moon';
    button.dataset.icon = icon;
    button.setAttribute('aria-label', dark ? 'Ativar tema claro' : 'Ativar tema escuro');
    button.setAttribute('title', dark ? 'Ativar tema claro' : 'Ativar tema escuro');
    button.setAttribute('aria-pressed', stored === 'light' || stored === 'dark' ? 'true' : 'false');
  };
  button.addEventListener('click', () => {
    const dark = document.documentElement.dataset.theme === 'dark' ||
      (!document.documentElement.dataset.theme && system.matches);
    const next = dark ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem(key, next); } catch (_error) {}
    paint();
  });
  if (system.addEventListener) system.addEventListener('change',paint);
  paint();
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
    message.textContent = 'Procurando seus projetos no Kimai...';
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
      message.textContent = 'Projetos encontrados. Escolha cliente, projeto e tipo de atividade abaixo.';
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
