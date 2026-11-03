<?php
// ═══════════════════════════════════════════════════════════════════════
//   p.php — готовое коммерческое предложение
//
//   Эту ссылку отдают клиенту: /kp/p.php?id=xxxxxxxxxxxxxxxx
//   Пароль не спрашивается — адрес сам по себе длинный и случайный,
//   подобрать его нельзя, а соседний номер не угадать.
//
//   Та же страница и печатается: кнопка «Печать» открывает диалог
//   браузера, там же сохранение в PDF.
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/obshee.php';

$id = isset($_GET['id']) ? $_GET['id'] : '';
$kp = chitat_kp($id);

if (!$kp) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Предложение не найдено</title>'
       . '<body style="font-family:system-ui;padding:40px;max-width:30em;margin:auto">'
       . '<h1>Такого предложения нет</h1><p>Возможно, ссылка скопирована не полностью '
       . 'или предложение удалили. Позвоните нам: '
       . '<a href="tel:+79997100108">' . h($FIRMA['phone']) . '</a>.</p>';
    exit;
}

$it   = itogi_po_gruppam($kp);
$dney = isset($kp['valid_days']) ? (int) $kp['valid_days'] : 0;
$srok = $dney > 0 ? plyus_dney($kp['date'], $dney) : '';
// Клиент может открыть ссылку и через месяц. Молча показывать ему
// вчерашние цены как действующие нечестно — и нам же дороже.
$prosrocheno = ($srok && strtotime($srok) < strtotime(date('Y-m-d')));
$redaktor = vhod_est();

// Позиции и услуги печатаем одной таблицей: клиенту важна общая сумма,
// а не наше деление на товары и работы. Но подзаголовок ставим — иначе
// шиномонтаж в одном списке с шинами читается как ещё один товар.
// Факсимиле ставим, если его не выключили в этом предложении и файлы
// действительно лежат на месте: пустая картинка на бумаге хуже, чем
// честная пустая линия под живую подпись.
$faksimile = (!isset($kp['faksimile']) || $kp['faksimile']);
$f_podpis = (!empty($FIRMA['podpis']) && file_exists(dirname(__FILE__) . '/' . $FIRMA['podpis']))
            ? $FIRMA['podpis'] : '';
$f_pechat = (!empty($FIRMA['pechat']) && file_exists(dirname(__FILE__) . '/' . $FIRMA['pechat']))
            ? $FIRMA['pechat'] : '';
$stavim = $faksimile && ($f_podpis || $f_pechat);

$items = isset($kp['items']) && is_array($kp['items']) ? $kp['items'] : array();
$uslugi = isset($kp['services']) && is_array($kp['services']) ? $kp['services'] : array();
$s_foto = false;
foreach ($items as $r) { if (!empty($r['img'])) { $s_foto = true; break; } }

// Итог нужен не всегда. Когда предложение — выбор из нескольких вариантов
// шин, клиент возьмёт один, а не все: сумма под таблицей сложит варианты
// между собой и напугает цифрой, которую никто платить не собирался.
// Поэтому итог выключается для таких предложений.
$s_itog = (!isset($kp['itog']) || $kp['itog']);
// Без итога столбец «Сумма» имеет смысл только там, где считают
// количество: при одной штуке он просто повторяет цену.
$s_summa = $s_itog;
if (!$s_itog) {
    foreach (array_merge($items, $uslugi) as $r) {
        if ((float) (isset($r['q']) ? $r['q'] : 1) != 1) { $s_summa = true; break; }
    }
}

// Первая прикидка плотности — по числу строк. Окончательное решение
// принимает сама страница: скрипт внизу меряет высоту и спускается
// на ступень ниже, пока документ не уложится в лист. Поэтому здесь
// можно смело брать ступень покрупнее: если не влезет, подвинется.
//
// Обычное предложение — три-пять позиций, под них и настроена первая
// ступень с крупными снимками.
$vsego_strok = count($items) + count($uslugi);
$plotnost = 'krupno';
if ($vsego_strok > 19)      { $plotnost = 'tesno bez-foto'; $s_foto = false; }
else if ($vsego_strok > 13) { $plotnost = 'tesno'; }
else if ($vsego_strok > 7)  { $plotnost = 'plotno'; }
else if ($vsego_strok > 4)  { $plotnost = ''; }
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($kp['num']) ?> — <?= h($FIRMA['name']) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Sans+Extra+Condensed:wght@700;800&family=Golos+Text:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap">
<?php
// Метка версии: иначе после заливки браузер покажет старую вёрстку
function v_pechat($f) {
    $p = dirname(__FILE__) . '/' . $f;
    return $f . '?v=' . (file_exists($p) ? filemtime($p) : time());
}
?>
<link rel="stylesheet" href="<?= h(v_pechat('pechat.css')) ?>">
</head>
<body>

<div class="panel">
  <b><?= h($kp['num']) ?></b>
  <button onclick="window.print()" class="sig">Печать и PDF</button>
  <button onclick="kopirovat()" id="kop">Скопировать ссылку</button>
  <?php if ($redaktor): ?>
    <a id="otpravit" href="#" target="_blank" rel="noopener">Отправить в WhatsApp</a>
  <?php endif; ?>
  <?php if ($redaktor): ?>
    <a href="index.php?id=<?= h($kp['id']) ?>">Изменить</a>
    <a href="index.php" class="tihaya">Все предложения</a>
  <?php else: ?>
    <a href="tel:<?= h(preg_replace('~[^0-9+]~', '', $FIRMA['phone'])) ?>" class="tihaya">Позвонить</a>
  <?php endif; ?>
</div>

<div class="list <?= h($plotnost) ?>">

  <div class="shapka">
    <div class="shapka-l">
      <div class="mark">
        <svg viewBox="0 0 32 32" aria-hidden="true">
          <rect width="32" height="32" rx="2" fill="#16160F"/>
          <circle cx="16" cy="16" r="9.5" fill="none" stroke="#D8410C" stroke-width="3"/>
          <circle cx="16" cy="16" r="3.4" fill="#F2EFE8"/>
        </svg>
        <span>
          <b><?= h($FIRMA['name']) ?></b>
          <i>Владимир · шины, диски, аккумуляторы</i>
        </span>
      </div>
      <div class="rekviz">
        <?= h($FIRMA['legal']) ?><?php if (!empty($FIRMA['inn'])): ?>, ИНН <?= h($FIRMA['inn']) ?><?php endif; ?><?php if (!empty($FIRMA['ogrnip'])): ?>, ОГРНИП <?= h($FIRMA['ogrnip']) ?><?php endif; ?><br>
        <?= h($FIRMA['address']) ?><?php if (!empty($FIRMA['address_note'])): ?> (<?= h($FIRMA['address_note']) ?>)<?php endif; ?><br>
        <span><?= h($FIRMA['phone']) ?></span><?php if (!empty($FIRMA['phone2'])): ?> · <span><?= h($FIRMA['phone2']) ?></span><?php endif; ?><?php if (!empty($FIRMA['phone3'])): ?> · <span><?= h($FIRMA['phone3']) ?></span><?php endif; ?>
        · <span><?= h($FIRMA['email']) ?></span> · <span><?= h($FIRMA['site']) ?></span>
        <?php /* Банковские реквизиты в коммерческом предложении не нужны:
                 платёжные данные идут в счёте, а здесь только занимают
                 строку в шапке. Сами данные лежат в nastroyki.php. */ ?>
      </div>
    </div>
    <div class="shapka-r">
      <div class="nomer"><?= h($kp['num']) ?></div>
      <div class="data">от <?= h(data_slovami($kp['date'])) ?></div>
      <?php if ($srok): ?>
        <div class="srok<?= $prosrocheno ? ' srok--vyshel' : '' ?>">
          <?php if ($prosrocheno): ?>
            Срок предложения вышел<br><b><?= h(data_slovami($srok)) ?></b>
          <?php else: ?>
            Цены действительны<br>до <b><?= h(data_slovami($srok)) ?></b>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <h1>Коммерческое <em>предложение</em></h1>

  <?php
  $client = isset($kp['client']) ? $kp['client'] : array();
  $polya = array();
  if (!empty($client['org']))    { $polya['Организация'] = $client['org']; }
  if (!empty($client['person'])) { $polya['Контактное лицо'] = $client['person']; }
  if (!empty($client['phone']))  { $polya['Телефон'] = $client['phone']; }
  if (!empty($client['email']))  { $polya['Почта'] = $client['email']; }
  if (!empty($kp['predmet']))    { $polya['Предмет'] = $kp['predmet']; }
  if ($polya): ?>
  <dl class="komu">
    <?php foreach ($polya as $k => $v): ?>
      <dt><?= h($k) ?></dt><dd><?= h($v) ?></dd>
    <?php endforeach; ?>
  </dl>
  <?php endif; ?>

  <?php if ($items || $uslugi): ?>
  <table class="poz">
    <thead>
      <tr>
        <th class="c-num">№</th>
        <?php if ($s_foto): ?><th class="c-img"></th><?php endif; ?>
        <th class="c-name">Наименование</th>
        <th class="c-q">Кол-во</th>
        <th class="c-p">Цена</th>
        <?php if ($s_summa): ?><th class="c-s">Сумма</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php
      $n = 0;
      $kolonok = ($s_foto ? 5 : 4) + ($s_summa ? 1 : 0);
      // Заголовок раздела ставим только тогда, когда разделов правда два.
      // Над одним-единственным списком он выглядит канцелярщиной.
      if ($items && $uslugi): ?>
        <tr class="razdel"><td colspan="<?= $kolonok ?>">Товары</td></tr>
      <?php endif;
      foreach ($items as $r):
        $n++;
        $p = (float) (isset($r['p']) ? $r['p'] : 0);
        $q = (float) (isset($r['q']) ? $r['q'] : 0);
      ?>
      <tr>
        <td class="c-num"><?= $n ?></td>
        <?php if ($s_foto): ?>
          <td class="c-img"><?php if (!empty($r['img'])): ?><img src="<?= h($r['img']) ?>" alt=""><?php endif; ?></td>
        <?php endif; ?>
        <td class="c-name">
          <b><?= h(isset($r['n']) ? $r['n'] : '') ?></b>
          <?php if (!empty($r['note'])): ?><i><?= h($r['note']) ?></i><?php endif; ?>
        </td>
        <td class="c-q"><?= h(round($q)) ?>
          <?= h(isset($r['unit']) && $r['unit'] ? $r['unit'] : 'шт') ?></td>
        <td class="c-p"><?= money($p) ?></td>
        <?php if ($s_summa): ?><td class="c-s"><?= money(stroka_summa($r)) ?></td><?php endif; ?>
      </tr>
      <?php endforeach; ?>

      <?php if ($uslugi): ?>
        <tr class="razdel"><td colspan="<?= $kolonok ?>">Работы и услуги</td></tr>
        <?php foreach ($uslugi as $r): $n++;
          $p = (float) (isset($r['p']) ? $r['p'] : 0);
          $q = (float) (isset($r['q']) ? $r['q'] : 0); ?>
        <tr>
          <td class="c-num"><?= $n ?></td>
          <?php if ($s_foto): ?><td class="c-img"></td><?php endif; ?>
          <td class="c-name"><b><?= h(isset($r['n']) ? $r['n'] : '') ?></b>
            <?php if (!empty($r['note'])): ?><i><?= h($r['note']) ?></i><?php endif; ?></td>
          <td class="c-q"><?= h(round($q)) ?>
            <?= h(isset($r['unit']) && $r['unit'] ? $r['unit'] : 'шт') ?></td>
          <td class="c-p"><?= money($p) ?></td>
          <?php if ($s_summa): ?><td class="c-s"><?= money(stroka_summa($r)) ?></td><?php endif; ?>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($s_itog): ?>
  <div class="itogi">
    <?php if ($uslugi && $it['items'] > 0): ?>
      <div class="row"><span>Товары</span><b><?= money($it['items']) ?> ₽</b></div>
      <div class="row"><span>Работы и услуги</span><b><?= money($it['services']) ?> ₽</b></div>
    <?php endif; ?>
    <div class="row vsego"><span>Итого</span><b><?= money($it['vsego']) ?> ₽</b></div>
  </div>
  <?php else: ?>
    <?php /* Без итога клиент может решить, что сумму забыли. Говорим прямо,
             что считать будем по выбранным позициям. Про «цену за единицу»
             добавляем только там, где столбца «Сумма» нет: иначе это
             противоречит тому, что человек видит в таблице. */ ?>
    <p class="bez-itoga"><?php if (!$s_summa): ?>Цены указаны за единицу.
      <?php endif; ?>Итоговую сумму рассчитаем по выбранным позициям.</p>
  <?php endif; ?>
  <?php if (!empty($FIRMA['nds'])): ?><p class="nds"><?= h($FIRMA['nds']) ?></p><?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($kp['comment']) || !empty($kp['usloviya'])): ?>
  <div class="usloviya">
    <?php if (!empty($kp['comment'])): ?>
      <h3>Комментарий</h3>
      <p><?= nl2br(h($kp['comment'])) ?></p>
    <?php endif; ?>
    <?php if (!empty($kp['usloviya'])): ?>
      <h3>Условия</h3>
      <p><?= nl2br(h($kp['usloviya'])) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="podpis">
    <div class="podpis-l">
      <?php if (!empty($kp['manager'])): ?><b><?= h($kp['manager']) ?></b><?php endif; ?>
      <span><?= h($FIRMA['legal']) ?><br><?= h($FIRMA['phone']) ?> · <?= h($FIRMA['email']) ?></span>
    </div>
    <div class="podpis-r<?= $stavim ? ' podpis-r--faks' : '' ?>">
      <?php if ($stavim && $f_podpis): ?>
        <img class="faks-podpis" src="<?= h($f_podpis) ?>" alt="подпись">
      <?php endif; ?>
      <?php if ($stavim && $f_pechat): ?>
        <img class="faks-pechat" src="<?= h($f_pechat) ?>" alt="печать">
      <?php endif; ?>
      <div class="liniya"></div>
      <?php /* Когда подпись и печать уже стоят, надпись под линией не нужна:
               это подпись к пустому месту, и печать её же и перекрывает. */ ?>
      <?php if (!$stavim): ?><i>подпись и печать</i><?php endif; ?>
    </div>
  </div>

  <div class="podval">
    <span><?= h($FIRMA['name']) ?> · <?= h($FIRMA['address']) ?></span>
    <span><?= h($FIRMA['phone']) ?> · <?= h($FIRMA['site']) ?></span>
  </div>

</div>

<script>
/* ═══════════════════════════════════════════════════════════════════
   Автоподгон под лист
   Считать заранее, сколько строк влезет, можно только приблизительно:
   длинные названия переносятся, примечания добавляют высоту, у разных
   браузеров свои шрифты. Поэтому страница меряет себя сама и спускается
   на ступень ниже, пока не уложится. Ступени: крупные снимки → обычная →
   плотная → прайс-лист без снимков.
   ═══════════════════════════════════════════════════════════════════ */
(function(){
  var STUPENI = ['krupno', '', 'plotno', 'tesno', 'tesno bez-foto'];

  /* Высота листа за вычетом полей из @page и места под подвал.
     Берём с запасом: у разных принтеров поля гуляют на пару миллиметров,
     и лучше оставить полосу пустой, чем выпустить одну строку на второй
     лист. */
  var DOSTUPNO_MM = 297 - 10 - 12 - 9;

  var list = document.querySelector('.list');
  if(!list) return;

  function mm(){
    var d = document.createElement('div');
    d.style.cssText = 'position:absolute;left:-9999px;height:100mm';
    document.body.appendChild(d);
    var v = d.getBoundingClientRect().height / 100;
    d.remove();
    return v;
  }

  /* Высота самого содержимого, без листа вокруг него.
     На экране у листа задана минимальная высота в целую страницу и поля
     по краям — если мерить его целиком, переполнение выходит всегда,
     и подгон каждый раз скатывался до прайс-листа, убирая снимки. */
  function vysota_mm(odin){
    var st = window.getComputedStyle(list);
    var sverhu = parseFloat(st.paddingTop) || 0;
    var snizu  = parseFloat(st.paddingBottom) || 0;
    var bylo = list.style.minHeight;
    list.style.minHeight = '0';
    var h = (list.scrollHeight - sverhu - snizu) / odin;
    list.style.minHeight = bylo;
    return h;
  }

  function nastupen(){
    var k = list.className.replace('list', '').trim();
    var i = STUPENI.indexOf(k);
    return i < 0 ? 0 : i;
  }

  /* Начальную ступень запоминаем: к ней можно вернуться, если первый
     замер прошёл до загрузки шрифтов и ушёл ниже, чем нужно. Снимки
     при спуске до прайс-листа удаляются безвозвратно, поэтому выше
     этой отметки уже не поднимаемся. */
  var NACHALNAYA = nastupen();
  var snimki_ubrany = false;

  function podognat(){
    var odin = mm();
    if(!odin) return;
    var i = nastupen();

    /* сначала пробуем вернуться повыше: вдруг в прошлый раз ужались зря */
    while(i > NACHALNAYA && !snimki_ubrany){
      list.className = ('list ' + STUPENI[i - 1]).trim();
      if(vysota_mm(odin) > DOSTUPNO_MM){
        list.className = ('list ' + STUPENI[i]).trim();
        break;
      }
      i--;
    }

    var zapas = 0;
    while(i < STUPENI.length - 1 && vysota_mm(odin) > DOSTUPNO_MM && zapas++ < 6){
      i++;
      list.className = ('list ' + STUPENI[i]).trim();
      /* смена ступени меняет и число колонок: в прайс-листе снимков нет */
      if(STUPENI[i].indexOf('bez-foto') >= 0 && !snimki_ubrany){
        ubrat_snimki();
        snimki_ubrany = true;
      }
    }
    document.documentElement.dataset.stupen = STUPENI[i] || 'obychnaya';
  }

  function ubrat_snimki(){
    var yach = list.querySelectorAll('.c-img');
    for(var i = 0; i < yach.length; i++) yach[i].remove();
    var razdely = list.querySelectorAll('.razdel td');
    for(var j = 0; j < razdely.length; j++){
      var n = Number(razdely[j].getAttribute('colspan')) || 6;
      razdely[j].setAttribute('colspan', Math.max(1, n - 1));
    }
  }

  if(document.fonts && document.fonts.ready){ document.fonts.ready.then(podognat); }
  else { window.addEventListener('load', podognat); }
  /* перед самой печатью меряем ещё раз: шрифты к этому моменту точно на месте */
  window.addEventListener('beforeprint', podognat);
})();

/* Готовое сообщение клиенту: ссылка плюс одна строка текста.
   Собираем в браузере, чтобы адрес был ровно тот, по которому открыли. */
(function(){
  var knopka = document.getElementById('otpravit');
  if(!knopka) return;
  var tekst = <?= json_encode(
      'Коммерческое предложение ' . $kp['num'] .
      (!empty($kp['predmet']) ? ' — ' . $kp['predmet'] : '') . ': ',
      (defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0)) ?>;
  knopka.href = 'https://wa.me/?text=' + encodeURIComponent(tekst + location.href.split('#')[0]);
})();

function kopirovat(){
  var url = location.href.split('#')[0];
  var knopka = document.getElementById('kop');
  var bylo = knopka.textContent;
  var gotovo = function(){ knopka.textContent = 'Скопировано'; setTimeout(function(){ knopka.textContent = bylo; }, 1800); };
  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(url).then(gotovo, function(){});
  } else {
    /* старый способ: нужен для Safari без разрешения на буфер обмена */
    var el = document.createElement('textarea');
    el.value = url; document.body.appendChild(el); el.select();
    try { document.execCommand('copy'); gotovo(); } catch(e){}
    document.body.removeChild(el);
  }
}
</script>
</body>
</html>
