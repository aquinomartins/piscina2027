// API e frontend reais, exclusivamente em ambiente demo; não executa em produção.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const base=process.env.TEST_URL||'http://127.0.0.1:8127';
const root=process.env.TEST_BASE_PATH||'';
const pass=process.env.PISCINA_DEMO_PASSWORD,adminPass=process.env.PISCINA_DEMO_ADMIN_PASSWORD;
const out=process.env.TEST_ARTIFACTS||'work/admin-browser';
if(!pass||!adminPass)throw Error('Defina as senhas locais de demonstração.');

(async()=>{
 await fs.mkdir(out,{recursive:true});
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const report=[],errors=[];
 const ctx=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Sao_Paulo'});
 const page=await ctx.newPage();page.on('pageerror',e=>errors.push(e.message));
 async function get(action,context=ctx){const r=await context.request.get(`${base}${root}/api.php?action=${action}`);return {status:r.status(),body:await r.json(),headers:r.headers()};}
 async function post(action,data,context=ctx,key=crypto.randomUUID(),csrf=true){
  const meta=await get('meta',context);
  const r=await context.request.post(`${base}${root}/api.php?action=${action}`,{headers:{...(csrf?{'X-CSRF-Token':meta.body.data.csrf}:{}),'Idempotency-Key':key},data});
  return {status:r.status(),body:await r.json()};
 }
 async function search(q,status=''){
  await page.locator('[data-form="admin.search"] [name=q]').fill(q);
  await page.locator('[data-form="admin.search"] [name=status]').selectOption(status);
  const previous=await page.locator('[data-form="admin.search"]').elementHandle();
  const response=page.waitForResponse(r=>r.url().includes('action=admin&'));
  await page.locator('[data-form="admin.search"] button').click();await response;
  await page.waitForFunction(form=>!form.isConnected,previous);await previous.dispose();
 }
 function check(name){report.push(name);console.log('PASS '+name);}
 try{
  assert.equal((await get('meta')).body.data.environment,'demo');
  assert.equal((await get('admin.wallet&user_id=2')).status,401);
  const participant=await browser.newContext();
  assert.equal((await post('auth.login',{email:'artista1@demo.invalid',password:pass},participant)).status,200);
  assert.equal((await get('admin',participant)).status,403);
  assert.equal((await get('admin.wallet&user_id=3',participant)).status,403);
  const override={user_id:'6',confirm_without_email:true,reason:'Conferência presencial do cadastro',password:adminPass};
  assert.equal((await post('admin.approve_without_email',override,participant)).status,403);
  assert.equal((await post('auth.register',{email:'interface@demo.invalid',display_name:'Sem confirmação <img src=x>',password:pass,role:'admin',status:'approved',confirm_without_email:true},participant)).status,200);
  check('Anônimo e participante não acessam carteiras administrativas nem aprovam cadastros');
  assert.equal((await post('auth.login',{email:'admin@demo.invalid',password:adminPass})).status,200);
  let all=await get('admin');assert.equal(all.status,200);assert(Number(all.body.data.users_total)>24);assert.equal(all.body.data.users.length,24);
  const target=(await get('admin&q=interface%40demo.invalid')).body.data.users[0];
  assert.equal(target.role,'participant');assert.equal(target.status,'pending');assert.equal(target.email_verified_at,null);
  override.user_id=target.id;
  assert.equal((await post('admin.approve_without_email',override,ctx,crypto.randomUUID(),false)).status,403);
  assert.equal((await post('admin.approve_without_email',{...override,password:'senha-incorreta'})).status,403);
  assert.equal((await post('admin.approve_without_email',{...override,reason:'x'})).status,422);
  assert.equal((await post('admin.approve_without_email',{...override,confirm_without_email:false})).status,422);
  assert.equal((await post('admin.approve',{user_id:target.id,password:adminPass,confirm_without_email:true})).status,409);
  const privateWallet=await get(`admin.wallet&user_id=${target.id}`);
  assert.match(privateWallet.headers['cache-control'],/no-store/);assert(!JSON.stringify(privateWallet.body).includes('password_hash'));
  check('API exige CSRF, senha atual, motivo e confirmação; aprovação comum continua exigindo e-mail');
  await page.goto(`${base}${root}/index.php?view=admin`);await page.waitForSelector('#admin-participants');
  for(const width of [1440,1024,768,700,390,360,320]){
   await page.setViewportSize({width,height:1000});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),width,`Admin sem transbordamento em ${width}`);
  }
  await page.screenshot({path:out+'/admin-mobile-320.png',fullPage:true});
  await page.setViewportSize({width:1440,height:1000});
  const firstIds=await page.locator('.admin-participant [data-action=admin-wallet]').evaluateAll(nodes=>nodes.map(n=>n.dataset.id));
  await page.locator('[data-action=admin-page]').last().click();
  await page.waitForFunction(()=>document.querySelector('#admin-participants>.row-actions span')?.textContent==='Página 2');
  const secondIds=await page.locator('.admin-participant [data-action=admin-wallet]').evaluateAll(nodes=>nodes.map(n=>n.dataset.id));
  assert(!firstIds.some(id=>secondIds.includes(id)));
  await page.locator('[data-action=admin-page]').first().click();
  await page.waitForFunction(()=>document.querySelector('#admin-participants>.row-actions span')?.textContent==='Página 1');
  assert.deepEqual(await page.locator('.admin-participant [data-action=admin-wallet]').evaluateAll(nodes=>nodes.map(n=>n.dataset.id)),firstIds);
  check('Admin responsivo em sete larguras; paginação avança e retorna sem perder usuários');
  await search('interface@demo.invalid','pending');await page.waitForSelector('[data-action=admin-approve-without-email]');
  assert.equal(await page.locator('.admin-participant').count(),1);
  assert.equal(await page.locator('.admin-participant img').count(),0);
  assert.match(await page.locator('.admin-participant h3').first().textContent(),/<img src=x>/);
  await page.locator('[data-action=admin-approve-without-email]').click();
  await page.locator('dialog [name=reason]').fill('Cadastro conferido presencialmente pelo administrador');
  await page.locator('dialog [name=password]').fill(adminPass);
  await page.locator('dialog form button.primary').click();
  assert.equal(await page.locator('dialog').evaluate(n=>n.open),true,'Checkbox impede confirmação sem aceite');
  await page.locator('dialog [name=approval_ack]').check();
  await page.locator('dialog form button.primary').click();await page.waitForFunction(()=>!document.querySelector('dialog').open);
  await search('interface@demo.invalid','approved');await page.waitForSelector('[data-action=admin-wallet]');
  assert.match(await page.locator('.admin-participant').textContent(),/E-mail pendente/);
  assert.match(await page.locator('.admin-participant').textContent(),/mR\$ 1\.600,00/);
  const wallet=(await get(`admin.wallet&user_id=${target.id}`)).body.data;
  assert.equal(wallet.user.email_verified_at,null);assert.equal(wallet.user.status,'approved');
  assert.equal(wallet.balances.MR.available,'160000');assert.equal(wallet.custody.private_nfts,'1');
  check('Aprovação excepcional pela interface mantém e-mail pendente e concede saldo/NFT uma única vez');
  await page.locator('[data-action=admin-wallet]').click();await page.waitForSelector('.admin-wallet-detail');
  assert.match(await page.locator('.admin-wallet-detail').textContent(),/Extrato contábil/);
  for(const width of [1440,390,320]){
   await page.setViewportSize({width,height:900});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),width);
   assert(await page.locator('dialog').evaluate(n=>n.scrollWidth<=n.clientWidth));
  }
  await page.screenshot({path:out+'/carteira-mobile-320.png',fullPage:true});
  await page.locator('dialog [data-action=close]').click();
  await page.setViewportSize({width:1440,height:1000});await page.screenshot({path:out+'/admin-carteira-aprovada.png',fullPage:true});
  check('Carteira individual e extrato visíveis em desktop e mobile, com nomes escapados');
  const key=crypto.randomUUID();const replay1=await post('admin.approve_without_email',override,ctx,key);const replay2=await post('admin.approve_without_email',override,ctx,key);
  assert.equal(replay1.status,200);assert.equal(replay2.status,200);assert.equal(replay1.body.data.operation_id,replay2.body.data.operation_id);
  const after=(await get(`admin.wallet&user_id=${target.id}`)).body.data;
  assert.equal(after.balances.MR.available,'160000');assert.equal(after.custody.private_nfts,'1');
  assert.equal(after.history.length,1);
  const audit=(await get('admin')).body.data.audit.find(a=>a.kind==='admin.approve_without_email'&&JSON.parse(a.data).input.user_id===target.id);
  assert(audit);assert(JSON.parse(audit.data).input.reason);
  check('Repetição e idempotência preservam crédito/NFT; ação fica na auditoria administrativa');
  await page.locator('#account-button').click();await page.locator('dialog form[data-form="auth.logout"] button').click();
  await page.waitForFunction(()=>document.querySelector('#account-name').textContent==='Entrar');
  assert.equal(await page.locator('.admin-participant').count(),0);
  assert.equal((await get(`admin.wallet&user_id=${target.id}`)).status,401);
  await page.goBack();await page.waitForTimeout(250);assert.equal(await page.locator('.admin-participant').count(),0);
  check('Logout remove os dados administrativos e voltar não recupera as carteiras');
  assert.deepEqual(errors,[]);
  await fs.writeFile(out+'/resultado.json',JSON.stringify({passed:report,errors,mode:'PHP/MySQL reais locais; sem acesso à hospedagem'},null,2));
  await participant.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
