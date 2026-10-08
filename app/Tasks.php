<?php
declare(strict_types=1);
namespace Piscina;
final class Tasks {
    public function __construct(private Domain $domain,private array $config) {}
    public function run(): array {
        $db=$this->domain->db;
        if($db->row("SELECT GET_LOCK('piscina_cron',0) acquired")['acquired']!=='1') return ['status'=>'BUSY'];
        $start=microtime(true);$run=null;
        try {
            $db->run("INSERT INTO task_runs(started_at,status) VALUES(UTC_TIMESTAMP(6),'RUNNING')");$run=$db->pdo->lastInsertId();
            $snapshot=$this->domain->periods->capture();$batches=[];
            while(microtime(true)-$start<min(50,$this->config['cron_seconds']??50)-30) {$r=$this->domain->periods->processBatch($this->config['max_batch']??100);if(!$r) break;$batches[]=$r;}
            $expired=$this->domain->expire();$mail=(new Mailer($db,$this->config))->batch(5);$cleanup=(new Upload($this->domain,$this->config))->cleanup();
            $db->run('DELETE FROM rate_limits WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(6),INTERVAL 2 DAY)');
            $summary=['status'=>'SUCCESS','snapshots'=>$snapshot,'batches'=>$batches,'expired'=>$expired,'mail'=>$mail,'cleanup'=>$cleanup,'seconds'=>round(microtime(true)-$start,3)];
            $db->run("UPDATE task_runs SET status='SUCCESS',finished_at=UTC_TIMESTAMP(6),summary=? WHERE id=?",[json_encode($summary),$run]);return $summary;
        } catch(\Throwable $e) {if($run) $db->run("UPDATE task_runs SET status='FAILED',finished_at=UTC_TIMESTAMP(6),summary=? WHERE id=?",[json_encode(['error'=>'Falha de tarefa. Verifique log privado.']),$run]);throw $e;}
        finally {$db->row("SELECT RELEASE_LOCK('piscina_cron')");}
    }
}
