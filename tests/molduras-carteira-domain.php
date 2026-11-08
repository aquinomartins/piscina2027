<?php
declare(strict_types=1);
function walletRead(int $actor,string $section='dashboard',int $offset=0): array {return (new Piscina\Queries(service()))->dashboard($actor,['section'=>$section,'offset'=>$offset]);}
function activityRead(int $actor,bool $history=false,int $offset=0): array {return (new Piscina\ParticipantActivity(service()))->page($actor,['offset'=>$offset],$history);}
test('Carteira: oferta exclusiva é pendência e não patrimônio antes da compra',function(){
    $f=msfixture(false);$o=ga('frame.quick',['name'=>'Oferta a Ana','sale_mode'=>'direct','buyer_id'=>(string)$f['ana'],'price'=>'100','asset'=>'MR']);
    $a=walletRead($f['ana']);assertSame(0,count($a['activity']['frames']['rows']));assertSame('160000',$a['balances']['MR']['available']);$p=activityRead($f['ana']);assertSame(1,count($p['rows']));assertSame('offer',$p['rows'][0]['kind']);check($p['rows'][0]['needs_action'],'Destinatário decide a compra.');assertSame('10000',$p['rows'][0]['amount']);assertSame(0,count(activityRead($f['bruno'])['rows']));
    msbuy($f['ana'],$o);$a=walletRead($f['ana']);assertSame(1,count($a['activity']['frames']['rows']));assertSame($o['frame_id'],$a['activity']['frames']['rows'][0]['id']);assertSame(0,count(activityRead($f['ana'])['rows']));
});
test('Carteira: proposta enviada e recebida indicam responsabilidade sem antecipar vínculo',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$a=walletRead($f['ana']);assertSame(1,count($a['activity']['frames']['rows']));assertSame('1',$a['activity']['frames']['rows'][0]['pending_proposals']);assertSame(0,count($a['activity']['relations']));
    $ana=(new Piscina\ParticipantActivity(service()))->counts($f['ana']);$bruno=(new Piscina\ParticipantActivity(service()))->counts($f['bruno']);assertSame(0,$ana['needs_action']);assertSame(1,$ana['waiting']);assertSame(1,$bruno['needs_action']);assertSame(0,$bruno['waiting']);
    $r=activityRead($f['ana'])['rows'][0];assertSame((string)$f['bruno'],$r['next_user_id']);assertSame('BrunoNFT',$r['next_user_name']);assertSame('Aguardando resposta',$r['responsibility']);assertSame('7000',$r['nft_bps']);check(!str_contains(json_encode($r),'image_path'),'Sem caminhos privados.');
});
test('Carteira: contraproposta inverte a resposta e não duplica a negociação',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));mspropose($f['bruno'],msterms($f,['link_proposal_id'=>$p['link_proposal_id'],'version'=>'1','frame_percent'=>'40','nft_percent'=>'60']));
    foreach([[$f['ana'],1,0],[$f['bruno'],0,1]] as [$actor,$needed,$waiting]){$a=(new Piscina\ParticipantActivity(service()))->counts($actor);assertSame($needed,$a['needs_action']);assertSame($waiting,$a['waiting']);$rows=activityRead($actor)['rows'];assertSame(1,count($rows));assertSame('2',$rows[0]['version']);assertSame('6000',$rows[0]['nft_bps']);}
});
test('Carteira: aceite associa aos ativos corretos sem incorporar ativo de terceiro',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$result=msaccept($f['bruno'],$p['link_proposal_id']);check(str_contains($result['message'],'Associação confirmada'),'Confirmação vem do servidor.');
    $a=walletRead($f['ana']);$b=walletRead($f['bruno']);assertSame(1,count($a['activity']['frames']['rows']));assertSame(0,count($b['activity']['frames']['rows']));assertSame(1,count($a['activity']['relations']));assertSame(1,count($b['activity']['relations']));
    assertSame((string)$f['bruno'],$a['activity']['relations'][0]['current_nft_owner']);assertSame((string)$f['ana'],$b['activity']['relations'][0]['current_frame_owner']);assertSame(1,count(array_filter($a['nfts'],fn($n)=>$n['owner_id']===(string)$f['ana'])));check(!in_array((string)$f['brunoNft'],array_column($a['nfts'],'id'),true),'NFT de Bruno não entra nas obras de Ana.');
    foreach([$f['ana'],$f['bruno']] as $u){assertSame(0,count(activityRead($u)['rows']));assertSame('0',balance($u,'SHARE','reserved'));}
    $h=activityRead($f['ana'],true)['rows'];assertSame(1,count(array_filter($h,fn($r)=>$r['kind']==='association'&&$r['id']===$p['link_proposal_id'])));
});
test('Carteira: recusa e cancelamento saem das abertas e ficam no histórico',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));op($f['bruno'],'galeria.link.reject',['link_proposal_id'=>$p['link_proposal_id'],'version'=>'1','confirm'=>true]);$q=mspropose($f['ana'],msterms($f));op($f['ana'],'galeria.link.cancel',['link_proposal_id'=>$q['link_proposal_id'],'version'=>'1','confirm'=>true]);assertSame(0,count(activityRead($f['ana'])['rows']));
    $h=array_filter(activityRead($f['ana'],true)['rows'],fn($r)=>$r['kind']==='association');assertSame(2,count($h));assertSame('CANCELLED',array_values($h)[0]['state']);assertSame('REJECTED',array_values($h)[1]['state']);
});
test('Carteira: expiradas não dependem do cron e vínculo aceito não expira com a proposta',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));gclock(gd('+8 days'));assertSame(0,count(activityRead($f['ana'])['rows']));$rows=activityRead($f['ana'],true)['rows'];assertSame('EXPIRED',array_values(array_filter($rows,fn($r)=>$r['kind']==='association'))[0]['state']);assertSame('PENDING',db()->row('SELECT state FROM galeria_link_proposals')['state']);
    db()->pdo->exec('SET timestamp=DEFAULT');msaccept($f['bruno'],$p['link_proposal_id']);gclock(gd('+8 days'));$r=array_values(array_filter(activityRead($f['ana'],true)['rows'],fn($r)=>$r['kind']==='association'))[0];assertSame('ACTIVE',$r['state']);
});
test('Carteira: pedido de encerramento conta uma vez e identifica quem deve confirmar',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$a=msaccept($f['bruno'],$p['link_proposal_id']);$r=op($f['ana'],'galeria.link.end',['link_id'=>$a['link_id'],'confirm'=>true]);assertSame((string)$f['bruno'],$r['next_user_id']);
    $c=(new Piscina\ParticipantActivity(service()))->counts($f['ana']);assertSame(0,$c['needs_action']);assertSame(1,$c['waiting']);$c=(new Piscina\ParticipantActivity(service()))->counts($f['bruno']);assertSame(1,$c['needs_action']);assertSame(0,$c['waiting']);assertSame('end',activityRead($f['bruno'])['rows'][0]['kind']);
    op($f['bruno'],'galeria.link.end',['link_id'=>$a['link_id'],'confirm'=>true]);assertSame(0,count(activityRead($f['bruno'])['rows']));assertSame(0,count(walletRead($f['ana'])['activity']['relations']));
});
test('Carteira: mesmo dono não cria contraparte ou tarefa duplicada',function(){
    $f=msfixture();mspropose($f['ana'],msterms($f,['nft_id'=>(string)$f['anaNft']]));$a=walletRead($f['ana']);assertSame(1,count($a['activity']['relations']));assertSame(0,$a['activity']['counts']['needs_action']);assertSame(0,$a['activity']['counts']['waiting']);
});
test('Carteira: mudança de dono retira o patrimônio e bloqueia aceite antigo na apresentação',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$o=op($f['ana'],'galeria.offer',['frame_id'=>$f['frame']['frame_id'],'price'=>'50','asset'=>'MR','terms'=>'Revenda válida','confirm'=>true]);msbuy($f['caio'],$o,(string)$f['ana']);
    assertSame(0,count(walletRead($f['ana'])['activity']['frames']['rows']));assertSame(1,count(walletRead($f['caio'])['activity']['frames']['rows']));assertSame(0,count(activityRead($f['caio'])['rows']));$r=activityRead($f['bruno'])['rows'][0];check($r['stale'],'Proposta deve indicar a mudança.');check(!$r['can_accept'],'Aceite desatualizado não é oferecido.');fail(fn()=>msaccept($f['bruno'],$p['link_proposal_id']));
});
test('Carteira: IDs iguais de fontes distintas não colidem nem duplicam contadores',function(){
    $f=msfixture();mspropose($f['ana'],msterms($f));op($f['ana'],'galeria.offer',['frame_id'=>$f['frame']['frame_id'],'buyer_id'=>(string)$f['bruno'],'price'=>'100','asset'=>'MR','terms'=>'Oferta exclusiva','confirm'=>true]);
    $d=['frame_id'=>$f['frame']['frame_id'],'buyer_id'=>(string)$f['bruno'],'price'=>'125','asset'=>'MR','conditions'=>'Negociação válida','confirm'=>true];$v=(new Piscina\MolduraTrades(new Piscina\Galeria(service())))->preview($f['ana'],$d);$d['expires_at']=(new DateTimeImmutable($v['terms']['expires_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');op($f['ana'],'galeria.trade.propose',$d+['preview_hash'=>$v['preview_hash']]);
    $rows=activityRead($f['bruno'])['rows'];assertSame(3,count($rows));assertSame(3,count(array_unique(array_column($rows,'key'))));assertSame(3,(new Piscina\ParticipantActivity(service()))->counts($f['bruno'])['needs_action']);
});
test('Carteira: contadores são globais e paginação não repete negociações',function(){
    $f=msfixture();for($i=0;$i<27;$i++)mspropose($f['ana'],msterms($f,['conditions'=>'Proposta independente '.$i]));$first=activityRead($f['bruno']);$last=activityRead($f['bruno'],false,24);assertSame(24,count($first['rows']));assertSame(3,count($last['rows']));check($first['has_more'],'Próxima página.');assertSame(0,count(array_intersect(array_column($first['rows'],'key'),array_column($last['rows'],'key'))));assertSame(27,(new Piscina\ParticipantActivity(service()))->counts($f['bruno'])['needs_action']);
});
test('Carteira: leituras não criam cópias, lançamentos ou notificações e funcionam em stand-by',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));msaccept($f['bruno'],$p['link_proposal_id']);$before=[];foreach(['operations','ledger_entries','notifications','galeria_links','galeria_link_proposals'] as $table)$before[$table]=db()->row("SELECT COUNT(*) n FROM $table")['n'];service()->config['galeria']['enabled']=false;
    foreach([$f['ana'],$f['bruno'],$f['caio']] as $u){walletRead($u);activityRead($u);activityRead($u,true);}
    foreach($before as $table=>$n)assertSame($n,db()->row("SELECT COUNT(*) n FROM $table")['n']);assertSame(1,count(walletRead($f['ana'])['activity']['relations']));
});
test('Carteira: contrato avançado mantém cotas, regras e responsabilidade originais',function(){
    $f=gfixture(1,false);$r=$f['all'][0];ga('features',['physical'=>false,'events'=>false,'share_charges'=>false]);$p=activityRead($r['holder'])['rows'][0];assertSame('physical',$p['kind']);assertSame('2',$p['shares']);check($p['needs_action'],'Titular precisa confirmar versão antiga.');gaccept($r['holder'],$r['p']['proposal_id']);$a=walletRead($r['holder']);assertSame('physical',$a['activity']['relations'][0]['protocol']);assertSame('2',$a['activity']['relations'][0]['x']);assertSame('2',balance($r['holder'],'SHARE','reserved'));
});
test('Carteira: cancelamento avançado preserva confirmação bilateral e cotas do contrato',function(){
    $f=gfixture(1);$r=$f['all'][0];op($r['holder'],'galeria.cancel',['agreement_id'=>$r['a']['agreement_id'],'confirm'=>true]);
    $sender=activityRead($r['holder'])['rows'];$recipient=activityRead($r['owner'])['rows'];assertSame(1,count($sender));assertSame(1,count($recipient));assertSame('physical_end',$recipient[0]['kind']);check(!$sender[0]['needs_action']&&$recipient[0]['needs_action'],'Apenas a outra parte precisa confirmar.');assertSame('2',balance($r['holder'],'SHARE','reserved'));
    op($r['owner'],'galeria.cancel',['agreement_id'=>$r['a']['agreement_id'],'confirm'=>true]);assertSame(0,count(activityRead($r['holder'])['rows']));assertSame('0',balance($r['holder'],'SHARE','reserved'));assertSame('CANCELLED',activityRead($r['holder'],true)['rows'][0]['state']);
});
test('Carteira: oferta exclusiva enviada aguarda o comprador sem cobrar tarefa do vendedor',function(){
    $f=msfixture();$o=op($f['ana'],'galeria.offer',['frame_id'=>$f['frame']['frame_id'],'buyer_id'=>(string)$f['bruno'],'price'=>'90','asset'=>'MR','terms'=>'Oferta pessoal','confirm'=>true]);
    $sender=activityRead($f['ana'])['rows'][0];$recipient=activityRead($f['bruno'])['rows'][0];check(!$sender['needs_action']&&$sender['can_cancel']&&!$sender['can_accept'],'Remetente só acompanha ou cancela.');check($recipient['needs_action']&&!$recipient['can_cancel']&&$recipient['can_accept'],'Comprador decide se paga.');assertSame('BrunoNFT',$sender['next_user_name']);assertSame(0,(new Piscina\ParticipantActivity(service()))->counts($f['ana'])['needs_action']);assertSame(1,(new Piscina\ParticipantActivity(service()))->counts($f['ana'])['waiting']);
    op($f['ana'],'galeria.offer.cancel',['offer_id'=>$o['offer_id'],'confirm'=>true]);assertSame(0,count(activityRead($f['ana'])['rows']));assertSame('CANCELLED',activityRead($f['bruno'],true)['rows'][0]['state']);
});
