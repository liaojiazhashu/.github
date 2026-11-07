'use strict';
/* 解释 390 与 414 的 minH 差：逐档扫视口高，打印弹层与卡片的真实几何 */
const fs=require('fs'),os=require('os'),path=require('path'),http=require('http');
const {spawn}=require('child_process');
const ROOT=path.resolve(__dirname,'..'),PORT=8127,DEBUG=9344,ORIGIN=`http://127.0.0.1:${PORT}`;
const MIME={'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'application/javascript; charset=utf-8','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml','.woff2':'font/woff2'};
const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
function startServer(){return new Promise(res=>{const srv=http.createServer((req,r)=>{const rel=decodeURIComponent(req.url.split('?')[0]);const f=path.join(ROOT,rel==='/'?'index.html':rel);if(!f.startsWith(ROOT)||!fs.existsSync(f)||fs.statSync(f).isDirectory()){r.writeHead(404);r.end('404');return;}r.writeHead(200,{'Content-Type':MIME[path.extname(f)]||'application/octet-stream'});fs.createReadStream(f).pipe(r);});srv.listen(PORT,'127.0.0.1',()=>res(srv));});}
function edgePath(){for(const p of['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe','C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'])if(fs.existsSync(p))return p;throw new Error('no edge');}
async function waitDevtools(port){for(let i=0;i<60;i++){try{const r=await fetch(`http://127.0.0.1:${port}/json/version`);const v=await r.json();if(v.webSocketDebuggerUrl)return v.webSocketDebuggerUrl;}catch{}await sleep(300);}throw new Error('devtools');}
function makeCdp(ws){let seq=0;const pending=new Map(),events=new Map();ws.addEventListener('message',(e)=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const{resolve,reject}=pending.get(m.id);pending.delete(m.id);m.error?reject(new Error(m.error.message)):resolve(m.result);}else if(m.method&&events.has(m.method)){events.get(m.method).splice(0).forEach(fn=>fn(m.params));}});return{send(method,params={},sessionId){const id=++seq;return new Promise((resolve,reject)=>{pending.set(id,{resolve,reject});const p={id,method,params};if(sessionId)p.sessionId=sessionId;ws.send(JSON.stringify(p));setTimeout(()=>{if(pending.has(id)){pending.delete(id);reject(new Error('timeout '+method));}},30000);});},on(m,f){const l=events.get(m)||[];l.push(f);events.set(m,l);}};}
(async()=>{
  const srv=await startServer();
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'hq-sw-'));
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
  const ev=async(expr)=>{const call=(w)=>S('Runtime.evaluate',{expression:expr,returnByValue:true,awaitPromise:true,...(w&&ctx?{contextId:ctx}:{})});let r;try{r=await call(true)}catch{ctx=null;r=await call(false)}if(r.exceptionDetails)throw new Error(r.exceptionDetails.text+' '+((r.exceptionDetails.exception||{}).description||''));return r.result?r.result.value:undefined;};
  for(const w of [390,414,479,480]){
    await S('Emulation.setDeviceMetricsOverride',{width:w,height:1000,deviceScaleFactor:1,mobile:w<500});
    await S('Page.navigate',{url:`${ORIGIN}/index.html`});await sleep(500);
    await ev(`(function(){var d=document.getElementById('invite-modal');if(!d.open)d.showModal();return d.open})()`);
    console.log(`\n--- ${w} ---`);
    for(const h of [700,720,730,735,740,742,745,750,752,755,760,763,770]){
      await S('Emulation.setDeviceMetricsOverride',{width:w,height:h,deviceScaleFactor:1,mobile:w<500});
      await sleep(120);
      const r=await ev(`(function(){var d=document.getElementById('invite-modal');d.scrollTop=0;var c=d.querySelector('.invite-modal__card');var q=d.querySelector('.invite-modal__qr');var db=d.getBoundingClientRect();var qb=q.getBoundingClientRect();return {hh:window.innerHeight,dlgH:+db.height.toFixed(2),clientH:d.clientHeight,scrollH:d.scrollHeight,cardH:+c.getBoundingClientRect().height.toFixed(2),qr:qb.width+'x'+qb.height,cardW:+c.getBoundingClientRect().width.toFixed(1)};})()`);
      console.log(`${w}×${h}: dlgH ${r.dlgH} clientH ${r.clientH} scrollH ${r.scrollH} cardH ${r.cardH} cardW ${r.cardW} qr ${r.qr}`);
    }
  }
  ws.close();process.exit(0);
})().catch(e=>{console.error('失败：',e.message);process.exit(1)});
