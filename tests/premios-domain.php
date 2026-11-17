<?php
declare(strict_types=1);
function prizeService(): Piscina\GaleriaPremios {return new Piscina\GaleriaPremios(new Piscina\Galeria(service()));}
function consentService(): Piscina\PrizeConsents {return new Piscina\PrizeConsents(new Piscina\Galeria(service()));}
function pf(bool $consent=true,bool $self=false): array {
    $f=msfixture();$d=msterms($f,$self?['nft_id'=>(string)$f['anaNft']]:[]);$p=mspropose($f['ana'],$d);
    $a=$self?$p:msaccept($f['bruno'],$p['link_proposal_id']);$f['link']=$a['link_id'];
    if($consent){$c=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]);$f['consent']=$c['consent_id'];foreach(array_unique([$f['ana'],$self?$f['ana']:$f['bruno']]) as $u)pcaccept($u,$f['consent']);}
    return $f;
}
function pcaccept(int $actor,string $id,bool $accept=true): array {$c=consentService()->read($actor,(int)$id);if($accept)check($c['eligible'],'A leitura deve permitir o aceite do aditivo válido.');return op($actor,'galeria.prize.consent.'.($accept?'accept':'reject'),['consent_id'=>$id,'terms_hash'=>$c['terms_hash'],'confirm'=>true]);}
function pcreate(string $amount='100',string $asset='MR',bool $fund=true,string $source='galeria_revenue'): array {$r=ga('prize.save',['title'=>'Prêmio direto da turma','amount'=>$amount,'asset'=>$asset]);if($fund)ga('prize.fund',['prize_id'=>$r['prize_id'],'source'=>$source]);return $r;}
function ppreview(string $prize,string $consent): array {return db()->transaction(function()use($prize,$consent){db()->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return prizeService()->preview(1,['prize_id'=>$prize,'consent_id'=>$consent]);});}
function ppayload(array $summary): array {return ['prize_id'=>$summary['prize_id'],'consent_id'=>$summary['consent_id'],'preview_hash'=>$summary['preview_hash'],'confirm'=>true,'reason'=>'Premiação direta conforme aditivo confirmado'];}
function paward(array $summary,?string $key=null): array {return op(1,'admin.galeria.prize.award',ppayload($summary),$key);}
test('Prêmios: cria modalidade direta sem agenda, emissão ou alteração de evento',function(){
    $f=pf();$before=balance($f['ana'],'MR');$entries=db()->row('SELECT COUNT(*) n FROM ledger_entries')['n'];$p=pcreate('100','MR',false);$e=db()->row('SELECT * FROM galeria_events WHERE id=?',[$p['prize_id']]);assertSame('DIRECT',$e['mode']);assertSame(null,$e['voting_starts']);assertSame(null,$e['capacity']);assertSame('UNFUNDED',$e['funds_state']);assertSame($before,balance($f['ana'],'MR'));assertSame($entries,db()->row('SELECT COUNT(*) n FROM ledger_entries')['n']);fail(fn()=>ppreview($p['prize_id'],$f['consent']));
});
test('Prêmios: aditivo exige aceite de cada participante e não movimenta saldo',function(){
    $f=pf(false);$p=pcreate();$before=balance($f['ana'],'MR');$old=db()->row('SELECT terms FROM galeria_links WHERE id=?',[$f['link']])['terms'];$c=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]);$id=$c['consent_id'];$entries=db()->row('SELECT COUNT(*) n FROM ledger_entries')['n'];fail(fn()=>ppreview($p['prize_id'],$id));
    $duplicate=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]);assertSame($id,$duplicate['consent_id']);pcaccept($f['ana'],$id);fail(fn()=>ppreview($p['prize_id'],$id));
    assertSame(0,(new Piscina\ParticipantActivity(service()))->counts($f['ana'])['needs_action']);assertSame(1,(new Piscina\ParticipantActivity(service()))->counts($f['ana'])['waiting']);assertSame(1,(new Piscina\ParticipantActivity(service()))->counts($f['bruno'])['needs_action']);
    pcaccept($f['bruno'],$id);assertSame('ACTIVE',consentService()->read(1,(int)$id)['state']);assertSame($entries,db()->row('SELECT COUNT(*) n FROM ledger_entries')['n']);assertSame($old,db()->row('SELECT terms FROM galeria_links WHERE id=?',[$f['link']])['terms']);assertSame($before,balance($f['ana'],'MR'));assertSame(0,(new Piscina\ParticipantActivity(service()))->counts($f['bruno'])['needs_action']);
});
test('Prêmios: confirmação credita 30/70 automaticamente e conserva financiamento',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);assertSame('3000',$s['frame_amount']);assertSame('7000',$s['nft_amount']);check(!str_contains(json_encode($s),'image_path'),'Resumo não expõe arquivos privados.');$a=balance($f['ana'],'MR');$b=balance($f['bruno'],'MR');$r=paward($s);assertSame('3000',bcsub(balance($f['ana'],'MR'),$a,0));assertSame('7000',bcsub(balance($f['bruno'],'MR'),$b,0));assertSame('SETTLED',$r['state']);assertSame('0',db()->row("SELECT balance FROM accounts WHERE code=?",['T:MR:galeria_prize:'.$p['prize_id']])['balance']);assertSame('PAID',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$p['prize_id']])['funds_state']);assertSame('0',balance($f['ana'],'SHARE'));assertSame(2,db()->row("SELECT COUNT(*) n FROM galeria_finance gf JOIN ledger_transactions t ON t.id=gf.transaction_id WHERE t.kind='GALERIA_AWARD'")['n']);
});
test('Prêmios: arredondamento de centavo é determinístico e soma o total',function(){
    $f=pf();$p=pcreate('0.01');$s=ppreview($p['prize_id'],$f['consent']);assertSame('0',$s['nft_amount']);assertSame('1',$s['frame_amount']);paward($s);$p=pcreate('1.01');$s=ppreview($p['prize_id'],$f['consent']);assertSame('70',$s['nft_amount']);assertSame('31',$s['frame_amount']);paward($s);
});
test('Prêmios: BYC preserva oito casas e origem em carteira existente',function(){
    $f=pf();op(1,'admin.approve',['user_id'=>'1']);op($f['ana'],'nft.deposit',['nft_id'=>(string)$f['anaNft']]);sale(1,$f['ana'],'BYC','2','0.00');$p=pcreate('1.00000001','BYC',true,'wallet');$s=ppreview($p['prize_id'],$f['consent']);assertSame('70000000',$s['nft_amount']);assertSame('30000001',$s['frame_amount']);paward($s);assertSame('99999999',balance(1,'BYC'));
});
test('Prêmios: tesouraria financia com recursos reais do livro',function(){
    $f=pf();period();$fee=db()->row('SELECT id FROM fees WHERE user_id=?',[$f['caio']]);op($f['caio'],'fee.pay',['fee_id'=>$fee['id']]);$p=pcreate('100','MR',true,'treasury');$s=ppreview($p['prize_id'],$f['consent']);paward($s);assertSame('0',db()->row("SELECT balance FROM accounts WHERE code='T:MR:treasury'")['balance']);
});
test('Prêmios: saldo insuficiente e origem de emissão não financiam',function(){
    pf();$p=pcreate('100','MR',false);$before=db()->row('SELECT COUNT(*) n FROM ledger_entries')['n'];fail(fn()=>ga('prize.fund',['prize_id'=>$p['prize_id'],'source'=>'wallet']));fail(fn()=>ga('prize.fund',['prize_id'=>$p['prize_id'],'source'=>'issuance']),422);assertSame('UNFUNDED',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$p['prize_id']])['funds_state']);assertSame($before,db()->row('SELECT COUNT(*) n FROM ledger_entries')['n']);
});
test('Prêmios: percentuais ausentes e soma inválida bloqueiam aditivo',function(){
    $f=pf(false);$a=db()->row('SELECT terms FROM galeria_links WHERE id=?',[$f['link']]);$t=json_decode($a['terms'],true);unset($t['frame_bps']);db()->run('UPDATE galeria_links SET terms=? WHERE id=?',[json_encode($t),$f['link']]);fail(fn()=>ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]));
    $t['frame_bps']=9000;db()->run('UPDATE galeria_links SET terms=? WHERE id=?',[json_encode($t),$f['link']]);fail(fn()=>ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]));assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_prize_consents')['n']);
});
test('Prêmios: não usa autorização de venda ou regulamento de votação',function(){
    $f=pf();$p=pcreate();$c=db()->row('SELECT terms FROM galeria_prize_consents WHERE id=?',[$f['consent']]);$t=json_decode($c['terms'],true);$t['scope']='SALE_PROCEEDS';db()->run('UPDATE galeria_prize_consents SET terms=?,terms_hash=? WHERE id=?',[json_encode($t),hash('sha256',Piscina\Domain::canonical($t)),$f['consent']]);fail(fn()=>ppreview($p['prize_id'],$f['consent']));
    ga('features',['physical'=>false,'events'=>true,'share_charges'=>false]);$e=ga('event.save',['title'=>'Prêmio com votação','voting_starts'=>gd('+2 hours'),'voting_ends'=>gd('+3 hours'),'prize_asset'=>'MR','prize'=>'100','rules'=>'Vencedor pela votação publicada']);fail(fn()=>ppreview($e['event_id'],$f['consent']));fail(fn()=>ga('event.publish',['event_id'=>$p['prize_id']]));assertSame(1,count((new Piscina\GaleriaQueries(service()))->events([],true)['rows']));
});
test('Prêmios: não aceita valores individuais nem confirmação desatualizada',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);fail(fn()=>op(1,'admin.galeria.prize.award',ppayload($s)+['nft_amount'=>'999999']),422);$bad=ppayload($s);$bad['preview_hash']=str_repeat('0',64);fail(fn()=>op(1,'admin.galeria.prize.award',$bad));assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_prize_awards')['n']);
});
test('Prêmios: mesma chave e novas tentativas não repetem créditos',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);$key=testKey();$a=paward($s,$key);$before=balance($f['bruno'],'MR');$b=paward($s,$key);assertSame($a['operation_id'],$b['operation_id']);$c=paward($s);assertSame($a['award_id'],$c['award_id']);check($c['already_awarded'],'Recibo informa destino anterior.');assertSame($before,balance($f['bruno'],'MR'));$bad=ppayload($s);$bad['consent_id']='999999';fail(fn()=>op(1,'admin.galeria.prize.award',$bad));assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_prize_awards')['n']);fail(fn()=>ga('prize.cancel',['prize_id'=>$p['prize_id']]));
});
test('Prêmios: concorrência sobre o mesmo prêmio credita uma única vez',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);$r=concurrent([['actor'=>1,'action'=>'admin.galeria.prize.award','data'=>ppayload($s)],['actor'=>1,'action'=>'admin.galeria.prize.award','data'=>ppayload($s)]]);check($r[0]['ok']&&$r[1]['ok'],'Ambas recebem o mesmo destino confirmado.');assertSame($r[0]['data']['award_id'],$r[1]['data']['award_id']);assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_prize_awards')['n']);assertSame(2,db()->row("SELECT COUNT(*) n FROM ledger_transactions WHERE kind='GALERIA_AWARD'")['n']);
});
test('Prêmios: destinos concorrentes diferentes têm somente um vencedor',function(){
    $f=pf();$frame=ga('frame.quick',['name'=>'Outra associação','sale_mode'=>'public','price'=>'1','asset'=>'MR']);msbuy($f['caio'],$frame);$link=mspropose($f['caio'],msterms($f,['frame_id'=>$frame['frame_id'],'nft_id'=>(string)$f['caioNft']]));$c=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$link['link_id']]);pcaccept($f['caio'],$c['consent_id']);$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);$t=ppreview($p['prize_id'],$c['consent_id']);$r=concurrent([['actor'=>1,'action'=>'admin.galeria.prize.award','data'=>ppayload($s)],['actor'=>1,'action'=>'admin.galeria.prize.award','data'=>ppayload($t)]]);assertSame(1,count(array_filter($r,fn($v)=>$v['ok'])));assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_prize_awards')['n']);assertSame(2,db()->row("SELECT COUNT(*) n FROM ledger_transactions WHERE kind='GALERIA_AWARD'")['n']);
});
test('Prêmios: falha no segundo crédito desfaz prêmio, primeiro crédito e auditoria',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);$key=testKey();$before=[];foreach(['operations','audit_events','galeria_finance','ledger_entries','notifications'] as $t)$before[$t]=db()->row("SELECT COUNT(*) n FROM $t")['n'];$a=balance($f['ana'],'MR');$b=balance($f['bruno'],'MR');
    db()->pdo->exec("CREATE TRIGGER fail_prize_frame BEFORE INSERT ON ledger_transactions FOR EACH ROW BEGIN IF NEW.reference LIKE '%:award:direct:frame' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Falha simulada no segundo crédito'; END IF; END");
    try {try{paward($s,$key);throw new RuntimeException('Deveria falhar no segundo crédito.');}catch(PDOException $e){check(str_contains($e->getMessage(),'Falha simulada'),'Falha injetada exercitada.');}}finally{db()->pdo->exec('DROP TRIGGER fail_prize_frame');}
    assertSame($a,balance($f['ana'],'MR'));assertSame($b,balance($f['bruno'],'MR'));assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_prize_awards')['n']);foreach($before as $t=>$n)assertSame($n,db()->row("SELECT COUNT(*) n FROM $t")['n']);assertSame('FUNDED',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$p['prize_id']])['funds_state']);paward($s,$key);assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_prize_awards')['n']);
});
test('Prêmios: recusa preserva contrato e novo aditivo exige novos aceites',function(){
    $f=pf(false);$c=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]);pcaccept($f['ana'],$c['consent_id']);pcaccept($f['bruno'],$c['consent_id'],false);assertSame(0,(new Piscina\ParticipantActivity(service()))->counts($f['ana'])['negotiations']);$new=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]);check($new['consent_id']!==$c['consent_id'],'Novo registro, sem reutilizar aceites.');assertSame(0,count(consentService()->read(1,(int)$new['consent_id'])['acceptances']));assertSame('ACTIVE',db()->row('SELECT state FROM galeria_links WHERE id=?',[$f['link']])['state']);
});
test('Prêmios: terceiro e Admin não podem aceitar no lugar dos proprietários',function(){
    $f=pf(false);$c=ga('prize.consent.request',['protocol'=>'simple','association_id'=>$f['link']]);fail(fn()=>pcaccept(1,$c['consent_id']),403);fail(fn()=>consentService()->read($f['caio'],(int)$c['consent_id']),403);fail(fn()=>prizeService()->list($f['ana'],[]),403);fail(fn()=>ga('prize.save',['title'=>'Sem moeda','asset'=>'SHARE','amount'=>'1']),422);$d=['title'=>'Sem autorização','asset'=>'MR','amount'=>'1','confirm'=>true,'reason'=>'Tentativa por participante'];fail(fn()=>op($f['ana'],'admin.galeria.prize.save',$d),403);
});
test('Prêmios: ativo suspenso e contrato alterado bloqueiam a distribuição',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);op($f['bruno'],'nft.deposit',['nft_id'=>(string)$f['brunoNft']]);fail(fn()=>paward($s));assertSame('FUNDED',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$p['prize_id']])['funds_state']);ga('prize.cancel',['prize_id'=>$p['prize_id']]);assertSame('RETURNED',db()->row('SELECT funds_state FROM galeria_events WHERE id=?',[$p['prize_id']])['funds_state']);
});
test('Prêmios: registro histórico não muda com alterações posteriores do contrato',function(){
    $f=pf();$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);$award=paward($s);$before=db()->row('SELECT snapshot FROM galeria_prize_awards WHERE id=?',[$award['award_id']])['snapshot'];$t=json_decode(db()->row('SELECT terms FROM galeria_links WHERE id=?',[$f['link']])['terms'],true);$t['nft_bps']=6000;$t['frame_bps']=4000;db()->run('UPDATE galeria_links SET nft_bps=6000,terms=? WHERE id=?',[json_encode($t),$f['link']]);$new=pcreate('10');fail(fn()=>ppreview($new['prize_id'],$f['consent']));assertSame($before,db()->row('SELECT snapshot FROM galeria_prize_awards WHERE id=?',[$award['award_id']])['snapshot']);$history=prizeService()->history($f['bruno']);assertSame('7000',$history[0]['nft_bps']);assertSame('7000',$history[0]['nft_amount']);assertSame(0,count(prizeService()->history($f['caio'])));
});
test('Prêmios: mesmo titular confirma uma vez e recebe somente o total',function(){
    $f=pf(true,true);$p=pcreate();$s=ppreview($p['prize_id'],$f['consent']);assertSame($s['frame_user'],$s['nft_user']);$before=balance($f['ana'],'MR');paward($s);assertSame('10000',bcsub(balance($f['ana'],'MR'),$before,0));assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_prize_acceptances WHERE consent_id=?',[$f['consent']])['n']);
});
test('Prêmios: contrato físico mantém cotas e votação enquanto recebe prêmio direto adicional',function(){
    $f=gfixture(1);$a=$f['all'][0];$before=balance($a['holder'],'SHARE','reserved');$original=db()->row('SELECT terms FROM galeria_agreements WHERE id=?',[$a['a']['agreement_id']])['terms'];$c=ga('prize.consent.request',['protocol'=>'physical','association_id'=>$a['a']['agreement_id']]);pcaccept($a['holder'],$c['consent_id']);pcaccept($a['owner'],$c['consent_id']);$p=pcreate('25');$s=ppreview($p['prize_id'],$c['consent_id']);paward($s);assertSame($before,balance($a['holder'],'SHARE','reserved'));assertSame($original,db()->row('SELECT terms FROM galeria_agreements WHERE id=?',[$a['a']['agreement_id']])['terms']);assertSame('REGISTRATION',db()->row('SELECT state FROM galeria_events WHERE id=?',[$f['event']['event_id']])['state']);
});
