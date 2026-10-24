(() => {
  'use strict';
  const panel = document.getElementById('daily-luck-prank');
  if (!panel) return;
  const trigger = document.getElementById('daily-prank-trigger');
  const restore = document.getElementById('daily-prank-reset');
  const status = document.getElementById('daily-prank-status');
  const area = panel.closest('.content-wrapper');
  if (!trigger || !restore || !status || !area) return;
  const effects = ['rainbow', 'paper', 'fly', 'shake', 'pulse', 'chaos', 'dodge'];
  const classes = [...effects.map(effect => 'daily-prank-' + effect), 'daily-prank-playful', 'daily-prank-gentle', 'daily-prank-wild'];
  let count = 0;
  let timer = null;
  let rescue = null;
  let flying = [];
  let frame = null;
  let pointer = null;
  const dodgers = new Map();
  const clearDodge = () => {
    document.removeEventListener('pointermove', onPointer);
    if (frame !== null) window.cancelAnimationFrame(frame);
    frame = null;
    pointer = null;
    dodgers.forEach((state, node) => {
      node.classList.remove('daily-prank-dodger');
      for (const [key, value, priority] of state.previous) {
        if (value) node.style.setProperty(key, value, priority); else node.style.removeProperty(key);
      }
    });
    dodgers.clear();
  };
  const dodge = () => {
    frame = null;
    if (!pointer) return;
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const radius = panel.dataset.motion === 'wild' ? 180 : (panel.dataset.motion === 'gentle' ? 65 : 100);
    const step = panel.dataset.motion === 'wild' ? 160 : (panel.dataset.motion === 'gentle' ? 35 : 65);
    const clamp = (value, low, high) => low <= high ? Math.max(low, Math.min(high, value)) : 0;
    document.querySelectorAll('.card, .btn, button, input[type="button"], input[type="submit"]').forEach(node => {
      if (node === restore || node === rescue || node.classList.contains('daily-prank-rescue')) return;
      const rect = node.getBoundingClientRect();
      if (!rect.width || !rect.height || rect.bottom < 0 || rect.top > viewportHeight || rect.right < 0 || rect.left > viewportWidth) return;
      const nearX = clamp(pointer.x, rect.left, rect.right);
      const nearY = clamp(pointer.y, rect.top, rect.bottom);
      if (Math.hypot(pointer.x - nearX, pointer.y - nearY) > radius) return;
      if (!dodgers.has(node)) {
        dodgers.set(node, {x:0, y:0, previous:['--daily-dodge-x', '--daily-dodge-y'].map(key => [key, node.style.getPropertyValue(key), node.style.getPropertyPriority(key)])});
        node.classList.add('daily-prank-dodger');
      }
      const state = dodgers.get(node);
      let dx = rect.left + rect.width / 2 - pointer.x;
      let dy = rect.top + rect.height / 2 - pointer.y;
      if (Math.hypot(dx, dy) < 1) { dx = dodgers.size % 2 ? 1 : -1; dy = -1; }
      const length = Math.hypot(dx, dy);
      const baseX = rect.left - state.x;
      const baseY = rect.top - state.y;
      state.x = clamp(state.x + dx / length * step, 12 - baseX, viewportWidth - 12 - rect.width - baseX);
      state.y = clamp(state.y + dy / length * step, 12 - baseY, viewportHeight - 12 - rect.height - baseY);
      node.style.setProperty('--daily-dodge-x', state.x + 'px');
      node.style.setProperty('--daily-dodge-y', state.y + 'px');
    });
  };
  function onPointer(event) {
    if (event.pointerType === 'touch') return;
    pointer = {x:event.clientX, y:event.clientY};
    if (frame === null) frame = window.requestAnimationFrame(dodge);
  }
  const clearEffects = () => {
    clearDodge();
    area.classList.remove(...classes);
    flying.forEach((card, index) => card.classList.remove('daily-prank-path-' + index % 6));
    flying = [];
  };
  const reset = () => {
    clearTimeout(timer);
    timer = null;
    count = 0;
    clearEffects();
    rescue?.remove();
    rescue = null;
    trigger.textContent = '千万别点';
    restore.hidden = true;
    status.textContent = '已经恢复原样。下次还会忍不住吗？';
  };
  trigger.addEventListener('click', () => {
    count++;
    restore.hidden = false;
    if (count === 1) {
      status.textContent = '说好了千万别点，你还是点了。';
      trigger.textContent = '真的还要点？';
      return;
    }
    if (count === 2) {
      status.textContent = '好吧，再点一下，也许会有点不一样。';
      trigger.textContent = '就再点一下';
      return;
    }
    clearEffects();
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let effect = panel.dataset.effect === 'mixed' ? effects[Math.floor(Math.random() * effects.length)] : panel.dataset.effect;
    if (!effects.includes(effect)) effect = 'rainbow';
    if (reduced && !['rainbow', 'paper'].includes(effect)) effect = 'rainbow';
    area.classList.add('daily-prank-' + effect);
    if (panel.dataset.motion === 'wild' && !reduced) area.classList.add('daily-prank-wild');
    if (panel.dataset.motion === 'gentle') area.classList.add('daily-prank-gentle');
    if (['playful', 'wild'].includes(panel.dataset.motion) && !reduced && ['rainbow', 'paper'].includes(effect)) area.classList.add('daily-prank-playful');
    if (effect === 'dodge') document.addEventListener('pointermove', onPointer, {passive:true});
    if (['fly', 'chaos'].includes(effect)) {
      flying = Array.from(area.querySelectorAll('.card')).filter(card => card.id !== 'daily-luck-card');
      flying.forEach((card, index) => card.classList.add('daily-prank-path-' + index % 6));
    }
    if (!rescue) {
      rescue = document.createElement('button');
      rescue.type = 'button';
      rescue.className = 'btn btn-default daily-prank-rescue';
      rescue.textContent = '恢复原样 · Esc';
      rescue.addEventListener('click', reset);
      document.body.appendChild(rescue);
    }
    const seconds = [15, 30, 60].includes(Number(panel.dataset.duration)) ? Number(panel.dataset.duration) : 30;
    status.textContent = '你发现了一个小彩蛋！' + seconds + ' 秒后恢复，按 Esc 也可以。';
    trigger.textContent = '再来一个彩蛋';
    clearTimeout(timer);
    timer = setTimeout(reset, seconds * 1000);
  });
  restore.addEventListener('click', reset);
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && count) reset(); });
  window.addEventListener('pagehide', reset);
})();
