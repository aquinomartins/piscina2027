// Interface/API reais, somente com fixture demo local. Sem acesso à hospedagem.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const crypto=require('node:crypto');
const base=process.env.TEST_URL||'http://127.0.0.1:8128';
const root=process.env.TEST_BASE_PATH||'';
const out=process.env.TEST_ARTIFACTS||'work/admin-image-browser';
const adminPass=process.env.PISCINA_DEMO_ADMIN_PASSWORD,pass=process.env.PISCINA_DEMO_PASSWORD;
if(!adminPass||!pass)throw Error('Defina senhas exclusivamente locais de demonstração.');
const action='admin.nft.image_upload';
(async()=>{
 await fs.mkdir(out,{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const ctx=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Sao_Paulo'});
 const other=await browser.newContext();const page=await ctx.newPage();const report=[],errors=[];
 page.on('pageerror',e=>errors.push(e.message));
 const url=a=>`${base}${root}/api.php?action=${a}`;
 async function get(a,c=ctx){const r=await c.request.get(url(a));return {status:r.status(),data:await r.json()};}
 async function post(a,data,c=ctx){const meta=await get('meta',c);const r=await c.request.post(url(a),{headers:{'X-CSRF-Token':meta.data.data.csrf,'Idempotency-Key':crypto.randomUUID()},data});return {status:r.status(),data:await r.json()};}
 let image;
 async function upload(fields={},c=ctx,key=crypto.randomUUID(),file=null,csrf=true){
  const meta=await get('meta',c);const r=await c.request.post(url(action),{headers:{...(csrf?{'X-CSRF-Token':meta.data.data.csrf}:{}),'Idempotency-Key':key},multipart:{nft_id:'1',reason:'Correção administrativa da imagem de teste',confirm:'1',password:adminPass,...fields,image:file||{name:'nova.png',mimeType:'image/png',buffer:image}}});
  return {status:r.status(),data:await r.json()};
 }
 const check=name=>{report.push(name);console.log('PASS '+name);};
 try{
  assert.equal((await get('meta')).data.data.environment,'demo');
  assert.equal((await get('admin.nft&nft_id=1')).status,401);
  assert.equal((await get('admin.nft.image_result&key=inexistente')).status,401);
  await page.goto(`${base}${root}/index.php`);await page.waitForSelector('.nft-card');
  image=Buffer.from(await page.evaluate(()=>{const c=document.createElement('canvas');c.width=2400;c.height=1200;const x=c.getContext('2d');x.fillStyle='#c84636';x.fillRect(0,0,c.width,c.height);return c.toDataURL('image/png').split(',')[1];}),'base64');
  const imagePath=out+'/nova.png';await fs.writeFile(imagePath,image);
  assert.equal((await upload({},ctx)).status,401);
  assert.equal((await post('auth.login',{email:'artista1@demo.invalid',password:pass},other)).status,200);
  assert.equal((await get('admin.nft&nft_id=1',other)).status,403);
  assert.equal((await get('admin.nft.image_result&key=inexistente',other)).status,403);
  assert.equal((await upload({},other)).status,403);
  assert.equal((await post('auth.login',{email:'admin@demo.invalid',password:adminPass})).status,200);
  const n=(await get('admin.nft&nft_id=1')).data.data;
  assert.equal(n.creator_id,'2');assert.equal(n.state,'POOL');assert.equal(n.owner_id,null);
  const publicBefore=(await get('nft&id=1')).data.data;
  assert(!('edit_version'in publicBefore));assert(!('image_path'in publicBefore));
  const before=(await get('admin')).data.data;
  const economic=d=>JSON.stringify(d.accounts.map(a=>[a.asset,a.purpose,a.balance]).sort());
  const beforeMedia=await ctx.request.get(`${base}${root}/image.php?id=1&thumb=1&v=${n.image_version}`);
  const oldDigest=crypto.createHash('sha256').update(await beforeMedia.body()).digest('hex');
  check('Acesso ao upload e consulta restrito ao Admin, inclusive com NFT de outro criador na piscina');
  const fields={expected_version:n.edit_version};
  assert.equal((await upload(fields,ctx,crypto.randomUUID(),null,false)).status,403);
  assert.equal((await upload({...fields,password:'incorreta'})).status,403);
  assert.equal((await upload({...fields,reason:'x'})).status,422);
  assert.equal((await upload({...fields,confirm:'0'})).status,422);
  assert.equal((await upload(fields,ctx,crypto.randomUUID(),{name:'falsa.jpg',mimeType:'image/jpeg',buffer:Buffer.from('<?php echo 1; ?>')})).status,422);
  assert.equal((await upload(fields,ctx,crypto.randomUUID(),{name:'grande.png',mimeType:'image/png',buffer:Buffer.alloc(5242881)})).status,422);
  assert.equal((await post('admin.nft.image_replace',{...fields,nft_id:'1',password:adminPass})).status,404);
  assert.equal((await get('nft&id=1')).data.data.image_version,n.image_version);
  check('CSRF, senha, motivo, confirmação, MIME real e tamanho verificados; falhas mantêm a imagem anterior');
  await page.goto(`${base}${root}/index.php?view=admin`);await page.waitForSelector('.admin-nft-panel');
  await page.locator('[data-form="admin.nft.lookup"] [name=nft_id]').fill('1');
  await page.locator('[data-form="admin.nft.lookup"] button').click();await page.waitForSelector('[data-action=admin-nft-image]');
  for(const width of [1440,768,390,320]){
   await page.setViewportSize({width,height:900});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),width);
  }
  await page.locator('[data-action=admin-nft-image]').click();await page.waitForSelector('[data-form="admin.nft.image_upload"]');
  await page.locator('dialog [name=image]').setInputFiles(imagePath);
  await page.waitForSelector('.admin-nft-preview:not([hidden])');
  await page.locator('dialog [name=reason]').fill('Correção administrativa da imagem de teste');
  await page.locator('dialog [name=password]').fill(adminPass);
  await page.locator('dialog form button.primary').click();assert.equal(await page.locator('dialog').evaluate(n=>n.open),true);
  await page.screenshot({path:out+'/trocar-imagem-mobile-320.png',fullPage:true});
  assert(await page.locator('dialog').evaluate(n=>n.scrollWidth<=n.clientWidth));
  await page.locator('dialog [name=confirm]').check();
  const sent=page.waitForRequest(r=>r.method()==='POST'&&r.url().includes('action='+action));
  await page.locator('dialog form button.primary').click();const request=await sent;const key=request.headers()['idempotency-key'];assert(key);
  await page.waitForFunction(()=>!document.querySelector('dialog').open);
  await page.waitForSelector('#admin-nft-result img');
  const newN=(await get('admin.nft&nft_id=1')).data.data;
  assert.notEqual(newN.image_version,n.image_version);assert.notEqual(newN.edit_version,n.edit_version);
  for(const k of ['creator_id','owner_id','title','state','reserved'])assert.equal(newN[k],n[k]);
  assert.equal(economic((await get('admin')).data.data),economic(before));
  assert.match(await page.locator('#admin-nft-result img').getAttribute('src'),new RegExp(newN.image_version));
  check('Troca pela interface com prévia, confirmação e versão atual; propriedade, custódia e economia preservadas');
  const replay=await upload(fields,ctx,key);assert.equal(replay.status,200);
  assert.equal((await upload({...fields,reason:'Outro motivo para a mesma chave'},ctx,key)).status,409);
  assert.equal((await upload(fields)).status,409);
  const latest=(await get('admin.nft&nft_id=1')).data.data;assert.equal(latest.image_version,newN.image_version);
  const audit=(await get('admin')).data.data.audit.filter(a=>a.kind==='admin.nft.image_replace');assert.equal(audit.length,1);
  const record=JSON.parse(audit[0].data);assert.equal(record.input.reason,fields.reason||'Correção administrativa da imagem de teste');
  assert(record.result.previous.image_path);assert(record.result.current.image_path);assert(!JSON.stringify(record).includes(adminPass));
  assert.equal(newN.events.filter(e=>e.kind==='IMAGE_REPLACED').length,1);
  check('Replay retorna o recibo sem duplicar; versão antiga e conteúdo conflitante são bloqueados; auditoria sem senha');
  const newMedia=await ctx.request.get(`${base}${root}/image.php?id=1&thumb=1&v=${newN.image_version}`);
  assert.equal(newMedia.headers()['content-type'],'image/jpeg');assert.notEqual(crypto.createHash('sha256').update(await newMedia.body()).digest('hex'),oldDigest);
  for(const [thumb,width,height]of [[false,1600,800],[true,480,240]]){
   const dimensions=await page.evaluate(async src=>{const i=new Image();i.src=src;await i.decode();return [i.naturalWidth,i.naturalHeight];},`${base}${root}/image.php?id=1${thumb?'&thumb=1':''}&v=${newN.image_version}`);
   assert.deepEqual(dimensions,[width,height]);
  }
  const creatorMeta=await get('meta',other);
  const regular=await other.request.post(url('nft.upload'),{headers:{'X-CSRF-Token':creatorMeta.data.data.csrf,'Idempotency-Key':crypto.randomUUID()},multipart:{nft_id:'1',image:{name:'nova.png',mimeType:'image/png',buffer:image}}});assert.equal(regular.status(),409);
  await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:out+'/imagem-administracao-desktop.png',fullPage:true});
  await page.goto(`${base}${root}/index.php?view=pool`);await page.waitForSelector('.nft-card[data-id="1"] img');
  assert.match(await page.locator('.nft-card[data-id="1"] img').getAttribute('src'),new RegExp(newN.image_version));
  await page.locator('.nft-card[data-id="1"]').click();await page.waitForSelector('.nft-detail-image');
  assert.match(await page.locator('.nft-detail-image').getAttribute('src'),new RegExp(newN.image_version));
  check('Original e miniatura reencodificados; cache versionado chega ao mercado; envio único do criador continua bloqueado');
  // O servidor confirma a transação, mas a resposta se perde. O cliente recupera o recibo.
  await page.locator('dialog [data-action=close]').click();
  await page.goto(`${base}${root}/index.php?view=admin`);await page.waitForSelector('.admin-nft-panel');
  await page.locator('[data-form="admin.nft.lookup"] [name=nft_id]').fill('1');
  await page.locator('[data-form="admin.nft.lookup"] button').click();await page.waitForSelector('[data-action=admin-nft-image]');
  await page.locator('[data-action=admin-nft-image]').click();
  await page.locator('dialog [name=image]').setInputFiles(imagePath);
  await page.locator('dialog [name=reason]').fill('Troca confirmada com resposta perdida');
  await page.locator('dialog [name=password]').fill(adminPass);await page.locator('dialog [name=confirm]').check();
  // Intercepta a resposta, preservando o multipart binário enviado pelo navegador.
  const cdp=await ctx.newCDPSession(page);
  let lostResponse;
  const responseLost=new Promise(resolve=>lostResponse=resolve);
  cdp.on('Fetch.requestPaused',async event=>{
   await cdp.send('Fetch.failRequest',{requestId:event.requestId,errorReason:'ConnectionClosed'});
   lostResponse(event.responseStatusCode);
  });
  await cdp.send('Fetch.enable',{patterns:[{urlPattern:'*api.php?action=admin.nft.image_upload',requestStage:'Response'}]});
  const receiptRequest=page.waitForRequest(r=>r.url().includes('action=admin.nft.image_result&key='));
  await page.locator('dialog form button.primary').click();assert.equal(await responseLost,200);await receiptRequest;
  await page.waitForFunction(()=>!document.querySelector('dialog').open);
  await page.waitForSelector('#admin-nft-result img');
  assert.equal((await get('admin')).data.data.audit.filter(a=>a.kind==='admin.nft.image_replace').length,2);
  await cdp.send('Fetch.disable');await cdp.detach();
  check('Resposta perdida após commit recupera recibo real, sem reenviar nem duplicar a troca');
  const cached=await page.evaluate(async()=>{const all=[];for(const name of await caches.keys())for(const r of await(await caches.open(name)).keys())all.push(r.url);return all;});
  assert(cached.every(u=>!u.includes('api.php')&&!u.includes('image.php')&&!u.includes('index.php')));
  await page.locator('#account-button').click();await page.locator('dialog [data-form="auth.logout"] button').click();
  await page.waitForFunction(()=>document.querySelector('#account-name').textContent==='Entrar');assert.equal((await get('admin.nft&nft_id=1')).status,401);
  assert.deepEqual(errors,[]);check('Dados e imagens fora do cache do PWA; logout encerra acesso administrativo');
  await fs.writeFile(out+'/resultado.json',JSON.stringify({passed:report,errors,mode:'API PHP/MySQL e Chromium reais em demo local'},null,2));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
