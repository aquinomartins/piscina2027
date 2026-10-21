<?php
declare(strict_types=1);
namespace Piscina;

// Caminho administrativo separado: o envio único do criador permanece em Upload.
final class AdminImageUpload {
    public function __construct(private Domain $domain,private array $config) {}

    public function save(int $actor,int $nft,array $file,string $key,array $data): array {
        $this->domain->admin($actor);
        $reason=$data['reason']??null;$version=$data['expected_version']??null;
        if(!is_string($reason)||mb_strlen(trim($reason))<5||mb_strlen(trim($reason))>255||($data['confirm']??false)!==true)
            throw new Problem('Confirme a troca e informe um motivo de 5 a 255 caracteres.');
        if(!is_string($version)||!preg_match('/^[a-f0-9]{64}$/D',$version)) throw new Problem('Consulte a versão atual da NFT antes de trocar a imagem.');
        if(!preg_match('/^[A-Za-z0-9_-]{16,80}$/D',$key)) throw new Problem('Chave de idempotência inválida.');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')) throw new Problem('Envio incompleto. Selecione uma imagem e tente novamente.');
        if($file['size']<1||$file['size']>($this->config['upload_bytes']??5242880)) throw new Problem('Imagem excede o limite de envio.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$info=@getimagesize($file['tmp_name']);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$info||$info[0]<1||$info[1]<1||(int)$info[0]*(int)$info[1]>($this->config['upload_pixels']??16000000))
            throw new Problem('Use JPG, PNG ou WebP válido, dentro dos limites de dimensões.');
        $source=@imagecreatefromstring(file_get_contents($file['tmp_name']));
        if(!$source) throw new Problem('Não foi possível decodificar a imagem.');
        $payload=['nft_id'=>(string)$nft,'reason'=>trim($reason),'confirm'=>true,'expected_version'=>$version,'image_sha'=>hash_file('sha256',$file['tmp_name'])];
        $paths=[];
        try {
            $old=$this->domain->db->row("SELECT payload_hash,result FROM operations WHERE actor_id=? AND action='admin.nft.image_replace' AND idempotency_key=?",[$actor,$key]);
            if($old) {
                if(!hash_equals($old['payload_hash'],hash('sha256',Domain::canonical($payload)))) throw new Problem('Chave já usada com outra imagem ou condições.',409,'idempotency_conflict');
                return json_decode($old['result'],true,512,JSON_THROW_ON_ERROR);
            }
            $dir=$this->config['storage'].'/images';
            if(!is_dir($dir)&&!mkdir($dir,0700,true)) throw new \RuntimeException('Armazenamento indisponível.');
            $name=bin2hex(random_bytes(16));$paths=[$name.'.jpg',$name.'-thumb.jpg'];
            foreach([1600,480] as $i=>$max) {
                $ratio=min(1,$max/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$ratio));$h=max(1,(int)round($info[1]*$ratio));
                $img=imagecreatetruecolor($w,$h);
                try {
                    imagefill($img,0,0,imagecolorallocate($img,255,255,255));
                    imagecopyresampled($img,$source,0,0,0,0,$w,$h,$info[0],$info[1]);
                    if(!imagejpeg($img,$dir.'/'.$paths[$i],86)||!chmod($dir.'/'.$paths[$i],0600)) throw new \RuntimeException('Falha ao salvar imagem.');
                } finally {imagedestroy($img);}
            }
            $result=$this->domain->operation($actor,'admin.nft.image_replace',$payload+['image_path'=>$paths[0],'thumbnail_path'=>$paths[1]],$key);
            try {$this->discardUnused($paths);} catch(\Throwable) {error_log('Piscina: limpeza de upload administrativo adiada para preservar arquivos.');}
            return $result;
        } catch(\Throwable $e) {
            // Se o resultado do commit for incerto, retenha arquivos até ser possível conferir referências.
            try {$this->discardUnused($paths);} catch(\Throwable) {error_log('Piscina: limpeza de upload administrativo adiada para preservar arquivos.');}
            throw $e;
        } finally {imagedestroy($source);}
    }

    private function discardUnused(array $paths): void {
        if(!$paths)return;
        $used=$this->domain->db->row("SELECT
            (EXISTS(SELECT 1 FROM nfts WHERE image_path=?) OR
             EXISTS(SELECT 1 FROM audit_events WHERE kind='admin.nft.image_replace'
                    AND JSON_UNQUOTE(JSON_EXTRACT(data,'$.input.image_path'))=?)) used",[$paths[0],$paths[0]]);
        if($used['used']==='1')return;
        foreach($paths as $path) {
            $file=$this->config['storage'].'/images/'.$path;
            if(is_file($file))unlink($file);
        }
    }
}
