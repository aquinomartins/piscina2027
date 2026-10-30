<?php
// Included only by the guarded, destructive piscina_test suite in run.php.
if(!function_exists('test')||PHP_SAPI!=='cli')exit(1);
function dangerData(int $user,array $extra=[]): array {return ['user_id'=>(string)$user,'reason'=>'Intervenção presencial de teste','confirm'=>true]+$extra;}
function banUntil(): string {return (new DateTimeImmutable('+1 hour'))->format('c');}
test('Danger: multa conserva mR$ e não utiliza emissão, reservas ou dívida',function(){
 [$a,$n]=user('Aluno');[$b,$m]=user('Colega');$p=proposal($a,$b,'NFT','1','1500.00',false,$m);op($a,'proposal.approve',['proposal_id'=>$p['proposal_id'],'version'=>'1']);
 $payload=dangerData($a,['amount'=>'50.25']);$key=testKey();$r=op(1,'admin.danger.fine',$payload,$key);assertSame('4975',balance($a,'MR'));assertSame('150000',balance($a,'MR','reserved'));
 assertSame('5025',db()->row("SELECT balance FROM accounts WHERE purpose='fines'")['balance']);
 op(1,'admin.danger.fine',$payload,$key);assertSame('4975',balance($a,'MR'));fail(fn()=>op(1,'admin.danger.fine',dangerData($a,['amount'=>'50.00'])),409);
 fail(fn()=>op(1,'admin.danger.fine',dangerData($a,['amount'=>'0.00'])),422);fail(fn()=>op(1,'admin.danger.fine',dangerData($a,['amount'=>'1.001'])),422);
});
test('Danger: participantes, contas admin, motivo e confirmação são protegidos',function(){
 [$a]=user('Aluno');fail(fn()=>op($a,'admin.danger.fine',dangerData($a,['amount'=>'1.00'])),403);
 foreach(['fine','ban','unban','delete']as$action)fail(fn()=>op(1,'admin.danger.'.$action,dangerData(1,['amount'=>'1.00','until'=>banUntil(),'confirmation'=>'EXCLUIR 1'])),403);
 fail(fn()=>op(1,'admin.danger.fine',['user_id'=>(string)$a,'amount'=>'1.00','reason'=>'ok','confirm'=>true]),422);
 fail(fn()=>op(1,'admin.danger.fine',['user_id'=>(string)$a,'amount'=>'1.00','reason'=>'Motivo válido']),422);
 fail(fn()=>op(1,'admin.danger.fine',dangerData($a,['amount'=>'1.00','new_password'=>'nunca-auditar'])),422);
});
test('Danger: banimento cancela negócios e libera reservas de ambas as partes',function(){
 [$a,$n]=user('Aluno');[$b,$m]=user('Colega');op($a,'nft.deposit',['nft_id'=>(string)$n]);$p=proposal($b,$a,'BYC','3','10.00',true);$l=op($a,'listing.create',['side'=>'SELL','asset'=>'BYC','quantity'=>'1','unit_price'=>'1.00','expires_at'=>banUntil()]);
 $r=op(1,'admin.danger.ban',dangerData($a,['until'=>banUntil()]));assertSame('CANCELLED',db()->row('SELECT state FROM proposals WHERE id=?',[$p['proposal_id']])['state']);assertSame('0',balance($a,'BYC','reserved'));assertSame('1000000000',balance($a,'BYC'));assertSame('CANCELLED',db()->row('SELECT state FROM listings WHERE id=?',[$l['listing_id']])['state']);
 fail(fn()=>service()->user($a),403);fail(fn()=>op($a,'listing.cancel',['listing_id'=>$l['listing_id']]),403);
 assertSame('approved',db()->row('SELECT status FROM users WHERE id=?',[$a])['status']);check(count($r['cancelled_proposals'])===1,'Cancelamentos rastreáveis.');
 op(1,'admin.danger.unban',dangerData($a));service()->user($a,true);assertSame('2',db()->row('SELECT auth_version FROM users WHERE id=?',[$a])['auth_version']);
});
test('Danger: expiração independe do Cron e não aprova ou verifica cadastro',function(){
 $a=pending('Pendente');db()->run('UPDATE users SET email_verified_at=NULL WHERE id=?',[$a]);op(1,'admin.danger.ban',dangerData($a,['until'=>banUntil()]));
 db()->run('UPDATE admin_restrictions SET banned_until=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE user_id=?',[$a]);$u=service()->user($a);assertSame('pending',$u['status']);assertSame(null,$u['email_verified_at']);fail(fn()=>service()->user($a,true),403);
 fail(fn()=>op(1,'admin.danger.ban',dangerData($a,['until'=>'2020-01-01T00:00:00Z'])),422);
});
test('Danger: exclusão preserva patrimônio de terceiros, NFTs POOL e proveniência',function(){
 [$a,$private]=user('Aluno');[$b,$other]=user('Colega');$pool=op(1,'admin.mint',['user_id'=>(string)$a,'title'=>'Na piscina','reason'=>'Fixture de custódia'])['nft_id'];op($a,'nft.deposit',['nft_id'=>$pool]);
 $sold=op(1,'admin.mint',['user_id'=>(string)$a,'title'=>'Já vendida','reason'=>'Fixture de custódia'])['nft_id'];sale($b,$a,'NFT','1','0.00',(int)$sold);sale($a,$b,'NFT','1','0.00',$other);
 $key=testKey();$payload=dangerData($a,['confirmation'=>'EXCLUIR '.$a]);$r=op(1,'admin.danger.delete',$payload,$key);assertSame($r['operation_id'],op(1,'admin.danger.delete',$payload,$key)['operation_id']);
 assertSame('0',balance($a,'MR'));assertSame('0',balance($a,'BYC'));assertSame('0',balance($a,'SHARE'));
 assertSame('160000',db()->row("SELECT balance FROM accounts WHERE user_id=? AND asset='MR' AND purpose='custody'",[$a])['balance']);
 assertSame('POOL',db()->row('SELECT state FROM nfts WHERE id=?',[$pool])['state']);assertSame($b,db()->row('SELECT owner_id FROM nfts WHERE id=?',[$sold])['owner_id']);
 assertSame('CUSTODY',db()->row('SELECT state FROM nfts WHERE id=?',[$private])['state']);assertSame('CUSTODY',db()->row('SELECT state FROM nfts WHERE id=?',[$other])['state']);assertSame($b,db()->row('SELECT creator_id FROM nfts WHERE id=?',[$other])['creator_id']);
 fail(fn()=>(new Piscina\Queries(service()))->nft($private),404);$d=(new Piscina\AdminDanger(service()))->view(1,$a);assertSame(2,count($d['nfts']));
 fail(fn()=>(new Piscina\AdminDanger(service()))->view($b,$a),403);fail(fn()=>op(1,'admin.approve',['user_id'=>(string)$a]),403);fail(fn()=>op(1,'admin.danger.delete',$payload),409);
});
test('Danger: confirmação forte de exclusão e rollback preservam ativos',function(){
 [$a,$n]=user('Aluno');fail(fn()=>op(1,'admin.danger.delete',dangerData($a,['confirmation'=>'EXCLUIR OUTRO'])),422);assertSame('160000',balance($a,'MR'));assertSame('PRIVATE',db()->row('SELECT state FROM nfts WHERE id=?',[$n])['state']);
});
test('Danger: falha durante custódia faz rollback integral da exclusão e das transferências',function(){
 [$a,$n]=user('Aluno');db()->pdo->exec("CREATE TRIGGER custody_failure BEFORE INSERT ON admin_nft_custody FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha injetada de teste'");
 try{try{op(1,'admin.danger.delete',dangerData($a,['confirmation'=>'EXCLUIR '.$a]));throw new LogicException('Devia falhar.');}catch(PDOException $e){assertSame('45000',$e->getCode());}}
 finally{db()->pdo->exec('DROP TRIGGER custody_failure');}
 assertSame('160000',balance($a,'MR'));assertSame('PRIVATE',db()->row('SELECT state FROM nfts WHERE id=?',[$n])['state']);assertSame(null,db()->row('SELECT deleted_at FROM admin_restrictions WHERE user_id=?',[$a]));service()->user($a,true);
});
test('Danger: reservas de contraparte são liberadas antes da custódia',function(){
 [$a,$n]=user('Aluno');[$b,$m]=user('Colega');$p=proposal($b,$a,'NFT','1','1000.00',false,$n);op($b,'proposal.approve',['proposal_id'=>$p['proposal_id'],'version'=>'1']);assertSame('100000',balance($b,'MR','reserved'));
 op(1,'admin.danger.delete',dangerData($a,['confirmation'=>'EXCLUIR '.$a]));assertSame('160000',balance($b,'MR'));assertSame('0',balance($b,'MR','reserved'));assertSame('CANCELLED',db()->row('SELECT state FROM proposals WHERE id=?',[$p['proposal_id']])['state']);
});
test('Danger: reserva órfã impede exclusão parcial e mantém acesso e saldo',function(){
 [$a]=user('Aluno');$l=service()->ledger;
 db()->transaction(fn()=>$l->move($l->account($a,'MR'),$l->account($a,'MR','reserved'),'100','TEST','orphan-fixture',null));
 fail(fn()=>op(1,'admin.danger.delete',dangerData($a,['confirmation'=>'EXCLUIR '.$a])),409);service()->user($a,true);assertSame('100',balance($a,'MR','reserved'));
 db()->transaction(fn()=>$l->move($l->account($a,'MR','reserved'),$l->account($a,'MR'),'100','TEST','orphan-undo',null));
});
test('Danger: cotas em custódia conservam Q, direitos e taxas pendentes nos cortes futuros',function(){
 [$a,$n]=user('Aluno');[$b,$m]=user('Colega');op($a,'nft.deposit',['nft_id'=>(string)$n]);op($b,'nft.deposit',['nft_id'=>(string)$m]);period();
 $before=db()->row("SELECT SUM(amount) value FROM rights WHERE user_id=? AND state='PENDING'",[$a])['value'];
 op(1,'admin.danger.delete',dangerData($a,['confirmation'=>'EXCLUIR '.$a]));
 db()->run('UPDATE periods SET cutoff_at=DATE_SUB(cutoff_at,INTERVAL 1 DAY)');service()->periods->capture();while(service()->periods->processBatch(100)!==null){}
 $p=db()->row('SELECT * FROM periods ORDER BY id DESC LIMIT 1');assertSame('2',$p['total_shares']);assertSame('40000',$p['distribution_total']);
 assertSame('40000',db()->row('SELECT SUM(amount) n FROM rights WHERE period_id=?',[$p['id']])['n']);assertSame('20000',db()->row('SELECT amount FROM rights WHERE period_id=? AND user_id=?',[$p['id'],$a])['amount']);
 assertSame('10000',db()->row('SELECT amount FROM fees WHERE period_id=? AND user_id=?',[$p['id'],$a])['amount']);assertSame('0',balance($a,'MR'));
 foreach(db()->all('SELECT state FROM rights WHERE user_id=?',[$a])as$r)assertSame('PENDING',$r['state']);
});
test('Danger: concorrência banimento/operação respeita ordem do gate',function(){
 [$a,$n]=user('Aluno');$r=concurrent([['actor'=>1,'action'=>'admin.danger.ban','data'=>dangerData($a,['until'=>banUntil()])],['actor'=>$a,'action'=>'nft.deposit','data'=>['nft_id'=>(string)$n]]]);
 check($r[0]['ok'],'Banimento deve concluir.');if($r[1]['ok']){$ban=db()->row('SELECT sequence_no FROM operations WHERE id=?',[$r[0]['data']['operation_id']])['sequence_no'];$dep=db()->row('SELECT sequence_no FROM operations WHERE id=?',[$r[1]['data']['operation_id']])['sequence_no'];check((int)$dep<(int)$ban,'Operação ocorreu antes da sanção.');}else assertSame(403,$r[1]['status']);
 fail(fn()=>op($a,'nft.deposit',['nft_id'=>(string)$n]),403);
});
test('Danger: multa concorrente não ultrapassa saldo e não duplica chave',function(){
 [$a]=user('Aluno');$payload=dangerData($a,['amount'=>'1000.00']);$r=concurrent(array_fill(0,2,['actor'=>1,'action'=>'admin.danger.fine','data'=>$payload]));assertSame(1,count(array_filter($r,fn($v)=>$v['ok'])));assertSame('60000',balance($a,'MR'));
});
test('Danger: senha administrativa é idempotente e não aparece em auditoria ou recibos',function(){
 [$a]=user('Aluno');$auth=new Piscina\Auth(db(),config());$auth->requestToken(['email'=>'Aluno@local.invalid'],'reset');$body=db()->row('SELECT body FROM outbox ORDER BY id DESC LIMIT 1')['body'];preg_match('/#reset=([a-f0-9]{64})/',$body,$m);
 $payload=dangerData($a,['new_password'=>'credencial-nova-testes','new_password_confirmation'=>'credencial-nova-testes']);$service=new Piscina\AdminCredentials(service());$key=testKey();$r=$service->reset(1,$payload,$key);assertSame($r['operation_id'],$service->reset(1,$payload,$key)['operation_id']);
 $u=db()->row('SELECT * FROM users WHERE id=?',[$a]);check(password_verify('credencial-nova-testes',$u['password_hash']),'Nova senha válida.');assertSame('2',$u['auth_version']);fail(fn()=>$auth->useToken(['token'=>$m[1],'password'=>'credencial-antiga-testes'],'reset'),422);
 $audit=db()->row('SELECT data FROM audit_events WHERE operation_id=?',[$r['operation_id']])['data'];check(!str_contains($audit,'credencial')&&!str_contains($audit,'password')&&!str_contains($audit,$u['password_hash']),'Auditoria sem credenciais.');
 $other=$payload;$other['new_password']=$other['new_password_confirmation']='outra-credencial-testes';fail(fn()=>$service->reset(1,$other,$key),409);
 fail(fn()=>$service->reset($a,$payload,testKey()),403);fail(fn()=>$service->reset(1,dangerData(1,['new_password'=>'credencial-nova-testes','new_password_confirmation'=>'credencial-nova-testes']),testKey()),403);
});
test('Danger: redefinição não remove banimento nem aprova pendência; excluído é recusado',function(){
 $a=pending('Aluno');db()->run('UPDATE users SET email_verified_at=NULL WHERE id=?',[$a]);op(1,'admin.danger.ban',dangerData($a,['until'=>banUntil()]));
 $p=dangerData($a,['new_password'=>'credencial-nova-testes','new_password_confirmation'=>'credencial-nova-testes']);$s=new Piscina\AdminCredentials(service());$s->reset(1,$p,testKey());fail(fn()=>service()->user($a),403);$u=db()->row('SELECT * FROM users WHERE id=?',[$a]);assertSame('pending',$u['status']);assertSame(null,$u['email_verified_at']);
 op(1,'admin.danger.delete',dangerData($a,['confirmation'=>'EXCLUIR '.$a]));fail(fn()=>$s->reset(1,$p,testKey()),409);
});
