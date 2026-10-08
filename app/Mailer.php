<?php
declare(strict_types=1);
namespace Piscina;
final class Mailer {
    public function __construct(private Database $db,private array $config) {}
    public function batch(int $limit=10): int {
        $count=0;
        for($i=0;$i<min(10,$limit);$i++) {
            $row=$this->db->transaction(function(){
                $r=$this->db->row("SELECT o.*,u.email FROM outbox o JOIN users u ON u.id=o.user_id WHERE o.state<>'SENT' AND o.next_attempt_at<=UTC_TIMESTAMP(6) AND (o.locked_until IS NULL OR o.locked_until<UTC_TIMESTAMP(6)) ORDER BY o.id LIMIT 1 FOR UPDATE");
                if($r) $this->db->run('UPDATE outbox SET locked_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 5 MINUTE),attempts=attempts+1 WHERE id=?',[$r['id']]);return $r;
            });if(!$row) break;
            try {
                if(($this->config['mail_transport']??'smtp')==='file' && $this->config['environment']==='demo') {
                    $dir=$this->config['storage'].'/mail';if(!is_dir($dir)) mkdir($dir,0700,true);
                    if(file_put_contents($dir.'/'.$row['id'].'.txt',"CAIXA DE TESTE — NÃO ENVIADO\nPara: ".$row['email']."\n".$row['subject']."\n".$row['body'])===false) throw new \RuntimeException('Falha na caixa local.');
                } else {
                    $c=$this->config['smtp'];if(!$c['host']||!$c['from']) throw new \RuntimeException('SMTP não configurado.');
                    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Host=$c['host'];$mail->Port=$c['port'];$mail->SMTPAuth=$c['username']!=='';$mail->Username=$c['username'];$mail->Password=$c['password'];$mail->SMTPSecure=$c['encryption'];$mail->Timeout=5;$mail->CharSet='UTF-8';$mail->setFrom($c['from'],$c['name']);$mail->addAddress($row['email']);$mail->Subject=$row['subject'];$mail->Body=$row['body'];$mail->send();
                }
                $this->db->run("UPDATE outbox SET state='SENT',locked_until=NULL,last_error=NULL WHERE id=?",[$row['id']]);$count++;
            } catch(\Throwable $e) {
                // Nunca grava respostas SMTP que possam conter credenciais ou tokens.
                $this->db->run("UPDATE outbox SET state='RETRY',locked_until=NULL,last_error='Falha no transporte; verificar configuração e log privado',next_attempt_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 15 MINUTE) WHERE id=?",[$row['id']]);
            }
        }
        return $count;
    }
}
