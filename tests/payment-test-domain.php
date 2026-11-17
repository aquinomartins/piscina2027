<?php
declare(strict_types=1);
require __DIR__.'/payment-test-fixtures.php';
test('Pagamentos teste: flag desativada e política diferente impedem envio e emissão',function(){
    [$p,$h]=ptfixture(['enabled'=>false]);fail(fn()=>$p->create(1,ptdata(),testKey()));assertSame(0,$h->creates);assertSame(0,ptcreditCount());
    service()->config['payments_test']['enabled']=true;db()->run('UPDATE decision_versions SET policy=?',[json_encode(['assets'=>'external','payment'=>'BRL'])]);fail(fn()=>$p->create(1,ptdata(),testKey()));
});
test('Pagamentos teste: OAuth comprova live_mode falso e coletor antes de enviar',function(){
    foreach([['live_mode'=>true],['live_mode'=>'false'],['live_mode'=>null],['user_id'=>'999'],['public_key'=>'']] as $bad){[$p,$h]=ptfixture();$h->credential=array_replace($h->credential,$bad);fail(fn()=>$p->create(1,ptdata(),testKey()));assertSame(0,$h->creates);assertSame(0,ptcreditCount());}
});
test('Pagamentos teste: prefixo de teste não substitui atestado do provedor',function(){
    [$p,$h]=ptfixture();$h->credential['live_mode']=true;$b=$p->bootstrap(1);check(!$b['enabled'],'Área deve estar desativada.');check(!isset($b['public_key']),'Não inicializar SDK sem ambiente verificado.');assertSame(0,$h->creates);
    [$p,$h]=ptfixture(['max_amount'=>'inválido']);check(!$p->bootstrap(1)['enabled'],'Limite inconsistente mantém área desativada.');assertSame(0,$h->oauth);
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
    [$p,$h]=ptfixture();$r=$p->create(1,ptdata(),testKey());$h->payments[$r['provider_id']]['status']='refunded';$s=$p->refresh(1,$r['id']);assertSame('REVIEW',$s['state']);assertSame('10001',balance(1,'MR'));assertSame(1,ptcreditCount());[$other]=ptfixture(['client_secret'=>str_repeat('z',32)]);fail(fn()=>$other->refresh(1,$r['id']));
});
test('Pagamentos teste: crédito experimental financia e paga prêmio pelo fluxo existente',function(){
    [$p]=ptfixture();$p->create(1,ptdata(amount:'100.00'),testKey());assertSame('10000',balance(1,'MR'));$f=pf();$prize=pcreate('100.00','MR',true,'wallet');paward(ppreview($prize['prize_id'],$f['consent']));assertSame('0',balance(1,'MR'));assertSame(1,ptcreditCount());assertSame('PAID',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$prize['prize_id']])['funds_state']);
});
