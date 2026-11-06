<?php
declare(strict_types=1);
require __DIR__.'/molduras-simples-fixtures.php';
test('Molduras simples: Admin cria só com nome, sem ponto, prazo, fotografia ou cotas',function(){
    $r=ga('frame.quick',['name'=>'Moldura mínima']);$f=db()->row('SELECT * FROM galeria_frames WHERE id=?',[$r['frame_id']]);assertSame('ACTIVE',$f['status']);assertSame('asset',json_decode($f['metadata'],true)['mode']);
    foreach(['galeria_allocations','galeria_points','galeria_spaces','galeria_policies','galeria_offers'] as $table)assertSame(0,db()->row("SELECT COUNT(*) n FROM $table")['n']);
    foreach((new Piscina\Galeria(service()))->features() as $enabled)check(!$enabled,'Recurso avançado deve começar em stand-by.');
});
test('Molduras simples: criação é exclusiva do Admin e compra continua paga',function(){
    [$u]=user('Participante');fail(fn()=>op($u,'admin.galeria.frame.quick',['name'=>'Alheia','confirm'=>true]),403);
    foreach(['0','-1',''] as $price)fail(fn()=>ga('frame.quick',['name'=>'Inválida','sale_mode'=>'public','price'=>$price,'asset'=>'MR']),422);
    assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_frames')['n']);assertSame('160000',balance($u,'MR'));
});
test('Molduras simples: oferta exclusiva não debita, não vaza preço e exige o destinatário',function(){
    ga('settings',['accepting'=>true]);
    [$a]=user('Ana');[$b]=user('Bia');$o=ga('frame.quick',['name'=>'Exclusiva','sale_mode'=>'direct','buyer_id'=>(string)$a,'price'=>'100','asset'=>'MR']);assertSame('160000',balance($a,'MR'));
    $q=new Piscina\GaleriaQueries(service());assertSame(0,count($q->frame((int)$o['frame_id'])['offers']));assertSame(0,count($q->frame((int)$o['frame_id'],$b)['offers']));assertSame(1,count($q->frame((int)$o['frame_id'],$a)['offers']));fail(fn()=>msbuy($b,$o),403);
    $k=testKey();$r=msbuy($a,$o,null,$k);assertSame($r['operation_id'],msbuy($a,$o,null,$k)['operation_id']);assertSame('150000',balance($a,'MR'));assertSame((string)$a,db()->row('SELECT holder_id FROM galeria_frames')['holder_id']);assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_sales')['n']);
});
test('Molduras simples: oferta sem vencimento permite compra concorrente consistente',function(){
    $f=msfixture(false);$o=$f['frame'];assertSame(null,db()->row('SELECT expires_at FROM galeria_offers WHERE id=?',[$o['offer_id']])['expires_at']);
    $d=['offer_id'=>$o['offer_id'],'version'=>$o['version'],'amount'=>$o['amount'],'asset'=>$o['asset'],'seller_id'=>null,'confirm'=>true];$calls=[];foreach([$f['ana'],$f['caio']] as $actor)$calls[]=['actor'=>$actor,'action'=>'galeria.buy','data'=>$d];$r=concurrent($calls);assertSame(1,count(array_filter($r,fn($v)=>$v['ok'])));assertSame('295000',bcadd(balance($f['ana'],'MR'),balance($f['caio'],'MR'),0));assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_sales')['n']);
});
test('Molduras simples: saldo insuficiente não transfere nem cobra parcialmente',function(){
    ga('settings',['accepting'=>true]);
    [$a]=user('Ana');$o=ga('frame.quick',['name'=>'Muito cara','sale_mode'=>'direct','buyer_id'=>(string)$a,'price'=>'1700','asset'=>'MR']);fail(fn()=>msbuy($a,$o));assertSame('160000',balance($a,'MR'));assertSame(null,db()->row('SELECT holder_id FROM galeria_frames')['holder_id']);assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_sales')['n']);
});
test('Molduras simples: titular propõe NFT de outro usuário sem cotas ou agenda',function(){
    $f=msfixture();$before=db()->row('SELECT COUNT(*) n FROM ledger_entries')['n'];$p=mspropose($f['ana'],msterms($f));$r=msaccept($f['bruno'],$p['link_proposal_id']);assertSame('ACTIVE',$r['state']);assertSame($before,db()->row('SELECT COUNT(*) n FROM ledger_entries')['n']);
    foreach([$f['ana'],$f['bruno']] as $u){assertSame('0',balance($u,'SHARE'));assertSame('0',balance($u,'SHARE','reserved'));}
    $a=db()->row('SELECT * FROM galeria_links');assertSame('7000',$a['nft_bps']);assertSame((string)$f['ana'],$a['frame_user']);assertSame((string)$f['bruno'],$a['nft_user']);assertSame((string)$f['bruno'],db()->row('SELECT owner_id FROM nfts WHERE id=?',[$f['brunoNft']])['owner_id']);assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_agreements')['n']);
});
test('Molduras simples: dono da NFT inicia; proponente cancela; terceiro não negocia',function(){
    $f=msfixture();$p=mspropose($f['bruno'],msterms($f));fail(fn()=>msaccept($f['caio'],$p['link_proposal_id']),403);$r=op($f['bruno'],'galeria.link.cancel',['link_proposal_id'=>$p['link_proposal_id'],'version'=>'1','confirm'=>true]);assertSame('CANCELLED',$r['state']);assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_links')['n']);
    $p=mspropose($f['bruno'],msterms($f));assertSame('ACTIVE',msaccept($f['ana'],$p['link_proposal_id'])['state']);
});
test('Molduras simples: contraproposta 40/60 exige novo aceite da versão exata',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$d=msterms($f,['link_proposal_id'=>$p['link_proposal_id'],'version'=>'1','frame_percent'=>'40','nft_percent'=>'60']);$v=mspropose($f['bruno'],$d);assertSame('2',$v['version']);fail(fn()=>msaccept($f['ana'],$p['link_proposal_id'],['version'=>'1']));assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_links')['n']);msaccept($f['ana'],$p['link_proposal_id']);assertSame('6000',db()->row('SELECT nft_bps FROM galeria_links')['nft_bps']);assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_link_acceptances WHERE version=1')['n']);
});
test('Molduras simples: mesmo dono confirma vínculo direto sem negociação fictícia',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f,['nft_id'=>(string)$f['anaNft'],'frame_percent'=>'0','nft_percent'=>'100']));assertSame('ACTIVE',$p['state']);assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_link_acceptances')['n']);$a=db()->row('SELECT * FROM galeria_links');assertSame($a['frame_user'],$a['nft_user']);assertSame('10000',$a['nft_bps']);op($f['ana'],'galeria.link.end',['link_id'=>$a['id'],'confirm'=>true]);assertSame('ENDED',db()->row('SELECT state FROM galeria_links')['state']);
});
test('Molduras simples: percentuais inválidos são recusados e 0/100 permanece livre',function(){
    $f=msfixture();foreach([['101','-1'],['30','69.99'],['50.001','49.999'],['0','0']] as [$fp,$np])fail(fn()=>mspreview($f['ana'],msterms($f,['frame_percent'=>$fp,'nft_percent'=>$np])),422);
    $p=mspropose($f['ana'],msterms($f,['frame_percent'=>'100','nft_percent'=>'0']));msaccept($f['bruno'],$p['link_proposal_id']);assertSame('0',db()->row('SELECT nft_bps FROM galeria_links')['nft_bps']);
});
test('Molduras simples: aceites concorrentes não associam a mesma Moldura duas vezes',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$q=mspropose($f['ana'],msterms($f,['nft_id'=>(string)$f['caioNft']]));$calls=[];
    foreach([[$p,$f['bruno']],[$q,$f['caio']]] as [$proposal,$actor]){$r=(new Piscina\MolduraLinks(new Piscina\Galeria(service())))->read($actor,(int)$proposal['link_proposal_id']);$calls[]=['actor'=>$actor,'action'=>'galeria.link.accept','data'=>['link_proposal_id'=>$r['id'],'version'=>$r['current_version'],'terms_hash'=>$r['versions'][0]['terms_hash'],'confirm'=>true,'license_confirm'=>true]];}
    $out=concurrent($calls);assertSame(1,count(array_filter($out,fn($v)=>$v['ok'])));assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_links')['n']);assertSame('0',balance($f['ana'],'SHARE','reserved'));
});
test('Molduras simples: repetição de aceite retorna o acordo sem duplicação',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$a=msaccept($f['bruno'],$p['link_proposal_id']);$b=msaccept($f['bruno'],$p['link_proposal_id']);assertSame($a['link_id'],$b['link_id']);assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_links')['n']);assertSame(2,db()->row('SELECT COUNT(*) n FROM galeria_link_acceptances')['n']);
});
test('Molduras simples: mesma NFT em aceites concorrentes tem uma única associação',function(){
    $f=msfixture();$other=ga('frame.quick',['name'=>'Segunda Moldura','sale_mode'=>'public','price'=>'100','asset'=>'MR']);msbuy($f['caio'],$other);
    $p=mspropose($f['ana'],msterms($f));$q=mspropose($f['caio'],msterms($f,['frame_id'=>$other['frame_id']]));$calls=[];
    foreach([$p,$q] as $item){$p=(new Piscina\MolduraLinks(new Piscina\Galeria(service())))->read($f['bruno'],(int)$item['link_proposal_id']);$calls[]=['actor'=>$f['bruno'],'action'=>'galeria.link.accept','data'=>['link_proposal_id'=>$p['id'],'version'=>$p['current_version'],'terms_hash'=>$p['versions'][0]['terms_hash'],'confirm'=>true,'license_confirm'=>true]];}
    $r=concurrent($calls);assertSame(1,count(array_filter($r,fn($v)=>$v['ok'])));assertSame(1,db()->row('SELECT COUNT(*) n FROM galeria_links')['n']);
});
test('Molduras simples: troca de titular durante proposta impede aceite desatualizado',function(){
    $f=msfixture();$p=mspropose($f['bruno'],msterms($f));$o=op($f['ana'],'galeria.offer',['frame_id'=>$f['frame']['frame_id'],'price'=>'50','asset'=>'MR','terms'=>'Revenda da titularidade','confirm'=>true]);msbuy($f['caio'],$o,(string)$f['ana']);fail(fn()=>msaccept($f['ana'],$p['link_proposal_id']));assertSame(0,db()->row('SELECT COUNT(*) n FROM galeria_links')['n']);
});
test('Molduras simples: revenda exige encerramento consensual sem apagar percentuais',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$a=msaccept($f['bruno'],$p['link_proposal_id']);$o=op($f['ana'],'galeria.offer',['frame_id'=>$f['frame']['frame_id'],'price'=>'100','asset'=>'MR','terms'=>'Revenda após encerramento','confirm'=>true]);$before=db()->row('SELECT terms FROM galeria_links')['terms'];fail(fn()=>msbuy($f['caio'],$o,(string)$f['ana']));assertSame('ACTIVE',db()->row('SELECT state FROM galeria_links')['state']);
    $r=op($f['ana'],'galeria.link.end',['link_id'=>$a['link_id'],'confirm'=>true]);assertSame('END_REQUESTED',$r['state']);fail(fn()=>msbuy($f['caio'],$o,(string)$f['ana']));op($f['bruno'],'galeria.link.end',['link_id'=>$a['link_id'],'confirm'=>true]);msbuy($f['caio'],$o,(string)$f['ana']);assertSame($before,db()->row('SELECT terms FROM galeria_links')['terms']);assertSame('7000',db()->row('SELECT nft_bps FROM galeria_links')['nft_bps']);assertSame('ENDED',db()->row('SELECT state FROM galeria_links')['state']);
});
test('Molduras simples: depósito normal suspende associação e preserva termos e mídia',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));msaccept($f['bruno'],$p['link_proposal_id']);$a=db()->row('SELECT * FROM galeria_links');$path=json_decode($a['terms'],true)['image']['image_path'];op($f['bruno'],'nft.deposit',['nft_id'=>(string)$f['brunoNft']]);$after=db()->row('SELECT * FROM galeria_links');assertSame('SUSPENDED',$after['state']);assertSame($a['terms'],$after['terms']);check(Piscina\ImageReferences::used(db(),$path),'Imagem do histórico deve continuar referenciada.');assertSame('1000000000',balance($f['bruno'],'BYC'));ga('link.resolve',['link_id'=>$a['id']]);assertSame('ENDED',db()->row('SELECT state FROM galeria_links')['state']);
});
test('Molduras simples: consulta e encerramento continuam com novas contratações pausadas',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));$a=msaccept($f['bruno'],$p['link_proposal_id']);ga('settings',['accepting'=>false]);service()->config['galeria']['enabled']=false;
    check((new Piscina\MolduraLinks(new Piscina\Galeria(service())))->read($f['ana'],(int)$p['link_proposal_id'])['link']!==null,'Acordo consultável');op($f['ana'],'galeria.link.end',['link_id'=>$a['link_id'],'confirm'=>true]);op($f['bruno'],'galeria.link.end',['link_id'=>$a['link_id'],'confirm'=>true]);assertSame('ENDED',db()->row('SELECT state FROM galeria_links')['state']);
});
test('Molduras simples: ativar opções avançadas não cria cobrança nem altera acordo',function(){
    $f=msfixture();$p=mspropose($f['ana'],msterms($f));msaccept($f['bruno'],$p['link_proposal_id']);$before=db()->row('SELECT * FROM galeria_links');$count=db()->row('SELECT COUNT(*) n FROM ledger_entries')['n'];ga('features',['physical'=>true,'events'=>true,'share_charges'=>true]);assertSame(json_encode($before),json_encode(db()->row('SELECT * FROM galeria_links')));assertSame($count,db()->row('SELECT COUNT(*) n FROM ledger_entries')['n']);
});
test('Molduras simples: recursos em stand-by preservam proposta antiga e cobrança já aceita',function(){
    $f=gfixture(1,false);$r=$f['all'][0];$terms=db()->row('SELECT terms FROM galeria_proposal_versions WHERE proposal_id=?',[$r['p']['proposal_id']])['terms'];ga('features',['physical'=>false,'events'=>false,'share_charges'=>false]);gaccept($r['holder'],$r['p']['proposal_id']);assertSame($terms,db()->row('SELECT terms FROM galeria_agreements')['terms']);assertSame('2',balance($r['holder'],'SHARE','reserved'));ga('event.cancel',['event_id'=>$f['event']['event_id']]);assertSame('0',balance($r['holder'],'SHARE','reserved'));assertSame('2',balance($r['holder'],'SHARE'));
});
test('Molduras simples: consultas separam privacidade, disponibilidade e titularidade',function(){
    $f=msfixture();$assets=(new Piscina\MolduraSimpleQueries(service()))->assets($f['ana'],['q'=>'BrunoNFT']);assertSame((string)$f['brunoNft'],$assets['rows'][0]['id']);$p=mspropose($f['ana'],msterms($f));msaccept($f['bruno'],$p['link_proposal_id']);$public=(new Piscina\GaleriaQueries(service()))->frame((int)$f['frame']['frame_id']);check(!str_contains(json_encode($public),'image_path'),'Catálogo não expõe caminho privado.');check(!isset($public['simple_link']['nft_bps']),'Percentuais privados das partes.');$private=(new Piscina\GaleriaQueries(service()))->frame((int)$f['frame']['frame_id'],$f['ana']);assertSame('7000',$private['simple_link']['nft_bps']);assertSame((string)$f['ana'],$private['holder_id']);
});
test('Molduras simples: consulta de revenda retorna aceites e permite contraproposta e compra',function(){
    $f=msfixture();$trade=new Piscina\MolduraTrades(new Piscina\Galeria(service()));
    $d=['frame_id'=>$f['frame']['frame_id'],'buyer_id'=>(string)$f['bruno'],'price'=>'123.45','asset'=>'MR','conditions'=>'Revenda livre da titularidade','confirm'=>true];
    $preview=$trade->preview($f['bruno'],$d);$d['expires_at']=(new DateTimeImmutable($preview['terms']['expires_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');$d['preview_hash']=$preview['preview_hash'];$p=op($f['bruno'],'galeria.trade.propose',$d);
    $read=$trade->read($f['ana'],(int)$p['trade_id']);assertSame(1,count($read['acceptances']));assertSame((string)$f['bruno'],$read['acceptances'][0]['user_id']);assertSame('1',$read['acceptances'][0]['version']);fail(fn()=>$trade->read($f['caio'],(int)$p['trade_id']),403);
    $counter=$d;$counter['trade_id']=$p['trade_id'];$counter['version']='1';$counter['price']='135.79';$preview=$trade->preview($f['ana'],$counter);$counter['preview_hash']=$preview['preview_hash'];op($f['ana'],'galeria.trade.counter',$counter);
    $read=$trade->read($f['bruno'],(int)$p['trade_id']);assertSame(1,count(array_filter($read['acceptances'],fn($a)=>$a['version']==='2')));assertSame((string)$f['ana'],array_values(array_filter($read['acceptances'],fn($a)=>$a['version']==='2'))[0]['user_id']);
    op($f['bruno'],'galeria.trade.accept',['trade_id'=>$p['trade_id'],'version'=>'2','terms_hash'=>$read['versions'][0]['terms_hash'],'confirm'=>true]);$read=$trade->read($f['bruno'],(int)$p['trade_id']);assertSame('SETTLED',$read['state']);assertSame(2,count(array_filter($read['acceptances'],fn($a)=>$a['version']==='2')));assertSame((string)$f['bruno'],db()->row('SELECT holder_id FROM galeria_frames')['holder_id']);
});
