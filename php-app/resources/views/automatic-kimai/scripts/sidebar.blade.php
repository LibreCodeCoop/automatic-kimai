(() => {
  const button=document.getElementById('sidebar-toggle');
  const shell=document.getElementById('workspace-shell');
  const sidebar=document.getElementById('app-sidebar');
  if(!button||!shell||!sidebar)return;

  const desktop=window.matchMedia('(min-width: 881px)');
  const key='automatic-kimai:sidebar';
  let collapsed=false;
  let mobileOpen=false;
  try { collapsed=localStorage.getItem(key)==='collapsed'; } catch (_error) { /* Optional. */ }

  const apply=() => {
    const open=desktop.matches ? !collapsed : mobileOpen;
    shell.classList.toggle('is-collapsed',desktop.matches&&collapsed);
    sidebar.classList.toggle('is-open',!desktop.matches&&mobileOpen);
    button.setAttribute('aria-expanded',open?'true':'false');
    const action=open?'Recolher menu':'Expandir menu';
    button.setAttribute('aria-label',action);
    button.setAttribute('title',action);
    button.dataset.open=open?'true':'false';
  };

  button.addEventListener('click',()=>{
    if(desktop.matches) {
      collapsed=!collapsed;
      try { localStorage.setItem(key,collapsed?'collapsed':'expanded'); } catch (_error) {}
    } else mobileOpen=!mobileOpen;
    apply();
  });
  if(desktop.addEventListener)desktop.addEventListener('change',()=>{
    mobileOpen=false;
    apply();
  });
  apply();
})();
