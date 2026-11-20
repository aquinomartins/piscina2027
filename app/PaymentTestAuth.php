<?php
declare(strict_types=1);
namespace Piscina;
final class PaymentTestAuth {
    public static function check(Auth $auth,Domain $d,int $actor,mixed $username,mixed $password): void {
        $u=$d->admin($actor);$auth->limit('payment_test.identity',(string)$actor,10);
        if(!is_string($username)||!hash_equals(hash('sha256',mb_strtolower($u['email'])),hash('sha256',mb_strtolower(trim($username)))))throw new Problem('Informe o e-mail do administrador desta sessão e a senha atual.',403);
        if(!is_string($password))throw new Problem('Confirme sua senha atual.',403);
        GaleriaAdminAuth::check($auth,$d,$actor,$password);
        $d->db->run('DELETE FROM rate_limits WHERE bucket=?',[hash('sha256','payment_test.identity:'.$actor)]);
    }
}
