<?php
declare(strict_types=1);
namespace Piscina;
final class Restrictions {
    public static function check(Database $db,int $user): void {
        $r=$db->row('SELECT deleted_at,banned_until,banned_until>UTC_TIMESTAMP(6) banned FROM admin_restrictions WHERE user_id=?',[$user]);
        if($r&&$r['deleted_at']!==null) throw new Problem('Esta participação foi encerrada definitivamente.',403,'account_closed');
        if($r&&$r['banned']==='1') throw new Problem('Acesso suspenso até '.(new \DateTimeImmutable($r['banned_until'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i').' (São Paulo).',403,'account_banned');
    }
}
