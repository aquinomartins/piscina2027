<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Use exclusivamente ambiente local de teste.');
db()->pdo->exec('CREATE DATABASE IF NOT EXISTS piscina_molduras_upgrade CHARACTER SET utf8mb4');
$cfg=config();$cfg['database']['dsn']=preg_replace('/dbname=piscina_test(?:;|$)/','dbname=piscina_molduras_upgrade;',$cfg['database']['dsn']);
$b=new Piscina\Database($cfg['database']);if($b->all('SHOW TABLES'))throw new RuntimeException('O banco de upgrade precisa estar vazio. Este script não apaga registros.');
$migration=function(string $name)use($b){foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/migrations/'.$name.'.sql')) as $sql)if(trim($sql)!=='')$b->pdo->exec($sql);};
foreach(['001_initial','002_http_tasks','003_admin_danger','004_galeria'] as $name)$migration($name);
$d=new Piscina\Domain($b,$cfg);$i=new Piscina\Installer($b,$cfg);$admin=(int)$i->admin('admin-upgrade@local.invalid','Admin de teste',getenv('PISCINA_DEMO_ADMIN_PASSWORD'));
$op=fn(int $a,string $action,array $data)=>$d->operation($a,$action,$data,bin2hex(random_bytes(16)));
$ga=fn(string $action,array $data)=>$op($admin,'admin.galeria.'.$action,$data+['confirm'=>true,'reason'=>'Fixture local de upgrade']);
$op($admin,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT']);$ga('settings',['accepting'=>true]);$people=[];
foreach(['NFT','Moldura'] as $name){$b->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[strtolower($name).'@upgrade.local.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),$name]);$u=(int)$b->pdo->lastInsertId();$grant=$op($admin,'admin.approve',['user_id'=>(string)$u]);$people[$name]=[$u,(int)$grant['nft_id']];}
[$nu,$nft]=$people['NFT'];[$fu]=$people['Moldura'];
$dir=$cfg['storage'].'/images';if(!is_dir($dir))mkdir($dir,0700,true);$path=bin2hex(random_bytes(16));$im=imagecreatetruecolor(120,100);imagejpeg($im,$dir.'/'.$path.'.jpg');imagejpeg($im,$dir.'/'.$path.'-thumb.jpg');imagedestroy($im);
$op($nu,'nft.image',['nft_id'=>(string)$nft,'image_path'=>$path.'.jpg','thumbnail_path'=>$path.'-thumb.jpg','image_sha'=>hash_file('sha256',$dir.'/'.$path.'.jpg')]);
require __DIR__.'/galeria-fixtures.php';
$date=fn(string $when)=>(new DateTimeImmutable($when,new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.000000\Z');
$space=$ga('space.save',['name'=>'Espaço original','address'=>'Endereço sintético','responsible'=>'Admin local']);
$point=$ga('point.save',['space_id'=>$space['space_id'],'name'=>'Ponto original','authorized_from'=>$date('-1 day'),'authorized_until'=>$date('+30 days'),'conditions'=>'Condições originais']);
$frame=$ga('frame.save',['code'=>'ORIGINAL-004','name'=>'Moldura anterior','type_key'=>'digital','metadata'=>gmetadata()]);
$ga('frame.allocate',['frame_id'=>$frame['frame_id'],'point_id'=>$point['point_id'],'starts_at'=>$date('+1 hour'),'ends_at'=>$date('+20 days')]);$ga('frame.verify',['frame_id'=>$frame['frame_id']]);
$offer=$ga('offer',['frame_id'=>$frame['frame_id'],'price'=>'100','asset'=>'MR','terms'=>'Condições da versão anterior','expires_at'=>$date('+7 days')]);
$op($fu,'galeria.buy',['offer_id'=>$offer['offer_id'],'version'=>$offer['version'],'amount'=>$offer['amount'],'asset'=>'MR','seller_id'=>null,'confirm'=>true]);
$policy=$ga('policy',['space_id'=>$space['space_id'],'type_key'=>'digital','x'=>'2','recipient_id'=>(string)$nu,'rights_ack'=>true]);
$terms=['frame_id'=>$frame['frame_id'],'nft_id'=>(string)$nft,'starts_at'=>$date('+2 days'),'ends_at'=>$date('+3 days'),'expires_at'=>$date('+1 day'),'policy_id'=>$policy['policy_id'],'frame_percent'=>'30','nft_percent'=>'70','obligations'=>'Contrato anterior preservado','confirm'=>true,'license_confirm'=>true];
$preview=$b->transaction(function()use($b,$d,$nu,$terms){$b->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return (new Piscina\Coalizoes(new Piscina\Galeria($d)))->preview($nu,$terms);});
$op($nu,'galeria.propose',$terms+['preview_hash'=>$preview['preview_hash']]);
$hashes=[];foreach($b->all('SHOW TABLES') as $r){$table=reset($r);if($table==='schema_migrations')continue;$rows=array_map(fn($v)=>json_encode($v,JSON_THROW_ON_ERROR),$b->all("SELECT * FROM `$table`"));sort($rows);$hashes[$table]=hash('sha256',implode("\n",$rows));}
$migration('005_molduras_simples');$migration('005_molduras_simples');
foreach($hashes as $table=>$before){$rows=array_map(fn($v)=>json_encode($v,JSON_THROW_ON_ERROR),$b->all("SELECT * FROM `$table`"));sort($rows);if($before!==hash('sha256',implode("\n",$rows)))throw new RuntimeException('Dados anteriores alterados: '.$table);}
foreach((new Piscina\Galeria($d))->features() as $enabled)if($enabled)throw new RuntimeException('Recurso avançado habilitado sem consentimento.');
foreach($d->ledger->reconcile() as $kind=>$rows)if($rows)throw new RuntimeException('Conciliação divergente: '.$kind);
echo 'PASS Migração 005 aplicada duas vezes; '.count($hashes).' tabelas anteriores preservadas, com carteiras, NFT, Moldura, oferta, política e proposta 004 reais de teste. Recursos avançados em stand-by; conciliação vazia.'.PHP_EOL;
