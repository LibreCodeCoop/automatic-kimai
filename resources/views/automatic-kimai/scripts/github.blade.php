(() => {
  const avatar=document.getElementById('github-avatar');
  if(!avatar)return;
  const $=id=>document.getElementById(id);
  const csrf=@json(csrf_token());
  const fallback=()=>{
    avatar.hidden=true;
    $('github-avatar-fallback').hidden=false;
  };
  avatar.addEventListener('error',fallback);
  if(avatar.complete&&!avatar.naturalWidth)fallback();
  fetch(@json(route('kimai.github.profile')),{credentials:'same-origin',cache:'no-store'})
    .then(response=>response.ok?response.json():null)
    .then(profile=>{
      if(profile&&typeof profile.login==='string'&&
         typeof profile.display_name==='string'){
        $('github-display-name').textContent=profile.display_name;
        $('github-username').textContent='@'+profile.login;
        $('github-health').textContent='Conta verificada';
        $('github-avatar-fallback').textContent=(profile.login[0]||'G').toUpperCase();
      }else{
        $('github-display-name').textContent='Chave de acesso configurada';
        $('github-username').textContent='Não foi possível verificar a identidade agora.';
        $('github-health').textContent='Verificação pendente';
      }
    }).catch(()=>{
      $('github-display-name').textContent='Chave de acesso configurada';
      $('github-username').textContent='Não foi possível verificar a identidade agora.';
      $('github-health').textContent='Verificação pendente';
    });
  $('github-disconnect').addEventListener('click',async()=>{
    if(!window.confirm('Remover a chave GitHub deste computador? A chave remota continuará válida até ser revogada no GitHub.'))return;
    const button=$('github-disconnect');
    button.disabled=true;
    $('github-disconnect-status').textContent='Removendo a chave GitHub local…';
    try{
      const response=await fetch(@json(route('kimai.github.disconnect')),{
        method:'POST',credentials:'same-origin',cache:'no-store',
        headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}
      });
      const value=await response.json();
      if(!response.ok||value.status!=='disconnected')throw new Error('Falha ao desconectar.');
      $('github-disconnect-status').textContent=value.message;
      window.location.reload();
    }catch(error){
      $('github-disconnect-status').textContent=error.message;
      button.disabled=false;
    }
  });
})();
