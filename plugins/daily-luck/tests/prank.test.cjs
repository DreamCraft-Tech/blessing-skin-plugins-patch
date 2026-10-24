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
  const frames=new Map();let frameId=0;
  function movable(node,left=250,top=200,width=100,height=50){
    const props=new Map();node.style={getPropertyValue:key=>props.get(key)?.[0]||'',getPropertyPriority:key=>props.get(key)?.[1]||'',setProperty:(key,value,priority='')=>props.set(key,[value,priority]),removeProperty:key=>props.delete(key)};
    node.getBoundingClientRect=()=>{const x=parseFloat(node.style.getPropertyValue('--daily-dodge-x'))||0;const y=parseFloat(node.style.getPropertyValue('--daily-dodge-y'))||0;return {left:left+x,top:top+y,right:left+x+width,bottom:top+y+height,width,height};};
    return node;
  }
  cards.forEach((card,i)=>{card.classList.contains=name=>card.names.has(name);movable(card,200+i*200,150,140,100);});
  const area={classList:{add:(...names)=>names.forEach(n=>classes.add(n)),remove:(...names)=>names.forEach(n=>classes.delete(n))}};
  area.querySelectorAll=()=>cards;
  const panel=new Element();panel.dataset={effect,motion,duration};panel.closest=()=>area;
  const trigger=new Element(), restore=new Element(), status=new Element();restore.hidden=true;
  for(const node of [trigger,restore]){const names=new Set();node.classList={add:n=>names.add(n),remove:n=>names.delete(n),contains:n=>names.has(n)};movable(node);}
  const doc=new EventTarget();const win=new EventTarget();win.matchMedia=()=>({matches:reduced});win.innerWidth=1000;win.innerHeight=800;win.requestAnimationFrame=cb=>{frames.set(++frameId,cb);return frameId;};win.cancelAnimationFrame=id=>frames.delete(id);
  const nodes={'daily-luck-prank':present?panel:null,'daily-prank-trigger':trigger,'daily-prank-reset':restore,'daily-prank-status':status};doc.getElementById=id=>nodes[id];doc.querySelectorAll=()=>[...cards,trigger,restore];doc.body={appendChild:el=>children.push(el)};doc.createElement=()=>{const el=new Element();el.remove=()=>{const i=children.indexOf(el);if(i>=0)children.splice(i,1);};return el;};
  const timers=new Map();let id=0;
  vm.runInNewContext(source,{document:doc,window:win,setTimeout:(cb,ms)=>{timers.set(++id,{cb,ms});return id;},clearTimeout:key=>timers.delete(key),Math});
  return {classes,cards,children,frames,move:(x,y,type='mouse')=>{const event=new Event('pointermove');Object.assign(event,{clientX:x,clientY:y,pointerType:type});doc.dispatchEvent(event);},flush:()=>{const pending=[...frames.values()];frames.clear();pending.forEach(cb=>cb());},trigger,restore,status,timers,click:()=>trigger.dispatchEvent(new Event('click')),reset:()=>restore.dispatchEvent(new Event('click')),esc:()=>{const e=new Event('keydown');e.key='Escape';doc.dispatchEvent(e);},hide:()=>win.dispatchEvent(new Event('pagehide'))};
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
test('motion effects fall back to static color when reduced motion is requested',()=>{for(const effect of ['fly','shake','pulse','chaos','dodge']){const f=fixture({effect,reduced:true});f.click();f.click();f.click();assert.equal(f.classes.has('daily-prank-rainbow'),true);assert.equal(f.classes.has('daily-prank-'+effect),false);assert.ok(f.cards.every(c=>c.names.size===0));f.reset();assert.equal(f.children.length,0);}});

test('cards and buttons dodge a nearby pointer, stay in view and restore previous properties',()=>{
 const f=fixture({effect:'dodge'});f.trigger.style.setProperty('--daily-dodge-x','2px','important');f.click();f.click();f.click();
 f.move(300,220);f.move(300,220);assert.equal(f.frames.size,1);f.flush();assert.notEqual(f.trigger.style.getPropertyValue('--daily-dodge-y'),'');assert.equal(f.cards[0].names.has('daily-prank-dodger'),true);
 assert.equal(f.restore.style.getPropertyValue('--daily-dodge-x'),'');
 for(let i=0;i<40;i++){const r=f.trigger.getBoundingClientRect();f.move(r.left+r.width/2,r.top+r.height/2);f.flush();}
 const rect=f.trigger.getBoundingClientRect();assert.ok(rect.left>=12 && rect.right<=988 && rect.top>=12 && rect.bottom<=788);
 f.move(100,100);assert.equal(f.frames.size,1);f.reset();assert.equal(f.frames.size,0);assert.equal(f.trigger.style.getPropertyValue('--daily-dodge-x'),'2px');assert.equal(f.trigger.style.getPropertyPriority('--daily-dodge-x'),'important');assert.equal(f.trigger.style.getPropertyValue('--daily-dodge-y'),'');assert.ok(f.cards.every(c=>c.names.size===0));
 f.move(300,220);assert.equal(f.frames.size,0);
});
test('dodge ignores touch input and reduced motion never installs a pointer handler',()=>{for(const reduced of [false,true]){const f=fixture({effect:'dodge',reduced});f.click();f.click();f.click();f.move(300,220,'touch');assert.equal(f.frames.size,0);if(reduced){f.move(300,220);assert.equal(f.frames.size,0);}f.reset();}});

test('wild is independent of the selected effect and always cleans up',()=>{for(const effect of ['rainbow','paper','fly','shake','pulse','chaos','dodge']){const f=fixture({effect,motion:'wild'});f.click();f.click();f.click();assert.equal(f.classes.has('daily-prank-wild'),true);assert.equal(f.classes.has('daily-prank-'+effect),true);f.esc();assert.equal(f.classes.size,0);assert.equal(f.children.length,0);assert.ok(f.cards.every(c=>c.names.size===0));}});
test('wild respects reduced motion and mouse avoidance stays within viewport',()=>{const reduced=fixture({effect:'chaos',motion:'wild',reduced:true});reduced.click();reduced.click();reduced.click();assert.equal(reduced.classes.has('daily-prank-wild'),false);assert.equal(reduced.classes.has('daily-prank-rainbow'),true);reduced.reset();const f=fixture({effect:'dodge',motion:'wild'});f.click();f.click();f.click();for(let i=0;i<20;i++){const r=f.trigger.getBoundingClientRect();f.move(r.left+50,r.top+25);f.flush();}const r=f.trigger.getBoundingClientRect();assert.ok(r.left>=12&&r.right<=988&&r.top>=12&&r.bottom<=788);f.reset();assert.equal(f.frames.size,0);});
