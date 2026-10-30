<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
try {
    $id=Piscina\Domain::id($_GET['id']??null);$n=db()->row('SELECT image_path,thumbnail_path,state FROM nfts WHERE id=?',[$id]);
    $private=($n['state']??'')==='CUSTODY';
    if($private){header('Cache-Control: no-store, private');$auth=new Piscina\Auth(db(),config());service()->admin($auth->actor());session_write_close();}
    $path=$n[isset($_GET['thumb'])?'thumbnail_path':'image_path']??null;
    if(!$path) {header('Content-Type: image/svg+xml');header('Cache-Control: no-store');readfile(__DIR__.'/assets/placeholder.svg');exit;}
    $file=config()['storage'].'/images/'.$path;if(!is_file($file)) throw new RuntimeException();
    header('Content-Type: image/jpeg');header('X-Content-Type-Options: nosniff');if(!$private)header('Cache-Control: public, max-age=3600');readfile($file);
} catch(Throwable){http_response_code(404);}
