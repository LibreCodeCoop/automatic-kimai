(() => {
  const start=document.getElementById('nc-start');
  if (!start) return;
  const $ = id => document.getElementById(id);
  const urls={
    status:@json(route('kimai.nextcloud.status')),
    start:@json(route('kimai.nextcloud.start')),
    poll:@json(route('kimai.nextcloud.poll')),
  };
  const csrf=@json(csrf_token());
  const desktop=/\bElectron\/\d+/i.test(navigator.userAgent);
  let timer=null;
  let waiting=false;
  let polling=false;
  const message=txt => { $('nc-status').textContent=txt; };
  const approval=url => {
    $('nc-approval').hidden=!url;
    if (url) $('nc-login-link').href=url;
    else $('nc-login-link').removeAttribute('href');
  };
  const post=async (url,data={}) => {
    const response=await fetch(url,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
      body:JSON.stringify(data),
    });
    const json=await response.json();
    if (!response.ok)throw new Error(json.message||'Não foi possível concluir a conexão.');
    return json;
  };
  const stop=() => {
    waiting=false;
    if(timer!==null)clearInterval(timer);
    timer=null;
  };
  const poll=async () => {
    if(!waiting || polling)return;
    polling=true;
    try {
      const result=await post(urls.poll);
      if (result.status==='connected') {
        stop();
        approval(null);
        message('Conta Nextcloud conectada. Atualizando a tela…');
        window.location.reload();
      } else {
        message('Aguardando sua autorização no navegador. Se ele não abriu, use Reabrir navegador.');
      }
    } catch (error) {
      stop();
      message(error.message);
    } finally { polling=false; }
  };
  const monitor=(url,opened=false) => {
    approval(url);
    waiting=true;
    if(timer!==null)clearInterval(timer);
    timer=setInterval(poll,4000);
    message(opened
      ? 'Navegador solicitado. Autorize o acesso ao Nextcloud e volte ao Automatic Kimai.'
      : 'Autorização pendente. Se necessário, clique em Reabrir navegador.');
  };
  const reconnect=$('nc-reconnect');
  if (reconnect) reconnect.addEventListener('click',() => {
    $('nc-connect-section').hidden=false;
    reconnect.hidden=true;
    start.focus();
  });
  start.addEventListener('click',async () => {
    // A browser needs the new tab during the click itself to avoid popup blocking.
    // Electron has a dedicated host-side opener after POST /nextcloud/start.
    // Never create an external Electron popup or expose a privileged JS bridge.
    let tab=null;
    if (!desktop) {
      try {
        tab=window.open('about:blank','_blank');
        if (tab) {
          tab.document.title='Conectando ao Nextcloud';
          tab.document.body.textContent='Preparando sua autorização segura no Nextcloud…';
        }
      } catch (_error) { /* Popup blocked; the explicit link remains available. */ }
    }
    start.disabled=true;
    message('Preparando a autorização no Nextcloud…');
    try {
      const result=await post(urls.start,{url:$('nc-url').value.trim()});
      if (result.status!=='waiting' || !result.login_url) {
        throw new Error('O servidor não retornou um endereço de autorização.');
      }
      if (tab && !tab.closed) {
        try {
          tab.opener=null;
          tab.location.replace(result.login_url);
        } catch (_error) { try {tab.close();}catch (_) {} }
      }
      monitor(result.login_url,!!tab || desktop);
    } catch(error) {
      if(tab && !tab.closed)tab.close();
      message(error.message);
    } finally {start.disabled=false;}
  });
  $('nc-check').addEventListener('click',poll);
  (async () => {
    try {
      const response=await fetch(urls.status,{credentials:'same-origin',cache:'no-store'});
      if (!response.ok)return;
      const state=await response.json();
      if (state.waiting && state.login_url) {
        // Resume pending authorization without opening duplicate browser tabs.
        if (reconnect) { $('nc-connect-section').hidden=false; reconnect.hidden=true; }
        monitor(state.login_url);
      }
    } catch (_error) { /* Connection initiation remains explicit. */ }
  })();
})();
