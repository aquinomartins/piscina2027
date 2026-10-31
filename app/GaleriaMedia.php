<?php
declare(strict_types=1);
namespace Piscina;

final class GaleriaMedia {
    public function __construct(private Domain $domain){}
    public function save(int $actor,int $frame,array $file,array $data,string $key): array {
        $this->domain->admin($actor);GaleriaInput::confirmed($data);$reason=GaleriaInput::text($data['reason']??null,255,5);$c=$this->domain->config;
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||$file['size']<1||$file['size']>($c['upload_bytes']??5242880))throw new Problem('Selecione imagem válida dentro do limite.');
        $info=@getimagesize($file['tmp_name']);$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp'],true)||$info[0]*$info[1]>($c['upload_pixels']??16000000))throw new Problem('Use JPG, PNG ou WebP dentro do limite de pixels.');
        $source=@imagecreatefromstring(file_get_contents($file['tmp_name']));if(!$source)throw new Problem('Imagem não decodificável.');
        $dir=$c['storage'].'/galeria';if(!is_dir($dir))mkdir($dir,0700,true);$path=bin2hex(random_bytes(16)).'.jpg';$max=1200;$ratio=min(1,$max/max($info[0],$info[1]));$img=imagecreatetruecolor(max(1,(int)round($info[0]*$ratio)),max(1,(int)round($info[1]*$ratio)));
        try{imagefill($img,0,0,imagecolorallocate($img,255,255,255));imagecopyresampled($img,$source,0,0,0,0,imagesx($img),imagesy($img),$info[0],$info[1]);if(!imagejpeg($img,$dir.'/'.$path,86)||!chmod($dir.'/'.$path,0600))throw new \RuntimeException('Armazenamento de fotografia indisponível.');
            return $this->domain->operation($actor,'admin.galeria.frame.photo',['frame_id'=>(string)$frame,'path'=>$path,'image_sha'=>hash_file('sha256',$file['tmp_name']),'confirm'=>true,'reason'=>$reason],$key);
        }finally{imagedestroy($source);imagedestroy($img);try{if(!$this->domain->db->row('SELECT id FROM galeria_photos WHERE path=?',[$path])&&is_file($dir.'/'.$path))unlink($dir.'/'.$path);}catch(\Throwable){error_log('Piscina Galeria: fotografia retida para conciliação de referência.');}}
    }
    public function file(array $q,?int $viewer): string {
        if(!Galeria::installed($this->domain->db))throw new Problem('Mídia indisponível.',404);
        if(isset($q['photo_id'])){$r=$this->domain->db->row('SELECT p.path,f.status FROM galeria_photos p JOIN galeria_frames f ON f.id=p.frame_id WHERE p.id=?',[Domain::id($q['photo_id'])]);if(!$r)throw new Problem('Mídia indisponível.',404);if($r['status']==='DRAFT'){if(!$viewer)throw new Problem('Mídia indisponível.',404);$this->domain->admin($viewer);}$path=$r['path'];$dir='galeria';}
        elseif(isset($q['candidate_id'])){$r=$this->domain->db->row('SELECT c.snapshot,c.state,e.state event_state FROM galeria_candidates c JOIN galeria_events e ON e.id=c.event_id WHERE c.id=?',[Domain::id($q['candidate_id'])]);if(!$r||$r['snapshot']===null||$r['state']!=='VALID'||!in_array($r['event_state'],['PREPARED','VOTING','REVIEW','FINALIZED','SETTLED'],true))throw new Problem('Exposição indisponível.',404);$t=GaleriaInput::decode($r['snapshot']);$path=$t['image']['image_path'];$dir='images';}
        else throw new Problem('Mídia não encontrada.',404);
        if(!preg_match('/^[a-f0-9]{32}(?:-thumb)?\.jpg$/D',$path))throw new Problem('Mídia inválida.',404);$file=$this->domain->config['storage'].'/'.$dir.'/'.$path;if(!is_file($file))throw new Problem('Mídia indisponível.',404);return $file;
    }
}
