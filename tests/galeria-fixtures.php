<?php
declare(strict_types=1);
// Incluído somente por testes/CLI de demonstração, após bootstrap.
if(PHP_SAPI!=='cli'||config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Fixtures Galeria exigem demo e banco descartável piscina_test.');
function ga(string $action,array $d=[]): array {return op(1,'admin.galeria.'.$action,['reason'=>'Configuração local da Galeria','confirm'=>true]+$d);}
function gd(string $when): string {return (new DateTimeImmutable(db()->now(),new DateTimeZone('UTC')))->modify($when)->format('Y-m-d\TH:i:s.u\Z');}
function gclock(string $time): void {db()->pdo->exec('SET timestamp='.(new DateTimeImmutable($time,new DateTimeZone('UTC')))->getTimestamp()+1);}
function gimage(int $u,int $n): void {
    $dir=config()['storage'].'/images';if(!is_dir($dir))mkdir($dir,0700,true);$name=bin2hex(random_bytes(16));$im=imagecreatetruecolor(120,100);imagefill($im,0,0,imagecolorallocate($im,37,110,86));imagejpeg($im,$dir.'/'.$name.'.jpg');imagejpeg($im,$dir.'/'.$name.'-thumb.jpg');imagedestroy($im);
    op($u,'nft.image',['nft_id'=>(string)$n,'image_path'=>$name.'.jpg','thumbnail_path'=>$name.'-thumb.jpg','image_sha'=>hash_file('sha256',$dir.'/'.$name.'.jpg')]);
}
function gshares(int $u,int $count=2): void {
    $n=(int)op(1,'admin.mint',['user_id'=>(string)$u,'title'=>'Obra de teste para ciclos de cotas','reason'=>'Fixture isolada de Galeria'])['nft_id'];
    for($i=0;$i<$count;$i++){op($u,'nft.deposit',['nft_id'=>(string)$n]);withdraw($u,$n);}
}
function gmetadata(): array {return ['description'=>'Moldura da demonstração local','equipment'=>'Tela digital','condition'=>'Verificada','width'=>'60','height'=>'40','unit'=>'cm','orientation'=>'landscape','resolution'=>'1920x1080','formats'=>'JPEG','installation'=>'Suporte e energia conferidos','rights'=>'Direito de operar no ponto; propriedade física do equipamento não é transferida','delivery'=>'Uso local conforme autorização publicada'];}
function gframe(int $point,int $holder,int $i=1,string $price='250.00'): array {
    $f=ga('frame.save',['code'=>'DEMO-'.$i,'name'=>'Moldura '.$i,'type_key'=>'digital','metadata'=>gmetadata()]);
    ga('frame.allocate',['frame_id'=>$f['frame_id'],'point_id'=>(string)$point,'starts_at'=>gd('+1 second'),'ends_at'=>gd('+30 days')]);ga('frame.verify',['frame_id'=>$f['frame_id']]);
    $offer=ga('offer',['frame_id'=>$f['frame_id'],'asset'=>'MR','price'=>$price,'expires_at'=>gd('+20 days'),'terms'=>'Aquisição do direito de operação, sem transferência do equipamento ou da autorização física']);
    op($holder,'galeria.buy',['offer_id'=>$offer['offer_id'],'version'=>$offer['version'],'amount'=>$offer['amount'],'asset'=>'MR','seller_id'=>null,'confirm'=>true]);return $f+$offer;
}
function gpreview(int $actor,array $d): array {return (new Piscina\Coalizoes(new Piscina\Galeria(service())))->preview($actor,$d);}
function gpropose(int $actor,array $d): array {$p=gpreview($actor,$d);return op($actor,'galeria.propose',$d+['preview_hash'=>$p['preview_hash']]);}
function gaccept(int $actor,string $id,?string $key=null): array {
    $p=(new Piscina\GaleriaQueries(service()))->proposal($actor,(int)$id);$v=$p['versions'][0];return op($actor,'galeria.accept',['proposal_id'=>$id,'version'=>$p['current_version'],'terms_hash'=>$v['terms_hash'],'confirm'=>true,'license_confirm'=>true],$key);
}
function gfixture(int $count=4,bool $activate=true,string $prize='1000'): array {
    service()->config['galeria']=['enabled'=>true];ga('settings',['accepting'=>true]);op(1,'admin.parameters',['initial_credit'=>'10000.00','reason'=>'Fixture isolada; não é parâmetro comercial']);
    [$recipient]=user('ResponsavelGaleria');$s=ga('space.save',['name'=>'Espaço piloto','address'=>'Endereço público da demonstração','responsible'=>'Responsável local']);$space=(int)$s['space_id'];
    $policy=ga('policy',['space_id'=>(string)$space,'type_key'=>'digital','x'=>'2','recipient_id'=>(string)$recipient,'rights_ack'=>true]);
    $event=ga('event.save',['title'=>'Mostra piloto','voting_starts'=>gd('+2 hours'),'voting_ends'=>gd('+3 hours'),'review_hours'=>'24','prize_asset'=>'MR','prize'=>$prize,'rules'=>'Quatro exposições; voto gratuito credenciado; empates compartilham prêmio; interrupção devolve integralmente.']);ga('event.publish',['event_id'=>$event['event_id']]);
    $window=db()->row('SELECT voting_starts,voting_ends FROM galeria_events WHERE id=?',[$event['event_id']]);$iso=fn($v)=>(new DateTimeImmutable($v,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    $all=[];$percent=['60','45','70','25'];
    for($i=0;$i<$count;$i++){
        [$holder]=user('Titular'.$i);[$owner,$nft]=user('Artista'.$i);gimage($owner,$nft);gshares($holder);gshares($owner);
        $point=ga('point.save',['space_id'=>(string)$space,'name'=>'Ponto '.($i+1),'authorized_from'=>gd('-1 day'),'authorized_until'=>gd('+40 days'),'conditions'=>'Energia, segurança e acesso conferidos']);
        $frame=gframe((int)$point['point_id'],$holder,$i+1,$prize==='1000'?'250.00':'250.01');$d=['frame_id'=>$frame['frame_id'],'nft_id'=>(string)$nft,'starts_at'=>$iso($window['voting_starts']),'ends_at'=>$iso($window['voting_ends']),'expires_at'=>gd('+1 hour'),'policy_id'=>$policy['policy_id'],'nft_percent'=>$percent[$i],'frame_percent'=>(string)(100-(int)$percent[$i]),'event_id'=>$event['event_id'],'obligations'=>'Titular instala; detentor autoriza a imagem e créditos.','confirm'=>true,'license_confirm'=>true];
        $p=gpropose($owner,$d);$a=$activate?gaccept($holder,$p['proposal_id']):null;$all[]=compact('holder','owner','nft','frame','d','p','a');
    }
    return ['space'=>$space,'recipient'=>$recipient,'policy'=>$policy,'event'=>$event,'all'=>$all];
}
function gprepare(array $f): void {foreach($f['all'] as $r)ga('exposure.confirm',['agreement_id'=>$r['a']['agreement_id']]);ga('event.fund',['event_id'=>$f['event']['event_id'],'source'=>'galeria_revenue']);ga('event.prepare',['event_id'=>$f['event']['event_id']]);}
function gvoteFixture(array $f,array $choices=[0,0,1]): array {
    $ids=db()->all('SELECT id FROM galeria_candidates WHERE event_id=? ORDER BY id',[$f['event']['event_id']]);$electors=[];
    foreach($choices as $i=>$choice){[$u]=user('Eleitor'.$i);ga('event.credential',['event_id'=>$f['event']['event_id'],'user_id'=>(string)$u,'credential_ref'=>'presenca-fixture-'.$i]);$electors[]=[$u,$ids[$choice]['id']];}
    $e=db()->row('SELECT * FROM galeria_events WHERE id=?',[$f['event']['event_id']]);gclock($e['voting_starts']);ga('event.open',['event_id'=>$e['id']]);foreach($electors as [$u,$c])op($u,'galeria.vote',['event_id'=>$e['id'],'candidate_id'=>$c,'confirm'=>true]);return $electors;
}
function gfinish(array $f,bool $finalize=true): void {
    $e=db()->row('SELECT * FROM galeria_events WHERE id=?',[$f['event']['event_id']]);gclock($e['voting_ends']);ga('event.close',['event_id'=>$e['id']]);foreach($f['all'] as $r)ga('exposure.complete',['agreement_id'=>$r['a']['agreement_id']]);
    if($finalize){$e=db()->row('SELECT * FROM galeria_events WHERE id=?',[$e['id']]);gclock($e['review_until']);ga('event.finalize',['event_id'=>$e['id']]);}
}
