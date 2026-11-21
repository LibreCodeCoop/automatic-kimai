<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="color-scheme" content="light dark"/>
<title>Automatic Kimai — {{ $pageTitles[$view] }}</title>
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
<link rel="stylesheet" href="{{ asset('css/automatic-kimai.css') }}?v=23">
</head>
<body>
<header class="app-header">
<div class="header-inner">
<a class="brand" href="{{ route('kimai.home',['view'=>'overview']) }}">
<span class="brand-mark">@include('automatic-kimai.components.icon',['name'=>'clock-3'])</span>
<span><strong>Automatic Kimai</strong><small>Seu tempo de trabalho</small></span>
</a>
<button type="button" id="updates-trigger" class="updates-trigger" hidden
        aria-controls="updates-panel" aria-expanded="false" title="Verificar atualizações">
  @include('automatic-kimai.components.icon',['name'=>'refresh-cw'])
  <span id="updates-trigger-label">Atualizações</span>
</button>
<button type="button" id="theme-switch" class="theme-switch" aria-label="Alternar entre tema claro e escuro" title="Alternar tema" aria-pressed="false">
@include('automatic-kimai.components.icon',['name'=>'moon','class'=>'theme-icon moon-icon'])
@include('automatic-kimai.components.icon',['name'=>'sun','class'=>'theme-icon sun-icon'])
</button>
</div>
</header>
<div class="workspace-shell" id="workspace-shell">
<aside id="app-sidebar" class="sidebar">
@include('automatic-kimai.sections.navigation')
</aside>
<main id="main-content" class="main-content">
<section id="updates-panel" class="updates-panel" hidden aria-labelledby="updates-heading">
  <div class="updates-header">
    <div>
      <h2 id="updates-heading">Atualizações do aplicativo</h2>
      <p id="updates-description" class="muted" role="status" aria-live="polite">Verificando versões disponíveis…</p>
    </div>
    <button type="button" id="updates-dismiss" class="secondary" aria-label="Fechar atualizações">Fechar</button>
  </div>
  <progress id="updates-progress" max="100" value="0" hidden aria-label="Progresso do download"></progress>
  <div class="updates-actions">
    <button type="button" id="updates-check">Verificar agora</button>
    <button type="button" id="updates-release-notes" class="secondary" hidden>Ver novidades desta versão</button>
    <button type="button" id="updates-download" hidden>Baixar atualização</button>
    <button type="button" id="updates-restart" hidden>Reiniciar e atualizar</button>
    <a class="button secondary" id="updates-manual"
       href="{{ \App\AutomaticKimai\OfficialRelease::RELEASES_URL }}"
       target="_blank" rel="noopener noreferrer" hidden>Ver versões no GitHub</a>
  </div>
  <p class="muted updates-note">As atualizações somente são baixadas mediante sua confirmação. Os dados locais não são enviados ao GitHub.</p>
</section>
@if(session('ok'))<div class="notice" role="status">{{session('ok')}}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Não foi possível concluir a operação.</strong><ul>@foreach($errors->all() as $message)<li>{{$message}}</li>@endforeach</ul></div>@endif
<div class="page-intro"><div>
<p class="eyebrow">Seu espaço de trabalho</p>
<h1>{{ $pageTitles[$view] }}</h1>
<p class="muted">{{ $pageHints[$view] }}</p>
</div>
@if(!$profile)<a class="button" href="{{ route('kimai.home',['view'=>'kimai']) }}">Conectar minha conta</a>@endif
</div>
@if($view==='overview')
@include('automatic-kimai.sections.overview')
@endif
@if($view==='calendar')
@include('automatic-kimai.sections.calendar')
@endif
@if($view==='review')
@include('automatic-kimai.sections.review')
@endif
@if($view==='manual')
@include('automatic-kimai.sections.manual')
@endif
@if($view==='kimai')
@include('automatic-kimai.sections.kimai')
@endif
@if($view==='settings_sync')
@include('automatic-kimai.sections.settings-sync')
@endif
@if($view==='sources')
@include('automatic-kimai.sections.sources')
@endif
@if($view==='integrations')
@include('automatic-kimai.sections.integrations')
@endif
@if($view==='nextcloud')
@include('automatic-kimai.sections.nextcloud')
@endif
@if($view==='telegram')
@include('automatic-kimai.sections.telegram')
@endif
@if($view==='github')
@include('automatic-kimai.sections.github')
@endif
<div id="page-loading-overlay" class="page-loading-overlay" hidden aria-live="polite" aria-busy="true" role="status">
  <div class="page-loading-card">
    <span class="page-loading-spinner" aria-hidden="true"></span>
    <strong id="page-loading-message">Carregando…</strong>
    <small>Aguarde a atualização da tela.</small>
  </div>
</div>
<footer class="muted app-footer">
  <span>Automatic Kimai · dados locais e privados · informações não equivalem a aprovação financeira.</span>
  <span class="app-footer-version">
    <span id="app-version-label" aria-live="polite">Prévia local</span>
    <button type="button" id="app-check-updates" class="app-version-check"
            aria-controls="updates-panel"
            data-release-check-url="{{ route('kimai.updates.latest') }}">Verificar atualizações</button>
  </span>
</footer>
</main>
</div>
<dialog id="release-notes-dialog" class="release-notes-dialog" aria-labelledby="release-notes-title">
  <div class="release-notes-header">
    <div>
      <p class="eyebrow">Atualizações do aplicativo</p>
      <h2 id="release-notes-title">Novidades do Automatic Kimai</h2>
      <p id="release-notes-version" class="muted"></p>
    </div>
    <button type="button" id="release-notes-dismiss" class="secondary">Entendi</button>
  </div>
  <div id="release-notes-content" class="release-notes-content" aria-live="polite"></div>
  <div class="release-notes-actions">
    <button type="button" id="release-notes-retry" hidden>Tentar novamente</button>
    <a href="https://github.com/LibreCodeCoop/automatic-kimai/releases/latest"
       target="_blank" rel="noopener noreferrer">Todas as versões no GitHub</a>
  </div>
</dialog>
<script>
@include('automatic-kimai.scripts.theme')
@include('automatic-kimai.scripts.sidebar')
@include('automatic-kimai.scripts.loading')
@include('automatic-kimai.scripts.updates')
@include('automatic-kimai.scripts.release-notes')
@if($view==='kimai')
@include('automatic-kimai.scripts.catalog')
@endif
@if($view==='calendar')
@include('automatic-kimai.scripts.calendar')
@endif
@if($view==='review')
@include('automatic-kimai.scripts.review')
@endif
@if($view==='telegram')
@include('automatic-kimai.scripts.telegram')
@endif
@if($view==='nextcloud')
@include('automatic-kimai.scripts.nextcloud')
@endif
@if($view==='github')
@include('automatic-kimai.scripts.github')
@endif
</script>
</body>
</html>
