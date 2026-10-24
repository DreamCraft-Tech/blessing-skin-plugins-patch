(() => {
'use strict';
document.querySelectorAll('[data-token-generate]').forEach(button=>button.addEventListener('click',()=>{
  const bytes=new Uint8Array(32);crypto.getRandomValues(bytes);const input=document.getElementById('cdn-'+button.dataset.tokenGenerate+'-token');
  input.value=Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join('');input.type='text';input.focus();input.select();
}));
document.querySelectorAll('[data-token-show]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById('cdn-'+button.dataset.tokenShow+'-token');input.type=input.type==='password'?'text':'password';button.textContent=input.type==='password'?'显示':'隐藏';}));
const button=document.getElementById('cdn-cloudflare-fetch');button?.addEventListener('click',async()=>{
  const status=document.getElementById('cdn-cloudflare-status');button.disabled=true;status.textContent='正在获取官方地址段…';
  try{
    const response=await fetch(button.dataset.url,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}});
    if(response.status===419){throw new Error('页面已过期，请刷新后重试。');}
    if(!response.headers.get('content-type')?.includes('application/json')){throw new Error('会话已过期，请重新登录。');}
    const data=await response.json();if(!response.ok){throw new Error(data.message || '地址获取失败，请稍后重试。');}
    document.getElementById('cdn-cloudflare-ranges').value=data.ranges;status.textContent='已获取 '+data.count+' 条地址段，保存配置后生效。';
  }catch(error){status.textContent=error.message;}finally{button.disabled=false;}
});
})();
