<?php
declare(strict_types=1);
require __DIR__.'/payment-test-fixtures.php';
test('Pagamentos teste: flag desativada e política diferente impedem envio e emissão',function(){
    [$p,$h]=ptfixture(['enabled'=>false],false);fail(fn()=>$p->create(1,ptdata(),testKey()));assertSame(0,$h->creates);assertSame(0,ptcreditCount());
    service()->config['payments_test']['enabled']=true;db()->run('UPDATE decision_versions SET policy=?',[json_encode(['assets'=>'external','payment'=>'BRL'])]);fail(fn()=>$p->create(1,ptdata(),testKey()));
});
test('Pagamentos teste: OAuth comprova live_mode falso e coletor antes de enviar',function(){
    foreach([['live_mode'=>true],['live_mode'=>'false'],['live_mode'=>null],['user_id'=>'999'],['public_key'=>'']] as $bad){[$p,$h]=ptfixture(['credential_source'=>'oauth'],false);$h->credential=array_replace($h->credential,$bad);fail(fn()=>$p->create(1,ptdata(),testKey()));assertSame(0,$h->creates);assertSame(0,ptcreditCount());}
});
test('Pagamentos teste: conta autenticada prepara cartão sem declarar ambiente nem liberar Pix',function(){
    [$p,$h,$provider]=ptfixture([],false);$b=$p->bootstrap(1);check($b['enabled']&&$b['methods']['credit_card'],'Preparação do componente liberada.');check($b['environment_verified']===false&&!$b['methods']['pix'],'Pix bloqueado até prova.');check(!array_key_exists('live_mode',$provider->credentials()),'Autenticação não fabrica live_mode=false.');
    fail(fn()=>$p->create(1,ptdata('pix'),testKey()));fail(fn()=>$provider->call('POST','/v1/payments',[]));fail(fn()=>$provider->call('PUT','/v1/payments/123',['status'=>'cancelled']));assertSame(0,$h->creates);assertSame(0,db()->row('SELECT COUNT(*) n FROM payment_test_intents')['n']);assertSame(0,ptcreditCount());
});
test('Pagamentos teste: somente Pix disponível não contorna a comprovação inicial do ambiente',function(){
    [$p,$h]=ptfixture([],false);$h->methodRows=[['id'=>'pix','payment_type_id'=>'bank_transfer','status'=>'active']];$b=$p->bootstrap(1);check(!$b['enabled']&&!isset($b['public_key']),'Sem cartão ou prova anterior, a área permanece bloqueada.');fail(fn()=>$p->create(1,ptdata('pix'),testKey()));assertSame(0,$h->creates);assertSame(0,ptcreditCount());
});
test('Pagamentos teste: Public Key e Access Token preparam SDK sem Client Secret ou cadastro técnico',function(){
    [$p,$h]=ptfixture(['client_secret'=>''],false);$b=$p->bootstrap(1);check($b['enabled'],'Credenciais diretas autenticadas.');assertSame($h->credential['public_key'],$b['public_key']);check(!isset($b['access_token']),'Segredo não vai ao navegador.');assertSame(0,$h->oauth);assertSame(1,$h->userQueries);assertSame(0,$h->cardQueries);assertSame(0,$h->creates);
    $cfg=service()->config;unset($cfg['payments_test']['credential_source'],$cfg['payments_test']['client_secret']);$automatic=new Piscina\PaymentTests(service(),new Piscina\MercadoPagoSandbox($cfg,$h));check($automatic->bootstrap(1)['enabled'],'Par de chaves seleciona caminho direto.');assertSame(0,$h->oauth);
});
test('Pagamentos teste: chaves de produção, campos incompletos e pares de outra aplicação falham antes da API',function(){
    foreach([['public_key'=>'APP_USR-123456789012345678901234567890'],['access_token'=>'APP_USR-123456789012345678901234567890'],['access_token'=>'TEST-12345-111712-00000000000000000000000000000000-23456'],['public_key'=>''],['access_token'=>''],['access_token'=>[]],['client_id'=>'99999'],['collector_id'=>'99999'],['client_id'=>12345],['collector_id'=>23456],['mode'=>'production'],['credential_source'=>'invalid'],['credential_source'=>[]]] as $bad){
        [$p,$h]=ptfixture($bad,false);$b=$p->bootstrap(1);check(!$b['enabled'],'Configuração inconsistente bloqueada.');check(!isset($b['public_key']),'SDK não liberado.');assertSame(0,$h->userQueries);assertSame(0,$h->creates);assertSame(0,$h->oauth);
    }
    [$p,$h]=ptfixture(['max_amount'=>'inválido'],false);check(!$p->bootstrap(1)['enabled'],'Limite inválido bloqueado.');assertSame(0,$h->userQueries);
});
test('Pagamentos teste: conta autenticada e país correspondem antes de preparar o componente',function(){
    foreach([['id'=>'99999'],['id'=>null],['id'=>true],['id'=>[]],['site_id'=>'MLA'],['site_id'=>null]] as $bad){[$p,$h]=ptfixture([],false);$h->userOverride=$bad;check(!$p->bootstrap(1)['enabled'],'Conta divergente bloqueada.');assertSame(0,$h->creates);}
    [$p,$h]=ptfixture([],false);$h->rejectIdentity=true;check(!$p->bootstrap(1)['enabled'],'Falha de identidade bloqueada.');assertSame(0,$h->creates);
});
test('Pagamentos teste: token de cartão exige live_mode false ativo válido e cartão oficial antes do POST',function(){
    foreach([['live_mode'=>true],['live_mode'=>'false'],['live_mode'=>null],['status'=>'used'],['date_due'=>'2000-01-01T00:00:00Z'],['date_due'=>null],['date_due'=>'inválida'],['id'=>'outro-token'],['public_key'=>'OTHER_PUBLIC_KEY_INVALID'],['first_six_digits'=>'411111'],['last_four_digits'=>'0000']] as $bad){
        [$p,$h,$provider]=ptfixture([],false);$h->cardOverride=$bad;fail(fn()=>$p->create(1,ptdata(),testKey()));check(!$provider->environmentVerified(),'Token inválido não atesta ambiente.');assertSame(0,$h->creates);assertSame(0,ptcreditCount());assertSame(0,db()->row('SELECT COUNT(*) n FROM payment_test_intents')['n']);
    }
});
test('Pagamentos teste: metadados documentados do SDK atestam token sem campo public_key opcional',function(){
    [$p,$h,$provider]=ptfixture([],false);$h->omitPublicKey=true;$r=$p->create(1,ptdata(),testKey());assertSame('APPROVED',$r['state']);check($provider->environmentVerified(),'Consulta autenticada confirmou token de teste.');assertSame(1,ptcreditCount());check($p->bootstrap(1)['methods']['pix'],'Pix liberado após comprovação.');
});
test('Pagamentos teste: cache de prova não guarda token ou cartão e expiração bloqueia Pix',function(){
    [$p,$h]=ptfixture();$cfg=service()->config;$file=$cfg['storage'].'/mercadopago-test-environment.json';$raw=file_get_contents($file);$cache=json_decode($raw,true,32,JSON_THROW_ON_ERROR);foreach([$h->credential['access_token'],$h->credential['public_key'],'fixture_credit_card_'] as $secret)check(!str_contains($raw,$secret),'Cache não armazena dados do cartão ou chaves.');check(!array_intersect(array_keys($cache),['token','card_id','first_six_digits','last_four_digits','cardholder','payer']),'Cache não tem campos de cartão ou pagador.');assertSame('card_token',$cache['proof_source']);clearstatcache(true,$file);assertSame(0600,fileperms($file)&0777);
    check($p->bootstrap(1)['methods']['pix'],'Pix liberado com prova recente.');$cache['expires_at']=time()-1;file_put_contents($file,json_encode($cache));$other=new Piscina\PaymentTests(service(),new Piscina\MercadoPagoSandbox($cfg,$h));$b=$other->bootstrap(1);check($b['enabled']&&!$b['environment_verified']&&!$b['methods']['pix'],'Expiração exige nova prova.');fail(fn()=>$other->create(1,ptdata('pix'),testKey()));assertSame(0,$h->creates);assertSame(0,ptcreditCount());
});
test('Pagamentos teste: rotação de chave invalida atestado e token da chave anterior é recusado',function(){
    [$p,$h]=ptfixture();service()->config['payments_test']['public_key']='TEST-87654321-4321-1234-9876-abcdef123456';$other=new Piscina\PaymentTests(service(),new Piscina\MercadoPagoSandbox(service()->config,$h));$b=$other->bootstrap(1);check(!$b['environment_verified']&&!$b['methods']['pix'],'Outra chave exige comprovação.');fail(fn()=>$other->create(1,ptdata(),testKey()));assertSame(0,$h->creates);
});
test('Pagamentos teste: consulta de pagamento de teste renova atestado sem novo envio ou crédito',function(){
    [$p,$h]=ptfixture();$h->nextStatus='pending';$r=$p->create(1,ptdata('pix'),testKey());$cfg=service()->config;$file=$cfg['storage'].'/mercadopago-test-environment.json';$cache=json_decode(file_get_contents($file),true);$cache['expires_at']=time()-1;file_put_contents($file,json_encode($cache));
    $other=new Piscina\PaymentTests(service(),new Piscina\MercadoPagoSandbox($cfg,$h));check(!$other->bootstrap(1)['environment_verified'],'Prova expirada.');assertSame('PENDING',$other->refresh(1,$r['id'])['state']);check($other->bootstrap(1)['methods']['pix'],'Pagamento próprio live_mode=false atesta o ambiente.');assertSame(1,$h->creates);assertSame(0,ptcreditCount());
});
test('Pagamentos teste: pagamento de produção não atesta ambiente nem gera crédito',function(){
    [$p,$h]=ptfixture();$h->nextStatus='pending';$r=$p->create(1,ptdata('pix'),testKey());$cfg=service()->config;$file=$cfg['storage'].'/mercadopago-test-environment.json';$cache=json_decode(file_get_contents($file),true);$cache['expires_at']=time()-1;file_put_contents($file,json_encode($cache));$h->payments[$r['provider_id']]['live_mode']=true;
    $other=new Piscina\PaymentTests(service(),new Piscina\MercadoPagoSandbox($cfg,$h));fail(fn()=>$other->refresh(1,$r['id']));check(!$other->bootstrap(1)['environment_verified'],'Produção não é prova de teste.');assertSame(0,ptcreditCount());
});
test('Pagamentos teste: e-mails de contas testuser são impedidos antes do envio',function(){
    [$p,$h]=ptfixture();$data=ptdata();$data['card']['payer']['email']='test_user_123@testuser.com';fail(fn()=>$p->create(1,$data,testKey()),422);assertSame(0,$h->creates);
    [$p,$h]=ptfixture(['payer_email'=>'test_payer_1@testuser.com']);fail(fn()=>$p->create(1,ptdata('pix'),testKey()),422);assertSame(0,$h->creates);
});
test('Pagamentos teste: configuração OAuth anterior preserva fingerprint e prova antes de pagar',function(){
    [$p,$h,$provider]=ptfixture(['credential_source'=>'oauth'],false);$cfg=service()->config;$c=$cfg['payments_test'];$expected=hash('sha256',Piscina\Domain::canonical(['client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'collector_id'=>$c['collector_id'],'mode'=>$c['mode']]));assertSame($expected,$provider->fingerprint());
    unset($cfg['payments_test']['credential_source'],$cfg['payments_test']['public_key'],$cfg['payments_test']['access_token']);$legacy=new Piscina\MercadoPagoSandbox($cfg,$h);assertSame($expected,$legacy->fingerprint());check($legacy->environmentVerified(),'OAuth mantém atestado do provedor.');assertSame(1,$h->oauth);assertSame(0,$h->userQueries);
    $old=new Piscina\PaymentTests(service(),$legacy);assertSame('APPROVED',$old->create(1,ptdata('pix'),testKey())['state']);assertSame(1,ptcreditCount());
});
test('Pagamentos teste: nove combinações de meio e destino creditam uma vez no livro canônico',function(){
    [$p,$h]=ptfixture();op(1,'admin.approve',['user_id'=>'1']);$before=balance(1,'MR');
    foreach(['credit_card','debit_card','pix'] as $method)foreach(['wallet','treasury','galeria_revenue'] as $dest){$r=$p->create(1,ptdata($method,$dest),testKey());assertSame('APPROVED',$r['state']);check($r['credit_transaction_id']!==null,'Lançamento deve existir.');check($r['experimental']===true,'Comprovante experimental.');}
    assertSame(9,ptcreditCount());assertSame('30003',bcsub(balance(1,'MR'),$before,0));foreach(['treasury','galeria_revenue'] as $dest)assertSame('30003',db()->row('SELECT balance FROM accounts WHERE code=?',['T:MR:'.$dest])['balance']);assertSame('-90009',db()->row("SELECT balance FROM accounts WHERE code='T:MR:issuance:mercadopago_test'")['balance']);assertSame(9,$h->queries);assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_sales')['n']);
});
test('Pagamentos teste: resposta de criação aprovada e consulta pendente não creditam',function(){
    [$p,$h]=ptfixture();$h->getStatus='pending';$r=$p->create(1,ptdata(),testKey());assertSame('PENDING',$r['state']);assertSame(null,$r['credit_transaction_id']);assertSame(0,ptcreditCount());$h->getStatus='approved';$p->refresh(1,$r['id']);assertSame(1,ptcreditCount());
});
test('Pagamentos teste: pendência, recusa e cancelamento não alteram saldo',function(){
    [$p,$h]=ptfixture();foreach(['pending','in_process','authorized','rejected','cancelled'] as $state){$h->nextStatus=$state;$r=$p->create(1,ptdata(),testKey());assertSame(null,$r['credit_transaction_id']);}assertSame(0,ptcreditCount());assertSame('0',balance(1,'MR'));
});
test('Pagamentos teste: cartões reais ou de outro ambiente são impedidos antes do POST',function(){
    foreach([['live_mode'=>true],['public_key'=>'other_public_key_aaaaaaaaa'],['first_six_digits'=>'411111'],['last_four_digits'=>'0000']] as $bad){[$p,$h]=ptfixture();$h->cardOverride=$bad;fail(fn()=>$p->create(1,ptdata(),testKey()));assertSame(0,$h->creates);}
});
test('Pagamentos teste: valor, moeda, ambiente, referência e destinatário são conferidos na consulta',function(){
    foreach([['transaction_amount'=>'100.02'],['currency_id'=>'USD'],['live_mode'=>true],['live_mode'=>null],['external_reference'=>'other'],['collector_id'=>'999'],['payment_type_id'=>'debit_card'],['payment_method_id'=>'visa'],['metadata'=>['scope'=>'other']],['captured'=>false],['transaction_amount_refunded'=>'1']] as $bad){[$p,$h]=ptfixture();$h->overrides=$bad;$r=$p->create(1,ptdata(),testKey());assertSame('BLOCKED',$r['state']);assertSame(null,$r['credit_transaction_id']);}assertSame(0,ptcreditCount());
});
test('Pagamentos teste: criação repetida e consulta repetida não duplicam saldo',function(){
    [$p,$h]=ptfixture();$k=testKey();$a=$p->create(1,ptdata(),$k);$b=$p->create(1,ptdata(),$k);assertSame($a['id'],$b['id']);$p->refresh(1,$a['id']);assertSame(1,$h->creates);assertSame(1,ptcreditCount());assertSame('10001',balance(1,'MR'));fail(fn()=>$p->create(1,ptdata(amount:'100.02'),$k));
});
test('Pagamentos teste: resposta perdida recupera pela referência sem reenviar pagamento',function(){
    [$p,$h]=ptfixture();$h->lostAfterPost=true;$key=testKey();$r=$p->create(1,ptdata(),$key);assertSame('UNKNOWN',$r['state']);assertSame(0,ptcreditCount());$found=$p->lookup(1,$key);assertSame($r['id'],$found['id']);$r=$p->refresh(1,$r['id']);assertSame('APPROVED',$r['state']);assertSame(1,$h->creates);assertSame(1,ptcreditCount());
});
test('Pagamentos teste: ausência de pagamento não vira aprovação local',function(){
    [$p,$h]=ptfixture();$h->lostBeforePost=true;$r=$p->create(1,ptdata('pix'),testKey());$r=$p->refresh(1,$r['id']);assertSame('UNKNOWN',$r['state']);assertSame(0,ptcreditCount());fail(fn()=>$p->cancel(1,$r['id']));
    [$p,$h]=ptfixture();$h->rejectPost=true;$r=$p->create(1,ptdata(),testKey());assertSame('FAILED',$r['state']);assertSame(null,$r['provider_id']);assertSame(0,ptcreditCount());
    [$p,$h]=ptfixture();$h->rejectGet=true;$r=$p->create(1,ptdata(),testKey());assertSame('UNKNOWN',$r['state']);check($r['provider_id']!==null,'Consulta falhou depois da criação.');assertSame(0,ptcreditCount());$h->rejectGet=false;$p->refresh(1,$r['id']);assertSame(1,ptcreditCount());
});
test('Pagamentos teste: recusa conserva diagnóstico seguro após leitura, consulta vazia e repetição',function(){
    [$p,$h]=ptfixture();$h->rejectPost=true;$private=['TEST-SEGREDO_LOCAL_NAO_PERSISTIR','privado@local.invalid','PAN_CVV_CPF_PRIVADOS'];
    $h->failureBody=['error'=>'bad_request','message'=>implode(' ',$private),'access_token'=>$private[0],'cause'=>[['code'=>3003,'description'=>implode(' ',$private)]]];
    $key=testKey();$data=ptdata(amount:'888.00');$r=$p->create(1,$data,$key);assertSame('FAILED',$r['state']);assertSame(null,$r['provider_status']);assertSame(null,$r['credit_transaction_id']);check(str_contains($r['provider_failure'],'envio do pagamento'),'Etapa da recusa preservada.');check(str_contains($r['provider_failure'],'HTTP: 400'),'HTTP preservado.');check(str_contains($r['provider_failure'],'Código: 3003'),'Causa específica preservada.');$diagnostic=$r['provider_failure'];
    assertSame($diagnostic,$p->read(1,$r['id'])['provider_failure']);$again=$p->create(1,$data,$key);assertSame($r['id'],$again['id']);assertSame(1,$h->creates);
    $after=$p->refresh(1,$r['id']);assertSame('FAILED',$after['state']);assertSame('test_provider_rejected',$after['error_code']);assertSame($diagnostic,$after['provider_failure']);assertSame(1,$h->creates);assertSame(0,ptcreditCount());
    foreach(['payment_test_intents','payment_test_events','operations','audit_events'] as $table){$raw=json_encode(db()->all('SELECT * FROM '.$table));foreach($private as $secret)check(!str_contains($raw,$secret),'Diagnóstico não retém corpo bruto ou segredos.');}
});
test('Pagamentos teste: consulta recusada não aprova por resposta de criação e diagnóstico some após crédito válido',function(){
    [$p,$h]=ptfixture();$h->rejectGet=true;$h->failureBody=['error'=>'forbidden'];$r=$p->create(1,ptdata(),testKey());assertSame('UNKNOWN',$r['state']);check($r['provider_id']!==null,'ID da criação preservado.');check(str_contains($r['provider_failure'],'consulta do pagamento'),'Recusa da consulta identificada.');assertSame(0,ptcreditCount());
    $h->rejectGet=false;$after=$p->refresh(1,$r['id']);assertSame('APPROVED',$after['state']);assertSame(null,$after['provider_failure']);assertSame('10001',balance(1,'MR'));$p->refresh(1,$r['id']);assertSame(1,ptcreditCount());assertSame(1,$h->creates);
});
test('Pagamentos teste: cancelar tentativa ainda não registrada bloqueia envio atrasado',function(){
    [$p,$h]=ptfixture();$key=testKey();$p->abandon(1,$key);fail(fn()=>$p->create(1,ptdata(),$key));assertSame(0,$h->creates);
    $key=testKey();$h->hook=fn()=>$p->abandon(1,$key);fail(fn()=>$p->create(1,ptdata(),$key));assertSame(0,$h->creates);
});
test('Pagamentos teste: abandonar chave de pagamento existente mostra o registro sem cancelamento fictício',function(){
    [$p,$h]=ptfixture();$h->nextStatus='pending';$key=testKey();$r=$p->create(1,ptdata('pix'),$key);$s=$p->abandon(1,$key);assertSame($r['id'],$s['id']);assertSame('PENDING',$s['state']);assertSame(0,ptcreditCount());
});
test('Pagamentos teste: cancelamento consulta provedor e nunca cancela crédito já aprovado',function(){
    [$p,$h]=ptfixture();$h->nextStatus='pending';$r=$p->create(1,ptdata('pix'),testKey());assertSame('CANCELLED',$p->cancel(1,$r['id'])['state']);assertSame(0,ptcreditCount());$h->nextStatus='approved';$r=$p->create(1,ptdata(),testKey());fail(fn()=>$p->cancel(1,$r['id']));assertSame(1,ptcreditCount());
});
test('Pagamentos teste: falha durante lançamento desfaz todos os créditos e permite conciliação',function(){
    [$p,$h]=ptfixture();$r=ptstage($p,$h);assertSame('READY_TO_CREDIT',$r['state']);assertSame(null,$r['credited_at']);assertSame(0,ptcreditCount());assertSame('0',balance(1,'MR'));assertSame(0,db()->row("SELECT COUNT(*) n FROM operations WHERE action='admin.payment_test.credit'")['n']);$p->refresh(1,$r['id']);assertSame(1,ptcreditCount());assertSame('10001',balance(1,'MR'));
});
test('Pagamentos teste: conciliações concorrentes usam uma única emissão experimental',function(){
    [$p,$h]=ptfixture();$r=ptstage($p,$h);$results=ptconcurrent($r['id']);assertSame($results[0]['transaction_id'],$results[1]['transaction_id']);assertSame(1,ptcreditCount());assertSame('10001',balance(1,'MR'));
});
test('Pagamentos teste: notificação assinada consulta API; corpo e repetição não autorizam saldo',function(){
    [$p,$h]=ptfixture();$h->nextStatus='pending';$r=$p->create(1,ptdata('pix'),testKey());$ts=(string)time();$rid='request-unit-1';$sig='ts='.$ts.',v1='.hash_hmac('sha256','id:'.$r['provider_id'].';request-id:'.$rid.';ts:'.$ts.';',str_repeat('s',32));$body=json_encode(['type'=>'payment','live_mode'=>false,'status'=>'approved']);
    $p->webhook('data.id='.$r['provider_id'],$sig,$rid,$body);assertSame(0,ptcreditCount());$h->payments[$r['provider_id']]['status']='approved';$p->webhook('data.id='.$r['provider_id'],$sig,$rid,$body);$p->webhook('data.id='.$r['provider_id'],$sig,$rid,$body);assertSame(1,ptcreditCount());
});
test('Pagamentos teste: assinatura falsa, expirada e parâmetros duplicados não consultam provedor',function(){
    [$p,$h]=ptfixture();$r=$p->create(1,ptdata(),testKey());$before=$h->queries;foreach(['ts='.time().',v1='.str_repeat('0',64),'ts=1000000000,v1='.str_repeat('0',64)] as $sig)fail(fn()=>$p->webhook('data.id='.$r['provider_id'],$sig,'request-1','{}'),401);fail(fn()=>$p->webhook('data.id=1&data.id=1','ts=1,v1=x','request-1','{}'),401);assertSame($before,$h->queries);
});
test('Pagamentos teste: usuário comum e outro Admin não acessam o pagamento',function(){
    [$p,$h]=ptfixture();[$u]=user('Participante');fail(fn()=>$p->create($u,ptdata(),testKey()),403);fail(fn()=>$p->list($u),403);$r=$p->create(1,ptdata(),testKey());db()->run("UPDATE users SET role='admin' WHERE id=?",[$u]);fail(fn()=>$p->read($u,$r['id']),403);fail(fn()=>$p->lookup($u,testKey()),404);
});
test('Pagamentos teste: revogação de privilégios antes da conciliação impede crédito',function(){
    [$p,$h]=ptfixture();$h->nextStatus='pending';$r=$p->create(1,ptdata(),testKey());$h->payments[$r['provider_id']]['status']='approved';db()->run("UPDATE users SET role='user' WHERE id=1");fail(fn()=>$p->refresh(1,$r['id']),403);assertSame(0,ptcreditCount());
});
test('Pagamentos teste: dados sensíveis e destino arbitrário são recusados; registros não guardam token',function(){
    [$p,$h]=ptfixture();$bad=ptdata();$bad['card']['card_number']='DADO_PROIBIDO';fail(fn()=>$p->create(1,$bad,testKey()),422);$bad=ptdata();$bad['destination']='external_bank';fail(fn()=>$p->create(1,$bad,testKey()),422);$good=ptdata();$p->create(1,$good,testKey());foreach(['payment_test_intents','payment_test_events','operations','audit_events'] as $t){$raw=json_encode(db()->all('SELECT * FROM '.$t));check(!str_contains($raw,$good['card']['token']),'Token não pode ir para '.$t);check(!str_contains($raw,$h->credential['access_token']),'Access Token não pode ir para '.$t);check(!str_contains($raw,'12345678909'),'CPF não deve ser retido em '.$t);}
});
test('Pagamentos teste: números JSON e centavos são exatos sem arredondamento binário',function(){
    $d=Piscina\MercadoPagoTestHttp::decode('{"id":12345678901234567890,"transaction_amount":0.10,"live_mode":false,"label":"abc123, 0.11"}');assertSame('12345678901234567890',$d['id']);assertSame('0.10',$d['transaction_amount']);check($d['live_mode']===false,'Booleano permanece booleano.');assertSame('abc123, 0.11',$d['label']);[$p,$h]=ptfixture();$p->create(1,ptdata(amount:'0.10'),testKey());assertSame('0.10',$h->posts[0]['transaction_amount']);assertSame('10',balance(1,'MR'));
});
test('Pagamentos teste: mudança de credenciais bloqueia consulta e estorno não debita o jogo automaticamente',function(){
    [$p,$h]=ptfixture();$r=$p->create(1,ptdata(),testKey());$h->payments[$r['provider_id']]['status']='refunded';$s=$p->refresh(1,$r['id']);assertSame('REVIEW',$s['state']);assertSame('10001',balance(1,'MR'));assertSame(1,ptcreditCount());[$other]=ptfixture();fail(fn()=>$other->refresh(1,$r['id']));
});
test('Pagamentos teste: crédito experimental financia e paga prêmio pelo fluxo existente',function(){
    [$p]=ptfixture();$p->create(1,ptdata(amount:'100.00'),testKey());assertSame('10000',balance(1,'MR'));$f=pf();$prize=pcreate('100.00','MR',true,'wallet');paward(ppreview($prize['prize_id'],$f['consent']));assertSame('0',balance(1,'MR'));assertSame(1,ptcreditCount());assertSame('PAID',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$prize['prize_id']])['funds_state']);
});
