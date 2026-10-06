<?php
declare(strict_types=1);
require_once __DIR__.'/media.php';
require_once __DIR__.'/content-uploads.php';
function admin_redirect(string $section,string $message='',bool $error=false): never {
    if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest'){
        if(!$error && $message)flash('success',$message);
        header('Content-Type: application/json');
        echo json_encode(['redirect'=>'index.php?section='.$section,'message'=>$message,'error'=>$error]);exit;
    }
    if($message)flash($error?'error':'success',$message);
    header('Location: index.php?section='.$section);exit;
}
function content_for_edit(int $id,bool $trashed=false): array {
    $q=db()->prepare('SELECT c.* FROM content c LEFT JOIN content_state s ON s.content_id=c.id WHERE c.id=? AND '.($trashed?'s.deleted_at IS NOT NULL':'s.deleted_at IS NULL'));
    $q->execute([$id]);$record=$q->fetch();$user=current_user();
    if(!$record || ($user['role']==='contributor' && (int)$record['author_id']!==(int)$user['id']))throw new RuntimeException('Content item is not available to your account.');return $record;
}
function save_content_action(): never {
    $user=current_user();$id=(int)($_POST['id']??0);$originalId=$id;$created=[];
    try {
        $existing=$id?content_for_edit($id):['featured_image'=>'','document_path'=>'','video_url'=>''];
        $type=(string)($_POST['content_type']??'insight');$title=trim((string)($_POST['title']??''));
        if(!$title || mb_strlen($title)>255 || !in_array($type,['insight','publication','project','event','governance'],true))throw new RuntimeException('Add a title of up to 255 characters and a valid content type.');
        $status=$_POST['status']??'draft';if(!in_array($status,['draft','review','published','archived'],true))throw new RuntimeException('Choose a valid status.');
        if($user['role']==='contributor' && !in_array($status,['draft','review'],true))$status='review';
        $event=trim((string)($_POST['event_date']??''));if($event && !DateTime::createFromFormat('Y-m-d\TH:i',$event))throw new RuntimeException('Choose a valid event date.');
        $date=trim((string)($_POST['document_date']??''));if($date && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))))throw new RuntimeException('Choose a valid document date.');
        $uploads=content_uploads($_FILES,content_upload_limit());$extraUploads=multiple_uploads($_FILES,'additional_documents');
        $video=trim((string)($_POST['video_link']??''));
        if($video && (!filter_var($video,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($video,PHP_URL_SCHEME)),['https','http'],true)))throw new RuntimeException('Enter a valid https:// video link.');
        if($video && (isset($uploads['video_file']) || !empty($_POST['video_existing'])))throw new RuntimeException('Choose a video file or an external video link, not both.');
        $slug=slugify(trim((string)($_POST['slug']??''))?:$title);if(strlen($slug)>255)throw new RuntimeException('Shorten the URL label.');
        db()->beginTransaction();
        if($id){$q=db()->prepare('SELECT id FROM content WHERE id=? FOR UPDATE');$q->execute([$id]);$existing=content_for_edit($id);}
        foreach(['image'=>'featured_image','document'=>'document_path','video'=>'video_url'] as $kind=>$column){
            if(!empty($_POST['remove_'.$kind]))$existing[$column]='';
            $selected=(int)($_POST[$kind.'_existing']??0);
            if($selected && isset($uploads[$kind.'_file']))throw new RuntimeException('Choose an existing '.$kind.' or upload a new one, not both.');
            if($selected){$media=editable_media($selected);if(media_kind($media['mime_type'])!==$kind)throw new RuntimeException('Choose a matching file type.');$existing[$column]=$media['stored_name'];}
            if(isset($uploads[$kind.'_file']))$existing[$column]=store_upload($uploads[$kind.'_file'],$created)['path'];
        }
        if($video)$existing['video_url']=$video;
        $data=[$type,$title,$slug,trim((string)($_POST['summary']??'')),trim((string)($_POST['body']??'')),$status,$existing['featured_image'],$existing['document_path'],$existing['video_url']?:null,$event?str_replace('T',' ',$event).':00':null];
        if($id){db()->prepare("UPDATE content SET content_type=?,title=?,slug=?,summary=?,body=?,status=?,featured_image=?,document_path=?,video_url=?,event_date=?,published_at=IF(?='published',COALESCE(published_at,NOW()),NULL) WHERE id=?")->execute([...$data,$status,$id]);}
        else {db()->prepare("INSERT INTO content (content_type,title,slug,summary,body,status,featured_image,document_path,video_url,event_date,author_id,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,IF(?='published',NOW(),NULL))")->execute([...$data,$user['id'],$status]);$id=(int)db()->lastInsertId();}
        db()->prepare('INSERT INTO content_metadata (content_id,authors,category,document_date) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE authors=VALUES(authors),category=VALUES(category),document_date=VALUES(document_date)')->execute([$id,mb_substr(trim((string)($_POST['authors']??'')),0,255),mb_substr(trim((string)($_POST['category']??'')),0,120),$date?:null]);
        $selectedDocuments=$_POST['document_ids']??[];if(!is_array($selectedDocuments) || count($selectedDocuments)>50)throw new RuntimeException('Choose at most 50 attachments.');
        $documentIds=[];
        foreach($selectedDocuments as $selected){$media=editable_media((int)$selected);if(media_kind($media['mime_type'])!=='document')throw new RuntimeException('An attachment must be a document.');$documentIds[]=(int)$media['id'];}
        foreach($extraUploads as $upload)$documentIds[]=store_upload($upload,$created)['id'];
        db()->prepare('DELETE FROM content_documents WHERE content_id=?')->execute([$id]);
        foreach(array_unique($documentIds) as $mid)db()->prepare('INSERT INTO content_documents (content_id,media_id) VALUES (?,?)')->execute([$id,$mid]);
        if($user['role']!=='contributor')db()->prepare('INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')->execute(['homepage_content_'.$id,isset($_POST['show_on_homepage'])?'1':'0',$user['id']]);
        audit($originalId?'update':'create','content',$id);db()->commit();
        admin_redirect('content&type='.urlencode($type),'Content and attachments saved.');
    }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();cleanup_uploads($created);
        $_SESSION['content_form']=['id'=>$originalId,'values'=>array_intersect_key($_POST,array_flip(['title','slug','summary','body','status','content_type','event_date','video_link','authors','category','document_date']))+['show_on_homepage'=>isset($_POST['show_on_homepage'])]];
        error_log('AIDA save content: '.$error->getMessage());
        admin_redirect('editor'.($originalId?'&id='.$originalId:''),$error instanceof PDOException?'Content could not be saved. Check the URL label is unique and try again.':$error->getMessage(),true);
    }
}
function backend_actions(string $action): void {
    if($action==='save_content')save_content_action();
    if(in_array($action,['upload_media','replace_media'],true)){
        $created=[];
        try{
            $replace=$action==='replace_media'?(int)($_POST['media_id']??0):null;
            if($replace)editable_media($replace);
            $uploads=$replace?[validate_upload($_FILES['file']??null)]:multiple_uploads($_FILES,'files','any');
            if(!$uploads || in_array(null,$uploads,true))throw new RuntimeException('Select a file to upload.');
            db()->beginTransaction();
            if($replace){$q=db()->prepare('SELECT id FROM media WHERE id=? FOR UPDATE');$q->execute([$replace]);}
            foreach($uploads as $upload)store_upload($upload,$created,$replace,trim((string)($_POST['alt_text']??'')));
            db()->commit();admin_redirect($replace?'media&file='.$replace:'media',$replace?'File replaced. Previous versions are available below.':'Files uploaded. Choose them in Create Content.');
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();cleanup_uploads($created);error_log('AIDA media upload: '.$error->getMessage());admin_redirect('media',$error instanceof PDOException?'Upload could not be saved. Please try again.':$error->getMessage(),true);}
    }
    if(in_array($action,['delete_content','restore_content','trash_media','restore_media'],true)){
        require_role(['administrator','editor']);
        try{
            if(($_POST['confirm_delete']??'')!=='1' && in_array($action,['delete_content','trash_media'],true))throw new RuntimeException('Confirm before moving this item to Trash.');
            $id=(int)($_POST['id']??0);db()->beginTransaction();
            if(str_contains($action,'content')){
                $q=db()->prepare('SELECT id FROM content WHERE id=? FOR UPDATE');$q->execute([$id]);$record=content_for_edit($id,$action==='restore_content');
                if($action==='delete_content'){
                    db()->prepare('INSERT INTO content_state (content_id,deleted_at,previous_status) VALUES (?,NOW(),?) ON DUPLICATE KEY UPDATE deleted_at=NOW(),previous_status=VALUES(previous_status)')->execute([$id,$record['status']]);
                    db()->prepare("UPDATE content SET status='archived' WHERE id=?")->execute([$id]);
                }else{
                    db()->prepare('UPDATE content_state SET deleted_at=NULL WHERE content_id=?')->execute([$id]);
                    db()->prepare("UPDATE content SET status='draft',published_at=NULL WHERE id=?")->execute([$id]);
                }
            }else{
                $q=db()->prepare('SELECT * FROM media WHERE id=? FOR UPDATE');$q->execute([$id]);$media=$q->fetch();if(!$media)throw new RuntimeException('File not found.');
                if($action==='trash_media' && media_references($media))throw new RuntimeException('This file is attached to content (including Trash). Detach it before moving it to Trash.');
                db()->prepare('INSERT INTO media_state (media_id,deleted_at) VALUES (?,'.($action==='trash_media'?'NOW()':'NULL').') ON DUPLICATE KEY UPDATE deleted_at=VALUES(deleted_at)')->execute([$id]);
            }
            audit($action,str_contains($action,'content')?'content':'media',$id);db()->commit();
            admin_redirect('trash',$action==='restore_content'?'Restored as a draft. Review it before publishing.':'Item updated. Trash items can be restored; uploaded files are preserved.');
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();error_log('AIDA trash: '.$error->getMessage());admin_redirect('trash',$error instanceof PDOException?'Could not update this item.':$error->getMessage(),true);}
    }
    if(in_array($action,['toggle_user','revoke_sessions','reset_password'],true)){
        require_role(['administrator']);
        try{
            require_sensitive_auth();
            $id=(int)($_POST['id']??0);if($id===(int)current_user()['id'])throw new RuntimeException('Use My Security for your own account.');
            db()->beginTransaction();$q=db()->prepare('SELECT * FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$target=$q->fetch();if(!$target)throw new RuntimeException('Account not found.');
            security_record($id);
            if($action==='toggle_user')db()->prepare('UPDATE users SET is_active=? WHERE id=?')->execute([(int)!$target['is_active'],$id]);
            if($action==='reset_password'){
                $password=(string)($_POST['new_password']??'');if(strlen($password)<12 || strlen($password)>72)throw new RuntimeException('Use a new password between 12 and 72 bytes.');
                db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
            }
            db()->prepare('UPDATE user_security SET session_version=session_version+1 WHERE user_id=?')->execute([$id]);audit($action,'user',$id);db()->commit();admin_redirect('users','Account updated; existing sessions have been revoked.');
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();admin_redirect('users',$error instanceof PDOException?'Could not update this account.':$error->getMessage(),true);}
    }
}
