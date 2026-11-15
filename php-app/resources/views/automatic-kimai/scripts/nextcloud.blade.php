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
  let timer=null;
  let waiting=false;
  const message=(txt) => { $('nc-status').textContent=txt; };
  const approval=(url) => {
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
    if(!waiting)return;
    try {
      const result=await post(urls.poll);
      if (result.status==='connected') {
        stop();
        approval(null);
        message('Nextcloud conectado. Sua autorização foi salva com segurança.');
        window.location.reload();
      } else message('Aguardando a autorização no navegador…');
    } catch (error) {
      stop();
      message(error.message);
    }
  };
  const monitor=(url) => {
    approval(url);
    waiting=true;
    if(timer!==null)clearInterval(timer);
    timer=setInterval(poll,4000);
    message('Abra o Nextcloud para autorizar. Esta página verificará a conclusão automaticamente.');
  };
  start.addEventListener('click',async () => {
    start.disabled=true;
    message('Solicitando autorização ao seu Nextcloud…');
    try {
      const result=await post(urls.start,{url:$('nc-url').value.trim()});
      if (result.status==='waiting' && result.login_url)monitor(result.login_url);
      else throw new Error('O servidor não retornou um endereço de autorização.');
    } catch(error) { message(error.message); }
    finally {start.disabled=false;}
  });
  $('nc-check').addEventListener('click',poll);
  (async () => {
    try {
      const response=await fetch(urls.status,{credentials:'same-origin',cache:'no-store'});
      const state=await response.json();
      if (state.waiting && state.login_url) monitor(state.login_url);
    } catch (_error) { /* No session; initial connection is explicit. */ }
  })();
})();
