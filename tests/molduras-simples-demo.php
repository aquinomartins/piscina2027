<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))throw new RuntimeException('Use exclusivamente demo e piscina_test vazio.');
foreach(['PISCINA_DEMO_ADMIN_PASSWORD','PISCINA_DEMO_PASSWORD'] as $key)if(strlen(getenv($key)?:'')<12)throw new RuntimeException('Defina as duas senhas privadas de demonstração.');
(new Piscina\Installer(db(),config()))->migrate();if(db()->row('SELECT id FROM users LIMIT 1'))throw new RuntimeException('O banco precisa estar vazio. Este gerador não apaga registros.');
function op(int $actor,string $action,array $data,?string $key=null): array {return service()->operation($actor,$action,$data,$key??bin2hex(random_bytes(16)));}
function user(string $name): array {db()->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[strtolower($name).'@local.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),$name]);$id=(int)db()->pdo->lastInsertId();$r=op(1,'admin.approve',['user_id'=>(string)$id]);return [$id,(int)$r['nft_id']];}
function withdraw(int $user,int $nft): array {$q=service()->quote($user,$nft);return op($user,'nft.withdraw',['nft_id'=>(string)$nft,'payment'=>'MR','parameter_version'=>$q['parameter_version'],'quote_expires'=>$q['quote_expires']]);}
(new Piscina\Installer(db(),config()))->admin('admin@demo.invalid','Administração da demonstração',getenv('PISCINA_DEMO_ADMIN_PASSWORD'));
op(1,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT']);
require __DIR__.'/galeria-fixtures.php';require __DIR__.'/molduras-simples-fixtures.php';
$f=msfixture();$f['public']=ga('frame.quick',['name'=>'Moldura à venda para a turma','sale_mode'=>'public','price'=>'95','asset'=>'MR']);
$f['exclusive']=ga('frame.quick',['name'=>'Moldura exclusiva para Bruno','sale_mode'=>'direct','buyer_id'=>(string)$f['bruno'],'price'=>'120','asset'=>'MR']);
echo json_encode($f,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
