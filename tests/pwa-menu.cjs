// Frontend real + API simulada; testa service workers reais sem banco ou produção.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const http=require('node:http');
const fs=require('node:fs/promises');
const path=require('node:path');

const root=path.resolve(__dirname,'..');
const out=path.resolve(process.env.TEST_ARTIFACTS||'work/pwa-menu');
const user={id:'1',display_name:'Artista de teste',status:'approved',role:'participant'};
const report=[];
let version='old',signedIn=true;

(async()=>{
 await fs.mkdir(out,{recursive:true});
 const index=await fs.readFile(path.join(root,'public/index.php'),'utf8');
 const script=await fs.readFile(path.join(root,'public/assets/app.js'),'utf8');
 const worker=await fs.readFile(path.join(root,'public/sw.js'),'utf8');
 const cacheName=worker.match(/const CACHE='([^']+)'/)[1];
 const svg=index.slice(index.indexOf('<svg class="sprite"'),index.indexOf('</svg>\n<aside')+6);
 const header=index.slice(index.indexOf('<header class="topbar"'),index.indexOf('</header>')+9)
  .replace(/<\?=\s*icon\('([^']+)'\)\s*\?>/g,(_,name)=>icon(name))
  .replace(/<\?=[\s\S]*?\?>/g,'Entrar');
 const dialog=index.slice(index.indexOf('<dialog id="dialog"'),index.indexOf('</body>'));
 assert.ok(header.includes('id="account-button"')&&dialog.includes('id="apply-update"'));
 function icon(name){return `<svg class="icon" aria-hidden="true"><use href="#i-${name}"/></svg>`;}
 function html(base){return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="${base}/assets/app.css"><script type="module" src="${base}/assets/app.js"></script></head><body data-base="${base}" data-initial-view="market" data-csrf="fixture">${svg}<aside class="sidebar"><a class="brand">Piscina</a><nav>${['market','pool','dashboard','pending'].map(v=>`<a class="nav-link" data-view="${v}">${v}</a>`).join('')}<a id="admin-link" hidden></a></nav><div class="sidebar-bottom"><button class="install-btn" data-action="install">Instalar app</button></div></aside><div class="app-shell">${header}<div id="environment-banner" hidden></div><div id="offline-banner" hidden></div><main id="main"></main></div><nav class="mobile-nav">${[['market','Explorar'],['pool','Piscina'],['dashboard','Portfólio'],['pending','Pendências']].map(([v,label])=>`<a data-view="${v}" href="${base}/index.php?view=${v}">${icon('grid')}<span>${label}</span></a>`).join('')}</nav>${dialog}</body></html>`;}
 const server=http.createServer(async(req,res)=>{
  try{
   const url=new URL(req.url,'http://localhost');
   const base=url.pathname.startsWith('/piscina/')?'/piscina':'';
   const rel=url.pathname.slice(base.length);
   res.setHeader('Cache-Control','no-store');
   if(rel==='/'||rel==='/index.php'){
    res.setHeader('Content-Type','text/html; charset=utf-8');res.end(html(base));return;
   }
   if(rel==='/api.php'){
    const action=url.searchParams.get('action');let data;
    if(action==='meta')data={csrf:'fixture',user:signedIn?user:null,environment:'production',policy:{}};
    else if(action==='auth.logout'){signedIn=false;data={csrf:'fixture',logged_out:true};}
    else if(action==='market')data={stats:{pool_nfts:0,shares:0,participants:1,trades:0},nfts:[],listings:[],users:[],feed:[],has_more:false};
    else throw Error('API inesperada: '+action);
    res.setHeader('Content-Type','application/json');res.end(JSON.stringify({ok:true,data}));return;
   }
   if(rel==='/sw.js'){
    res.setHeader('Content-Type','text/javascript');
    res.end(version==='old'?worker.replace(/const CACHE='[^']+';/,"const CACHE='piscina-static-menu-test-old';"):worker);return;
   }
   if(rel==='/assets/app.js'){
    res.setHeader('Content-Type','text/javascript');
    res.end(script+'\nwindow.__pwaTest={state,registration:()=>registration,clearPrompt:()=>{installPrompt=null;}};');return;
   }
   if(!/^\/(assets|icons)\/[\w.-]+$/.test(rel)&&rel!=='/offline.html'){res.writeHead(404);res.end();return;}
   const data=await fs.readFile(path.join(root,'public',rel.slice(1)));
   const ext=path.extname(rel);res.setHeader('Content-Type',({'.css':'text/css','.js':'text/javascript','.png':'image/png','.svg':'image/svg+xml','.woff2':'font/woff2','.html':'text/html'})[ext]||'application/octet-stream');res.end(data);
  }catch(e){res.writeHead(500);res.end(String(e));}
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const origin=`http://127.0.0.1:${server.address().port}`;
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try{
  for(const base of ['','/piscina']){
   version='old';signedIn=true;
   const ctx=await browser.newContext({viewport:{width:390,height:844}});
   await ctx.addInitScript(()=>{sessionStorage.pageLoads=String(Number(sessionStorage.pageLoads||0)+1);});
   const page=await ctx.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(origin+base+'/index.php');
   await page.waitForFunction(()=>window.__pwaTest?.registration()&&navigator.serviceWorker.controller&&document.querySelector('#account-name').textContent==='Artista de teste');
   assert.equal(await page.evaluate(()=>Number(sessionStorage.pageLoads)),1,'primeira instalação não pode recarregar');
   assert.equal(await page.locator('#update-banner').isVisible(),false,'primeira instalação não é atualização');
   async function account(){await page.locator('#account-button').click();await page.waitForSelector('dialog[open] .account-app-actions');}
   async function close(){await page.locator('dialog[open] [data-action="close"]').click();}
   for(const width of [320,360,390,412,700,701,1440]){
    await page.setViewportSize({width,height:844});await account();
    assert.equal(await page.locator('dialog h2').textContent(),user.display_name);
    for(const action of ['install','app-update']){
     const button=page.locator(`dialog [data-action="${action}"]`);
     assert.equal(await button.isVisible(),true);
     const box=await button.boundingBox();assert.ok(box.height>=44&&box.x>=0&&box.x+box.width<=width);
    }
    assert.equal(await page.locator('dialog [data-action="app-update"]').isDisabled(),true);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),width);
    if(width===390||width===1440)await page.screenshot({path:path.join(out,`${base?'subpasta':'raiz'}-menu-${width}.png`)});
    await close();
   }
   report.push(`${base||'/'}: menu acessível em 320/360/390/412/700/701/1440 px; navegação mobile preservada`);
   await page.setViewportSize({width:390,height:844});
   await page.evaluate(()=>window.__pwaTest.clearPrompt());await account();
   await page.locator('dialog [data-action="install"]').click();
   assert.match(await page.locator('dialog').textContent(),/iPhone \/ iPad/);
   assert.match(await page.locator('dialog').textContent(),/Android \/ desktop/);await close();
   await page.evaluate(()=>{
    window.promptCalls=0;const event=new Event('beforeinstallprompt',{cancelable:true});
    event.prompt=()=>{window.promptCalls++;return Promise.resolve();};event.userChoice=Promise.resolve({outcome:'accepted'});window.dispatchEvent(event);
   });
   await account();await page.locator('dialog [data-action="install"]').click();
   assert.equal(await page.evaluate(()=>window.promptCalls),1);await close();
   report.push(`${base||'/'}: Baixar o app usa prompt quando disponível e instruções como alternativa`);

   await account();version='new';
   await page.evaluate(()=>window.__pwaTest.registration().update());
   await page.waitForFunction(()=>window.__pwaTest.registration().waiting&&!document.querySelector('#update-banner').hidden&&!document.querySelector('dialog [data-action="app-update"]').disabled);
   assert.equal(await page.locator('dialog [data-app-update-badge]').isVisible(),true);
   assert.equal(await page.evaluate(()=>Number(sessionStorage.pageLoads)),1,'atualização detectada não pode recarregar');
   for(const flag of ['busy','unknown']){
    await page.evaluate(flag=>{window.__pwaTest.state[flag]=flag==='busy'?true:{};},flag);
    await page.locator('dialog [data-action="app-update"]').click();
    assert.match(await page.locator('#toast').textContent(),/Finalize ou consulte/);
    assert.ok(await page.evaluate(()=>window.__pwaTest.registration().waiting));
    await page.evaluate(flag=>{window.__pwaTest.state[flag]=flag==='busy'?false:null;},flag);
   }
   await page.screenshot({path:path.join(out,`${base?'subpasta':'raiz'}-atualizacao-disponivel.png`)});
   await page.reload();await page.waitForFunction(()=>window.__pwaTest?.registration()?.waiting&&!document.querySelector('#update-banner').hidden);
   await account();assert.equal(await page.locator('dialog [data-action="app-update"]').isEnabled(),true,'waiting pré-existente aparece no menu');
   const loads=await page.evaluate(()=>Number(sessionStorage.pageLoads));
   if(base){await close();await page.locator('#apply-update').click();}
   else await page.locator('dialog [data-action="app-update"]').click();
   await page.waitForFunction(expected=>Number(sessionStorage.pageLoads)===expected,loads+1);
   await page.waitForFunction(()=>window.__pwaTest?.registration()&&document.querySelector('#account-name').textContent==='Artista de teste');
   await page.waitForTimeout(200);
   assert.equal(await page.evaluate(()=>Number(sessionStorage.pageLoads)),loads+1,'ativação recarrega somente uma vez');
   assert.equal(await page.locator('#update-banner').isVisible(),false);
   await account();assert.equal(await page.locator('dialog [data-action="app-update"]').isDisabled(),true);
   const cached=await page.evaluate(async()=>{const keys=await caches.keys();return Promise.all(keys.map(async k=>({name:k,urls:(await(await caches.open(k)).keys()).map(r=>new URL(r.url).pathname)})));});
   assert.ok(cached.some(c=>c.name===cacheName));
   assert.ok(cached.every(c=>c.urls.every(u=>!u.endsWith('index.php')&&!u.endsWith('api.php'))));
   report.push(`${base||'/'}: atualização real via ${base?'aviso da tela inicial':'menu'}, waiting pré-existente, bloqueio de operação e recarga única aprovados`);
   await page.locator('dialog form[data-form="auth.logout"] button').click();
   await page.waitForFunction(()=>document.querySelector('#account-name').textContent==='Entrar');
   await account();assert.equal(await page.locator('dialog [data-action="install"]').isVisible(),true);
   assert.equal(await page.locator('dialog form[data-form="auth.login"]').isVisible(),true);
   assert.equal(await page.locator('dialog form[data-form="auth.logout"]').count(),0);
   assert.deepEqual(errors,[]);await ctx.close();
  }
  await fs.writeFile(path.join(out,'resultado.json'),JSON.stringify({passed:true,report},null,2));
  console.log(report.map(line=>'PASS '+line).join('\n'));
 }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1;});
