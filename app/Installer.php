<?php
declare(strict_types=1);
namespace Piscina;
final class Installer {
    public function __construct(private Database $db,private array $config) {}
    public function migrate(): void {
        // DDL MySQL faz commits implícitos: arquivo idempotente, versão só ao final.
        foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) {
            $version=basename($file,'.sql');
            try{$exists=$this->db->row('SELECT version FROM schema_migrations WHERE version=?',[$version]);}catch(\PDOException){$exists=null;}
            if($exists)continue;
            foreach(explode(';',file_get_contents($file)) as $sql) if(trim($sql)!=='')$this->db->pdo->exec($sql);
        }
    }
    public function admin(string $email,string $name,string $password): string {
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||mb_strlen($name)<2||mb_strlen($name)>80||strlen($password)<12||strlen($password)>72)throw new Problem('Dados do administrador inválidos. Senha de 12 a 72 bytes.');
        return $this->db->transaction(function()use($email,$name,$password){
            $this->db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');
            if($this->db->row("SELECT id FROM users WHERE role='admin' LIMIT 1"))throw new Problem('Administrador inicial já existe.',409);
            $this->db->run("INSERT INTO users(email,password_hash,display_name,role,email_verified_at,created_at) VALUES(?,?,?,'admin',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[mb_strtolower($email),password_hash($password,PASSWORD_DEFAULT),$name]);
            return $this->db->pdo->lastInsertId();
        });
    }
}
