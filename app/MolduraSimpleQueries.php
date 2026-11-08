<?php
declare(strict_types=1);
namespace Piscina;

final class MolduraSimpleQueries {
    public function __construct(private Domain $d) {}
    private function ready(): void {if(!MolduraLinks::installed($this->d->db))throw new Problem('Molduras simples aguardando migração 005.',409);}
    private function page(string $sql,array $args,array $q): array {
        $off=max(0,min(100000,(int)($q['offset']??0)));$rows=$this->d->db->all($sql.' LIMIT 25 OFFSET '.$off,$args);
        return ['rows'=>array_slice($rows,0,24),'offset'=>$off,'has_more'=>count($rows)>24];
    }
    public function people(int $actor,array $q): array {
        $this->ready();$u=$this->d->user($actor);if($u['role']!=='admin'&&$u['status']!=='approved')throw new Problem('Cadastro ainda não aprovado.',403);$needle='%'.mb_substr((string)($q['q']??''),0,80).'%';
        return $this->page("SELECT u.id,u.display_name FROM users u LEFT JOIN admin_restrictions r ON r.user_id=u.id WHERE u.status='approved' AND r.deleted_at IS NULL AND (r.banned_until IS NULL OR r.banned_until<=UTC_TIMESTAMP(6)) AND (u.display_name LIKE ? OR CAST(u.id AS CHAR) LIKE ?) ORDER BY u.display_name,u.id",[$needle,$needle],$q);
    }
    public function assets(int $actor,array $q): array {
        $this->ready();$u=$this->d->user($actor);if($u['role']!=='admin'&&$u['status']!=='approved')throw new Problem('Cadastro ainda não aprovado.',403);$where=["n.state='PRIVATE'","n.reserved_proposal IS NULL","n.image_path IS NOT NULL","u.status='approved'",'r.deleted_at IS NULL',"(r.banned_until IS NULL OR r.banned_until<=UTC_TIMESTAMP(6))",'NOT EXISTS(SELECT 1 FROM galeria_links a WHERE a.active_nft=n.id)',"NOT EXISTS(SELECT 1 FROM galeria_agreements a WHERE a.nft_id=n.id AND a.state IN ('RESERVED','EXHIBITING','SUSPENDED'))"];$args=[];
        if(!empty($q['owner_id'])){$where[]='n.owner_id=?';$args[]=Domain::id($q['owner_id']);}
        if(!empty($q['q'])){$where[]='(n.title LIKE ? OR u.display_name LIKE ? OR CAST(n.id AS CHAR) LIKE ?)';$needle='%'.mb_substr((string)$q['q'],0,80).'%';array_push($args,$needle,$needle,$needle);}
        return $this->page('SELECT n.id,n.title,n.owner_id,u.display_name owner_name,n.creator_id FROM nfts n JOIN users u ON u.id=n.owner_id LEFT JOIN admin_restrictions r ON r.user_id=u.id WHERE '.implode(' AND ',$where).' ORDER BY n.id DESC',$args,$q);
    }
    public function mine(int $actor,array $q): array {
        $this->ready();$this->d->user($actor);
        $frames=$this->page('SELECT f.id,f.name,f.code,f.status,f.holder_id,(SELECT id FROM galeria_links a WHERE a.active_frame=f.id) link_id FROM galeria_frames f WHERE f.holder_id=? ORDER BY f.id DESC',[$actor],$q);
        $offers=$this->page("SELECT o.id,o.frame_id,o.seller_id,o.buyer_id,o.asset,o.amount,o.version,o.terms,o.expires_at,f.name frame_name,u.display_name seller_name FROM galeria_offers o JOIN galeria_frames f ON f.id=o.frame_id LEFT JOIN users u ON u.id=o.seller_id WHERE o.buyer_id=? AND o.state='ACTIVE' AND (o.expires_at IS NULL OR o.expires_at>UTC_TIMESTAMP(6)) ORDER BY o.id DESC",[$actor],$q);
        $proposals=$this->page('SELECT p.*,v.author_id,v.expires_at,f.name frame_name,n.title nft_title,fu.display_name frame_user_name,nu.display_name nft_user_name FROM galeria_link_proposals p JOIN galeria_link_versions v ON v.proposal_id=p.id AND v.version=p.current_version JOIN galeria_frames f ON f.id=p.frame_id JOIN nfts n ON n.id=p.nft_id JOIN users fu ON fu.id=p.frame_user JOIN users nu ON nu.id=p.nft_user WHERE p.frame_user=? OR p.nft_user=? ORDER BY p.id DESC',[$actor,$actor],$q);
        $links=$this->page('SELECT a.*,f.name frame_name FROM galeria_links a JOIN galeria_frames f ON f.id=a.frame_id WHERE a.frame_user=? OR a.nft_user=? ORDER BY a.id DESC',[$actor,$actor],$q);
        foreach($links['rows'] as &$a)$a['terms']=GaleriaQueries::terms(GaleriaInput::decode($a['terms']));unset($a);
        $trades=$this->page('SELECT p.*,f.name frame_name FROM galeria_trade_proposals p JOIN galeria_frames f ON f.id=p.frame_id WHERE p.buyer_id=? OR p.seller_id=? ORDER BY p.id DESC',[$actor,$actor],$q);
        return compact('frames','offers','proposals','links','trades');
    }
    public function admin(int $actor,array $q): array {
        $this->ready();$this->d->admin($actor);
        $frames=$this->page("SELECT f.id,f.name,f.code,f.status,f.holder_id,f.metadata,u.display_name holder_name,o.id offer_id,o.buyer_id,o.amount,o.asset,o.version offer_version,bu.display_name buyer_name,EXISTS(SELECT 1 FROM galeria_links a WHERE a.active_frame=f.id) associated FROM galeria_frames f LEFT JOIN users u ON u.id=f.holder_id LEFT JOIN galeria_offers o ON o.frame_id=f.id AND o.state='ACTIVE' AND (o.expires_at IS NULL OR o.expires_at>UTC_TIMESTAMP(6)) LEFT JOIN users bu ON bu.id=o.buyer_id ORDER BY f.id DESC",[],$q);
        foreach($frames['rows'] as &$f)$f['metadata']=GaleriaInput::decode($f['metadata']);unset($f);
        $links=$this->page("SELECT a.id,a.proposal_id,a.state,a.frame_id,a.nft_id,a.frame_user,a.nft_user,a.nft_bps,a.created_at,a.end_reason,f.name frame_name,fu.display_name frame_user_name,nu.display_name nft_user_name FROM galeria_links a JOIN galeria_frames f ON f.id=a.frame_id JOIN users fu ON fu.id=a.frame_user JOIN users nu ON nu.id=a.nft_user ORDER BY a.id DESC",[],$q);
        return ['frames'=>$frames,'links'=>$links,'features'=>(new Galeria($this->d))->features(),'income'=>$this->d->db->all("SELECT asset,balance FROM accounts WHERE purpose='galeria_revenue'")];
    }
    public function publicLink(int $frame,?int $viewer,bool $admin): ?array {
        if(!MolduraLinks::installed($this->d->db))return null;
        $a=$this->d->db->row('SELECT id,proposal_id,frame_user,nft_user,nft_id,nft_bps,state,end_requested_by,terms FROM galeria_links WHERE active_frame=?',[$frame]);
        if(!$a)return null;$t=GaleriaInput::decode($a['terms']);$a['nft_title']=$t['nft_title'];$a['frame_user_name']=$t['frame_user_name'];$a['nft_user_name']=$t['nft_user_name'];unset($a['terms']);
        if(!$admin&&!in_array((string)$viewer,[$a['frame_user'],$a['nft_user']],true)){unset($a['nft_bps'],$a['end_requested_by']);}
        return $a;
    }
}
