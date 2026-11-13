<?php
// ═══════════════════════════════════════════════════════════════════════
//   index.php — редактор коммерческих предложений
//
//   Без входа показывает только форму пароля. После входа: список готовых
//   КП и редактор одного КП. Позиции подбираются из каталога сайта,
//   недостающее добавляется руками со своей картинкой.
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/obshee.php';

$NASTROYKI = dirname(__FILE__) . '/nastroyki.php';
$oshibka = '';
$podskazka = '';

// ── Первый запуск: пароля ещё нет ──────────────────────────────────────
// Пока $PAROL_HASH пуст, внутрь пускать нельзя никого, но и войти
// невозможно. Поэтому первое открытие просит задать пароль и, если файл
// доступен для записи, вписывает отпечаток само.
$nastroyka = ($PAROL_HASH === '');

if ($nastroyka && isset($_POST['novyy'])) {
    $p1 = isset($_POST['novyy']) ? $_POST['novyy'] : '';
    $p2 = isset($_POST['povtor']) ? $_POST['povtor'] : '';
    $dlina = function_exists('mb_strlen') ? mb_strlen($p1, 'UTF-8') : strlen($p1);
    if ($dlina < 6) {
        $oshibka = 'Пароль короче шести знаков — такой подберут';
    } else if ($p1 !== $p2) {
        $oshibka = 'Пароли не совпали';
    } else {
        $hash = password_hash($p1, PASSWORD_DEFAULT);
        $stroka = '$PAROL_HASH = \'' . $hash . '\';';
        $soderzhimoe = @file_get_contents($NASTROYKI);
        $zapisano = false;
        if ($soderzhimoe) {
            $novoe = preg_replace('~\$PAROL_HASH\s*=\s*\x27[^\x27]*\x27\s*;~',
                                  str_replace('$', '\\$', $stroka), $soderzhimoe, 1);
            if ($novoe && $novoe !== $soderzhimoe) {
                $zapisano = (bool) @file_put_contents($NASTROYKI, $novoe);
            }
        }
        if ($zapisano) {
            $_SESSION['kp_vhod'] = true;
            header('Location: index.php');
            exit;
        }
        // Файл только для чтения — не беда, покажем строку для вставки.
        $podskazka = $stroka;
        $oshibka = 'Файл nastroyki.php закрыт для записи. Вставьте строку ниже '
                 . 'в него вручную — и заходите с новым паролем.';
    }
}

// ── Вход и выход ───────────────────────────────────────────────────────
if (isset($_POST['parol']) && !$nastroyka) {
    if (password_verify($_POST['parol'], $PAROL_HASH)) {
        // Новый идентификатор сессии при входе: чужой заранее подсунутый
        // идентификатор после этого бесполезен.
        if (function_exists('session_regenerate_id')) { @session_regenerate_id(true); }
        $_SESSION['kp_vhod'] = true;
        header('Location: index.php' . (isset($_GET['id']) ? '?id=' . urlencode($_GET['id']) : ''));
        exit;
    }
    // Пауза против подбора: тысяча попыток в секунду превращается в одну.
    sleep(1);
    $oshibka = 'Пароль не подошёл';
}
if (isset($_GET['vyhod'])) {
    $_SESSION = array();
    @session_destroy();
    header('Location: index.php');
    exit;
}

if (!vhod_est()) {
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
    <html lang="ru"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — предложения IVANOR</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Sans+Extra+Condensed:wght@800&family=Golos+Text:wght@400;600&display=swap">
    <style>
      body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
        background:#16160F;color:#F2EFE8;font-family:'Golos Text',system-ui,sans-serif}
      form{width:min(92vw,340px)}
      h1{font-family:'Fira Sans Extra Condensed',sans-serif;font-weight:800;font-size:30px;
        margin:0 0 6px;letter-spacing:-.02em}
      p{margin:0 0 22px;color:rgba(242,239,232,.6);font-size:14px}
      input{width:100%;box-sizing:border-box;padding:13px 14px;font-size:16px;
        font-family:inherit;background:#F2EFE8;color:#16160F;border:1.5px solid transparent;
        border-radius:2px}
      input:focus{outline:none;border-color:#D8410C}
      button{width:100%;margin-top:10px;padding:13px;font-size:15px;font-weight:600;
        font-family:inherit;background:#D8410C;color:#fff;border:0;border-radius:2px;cursor:pointer}
      .err{margin-top:12px;color:#E8734F;font-size:14px}
      .kod{margin-top:12px;padding:10px;background:rgba(242,239,232,.1);
        font-family:ui-monospace,monospace;font-size:11.5px;word-break:break-all;
        color:#F2EFE8;border-radius:2px;user-select:all}
    </style></head><body>
    <form method="post">
      <?php if ($nastroyka): ?>
        <h1>Первый запуск</h1>
        <p>Придумайте пароль от редактора предложений. Он нигде не сохранится —
           в файл ляжет только отпечаток.</p>
        <input type="password" name="novyy" placeholder="Новый пароль" autofocus autocomplete="new-password">
        <input type="password" name="povtor" placeholder="Ещё раз" autocomplete="new-password" style="margin-top:8px">
        <button type="submit">Задать пароль</button>
      <?php else: ?>
        <h1>Предложения</h1>
        <p>Шинный центр IVANOR</p>
        <input type="password" name="parol" placeholder="Пароль" autofocus autocomplete="current-password">
        <button type="submit">Войти</button>
      <?php endif; ?>
      <?php if ($oshibka): ?><div class="err"><?= h($oshibka) ?></div><?php endif; ?>
      <?php if ($podskazka): ?><div class="kod"><?= h($podskazka) ?></div><?php endif; ?>
    </form></body></html><?php
    exit;
}

// ── Дальше только для своих ────────────────────────────────────────────
$id = isset($_GET['id']) ? $_GET['id'] : '';
$kp = $id ? chitat_kp($id) : null;
$novoe = !$kp;

if ($novoe) {
    $kp = array(
        'id' => '', 'num' => '', 'date' => date('Y-m-d'),
        'valid_days' => $PO_UMOLCHANIYU['valid_days'],
        'manager' => $PO_UMOLCHANIYU['manager'],
        'faksimile' => $PO_UMOLCHANIYU['faksimile'],
        'itog' => isset($PO_UMOLCHANIYU['itog']) ? $PO_UMOLCHANIYU['itog'] : true,
        'usloviya' => $PO_UMOLCHANIYU['usloviya'],
        // из пульта (карточка клиента → «+ КП») приходят организация, имя и телефон
        'client' => array(
            'org' => isset($_GET['org']) ? mb_substr(trim(strip_tags((string)$_GET['org'])), 0, 200) : '',
            'person' => isset($_GET['person']) ? mb_substr(trim(strip_tags((string)$_GET['person'])), 0, 120) : '',
            'phone' => isset($_GET['phone']) ? preg_replace('/[^0-9+() -]/', '', (string)$_GET['phone']) : '',
            'email' => ''),
        'predmet' => '', 'comment' => '',
        'items' => array(), 'services' => array(),
    );
}

$spisok = spisok_kp();
$flags = 0;
if (defined('JSON_UNESCAPED_UNICODE')) { $flags = $flags | JSON_UNESCAPED_UNICODE; }
if (defined('JSON_UNESCAPED_SLASHES')) { $flags = $flags | JSON_UNESCAPED_SLASHES; }
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Редактор предложений — IVANOR</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Sans+Extra+Condensed:wght@700;800&family=Golos+Text:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap">
<?php
// Метка версии по времени изменения файла. Без неё браузер держит
// старый скрипт после заливки новой версии: разметка уже новая,
// а код прежний — и отваливается то одно, то другое.
function v($f) {
    $p = dirname(__FILE__) . '/' . $f;
    return $f . '?v=' . (file_exists($p) ? filemtime($p) : time());
}
?>
<link rel="stylesheet" href="<?= h(v('redaktor.css')) ?>">
<?php if (!empty($GLOBALS['IVANOR_OWNER'])): ?><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/<?php $pf = is_file(dirname(__DIR__) . '/pult/kp-embed.css') ? 'pult' : 'montazh'; echo $pf . '/kp-embed.css?v=' . (int)@filemtime(dirname(__DIR__) . '/' . $pf . '/kp-embed.css'); ?>"><script>(function(){function t(){var d=false;try{d=localStorage.getItem('ivanor_theme')==='dark';}catch(_){}var h=document.documentElement;if(d){h.setAttribute('data-theme','dark');h.style.backgroundColor='#121418';h.style.colorScheme='dark';}else{h.removeAttribute('data-theme');h.style.backgroundColor='';h.style.colorScheme='';}}t();window.__ivTheme=t;window.addEventListener('storage',function(e){if(e.key==='ivanor_theme'||e.key===null)t();});})();</script><?php endif; ?>
</head>
<body>

<header class="top">
  <a class="logo" href="index.php">
    <svg viewBox="0 0 32 32" aria-hidden="true">
      <rect width="32" height="32" rx="2" fill="#16160F"/>
      <circle cx="16" cy="16" r="9.5" fill="none" stroke="#D8410C" stroke-width="3"/>
      <circle cx="16" cy="16" r="3.4" fill="#F2EFE8"/></svg>
    <span>Предложения<i>IVANOR</i></span>
  </a>
  <span class="nom" id="nom"><?= h($kp['num'] ? $kp['num'] : 'новое предложение') ?></span>
  <span class="itog" id="itog-verh">—</span>
  <button id="sohranit" class="sig">Сохранить</button>
  <a id="smotret" class="<?= $novoe ? 'off' : '' ?>" href="<?= $novoe ? '#' : 'p.php?id=' . h($kp['id']) ?>" target="_blank">Открыть и напечатать</a>
  <?php if (!$novoe): ?>
    <button id="kopiya" class="tihaya">Сделать копию</button>
    <button id="udalit" class="tihaya">Удалить</button>
  <?php endif; ?>
  <a href="index.php?vyhod=1" class="tihaya">Выйти</a>
</header>

<div class="wrap">

  <!-- ══ ЛЕВО: что в предложении ══ -->
  <main class="main">

    <section class="blok">
      <h2>Кому</h2>
      <div class="setka">
        <label>Организация<input id="f-org" value="<?= h($kp['client']['org']) ?>" placeholder="ООО «Автопарк»"></label>
        <label>Контактное лицо<input id="f-person" value="<?= h($kp['client']['person']) ?>" placeholder="Сергей Иванович"></label>
        <label>Телефон<input id="f-phone" value="<?= h($kp['client']['phone']) ?>" placeholder="+7 ..."></label>
        <label>Почта<input id="f-email" value="<?= h($kp['client']['email']) ?>" placeholder="zakupki@..."></label>
        <label class="shir">Предмет предложения<input id="f-predmet" value="<?= h($kp['predmet']) ?>" placeholder="Зимние шины для парка Газелей"></label>
      </div>
    </section>

    <section class="blok">
      <h2>Позиции<span class="schet" id="schet-poz">0</span></h2>

      <div class="poisk">
        <input id="q" placeholder="Начните вводить размер или модель: 205/55 R16, Rocket, Magnetto" autocomplete="off">
        <div class="podskazki" id="podskazki" hidden></div>
        <p class="hint" id="cat-stat">Каталог загружается…</p>
      </div>

      <div class="ssylka">
        <input id="url" placeholder="Или вставьте ссылку на товар у поставщика" autocomplete="off">
        <button id="tyanut">Забрать</button>
        <button id="bufer" hidden>Вставить из буфера</button>
        <a href="zakladka.php" target="_blank" class="pro-zakladku">про закладку</a>
      </div>
      <p class="hint" id="url-stat"></p>

      <table class="redt" id="tabl-poz">
        <thead><tr>
          <th class="t-img"></th><th>Наименование</th><th class="t-q">Кол-во</th>
          <th class="t-u">Ед.</th><th class="t-p">Цена</th>
          <th class="t-s">Сумма</th><th class="t-x"></th>
        </tr></thead>
        <tbody></tbody>
      </table>

      <div class="knopki">
        <button id="dobavit-ruchnuyu">Добавить строку вручную</button>
      </div>
    </section>

    <section class="blok">
      <h2>Работы и услуги<span class="schet" id="schet-usl">0</span></h2>
      <table class="redt" id="tabl-usl">
        <thead><tr>
          <th>Наименование</th><th class="t-q">Кол-во</th><th class="t-u">Ед.</th>
          <th class="t-p">Цена</th><th class="t-s">Сумма</th><th class="t-x"></th>
        </tr></thead>
        <tbody></tbody>
      </table>
      <div class="knopki" id="gotovye-uslugi"></div>
    </section>

    <section class="blok">
      <h2>Условия и подпись</h2>
      <div class="setka">
        <label>Дата<input id="f-date" type="date" value="<?= h($kp['date']) ?>"></label>
        <label>Цены держим, дней<input id="f-valid" type="number" min="0" step="1" value="<?= h($kp['valid_days']) ?>"></label>
        <label class="shir">Кто подписывает<input id="f-manager" value="<?= h($kp['manager']) ?>"></label>
        <label class="shir galka">
          <input type="checkbox" id="f-faksimile" <?= !isset($kp['faksimile']) || $kp['faksimile'] ? 'checked' : '' ?>>
          <span>Подпись и печать в документе
            <i>Снимите галочку, если будете подписывать от руки и ставить настоящую печать</i></span>
        </label>
        <label class="shir galka">
          <input type="checkbox" id="f-itog" <?= !isset($kp['itog']) || $kp['itog'] ? 'checked' : '' ?>>
          <span>Итоговая сумма в документе
            <i>Снимите, когда это выбор из вариантов и клиент возьмёт не всё:
               иначе итог сложит варианты между собой</i></span>
        </label>
        <label class="shir">Комментарий для клиента<textarea id="f-comment" rows="3" placeholder="Особые условия и скидки — сюда, словами"><?= h($kp['comment']) ?></textarea></label>
        <label class="shir">Условия<textarea id="f-usloviya" rows="4"><?= h($kp['usloviya']) ?></textarea></label>
      </div>
    </section>
  </main>

  <!-- ══ ПРАВО: итог и список ══ -->
  <aside class="bok">
    <div class="svodka">
      <span class="mono">Итого по предложению</span>
      <b id="itog">0 ₽</b>
      <div class="svodka-row"><span>Товары</span><b id="itog-tov">0 ₽</b></div>
      <div class="svodka-row"><span>Работы</span><b id="itog-usl">0 ₽</b></div>
      <p class="mono mal vnimanie" id="itog-ne-v-doc"
         <?= !isset($kp['itog']) || $kp['itog'] ? 'hidden' : '' ?>>В документ итог не идёт</p>
      <p class="mono mal" id="listov">Пока пусто</p>
      <p class="mono mal" id="status">Не сохранено</p>
    </div>

    <div class="spisok">
      <h3>Сохранённые<a href="index.php">+ новое</a></h3>
      <?php if (!$spisok): ?>
        <p class="pusto">Пока ни одного. Соберите первое — оно появится здесь.</p>
      <?php endif; ?>
      <?php foreach ($spisok as $s): ?>
        <a class="pos <?= $s['id'] === $kp['id'] ? 'tek' : '' ?>" href="index.php?id=<?= h($s['id']) ?>">
          <b><?= h($s['num']) ?></b>
          <i><?= h($s['client'] ? $s['client'] : 'без адресата') ?></i>
          <span><?= h(money($s['total'])) ?> ₽ · <?= h(date('d.m.y', strtotime($s['date']))) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>
</div>

<script>
window.KP = <?= json_encode($kp, $flags) ?>;
window.USLUGI_GOTOVYE = <?= json_encode($USLUGI, $flags) ?>;
window.SAIT_FOTO = <?= json_encode($SAIT_FOTO, $flags) ?>;
</script>
<script src="<?= h(v('redaktor.js')) ?>"></script>
</body>
</html>
