<?php
declare(strict_types=1);
namespace Piscina;

final class GaleriaRanking {
    public function __construct(private Database $db){}
    public function read(array $q): array {
        $where=["e.state IN ('FINALIZED','SETTLED')","c.state='VALID'","a.state='COMPLETED'"];$params=[];
        foreach(['from'=>'>=','to'=>'<'] as $key=>$op)if(!empty($q[$key])){$where[]="e.closed_at $op ?";$params[]=GaleriaInput::date($q[$key]);}
        if(!empty($q['space_id'])){$where[]='a.space_id=?';$params[]=Domain::id($q['space_id']);}
        $stats=[];$lastEvent=null;$winners=[];
        $s=$this->db->run('SELECT c.id candidate_id,c.frame_id,a.space_id,e.id event_id,JSON_EXTRACT(r.data,\'$.winners\') winners FROM galeria_candidates c JOIN galeria_agreements a ON a.id=c.agreement_id JOIN galeria_events e ON e.id=c.event_id JOIN galeria_results r ON r.event_id=e.id AND r.version=e.result_version WHERE '.implode(' AND ',$where).' ORDER BY e.id,c.id',$params);
        while($r=$s->fetch()){$id=$r['frame_id'];$stats[$id]??=['wins'=>0,'shared_wins'=>0,'participations'=>0];$stats[$id]['participations']++;$event=$r['event_id'];if($lastEvent!==$event){$winners=GaleriaInput::decode($r['winners']);$lastEvent=$event;}$w=$winners;if(in_array($r['candidate_id'],$w,true)){$stats[$id]['wins']++;if(count($w)>1)$stats[$id]['shared_wins']++;}}
        $frames=$this->db->all("SELECT f.id,f.code,f.name,f.authority_id,s.name current_space FROM galeria_frames f LEFT JOIN galeria_allocations l ON l.frame_id=f.id AND l.starts_at<=UTC_TIMESTAMP(6) AND l.ends_at>UTC_TIMESTAMP(6) LEFT JOIN galeria_points p ON p.id=l.point_id LEFT JOIN galeria_spaces s ON s.id=p.space_id WHERE f.status<>'DRAFT' ORDER BY f.id");$rows=[];
        foreach($frames as $f){if(!empty($q['space_id'])&&!isset($stats[$f['id']]))continue;$r=$stats[$f['id']]??['wins'=>0,'shared_wins'=>0,'participations'=>0];$r['win_rate']=$r['participations']?bcdiv(bcmul((string)$r['wins'],'100',0),(string)$r['participations'],2):null;$rows[]=$f+$r;}
        usort($rows,fn($a,$b)=>($b['wins']<=>$a['wins'])?:((int)$a['id']<=>(int)$b['id']));$position=0;$previous=null;foreach($rows as &$r){if($r['wins']!==$previous){$position++;$previous=$r['wins'];}$r['position']=$position;}unset($r);
        $offset=max(0,min(100000,(int)($q['offset']??0)));return ['ranking_version'=>1,'rows'=>array_slice($rows,$offset,24),'offset'=>$offset,'has_more'=>count($rows)>$offset+24,'total'=>count($rows)];
    }
}
