<?php
declare(strict_types=1);
namespace Piscina;

final class ImageReferences {
    public static function used(Database $db,string $path): bool {
        if($db->row('SELECT id FROM nfts WHERE image_path=? OR thumbnail_path=? LIMIT 1',[$path,$path]))return true;
        if($db->row("SELECT id FROM audit_events WHERE kind='admin.nft.image_replace' AND (JSON_UNQUOTE(JSON_EXTRACT(data,'$.input.image_path'))=? OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.input.thumbnail_path'))=? OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.result.previous.image_path'))=? OR JSON_UNQUOTE(JSON_EXTRACT(data,'$.result.previous.thumbnail_path'))=?) LIMIT 1",[$path,$path,$path,$path]))return true;
        if(Galeria::installed($db)&&$db->row("SELECT id FROM galeria_agreements WHERE JSON_UNQUOTE(JSON_EXTRACT(terms,'$.image.image_path'))=? OR JSON_UNQUOTE(JSON_EXTRACT(terms,'$.image.thumbnail_path'))=? LIMIT 1",[$path,$path]))return true;
        return false;
    }
}
