(() => {
  const overlay=document.getElementById('page-loading-overlay');
  const message=document.getElementById('page-loading-message');
  if(!overlay||!message)return;
  const show=(label='Carregando…')=>{
    message.textContent=label;
    overlay.hidden=false;
  };
  const hide=()=>{overlay.hidden=true;};
  window.AutomaticKimaiLoading={show,hide};
  // Keep navigation responsive while an ordinary GET request is being processed.
  // Never interfere with a click opening a new tab, download, or other action.
  document.addEventListener('click',event=>{
    if(event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
    const link=event.target.closest('a[href]');
    if(!link||link.target==='_blank'||link.hasAttribute('download'))return;
    let next;
    try {next=new URL(link.href,location.href);} catch(_error){return;}
    if(next.origin!==location.origin)return;
    const view=next.searchParams.get('view');
    if(view==='calendar')show('Consultando o calendário do Kimai…');
    else if(view==='telegram')show('Abrindo o Telegram…');
  },{capture:true});
  window.addEventListener('pageshow',hide);
  window.addEventListener('pagehide',hide);
})();