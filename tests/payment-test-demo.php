<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!str_contains(config()['database']['dsn'],'dbname=piscina_payments_ui;'))throw new RuntimeException('Use somente demo e piscina_payments_ui vazio.');
foreach(['PISCINA_DEMO_ADMIN_PASSWORD','PISCINA_DEMO_PASSWORD'] as $key)if(strlen(getenv($key)?:'')<12)throw new RuntimeException('Defina senhas privadas de demonstração.');
(new Piscina\Installer(db(),config()))->migrate();if(db()->row('SELECT id FROM users LIMIT 1'))throw new RuntimeException('Banco deve estar vazio. Este gerador não apaga dados.');
(new Piscina\Installer(db(),config()))->admin('admin@demo.invalid','Admin de testes',getenv('PISCINA_DEMO_ADMIN_PASSWORD'));
service()->operation(1,'admin.activate',['confirm'=>true,'rounding'=>'HALF_UP_CENT'],bin2hex(random_bytes(16)));
db()->run('INSERT INTO users(email,password_hash,display_name,email_verified_at,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',['participante@local.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),'Participante de teste']);service()->operation(1,'admin.approve',['user_id'=>'2'],bin2hex(random_bytes(16)));
echo 'PASS Dados sintéticos criados; pagamentos externos permanecem desativados.'.PHP_EOL;
