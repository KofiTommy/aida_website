<?php
declare(strict_types=1);
function media_kind(string $mime): string {return str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':'document');}
function editable_media(int $id): array {
    $q=db()->prepare('SELECT m.* FROM media m LEFT JOIN media_state s ON s.media_id=m.id WHERE m.id=? AND s.deleted_at IS NULL'.(db()->inTransaction()?' FOR UPDATE':''));$q->execute([$id]);$media=$q->fetch();$user=current_user();
    if(!$media || !$user || ($user['role']==='contributor' && (int)$media['uploaded_by']!==(int)$user['id']))throw new RuntimeException('That file is not available to your account.');
    return $media;
}
function media_url(?string $path): string {
    if(!$path)return '';
    if(preg_match('~^https?://~i',$path))return filter_var($path,FILTER_VALIDATE_URL)?$path:'';
    if(str_starts_with($path,'uploads/') || str_starts_with($path,'storage/media/')){
        static $cache=[];if(isset($cache[$path]))return $cache[$path];
        $q=db()->prepare('SELECT id FROM media WHERE stored_name=?');$q->execute([$path]);$id=$q->fetchColumn();return $cache[$path]=$id?'file.php?id='.(int)$id:'';
    }
    // Only root-level image assets are eligible for a non-library URL.
    return preg_match('~^[a-zA-Z0-9 _.-]+\.(png|jpg|jpeg|webp)$~i',$path)?$path:'';
}
function media_disk_path(string $path): string {
    if(!preg_match('~^(uploads/|storage/media/)[a-zA-Z0-9/_ .-]+$~D',$path) || str_contains($path,'..'))throw new RuntimeException('Invalid stored file path.');
    $absolute=realpath(__DIR__.'/../'.$path);$root=realpath(__DIR__.'/..');
    if(!$absolute || !str_starts_with(str_replace('\\','/',$absolute),str_replace('\\','/',$root).'/') || !is_file($absolute))throw new RuntimeException('File unavailable.');return $absolute;
}
function media_references(array $media): array {
    $q=db()->prepare('SELECT DISTINCT c.id,c.title,c.status,s.deleted_at FROM content c LEFT JOIN content_state s ON s.content_id=c.id LEFT JOIN content_documents d ON d.content_id=c.id WHERE c.featured_image=? OR c.document_path=? OR c.video_url=? OR d.media_id=?');
    $q->execute([$media['stored_name'],$media['stored_name'],$media['stored_name'],$media['id']]);return $q->fetchAll();
}
function media_is_public(array $media): bool {
    foreach(media_references($media) as $content)if($content['status']==='published' && !$content['deleted_at'])return true;return false;
}
function content_extra_documents(int $id): array {
    $q=db()->prepare('SELECT m.id,m.original_name,m.stored_name FROM content_documents d JOIN media m ON m.id=d.media_id LEFT JOIN media_state s ON s.media_id=m.id WHERE d.content_id=? AND s.deleted_at IS NULL ORDER BY m.original_name');$q->execute([$id]);return $q->fetchAll();
}
