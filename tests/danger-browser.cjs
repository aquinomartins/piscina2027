// UI/API integration with real demo database; explicitly refuses public URLs.
const{chromium}=require('playwright');const assert=require('node:assert/strict');const fs=require('node:fs');const path=require('node:path');
const base=process.env.TEST_URL||'http://127.0.0.1:8140',prefix=process.env.TEST_BASE_PATH||'',out=process.env.TEST_ARTIFACTS||'/tmp/danger-browser';
if(new URL(base).hostname!=='127.0.0.1')throw Error('Only isolated loopback server.');
const adminPass=process.env.PISCINA_DEMO_ADMIN_PASSWORD,pass=process.env.PISCINA_DEMO_PASSWORD;
if(!adminPass||!pass)throw Error('Only local demo credentials are required.');
const work=process.env.TEST_PRIVATE_DIR;
if(!work)throw Error('Private directory for local reset fixtures required.');
fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const errors=[],report=[];
 async function get(ctx,action){const r=await ctx.request.get(base+prefix+'/api.php?action='+action);return{status:r.status(),body:await r.json()};}
 async function post(ctx,action,data,key=crypto.randomUUID()){const meta=await get(ctx,'meta');const r=await ctx.request.post(base+prefix+'/api.php?action='+action,{headers:{'X-CSRF-Token':meta.body.data.csrf,'Idempotency-Key':key},data});return{status:r.status(),body:await r.json()};}
 async function login(ctx,email,password=pass){const r=await post(ctx,'auth.login',{email,password});assert.equal(r.status,200);return r;}
 const admin=await browser.newContext({viewport:{width:1440,height:1000},timezoneId:'America/Sao_Paulo',serviceWorkers:'block'});
 const page=await admin.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(base+prefix+'/index.php');assert.equal((await get(admin,'meta')).body.data.environment,'demo');await login(admin,'admin@demo.invalid',adminPass);
 const pupil=await browser.newContext({serviceWorkers:'block'});const email='danger-browser@local.invalid';
 const created=await post(pupil,'auth.register',{email,display_name:'Participante Danger',password:pass});assert.equal(created.status,200);const user=created.body.data.user_id;
 assert.equal((await post(admin,'admin.approve_without_email',{password:adminPass,user_id:user,confirm_without_email:true,reason:'Aluno local de teste de sanções'})).status,200);
 await login(pupil,email);
 assert.equal((await get(pupil,'admin.danger&user_id='+user)).status,403);
 assert.equal((await post(pupil,'admin.danger.fine',{password:pass,user_id:user,amount:'1.00',reason:'Não permitido',confirm:true})).status,403);
 const noCsrf=await admin.request.post(base+prefix+'/api.php?action=admin.danger.ban',{data:{user_id:user}});assert.equal(noCsrf.status(),403);
 assert.equal((await post(admin,'admin.danger.fine',{password:'errada',user_id:user,amount:'1.00',reason:'Senha inválida',confirm:true})).status,403);
 report.push('Autorização, API direta, CSRF e senha administrativa obrigatórios');
 await page.goto(base+prefix+'/index.php?view=admin');await page.waitForSelector('.admin-danger-zone');
 assert.equal(await page.locator('.admin-nft-panel').count(),1);assert.equal(await page.locator('.admin-danger-zone .admin-nft-panel').count(),1);
 const lookup=page.locator('[data-danger-form=lookup]');await lookup.locator('[name=user_id]').fill(user);await lookup.locator('button').click();await page.waitForSelector('#danger-participant [data-danger-action=fine]');
 for(const width of [320,360,390,412,1440]){await page.setViewportSize({width,height:950});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),width);await page.locator('#admin-danger-zone').screenshot({path:path.join(out,`danger-${width}.png`)});}
 report.push('Danger Zone e troca de imagem únicas, sem overflow em cinco larguras');
 await page.locator('[data-danger-action=fine]').click();let form=page.locator('[data-danger-form=mutate]');await form.locator('[name=amount]').fill('25.50');await form.locator('[name=reason]').fill('Multa didática local');await form.locator('[name=confirm]').check();await form.locator('[name=password]').fill(adminPass);await form.locator('button.primary').click();await page.waitForFunction(()=>!document.querySelector('#dialog').open);
 let d=(await get(admin,'admin.danger&user_id='+user)).body.data;assert.equal(d.balances.MR.available,'157450');
 report.push('Multa pela interface com prévia, confirmação e débito correto');
 await page.locator('[data-danger-action=ban]').click();form=page.locator('[data-danger-form=mutate]');await form.locator('[name=reason]').fill('Suspensão didática local');await form.locator('[name=confirm]').check();await form.locator('[name=password]').fill(adminPass);await form.locator('button.primary').click();await page.waitForFunction(()=>!document.querySelector('#dialog').open);
 assert.equal((await get(pupil,'dashboard')).status,401);assert.equal((await post(pupil,'auth.login',{email,password:pass})).status,403);
 d=(await get(admin,'admin.danger&user_id='+user)).body.data;assert(d.u.banned_until);assert.equal(d.balances.MR.available,'157450');
 await page.locator('[data-danger-action=unban]').click();form=page.locator('[data-danger-form=mutate]');await form.locator('[name=reason]').fill('Fim antecipado didático');await form.locator('[name=confirm]').check();await form.locator('[name=password]').fill(adminPass);await form.locator('button.primary').click();await page.waitForFunction(()=>!document.querySelector('#dialog').open);await login(pupil,email);
 report.push('Banimento encerra sessão aberta e impede login; desbloqueio exige confirmação');
 const newPass='nova-credencial-admin-teste';await page.locator('[data-danger-action=password]').click();form=page.locator('[data-danger-form=mutate]');await form.locator('[name=new_password]').fill(newPass);await form.locator('[name=new_password_confirmation]').fill(newPass);await form.locator('[name=reason]').fill('Recuperação presencial local');await form.locator('[name=confirm]').check();await form.locator('[name=password]').fill(adminPass);await form.locator('button.primary').click();await page.waitForFunction(()=>!document.querySelector('#dialog').open);
 assert.equal((await get(pupil,'dashboard')).status,401);assert.equal((await post(pupil,'auth.login',{email,password:pass})).status,401);await login(pupil,email,newPass);
 const audit=(await get(admin,'admin.danger&user_id='+user)).body.data.audit;assert(!JSON.stringify(audit).includes(newPass));assert(!JSON.stringify(audit).includes(adminPass));
 report.push('Redefinição administrativa sem e-mail revoga senha/sessão e não vaza credenciais');
 // Lost response: server commits; browser receives an aborted response and must retrieve a receipt.
 let intercepted=false;await page.route('**/api.php?action=admin.danger.fine',async route=>{if(intercepted)return route.continue();intercepted=true;await route.fetch();await route.abort('failed');});
 await page.locator('[data-danger-action=fine]').click();form=page.locator('[data-danger-form=mutate]');await form.locator('[name=amount]').fill('1.00');await form.locator('[name=reason]').fill('Recibo após falha de rede');await form.locator('[name=confirm]').check();await form.locator('[name=password]').fill(adminPass);await form.locator('button.primary').click();await page.waitForSelector('[data-danger-recover]');assert.equal(await form.locator('[name=password]').inputValue(),'');await form.locator('[data-danger-recover]').click();await page.waitForFunction(()=>!document.querySelector('#dialog').open);await page.unroute('**/api.php?action=admin.danger.fine');assert.equal((await get(admin,'admin.danger&user_id='+user)).body.data.balances.MR.available,'157350');
 report.push('Resposta perdida recupera recibo sem repetir multa ou reter senha');
 // Read generated links only from the disposable demo outbox through a CLI fixture helper.
 async function exportToken(){const {execFileSync}=require('node:child_process');const env={...process.env};for(const k of ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'])delete env[k];const output=execFileSync('docker',['--host=unix:///var/run/docker.sock','exec',process.env.TEST_PHP_CONTAINER||'piscina-dz-web','php','/app/tests/danger-fixture.php','export-reset',email],{env,stdio:'pipe'});return JSON.parse(output.toString());}
 await post(pupil,'auth.request_reset',{email});let fixture=await exportToken();
 const recover=await browser.newContext({serviceWorkers:'block'});const recoverPage=await recover.newPage();recoverPage.on('pageerror',e=>errors.push(e.message));await recoverPage.goto(base+prefix+'/index.php');await recoverPage.waitForFunction(()=>document.querySelector('#account-name').textContent==='Entrar');
 await recoverPage.evaluate(link=>{location.href=link},base+prefix+'/index.php#reset='+fixture.token);await recoverPage.waitForSelector('form[data-form="auth.reset"]');
 // Merely opening does not consume token; CLI export includes its current state.
 assert.equal((await exportToken()).used_at,null);
 const resetPass='nova-credencial-email-teste';await recoverPage.locator('form[data-form="auth.reset"] [name=password]').fill(resetPass);await recoverPage.locator('form[data-form="auth.reset"] button').click();await recoverPage.waitForFunction(()=>!document.querySelector('#dialog').open);
 await login(recover,email,resetPass);assert.equal((await post(pupil,'auth.login',{email,password:newPass})).status,401);
 assert.equal((await post(recover,'auth.reset',{token:fixture.token,password:'senha-nao-pode-reutilizar'})).status,422);
 assert.equal((await post(recover,'auth.reset',{token:'invalido',password:resetPass})).status,422);
 await post(recover,'auth.request_reset',{email});fixture=await exportToken();const cold=await browser.newContext({serviceWorkers:'block'});const cp=await cold.newPage();
 const redirect=require('node:http').createServer((req,res)=>{res.writeHead(302,{Location:base+prefix+'/index.php'});res.end();});
 await new Promise(resolve=>redirect.listen(0,'127.0.0.1',resolve));
 try{await cp.goto('http://127.0.0.1:'+redirect.address().port+'/index.php#reset='+fixture.token);await cp.waitForSelector('form[data-form="auth.reset"]');assert(cp.url().startsWith(base+prefix+'/'));}finally{await new Promise(resolve=>redirect.close(resolve));}
 // Expire in disposable DB; caller invokes fixed helper, not a public endpoint.
 {const{execFileSync}=require('node:child_process');const env={...process.env};for(const k of ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'])delete env[k];execFileSync('docker',['--host=unix:///var/run/docker.sock','exec',process.env.TEST_PHP_CONTAINER||'piscina-dz-web','php','/app/tests/danger-fixture.php','expire-reset',email],{env,stdio:'pipe'});}
 const coldmeta=await get(cold,'meta');assert.equal((await post(cold,'auth.reset',{token:fixture.token,password:resetPass})).status,422);
 report.push('Recuperação pública em página aberta e navegador anônimo; GET não consome; tokens inválidos, usados e expirados recusados');
 await page.locator('[data-danger-action=delete]').click();form=page.locator('[data-danger-form=mutate]');await form.locator('[name=confirmation]').fill('EXCLUIR '+user);await form.locator('[name=reason]').fill('Encerramento didático de teste');await form.locator('[name=confirm]').check();await form.locator('[name=password]').fill(adminPass);await form.locator('button.primary').click();await page.waitForFunction(()=>!document.querySelector('#dialog').open);
 d=(await get(admin,'admin.danger&user_id='+user)).body.data;assert(d.u.deleted_at);assert.equal(d.custody.find(a=>a.asset==='MR').balance,'157350');assert.equal(d.nfts.length,1);assert.equal(d.balances.MR.available,'0');assert.equal((await get(pupil,'nft&id='+d.nfts[0].nft_id)).status,404);assert.equal((await get(recover,'dashboard')).status,401);assert.equal((await post(recover,'auth.login',{email,password:resetPass})).status,403);
 assert.equal((await post(admin,'admin.approve_without_email',{password:adminPass,user_id:user,confirm_without_email:true,reason:'Não pode reaprovar excluído'})).status,403);
 assert.equal(await page.locator('#danger-participant [data-danger-action=delete]').count(),0);
 const guest=await browser.newContext();assert.equal((await guest.request.get(base+prefix+'/image.php?id='+d.nfts[0].nft_id)).status(),404);
 report.push('Exclusão definitiva move ativos à custódia privada, bloqueia reaprovação e preserva origem');
 assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'resultado.json'),JSON.stringify({passed:report,errors},null,2));console.log(report.join('\n'));await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
