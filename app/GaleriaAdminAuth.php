<?php
declare(strict_types=1);
namespace Piscina;

final class GaleriaAdminAuth {
    // Preparar uma turma envolve dezenas de confirmações legítimas. Só falhas
    // consomem o orçamento desta confirmação; limites antigos ficam intactos.
    public static function check(Auth $auth,Domain $domain,int $actor,string $password): void {
        $domain->admin($actor);$kind='galeria.reauth';$auth->limit($kind,(string)$actor,10);
        $u=$domain->db->row('SELECT password_hash FROM users WHERE id=?',[$actor]);
        if(!$u||!password_verify($password,$u['password_hash']))throw new Problem('Confirme sua senha atual de Admin.',403);
        $domain->db->run('DELETE FROM rate_limits WHERE bucket=?',[hash('sha256',$kind.':'.$actor)]);
    }
}
