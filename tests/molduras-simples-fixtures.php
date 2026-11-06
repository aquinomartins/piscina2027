<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Use somente demo e piscina_test descartável.');
function msfixture(bool $buy=true): array {
    service()->config['galeria']=['enabled'=>true];ga('settings',['accepting'=>true]);
    [$ana,$anaNft]=user('AnaMoldura');[$bruno,$brunoNft]=user('BrunoNFT');[$caio,$caioNft]=user('CaioParticipante');
    gimage($ana,$anaNft);gimage($bruno,$brunoNft);gimage($caio,$caioNft);
    $frame=ga('frame.quick',['name'=>'Moldura 012','sale_mode'=>'public','price'=>'250.00','asset'=>'MR']);
    if($buy)msbuy($ana,$frame);
    return compact('ana','anaNft','bruno','brunoNft','caio','caioNft','frame');
}
function msbuy(int $actor,array $o,?string $seller=null,?string $key=null): array {
    return op($actor,'galeria.buy',['offer_id'=>$o['offer_id'],'version'=>$o['version'],'amount'=>$o['amount'],'asset'=>$o['asset'],'seller_id'=>$seller,'confirm'=>true],$key);
}
function msterms(array $f,array $overrides=[]): array {
    return $overrides+['frame_id'=>$f['frame']['frame_id'],'nft_id'=>(string)$f['brunoNft'],'frame_percent'=>'30','nft_percent'=>'70','conditions'=>'Parceria simples da turma','confirm'=>true,'license_confirm'=>true];
}
function mspreview(int $actor,array $d): array {return db()->transaction(function()use($actor,$d){db()->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return (new Piscina\MolduraLinks(new Piscina\Galeria(service())))->preview($actor,$d);});}
function mspropose(int $actor,array $d,?string $key=null): array {
    $p=mspreview($actor,$d);$d['expires_at']=(new DateTimeImmutable($p['terms']['expires_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');$d['preview_hash']=$p['preview_hash'];return op($actor,isset($d['link_proposal_id'])?'galeria.link.counter':'galeria.link.propose',$d,$key);
}
function msaccept(int $actor,int|string $id,array $extra=[]): array {
    $p=(new Piscina\MolduraLinks(new Piscina\Galeria(service())))->read($actor,(int)$id);
    return op($actor,'galeria.link.accept',$extra+['link_proposal_id'=>(string)$id,'version'=>$p['current_version'],'terms_hash'=>$p['versions'][0]['terms_hash'],'confirm'=>true,'license_confirm'=>true]);
}
