<?php
declare(strict_types=1);
namespace Piscina;
final class AdminDanger {
    public function __construct(private Domain $domain,private string $op='',private string $now='') {}
    public function target(int $actor,int $id,bool $closed=false): array {
        $this->domain->admin($actor);
        $u=$this->domain->db->row('SELECT u.id,u.email,u.display_name,u.role,u.status,r.banned_until,r.deleted_at FROM users u LEFT JOIN admin_restrictions r ON r.user_id=u.id WHERE u.id=?',[$id])??throw new Problem('Participante não encontrado.',404);
        if($u['role']!=='participant'||$id===$actor)throw new Problem('Esta ação permite somente contas de participantes.',403);
        if(!$closed&&$u['deleted_at']!==null)throw new Problem('Participação encerrada definitivamente.',409,'account_closed');
        return $u;
    }
    public static function reason(mixed $value): string {
        if(!is_string($value)||mb_strlen(trim($value))<5||mb_strlen(trim($value))>255)throw new Problem('Informe motivo de 5 a 255 caracteres.');
        return trim($value);
    }
    public function apply(int $actor,string $action,array $d): array {
        $id=Domain::id($d['user_id']??null);$u=$this->target($actor,$id);self::reason($d['reason']??null);
        if(($d['confirm']??false)!==true)throw new Problem('Confirme expressamente a ação.');
        $db=$this->domain->db;$ledger=$this->domain->ledger;$result=['user_id'=>(string)$id];
        if($action==='admin.danger.fine') {
            $amount=Exact::units($d['amount']??null,2);
            $from=$ledger->account($id,'MR');
            $ledger->move($from,$ledger->account(null,'MR','fines'),$amount,'ADMIN_FINE','fine:'.$this->op,$this->op);
            return $result+['amount'=>$amount,'before'=>$from['balance'],'after'=>bcsub($from['balance'],$amount,0),'destination'=>'fines'];
        }
        if($action==='admin.danger.unban') {
            if($u['banned_until']===null||$u['banned_until']<=$this->now)throw new Problem('Não há banimento ativo.',409);
            $db->run('UPDATE admin_restrictions SET banned_until=NULL WHERE user_id=?',[$id]);
            return $result+['state'=>'UNBANNED'];
        }
        if($action==='admin.danger.ban') {
            $until=$d['until']??null;
            if(!is_string($until)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d(?:\.\d{1,6})?)?(?:Z|[+-]\d\d:\d\d)$/D',$until))throw new Problem('Informe término válido com fuso horário.');
            try{$date=new \DateTimeImmutable($until);$errors=\DateTimeImmutable::getLastErrors();if($errors!==false&&($errors['warning_count']||$errors['error_count']))throw new Problem('Data inválida.');$until=$date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}catch(\Exception){throw new Problem('Data inválida.');}
            if($until<=$this->now||$until>(new \DateTimeImmutable($this->now))->modify('+1 year')->format('Y-m-d H:i:s.u'))throw new Problem('Término deve ser futuro, em até um ano.');
            $cancelled=$this->domain->cancelForRestriction($actor,$id);
            $db->run('INSERT INTO admin_restrictions(user_id,banned_until) VALUES(?,?) ON DUPLICATE KEY UPDATE banned_until=?',[$id,$until,$until]);
            $db->run('UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$id]);
            return $result+['state'=>'BANNED','until'=>$until]+$cancelled;
        }
        if($action==='admin.danger.delete') {
            if(($d['confirmation']??'')!=='EXCLUIR '.$id)throw new Problem('Digite EXCLUIR '.$id.' para confirmar.');
            $cancelled=$this->domain->cancelForRestriction($actor,$id);
            if($db->row("SELECT id FROM accounts WHERE user_id=? AND purpose='reserved' AND balance<>0 LIMIT 1",[$id])||$db->row("SELECT id FROM reservations WHERE user_id=? AND state='ACTIVE' LIMIT 1",[$id])||$db->row('SELECT id FROM nfts WHERE owner_id=? AND reserved_proposal IS NOT NULL LIMIT 1',[$id]))throw new Problem('Há reservas inconsistentes. Confira a reconciliação antes de excluir.',409,'custody_reserve_conflict');
            $db->run('INSERT INTO admin_restrictions(user_id,deleted_at) VALUES(?,?) ON DUPLICATE KEY UPDATE deleted_at=?',[$id,$this->now,$this->now]);
            $db->run('UPDATE users SET auth_version=auth_version+1 WHERE id=?',[$id]);
            $db->run('UPDATE auth_tokens SET used_at=UTC_TIMESTAMP(6) WHERE user_id=? AND used_at IS NULL',[$id]);
            $assets=[];
            foreach(['MR','BYC','SHARE'] as $asset) {
                $from=$ledger->account($id,$asset);$assets[$asset]=$from['balance'];
                // A origem continua associada ao participante para preservar Q,
                // snapshots e obrigações. Custódia não é carteira negociável.
                if($from['balance']!=='0')$ledger->move($from,$ledger->account($id,$asset,'custody'),$from['balance'],'ADMIN_CUSTODY','custody:'.$this->op.':'.$asset,$this->op);
            }
            $nfts=$db->all("SELECT id FROM nfts WHERE owner_id=? AND state='PRIVATE' ORDER BY id FOR UPDATE",[$id]);
            foreach($nfts as $n) {
                $db->run("UPDATE nfts SET owner_id=NULL,state='CUSTODY' WHERE id=?",[$n['id']]);
                $db->run('INSERT INTO admin_nft_custody(nft_id,source_user_id,operation_id,created_at) VALUES(?,?,?,?)',[$n['id'],$id,$this->op,$this->now]);
                $db->run("INSERT INTO nft_events(nft_id,actor_id,kind,from_user,to_user,operation_id,effective_at) VALUES(?,?,'ADMIN_CUSTODY',?,NULL,?,?)",[$n['id'],$actor,$id,$this->op,$this->now]);
            }
            return $result+['state'=>'CLOSED','custody'=>$assets,'nfts'=>array_column($nfts,'id')]+$cancelled;
        }
        throw new Problem('Ação administrativa desconhecida.',404);
    }
    public function view(int $actor,int $id,int $offset=0): array {
        $db=$this->domain->db;
        $offset=max(0,min(5000,$offset));
        return $db->transaction(function()use($actor,$id,$db,$offset){
            $db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');$u=$this->target($actor,$id,true);
            $balances=$this->domain->ledger->balances($id);
            $custody=$db->all("SELECT asset,balance FROM accounts WHERE user_id=? AND purpose='custody' ORDER BY asset",[$id]);
            $nfts=$db->all('SELECT c.nft_id,n.title,c.created_at FROM admin_nft_custody c JOIN nfts n ON n.id=c.nft_id WHERE c.source_user_id=? ORDER BY c.nft_id LIMIT 100 OFFSET '.$offset,[$id]);
            $nfts_total=(int)$db->row('SELECT COUNT(*) n FROM admin_nft_custody WHERE source_user_id=?',[$id])['n'];
            $pending=$db->row("SELECT (SELECT COALESCE(SUM(amount),0) FROM rights WHERE user_id=? AND state='PENDING') rights_amount,(SELECT COALESCE(SUM(amount),0) FROM fees WHERE user_id=? AND state='PENDING') fees_amount",[$id,$id]);
            $audit=$db->all("SELECT id,actor_id,kind,data,created_at FROM audit_events WHERE kind LIKE 'admin.danger.%' AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.input.user_id'))=? ORDER BY id DESC LIMIT 50",[(string)$id]);
            return compact('u','balances','custody','nfts','pending','audit','offset','nfts_total')+['has_more'=>$offset+count($nfts)<$nfts_total];
        });
    }
}
