<?php
declare(strict_types=1);
namespace Piscina;
final class Queries {
    public function __construct(private Domain $domain) {}
    private function paging(array $d): array {$offset=max(0,min(100000,(int)($d['offset']??0)));return [$offset,24];}
    public function market(array $d): array {
        [$off,$limit]=$this->paging($d);$q=mb_substr(trim((string)($d['q']??'')),0,80);$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';$db=$this->domain->db;
        $state=($d['state']??'')==='POOL'?"n.state='POOL'":"(n.state='POOL' OR EXISTS (SELECT 1 FROM listings l WHERE l.nft_id=n.id AND l.state='ACTIVE' AND l.expires_at>UTC_TIMESTAMP(6)))";
        $nfts=$db->all("SELECT n.id,n.title,n.state,n.creator_id,n.owner_id,n.image_path IS NOT NULL image_ready,n.reserved_proposal IS NOT NULL reserved,u.display_name creator_name,o.display_name owner_name FROM nfts n JOIN users u ON u.id=n.creator_id LEFT JOIN users o ON o.id=n.owner_id WHERE $state AND (n.title LIKE ? OR u.display_name LIKE ?) ORDER BY n.id DESC LIMIT $limit OFFSET $off",[$like,$like]);
        $listings=$db->all("SELECT l.*,u.display_name,n.title nft_title FROM listings l JOIN users u ON u.id=l.user_id LEFT JOIN nfts n ON n.id=l.nft_id WHERE l.state='ACTIVE' AND l.expires_at>UTC_TIMESTAMP(6) AND (u.display_name LIKE ? OR l.asset LIKE ?) AND (l.asset<>'NFT' OR (n.state='PRIVATE' AND (l.side='BUY' OR n.owner_id=l.user_id))) ORDER BY l.id DESC LIMIT $limit OFFSET $off",[$like,$like]);
        $users=$q!==''?$db->all("SELECT id,display_name FROM users WHERE status='approved' AND display_name LIKE ? ORDER BY id LIMIT 24",[$like]):[];
        $stats=$db->row("SELECT (SELECT COUNT(*) FROM nfts WHERE state='POOL') pool_nfts,(SELECT COALESCE(SUM(balance),0) FROM accounts WHERE asset='SHARE' AND user_id IS NOT NULL) shares,(SELECT COUNT(*) FROM users WHERE status='approved') participants,(SELECT COUNT(*) FROM settlements) trades");
        $feed=[];if($this->domain->policy()['public_feed']??false) $feed=$db->all("SELECT p.id,p.buyer_id,p.seller_id,p.settled_at,v.asset,v.quantity,v.total,b.display_name buyer_name,s.display_name seller_name FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN users b ON b.id=p.buyer_id JOIN users s ON s.id=p.seller_id WHERE p.state='SETTLED' ORDER BY p.id DESC LIMIT 20");
        return compact('nfts','listings','users','stats','feed')+['offset'=>(string)$off,'has_more'=>count($nfts)===$limit||count($listings)===$limit,'parameters'=>$this->domain->parameters(),'active'=>$this->domain->policy()!==null];
    }
    public function nft(int $id): array {
        $db=$this->domain->db;$n=$db->row('SELECT n.id,n.title,n.state,n.creator_id,n.owner_id,n.image_path IS NOT NULL image_ready,n.reserved_proposal IS NOT NULL reserved,u.display_name creator_name,o.display_name owner_name FROM nfts n JOIN users u ON u.id=n.creator_id LEFT JOIN users o ON o.id=n.owner_id WHERE n.id=?',[$id])??throw new Problem('NFT não encontrada.',404);
        $n['events']=$db->all('SELECT e.kind,e.effective_at,e.from_user,e.to_user,u.display_name actor_name FROM nft_events e JOIN users u ON u.id=e.actor_id WHERE nft_id=? ORDER BY e.id DESC LIMIT 50',[$id]);return $n;
    }
    public function profile(int $id): array {
        $db=$this->domain->db;$u=$db->row("SELECT id,display_name,created_at FROM users WHERE id=? AND status='approved'",[$id])??throw new Problem('Perfil indisponível.',404);
        $u['listings']=$db->all("SELECT l.*,n.title nft_title FROM listings l LEFT JOIN nfts n ON n.id=l.nft_id WHERE l.user_id=? AND l.state='ACTIVE' AND l.expires_at>UTC_TIMESTAMP(6) ORDER BY l.id DESC LIMIT 24",[$id]);return $u;
    }
    public function dashboard(int $actor,array $d=[]): array {
        $db=$this->domain->db;$u=$this->domain->user($actor);[$off,$limit]=$this->paging($d);
        $nfts=$db->all('SELECT n.id,n.title,n.state,n.creator_id,n.owner_id,n.image_path IS NOT NULL image_ready,n.reserved_proposal IS NOT NULL reserved FROM nfts n WHERE owner_id=? OR (creator_id=? AND image_path IS NULL) ORDER BY n.id DESC LIMIT 24 OFFSET '.$off,[$actor,$actor]);
        $proposals=$db->all('SELECT p.*,v.asset,v.nft_id,v.quantity,v.unit_price,v.total,v.expires_at,b.display_name buyer_name,s.display_name seller_name FROM proposals p JOIN proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN users b ON b.id=p.buyer_id JOIN users s ON s.id=p.seller_id WHERE p.buyer_id=? OR p.seller_id=? ORDER BY p.id DESC LIMIT 24 OFFSET '.$off,[$actor,$actor]);
        $ids=array_column($proposals,'id');$approvals=$ids?$db->all('SELECT a.* FROM approvals a JOIN proposals p ON p.id=a.proposal_id WHERE p.id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND a.version=p.current_version',$ids):[];
        $rights=$db->all("SELECT r.*,p.cutoff_at,p.nft_count,p.total_shares,p.distribution_total,p.parameter_id,s.shares,par.distribution_base FROM rights r JOIN periods p ON p.id=r.period_id JOIN period_snapshots s ON s.period_id=r.period_id AND s.user_id=r.user_id JOIN parameter_versions par ON par.id=p.parameter_id WHERE r.user_id=? AND p.state='PUBLISHED' ORDER BY r.id DESC LIMIT 24 OFFSET $off",[$actor]);
        $fees=$db->all("SELECT f.*,p.cutoff_at,p.parameter_id FROM fees f JOIN periods p ON p.id=f.period_id WHERE f.user_id=? AND p.state='PUBLISHED' ORDER BY f.id DESC LIMIT 24 OFFSET $off",[$actor]);
        $history=$db->all("SELECT t.id,t.kind,t.created_at,a.asset,a.purpose,e.amount,t.operation_id FROM ledger_entries e JOIN ledger_transactions t ON t.id=e.transaction_id JOIN accounts a ON a.id=e.account_id WHERE a.user_id=? ORDER BY e.id DESC LIMIT 48 OFFSET $off",[$actor]);
        $notifications=$db->all('SELECT id,message,created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 12',[$actor]);
        $deposits=$db->all('SELECT d.id,d.nft_id,d.created_at,n.title,n.state FROM deposits d JOIN nfts n ON n.id=d.nft_id WHERE d.user_id=? ORDER BY d.id DESC LIMIT 24',[$actor]);
        $totals=$db->row("SELECT (SELECT COALESCE(SUM(r.amount),0) FROM rights r JOIN periods p ON p.id=r.period_id WHERE r.user_id=? AND r.state='PENDING' AND p.state='PUBLISHED') receivable,(SELECT COALESCE(SUM(f.amount),0) FROM fees f JOIN periods p ON p.id=f.period_id WHERE f.user_id=? AND f.state='PENDING' AND p.state='PUBLISHED') due",[$actor,$actor]);
        return compact('nfts','proposals','approvals','rights','fees','history','notifications','deposits','totals')+['user'=>$u,'balances'=>$this->domain->ledger->balances($actor),'parameters'=>$this->domain->parameters()];
    }
    public function proposal(int $actor,int $id): array {
        $db=$this->domain->db;$p=$db->row('SELECT * FROM proposals WHERE id=? AND (buyer_id=? OR seller_id=?)',[$id,$actor,$actor])??throw new Problem('Proposta privada ou indisponível.',404);
        $p['versions']=$db->all('SELECT * FROM proposal_versions WHERE proposal_id=? ORDER BY version DESC',[$id]);$p['approvals']=$db->all('SELECT * FROM approvals WHERE proposal_id=? ORDER BY version DESC',[$id]);return $p;
    }
    public function operation(int $actor,array $d): array {
        $r=isset($d['id'])?$this->domain->db->row('SELECT result FROM operations WHERE id=? AND actor_id=?',[$d['id'],$actor]):$this->domain->db->row('SELECT result FROM operations WHERE actor_id=? AND action=? AND idempotency_key=?',[$actor,$d['action']??'',$d['key']??'']);
        if(!$r) throw new Problem('Operação ainda não encontrada. Reutilize a mesma chave e condições para tentar novamente.',404,'operation_unknown');return json_decode($r['result'],true,512,JSON_THROW_ON_ERROR);
    }
    public function admin(int $actor,array $d): array {
        $this->domain->admin($actor);$db=$this->domain->db;[$off,$limit]=$this->paging($d);
        return ['users'=>$db->all("SELECT id,email,display_name,role,status,email_verified_at,approved_at FROM users ORDER BY id DESC LIMIT $limit OFFSET $off"),
            'parameters'=>$db->all('SELECT * FROM parameter_versions ORDER BY id DESC LIMIT 20'),'decisions'=>$db->all('SELECT * FROM decision_versions ORDER BY id DESC LIMIT 20'),
            'periods'=>$db->all('SELECT * FROM periods ORDER BY cutoff_at DESC LIMIT 24'),'accounts'=>$db->all("SELECT asset,purpose,COUNT(*) accounts,SUM(balance) balance FROM accounts GROUP BY asset,purpose"),
            'obligations'=>$db->all('SELECT state,COUNT(*) n,SUM(amount) amount FROM fees GROUP BY state'),'rights'=>$db->all('SELECT state,COUNT(*) n,SUM(amount) amount FROM rights GROUP BY state'),
            'nfts'=>$db->all('SELECT state,COUNT(*) n FROM nfts GROUP BY state'),'tasks'=>$db->all('SELECT * FROM task_runs ORDER BY id DESC LIMIT 10'),
            'audit'=>$db->all("SELECT id,actor_id,kind,data,created_at FROM audit_events ORDER BY id DESC LIMIT 24 OFFSET $off"),'outbox'=>$db->all('SELECT state,COUNT(*) n FROM outbox GROUP BY state'),
            'reconciliation'=>$db->transaction(function()use($db){$db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');return $this->domain->ledger->reconcile();})];
    }
}
