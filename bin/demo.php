<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!in_array('--confirm-demo',$argv,true))throw new RuntimeException('Use environment=demo e --confirm-demo. Nunca execute em produção.');
$adminPassword=getenv('PISCINA_DEMO_ADMIN_PASSWORD');$userPassword=getenv('PISCINA_DEMO_PASSWORD');if(!$adminPassword||!$userPassword||strlen($userPassword)<12)throw new RuntimeException('Defina senhas locais PISCINA_DEMO_ADMIN_PASSWORD e PISCINA_DEMO_PASSWORD fora do código.');
if(db()->row('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Demonstração exige banco vazio.');
$admin=(int)(new Piscina\Installer(db(),config()))->admin('admin@demo.invalid','Administração',$adminPassword);
$op=fn($actor,$action,$data)=>service()->operation($actor,$action,$data,bin2hex(random_bytes(16)));
$op($admin,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT']);
$artists=['Marina Costa','Rafael Nunes','Lia Santos','Pedro Lima'];$titles=['Equilíbrio orgânico','Entre marés','Horizonte de silêncio','Geometria do afeto','Campo sensível','Fragmentos de luz','Ritmo em terra','Corpo celeste'];$ids=[];
foreach($artists as $i=>$name){db()->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',['artista'.($i+1).'@demo.invalid',password_hash($userPassword,PASSWORD_DEFAULT),$name]);$u=(int)db()->pdo->lastInsertId();$n=(int)$op($admin,'admin.approve',['user_id'=>(string)$u])['nft_id'];db()->run('UPDATE nfts SET title=? WHERE id=?',[$titles[$i],$n]);$ids[]=[$u,$n];}
foreach($artists as $i=>$name){$u=$ids[$i][0];$n=(int)$op($admin,'admin.mint',['user_id'=>(string)$u,'title'=>$titles[$i+4],'reason'=>'Obra do perfil local de demonstração'])['nft_id'];$ids[]=[$u,$n];}
$colors=[[175,142,101],[55,95,107],[163,111,90],[82,97,68],[140,158,106],[184,130,84],[119,71,55],[39,71,63]];
$dir=config()['storage'].'/images';if(!is_dir($dir))mkdir($dir,0700,true);
foreach($ids as $i=>[$u,$n]){
 $size=600;$img=imagecreatetruecolor($size,$size);[$r,$g,$b]=$colors[$i];imagefill($img,0,0,imagecolorallocate($img,min(255,$r+28),min(255,$g+26),min(255,$b+20)));
 if($i%3===0){for($j=10;$j>=0;$j--){$shade=imagecolorallocate($img,max(0,$r-$j*3),max(0,$g-$j*3),max(0,$b-$j*3));imagefilledellipse($img,290+$j*4,315+$j*3,220+$j*15,300+$j*8,$shade);}imagefilledellipse($img,205,250,100,140,imagecolorallocate($img,min(255,$r+40),min(255,$g+32),min(255,$b+28)));}
 elseif($i%3===1){for($j=0;$j<10;$j++){imagefilledellipse($img,40+$j*85,460-$j*43,700,220,imagecolorallocate($img,min(255,$r+$j*8),min(255,$g+$j*6),min(255,$b+$j*6)));}}
 else{imagefilledrectangle($img,80,100,520,495,imagecolorallocate($img,max(0,$r-35),max(0,$g-30),max(0,$b-20)));imagefilledrectangle($img,130,150,450,520,imagecolorallocate($img,min(255,$r+20),min(255,$g+15),min(255,$b+8)));imagefilledellipse($img,300,275,250,250,imagecolorallocate($img,max(0,$r-10),max(0,$g-6),$b));}
 $name=bin2hex(random_bytes(16));imagejpeg($img,$dir.'/'.$name.'.jpg',90);$thumb=imagescale($img,480);imagejpeg($thumb,$dir.'/'.$name.'-thumb.jpg',86);imagedestroy($thumb);imagedestroy($img);
 $op($u,'nft.image',['nft_id'=>(string)$n,'image_path'=>$name.'.jpg','thumbnail_path'=>$name.'-thumb.jpg','image_sha'=>hash_file('sha256',$dir.'/'.$name.'.jpg')]);
 if($i<6)$op($u,'nft.deposit',['nft_id'=>(string)$n]);
 else $op($u,'listing.create',['side'=>'SELL','asset'=>'NFT','nft_id'=>(string)$n,'quantity'=>'1','unit_price'=>'380.00','expires_at'=>(new DateTimeImmutable())->modify('+6 days')->format('c')]);
}
$op($ids[0][0],'listing.create',['side'=>'SELL','asset'=>'BYC','quantity'=>'3','unit_price'=>'30.00','expires_at'=>(new DateTimeImmutable())->modify('+6 days')->format('c')]);
$op($ids[1][0],'listing.create',['side'=>'SELL','asset'=>'SHARE','quantity'=>'1','unit_price'=>'60.00','expires_at'=>(new DateTimeImmutable())->modify('+6 days')->format('c')]);
echo "Demonstração criada: admin@demo.invalid e artista1..4@demo.invalid. Senhas somente nas variáveis locais. Primeiro corte não configurado.\n";
