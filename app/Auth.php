<?php
declare(strict_types=1);
namespace Piscina;
final class Auth {
    public function __construct(private Database $db,private array $config) {}
    public function session(): void {
        if(session_status()===PHP_SESSION_ACTIVE) return;
        $dir=$this->config['session_path'];if(!is_dir($dir)&&!mkdir($dir,0700,true)) throw new \RuntimeException('Armazenamento de sessão indisponível.');
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.save_path',$dir);
        session_name('piscina_session');session_set_cookie_params(['lifetime'=>0,'path'=>($this->config['base_path']??'').'/' ,'secure'=>$this->config['secure_cookie'],'httponly'=>true,'samesite'=>'Lax']);session_start();
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
        if(isset($_SESSION['user_id'])&&time()-($_SESSION['last_seen']??0)>3600) {$this->logout();return;}
        $_SESSION['last_seen']=time();
    }
    public function csrf(): string {$this->session();return $_SESSION['csrf'];}
    public function actor(): int {$this->session();if(empty($_SESSION['user_id'])) throw new Problem('Entre para continuar.',401,'unauthenticated');$u=$this->db->row('SELECT auth_version FROM users WHERE id=?',[$_SESSION['user_id']]);if(!$u||(int)$u['auth_version']!==($_SESSION['auth_version']??0)) {$this->logout();throw new Problem('Sessão encerrada. Entre novamente.',401);}return (int)$_SESSION['user_id'];}
    public function checkCsrf(?string $token): void {$this->session();if(!$token||!hash_equals($_SESSION['csrf'],$token)) throw new Problem('Sessão da página expirou. Atualize e tente novamente.',403,'csrf');}
    public function limit(string $kind,string $identity,int $max=10,int $seconds=900): void {
        $bucket=hash('sha256',$kind.':'.$identity);
        $allowed=$this->db->transaction(function() use($bucket,$max,$seconds){
            $this->db->run('INSERT IGNORE INTO rate_limits(bucket,attempts,expires_at) VALUES(?,0,UTC_TIMESTAMP(6))',[$bucket]);
            $r=$this->db->row('SELECT * FROM rate_limits WHERE bucket=? FOR UPDATE',[$bucket]);$n=$r['expires_at']<=$this->db->now()?1:(int)$r['attempts']+1;
            $expires=$n===1?(new \DateTimeImmutable($this->db->now()))->modify("+$seconds seconds")->format('Y-m-d H:i:s.u'):$r['expires_at'];
            $this->db->run('UPDATE rate_limits SET attempts=?,expires_at=? WHERE bucket=?',[$n,$expires,$bucket]);return $n<=$max;
        });if(!$allowed) throw new Problem('Muitas tentativas. Aguarde alguns minutos.',429,'rate_limit');
    }
    public function register(array $d): array {
        $email=mb_strtolower(trim((string)($d['email']??'')));$name=trim((string)($d['display_name']??''));$password=(string)($d['password']??'');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190) throw new Problem('E-mail inválido.');
        if(mb_strlen($name)<2||mb_strlen($name)>80) throw new Problem('Identificação pública entre 2 e 80 caracteres.');$this->password($password);
        $this->limit('register',$_SERVER['REMOTE_ADDR']??'cli',5);
        return $this->db->transaction(function() use($email,$name,$password){
            if($this->db->row('SELECT id FROM users WHERE email=?',[$email])) throw new Problem('Não foi possível cadastrar esse e-mail.',409);
            $this->db->run('INSERT INTO users(email,password_hash,display_name,created_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$email,password_hash($password,PASSWORD_DEFAULT),$name]);$id=(int)$this->db->pdo->lastInsertId();$this->token($id,'verify');return ['user_id'=>(string)$id,'message'=>'Cadastro criado. Verifique seu e-mail para solicitar aprovação.'];
        });
    }
    private function password(string $p): void {if(strlen($p)<12||strlen($p)>72) throw new Problem('Senha deve ter entre 12 e 72 bytes.');}
    public function login(array $d): array {
        $email=mb_strtolower(trim((string)($d['email']??'')));$this->limit('login',($_SERVER['REMOTE_ADDR']??'cli').':'.$email);
        $u=$this->db->row('SELECT * FROM users WHERE email=?',[$email]);
        $hash=$u['password_hash']??'$2y$10$5pgmi6DdRFXLFVoMVHLBeujbaJ6ri/BGLNpGwNUPlNUYaiojaNCGO';
        if(!password_verify((string)($d['password']??''),$hash)||!$u) throw new Problem('E-mail ou senha incorretos.',401);
        $this->session();session_regenerate_id(true);$_SESSION['user_id']=(int)$u['id'];$_SESSION['auth_version']=(int)$u['auth_version'];$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['last_seen']=time();
        if(password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT)) $this->db->run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($d['password'],PASSWORD_DEFAULT),$u['id']]);
        return ['user'=>['id'=>$u['id'],'display_name'=>$u['display_name'],'status'=>$u['status'],'role'=>$u['role']],'csrf'=>$_SESSION['csrf']];
    }
    public function logout(): array {
        $this->session();$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['last_seen']=time();return ['csrf'=>$_SESSION['csrf'],'logged_out'=>true];
    }
    public function reauthenticate(int $actor,string $password): void {$this->limit('reauth',(string)$actor,10);$u=$this->db->row('SELECT password_hash FROM users WHERE id=?',[$actor]);if(!$u||!password_verify($password,$u['password_hash'])) throw new Problem('Confirme sua senha atual.',403);}
    public function requestToken(array $d,string $kind): array {
        $email=mb_strtolower(trim((string)($d['email']??'')));$this->limit('token',($_SERVER['REMOTE_ADDR']??'cli').':'.$email,4);
        $this->db->transaction(function() use($email,$kind){$u=$this->db->row('SELECT id,email_verified_at FROM users WHERE email=?',[$email]);if($u&&($kind!=='verify'||!$u['email_verified_at'])) $this->token((int)$u['id'],$kind);});
        return ['message'=>'Se o endereço for elegível, enviaremos as instruções.'];
    }
    private function token(int $id,string $kind): void {
        $token=bin2hex(random_bytes(32));$expires=(new \DateTimeImmutable($this->db->now()))->modify($kind==='reset'?'+1 hour':'+24 hours')->format('Y-m-d H:i:s.u');
        $this->db->run('INSERT INTO auth_tokens(user_id,kind,token_hash,expires_at) VALUES(?,?,?,?)',[$id,$kind,hash('sha256',$token),$expires]);
        // Fragmento não chega a logs de URL; frontend o troca por POST e remove da barra.
        $link=rtrim($this->config['url'],'/').($this->config['base_path']??'').'/index.php#'.$kind.'='.$token;
        $this->db->run('INSERT INTO outbox(user_id,subject,body,next_attempt_at) VALUES(?,?,?,UTC_TIMESTAMP(6))',[$id,$kind==='verify'?'Verifique seu e-mail':'Redefina sua senha',"Piscina de Liquidez\n\nAbra: $link\n\nLink de uso único, com validade limitada.\nMoeda de simulação, sem conversão para dinheiro real."]);
    }
    public function useToken(array $d,string $kind): array {
        $token=(string)($d['token']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new Problem('Link inválido ou expirado.');
        $password=(string)($d['password']??'');if($kind==='reset') $this->password($password);
        return $this->db->transaction(function() use($token,$kind,$password){
            $t=$this->db->row('SELECT * FROM auth_tokens WHERE token_hash=? AND kind=? FOR UPDATE',[hash('sha256',$token),$kind]);
            if(!$t||$t['used_at']||$t['expires_at']<=$this->db->now()) throw new Problem('Link inválido, usado ou expirado.');
            $this->db->run('UPDATE auth_tokens SET used_at=UTC_TIMESTAMP(6) WHERE id=?',[$t['id']]);
            if($kind==='verify') $this->db->run('UPDATE users SET email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP(6)) WHERE id=?',[$t['user_id']]);
            else { $this->db->run('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$t['user_id']]);$this->db->run("UPDATE auth_tokens SET used_at=UTC_TIMESTAMP(6) WHERE user_id=? AND kind='reset' AND used_at IS NULL",[$t['user_id']]); }
            return ['message'=>$kind==='verify'?'E-mail verificado. Aguarde aprovação administrativa.':'Senha redefinida. Entre novamente.'];
        });
    }
}
