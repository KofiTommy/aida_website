<?php
declare(strict_types=1);

/** Validate every attachment before any files or content records are saved. */
function content_uploads(array $files, int $limit): array {
    $types = [
        'image_file' => ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'],
        'document_file' => [
            'application/pdf'=>'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
        ],
        'video_file' => ['video/mp4'=>'mp4', 'video/webm'=>'webm'],
    ];
    $labels = ['image_file'=>'Featured image','document_file'=>'Document','video_file'=>'Video'];
    $uploads = [];
    foreach ($types as $field=>$allowed) {
        $file = $files[$field] ?? null;
        if ($file === null || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) continue;
        if (!is_array($file) || !is_int($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($labels[$field] . ': upload failed. Check the file size and try again.');
        }
        if (!is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException($labels[$field] . ': invalid upload. Please select the file again.');
        }
        $size = filesize($file['tmp_name']);
        if (!$size || $size > $limit) throw new RuntimeException($labels[$field] . ': select a file within the upload limit.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($allowed[$mime])) throw new RuntimeException($labels[$field] . ': that file format is not supported.');
        if ($field === 'image_file' && !@getimagesize($file['tmp_name'])) throw new RuntimeException('The selected image could not be read.');
        $uploads[$field] = ['tmp'=>$file['tmp_name'], 'mime'=>$mime, 'extension'=>$allowed[$mime], 'size'=>$size,
            'name'=>substr(basename(is_string($file['name'] ?? null) ? $file['name'] : 'attachment'), 0, 255)];
    }
    return $uploads;
}

/** Server limits also apply; show the smaller per-file limit in the editor. */
function content_upload_limit(): int {
    global $config;
    $limit = (int)($config['upload_max_mb'] ?? 25) * 1024 * 1024;
    foreach (['upload_max_filesize', 'post_max_size'] as $option) {
        $value = trim((string)ini_get($option));
        $bytes = (float)$value;
        $unit = strtolower(substr($value, -1));
        $bytes *= ['k'=>1024,'m'=>1048576,'g'=>1073741824][$unit] ?? 1;
        if ($bytes > 0) $limit = min($limit, (int)$bytes);
    }
    return $limit;
}
