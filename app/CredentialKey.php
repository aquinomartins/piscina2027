<?php
declare(strict_types=1);
namespace Piscina;
// Segredo fora do banco: um vazamento de recibos não vira verificador rápido de senha.
final class CredentialKey {
    public static function get(array $config): string {
        $file=rtrim($config['storage'],'/').'/credentials-idempotency.key';
        $fp=@fopen($file,'c+b');if(!$fp)throw new \RuntimeException('Armazenamento privado de credenciais indisponível.');
        try {
            if(!chmod($file,0600)||!flock($fp,LOCK_EX))throw new \RuntimeException('Proteção do segredo privado indisponível.');
            $key=stream_get_contents($fp);
            if($key===''){$key=bin2hex(random_bytes(32));if(fwrite($fp,$key)!==64||!fflush($fp))throw new \RuntimeException('Falha ao persistir segredo privado.');}
            if(strlen($key)!==64||!ctype_xdigit($key))throw new \RuntimeException('Segredo privado inválido. Verifique o armazenamento.');
            return hex2bin($key);
        } finally {flock($fp,LOCK_UN);fclose($fp);}
    }
}
