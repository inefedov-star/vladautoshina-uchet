<?php
// ═══════════════════════════════════════════════════════════════════════
//   obshee.php — общая часть: вход, чтение и запись КП, мелкие помощники
//
//   Подключается и редактором, и страницей просмотра. Просмотр пароля
//   не требует: клиент открывает КП по длинной случайной ссылке.
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/nastroyki.php';

// Вход из пульта IVANOR (vladautoshina.ru/montazh/): собственнику пароль КП не нужен.
// Сессию пульта только читаем и закрываем, потом открываем свою, как раньше.
$GLOBALS['IVANOR_OWNER'] = false;
if (!headers_sent() && !empty($_COOKIE['ivanor_montazh']) && function_exists('session_status')) {
    session_name('ivanor_montazh');
    @session_start(array('read_and_close' => true, 'gc_probability' => 0));
    $GLOBALS['IVANOR_OWNER'] = isset($_SESSION['role']) && $_SESSION['role'] === 'owner';
    $_SESSION = array();
    session_name('PHPSESSID');
    session_id(isset($_COOKIE['PHPSESSID']) && preg_match('/^[a-zA-Z0-9,-]{20,128}$/', $_COOKIE['PHPSESSID']) ? $_COOKIE['PHPSESSID'] : (function_exists('session_create_id') ? session_create_id() : md5(uniqid('', true))));
}

if (!headers_sent()) {
    // Срок жизни входа — сутки, чтобы не пускать заново каждый раз
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.gc_maxlifetime', '86400');
    @session_start();
}

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// 14900 → «14 900», неразрывным пробелом, чтобы число не рвалось при печати.
// Копеек в документе нет нигде: всё округляется до рубля ещё на входе,
// в cena_za_edinicu(), поэтому здесь просто печатаем целое.
function money($v) {
    return number_format(round((float) $v), 0, ',', "\xC2\xA0");
}

// Банковские реквизиты одной строкой для шапки предложения.
// Если в настройках заполнено готовое поле 'bank' — берём его как есть.
function bank_stroka($f) {
    if (!empty($f['bank'])) { return $f['bank']; }
    if (empty($f['rs'])) { return ''; }
    $ch = array('р/с ' . $f['rs']);
    if (!empty($f['bank_name'])) { $ch[] = 'в ' . $f['bank_name']; }
    if (!empty($f['bik']))       { $ch[] = 'БИК ' . $f['bik']; }
    if (!empty($f['ks']))        { $ch[] = 'к/с ' . $f['ks']; }
    return implode(', ', $ch);
}

function vhod_est() {
    if (!empty($GLOBALS['IVANOR_OWNER'])) return true;
    return isset($_SESSION['kp_vhod']) && $_SESSION['kp_vhod'] === true;
}

function trebuem_vhod() {
    if (!vhod_est()) {
        header('Location: index.php');
        exit;
    }
}

// Длинный случайный ключ: он же имя файла, он же ссылка для клиента.
// Угадать такой подбором нельзя, а значит КП не утечёт к соседу по списку.
function novyy_id() {
    $alf = 'abcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $alf[mt_rand(0, strlen($alf) - 1)];
    }
    return $out;
}

function put_kp($id) {
    global $DATA_DIR;
    // В имени файла допускаем только то, что сами же и генерируем:
    // иначе через id можно было бы дотянуться до чужих файлов на сервере.
    if (!preg_match('~^[a-z0-9]{8,32}$~', $id)) { return null; }
    return $DATA_DIR . '/' . $id . '.json';
}

function chitat_kp($id) {
    $p = put_kp($id);
    if (!$p || !file_exists($p)) { return null; }
    $raw = @file_get_contents($p);
    if (!$raw) { return null; }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

function pisat_kp($kp) {
    $p = put_kp($kp['id']);
    if (!$p) { return false; }
    $flags = 0;
    if (defined('JSON_UNESCAPED_UNICODE')) { $flags = $flags | JSON_UNESCAPED_UNICODE; }
    if (defined('JSON_UNESCAPED_SLASHES')) { $flags = $flags | JSON_UNESCAPED_SLASHES; }
    $json = json_encode($kp, $flags);
    if (!$json) { return false; }
    $tmp = $p . '.tmp';
    if (@file_put_contents($tmp, $json) && @rename($tmp, $p)) { return true; }
    @unlink($tmp);
    return false;
}

// Список сохранённых КП, свежие сверху.
function spisok_kp() {
    global $DATA_DIR;
    $out = array();
    if (!is_dir($DATA_DIR)) { return $out; }
    foreach (scandir($DATA_DIR) as $f) {
        if (substr($f, -5) !== '.json') { continue; }
        $d = chitat_kp(substr($f, 0, -5));
        if (!$d) { continue; }
        $out[] = array(
            'id'      => $d['id'],
            'num'     => isset($d['num']) ? $d['num'] : '',
            'date'    => isset($d['date']) ? $d['date'] : '',
            'client'  => isset($d['client']['org']) ? $d['client']['org'] : '',
            'total'   => itogi($d),
            'updated' => isset($d['updated']) ? $d['updated'] : '',
        );
    }
    usort($out, 'sravnit_po_date');
    return $out;
}

function sravnit_po_date($a, $b) {
    return strcmp($b['updated'], $a['updated']);
}

// ═══════════════════════════════════════════════════════════════════════
//   Деньги. Этот же расчёт продублирован в редакторе на JavaScript, чтобы
//   суммы пересчитывались на лету. Итоговая цифра в печатном КП берётся
//   отсюда, с сервера: правка цены в браузере на бумагу не повлияет.
// ═══════════════════════════════════════════════════════════════════════
// Цена за единицу, округлённая до рубля. Скидок в коммерческом
// предложении нет: если надо дать особые условия, это пишется словами
// в комментарии. Поэтому расчёт простой — цена, умноженная на количество.
function cena_za_edinicu($row) {
    return round(isset($row['p']) ? (float) $row['p'] : 0);
}

function stroka_summa($row) {
    $q = isset($row['q']) ? (float) $row['q'] : 0;
    return round(cena_za_edinicu($row) * $q);
}

function itogi($kp) {
    $sum = 0;
    foreach (array('items', 'services') as $gruppa) {
        if (!isset($kp[$gruppa]) || !is_array($kp[$gruppa])) { continue; }
        foreach ($kp[$gruppa] as $row) { $sum += stroka_summa($row); }
    }
    return round($sum);
}

function itogi_po_gruppam($kp) {
    $out = array('items' => 0, 'services' => 0);
    foreach (array('items', 'services') as $gruppa) {
        if (!isset($kp[$gruppa]) || !is_array($kp[$gruppa])) { continue; }
        foreach ($kp[$gruppa] as $row) { $out[$gruppa] += stroka_summa($row); }
    }
    $out['vsego'] = round($out['items'] + $out['services']);
    return $out;
}

// Следующий номер: КП-2026-001, нумерация своя на каждый год.
function sleduyushchiy_nomer() {
    global $DATA_DIR;
    $god = date('Y');
    $max = 0;
    if (is_dir($DATA_DIR)) {
        foreach (scandir($DATA_DIR) as $f) {
            if (substr($f, -5) !== '.json') { continue; }
            $d = chitat_kp(substr($f, 0, -5));
            if (!$d || !isset($d['num'])) { continue; }
            if (preg_match('~^КП-' . $god . '-(\d+)$~u', $d['num'], $m)) {
                $n = (int) $m[1];
                if ($n > $max) { $max = $n; }
            }
        }
    }
    return 'КП-' . $god . '-' . str_pad($max + 1, 3, '0', STR_PAD_LEFT);
}

// Дата по-русски: 30 октября 2026 г.
function data_slovami($iso) {
    $mes = array(1=>'января','февраля','марта','апреля','мая','июня','июля',
                 'августа','сентября','октября','ноября','декабря');
    $t = strtotime($iso);
    if (!$t) { return $iso; }
    return (int) date('j', $t) . ' ' . $mes[(int) date('n', $t)] . ' ' . date('Y', $t) . ' г.';
}

function plyus_dney($iso, $dney) {
    $t = strtotime($iso);
    if (!$t) { return ''; }
    return date('Y-m-d', $t + $dney * 86400);
}
