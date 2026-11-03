<?php
// ═══════════════════════════════════════════════════════════════════════
//   dobavit.php — сюда закладка присылает товар со страницы поставщика
//
//   Зачем он нужен. 4tochki и koleso отвечают только настоящему браузеру:
//   с сервера их страницы приходят пустыми. Но в вашем браузере страница
//   уже открыта и разобрана — остаётся забрать с неё готовые данные.
//   Это и делает закладка: читает разметку и открывает этот адрес.
//
//   Товар кладётся в буфер, а не сразу в предложение: так не нужно
//   выбирать нужное КП прямо на чужом сайте. Вернулись в редактор —
//   там кнопка «Вставить из буфера».
//
//   Как поставить закладку — см. zakladka.php
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/obshee.php';
require_once dirname(__FILE__) . '/postavshchik.php';

header('Content-Type: text/html; charset=utf-8');

$BUFER = dirname(__FILE__) . '/data/bufer.json';

function okno($zagolovok, $text, $cvet) {
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($zagolovok) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Sans+Extra+Condensed:wght@800&family=Golos+Text:wght@400;600&display=swap">
    <style>
      body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
        background:#16160F;color:#F2EFE8;font-family:'Golos Text',system-ui,sans-serif;padding:24px}
      .k{width:min(92vw,420px);text-align:center}
      h1{font-family:'Fira Sans Extra Condensed',sans-serif;font-weight:800;font-size:28px;
        margin:0 0 10px;letter-spacing:-.02em;color:<?= $cvet ?>}
      p{margin:0 0 18px;color:rgba(242,239,232,.72);font-size:15px;line-height:1.5}
      a{display:inline-block;background:#D8410C;color:#fff;text-decoration:none;
        padding:11px 18px;border-radius:2px;font-weight:600;font-size:14px}
      .t{background:rgba(242,239,232,.08);padding:12px 14px;border-radius:2px;
        text-align:left;font-size:14px;line-height:1.5;margin-bottom:18px}
      .t b{display:block;margin-bottom:4px}
      small{display:block;margin-top:14px;color:rgba(242,239,232,.45);font-size:12.5px}
    </style></head><body><div class="k">
    <h1><?= h($zagolovok) ?></h1><?= $text ?>
    <small>Окно можно закрыть</small>
    </div></body></html><?php
    exit;
}

if (!vhod_est()) {
    okno('Нужно войти', '<p>Откройте редактор предложений, войдите по паролю '
        . 'и нажмите закладку ещё раз.</p>'
        . '<a href="index.php" target="_blank">Открыть редактор</a>', '#E8734F');
}

// ── что прислала закладка ──────────────────────────────────────────────
$n   = isset($_GET['n']) ? trim($_GET['n']) : '';
$p   = isset($_GET['p']) ? chislo_iz_ceny($_GET['p']) : 0;
$img = isset($_GET['img']) ? trim($_GET['img']) : '';
$src = isset($_GET['src']) ? trim($_GET['src']) : '';

if ($n === '') {
    okno('Товар не распознан',
        '<p>На этой странице не нашлось карточки товара. Бывает на страницах '
        . 'со списком — откройте карточку конкретного размера и нажмите закладку там.</p>', '#E8734F');
}

// Чужую картинку забираем к себе: ссылка на сайт поставщика в документе
// ненадёжна — пропадёт или не напечатается.
$svoyo = '';
if ($img !== '' && preg_match('~^https?://~i', $img)) {
    $svoyo = zabrat_kartinku($img, $FOTO_DIR, $FOTO_URL);
}

$hz = parse_url($src ? $src : 'http://x/');
$istochnik = isset($hz['host']) ? preg_replace('~^www\.~', '', $hz['host']) : '';

$tovar = array(
    'n' => $n,
    'p' => $p,
    'img' => $svoyo,
    'istochnik' => $istochnik,
    'kogda' => date('c'),
);

$raw = @file_get_contents($BUFER);
$bufer = $raw ? json_decode($raw, true) : array();
if (!is_array($bufer)) { $bufer = array(); }
$bufer[] = $tovar;
if (count($bufer) > 50) { $bufer = array_slice($bufer, -50); }

$flags = 0;
if (defined('JSON_UNESCAPED_UNICODE')) { $flags = $flags | JSON_UNESCAPED_UNICODE; }
if (defined('JSON_UNESCAPED_SLASHES')) { $flags = $flags | JSON_UNESCAPED_SLASHES; }
if (!@file_put_contents($BUFER, json_encode($bufer, $flags))) {
    okno('Не записалось', '<p>Папка <b>data</b> закрыта для записи. Поставьте ей права 755.</p>', '#E8734F');
}

$kartinka = $svoyo ? 'снимок забрали к себе' : 'снимка не нашлось';
okno('Добавлено в буфер',
    '<div class="t"><b>' . h($n) . '</b>'
    . ($p ? money($p) . ' ₽' : 'цена не распозналась') . ' · ' . $kartinka
    . ($istochnik ? ' · ' . h($istochnik) : '') . '</div>'
    . '<p>В буфере: <b>' . count($bufer) . '</b>. Вернитесь в редактор и нажмите '
    . '«Вставить из буфера».</p>'
    . '<a href="index.php" target="_blank">Открыть редактор</a>', '#9BC08F');
