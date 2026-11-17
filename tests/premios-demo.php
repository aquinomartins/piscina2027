<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!str_contains(config()['database']['dsn'],'dbname=piscina_prize_ui;'))throw new RuntimeException('Somente demo e piscina_prize_ui descartável.');
foreach(['PISCINA_DEMO_ADMIN_PASSWORD','PISCINA_DEMO_PASSWORD'] as $key)if(strlen(getenv($key)?:'')<12)throw new RuntimeException('Defina as senhas locais fora do código.');
(new Piscina\Installer(db(),config()))->migrate();
if(db()->row('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Banco precisa estar vazio. Este gerador não apaga dados.');
function po(int $u,string $action,array $d): array {return service()->operation($u,$action,$d,bin2hex(random_bytes(16)));}
function pa(string $action,array $d): array {return po(1,'admin.galeria.'.$action,$d+['reason'=>'Demonstração isolada de prêmios','confirm'=>true]);}
function pu(string $name): array {
    db()->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[strtolower($name).'@local.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),$name]);$id=(int)db()->pdo->lastInsertId();$r=po(1,'admin.approve',['user_id'=>(string)$id]);$n=(int)$r['nft_id'];
    $dir=config()['storage'].'/images';if(!is_dir($dir))mkdir($dir,0700,true);$file=bin2hex(random_bytes(16));$im=imagecreatetruecolor(32,32);imagefill($im,0,0,imagecolorallocate($im,38,110,84));imagejpeg($im,$dir.'/'.$file.'.jpg');imagejpeg($im,$dir.'/'.$file.'-thumb.jpg');imagedestroy($im);
    po($id,'nft.image',['nft_id'=>(string)$n,'image_path'=>$file.'.jpg','thumbnail_path'=>$file.'-thumb.jpg','image_sha'=>hash_file('sha256',$dir.'/'.$file.'.jpg')]);return [$id,$n];
}
(new Piscina\Installer(db(),config()))->admin('admin@demo.invalid','Administração',getenv('PISCINA_DEMO_ADMIN_PASSWORD'));
po(1,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT']);pa('settings',['accepting'=>true]);
[$ana,$anaNft]=pu('AnaMoldura');[$bruno,$brunoNft]=pu('BrunoNFT');[$caio,$caioNft]=pu('Caio');
$frame=pa('frame.quick',['name'=>'Moldura da turma','sale_mode'=>'public','asset'=>'MR','price'=>'250']);
po($ana,'galeria.buy',['offer_id'=>$frame['offer_id'],'version'=>$frame['version'],'amount'=>$frame['amount'],'asset'=>$frame['asset'],'seller_id'=>null,'confirm'=>true]);
$data=['frame_id'=>$frame['frame_id'],'nft_id'=>(string)$brunoNft,'frame_percent'=>'30','nft_percent'=>'70','confirm'=>true,'license_confirm'=>true];
$preview=db()->transaction(function()use($ana,$data){db()->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return (new Piscina\MolduraLinks(new Piscina\Galeria(service())))->preview($ana,$data);});
$data['expires_at']=(new DateTimeImmutable($preview['terms']['expires_at'],new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');$data['preview_hash']=$preview['preview_hash'];$proposal=po($ana,'galeria.link.propose',$data);
$read=(new Piscina\MolduraLinks(new Piscina\Galeria(service())))->read($bruno,(int)$proposal['link_proposal_id']);
$link=po($bruno,'galeria.link.accept',['link_proposal_id'=>$proposal['link_proposal_id'],'version'=>$read['current_version'],'terms_hash'=>$read['versions'][0]['terms_hash'],'confirm'=>true,'license_confirm'=>true]);
$existing=pa('prize.save',['title'=>'Prêmio já cadastrado','asset'=>'MR','amount'=>'42']);
echo json_encode(compact('ana','bruno','caio','frame','link','existing'),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
