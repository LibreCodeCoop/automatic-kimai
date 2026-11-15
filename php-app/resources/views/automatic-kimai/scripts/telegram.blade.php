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
