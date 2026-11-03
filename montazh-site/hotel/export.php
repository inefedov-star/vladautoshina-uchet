<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db = db();

// ─────────── выгрузка CSV ───────────
if (isset($_GET['csv'])) {
    $only = $_GET['csv'] === 'stored' ? " WHERE status='stored'" : " WHERE status<>'deleted'";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sklad-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                     // BOM для Excel
    fputcsv($out, ['№','Ячейка','ФИО','Телефон','Что храним','Оплачено за хранение',
                   'Доп. услуги','Сумма услуг','Оплачено услуг','Комментарий',
                   'Тип','Размер','Марка','Штук','Сезон','Принято','Срок до',
                   'Статус','Выдано','Фото'], ';');
    $q = "SELECT i.*, (SELECT COUNT(*) FROM photos p WHERE p.item_id=i.id) nph
          FROM items i$only ORDER BY CAST(i.num AS INTEGER)";
    foreach ($db->query($q) as $r) {
        fputcsv($out, [
            $r['num'], $r['cell'], $r['client'], $r['phone'], $r['descr'], $r['paid'],
            $r['services'], $r['services_sum'], $r['services_paid'], $r['note'],
            kindShort($r['kind']), $r['size'], $r['brand'], $r['qty'], $r['season'],
            ruDate($r['accepted_at']), ruDate($r['due_at']),
            ['stored'=>'на хранении','issued'=>'выдано','deleted'=>'в корзине'][$r['status']] ?? $r['status'],
            $r['issued_at'] ? ruDate($r['issued_at']) : '', (int)$r['nph'],
        ], ';');
    }
    fclose($out);
    exit;
}

// ─────────── резервная копия базы ───────────
if (isset($_GET['db'])) {
    if (!file_exists(DB_FILE)) { http_response_code(404); exit('Базы ещё нет'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="sklad-' . date('Y-m-d') . '.db"');
    header('Content-Length: ' . filesize(DB_FILE));
    readfile(DB_FILE);
    exit;
}

// ─────────── уборка записей о пропавших снимках ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'fixphotos') {
    $gone = 0;
    foreach ($db->query('SELECT id, file FROM photos') as $r) {
        if (!photoExists($r['file'])) {
            $db->prepare('DELETE FROM photos WHERE id=?')->execute([(int)$r['id']]);
            $gone++;
        }
    }
    header('Location: export.php?fixed=' . $gone . '#check'); exit;
}

// ─────────── живая проверка записи ───────────
/* Права могут выглядеть правильными, а запись всё равно не проходить:
   open_basedir, отключённые функции, квота, антивирус хостинга.
   Единственный честный способ — попробовать записать и прочитать. */
$wtest = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'wtest') {
    $wtest = [];
    $dir   = PHOTO_DIR;
    $name  = 'test-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.jpg';
    $path  = $dir . '/' . $name;

    $wtest['Папка, куда пишем'] = realpath($dir) ?: ($dir . ' — realpath не отработал');

    // 1. простая запись текстом
    $ok1 = @file_put_contents($path . '.txt', 'проверка записи');
    $wtest['Простая запись файла'] = $ok1 !== false
        ? 'получилось, ' . (int)$ok1 . ' байт'
        : 'НЕ ПОЛУЧИЛОСЬ — сервер не даёт создать файл';
    if ($ok1 !== false) {
        $wtest['Чтение обратно'] = (@file_get_contents($path . '.txt') === 'проверка записи')
            ? 'получилось' : 'файл создан, но прочитать не вышло';
        @unlink($path . '.txt');
        $wtest['Удаление тестового файла'] = file_exists($path . '.txt') ? 'НЕ УДАЛИЛСЯ' : 'получилось';
    }

    // 2. запись картинки через GD — тот же путь, что у настоящих фото
    if (function_exists('imagejpeg')) {
        $im = @imagecreatetruecolor(60, 40);
        if ($im) {
            @imagefill($im, 0, 0, imagecolorallocate($im, 200, 80, 20));
            $ok2 = @imagejpeg($im, $path, 80);
            @imagedestroy($im);
            $size = is_file($path) ? filesize($path) : 0;
            $wtest['Запись JPEG через GD'] = ($ok2 && $size > 0)
                ? 'получилось, ' . $size . ' байт'
                : 'НЕ ПОЛУЧИЛОСЬ — именно так теряются снимки';
            if (is_file($path)) {
                $wtest['Отдача через photo.php'] = 'проверьте ссылкой ниже';
                $wtest['__testfile'] = $name;
            }
        } else {
            $wtest['Запись JPEG через GD'] = 'GD не смог создать картинку в памяти';
        }
    } else {
        $wtest['Запись JPEG через GD'] = 'модуля GD нет, снимки кладутся без сжатия';
    }
}
if (isset($_GET['delwtest'])) {
    $f = basename((string)$_GET['delwtest']);
    if (preg_match('/^test-[\w-]+\.jpg$/', $f)) @unlink(PHOTO_DIR . '/' . $f);
    header('Location: export.php#check'); exit;
}

// ─────────── самопроверка ───────────
$phAll = $phGone = $phHeic = 0;
$phRows = [];
foreach ($db->query('SELECT p.file, p.at, i.num, i.client FROM photos p
                     LEFT JOIN items i ON i.id = p.item_id ORDER BY p.id DESC') as $r) {
    $phAll++;
    $exists = photoExists($r['file']);
    if (!$exists) $phGone++;
    elseif (preg_match('/\.hei[cf]$/i', $r['file'])) $phHeic++;
    if (count($phRows) < 30) $phRows[] = $r + ['exists' => $exists];
}

// что реально лежит в папке
$onDisk = [];
foreach ((array)@scandir(PHOTO_DIR) as $f) {
    if ($f === '.' || $f === '..' || $f === '.htaccess' || $f === 'index.html') continue;
    $onDisk[$f] = @filesize(PHOTO_DIR . '/' . $f);
}
$dbFiles = [];
foreach ($db->query('SELECT file FROM photos') as $r) $dbFiles[$r['file']] = true;
$orphans = array_diff_key($onDisk, $dbFiles);   // файл есть, записи нет
$updir  = PHOTO_DIR;
$upOk   = is_dir($updir) && is_writable($updir);
$dbOk   = is_file(DB_FILE) && is_writable(DB_FILE);
$gdOk   = function_exists('imagejpeg');
$perm   = is_dir($updir) ? substr(sprintf('%o', fileperms($updir)), -3) : '—';
$dbPerm = is_file(DB_FILE) ? substr(sprintf('%o', fileperms(DB_FILE)), -3) : '—';

$total  = (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn();
$stored = (int)$db->query("SELECT COUNT(*) FROM items WHERE status='stored'")->fetchColumn();
$last   = $db->query("SELECT at, action, details FROM log ORDER BY id DESC LIMIT 15")->fetchAll();
$imp    = $db->query("SELECT v FROM settings WHERE k='imported_at'")->fetchColumn();
?>
<?php head('Выгрузка', 'exp'); ?>

<h1>Выгрузка и копии</h1>

<div class="card">
  <h3>Скачать таблицу</h3>
  <p class="small muted" style="margin-top:-4px">Открывается в Excel, кириллица не рассыпается.</p>
  <div class="btns">
    <a class="btn" href="?csv=stored">Только на хранении · <?= $stored ?></a>
    <a class="btn g" href="?csv=all">Всё, включая выданные · <?= $total ?></a>
  </div>
</div>

<div class="card">
  <h3>Резервная копия базы</h3>
  <p class="small muted" style="margin-top:-4px">
    Один файл со всеми данными. Скачивайте раз в неделю в сезон — если что-то случится
    с хостингом, достаточно будет положить его обратно.</p>
  <a class="btn g" href="?db=1">Скачать sklad.db</a>
  <?php if ($imp): ?>
    <p class="small muted" style="margin:10px 0 0">Данные из Excel перенесены <?= e(substr($imp,0,16)) ?>.</p>
  <?php endif; ?>
</div>

<?php if ($last): ?>
<div class="card">
  <h3>Последние действия</h3>
  <?php foreach ($last as $h): ?>
    <div class="small" style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid var(--rule2)">
      <span class="mono muted" style="flex:none;font-size:.72rem"><?= e(substr($h['at'],0,16)) ?></span>
      <span><?= e($h['details'] ?: $h['action']) ?></span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card" id="check">
  <h3>Проверка системы</h3>
  <?php if (isset($_GET['fixed'])): ?>
    <div class="flash">Убрано записей о пропавших снимках: <?= (int)$_GET['fixed'] ?></div>
  <?php endif; ?>
  <dl class="kv chk">
    <dt>Папка uploads</dt>
    <dd class="<?= $upOk ? 'ok' : 'bad' ?>">
      <?= $upOk ? 'запись разрешена' : 'ЗАКРЫТА НА ЗАПИСЬ — снимки не сохранятся' ?>
      <span class="muted">· права <?= e($perm) ?><?= $upOk ? '' : ', нужно 775' ?></span>
    </dd>

    <dt>Файл базы</dt>
    <dd class="<?= $dbOk ? 'ok' : 'bad' ?>">
      <?= $dbOk ? 'запись разрешена' : 'ЗАКРЫТ НА ЗАПИСЬ — ничего не сохранится' ?>
      <span class="muted">· права <?= e($dbPerm) ?><?= $dbOk ? '' : ', нужно 664' ?></span>
    </dd>

    <dt>Сжатие фото (GD)</dt>
    <dd class="<?= $gdOk ? 'ok' : 'warn' ?>">
      <?= $gdOk ? 'работает' : 'нет модуля — снимки лягут без сжатия, займут больше места' ?>
    </dd>

    <dt>Снимков в базе</dt>
    <dd><?= $phAll ?></dd>

    <dt>Из них потерялись</dt>
    <dd class="<?= $phGone ? 'bad' : 'ok' ?>">
      <?= $phGone ?: 'ни одного' ?>
      <?php if ($phGone): ?>
        <div class="small" style="margin-top:6px;color:var(--ink2)">
          В базе есть запись, а файла на диске нет — так бывает, если папка
          <span class="mono">uploads</span> была закрыта на запись в момент загрузки.
          В галерее такие места помечены «файл не найден». Снимки придётся сделать заново.
        </div>
        <form method="post" style="margin-top:8px"
              onsubmit="return confirm('Убрать из базы записи о снимках, которых нет на диске?')">
          <input type="hidden" name="act" value="fixphotos">
          <button class="btn g sm" type="submit">Убрать пустые записи · <?= $phGone ?></button>
        </form>
      <?php endif; ?>
    </dd>

    <?php if ($phHeic): ?>
      <dt>Снимков в HEIC</dt>
      <dd class="warn"><?= $phHeic ?> — с айфона видны, на компьютере могут не открыться.
        Чтобы такого не было, включите на телефоне
        «Настройки → Камера → Форматы → Наиболее совместимый».</dd>
    <?php endif; ?>

    <dt>Лимит на файл</dt>
    <dd><?= e(ini_get('upload_max_filesize')) ?>
      <span class="muted">· на запрос <?= e(ini_get('post_max_size')) ?></span></dd>

    <dt>Полный путь к папке</dt>
    <dd class="mono" style="font-size:.78rem;word-break:break-all"><?= e(realpath(PHOTO_DIR) ?: PHOTO_DIR) ?></dd>

    <dt>Файлов лежит в папке</dt>
    <dd><?= count($onDisk) ?><?= $orphans ? ' · из них ' . count($orphans) . ' без записи в базе' : '' ?></dd>

    <?php if (ini_get('open_basedir')): ?>
      <dt>open_basedir</dt>
      <dd class="warn mono" style="font-size:.78rem;word-break:break-all"><?= e(ini_get('open_basedir')) ?>
        <div class="small" style="color:var(--ink2)">Если папки uploads нет в этом списке — сервер не даст в неё писать.</div></dd>
    <?php endif; ?>
    <?php if (ini_get('disable_functions')): ?>
      <dt>Отключённые функции</dt>
      <dd class="mono" style="font-size:.75rem;word-break:break-all"><?= e(ini_get('disable_functions')) ?></dd>
    <?php endif; ?>
  </dl>
</div>

<div class="card">
  <h3>Живая проверка записи</h3>
  <p class="small muted" style="margin:-6px 0 12px">
    Права бывают правильными, а запись всё равно не проходит: мешает
    <span class="mono">open_basedir</span>, отключённая функция, квота или антивирус хостинга.
    Кнопка ниже пробует по-настоящему записать файл в <span class="mono">uploads</span>,
    прочитать его обратно и удалить. Это тот же путь, которым идут снимки.</p>
  <form method="post">
    <input type="hidden" name="act" value="wtest">
    <button class="btn" type="submit">Проверить запись прямо сейчас</button>
  </form>

  <?php if ($wtest !== null): ?>
    <dl class="kv chk" style="margin-top:14px">
      <?php foreach ($wtest as $k => $v): if ($k === '__testfile') continue; ?>
        <dt><?= e($k) ?></dt>
        <dd class="<?= stripos($v, 'НЕ ') !== false ? 'bad' : (stripos($v, 'получилось') !== false ? 'ok' : '') ?>"
            style="word-break:break-all"><?= e($v) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php if (!empty($wtest['__testfile'])): ?>
      <p class="small" style="margin:10px 0 0">
        Файл записан. Нажмите — он должен открыться картинкой:
        <a href="photo.php?f=<?= e($wtest['__testfile']) ?>" target="_blank" rel="noopener">открыть тестовый снимок</a>.
        Если открылся, а настоящие фото не открываются — значит запись работает,
        а проблема в старых записях: уберите их кнопкой выше и переснимите.
      </p>
      <p class="small" style="margin:8px 0 0">
        <a href="?delwtest=<?= e($wtest['__testfile']) ?>">Удалить тестовый файл</a>
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($phRows): ?>
<div class="card">
  <h3>Снимки по одному</h3>
  <p class="small muted" style="margin:-6px 0 10px">
    Последние <?= count($phRows) ?> записей. Слева — что записано в базе,
    справа — есть ли файл на диске.</p>
  <div class="tblw">
    <table class="pt">
      <thead><tr><th>Файл</th><th>Комплект</th><th>Когда</th><th>На диске</th></tr></thead>
      <tbody>
      <?php foreach ($phRows as $r): ?>
        <tr>
          <td class="mono"><?= e($r['file']) ?></td>
          <td><?= $r['num'] ? '№ ' . (int)$r['num'] . ' · ' . e((string)$r['client']) : '—' ?></td>
          <td class="mono"><?= e(substr((string)$r['at'], 0, 16)) ?></td>
          <td class="<?= $r['exists'] ? 'ok' : 'bad' ?>"><?= $r['exists'] ? 'есть' : 'НЕТ' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h3>Настройки</h3>
  <dl class="kv">
    <dt>Пароль, цены, ячейки</dt><dd>файл <span class="mono">config.php</span></dd>
    <dt>Вместимость</dt><dd><?= count(allLines()) ?> линий × <?= SECTIONS ?> мест × <?= TIERS ?> яруса
        = <?= capacity() ?> ячеек</dd>
    <dt>Цены за сезон</dt><dd><?= money(PRICE_ASSEMBLED) ?> в сборе · <?= money(PRICE_TIRES) ?> шины</dd>
    <dt>Сезон</dt><dd><?= SEASON_MONTHS ?> месяцев</dd>
  </dl>
  <p class="small muted" style="margin:12px 0 0">
    Поставите пятый ярус — поменяйте <span class="mono">TIERS</span> на 5,
    карта склада сразу станет на 440 ячеек.</p>
</div>

<style>
.tblw{overflow-x:auto;border:1.5px solid var(--rule);border-radius:3px;background:var(--paper)}
table.pt{border-collapse:collapse;width:100%;font-size:.82rem;min-width:560px}
table.pt th{background:var(--ink);color:var(--paper);text-align:left;padding:6px 8px;
  font-size:.7rem;white-space:nowrap}
table.pt td{padding:5px 8px;border-bottom:1px solid var(--rule2);white-space:nowrap}
table.pt td.ok{color:#33501f;font-weight:600}
table.pt td.bad{color:var(--sig);font-weight:700}
dl.chk dd.ok{color:#33501f}
dl.chk dd.bad{color:var(--sig);font-weight:600}
dl.chk dd.warn{color:#8a6d0b}
dl.chk dd .muted{font-weight:400}
</style>

<?php foot('exp');
