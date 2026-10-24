(() => {
  'use strict';
  const panel = document.getElementById('daily-luck-prank');
  if (!panel) return;
  const trigger = document.getElementById('daily-prank-trigger');
  const restore = document.getElementById('daily-prank-reset');
  const status = document.getElementById('daily-prank-status');
  const area = panel.closest('.content-wrapper');
  if (!trigger || !restore || !status || !area) return;
  const effects = ['rainbow', 'paper', 'fly', 'shake', 'pulse', 'chaos'];
  const classes = [...effects.map(effect => 'daily-prank-' + effect), 'daily-prank-playful', 'daily-prank-gentle'];
  let count = 0;
  let timer = null;
  let rescue = null;
  let flying = [];
  const clearEffects = () => {
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
    if (panel.dataset.motion === 'gentle') area.classList.add('daily-prank-gentle');
    if (panel.dataset.motion === 'playful' && !reduced && ['rainbow', 'paper'].includes(effect)) area.classList.add('daily-prank-playful');
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
