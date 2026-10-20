<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config()['environment']!=='demo'||!preg_match('/dbname=piscina_test(?:;|$)/',config()['database']['dsn']))
    throw new RuntimeException('Fixture permitida somente em demo e banco piscina_test vazio.');
(new Piscina\Installer(db(),config()))->migrate();
// bin/demo.php valida banco vazio e senhas exclusivamente de teste.
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/demo.php').' --confirm-demo',$status);
if($status!==0)exit($status);
for($i=0;$i<30;$i++) {
    db()->run('INSERT INTO users(email,password_hash,display_name,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',
        ['pendente'.$i.'@demo.invalid',password_hash(getenv('PISCINA_DEMO_PASSWORD'),PASSWORD_DEFAULT),'Cadastro pendente '.$i]);
}
echo "Mais 30 cadastros sem confirmação de e-mail criados para paginação administrativa.\n";
