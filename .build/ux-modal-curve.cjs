'use strict';
/* 穷举而不是二分：逐 1px 扫 700–780，每档等 300ms 再读；
   判定翻转的那一档再等 400ms 复读两次，两次一致才采信。
   目的：排除「上一个更高/更低档留下的 stale reflow」。 */
const fs=require('fs'),os=require('os'),path=require('path'),http=require('http');
const {spawn}=require('child_process');
const ROOT=path.resolve(__dirname,'..'),PORT=8130,DEBUG=9347,ORIGIN=`http://127.0.0.1:${PORT}`;
const MIME={'.html':'text/html; charset=utf-8','.css':'text/css; charset=utf-8','.js':'application/javascript; charset=utf-8','.png':'image/png','.jpg':'image/jpeg','.svg':'image/svg+xml','.woff2':'font/woff2'};
const sleep=(ms)=>new Promise(r=>setTimeout(r,ms));
const WIDTHS=(process.env.WIDTHS||'390,414,479,480').split(',').map(Number);
const H0=700,H1=780;
function startServer(){return new Promise(res=>{const srv=http.createServer((req,r)=>{const rel=decodeURIComponent(req.url.split('?')[0]);const f=path.join(ROOT,rel==='/'?'index.html':rel);if(!f.startsWith(ROOT)||!fs.existsSync(f)||fs.statSync(f).isDirectory()){r.writeHead(404);r.end('404');return;}r.writeHead(200,{'Content-Type':MIME[path.extname(f)]||'application/octet-stream'});fs.createReadStream(f).pipe(r);});srv.listen(PORT,'127.0.0.1',()=>res(srv));});}
function edgePath(){for(const p of['C://Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe','C://Program Files\\Microsoft\\Edge\\Application\\msedge.exe'])if(fs.existsSync(p))return p;throw new Error('no edge');}
async function waitDevtools(port){for(let i=0;i<60;i++){try{const r=await fetch(`http://127.0.0.1:${port}/json/version`);const v=await r.json();if(v.webSocketDebuggerUrl)return v.webSocketDebuggerUrl;}catch{}await sleep(300);}throw new Error('devtools');}
function makeCdp(ws){let seq=0;const pending=new Map(),events=new Map();ws.addEventListener('message',(e)=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){const{resolve,reject}=pending.get(m.id);pending.delete(m.id);m.error?reject(new Error(m.error.message)):resolve(m.result);}else if(m.method&&events.has(m.method)){events.get(m.method).splice(0).forEach(fn=>fn(m.params));}});return{send(method,params={},sessionId){const id=++seq;return new Promise((resolve,reject)=>{pending.set(id,{resolve,reject});const p={id,method,params};if(sessionId)p.sessionId=sessionId;ws.send(JSON.stringify(p));setTimeout(()=>{if(pending.has(id)){pending.delete(id);reject(new Error('timeout '+method));}},30000);});},on(m,f){const l=events.get(m)||[];l.push(f);events.set(m,l);}};}
(async()=>{
  const srv=await startServer();
  const profile=fs.mkdtempSync(path.join(os.tmpdir(),'hq-cv-'));
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
  const probe=`(function(){var d=document.getElementById('invite-modal');d.scrollTop=0;var f=d.querySelector('.invite-modal__foot a');var db=d.getBoundingClientRect(),fb=f.getBoundingClientRect();return {A:d.scrollHeight-d.clientHeight<=0,B:fb.bottom<=db.bottom+0.5,need:d.scrollHeight-d.clientHeight,ih:window.innerHeight,dch:document.documentElement.clientHeight,dlgH:+db.height.toFixed(2)};})()`;
  for(const w of WIDTHS){
    await S('Emulation.setDeviceMetricsOverride',{width:w,height:800,deviceScaleFactor:1,mobile:w<500});
    await S('Page.navigate',{url:`${ORIGIN}/index.html`});await sleep(700);
    await ev(`(function(){var d=document.getElementById('invite-modal');if(!d.open)d.showModal();return d.open})()`);
    await sleep(300);
    let prevA=null,prevB=null,aMin=null,bMin=null,rows=[];
    for(let h=H0;h<=H1;h++){
      await S('Emulation.setDeviceMetricsOverride',{width:w,height:h,deviceScaleFactor:1,mobile:w<500});
      await sleep(300);
      let r=await ev(probe);
      if((prevA!==null&&r.A!==prevA)||(prevB!==null&&r.B!==prevB)){
        // 翻转档：等 400ms 复读两次，必须一致
        await sleep(400);const r2=await ev(probe);await sleep(120);const r3=await ev(probe);
        if(r2.A!==r3.A||r2.B!==r3.B){console.log(`  ⚠ ${w}×${h} 翻转档两次复读不一致：${r2.A}/${r3.A} ${r2.B}/${r3.B}`);}
        r={...r,flag:true,agree:(r2.A===r3.A&&r2.B===r3.B)};
      }
      if(prevA!==null&&r.A&&!prevA)aMin=h;
      if(prevB!==null&&r.B&&!prevB)bMin=h;
      if(r.flag||h===H0||h===H1)rows.push(`${w}×${h} innerH ${r.ih} docH ${r.dch} dlgH ${r.dlgH} need ${r.need} A ${r.A} B ${r.B}${r.agree===false?' (不一致)':''}`);
      prevA=r.A;prevB=r.B;
    }
    console.log(`\n--- ${w} --- 首次 A 通过 ${aMin ?? '未通过'} ｜ 首次 B 通过 ${bMin ?? '未通过'}`);
    rows.forEach(x=>console.log('  '+x));
  }
  ws.close();process.exit(0);
})().catch(e=>{console.error('失败：',e.message);process.exit(1)});
