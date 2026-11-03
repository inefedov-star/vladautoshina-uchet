<?php
// ═══════════════════════════════════════════════════════════════════════
//   api.php — всё, что редактор просит у сервера
//
//   Действия: catalog, save, delete, upload.
//   Все требуют входа: без него отвечаем 403 и ничего не делаем.
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/obshee.php';
require_once dirname(__FILE__) . '/postavshchik.php';

header('Content-Type: application/json; charset=utf-8');

function otvet($data) {
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) { $flags = $flags | JSON_UNESCAPED_UNICODE; }
    if (defined('JSON_UNESCAPED_SLASHES')) { $flags = $flags | JSON_UNESCAPED_SLASHES; }
    echo json_encode($data, $flags);
    exit;
}

if (!vhod_est()) {
    header('HTTP/1.1 403 Forbidden');
    otvet(array('error' => 'Нужно войти заново'));
}

$chto = isset($_GET['chto']) ? $_GET['chto'] : '';

// ── Каталог сайта для подбора позиций ──────────────────────────────────
// Отдаём только то, что нужно для КП: название, цену, наличие, снимок.
// Каталог лежит этажом выше и весит под четверть мегабайта, поэтому
// отдаём его один раз, а искать редактор будет уже у себя.
if ($chto === 'catalog') {
    if (!file_exists($KATALOG_JSON)) {
        // Показываем путь: обычно папку kp просто положили не в корень сайта,
        // и по этой строке сразу видно, куда именно скрипт смотрел.
        otvet(array('items' => array(),
                    'error' => 'catalog.json не найден. Искал тут: ' . $KATALOG_JSON));
    }
    $raw = @file_get_contents($KATALOG_JSON);
    $d = $raw ? json_decode($raw, true) : null;
    if (!$d || !isset($d['items'])) {
        otvet(array('items' => array(), 'error' => 'catalog.json не прочитался'));
    }
    $out = array();
    foreach ($d['items'] as $it) {
        $out[] = array(
            'n'   => isset($it['n']) ? $it['n'] : '',
            'p'   => isset($it['p']) ? $it['p'] : 0,
            's'   => isset($it['s']) ? $it['s'] : 0,
            'c'   => isset($it['c']) ? $it['c'] : '',
            'img' => isset($it['img']) ? $it['img'] : '',
        );
    }
    otvet(array('items' => $out, 'updated' => isset($d['updated']) ? $d['updated'] : ''));
}

// ── Сохранить КП ───────────────────────────────────────────────────────
if ($chto === 'save') {
    $raw = file_get_contents('php://input');
    $kp = json_decode($raw, true);
    if (!is_array($kp)) { otvet(array('error' => 'не разобрал присланное')); }

    $id = isset($kp['id']) ? $kp['id'] : '';
    $staroe = $id ? chitat_kp($id) : null;
    if (!$staroe) {
        $id = novyy_id();
        $kp['created'] = date('c');
    } else {
        $kp['created'] = isset($staroe['created']) ? $staroe['created'] : date('c');
    }
    $kp['id'] = $id;
    $kp['updated'] = date('c');

    // С копейками не работаем вовсе, поэтому приводим к целым прямо
    // здесь, на входе: иначе дробь из браузера осядет в файле и потом
    // вылезет в расчёте.
    foreach (array('items', 'services') as $gruppa) {
        if (!isset($kp[$gruppa]) || !is_array($kp[$gruppa])) { continue; }
        foreach ($kp[$gruppa] as $i => $row) {
            if (isset($row['p'])) { $kp[$gruppa][$i]['p'] = round((float) $row['p']); }
            if (isset($row['q'])) { $kp[$gruppa][$i]['q'] = round((float) $row['q']); }
            // Скидок в предложении нет; в старых файлах поле могло остаться
            unset($kp[$gruppa][$i]['disc']);
            // Адрес картинки разрешаем только из своих папок. В документе он
            // идёт прямо в src, и пускать туда произвольную строку незачем,
            // даже из собственного редактора.
            if (isset($row['img']) && $row['img'] !== '') {
                if (!preg_match('~^/(foto|kp/foto-kp)/~', $row['img'])) {
                    $kp[$gruppa][$i]['img'] = '';
                }
            }
        }
    }

    // Галочки храним именно галочками: строка «false» из браузера
    // в документе читалась бы как «да».
    foreach (array('faksimile', 'itog') as $galka) {
        $kp[$galka] = !isset($kp[$galka]) ? true
                    : !($kp[$galka] === false || $kp[$galka] === 0
                        || $kp[$galka] === '0' || $kp[$galka] === 'false');
    }

    // Номер присваиваем на сервере: в браузере он мог бы повториться,
    // если два КП делают одновременно с разных устройств.
    if (empty($kp['num'])) { $kp['num'] = sleduyushchiy_nomer(); }
    if (empty($kp['date'])) { $kp['date'] = date('Y-m-d'); }

    if (!pisat_kp($kp)) { otvet(array('error' => 'не смог записать файл, проверьте права на папку data')); }
    otvet(array('ok' => true, 'id' => $id, 'num' => $kp['num'],
                'total' => itogi($kp), 'link' => 'p.php?id=' . $id));
}

// ── Товар по ссылке поставщика ─────────────────────────────────────────
if ($chto === 'tovar') {
    $url = isset($_GET['url']) ? trim($_GET['url']) : '';
    if (!preg_match('~^https?://~i', $url)) { otvet(array('error' => 'это не похоже на ссылку')); }

    $html = postavshchik_fetch($url);
    if ($html === null || strlen($html) < 500) {
        // Часть магазинов отвечает только настоящему браузеру
        otvet(array('error' => 'Сайт не ответил серверу. Такое бывает у 4tochki и koleso — '
                             . 'для них есть закладка, см. ссылку рядом с полем.'));
    }
    $t = razobrat_tovar($html, $url);
    if ($t['n'] === '') { otvet(array('error' => 'на странице не нашлось карточки товара')); }

    $svoyo = zabrat_kartinku($t['img'], $FOTO_DIR, $FOTO_URL);
    $t['img'] = $svoyo;             // ссылку на чужой сайт в документ не кладём
    $hz = parse_url($url);
    $t['istochnik'] = isset($hz['host']) ? preg_replace('~^www\.~', '', $hz['host']) : '';
    otvet(array('ok' => true, 'tovar' => $t));
}

// ── Буфер: сюда складывает закладка ────────────────────────────────────
function put_bufera() {
    return dirname(__FILE__) . '/data/bufer.json';
}

if ($chto === 'bufer') {
    $raw = @file_get_contents(put_bufera());
    $d = $raw ? json_decode($raw, true) : array();
    otvet(array('ok' => true, 'items' => is_array($d) ? $d : array()));
}

if ($chto === 'bufer_ochistit') {
    @unlink(put_bufera());
    otvet(array('ok' => true));
}

// ── Сделать копию ──────────────────────────────────────────────────────
// Похожее предложение другому клиенту — самый частый случай. Копируем
// позиции и условия, но не адресата: иначе легко отправить чужое имя.
if ($chto === 'copy') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    $staroe = chitat_kp($id);
    if (!$staroe) { otvet(array('error' => 'нечего копировать')); }

    $novoe = $staroe;
    $novoe['id'] = novyy_id();
    $novoe['num'] = sleduyushchiy_nomer();
    $novoe['date'] = date('Y-m-d');
    $novoe['created'] = date('c');
    $novoe['updated'] = date('c');
    $novoe['client'] = array('org' => '', 'person' => '', 'phone' => '', 'email' => '');
    $novoe['predmet'] = '';
    if (!pisat_kp($novoe)) { otvet(array('error' => 'не смог записать копию')); }
    otvet(array('ok' => true, 'id' => $novoe['id'], 'num' => $novoe['num']));
}

// ── Удалить ────────────────────────────────────────────────────────────
if ($chto === 'delete') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    $p = put_kp($id);
    if ($p && file_exists($p) && @unlink($p)) { otvet(array('ok' => true)); }
    otvet(array('error' => 'не нашёл или не смог удалить'));
}

// ── Загрузить картинку ─────────────────────────────────────────────────
if ($chto === 'upload') {
    if (!isset($_FILES['file'])) { otvet(array('error' => 'файл не пришёл')); }
    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) { otvet(array('error' => 'ошибка загрузки №' . $f['error'])); }
    if ($f['size'] > 8 * 1024 * 1024) { otvet(array('error' => 'файл больше 8 МБ')); }

    // Доверять расширению из имени файла нельзя — смотрим, что внутри.
    // Иначе под видом картинки на сервер попадёт что угодно.
    $info = @getimagesize($f['tmp_name']);
    if (!$info) { otvet(array('error' => 'это не картинка')); }
    $tipy = array(IMAGETYPE_JPEG => '.jpg', IMAGETYPE_PNG => '.png',
                  IMAGETYPE_WEBP => '.webp', IMAGETYPE_GIF => '.gif');
    if (!isset($tipy[$info[2]])) { otvet(array('error' => 'формат не поддерживается')); }

    if (!is_dir($FOTO_DIR)) { @mkdir($FOTO_DIR, 0755, true); }
    $imya = novyy_id() . $tipy[$info[2]];
    if (!@move_uploaded_file($f['tmp_name'], $FOTO_DIR . '/' . $imya)) {
        otvet(array('error' => 'не смог сохранить, проверьте права на папку foto-kp'));
    }
    @chmod($FOTO_DIR . '/' . $imya, 0644);
    otvet(array('ok' => true, 'img' => $FOTO_URL . '/' . $imya));
}

otvet(array('error' => 'неизвестное действие'));
