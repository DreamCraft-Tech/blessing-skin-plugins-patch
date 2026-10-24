const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname,'../assets/prank.js'),'utf8');
function fixture({effect='rainbow', motion='playful', duration='30', reduced=false, present=true}={}) {
  class Element extends EventTarget { constructor(){super();this.hidden=false;this.textContent='';} }
  const classes=new Set();
  const cards=Array.from({length:3},(_,i)=>{const names=new Set();return {id:i===2?'daily-luck-card':'card-'+i,names,classList:{add:n=>names.add(n),remove:n=>names.delete(n)}};});
  const children=[];
  const area={classList:{add:(...names)=>names.forEach(n=>classes.add(n)),remove:(...names)=>names.forEach(n=>classes.delete(n))}};
  area.querySelectorAll=()=>cards;
  const panel=new Element();panel.dataset={effect,motion,duration};panel.closest=()=>area;
  const trigger=new Element(), restore=new Element(), status=new Element();restore.hidden=true;
  const doc=new EventTarget();const win=new EventTarget();win.matchMedia=()=>({matches:reduced});
  const nodes={'daily-luck-prank':present?panel:null,'daily-prank-trigger':trigger,'daily-prank-reset':restore,'daily-prank-status':status};doc.getElementById=id=>nodes[id];doc.body={appendChild:el=>children.push(el)};doc.createElement=()=>{const el=new Element();el.remove=()=>{const i=children.indexOf(el);if(i>=0)children.splice(i,1);};return el;};
  const timers=new Map();let id=0;
  vm.runInNewContext(source,{document:doc,window:win,setTimeout:(cb,ms)=>{timers.set(++id,{cb,ms});return id;},clearTimeout:key=>timers.delete(key),Math});
  return {classes,cards,children,trigger,restore,status,timers,click:()=>trigger.dispatchEvent(new Event('click')),reset:()=>restore.dispatchEvent(new Event('click')),esc:()=>{const e=new Event('keydown');e.key='Escape';doc.dispatchEvent(e);},hide:()=>win.dispatchEvent(new Event('pagehide'))};
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

test('flying cards get independent paths and cleanup leaves the widget in place',()=>{const f=fixture({effect:'fly'});f.click();f.click();f.click();assert.equal(f.classes.has('daily-prank-fly'),true);assert.equal(f.cards[0].names.has('daily-prank-path-0'),true);assert.equal(f.cards[1].names.has('daily-prank-path-1'),true);assert.equal(f.cards[2].names.size,0);assert.equal(f.children.length,1);f.children[0].dispatchEvent(new Event('click'));assert.equal(f.children.length,0);assert.equal(f.classes.size,0);assert.ok(f.cards.every(c=>c.names.size===0));});
test('shake, pulse and chaos work and repeated clicks retain one recovery button',()=>{for(const effect of ['shake','pulse','chaos']){const f=fixture({effect});f.click();f.click();f.click();assert.equal(f.classes.has('daily-prank-'+effect),true);f.click();assert.equal(f.children.length,1);f.esc();assert.equal(f.children.length,0);assert.equal(f.classes.size,0);assert.ok(f.cards.every(c=>c.names.size===0));}});
test('motion effects fall back to static color when reduced motion is requested',()=>{for(const effect of ['fly','shake','pulse','chaos']){const f=fixture({effect,reduced:true});f.click();f.click();f.click();assert.equal(f.classes.has('daily-prank-rainbow'),true);assert.equal(f.classes.has('daily-prank-'+effect),false);assert.ok(f.cards.every(c=>c.names.size===0));f.reset();assert.equal(f.children.length,0);}});
