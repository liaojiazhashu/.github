'use strict';
/* 390 那 10px 的来源：页面横向溢出 → 页面缩放 → 布局视口(innerHeight) 比设备高更高 */
const fs=require('fs'),os=require('os'),path=require('path'),http=require('http');
const {spawn}=require('child_process');
const ROOT=path.resolve(__dirname,'..'),PORT=8132,DEBUG=9349,ORIGIN=`http://127.0.0.1:${PORT}`;
const MIME={'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'application/javascript; charset=utf-8','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2'};
const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
const CASES=[[390,740],[390,742],[390,752],[414,752],[480,763]];
function startServer(){return new Promise(res=>{const srv=http.createServer((req,r)=>{const rel=decodeURIComponent(req.url.split('?')[0]);const f=path.join(ROOT,rel==='/'?'index.html':rel);if(!f.startsWith(ROOT)||!fs.existsSync(f)||fs.statSync(f).isDirectory()){r.writeHead(404);r.end('404');return;}r.writeHead(200,{'Content-Type':MIME[path.extname(f)]||'application/octet-stream'});fs.createReadStream(f).pipe(r);});srv.listen(PORT,'127.0.0.1',()=>res(srv));});}
function edgePath(){for(const p of['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe','C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'])if(fs.existsSync(p))return p;throw new Error('no edge');}
async function waitDevtools(port){for(let i=0;i<60;i++){try{const r=await fetch(`http://127.0.0.1:${port}/json/version`);const v=await r.json();if(v.webSocketDebuggerUrl)return v.webSocketDebuggerUrl;}catch{}await sleep(300);}throw new Error('devtools');}
function makeCdp(ws){let seq=0;const pending=new Map(),events=new Map();ws.addEventListener('message',(e)=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const{resolve,reject}=pending.get(m.id);pending.delete(m.id);m.error?reject(new Error(m.error.message)):resolve(m.result);}else if(m.method&&events.has(m.method)){events.get(m.method).splice(0).forEach(fn=>fn(m.params));}});return{send(method,params={},sessionId){const id=++seq;return new Promise((resolve,reject)=>{pending.set(id,{resolve,reject});const p={id,method,params};if(sessionId)p.sessionId=sessionId;ws.send(JSON.stringify(p));setTimeout(()=>{if(pending.has(id)){pending.delete(id);reject(new Error('timeout '+method));}},30000);});},on(m,f){const l=events.get(m)||[];l.push(f);events.set(m,l);}};}
(async()=>{
  const srv=await startServer();
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'hq-sc-'));
  const child=spawn(edgePath(),['--headless=new',`--remote-debugging-port=${DEBUG}`,`--user-data-dir=${profile}`,'--no-first-run','--disable-gpu','--window-size=1440,1000','about:blank'],{stdio:'ignore'});
  process.on('exit',()=>{try{child.kill()}catch{};try{fs.rmSync(profile,{recursive:true,force:true})}catch{};try{srv.close()}catch{}});
  const ws=new WebSocket(await waitDevtools(DEBUG));
  await new Promise((res,rej)=>{ws.addEventListener('open',res,{once:true});ws.addEventListener('error',()=>rej(new Error('ws')),{once:true});});
  const browser=makeCdp(ws);
  const {targetId}=await browser.send('Target.createTarget',{url:'about:blank'});
  const {sessionId}=await browser.send('Target.attachToTarget',{targetId,flatten:true});
  const S=(m,p)=>browser.send(m,p,sessionId);
  let ctx=null;
  browser.on('Runtime.executionContextsCleared',()=>{ctx=null});
  browser.on('Runtime.executionContextCreated',(p)=>{if(p?.context?.auxData?.isDefault)ctx=p.context.id});
  await S('Page.enable');await S('Runtime.enable');
  const ev=async(expr)=>{const call=(w)=>S('Runtime.evaluate',{expression:expr,returnByValue:true,awaitPromise:true,...(w&&ctx?{contextId:ctx}:{})});let r;try{r=await call(true)}catch{ctx=null;r=await call(false)}if(r.exceptionDetails)throw new Error(r.exceptionDetails.text);return r.result?r.result.value:undefined;};
  let first=true;
  console.log('宽×高(mobile:false) | scrollWidth | clientW | innerW | innerH | 上限(innerH-35) | need | A');
  for(const [w,h] of CASES){
    if(first){await S('Emulation.setDeviceMetricsOverride',{width:w,height:h,deviceScaleFactor:1,mobile:false});
      await S('Page.navigate',{url:`${ORIGIN}/index.html`});await sleep(700);first=false;
    } else {await S('Emulation.setDeviceMetricsOverride',{width:w,height:h,deviceScaleFactor:1,mobile:false});await sleep(300);}
    const r=await ev(`(function(){var d=document.getElementById('invite-modal');if(!d.open)d.showModal();d.scrollTop=0;var de=document.documentElement;var b=d.getBoundingClientRect();return {sw:de.scrollWidth,cw:de.clientWidth,iw:window.innerWidth,ih:window.innerHeight,cap:+(window.innerHeight-35).toFixed(2),need:d.scrollHeight-d.clientHeight,dlgH:+b.height.toFixed(2)};})()`);
    console.log(`${w}×${h} | ${r.sw} | ${r.cw} | ${r.iw} | ${r.ih} | ${r.cap} | ${r.need} | ${r.need<=0} (dlgH ${r.dlgH})`);
  }
  ws.close();process.exit(0);
})().catch(e=>{console.error('失败：',e.message);process.exit(1)});
