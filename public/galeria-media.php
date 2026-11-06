<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
try{$viewer=null;if(isset($_GET['photo_id'])){$r=db()->row('SELECT f.status FROM galeria_photos p JOIN galeria_frames f ON f.id=p.frame_id WHERE p.id=?',[Piscina\Domain::id($_GET['photo_id'])]);if(($r['status']??'')==='DRAFT'){$auth=new Piscina\Auth(db(),config());$viewer=$auth->actor();session_write_close();}}
    $file=(new Piscina\GaleriaMedia(service()))->file($_GET,$viewer);header('Content-Type: image/jpeg');readfile($file);
}catch(Throwable){http_response_code(404);}
