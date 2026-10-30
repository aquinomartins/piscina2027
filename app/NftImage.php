<?php
declare(strict_types=1);
namespace Piscina;

final class NftImage {
    // A versão não expõe nomes de arquivo e muda mesmo ao reenviar a mesma imagem.
    public const SQL_VERSION = "SHA2(CONCAT(COALESCE(n.image_path,''),':',COALESCE(n.thumbnail_path,''),':',COALESCE(n.image_sha,'')),256)";

    public static function version(array $nft): string {
        return hash('sha256',($nft['image_path']??'').':'.($nft['thumbnail_path']??'').':'.($nft['image_sha']??''));
    }

    public static function snapshot(array $nft): array {
        return ['image_path'=>$nft['image_path'],'thumbnail_path'=>$nft['thumbnail_path'],
            'image_sha'=>$nft['image_sha'],'version'=>self::version($nft)];
    }

    public static function editVersion(array $nft): string {
        return hash('sha256',self::version($nft).':'.$nft['creator_id'].':'.($nft['owner_id']??'').':'.$nft['state'].':'.($nft['reserved_proposal']??''));
    }
}
