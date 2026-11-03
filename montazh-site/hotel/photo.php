<?php
/* Отдаёт фотографию только вошедшему пользователю.
   Сама папка uploads закрыта, поэтому прямых ссылок на снимки нет. */
require_once __DIR__ . '/db.php';

$f = basename((string)($_GET['f'] ?? ''));

/* Доступ: либо сотрудник вошёл в приложение, либо клиент открыл свою
   страницу фотоотчёта по коду — тогда отдаём только его снимки. */
$tok = trim((string)($_GET['t'] ?? ''));
if ($tok !== '') {
    $st = db()->prepare("SELECT COUNT(*) FROM photos p
                         JOIN items i ON i.id = p.item_id
                         WHERE i.share_token = ? AND p.file = ? AND i.status <> 'deleted'");
    $st->execute([$tok, $f]);
    if (!(int)$st->fetchColumn()) { http_response_code(403); exit('Нет доступа'); }
} else {
    requireAuth();
}

if ($f === '' || !preg_match('/^[\w\-.]+\.(jpg|jpeg|png|webp|gif|heic|heif|bmp|tiff?)$/i', $f)) {
    http_response_code(400); exit('Неверное имя файла');
}
$path = PHOTO_DIR . '/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    if ($tok !== '') exit('Снимок не найден');           // клиенту путей не показываем
    // сотруднику объясняем, куда именно смотрели
    header('Content-Type: text/html; charset=utf-8');
    exit('<h2 style="font:600 18px system-ui">Файла нет на диске</h2>'
       . '<p style="font:14px/1.5 system-ui">Запись в базе есть, а файла нет. '
       . 'Искали здесь:</p><p style="font:13px ui-monospace,monospace;word-break:break-all">'
       . e($path) . '</p>'
       . '<p style="font:14px/1.5 system-ui">Откройте <a href="export.php#check">Выгрузка → '
       . 'Проверка системы</a> и нажмите «Проверить запись прямо сейчас» — там будет видно, '
       . 'пишутся ли новые файлы вообще.</p>');
}

$ext  = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp',
         'gif'=>'image/gif','heic'=>'image/heic','heif'=>'image/heif','bmp'=>'image/bmp',
         'tif'=>'image/tiff','tiff'=>'image/tiff'][$ext] ?? 'application/octet-stream';

$etag = '"' . md5($f . filemtime($path) . filesize($path)) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=604800');
header('ETag: ' . $etag);
if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="' . $f . '"');
readfile($path);
