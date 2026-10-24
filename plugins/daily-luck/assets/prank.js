(() => {
  'use strict';
  const panel = document.getElementById('daily-luck-prank');
  if (!panel) return;
  const trigger = document.getElementById('daily-prank-trigger');
  const restore = document.getElementById('daily-prank-reset');
  const status = document.getElementById('daily-prank-status');
  const area = panel.closest('.content-wrapper');
  if (!trigger || !restore || !status || !area) return;
  let count = 0;
  let timer = null;
  const clearEffects = () => area.classList.remove('daily-prank-rainbow', 'daily-prank-paper', 'daily-prank-playful');
  const reset = () => {
    clearTimeout(timer);
    timer = null;
    count = 0;
    clearEffects();
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
    const effect = panel.dataset.effect === 'mixed' ? (Math.random() < 0.5 ? 'rainbow' : 'paper') : panel.dataset.effect;
    area.classList.add(effect === 'paper' ? 'daily-prank-paper' : 'daily-prank-rainbow');
    if (panel.dataset.motion === 'playful' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) area.classList.add('daily-prank-playful');
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
