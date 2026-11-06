<?php
declare(strict_types=1);
namespace Piscina;

// Liga o Portfólio às mesmas Molduras e contratos da Galeria. Não transfere ativos.
final class MolduraWallet {
    public function __construct(private Domain $d) {}
    public function read(int $actor,array $q,array $nftIds): array {
        $this->d->user($actor);$activity=new ParticipantActivity($this->d);$counts=$activity->counts($actor);
        $section=$q['section']??'';$empty=['rows'=>[],'offset'=>0,'has_more'=>false];
        $frames=Galeria::installed($this->d->db)&&in_array($section,['','dashboard'],true)?$this->frames($actor,$q):$empty;
        $related=in_array($section,['','dashboard'],true)?$this->relations($actor,array_column($frames['rows'],'id'),$nftIds):[];
        return ['installed'=>Galeria::installed($this->d->db),'simple_installed'=>MolduraLinks::installed($this->d->db),
            'frames'=>$frames,'relations'=>$related,'counts'=>$counts,
            'pending'=>in_array($section,['','pending'],true)?$activity->page($actor,$q):$empty,
            'history'=>in_array($section,['','history'],true)?$activity->page($actor,$q,true):$empty];
    }
    private function frames(int $actor,array $q): array {
        $off=max(0,min(100000,(int)($q['offset']??0)));$simple=MolduraLinks::installed($this->d->db);
        $proposals=$simple?"(SELECT COUNT(*) FROM galeria_link_proposals p JOIN galeria_link_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.frame_id=f.id AND (p.frame_user=$actor OR p.nft_user=$actor) AND p.state='PENDING' AND v.expires_at>UTC_TIMESTAMP(6))":'0';
        $rows=$this->d->db->all("SELECT f.id,f.name,f.code,f.status,f.holder_id,f.authority_id,
            (SELECT id FROM galeria_photos p WHERE p.frame_id=f.id ORDER BY id DESC LIMIT 1) photo_id,
            o.id offer_id,o.buyer_id,o.amount,o.asset,o.version offer_version,
            ($proposals+(SELECT COUNT(*) FROM galeria_trade_proposals p JOIN galeria_trade_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.frame_id=f.id AND (p.buyer_id=$actor OR p.seller_id=$actor) AND p.state='PENDING' AND JSON_UNQUOTE(JSON_EXTRACT(v.terms,'$.expires_at'))>UTC_TIMESTAMP(6))+(SELECT COUNT(*) FROM galeria_proposals p JOIN galeria_proposal_versions v ON v.proposal_id=p.id AND v.version=p.current_version WHERE p.frame_id=f.id AND (p.frame_user=$actor OR p.nft_user=$actor) AND p.state='PENDING' AND v.expires_at>UTC_TIMESTAMP(6))) pending_proposals
            FROM galeria_frames f LEFT JOIN galeria_offers o ON o.id=(SELECT id FROM galeria_offers WHERE frame_id=f.id AND state='ACTIVE' AND (expires_at IS NULL OR expires_at>UTC_TIMESTAMP(6)) ORDER BY id DESC LIMIT 1)
            WHERE f.holder_id=? ORDER BY f.id DESC LIMIT 25 OFFSET $off",[$actor]);
        return ['rows'=>array_slice($rows,0,24),'offset'=>$off,'has_more'=>count($rows)>24];
    }
    private function relations(int $actor,array $frameIds,array $nftIds): array {
        if(!Galeria::installed($this->d->db)||(!$frameIds&&!$nftIds))return [];
        $clauses=[];$args=[];
        if($frameIds){$clauses[]='(a.frame_user=? AND f.holder_id=? AND a.frame_id IN ('.implode(',',array_fill(0,count($frameIds),'?')).'))';array_push($args,$actor,$actor,...$frameIds);}
        if($nftIds){$clauses[]='(a.nft_user=? AND n.owner_id=? AND a.nft_id IN ('.implode(',',array_fill(0,count($nftIds),'?')).'))';array_push($args,$actor,$actor,...$nftIds);}
        $where='('.implode(' OR ',$clauses).')';$out=[];
        $common="a.id,a.proposal_id,a.frame_id,a.nft_id,a.frame_user,a.nft_user,a.nft_bps,a.state,f.name frame_name,n.title nft_title,f.holder_id current_frame_owner,n.owner_id current_nft_owner,fo.display_name current_frame_owner_name,no.display_name current_nft_owner_name,fu.display_name frame_user_name,nu.display_name nft_user_name";
        $joins=' JOIN galeria_frames f ON f.id=a.frame_id JOIN nfts n ON n.id=a.nft_id JOIN users fu ON fu.id=a.frame_user JOIN users nu ON nu.id=a.nft_user LEFT JOIN users fo ON fo.id=f.holder_id LEFT JOIN users no ON no.id=n.owner_id';
        if(MolduraLinks::installed($this->d->db))foreach($this->d->db->all("SELECT $common FROM galeria_links a $joins WHERE $where AND a.state IN ('ACTIVE','SUSPENDED')",$args) as $r)$out[]=$r+['protocol'=>'simple','key'=>'simple:'.$r['id']];
        foreach($this->d->db->all("SELECT $common,a.funds_state,a.x FROM galeria_agreements a $joins WHERE $where AND a.state IN ('RESERVED','EXHIBITING','SUSPENDED')",$args) as $r)$out[]=$r+['protocol'=>'physical','key'=>'physical:'.$r['id']];
        return $out;
    }
}
