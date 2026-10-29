// Local integration test. Never run against production.
const http=require('node:http');
const fs=require('node:fs');
const assert=require('node:assert/strict');
const {performance}=require('node:perf_hooks');
const base=process.env.TEST_URL||'http://127.0.0.1:8131';
const origin=new URL(base);
if(origin.protocol!=='http:'||origin.hostname!=='127.0.0.1')throw Error('Somente servidor local isolado.');
const agent=new http.Agent({keepAlive:true,maxSockets:100});
const password='senha-apenas-testes-http';
const report=[];
function request(action,data,session={},extra={}) {
    return new Promise((resolve,reject)=>{
        const start=performance.now();const body=data===undefined?null:JSON.stringify(data);
        const headers={...extra};if(session.cookie)headers.Cookie=session.cookie;if(session.csrf)headers['X-CSRF-Token']=session.csrf;
        if(body){headers['Content-Type']='application/json';headers['Content-Length']=Buffer.byteLength(body);}
        const req=http.request(new URL('/api.php?action='+action,base),{agent,method:body?'POST':'GET',headers},res=>{
            let raw='';res.on('data',c=>raw+=c);res.on('end',()=>{
                try {const json=JSON.parse(raw);resolve({status:res.statusCode,data:json.data,error:json.error,ms:performance.now()-start,cookie:res.headers['set-cookie']?.[0]?.split(';')[0]||session.cookie,csrf:json.data?.csrf||session.csrf});}
                catch(e){reject(e);}
            });
        });
        req.setTimeout(30000,()=>req.destroy(Error('Tempo de teste excedido.')));req.on('error',reject);if(body)req.write(body);req.end();
    });
}
async function session(){const r=await request('meta');assert.equal(r.status,200);assert.equal(r.data.environment,'demo');assert(r.csrf&&r.cookie);return r;}
const registration=i=>({email:`turma-http-${i}@local.invalid`,display_name:`Aluno HTTP ${i}`,password});
(async()=>{
    const anon=await session();assert.equal((await request('auth.register',registration(999),{cookie:anon.cookie})).status,403);
    assert.equal((await request('dashboard',undefined,anon)).status,401);
    report.push('CSRF obrigatório e carteira privada antes de autenticar');
    const sessions=[];for(let i=0;i<50;i++)sessions.push(await session());
    const durations=[];
    for(let start=0;start<50;start+=25) {
        const results=await Promise.all(sessions.slice(start,start+25).map((s,i)=>request('auth.register',registration(start+i),s)));
        assert.deepEqual(results.map(r=>r.status),Array(25).fill(200));durations.push(...results.map(r=>r.ms));
    }
    report.push('Duas rajadas de 25 cadastros no mesmo IP: 50 respostas HTTP 200');
    for(let i=0;i<5;i++) {
        const blocked=await request('auth.register',registration(100+i),anon,{'X-Forwarded-For':`198.51.100.${i+1}`});
        assert.equal(blocked.status,429);assert.equal(blocked.error,'rate_limit');
    }
    report.push('Tentativas 51–55 bloqueadas; X-Forwarded-For não contorna a cota da rede');
    const initial=await session();const login=await request('auth.login',{email:registration(0).email,password},initial);
    assert.equal(login.status,200);assert.equal(login.data.user.status,'pending');assert.notEqual(login.csrf,initial.csrf);
    const dashboard=await request('dashboard',undefined,login);assert.equal(dashboard.status,200);assert.equal(dashboard.data.user.email_verified_at,null);
    assert.equal(dashboard.data.balances.MR.available,'0');assert.equal(dashboard.data.balances.BYC.available,'0');assert.equal(dashboard.data.balances.SHARE.available,'0');
    assert.equal((await request('nft.deposit',{nft_id:'1'},login,{'Idempotency-Key':require('node:crypto').randomUUID()})).status,403);
    report.push('Login continua disponível após cota de cadastro; conta pendente sem verificação, créditos ou permissão de jogar');
    assert.equal((await request('auth.logout',{}, {...login,csrf:initial.csrf})).status,403);
    const logout=await request('auth.logout',{},login);assert.equal(logout.status,200);assert.equal((await request('dashboard',undefined,logout)).status,401);
    report.push('Rotação de CSRF, logout e proteção de sessão preservados');
    const attempts=await session();
    for(let i=0;i<10;i++)assert.equal((await request('auth.login',{email:registration(1).email,password:'senha-incorreta'},attempts)).status,401);
    assert.equal((await request('auth.login',{email:registration(1).email,password},attempts)).status,429);
    assert.equal((await request('auth.login',{email:registration(2).email,password},await session())).status,200);
    report.push('Login mantém dez tentativas por IP + e-mail, sem bloquear outra conta da turma');
    for(let i=0;i<4;i++)assert.equal((await request('auth.request_reset',{email:'ausente-http@local.invalid'},attempts)).status,200);
    assert.equal((await request('auth.request_reset',{email:'ausente-http@local.invalid'},attempts)).status,429);
    report.push('Recuperação mantém quatro tentativas por origem + e-mail');
    durations.sort((a,b)=>a-b);
    const output={passed:report,registrations:50,p95_registration_ms:durations[Math.ceil(durations.length*.95)-1],max_registration_ms:durations.at(-1),scope:'API local; sem SMTP ou teste na produção'};
    if(process.env.TEST_OUTPUT)fs.writeFileSync(process.env.TEST_OUTPUT,JSON.stringify(output,null,2)+'\n');
    console.log(JSON.stringify(output,null,2));agent.destroy();
})().catch(e=>{console.error(e);agent.destroy();process.exit(1);});
