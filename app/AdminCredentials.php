<?php
declare(strict_types=1);
namespace Piscina;
// Senhas nunca entram no payload da auditoria genérica do Domain.
final class AdminCredentials {
    public function __construct(private Domain $domain) {}
    public function reset(int $actor,array $d,string $key): array {
        $this->domain->admin($actor);$id=Domain::id($d['user_id']??null);$reason=AdminDanger::reason($d['reason']??null);
        $password=$d['new_password']??null;$confirmation=$d['new_password_confirmation']??null;
        if(!is_string($password)||strlen($password)<12||strlen($password)>72||!is_string($confirmation)||!hash_equals($password,$confirmation)||($d['confirm']??false)!==true)throw new Problem('Confirme a ação e duas senhas iguais de 12 a 72 bytes.');
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key))throw new Problem('Chave de idempotência inválida.');
        $db=$this->domain->db;
        $secret=CredentialKey::get($this->domain->config);
        // HMAC privado evita um digest de senha passível de dicionário nos
        // recibos. Nenhuma impressão de credencial é incluída na auditoria.
        $fingerprint=hash_hmac('sha256',Domain::canonical(['user_id'=>(string)$id,'reason'=>$reason,'password'=>$password]),$secret);
        $hash=password_hash($password,PASSWORD_DEFAULT);
        return $db->transaction(function()use($actor,$id,$reason,$key,$fingerprint,$hash,$db){
            $g=$db->row('SELECT * FROM economic_gate WHERE id=1 FOR UPDATE');$this->domain->admin($actor);
            $prior=$db->row("SELECT payload_hash,result FROM operations WHERE actor_id=? AND action='admin.danger.password' AND idempotency_key=?",[$actor,$key]);
            if($prior){if(!hash_equals($prior['payload_hash'],$fingerprint))throw new Problem('Chave já utilizada com outras condições.',409,'idempotency_conflict');return json_decode($prior['result'],true,32,JSON_THROW_ON_ERROR);}
            (new AdminDanger($this->domain))->target($actor,$id);
            $op=bin2hex(random_bytes(16));$now=$db->now();
            $db->run('UPDATE economic_gate SET sequence_no=sequence_no+1 WHERE id=1');
            $db->run("INSERT INTO operations(id,actor_id,action,idempotency_key,payload_hash,effective_at,sequence_no) VALUES(?,?,'admin.danger.password',?,?,?,?)",[$op,$actor,$key,$fingerprint,$now,(int)$g['sequence_no']+1]);
            $db->run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[$hash,$id]);
            $db->run("UPDATE auth_tokens SET used_at=? WHERE user_id=? AND kind='reset' AND used_at IS NULL",[$now,$id]);
            $result=['operation_id'=>$op,'user_id'=>(string)$id,'state'=>'PASSWORD_RESET'];
            $db->run('INSERT INTO audit_events(actor_id,operation_id,kind,data,created_at) VALUES(?,?,?,?,?)',[$actor,$op,'admin.danger.password',json_encode(['input'=>['user_id'=>(string)$id,'reason'=>$reason],'result'=>$result]),$now]);
            $db->run('UPDATE operations SET result=? WHERE id=?',[json_encode($result),$op]);return $result;
        });
    }
}
