<?php
declare(strict_types=1);
namespace Piscina;

// Horas oferecidas são registradas prospectivamente, sem inventar disponibilidade passada.
final class GaleriaAvailability {
    public function __construct(private Galeria $g){}
    public function frame(int $id): void {
        $cancelled=$this->g->db->run("UPDATE galeria_offers SET state='CANCELLED' WHERE frame_id=? AND state='ACTIVE'",[$id])->rowCount();
        if($cancelled){$owner=$this->g->db->row('SELECT holder_id FROM galeria_frames WHERE id=?',[$id]);if($owner&&$owner['holder_id'])$this->g->notify((int)$owner['holder_id'],'Galeria: condições da Moldura #'.$id.' alteradas. Revise e publique novamente sua oferta.');}
        $this->g->db->run('UPDATE galeria_availability SET cancelled_at=? WHERE frame_id=? AND cancelled_at IS NULL',[$this->g->now,$id]);
        $f=$this->g->db->row('SELECT status,verified_at FROM galeria_frames WHERE id=?',[$id]);if(!$f||$f['status']!=='ACTIVE'||!$f['verified_at'])return;
        foreach($this->g->db->all("SELECT l.*,p.space_id,p.authorized_from,p.authorized_until,p.opens,p.closes,p.weekdays FROM galeria_allocations l JOIN galeria_points p ON p.id=l.point_id JOIN galeria_spaces s ON s.id=p.space_id WHERE l.frame_id=? AND l.ends_at>? AND p.status='ACTIVE' AND s.status='ACTIVE'",[$id,$this->g->now]) as $r){
            $from=max($this->g->now,$r['starts_at'],$r['authorized_from']);$to=min($r['ends_at'],$r['authorized_until']);if($to<=$from)continue;
            $this->g->db->run('INSERT INTO galeria_availability(frame_id,point_id,space_id,starts_at,ends_at,opens,closes,weekdays,operation_id) VALUES(?,?,?,?,?,?,?,?,?)',[$id,$r['point_id'],$r['space_id'],$from,$to,$r['opens'],$r['closes'],$r['weekdays'],$this->g->op]);
        }
    }
    public function point(int $id): void {foreach($this->g->db->all('SELECT DISTINCT frame_id FROM galeria_allocations WHERE point_id=?',[$id]) as $r)$this->frame((int)$r['frame_id']);}
    public function space(int $id): void {foreach($this->g->db->all('SELECT id FROM galeria_points WHERE space_id=?',[$id]) as $r)$this->point((int)$r['id']);}
    private static function union(array $ranges): array {
        usort($ranges,fn($a,$b)=>$a[0]<=>$b[0]);$out=[];foreach($ranges as $r){if($r[1]<=$r[0])continue;$i=count($out)-1;if($i>=0&&$r[0]<=$out[$i][1])$out[$i][1]=max($out[$i][1],$r[1]);else $out[]=$r;}return $out;
    }
    private static function subtract(array $ranges,array $blocks): array {
        foreach(self::union($blocks) as [$a,$b]){$next=[];foreach($ranges as [$s,$e]){if($b<=$s||$a>=$e)$next[]=[$s,$e];else{if($s<$a)$next[]=[$s,$a];if($b<$e)$next[]=[$b,$e];}}$ranges=$next;}return $ranges;
    }
    public function read(array $q=[]): ?array {
        $end=!empty($q['to'])?GaleriaInput::date($q['to']):$this->g->now;$end=min($end,$this->g->now);$start=!empty($q['from'])?GaleriaInput::date($q['from']):(new \DateTimeImmutable($end))->modify('-30 days')->format('Y-m-d H:i:s.u');
        $stamp=fn($v)=>(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->getTimestamp();if($end<=$start||$stamp($end)-$stamp($start)>366*86400)throw new Problem('Indicador de ocupação aceita período positivo de até 366 dias.');
        $where='starts_at<? AND COALESCE(cancelled_at,ends_at)>?';$params=[$end,$start];$space=null;if(!empty($q['space_id'])){$space=Domain::id($q['space_id']);$where.=' AND space_id=?';$params[]=$space;}
        $windows=$this->g->db->all('SELECT * FROM galeria_availability WHERE '.$where.' ORDER BY frame_id,starts_at',$params);$offered=[];$tz=new \DateTimeZone('America/Sao_Paulo');
        $blocks=$this->g->db->all('SELECT frame_id,point_id,starts_at,ends_at FROM galeria_blocks WHERE starts_at<? AND ends_at>?',[$end,$start]);
        foreach($windows as $w){$s=max($stamp($start),$stamp($w['starts_at']));$e=min($stamp($end),$stamp($w['ends_at']),$w['cancelled_at']?$stamp($w['cancelled_at']):PHP_INT_MAX);if($e<=$s)continue;
            $day=(new \DateTimeImmutable('@'.$s))->setTimezone($tz)->setTime(0,0);$segments=[];
            while($day->getTimestamp()<$e){if(in_array($day->format('N'),explode(',',$w['weekdays']),true)){$a=(new \DateTimeImmutable($day->format('Y-m-d').' '.$w['opens'],$tz))->getTimestamp();$b=(new \DateTimeImmutable($day->format('Y-m-d').' '.$w['closes'],$tz))->getTimestamp();if(min($b,$e)>max($a,$s))$segments[]=[max($a,$s),min($b,$e)];}$day=$day->modify('+1 day');}
            $maintenance=[];foreach($blocks as $b)if($b['frame_id']===$w['frame_id']||$b['point_id']===$w['point_id'])$maintenance[]=[$stamp($b['starts_at']),$stamp($b['ends_at'])];$key=$w['frame_id'].':'.$w['point_id'];$offered[$key]=array_merge($offered[$key]??[],self::subtract($segments,$maintenance));
        }
        $den=0;foreach($offered as &$ranges){$ranges=self::union($ranges);foreach($ranges as [$a,$b])$den+=$b-$a;}unset($ranges);if($den<60)return null;
        $params=[$end,$start];$filter='';if($space){$filter=' AND space_id=?';$params[]=$space;}$confirmed=[];
        foreach($this->g->db->all('SELECT frame_id,point_id,starts_at,ends_at FROM galeria_agreements WHERE completed_at IS NOT NULL AND physical_at IS NOT NULL AND starts_at<? AND ends_at>?'.$filter,$params) as $a){$s=max($stamp($start),$stamp($a['starts_at']));$e=min($stamp($end),$stamp($a['ends_at']));$key=$a['frame_id'].':'.$a['point_id'];foreach($offered[$key]??[] as [$x,$y])if(min($e,$y)>max($s,$x))$confirmed[$key][]=[max($s,$x),min($e,$y)];}
        $num=0;foreach($confirmed as $ranges)foreach(self::union($ranges) as [$a,$b])$num+=$b-$a;
        return ['from'=>$start,'to'=>$end,'offered_seconds'=>$den,'exhibited_seconds'=>$num,'offered_hours'=>bcdiv((string)$den,'3600',2),'exhibited_hours'=>bcdiv((string)$num,'3600',2),'percent'=>bcdiv(bcmul((string)$num,'100',0),(string)$den,2),'measurement'=>'CONFIRMED_EXPOSURES_OVER_RECORDED_OPERATIONAL_HOURS'];
    }
}
