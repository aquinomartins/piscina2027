<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Use somente piscina_test em demo. Esta suíte limpa suas fixtures e todos os contadores desse banco de teste.');
(new Piscina\Installer(db(),config()))->migrate();
$passed=0;$failed=0;
function equal(mixed $want,mixed $got): void {if((string)$want!==(string)$got)throw new RuntimeException('Esperado '.var_export($want,true).'; obtido '.var_export($got,true));}
function must(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function problem(callable $fn,int $status): void {try{$fn();}catch(Piscina\Problem $e){equal($status,$e->status);return;}throw new RuntimeException('Esperava HTTP '.$status);}
function auth(array $limits=[]): Piscina\Auth {return new Piscina\Auth(db(),array_replace(config(),$limits?['registration_limits'=>$limits]:[]));}
function data(int $n): array {return ['email'=>"turma-test-$n@local.invalid",'display_name'=>"Participante $n",'password'=>'senha-apenas-testes-locais'];}
function bucket(string $kind,string $identity): ?array {return db()->row('SELECT * FROM rate_limits WHERE bucket=?',[hash('sha256',$kind.':'.$identity)]);}
function users(): string {return db()->row("SELECT COUNT(*) n FROM users WHERE email LIKE 'turma-test-%@local.invalid'")['n'];}
function clean(): void {
    db()->run("DELETE FROM outbox WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'turma-test-%@local.invalid')");
    db()->run("DELETE FROM auth_tokens WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'turma-test-%@local.invalid')");
    db()->run("DELETE FROM users WHERE email LIKE 'turma-test-%@local.invalid'");
    db()->run('DELETE FROM rate_limits');
    $_SERVER['REMOTE_ADDR']='203.0.113.25';unset($_SERVER['HTTP_X_FORWARDED_FOR']);
}
function concurrent(array $calls): array {
    $dir=config()['storage'].'/tmp';if(!is_dir($dir))mkdir($dir,0700,true);
    $barrier=$dir.'/'.bin2hex(random_bytes(12)).'.go';$children=[];$paths=[];
    try {
        foreach($calls as $call) {
            $path=$dir.'/'.bin2hex(random_bytes(12)).'.json';$paths[]=$path;$call['barrier']=$barrier;file_put_contents($path,json_encode($call,JSON_THROW_ON_ERROR));
            $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/auth-limit-worker.php',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($proc))throw new RuntimeException('Não iniciou worker.');
            fclose($pipes[0]);$children[]=[$proc,$pipes,$path];
        }
        $deadline=microtime(true)+20;
        foreach($children as [,, $path])while(!is_file($path.'.ready')){if(microtime(true)>$deadline)throw new RuntimeException('Worker não alcançou barreira.');usleep(1000);}
        touch($barrier);$results=[];
        foreach($children as [$proc,$pipes,$path]) {
            $raw=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
            if($exit!==0||$error!==''||$raw==='')throw new RuntimeException('Worker falhou: '.$error);
            $results[]=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        }
        $children=[];
        return $results;
    } finally {
        foreach($children as [$proc,$pipes,$path])if(is_resource($proc)){proc_terminate($proc);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($proc);}
        foreach($paths as $path){if(is_file($path))unlink($path);if(is_file($path.'.ready'))unlink($path.'.ready');}
        if(is_file($barrier))unlink($barrier);
        // Other suites may use the same tmp directory, so remove no unrelated files.
    }
}
function statuses(array $results,int $code): int {return count(array_filter($results,fn($r)=>$r['status']===$code));}
function test(string $name,callable $fn): void {global $passed,$failed;try{clean();$fn();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}

test('Janela fixa: dez tentativas; rejeição persistida sem ampliar validade',function(){
    $a=auth();$a->limit('login','scope');$expires=bucket('login','scope')['expires_at'];
    for($i=1;$i<10;$i++)$a->limit('login','scope');
    problem(fn()=>$a->limit('login','scope'),429);equal(11,bucket('login','scope')['attempts']);equal($expires,bucket('login','scope')['expires_at']);
});
test('Contador saturado não estoura INT nem prolonga bloqueio',function(){
    db()->run('INSERT INTO rate_limits VALUES(?,2147483647,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))',[hash('sha256','test:saturated')]);
    for($i=0;$i<20;$i++)problem(fn()=>auth()->limit('test','saturated',3),429);
    equal(4,bucket('test','saturated')['attempts']);
});
foreach(['new','existing','expired'] as $state)test("32 chamadas na mesma chave ($state): contagem exata e sem deadlock",function()use($state){
    if($state!=='new')db()->run('INSERT INTO rate_limits VALUES(?,0,'.($state==='expired'?'DATE_SUB':'DATE_ADD').'(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))',[hash('sha256','test:hot')]);
    $results=concurrent(array_fill(0,32,['action'=>'limit','kind'=>'test','identity'=>'hot','max'=>17]));
    equal(17,statuses($results,200));equal(15,statuses($results,429));equal(18,bucket('test','hot')['attempts']);
    must(bucket('test','hot')['expires_at']>db()->now(),'Nova janela precisa ser futura.');
});
test('Expiração reinicia em um; igualdade com horário atual também expira',function(){
    $a=auth();$a->limit('test','expired',1);problem(fn()=>$a->limit('test','expired',1),429);
    db()->run('UPDATE rate_limits SET expires_at=UTC_TIMESTAMP(6)');$a->limit('test','expired',1);equal(1,bucket('test','expired')['attempts']);
});
test('Limites independentes por tipo e identidade',function(){
    auth()->limit('login','a',1);problem(fn()=>auth()->limit('login','a',1),429);auth()->limit('login','b',1);auth()->limit('token','a',1);
});
test('25 cadastros simultâneos na mesma rede criam uma conta, token e outbox por aluno',function(){
    $calls=[];for($i=0;$i<25;$i++)$calls[]=['action'=>'register','data'=>data($i)];
    $results=concurrent($calls);equal(25,statuses($results,200));equal(25,users());
    equal(25,db()->row("SELECT COUNT(*) n FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE u.email LIKE 'turma-test-%@local.invalid'")['n']);
    equal(25,db()->row("SELECT COUNT(*) n FROM outbox o JOIN users u ON u.id=o.user_id WHERE u.email LIKE 'turma-test-%@local.invalid' AND o.state='PENDING'")['n']);
    equal(0,db()->row("SELECT COUNT(*) n FROM users WHERE email LIKE 'turma-test-%@local.invalid' AND (status<>'pending' OR email_verified_at IS NOT NULL)")['n']);
    equal(25,bucket('register','203.0.113.25')['attempts']);equal(25,bucket('register.global','all')['attempts']);
});
test('Limite padrão da rede permite 50 e bloqueia a tentativa 51',function(){
    for($i=0;$i<50;$i++)auth()->limit('register','203.0.113.25',50);
    $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.99';problem(fn()=>auth()->register(data(51)),429);
    equal(0,users());equal(null,bucket('register.email',data(51)['email']));equal(null,bucket('register.global','all'));
});
test('Mesmo e-mail normalizado tem três tentativas, sem repetir token ou consumir global após bloqueio',function(){
    auth()->register(data(1));$input=data(1);$input['email']='  TURMA-TEST-1@LOCAL.INVALID  ';
    for($i=0;$i<2;$i++)problem(fn()=>auth()->register($input),409);
    $_SERVER['REMOTE_ADDR']='198.51.100.2';problem(fn()=>auth()->register($input),429);
    equal(1,users());equal(3,bucket('register.global','all')['attempts']);equal(4,bucket('register.email',data(1)['email'])['attempts']);
    equal(1,db()->row("SELECT COUNT(*) n FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE u.email=?",[data(1)['email']])['n']);
});
test('12 IPs simultâneos respeitam teto global configurado de seis',function(){
    $calls=[];for($i=0;$i<12;$i++)$calls[]=['action'=>'register','data'=>data($i),'ip'=>'198.51.100.'.($i+1),'config'=>['registration_limits'=>['global'=>6]]];
    $results=concurrent($calls);equal(6,statuses($results,200));equal(6,statuses($results,429));equal(6,users());equal(7,bucket('register.global','all')['attempts']);
});
test('Teto global padrão continua bloqueando apesar de troca de IP e e-mail',function(){
    for($i=0;$i<100;$i++)auth()->limit('register.global','all',100);
    $_SERVER['REMOTE_ADDR']='198.51.100.200';problem(fn()=>auth()->register(data(1)),429);equal(0,users());
});
test('Contadores legados preservam janela e não exigem limpeza ou migration',function(){
    db()->run('INSERT INTO rate_limits VALUES(?,6,DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE))',[hash('sha256','register:203.0.113.25')]);
    $before=bucket('register','203.0.113.25')['expires_at'];auth()->register(data(1));equal(7,bucket('register','203.0.113.25')['attempts']);equal($before,bucket('register','203.0.113.25')['expires_at']);
});
test('Configuração parcial mantém outros padrões e rejeitados por IP não gastam global',function(){
    $a=auth(['per_ip'=>1]);$a->register(data(1));problem(fn()=>$a->register(data(2)),429);equal(1,bucket('register.global','all')['attempts']);equal(null,bucket('register.email',data(2)['email']));
});
test('Duas inscrições do mesmo e-mail em paralelo: uma conta, um token e conflito 409',function(){
    $results=concurrent(array_fill(0,2,['action'=>'register','data'=>data(1)]));equal(1,statuses($results,200));equal(1,statuses($results,409));equal(1,users());
});
test('Formulários inválidos não geram conta, token ou contador de cadastro',function(){
    $bad=data(1);$bad['password']='curta';problem(fn()=>auth()->register($bad),422);$bad=data(1);$bad['email']='inválido';problem(fn()=>auth()->register($bad),422);equal(0,users());equal(0,db()->row('SELECT COUNT(*) n FROM rate_limits')['n']);
});
test('Reenvio de token mantém quatro tentativas por origem e e-mail',function(){
    for($i=0;$i<4;$i++)auth()->requestToken(['email'=>'ausente@local.invalid'],'verify');problem(fn()=>auth()->requestToken(['email'=>'ausente@local.invalid'],'verify'),429);equal(0,users());
});
test('Confirmação de senha mantém dez tentativas por usuário',function(){
    $a=auth();$user=(int)$a->register(data(1))['user_id'];
    for($i=0;$i<10;$i++)problem(fn()=>$a->reauthenticate($user,'senha-incorreta'),403);
    problem(fn()=>$a->reauthenticate($user,data(1)['password']),429);
    equal(11,bucket('reauth',(string)$user)['attempts']);
});
test('Configuração insegura é recusada antes de criar contadores',function(){
    foreach([['per_ip'=>0],['per_email'=>-1],['global'=>1001],['per_ip'=>'50'],['per_email'=>11]] as $limits) {
        try{auth($limits)->register(data(1));throw new LogicException('Configuração inválida aceita.');}catch(RuntimeException $e){must(str_contains($e->getMessage(),'Limite de cadastro inválido'),'Erro esperado de configuração.');}
    }
    equal(0,db()->row('SELECT COUNT(*) n FROM rate_limits')['n']);
});
echo "$passed casos aprovados; $failed falhas.\n";
exit($failed?1:0);
