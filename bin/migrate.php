<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
(new Piscina\Installer(db(),config()))->migrate();echo "Migrações aplicadas.\n";
