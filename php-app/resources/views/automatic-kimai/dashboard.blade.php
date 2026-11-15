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
<link rel="stylesheet" href="{{ asset('css/automatic-kimai.css') }}?v=6">
</head>
<body>
<header class="app-header">
<div class="header-inner">
<a class="brand" href="{{ route('kimai.home',['view'=>'overview']) }}"><span class="brand-mark" aria-hidden="true">◷</span><span><strong>Automatic Kimai</strong><small>Seu tempo de trabalho</small></span></a>
<button type="button" id="theme-switch" class="theme-switch" aria-label="Alternar entre tema claro e escuro" title="Alternar tema" aria-pressed="false">
<svg class="theme-icon moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z"/></svg>
<svg class="theme-icon sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
</button>
</div>
</header>
<div class="workspace-shell">
<aside id="app-sidebar" class="sidebar">
<div class="sidebar-mobile-head"><strong>Menu</strong><button id="menu-switch" type="button" aria-controls="sidebar-navigation" aria-expanded="false" aria-label="Abrir menu">☰</button></div>
<div id="sidebar-navigation" class="sidebar-navigation">
<nav class="sidebar-nav" aria-label="Navegação do aplicativo">
<div class="nav-group"><p class="nav-caption">Meu trabalho</p>
<a href="{{ route('kimai.home', ['view'=>'overview']) }}" class="nav-item @if($view==='overview') is-current @endif" @if($view==='overview') aria-current="page" @endif>Visão geral</a>
<a href="{{ route('kimai.home', ['view'=>'review']) }}" class="nav-item @if($view==='review') is-current @endif" @if($view==='review') aria-current="page" @endif>Revisar horas</a>
<a href="{{ route('kimai.home', ['view'=>'attendance']) }}" class="nav-item @if($view==='attendance') is-current @endif" @if($view==='attendance') aria-current="page" @endif>Trabalho presencial</a>
<a href="{{ route('kimai.home', ['view'=>'manual']) }}" class="nav-item @if($view==='manual') is-current @endif" @if($view==='manual') aria-current="page" @endif>Informar horas</a>
</div>
<div class="nav-group"><p class="nav-caption">Conexões</p>
<a href="{{ route('kimai.home', ['view'=>'integrations']) }}" class="nav-item @if($view==='integrations') is-current @endif" @if($view==='integrations') aria-current="page" @endif>Contas conectadas</a>
<a href="{{ route('kimai.home', ['view'=>'nextcloud']) }}" class="nav-item @if($view==='nextcloud') is-current @endif" @if($view==='nextcloud') aria-current="page" @endif>Nextcloud</a>
<a href="{{ route('kimai.home', ['view'=>'telegram']) }}" class="nav-item @if($view==='telegram') is-current @endif" @if($view==='telegram') aria-current="page" @endif>Telegram</a>
<a href="{{ route('kimai.home', ['view'=>'github']) }}" class="nav-item @if($view==='github') is-current @endif" @if($view==='github') aria-current="page" @endif>GitHub</a>
</div>
<div class="nav-group"><p class="nav-caption">Preferências</p>
<a href="{{ route('kimai.home', ['view'=>'kimai']) }}" class="nav-item @if($view==='kimai') is-current @endif" @if($view==='kimai') aria-current="page" @endif>Conta Kimai</a>
<a href="{{ route('kimai.home', ['view'=>'sources']) }}" class="nav-item @if($view==='sources') is-current @endif" @if($view==='sources') aria-current="page" @endif>Fontes de dados</a>
<a href="{{ route('kimai.home', ['view'=>'about']) }}" class="nav-item @if($view==='about') is-current @endif" @if($view==='about') aria-current="page" @endif>Migração e segurança</a>
</div>
</nav>
</div>
</aside>
<main id="main-content" class="main-content">
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
@if($view==='review')
@include('automatic-kimai.sections.review')
@endif
@if($view==='attendance')
@include('automatic-kimai.sections.attendance')
@endif
@if($view==='manual')
@include('automatic-kimai.sections.manual')
@endif
@if($view==='kimai')
@include('automatic-kimai.sections.kimai')
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
@if($view==='about')
@include('automatic-kimai.sections.about')
@endif
<footer class="muted">Automatic Kimai · dados locais e privados · informações não equivalem a aprovação financeira.</footer>
</main>
</div>
<script>
@include('automatic-kimai.scripts.theme')
(() => {
  const menu=document.getElementById('menu-switch');
  const sidebar=document.getElementById('app-sidebar');
  if(!menu||!sidebar)return;
  menu.addEventListener('click',()=>{
    const expanded=sidebar.classList.toggle('is-open');
    menu.setAttribute('aria-expanded',expanded?'true':'false');
    menu.setAttribute('aria-label',expanded?'Fechar menu':'Abrir menu');
  });
})();
@if($view==='kimai')
@include('automatic-kimai.scripts.catalog')
@endif
@if($view==='telegram')
@include('automatic-kimai.scripts.telegram')
@endif
@if($view==='nextcloud')
@include('automatic-kimai.scripts.nextcloud')
@endif
</script>
</body>
</html>
