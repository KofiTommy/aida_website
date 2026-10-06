<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/content-uploads.php';
require_login();

$types=['insight'=>'Insights & News','publication'=>'Publications','project'=>'Projects & Impact','event'=>'Events & Videos','governance'=>'Governance Documents'];
$section=$_GET['section'] ?? 'dashboard';
if (!in_array($section,['dashboard','content','editor','delete','media','messages','applications','settings','users'],true)) $section='dashboard';
$user=current_user();

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0) {
        flash('error','The attachments exceed the server upload limit. Please select smaller files.');
        header('Location: index.php?section=editor'); exit;
    }
    verify_csrf(); $action=$_POST['action'] ?? '';
    if ($action==='delete_content') {
        require_role(['administrator','editor']);
        $id=(int)($_POST['id']??0);
        if (($_POST['confirm_delete']??'')!=='1') {
            flash('error','Confirm deletion before continuing.');
            header('Location: index.php?section=delete&id='.$id); exit;
        }
        try {
            db()->beginTransaction();
            $q=db()->prepare('SELECT id FROM content WHERE id=? FOR UPDATE'); $q->execute([$id]);
            if (!$q->fetch()) throw new RuntimeException('Content item not found.');
            db()->prepare('DELETE FROM content WHERE id=?')->execute([$id]);
            db()->prepare('DELETE FROM site_settings WHERE setting_key=?')->execute(['homepage_content_'.$id]);
            audit('delete','content',$id); db()->commit();
            flash('success','Content deleted from the website. Uploaded files remain in Media & Documents.');
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            flash('error','The content could not be deleted. Please refresh and try again.');
        }
        header('Location: index.php?section=content'); exit;
    }
    if ($action==='review_application') {
        require_role(['administrator','editor']);
        $status=$_POST['status']??'';
        if(is_string($status) && in_array($status,['new','reviewing','accepted','declined'],true)) {
            $id=(int)($_POST['id']??0);
            db()->prepare('UPDATE applications SET status=? WHERE id=?')->execute([$status,$id]);
            audit('review','application',$id);
            flash('success','Application status updated.');
        }
        header('Location: index.php?section=applications'); exit;
    }
    if ($action==='save_content') {
        require_role(['administrator','editor','contributor']);
        $id=(int)($_POST['id']??0); $type=$_POST['content_type']??'insight'; $title=trim($_POST['title']??''); $status=$_POST['status']??'draft';
        if (!$title || !isset($types[$type])) { flash('error','Add a title and content type.'); header('Location: index.php?section=editor'.($id?'&id='.$id:'')); exit; }
        if ($user['role']==='contributor' && $status==='published') $status='review';
        if (!in_array($status,['draft','review','published','archived'],true)) $status='draft';
        $existing=['featured_image'=>'','document_path'=>'','video_url'=>''];
        if ($id) {
            $q=db()->prepare('SELECT * FROM content WHERE id=?'.($user['role']==='contributor'?' AND author_id=?':''));
            $q->execute($user['role']==='contributor'?[$id,$user['id']]:[$id]);
            $existing=$q->fetch();
            if (!$existing) { http_response_code(404); exit('Content item not found.'); }
        }
        $storedFiles=[];
        try {
            $uploads=content_uploads($_FILES,content_upload_limit());
            $videoLink=trim($_POST['video_link']??'');
            if ($videoLink && (!filter_var($videoLink,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($videoLink,PHP_URL_SCHEME)),['http','https'],true))) {
                throw new RuntimeException('Enter a valid video link starting with https://.');
            }
            if ($videoLink && isset($uploads['video_file'])) throw new RuntimeException('Choose either a video file or a video link, not both.');
            db()->beginTransaction();
            foreach ($uploads as $field=>$upload) {
                $relative='uploads/'.date('Y/m'); $dir=__DIR__.'/../'.$relative;
                if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('The server could not create the upload folder.');
                $path=$relative.'/'.bin2hex(random_bytes(16)).'.'.$upload['extension'];
                $absolute=__DIR__.'/../'.$path;
                if (!move_uploaded_file($upload['tmp'],$absolute)) throw new RuntimeException('The server could not store the attachment.');
                $storedFiles[]=$absolute;
                db()->prepare('INSERT INTO media (original_name,stored_name,mime_type,file_size,alt_text,uploaded_by) VALUES (?,?,?,?,?,?)')->execute([$upload['name'],$path,$upload['mime'],$upload['size'],'',$user['id']]);
                audit('upload','media',(int)db()->lastInsertId());
                $column=['image_file'=>'featured_image','document_file'=>'document_path','video_file'=>'video_url'][$field];
                $existing[$column]=$path;
            }
            if ($videoLink) $existing['video_url']=$videoLink;
            $slug=slugify(($_POST['slug']??'')?:$title);
            $data=[$type,$title,$slug,trim($_POST['summary']??''),trim($_POST['body']??''),$status,$existing['featured_image'],$existing['document_path'],$existing['video_url']?:null,($_POST['event_date']??'')?:null];
        if ($id) {
            $where='id=?'; $params=[$id]; if ($user['role']==='contributor') {$where.=' AND author_id=?';$params[]=$user['id'];}
            $stmt=db()->prepare("UPDATE content SET content_type=?,title=?,slug=?,summary=?,body=?,status=?,featured_image=?,document_path=?,video_url=?,event_date=?,published_at=IF(?='published',COALESCE(published_at,NOW()),NULL) WHERE $where");
            $stmt->execute([...$data,$status,...$params]); audit('update','content',$id); flash('success','Content saved successfully.');
        } else {
            $stmt=db()->prepare("INSERT INTO content (content_type,title,slug,summary,body,status,featured_image,document_path,video_url,event_date,author_id,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,IF(?='published',NOW(),NULL))");
            $stmt->execute([...$data,$user['id'],$status]); $id=(int)db()->lastInsertId(); audit('create','content',$id); flash('success','Content created successfully.');
        }
            if (in_array($user['role'],['administrator','editor'],true)) {
                db()->prepare('INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)')->execute(['homepage_content_'.$id,isset($_POST['show_on_homepage'])?'1':'0',$user['id']]);
            }
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            foreach ($storedFiles as $storedFile) { if (is_file($storedFile)) unlink($storedFile); }
            $_SESSION['content_form']=['id'=>$id,'values'=>array_intersect_key($_POST,array_flip(['title','slug','summary','body','status','content_type','event_date','video_link','show_on_homepage']))];
            flash('success');
            flash('error',$exception instanceof PDOException ? 'Content could not be saved. Check that the URL label is unique and try again.' : $exception->getMessage());
            header('Location: index.php?section=editor'.($id?'&id='.$id:'')); exit;
        }
        header('Location: index.php?section=content&type='.urlencode($type)); exit;
    }
    if ($action==='upload_media') {
        require_role(['administrator','editor','contributor']);
        $file=$_FILES['file']??null; $allowed=['image/jpeg','image/png','image/webp','application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/vnd.openxmlformats-officedocument.presentationml.presentation'];
        global $config; $max=($config['upload_max_mb']??25)*1024*1024;
        if (!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>$max) { flash('error','Upload failed. Use a permitted file under '.($config['upload_max_mb']??25).' MB.'); }
        else { $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']); if(!in_array($mime,$allowed,true)){flash('error','That file type is not allowed.');} else { $dir=__DIR__.'/../uploads/'.date('Y/m'); if(!is_dir($dir))mkdir($dir,0750,true); $extension=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$stored=bin2hex(random_bytes(16)).'.'.$extension; if(move_uploaded_file($file['tmp_name'],$dir.'/'.$stored)){ $path='uploads/'.date('Y/m').'/'.$stored; db()->prepare('INSERT INTO media (original_name,stored_name,mime_type,file_size,alt_text,uploaded_by) VALUES (?,?,?,?,?,?)')->execute([$file['name'],$path,$mime,$file['size'],trim($_POST['alt_text']??''),$user['id']]);audit('upload','media',(int)db()->lastInsertId());flash('success','File uploaded. Copy its path into a content item when needed.');}else{flash('error','The server could not store that file.');} } }
        header('Location: index.php?section=media'); exit;
    }
    if ($action==='save_settings') { require_role(['administrator']); foreach(['hero_title','hero_text','contact_email','contact_phone','contact_address'] as $key){$stmt=db()->prepare('INSERT INTO site_settings (setting_key,setting_value,updated_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');$stmt->execute([$key,trim($_POST[$key]??''),$user['id']]);} audit('update','settings');flash('success','Website settings updated.');header('Location: index.php?section=settings');exit; }
    if ($action==='create_user') { require_role(['administrator']);$name=trim($_POST['name']??'');$email=filter_var(trim($_POST['email']??''),FILTER_VALIDATE_EMAIL);$password=$_POST['password']??'';$role=$_POST['role']??'contributor';if(!$name||!$email||strlen($password)<12||!in_array($role,['administrator','editor','contributor'],true)){flash('error','Use a name, valid email, role, and password of at least 12 characters.');}else{try{db()->prepare('INSERT INTO users(name,email,password_hash,role)VALUES(?,?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);audit('create','user',(int)db()->lastInsertId());flash('success','Team account created.');}catch(PDOException $e){flash('error','That email address is already in use.');}}header('Location: index.php?section=users');exit; }
}
function admin_header(string $title,string $section): void { global $user; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?> | AIDA Portal</title><link rel="stylesheet" href="../assets/css/admin.css"></head><body><div class="admin-shell"><aside class="admin-side"><a class="brand" href="../index.php"><img src="../Logo.png" alt="AIDA"></a><nav><a class="<?= $section==='dashboard'?'active':'' ?>" href="index.php">Overview</a><a class="<?= in_array($section,['content','editor'],true)?'active':'' ?>" href="index.php?section=content">Content</a><a class="<?= $section==='media'?'active':'' ?>" href="index.php?section=media">Media & Documents</a><?php if(in_array($user['role'],['administrator','editor'],true)): ?><a class="<?= $section==='messages'?'active':'' ?>" href="index.php?section=messages">Messages</a><a class="<?= $section==='applications'?'active':'' ?>" href="index.php?section=applications">Applications</a><?php endif; ?><?php if($user['role']==='administrator'): ?><a class="<?= $section==='settings'?'active':'' ?>" href="index.php?section=settings">Home Page</a><a class="<?= $section==='users'?'active':'' ?>" href="index.php?section=users">Team Access</a><?php endif; ?></nav><a class="signout" href="logout.php">Sign out</a></aside><main class="admin-main"><header class="admin-top"><div><h1><?= e($title) ?></h1><p>Manage AIDA’s public website.</p></div><span class="user-pill"><?= e($user['name']) ?> · <?= e(ucfirst($user['role'])) ?></span></header><?php if($m=flash('success')):?><div class="admin-alert success"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="admin-alert error"><?=e($m)?></div><?php endif;?>
<?php }
function admin_footer(): void { echo '</main></div></body></html>'; }

if ($section==='dashboard') { require_role(['administrator','editor','contributor']); $counts=[];foreach(['insight','publication','project','event'] as $t){$q=db()->prepare('SELECT COUNT(*) FROM content WHERE content_type=?'.($user['role']==='contributor'?' AND author_id='.(int)$user['id']:''));$q->execute([$t]);$counts[$t]=$q->fetchColumn();}$recent=db()->query('SELECT c.*,u.name author FROM content c JOIN users u ON u.id=c.author_id ORDER BY c.updated_at DESC LIMIT 7')->fetchAll();admin_header('Overview','dashboard'); ?>
<div class="metric-grid"><?php foreach(['insight'=>'Insights','publication'=>'Publications','project'=>'Projects','event'=>'Events'] as $k=>$label):?><div class="metric"><b><?= $counts[$k] ?></b><span><?=e($label)?></span></div><?php endforeach;?></div><section class="admin-card"><div class="admin-card-header"><h2>Recent updates</h2><a class="admin-link" href="index.php?section=editor">+ Create content</a></div><table class="data-table"><tr><th>Title</th><th>Type</th><th>Status</th><th>Updated</th></tr><?php foreach($recent as $item):?><tr><td><a href="index.php?section=editor&id=<?=$item['id']?>"><?=e($item['title'])?></a></td><td><?=e($types[$item['content_type']])?></td><td><span class="badge <?=e($item['status'])?>"><?=e($item['status'])?></span></td><td><?=e(date('d M Y',strtotime($item['updated_at'])))?></td></tr><?php endforeach;?></table></section><?php admin_footer(); exit; }

if ($section==='content') { $type=$_GET['type']??'';$sql='SELECT c.*,u.name author FROM content c JOIN users u ON u.id=c.author_id WHERE 1';$params=[];if(isset($types[$type])){$sql.=' AND content_type=?';$params[]=$type;}if($user['role']==='contributor'){$sql.=' AND c.author_id=?';$params[]=$user['id'];}$sql.=' ORDER BY c.updated_at DESC';$stmt=db()->prepare($sql);$stmt->execute($params);$items=$stmt->fetchAll();admin_header('Content','content');?>
<div class="toolbar"><form method="get"><input type="hidden" name="section" value="content"><select name="type" onchange="this.form.submit()"><option value="">All content</option><?php foreach($types as $key=>$label):?><option value="<?=$key?>" <?=$type===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></form><a class="admin-button" href="index.php?section=editor">Create content →</a></div><section class="admin-card"><table class="data-table"><tr><th>Title</th><th>Type</th><th>Author</th><th>Status</th><th></th></tr><?php foreach($items as $item):?><tr><td><?=e($item['title'])?></td><td><?=e($types[$item['content_type']])?></td><td><?=e($item['author'])?></td><td><span class="badge <?=e($item['status'])?>"><?=e($item['status'])?></span></td><td><a class="admin-link" href="index.php?section=editor&id=<?=$item['id']?>">Edit →</a><?php if(in_array($user['role'],['administrator','editor'],true)): ?> <a class="delete-link" href="index.php?section=delete&id=<?=$item['id']?>">Delete</a><?php endif; ?></td></tr><?php endforeach;?></table></section><?php admin_footer();exit; }

if ($section==='delete') {
    require_role(['administrator','editor']);
    $q=db()->prepare('SELECT id,title FROM content WHERE id=?'); $q->execute([(int)($_GET['id']??0)]); $record=$q->fetch();
    if (!$record) { http_response_code(404); exit('Content item not found.'); }
    admin_header('Delete content','content'); ?>
<section class="admin-card"><h2>Delete <?=e($record['title'])?>?</h2><p>This permanently deletes the write-up and removes it from the homepage and resource library. Uploaded files stay in Media &amp; Documents, so other content using them is not affected.</p>
<form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="delete_content"><input type="hidden" name="id" value="<?=$record['id']?>">
<label class="checkbox-label"><input type="checkbox" name="confirm_delete" value="1" required> I understand that this content will be permanently deleted.</label>
<div class="content-controls"><button class="admin-button danger-button">Delete permanently</button><a class="admin-link" href="index.php?section=content">Cancel</a></div></form></section>
<?php admin_footer();exit; }

if ($section==='editor') { $item=['id'=>'','content_type'=>'insight','title'=>'','slug'=>'','summary'=>'','body'=>'','status'=>'draft','featured_image'=>'','document_path'=>'','video_url'=>'','event_date'=>''];if(!empty($_GET['id'])){$stmt=db()->prepare('SELECT * FROM content WHERE id=?'.($user['role']==='contributor'?' AND author_id=?':''));$stmt->execute($user['role']==='contributor'?[(int)$_GET['id'],$user['id']]:[(int)$_GET['id']]);$item=$stmt->fetch();if(!$item){http_response_code(404);exit('Content item not found.');}}$q=db()->prepare('SELECT setting_value FROM site_settings WHERE setting_key=?'); $q->execute(['homepage_content_'.(int)$item['id']]); $item['show_on_homepage']=$q->fetchColumn()==='1';
$retry=$_SESSION['content_form']??null; unset($_SESSION['content_form']);
if ($retry && (int)$retry['id']===(int)$item['id']) $item=array_replace($item,$retry['values']);
admin_header($item['id']?'Edit content':'Create content','editor');?>
<form method="post" enctype="multipart/form-data" class="editor-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_content"><input type="hidden" name="id" value="<?=e((string)$item['id'])?>"><div class="editor-grid"><div><label>Title<input name="title" value="<?=e($item['title'])?>" required></label><br><label>Short summary<textarea name="summary" rows="3"><?=e($item['summary'])?></textarea></label><br><label>Write-up / content<textarea name="body"><?=e($item['body'])?></textarea></label></div><aside class="editor-side"><label>Content type<select name="content_type"><?php foreach($types as $key=>$label):?><option value="<?=$key?>" <?=$item['content_type']===$key?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><label>URL label <input name="slug" value="<?=e($item['slug'])?>"><small>Leave blank to generate from title.</small></label><label>Status<select name="status"><option value="draft" <?=$item['status']==='draft'?'selected':''?>>Draft</option><option value="review" <?=$item['status']==='review'?'selected':''?>>Ready for review</option><?php if($user['role']!=='contributor'):?><option value="published" <?=$item['status']==='published'?'selected':''?>>Published</option><option value="archived" <?=$item['status']==='archived'?'selected':''?>>Archived</option><?php endif;?></select></label><?php if(in_array($user['role'],['administrator','editor'],true)): ?><label class="checkbox-label"><input type="checkbox" name="show_on_homepage" value="1" <?=!empty($item['show_on_homepage'])?'checked':''?>> Show on homepage</label><p class="hint">Published content appears in the homepage's Latest from AIDA section. Drafts and archived content stay hidden.</p><?php endif; ?><section class="attachment-panel"><h2>Attachments</h2><p class="hint">Choose files from your computer. They are uploaded when you save content.</p>
<label>Featured image<input type="file" name="image_file" accept=".jpg,.jpeg,.png,.webp"><small>JPG, PNG or WebP</small></label>
<?php if($item['featured_image']): ?><a class="attachment-current" href="../<?=e($item['featured_image'])?>" target="_blank" rel="noopener">View current image</a><?php endif; ?>
<label>Document<input type="file" name="document_file" accept=".pdf,.docx,.xlsx,.pptx"><small>PDF, Word, Excel or PowerPoint</small></label>
<?php if($item['document_path']): ?><a class="attachment-current" href="../<?=e($item['document_path'])?>" target="_blank" rel="noopener">Open current document</a><?php endif; ?>
<label>Video file<input type="file" name="video_file" accept=".mp4,.webm"><small>MP4 or WebM. For larger videos, use a hosted video link below.</small></label>
<?php if($item['video_url']): ?><a class="attachment-current" href="<?=e(str_starts_with($item['video_url'],'uploads/')?'../'.$item['video_url']:$item['video_url'])?>" target="_blank" rel="noopener">Open current video</a><?php endif; ?>
<label>Or a hosted video link<input type="url" name="video_link" value="<?=e($item['video_link']??'')?>" placeholder="https://www.youtube.com/..."><small>Optional alternative to uploading a video.</small></label>
<p class="hint">Maximum <?=e((string)round(content_upload_limit()/1048576,1))?> MB per file. Combined attachments must also fit the server's <?=e((string)ini_get('post_max_size'))?> total request limit. Existing attachments stay unchanged unless you replace them.</p></section>
<label>Event date<input type="datetime-local" name="event_date" value="<?=e($item['event_date']?date('Y-m-d\TH:i',strtotime($item['event_date'])):'')?>"></label><p class="hint">Save as a draft to prepare your content, or publish to make its attachments available on the website.</p><button class="admin-button">Save content →</button></aside></div></form><?php admin_footer();exit; }

if ($section==='media') { require_role(['administrator','editor','contributor']);$media=db()->query('SELECT m.*,u.name FROM media m JOIN users u ON u.id=m.uploaded_by ORDER BY m.created_at DESC LIMIT 60')->fetchAll();admin_header('Media & Documents','media');?>
<section class="admin-card"><h2>Upload a document or image</h2><p class="hint">Accepted: JPG, PNG, WebP, PDF, DOCX, XLSX and PPTX. Videos can be uploaded directly in Create Content, or added there using a YouTube or Vimeo link.</p><form method="post" enctype="multipart/form-data" class="admin-form upload-zone"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="upload_media"><label>Select file<input type="file" name="file" required></label><label>Image description / alt text <input name="alt_text"></label><button class="admin-button">Upload file →</button></form></section><section class="admin-card"><h2>Recent uploads</h2><div class="file-list"><?php foreach($media as $file):?><article class="file-card"><b><?=e($file['original_name'])?></b><small><?=e($file['mime_type'])?> · <?=number_format($file['file_size']/1024,1)?> KB</small><p class="hint"><?=e($file['stored_name'])?></p></article><?php endforeach;?></div></section><?php admin_footer();exit; }

if ($section==='messages') { require_role(['administrator','editor']);$messages=db()->query('SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 100')->fetchAll();admin_header('Contact Messages','messages');?>
<section class="admin-card"><p class="hint">Messages submitted from the public AIDA contact form.</p><table class="data-table"><tr><th>From</th><th>Message</th><th>Received</th></tr><?php foreach($messages as $message):?><tr><td><b><?=e($message['name'])?></b><br><a href="mailto:<?=e($message['email'])?>"><?=e($message['email'])?></a></td><td><?=nl2br(e($message['message']))?></td><td><?=e(date('d M Y H:i',strtotime($message['created_at'])))?></td></tr><?php endforeach;?></table></section><?php admin_footer();exit; }

if ($section==='settings') { require_role(['administrator']);$settings=db()->query('SELECT setting_key,setting_value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR);admin_header('Home Page','settings');?>
<section class="admin-card"><h2>Homepage content</h2><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="save_settings"><label>Hero title<input name="hero_title" value="<?=e($settings['hero_title']??'')?>"></label><label>Hero introduction<textarea name="hero_text" rows="4"><?=e($settings['hero_text']??'')?></textarea></label><div class="form-grid"><label>Email address<input type="email" name="contact_email" value="<?=e($settings['contact_email']??'')?>"></label><label>Phone number<input name="contact_phone" value="<?=e($settings['contact_phone']??'')?>"></label><label class="full">Office address<textarea name="contact_address" rows="3"><?=e($settings['contact_address']??'')?></textarea></label></div><button class="admin-button">Save homepage settings →</button></form></section><?php admin_footer();exit; }

if ($section==='users') { require_role(['administrator']);$users=db()->query('SELECT id,name,email,role,is_active,last_login_at,created_at FROM users ORDER BY created_at DESC')->fetchAll();admin_header('Team Access','users');?>
<section class="admin-card"><h2>Add a team member</h2><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="create_user"><div class="form-grid"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label><label>Role<select name="role"><option value="contributor">Contributor — drafts only</option><option value="editor">Editor — can publish</option><option value="administrator">Administrator — full access</option></select></label><label>Password <small>At least 12 characters</small><input type="password" name="password" minlength="12" required></label></div><button class="admin-button">Create account →</button></form></section><section class="admin-card"><h2>Current access</h2><table class="data-table"><tr><th>Name</th><th>Email</th><th>Role</th><th>Last sign in</th></tr><?php foreach($users as $member):?><tr><td><?=e($member['name'])?></td><td><?=e($member['email'])?></td><td><span class="badge"><?=e($member['role'])?></span></td><td><?=e($member['last_login_at']?date('d M Y',strtotime($member['last_login_at'])):'Not yet')?></td></tr><?php endforeach;?></table></section><?php admin_footer();exit; }

if ($section==='applications') {
    require_role(['administrator','editor']);
    $applications=db()->query('SELECT * FROM applications ORDER BY created_at DESC LIMIT 100')->fetchAll();
    admin_header('Applications','applications'); ?>
<section class="admin-card"><h2>People interested in joining AIDA</h2><p class="hint">Review applications and record a decision. Status changes do not send emails; contact applicants separately.</p>
<?php if (!$applications): ?><p>No applications have been received yet.</p><?php endif; ?>
<?php foreach($applications as $application): ?><details class="application-record"><summary><b><?=e($application['name'])?></b> · <?=e($application['interest'])?> <span class="badge"><?=e($application['status'])?></span></summary>
<p><b>Email:</b> <a href="mailto:<?=e($application['email'])?>"><?=e($application['email'])?></a><br><b>Phone:</b> <?=e($application['phone'])?><br><b>Location:</b> <?=e($application['location'])?><br><b>Organisation:</b> <?=e($application['organisation'])?><br><b>Received:</b> <?=e($application['created_at'])?></p>
<h3>Skills and experience</h3><p><?=nl2br(e($application['expertise']))?></p><h3>Reason for joining</h3><p><?=nl2br(e($application['motivation']))?></p>
<form method="post" class="application-review"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="review_application"><input type="hidden" name="id" value="<?=$application['id']?>"><label>Status <select name="status"><?php foreach(['new','reviewing','accepted','declined'] as $s): ?><option value="<?=$s?>" <?=$application['status']===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?></select></label><button class="admin-button">Save status</button></form></details><?php endforeach; ?></section>
<?php admin_footer(); exit; }

