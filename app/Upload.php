<?php
declare(strict_types=1);
namespace Piscina;
final class Upload {
    public function __construct(private Domain $domain,private array $config) {}
    public function save(int $actor,int $nft,array $file,string $key): array {
        $this->domain->user($actor,true);
        // Repetir um envio já concluído consulta a operação sem criar novos arquivos.
        $old=$this->domain->db->row("SELECT payload_hash,result FROM operations WHERE actor_id=? AND action='nft.image' AND idempotency_key=?",[$actor,$key]);
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new Problem('Envio incompleto. Tente novamente.');
        if($file['size']<1||$file['size']>($this->config['upload_bytes']??5242880)) throw new Problem('Imagem excede o limite de envio.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$info=@getimagesize($file['tmp_name']);
        if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$info||$info[0]<1||$info[1]<1||(int)$info[0]*(int)$info[1]>($this->config['upload_pixels']??16000000)) throw new Problem('Use JPG, PNG ou WebP válido, dentro dos limites de dimensões.');
        $source=@imagecreatefromstring(file_get_contents($file['tmp_name']));if(!$source) throw new Problem('Não foi possível decodificar a imagem.');
        $sha=hash_file('sha256',$file['tmp_name']);
        if($old) {imagedestroy($source);$res=json_decode($old['result'],true,512,JSON_THROW_ON_ERROR);$prior=$this->domain->db->row('SELECT data FROM audit_events WHERE operation_id=? AND kind=?',[$res['operation_id'],'nft.image']);$input=json_decode($prior['data'],true)['input'];if($input['image_sha']!==$sha||(string)$input['nft_id']!==(string)$nft) throw new Problem('Chave já usada com outra imagem.',409);return $res;}
        $dir=$this->config['storage'].'/images';if(!is_dir($dir)&&!mkdir($dir,0700,true)) throw new \RuntimeException('Armazenamento indisponível.');
        $name=bin2hex(random_bytes(16));$paths=[$name.'.jpg',$name.'-thumb.jpg'];
        try {
            foreach([1600,480] as $i=>$max) {
                $ratio=min(1,$max/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$ratio));$h=max(1,(int)round($info[1]*$ratio));
                $img=imagecreatetruecolor($w,$h);imagefill($img,0,0,imagecolorallocate($img,255,255,255));imagecopyresampled($img,$source,0,0,0,0,$w,$h,$info[0],$info[1]);
                if(!imagejpeg($img,$dir.'/'.$paths[$i],86)) throw new \RuntimeException('Falha ao salvar imagem.');imagedestroy($img);chmod($dir.'/'.$paths[$i],0600);
            }
            $result=$this->domain->operation($actor,'nft.image',['nft_id'=>(string)$nft,'image_path'=>$paths[0],'thumbnail_path'=>$paths[1],'image_sha'=>$sha],$key);
            $used=$this->domain->db->row('SELECT id FROM nfts WHERE image_path=?',[$paths[0]]);if(!$used)foreach($paths as $p)if(is_file($dir.'/'.$p))unlink($dir.'/'.$p);return $result;
        } catch(\Throwable $e) {
            // Resultado desconhecido após commit: só remove se nenhum registro se associou aos arquivos.
            $used=$this->domain->db->row('SELECT id FROM nfts WHERE image_path=?',[$paths[0]]);
            if(!$used) foreach($paths as $p) if(is_file($dir.'/'.$p)) unlink($dir.'/'.$p);
            throw $e;
        } finally {imagedestroy($source);}
    }
    public function cleanup(): int {
        $count=0;$dir=$this->config['storage'].'/images';if(!is_dir($dir)) return 0;
        $associated=[];foreach($this->domain->db->all('SELECT image_path,thumbnail_path FROM nfts WHERE image_path IS NOT NULL')as $r){$associated[$r['image_path']]=true;$associated[$r['thumbnail_path']]=true;}
        foreach(glob($dir.'/*.jpg') as $file) {if(filemtime($file)>time()-86400) continue;$name=basename($file);if(!isset($associated[$name])) {unlink($file);$count++;}}
        return $count;
    }
}
