(() => {
  'use strict';
  const errorBox = document.querySelector('[data-passkey-error]');
  const fail = message => { if(errorBox){errorBox.textContent=message;errorBox.hidden=false;errorBox.scrollIntoView({block:'nearest'});} };
  const base = document.querySelector('meta[name="csrf-token"]');
  const prefix = new URL(document.currentScript.dataset.base.replace(/\/$/, '') + '/');
  const post = async (path, data) => {
    const response = await fetch(new URL(path.replace(/^\/+/, ''), prefix), {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':base?.content || ''},body:JSON.stringify(data)});
    if(response.status===419){throw new Error('页面已过期，请刷新后重试。');}
    if(response.status===429){throw new Error('操作较频繁，请稍后重试。');}
    if(!response.headers.get('content-type')?.includes('application/json')){throw new Error('会话已过期，请刷新页面后重试。');}
    const value = await response.json();
    if(!response.ok){throw new Error(value.message || '操作未完成，请重试。');}return value;
  };
  const decode = value => Uint8Array.from(atob(value.replace(/-/g,'+').replace(/_/g,'/')), c=>c.charCodeAt(0));
  const encode = value => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
  const run = async (button, callback) => {
    if(!window.isSecureContext || !window.PublicKeyCredential || !navigator.credentials){fail('请使用支持通行密钥的浏览器，并通过 HTTPS 访问站点。');return;}
    button.disabled=true;if(errorBox){errorBox.hidden=true;}
    try{await callback();}catch(error){fail(error.name==='NotAllowedError'?'操作已取消或等待超时，可重新尝试。':error.name==='InvalidStateError'?'此通行密钥已添加，请选择其他设备。':error.name==='SecurityError'?'通行密钥域名不匹配，请检查站点地址配置。':error.message || '通行密钥验证未完成。');}finally{button.disabled=false;}
  };
  document.querySelectorAll('[data-passkey-purpose]').forEach(button=>button.addEventListener('click',()=>run(button,async()=>{
    const purpose=button.dataset.passkeyPurpose;
    const {publicKey}=await post('/auth/two-factor/passkey/options',{purpose});
    publicKey.challenge=decode(publicKey.challenge);
    if(publicKey.allowCredentials){publicKey.allowCredentials.forEach(credential=>credential.id=decode(credential.id));}
    const credential=await navigator.credentials.get({publicKey});
    if(!credential){throw new Error('未选择通行密钥。');}
    const result=await post('/auth/two-factor/passkey/verify',{purpose,id:encode(credential.rawId),clientDataJSON:encode(credential.response.clientDataJSON),authenticatorData:encode(credential.response.authenticatorData),signature:encode(credential.response.signature),userHandle:credential.response.userHandle?encode(credential.response.userHandle):null});
    window.location.assign(result.redirectTo);
  })));
  document.querySelector('[data-passkey-register]')?.addEventListener('click',event=>run(event.currentTarget,async()=>{
    const name=document.getElementById('passkey-name').value.trim();if(!name){throw new Error('请填写通行密钥设备名称。');}
    const {publicKey}=await post('/user/two-factor/passkeys/options',{});
    publicKey.challenge=decode(publicKey.challenge);publicKey.user.id=decode(publicKey.user.id);
    if(publicKey.excludeCredentials){publicKey.excludeCredentials.forEach(credential=>credential.id=decode(credential.id));}
    const credential=await navigator.credentials.create({publicKey});if(!credential){throw new Error('通行密钥创建未完成。');}
    const result=await post('/user/two-factor/passkeys/register',{name,id:encode(credential.rawId),clientDataJSON:encode(credential.response.clientDataJSON),attestationObject:encode(credential.response.attestationObject),transports:credential.response.getTransports?credential.response.getTransports():[]});
    window.location.assign(result.redirectTo);
  }));
  let pending=null;
  document.querySelectorAll('[data-security-confirm]').forEach(form=>form.addEventListener('submit',event=>{
    event.preventDefault();pending=form;document.getElementById('two-factor-confirm-text').textContent=form.dataset.securityConfirm;
    document.getElementById('two-factor-confirm-submit').disabled=false;document.getElementById('two-factor-modal-open').click();
  }));
  document.getElementById('two-factor-confirm-submit')?.addEventListener('click',event=>{if(pending){event.currentTarget.disabled=true;HTMLFormElement.prototype.submit.call(pending);}});
})();
