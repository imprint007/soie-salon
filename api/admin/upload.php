<?php
require_once __DIR__ . '/_helper.php';
setupAdminApi();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Тільки POST', 405);

if (empty($_FILES['file'])) jsonError('Файл не надіслано');

$file = $_FILES['file'];
$folder = trim($_POST['folder'] ?? 'general');
$folder = preg_replace('/[^a-z0-9_\-]/i', '', $folder);
if (empty($folder)) $folder = 'general';

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'Файл занадто великий (ліміт сервера)',
        UPLOAD_ERR_FORM_SIZE  => 'Файл занадто великий',
        UPLOAD_ERR_PARTIAL    => 'Файл завантажено частково',
        UPLOAD_ERR_NO_FILE    => 'Файл не надіслано',
        UPLOAD_ERR_NO_TMP_DIR => 'Немає тимчасової папки',
        UPLOAD_ERR_CANT_WRITE => 'Не вдалося записати файл',
    ];
    jsonError($errors[$file['error']] ?? 'Помилка завантаження');
}

// Визначаємо MIME
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMime = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'video/mp4', 'video/webm', 'video/quicktime'
];

// Деякі сервери повертають дивний MIME для відео — перевіряємо ще й розширення
$origName = $file['name'] ?? '';
$origExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$videoExtensions = ['mp4', 'webm', 'mov'];

if (!in_array($mime, $allowedMime)) {
    // Можливо MIME не визначився — спробуємо за розширенням
    if (in_array($origExt, $videoExtensions)) {
        $extToMime = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];
        $mime = $extToMime[$origExt];
    } else {
        jsonError('Дозволені тільки зображення (JPG, PNG, GIF, WebP, SVG) та відео (MP4, WebM, MOV). MIME: ' . $mime);
    }
}

$isVideo = strpos($mime, 'video/') === 0;
$maxSize = $isVideo ? 50 * 1024 * 1024 : 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    jsonError($isVideo ? 'Відео більше 50 MB' : 'Файл більше 5 MB');
}

$extMap = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/gif'       => 'gif',
    'image/webp'      => 'webp',
    'image/svg+xml'   => 'svg',
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
];
$ext = $extMap[$mime];

$basename = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;

$uploadsRoot = __DIR__ . '/../../uploads';
$targetDir = $uploadsRoot . '/' . $folder;

if (!is_dir($targetDir)) {
    if (!mkdir($targetDir, 0755, true)) {
        jsonError('Не вдалося створити папку', 500);
    }
}

$targetPath = $targetDir . '/' . $basename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    jsonError('Не вдалося зберегти файл', 500);
}

$publicUrl = '/uploads/' . $folder . '/' . $basename;

jsonOk([
    'url' => $publicUrl,
    'filename' => $basename,
    'size' => $file['size'],
    'folder' => $folder,
    'is_video' => $isVideo,
]);