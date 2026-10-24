(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const type = byId('wecom-message-type');
  const style = byId('wecom-message-style');
  const title = byId('wecom-message-title');
  const template = byId('wecom-message-template');
  const button = byId('wecom-message-button');
  const detailed = '{reason}\n{site_name} 登录通知\n用户：{username}({uid})\n时间：{login_time}\n方式：{login_method}\nIP：{ip}({city})\n设备：{user_agent}\n可在账号页面查看绑定与通知设置。';
  const compact = '{reason}\n用户：{username}({uid})\n时间：{login_time}\nIP：{ip}({city})';
  const sample = {reason: '登录城市发生变化，请确认本人操作', site_name: '皮肤站', username: '张三', uid: '123',
    login_time: '2026-10-24 12:00:00 +08:00', login_method: '企业微信扫码', ip: '203.0.113.10', city: '上海', user_agent: '示例设备：Windows · Chrome'};
  function preview() {
    if (!type) return;
    byId('wecom-card-fields').hidden = type.value !== 'textcard';
    [title, button].forEach(element => {element.disabled = type.value !== 'textcard';});
    byId('wecom-template-field').hidden = style.value !== 'custom';
    template.disabled = style.value !== 'custom';
    byId('wecom-preview-title').textContent = type.value === 'textcard' ? title.value : '消息正文';
    const body = style.value === 'custom' ? template.value : (style.value === 'compact' ? compact : detailed);
    byId('wecom-preview-body').textContent = body.replace(/\{([a-z_]+)\}/g, (whole, key) => sample[key] ?? whole);
    byId('wecom-preview-button').textContent = type.value === 'textcard' ? button.value + ' →' : '';
  }
  [type, style, title, template, button].forEach(element => element?.addEventListener('input', preview));
  preview();
  const panel = byId('wecom-geo-panel');
  if (!panel) return;
  const start = byId('wecom-geo-start');
  const pause = byId('wecom-geo-pause');
  const message = byId('wecom-geo-message');
  let running = false;
  let currentPhase = 'idle';
  const loading = byId('wecom-geo-loading');
  const status = byId('wecom-geo-status');
  const activePhases = ['preparing', 'downloading', 'decompressing', 'validating'];
  async function request(action, payload) {
    const response = await fetch(panel.dataset.base + '/' + action, {
      method: payload ? 'POST' : 'GET', credentials: 'same-origin',
      headers: {'Accept': 'application/json', ...(payload ? {'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content} : {})},
      ...(payload ? {body: JSON.stringify(payload)} : {}),
    });
    if (!response.ok) throw new Error(response.status === 419 ? '登录状态已过期，请刷新页面后重试。' : '请求失败，请稍后点击继续更新。');
    return response.json();
  }
  function show(state) {
    currentPhase = state.phase;
    status.textContent = ({idle: '空闲', preparing: '检查下载', downloading: '下载中', decompressing: '解压中', validating: '校验中', complete: '更新完成', error: '更新失败'})[state.phase] || '读取完成';
    loading.hidden = !running || !activePhases.includes(state.phase);
    if (!running && activePhases.includes(state.phase)) status.textContent = '已暂停，可继续更新';
    if (state.database) {
      if (state.phase === 'idle') status.textContent = state.database.ready ? '已加载' : '待下载';
      byId('wecom-geo-ready').textContent = state.database.ready ? '已准备' : '待更新';
      byId('wecom-geo-updated').textContent = state.database.updated_at_label;
    }
    message.textContent = state.message || '';
    message.className = 'mt-3 alert ' + (state.phase === 'error' ? 'alert-danger' : state.phase === 'complete' ? 'alert-success' : 'alert-info');
    if (state.phase === 'downloading') message.textContent += ' 已下载 ' + (state.downloaded / 1048576).toFixed(1) + ' / ' + (state.total / 1048576).toFixed(1) + ' MiB';
    if (state.phase === 'decompressing') message.textContent += ' 已解压 ' + (state.decoded / 1048576).toFixed(1) + ' MiB';
    byId('wecom-geo-progress-wrap').hidden = state.phase === 'idle';
    const progress = Math.min(100, Math.max(0, Number(state.progress) || 0));
    const bar = byId('wecom-geo-progress');bar.style.width = progress + '%';bar.textContent = progress + '%';bar.setAttribute('aria-valuenow', progress);
    if (state.phase === 'complete') byId('wecom-geo-ready').textContent = '已准备';
    start.textContent = ['preparing', 'downloading', 'decompressing', 'validating'].includes(state.phase) ? '继续更新' : '下载 / 更新城市库';
  }
  start.addEventListener('click', async () => {
    if (running) return;
    running = true;start.disabled = true;pause.hidden = false;loading.hidden = false;status.textContent = '连接中…';
    try {
      let state = await request('start', {});show(state);
      while (running && !['complete', 'error'].includes(state.phase)) {
        state = await request('step', {id: state.id});show(state);
        await new Promise(resolve => setTimeout(resolve, 400));
      }
    } catch (error) {status.textContent = '请求失败';message.textContent = error.message;message.className = 'mt-3 alert alert-danger';}
    finally {running = false;start.disabled = false;pause.hidden = true;loading.hidden = true;if (activePhases.includes(currentPhase) && status.textContent !== '请求失败') status.textContent = '已暂停，可继续更新';}
  });
  pause.addEventListener('click', () => {running = false;pause.hidden = true;});
  request('status').then(show).catch(() => {status.textContent = '读取失败';message.textContent = '暂时无法读取更新进度，可以点击更新按钮重试。';}).finally(() => {loading.hidden = true;});
})();
