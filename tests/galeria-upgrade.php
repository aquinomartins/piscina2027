<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Use somente ambiente isolado de testes.');
db()->pdo->exec('CREATE DATABASE IF NOT EXISTS piscina_galeria_upgrade_test CHARACTER SET utf8mb4');
$c=config();$c['database']['dsn']=preg_replace('/dbname=piscina_test(?:;|$)/','dbname=piscina_galeria_upgrade_test;',$c['database']['dsn']);$c['galeria']=['enabled'=>false];$db=new Piscina\Database($c['database']);
if($db->all('SHOW TABLES'))throw new RuntimeException('Banco de upgrade deve estar vazio. Este script não apaga tabelas.');
function migration(Piscina\Database $db,string $name): void {foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/migrations/'.$name.'.sql'))as$sql)if(trim($sql)!=='')$db->pdo->exec($sql);}
foreach(['001_initial','002_http_tasks','003_admin_danger'] as $version)migration($db,$version);
$installer=new Piscina\Installer($db,$c);$admin=(int)$installer->admin('admin-upgrade@local.invalid','Admin upgrade',getenv('PISCINA_DEMO_ADMIN_PASSWORD'));
$d=new Piscina\Domain($db,$c);$d->operation($admin,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT'],bin2hex(random_bytes(16)));
$db->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',['participante-upgrade@local.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),'Participante original']);$u=(int)$db->pdo->lastInsertId();$d->operation($admin,'admin.approve',['user_id'=>(string)$u],bin2hex(random_bytes(16)));
$before=[];foreach($db->all('SHOW TABLES') as $r){$t=reset($r);if($t==='schema_migrations')continue;$rows=array_map(fn($v)=>json_encode($v,JSON_THROW_ON_ERROR),$db->all("SELECT * FROM `$t`"));sort($rows);$before[$t]=hash('sha256',implode("\n",$rows));}
migration($db,'004_galeria');migration($db,'004_galeria');
foreach($before as $t=>$hash){$rows=array_map(fn($v)=>json_encode($v,JSON_THROW_ON_ERROR),$db->all("SELECT * FROM `$t`"));sort($rows);if($hash!==hash('sha256',implode("\n",$rows)))throw new RuntimeException('Tabela antiga alterada: '.$t);}
foreach($d->ledger->reconcile() as $kind=>$rows)if($rows)throw new RuntimeException('Conciliação divergente: '.$kind);
if($db->row('SELECT accepting FROM galeria_settings WHERE id=1')['accepting']!=='0')throw new RuntimeException('O módulo não pode abrir automaticamente.');
echo 'PASS Migração 004 aplicada duas vezes; '.count($before).' tabelas antigas preservadas, saldo e NFT originais intactos; conciliação vazia e entrada comercial suspensa.'.PHP_EOL;
