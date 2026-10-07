<?php
declare(strict_types=1);
namespace Piscina;
final class Periods {
    public function __construct(private Database $db,private Ledger $ledger,private array $config) {}
    // Somente sob o gate; captura antes da mutação seguinte, nunca a partir de uma leitura pública.
    public function captureDue(string $now,int $sequence,bool $allowPartial=false): int {
        if(!$this->db->row('SELECT id FROM decision_versions LIMIT 1')) return 0;
        $p=$this->db->row('SELECT * FROM parameter_versions ORDER BY id DESC LIMIT 1');
        if(!$p['first_cutoff'] || !$p['interval_minutes']) return 0;
        $last=$this->db->row('SELECT cutoff_at FROM periods ORDER BY cutoff_at DESC LIMIT 1');
        $cut=new \DateTimeImmutable($last['cutoff_at']??$p['first_cutoff'],new \DateTimeZone('UTC'));
        if($last) $cut=$cut->modify('+'.$p['interval_minutes'].' minutes');
        $count=0;
        // Se atrasou mais que esse limite, aborta também a mutação: cron precisa recuperar primeiro.
        while($cut->format('Y-m-d H:i:s.u')<=$now && $count<min(100,$this->config['snapshot_periods_per_run']??10)) {
            $n=(int)$this->db->row("SELECT COUNT(*) n FROM nfts WHERE state='POOL'")['n'];
            $q=$this->db->row("SELECT COALESCE(SUM(balance),0) q FROM accounts WHERE asset='SHARE' AND user_id IS NOT NULL")['q'];
            $total=Exact::bounded(bcmul((string)$n,$p['distribution_base'],0),true);
            $this->db->run('INSERT INTO periods(cutoff_at,parameter_id,nft_count,total_shares,distribution_total,gate_sequence) VALUES(?,?,?,?,?,?)',[$cut->format('Y-m-d H:i:s.u'),$p['id'],$n,$q,$total,$sequence]);
            $id=$this->db->pdo->lastInsertId();
            if($n>0&&$q==='0')$this->db->run('INSERT INTO audit_events(kind,data,created_at) VALUES(?,?,UTC_TIMESTAMP(6))',['period.inconsistent',json_encode(['period'=>$id,'reason'=>'NFTs na piscina sem cotas'])]);
            $this->db->run("INSERT INTO period_snapshots(period_id,user_id,shares,eligible) SELECT ?,u.id,COALESCE(a.q,0),CASE WHEN u.status='approved' THEN 1 ELSE 0 END FROM users u LEFT JOIN (SELECT user_id,SUM(balance) q FROM accounts WHERE asset='SHARE' AND user_id IS NOT NULL GROUP BY user_id) a ON a.user_id=u.id WHERE u.status='approved' OR COALESCE(a.q,0)>0",[$id]);
            $count++;$cut=$cut->modify('+'.$p['interval_minutes'].' minutes');
        }
        if(!$allowPartial && $cut->format('Y-m-d H:i:s.u')<=$now) throw new Problem('Calendário muito atrasado. Recuperação administrativa necessária.',503,'calendar_backlog');
        return $count;
    }
    public function capture(): int {return $this->db->transaction(function(){ $g=$this->db->row('SELECT * FROM economic_gate WHERE id=1 FOR UPDATE');return $this->captureDue($this->db->now(),(int)$g['sequence_no'],true);});}
    public function processBatch(int $batch=100): ?array {
        return $this->db->transaction(function() use($batch){
            $this->db->row('SELECT id FROM economic_gate WHERE id=1 FOR UPDATE');
            $p=$this->db->row("SELECT * FROM periods WHERE state<>'PUBLISHED' ORDER BY cutoff_at LIMIT 1 FOR UPDATE");if(!$p) return null;
            $params=$this->db->row('SELECT * FROM parameter_versions WHERE id=?',[$p['parameter_id']]);
            if($p['state']==='SNAPSHOT') $this->db->run("UPDATE periods SET state='PROCESSING',processing_started_at=UTC_TIMESTAMP(6) WHERE id=?",[$p['id']]);
            $all=$this->db->all('SELECT * FROM period_snapshots WHERE period_id=? ORDER BY user_id',[$p['id']]);$shares=[];foreach($all as $s) $shares[$s['user_id']]=$s['shares'];
            $values=Exact::distribute($p['distribution_total'],$shares);
            $done=0;
            foreach($all as $s) {
                if($s['processed']==='1') continue;if($done>=max(1,min(100,$batch))) break;
                $value=$values[$s['user_id']]??'0';
                if($value!=='0') $this->db->run('INSERT INTO rights(period_id,user_id,amount) VALUES(?,?,?)',[$p['id'],$s['user_id'],$value]);
                if($s['eligible']==='1') $this->db->run('INSERT INTO fees(period_id,user_id,amount) VALUES(?,?,?)',[$p['id'],$s['user_id'],$params['admin_fee']]);
                $this->db->run('UPDATE period_snapshots SET processed=1 WHERE period_id=? AND user_id=?',[$p['id'],$s['user_id']]);$done++;
            }
            $remaining=(int)$this->db->row('SELECT COUNT(*) n FROM period_snapshots WHERE period_id=? AND processed=0',[$p['id']])['n'];
            if($remaining===0) {
                $sum=$this->db->row('SELECT COALESCE(SUM(amount),0) n FROM rights WHERE period_id=?',[$p['id']])['n'];
                if($p['total_shares']!=='0' && bccomp($sum,$p['distribution_total'],0)!==0) throw new \LogicException('Soma de direitos inválida.');
                if($sum!=='0') $this->ledger->move($this->ledger->account(null,'MR','issuance'),$this->ledger->account(null,'MR','distribution',$p['id']),$sum,'PERIOD_ISSUANCE',"period:{$p['id']}",null);
                $this->db->run("UPDATE periods SET state='PUBLISHED',processed_at=UTC_TIMESTAMP(6) WHERE id=?",[$p['id']]);
                $this->db->run("INSERT INTO notifications(user_id,message,created_at) SELECT user_id,?,UTC_TIMESTAMP(6) FROM period_snapshots WHERE period_id=?",['Período #'.$p['id'].' publicado. Consulte recebíveis e taxa.',$p['id']]);
                $this->db->run('INSERT INTO audit_events(kind,data,created_at) VALUES(?,?,UTC_TIMESTAMP(6))',['period.publish',json_encode(['period'=>$p['id'],'N'=>$p['nft_count'],'Q'=>$p['total_shares'],'issued'=>$sum])]);
            }
            return ['period_id'=>$p['id'],'processed'=>$done,'remaining'=>$remaining,'state'=>$remaining===0?'PUBLISHED':'PROCESSING'];
        });
    }
}
