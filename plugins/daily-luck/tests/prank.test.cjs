const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../assets/prank.js'),'utf8');
function fixture({effect='mixed', motion='playful', duration='30', reduced=false, present=true}={}) {
  class Element extends EventTarget { constructor(){super();this.hidden=false;this.textContent='';} }
  const classes=new Set();
  const area={classList:{add:(...names)=>names.forEach(n=>classes.add(n)),remove:(...names)=>names.forEach(n=>classes.delete(n))}};
  const panel=new Element();panel.dataset={effect,motion,duration};panel.closest=()=>area;
  const trigger=new Element(), restore=new Element(), status=new Element();restore.hidden=true;
  const doc=new EventTarget();const win=new EventTarget();win.matchMedia=()=>({matches:reduced});
  const nodes={'daily-luck-prank':present?panel:null,'daily-prank-trigger':trigger,'daily-prank-reset':restore,'daily-prank-status':status};doc.getElementById=id=>nodes[id];
  const timers=new Map();let id=0;
  vm.runInNewContext(source,{document:doc,window:win,setTimeout:(cb,ms)=>{timers.set(++id,{cb,ms});return id;},clearTimeout:key=>timers.delete(key),Math});
  return {classes,trigger,restore,status,timers,click:()=>trigger.dispatchEvent(new Event('click')),reset:()=>restore.dispatchEvent(new Event('click')),esc:()=>{const e=new Event('keydown');e.key='Escape';doc.dispatchEvent(e);},hide:()=>win.dispatchEvent(new Event('pagehide'))};
}
test('third click starts an effect; restore clears owned classes and timer',()=>{
  const f=fixture();f.click();assert.equal(f.restore.hidden,false);assert.equal(f.classes.size,0);f.click();assert.equal(f.classes.size,0);f.click();assert.equal(f.classes.size,2);assert.match(f.status.textContent,/30 秒/);assert.equal([...f.timers.values()][0].ms,30000);
  f.click();assert.equal(f.timers.size,1);f.reset();assert.equal(f.classes.size,0);assert.equal(f.timers.size,0);assert.equal(f.restore.hidden,true);assert.equal(f.trigger.textContent,'千万别点');f.click();assert.equal(f.classes.size,0);
});
test('Escape, page departure and timeout all restore the page',()=>{
  for(const exit of ['esc','hide','timeout']){const f=fixture();f.click();f.click();f.click();if(exit==='timeout'){[...f.timers.values()][0].cb();}else{f[exit]();}assert.equal(f.classes.size,0);assert.equal(f.timers.size,0);assert.equal(f.restore.hidden,true);}
});
test('effect settings and reduced motion are respected',()=>{
  for(const options of [{effect:'paper',motion:'gentle',duration:'15'},{effect:'rainbow',reduced:true,duration:'60'}]){const f=fixture(options);f.click();f.click();f.click();assert.equal(f.classes.has('daily-prank-'+options.effect),true);assert.equal(f.classes.has('daily-prank-playful'),false);assert.equal([...f.timers.values()][0].ms,Number(options.duration)*1000);}
});
test('missing widget does not install handlers or timers',()=>{const f=fixture({present:false});f.click();assert.equal(f.timers.size,0);assert.equal(f.classes.size,0);assert.equal(f.restore.hidden,true);});
