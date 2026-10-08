<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
fwrite(STDOUT,"E-mail do administrador: ");$email=trim(fgets(STDIN));fwrite(STDOUT,"Identificação pública: ");$name=trim(fgets(STDIN));
fwrite(STDOUT,"Senha (não use argumentos da linha de comando): ");
$tty=function_exists('posix_isatty')&&posix_isatty(STDIN);if($tty)system('stty -echo');try{$password=trim(fgets(STDIN));}finally{if($tty)system('stty echo');}fwrite(STDOUT,"\n");
$id=(new Piscina\Installer(db(),config()))->admin($email,$name,$password);echo "Administrador #$id criado. Ative regras e calendário no painel.\n";
