<?php
declare(strict_types=1);
// Ao separar public_html da parte privada, altere somente este caminho absoluto
// ou configure PISCINA_ROOT no ambiente PHP da conta. Nenhum segredo fica aqui.
$privateRoot=getenv('PISCINA_ROOT') ?: dirname(__DIR__);
require $privateRoot.'/app/bootstrap.php';
