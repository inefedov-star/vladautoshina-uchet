<?php
/* Печатные формы А4: договор, акт приёма-передачи, акт возврата. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/docs.php';
requireAuth();

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$doc = $_GET['doc'] ?? 'all';
if (!in_array($doc, ['all', 'dogovor', 'akt', 'vozvrat', 'birki'], true)) $doc = 'all';

$st = $db->prepare('SELECT * FROM items WHERE id=?');
$st->execute([$id]);
$it = $st->fetch();
if (!$it) { http_response_code(404); exit('Комплект не найден'); }

$token = ensureToken($db, $id);
$link  = shareUrl($token);

$ps = $db->prepare('SELECT * FROM photos WHERE item_id=? ORDER BY id');
$ps->execute([$id]);
$photos = $ps->fetchAll();
$accept = array_values(array_filter($photos, fn($p) => ($p['stage'] ?? 'accept') !== 'wash'));

$qty     = max(1, (int)$it['qty']);
$storage = (int)$it['paid'];
$svcSum  = (int)$it['services_sum'];
$total   = $storage + $svcSum;

$V = [
    '{{номер}}'          => docNo($it),
    '{{дата}}'           => ruDateFull($it['accepted_at']),
    '{{клиент}}'         => $it['client'],
    '{{телефон}}'        => $it['phone'] ?: '—',
    '{{срок_с}}'         => ruDateFull($it['accepted_at']),
    '{{срок_по}}'        => ruDateFull($it['due_at']),
    '{{месяцев}}'        => (string)SEASON_MONTHS,
    '{{сумма}}'          => number_format($total, 0, ',', ' ') . ' ₽',
    '{{сумма_прописью}}' => rubWords($total),
    '{{хранение}}'       => number_format($storage, 0, ',', ' ') . ' ₽',
    '{{услуги_сумма}}'   => number_format($svcSum, 0, ',', ' ') . ' ₽',
    '{{тариф_шины}}'     => number_format(PRICE_TIRES, 0, ',', ' ') . ' ₽',
    '{{тариф_сборе}}'    => number_format(PRICE_ASSEMBLED, 0, ',', ' ') . ' ₽',
    '{{тариф_месяц}}'    => number_format(PRICE_MONTH, 0, ',', ' ') . ' ₽',
    '{{дней_претензии}}' => (string)PHOTO_CLAIM_DAYS,
    '{{ссылка}}'         => $link,
    '{{код}}'            => $token,
    '{{организация}}'    => ORG_SHORT,
    '{{персона}}'        => ORG_PERSON,
    '{{огрнип}}'         => ORG_OGRNIP,
    '{{инн}}'            => ORG_INN,
    '{{адрес}}'          => ORG_ADDR,
    '{{телефоны}}'       => ORG_PHONE,
    '{{почта}}'          => ORG_EMAIL,
];
function t(string $s): string { return e(strtr($s, $GLOBALS['V'])); }

$services = array_values(array_filter(array_map('trim',
    preg_split('/[,;\n]+/', (string)$it['services']))));
$svcEach = $services ? (int)round($svcSum / count($services)) : 0;
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Документы № <?= t('{{номер}}') ?> · <?= e($it['client']) ?></title>
<style>
@page { size: A4 portrait; margin: <?= $doc === 'birki' ? '8mm' : '14mm 14mm 12mm' ?>; }
*{box-sizing:border-box}
body{margin:0;background:#8a8a84;font:11pt/1.42 'Times New Roman',Georgia,serif;color:#000}
.bar{position:sticky;top:0;z-index:9;background:#16160F;color:#F2EFE8;padding:10px 14px;
  font-family:system-ui,sans-serif;font-size:14px;display:flex;gap:10px;align-items:center;
  flex-wrap:wrap}
.bar a,.bar button{font:inherit;font-size:13px;color:#F2EFE8;background:transparent;
  border:1.4px solid rgba(242,239,232,.45);border-radius:3px;padding:6px 12px;
  text-decoration:none;cursor:pointer}
.bar button.p{background:#D8410C;border-color:#D8410C;font-weight:600}
.bar .sp{margin-left:auto}
.sheet{width:210mm;min-height:297mm;margin:14px auto;padding:14mm 14mm 12mm;background:#fff;
  box-shadow:0 3px 18px rgba(0,0,0,.28)}

h1{font-size:14pt;text-align:center;margin:0 0 2mm;text-transform:uppercase;letter-spacing:.02em}
.sub{text-align:center;font-size:11pt;margin:0 0 6mm}
h2{font-size:11pt;margin:4mm 0 1.5mm;font-weight:bold}
p{margin:0 0 1.6mm;text-align:justify}
.i{text-indent:0}
.blank{border-bottom:1px solid #000;display:inline-block;min-width:60mm}
.cap{font-size:8pt;color:#444;text-align:center;margin:0 0 3mm}

table.d{width:100%;border-collapse:collapse;margin:2mm 0 3mm;font-size:10pt}
table.d th,table.d td{border:1px solid #000;padding:1.6mm 2mm;vertical-align:top}
table.d th{background:#eee;font-weight:bold;text-align:left;font-size:9pt}
table.d td.c{text-align:center}table.d td.r{text-align:right;white-space:nowrap}

.req{font-size:9.5pt;line-height:1.35}
.sign{display:flex;gap:10mm;margin-top:7mm;font-size:10pt}
.sign>div{flex:1}
.sign .line{border-bottom:1px solid #000;height:7mm;margin-bottom:1mm}
.mp{margin-top:4mm;font-size:9pt;color:#333}

.head{display:flex;justify-content:space-between;align-items:flex-start;
  border-bottom:1.5pt solid #000;padding-bottom:2mm;margin-bottom:5mm}
.head .b{font-size:13pt;font-weight:bold;letter-spacing:.04em}
.head .s{font-size:8.5pt;line-height:1.3;text-align:right}

.photobox{border:1px solid #000;padding:3mm;margin:3mm 0}
.pg{display:grid;grid-template-columns:repeat(4,1fr);gap:2mm;margin-top:2mm}
.pg img{width:100%;aspect-ratio:4/3;object-fit:cover;border:.5pt solid #666;display:block}
.code{font-family:ui-monospace,'Courier New',monospace;font-size:15pt;font-weight:bold;
  letter-spacing:.16em;border:1.5pt solid #000;padding:1.5mm 4mm;display:inline-block}
.qrrow{display:flex;align-items:center;gap:5mm;margin-top:2mm}
.qr{flex:none;line-height:0}
.qr svg{display:block}

/* ═══════════ БИРКИ НА КОЛЁСА ═══════════
   Лист А4 режется на четыре одинаковые этикетки, по одной на колесо.
   Номер занимает всю ширину; подписи ушли вниз, в строку с ячейкой. */
.labels{display:grid;grid-template-columns:1fr 1fr;grid-template-rows:1fr 1fr;
  width:100%;height:281mm;gap:0}
.lbl{border:.4mm dashed #999;display:flex;flex-direction:column;
  padding:4mm 5mm;overflow:hidden}
/* Одна строка, без переносов: перенос съедает высоту у номера. */
.lbl-top{display:flex;justify-content:space-between;align-items:baseline;gap:3mm;
  border-bottom:.5mm solid #000;padding-bottom:1.5mm;font-size:8pt;
  font-family:Arial,Helvetica,sans-serif;letter-spacing:.03em;flex:none;
  flex-wrap:nowrap;white-space:nowrap}
.lbl-top b{font-size:9.5pt;letter-spacing:.06em;overflow:hidden;text-overflow:ellipsis}
.lbl-top span{flex:none}

/* номер — во всю ширину этикетки, кегль подбирается по числу цифр */
.lbl-num{flex:1;display:flex;align-items:center;justify-content:center;min-height:0}
.lbl-num .n{font-family:'Arial Narrow',Arial,Helvetica,sans-serif;font-weight:bold;
  line-height:.78;letter-spacing:-.04em;white-space:nowrap}

/* ячейка и подписи — одной строкой, напротив друг друга */
.lbl-mid{flex:none;display:flex;align-items:center;gap:4mm;
  border-top:.4mm solid #000;padding-top:2.5mm}
.lbl-mid .addr{flex:none;display:flex;flex-direction:column;align-items:center;gap:1mm}
/* Сезон словами. Сокращения вроде «З26» на печати сливаются с числом 326,
   а места тут хватает: плашка уже адреса ячейки. */
.lbl-mid .scode{font-family:Arial,Helvetica,sans-serif;font-size:8pt;font-weight:bold;
  letter-spacing:.1em;background:#000;color:#fff;padding:.6mm 2mm;border-radius:.6mm;
  white-space:nowrap;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.lbl-mid .cell{font-family:'Courier New',monospace;font-size:15pt;font-weight:bold;
  letter-spacing:.08em;border:.6mm solid #000;padding:1.5mm 2.5mm;flex:none;
  white-space:nowrap}
.lbl-mid .cell.none{border-style:dashed;font-size:9pt;color:#666;letter-spacing:0}
.lbl-mid .who{flex:1;min-width:0;font-family:Arial,Helvetica,sans-serif;
  line-height:1.25;overflow:hidden}
.lbl-mid .who .nm{display:block;font-size:9.5pt;font-weight:bold;
  max-height:9.6mm;overflow:hidden}
.lbl-mid .who .tel{display:block;font-size:10pt;font-weight:bold;
  white-space:nowrap;letter-spacing:-.01em;margin-top:.6mm}

.lbl-foot{flex:none;border-top:.4mm solid #000;padding-top:1.5mm;margin-top:2.5mm;
  font-size:8pt;font-family:Arial,Helvetica,sans-serif;display:flex;
  justify-content:space-between;align-items:flex-end;gap:3mm}
.lbl-foot .d{flex:1;min-width:0;line-height:1.3}
.lbl-qr{flex:none;line-height:0}
.lbl-qr svg{display:block}

@media print{
  body{background:#fff}
  .bar{display:none}
  .sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}
  .sheet + .sheet{page-break-before:always}
  a{color:#000;text-decoration:none}
}
@media(max-width:820px){ .sheet{width:auto;padding:8mm} }
</style>
<script src="qr.js"></script>
</head>
<body>

<div class="bar">
  <a href="item.php?id=<?= $id ?>">← Карточка</a>
  <a href="?id=<?= $id ?>&doc=all"     <?= $doc==='all'?'style="background:rgba(255,255,255,.16)"':'' ?>>Договор + акт</a>
  <a href="?id=<?= $id ?>&doc=dogovor" <?= $doc==='dogovor'?'style="background:rgba(255,255,255,.16)"':'' ?>>Только договор</a>
  <a href="?id=<?= $id ?>&doc=akt"     <?= $doc==='akt'?'style="background:rgba(255,255,255,.16)"':'' ?>>Только акт</a>
  <a href="?id=<?= $id ?>&doc=vozvrat" <?= $doc==='vozvrat'?'style="background:rgba(255,255,255,.16)"':'' ?>>Акт возврата</a>
  <a href="?id=<?= $id ?>&doc=birki"   <?= $doc==='birki'?'style="background:rgba(255,255,255,.16)"':'' ?>>Бирки на колёса</a>
  <span class="sp"></span>
  <button class="p" onclick="window.print()">Печать / PDF</button>
</div>

<?php
function pageHead(): void { ?>
  <div class="head">
    <div>
      <div class="b"><?= e(mb_strtoupper(ORG_BRAND, 'UTF-8')) ?></div>
      <div style="font-size:8.5pt"><?= e(ORG_SHORT) ?> · <?= e(ORG_SITE) ?></div>
    </div>
    <div class="s">
      <?= e(ORG_ADDR) ?><br>
      <?= e(ORG_PHONE) ?><br>
      <?= e(ORG_EMAIL) ?>
    </div>
  </div>
<?php }

function parties(): void { ?>
  <p class="i"><?= t(partiesText()) ?></p>
  <p style="margin-top:2.5mm">гр. <b><?= t('{{клиент}}') ?></b>, тел. <?= t('{{телефон}}') ?>,</p>
  <p><span class="blank" style="min-width:100%">&nbsp;</span></p>
  <p class="cap">документ, удостоверяющий личность: серия, номер, дата выдачи, кем выдан, адрес регистрации</p>
<?php }

function signatures(): void { ?>
  <div class="sign">
    <div>
      <div><b>Поклажедатель</b></div>
      <div class="line"></div>
      <div style="font-size:8.5pt">подпись / расшифровка</div>
    </div>
    <div>
      <div><b>Хранитель</b></div>
      <div class="line"></div>
      <div style="font-size:8.5pt">подпись / <?= e(ORG_SIGN) ?> &nbsp; М.П.</div>
    </div>
  </div>
<?php }
?>

<?php /* ═══════════════ ДОГОВОР ═══════════════ */ ?>
<?php if ($doc === 'all' || $doc === 'dogovor'): ?>
<div class="sheet">
  <?php pageHead(); ?>
  <h1>Договор сезонного хранения автомобильных шин</h1>
  <p class="sub">№ <?= t('{{номер}}') ?> от <?= t('{{дата}}') ?> · г. Владимир</p>

  <?php parties(); ?>
  <p>именуемый в дальнейшем «Поклажедатель», с другой стороны, заключили настоящий договор
     (далее «Договор») о нижеследующем:</p>

  <?php foreach (contractSections() as [$title, $items]): ?>
    <h2><?= t($title) ?></h2>
    <?php foreach ($items as $par): ?>
      <p><?= t($par) ?></p>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <h2>Адреса и реквизиты сторон</h2>
  <table class="d">
    <tr>
      <td style="width:50%">
        <b>Поклажедатель</b><br>
        <?= t('{{клиент}}') ?><br>
        Телефон: <?= t('{{телефон}}') ?><br>
        Адрес: <span class="blank" style="min-width:45mm">&nbsp;</span>
      </td>
      <td class="req">
        <b>Хранитель</b><br>
        <?= e(ORG_SHORT) ?><br>
        ОГРНИП <?= e(ORG_OGRNIP) ?>, ИНН <?= e(ORG_INN) ?><br>
        Фактический адрес: <?= e(ORG_ADDR) ?><br>
        Юридический адрес: <?= e(ORG_ADDR_UR) ?><br>
        Телефоны: <?= e(ORG_PHONE) ?><br>
        E-mail: <?= e(ORG_EMAIL) ?>
      </td>
    </tr>
  </table>

  <?php signatures(); ?>
</div>
<?php endif; ?>

<?php /* ═══════════════ АКТ ПРИЁМА-ПЕРЕДАЧИ ═══════════════ */ ?>
<?php if ($doc === 'all' || $doc === 'akt'): ?>
<div class="sheet">
  <?php pageHead(); ?>
  <h1>Акт приёма-передачи № <?= t('{{номер}}') ?></h1>
  <p class="sub">от <?= t('{{дата}}') ?><br>
     к Договору сезонного хранения автомобильных шин № <?= t('{{номер}}') ?> от <?= t('{{дата}}') ?></p>

  <?php parties(); ?>
  <p>именуемый в дальнейшем «Поклажедатель», с другой стороны, подписали настоящий Акт
     о нижеследующем: Поклажедатель сдал, а Хранитель принял на ответственное хранение
     Имущество в следующем составе.</p>

  <table class="d">
    <thead><tr>
      <th style="width:30mm">Имущество</th>
      <th>Наименование, характеристики, дополнительная информация</th>
      <th style="width:20mm">Кол-во</th>
      <th style="width:26mm">Вознаграждение</th>
    </tr></thead>
    <tbody>
      <?php $isTires = in_array($it['kind'], ['tires', 'preseason'], true); ?>
      <tr<?= $isTires ? '' : ' style="color:#999"' ?>>
        <td>Шины (покрышки)</td>
        <td><?= $isTires ? e($it['descr']) : '—' ?><?php
          if ($it['kind'] === 'preseason'): ?><br><i>новые, приняты на хранение до начала сезона</i><?php endif; ?></td>
        <td class="c"><?= $isTires ? $qty . ' ед.' : '—' ?></td>
        <td class="r"><?= $isTires ? t('{{хранение}}') : '—' ?></td>
      </tr>
      <tr<?= $it['kind'] === 'assembled' ? '' : ' style="color:#999"' ?>>
        <td>Колёса в сборе</td>
        <td><?= $it['kind'] === 'assembled' ? e($it['descr']) : '—' ?></td>
        <td class="c"><?= $it['kind'] === 'assembled' ? $qty . ' ед.' : '—' ?></td>
        <td class="r"><?= $it['kind'] === 'assembled' ? t('{{хранение}}') : '—' ?></td>
      </tr>
      <tr>
        <td>Место хранения</td>
        <td colspan="2"><?= $it['cell'] ? 'ячейка ' . e($it['cell']) : 'определяется Хранителем' ?><?php
          if ($it['size']): ?> · размер <?= e($it['size']) ?><?php endif; ?><?php
          if ($it['season']): ?> · сезон <?= e($it['season']) ?><?php endif; ?></td>
        <td class="r"><b><?= t('{{хранение}}') ?></b></td>
      </tr>
    </tbody>
  </table>

  <p><b>Хранитель обязуется оказать следующие дополнительные услуги:</b></p>
  <table class="d">
    <thead><tr>
      <th>Услуга</th>
      <th style="width:30mm">Заказана</th>
      <th style="width:30mm">Вознаграждение</th>
    </tr></thead>
    <tbody>
      <?php if ($services): foreach ($services as $sv): ?>
        <tr><td><?= e($sv) ?></td><td class="c">да</td>
            <td class="r"><?= $svcEach ? number_format($svcEach, 0, ',', ' ') . ' ₽' : '—' ?></td></tr>
      <?php endforeach; else: ?>
        <tr><td>&nbsp;</td><td class="c">&nbsp;</td><td>&nbsp;</td></tr>
        <tr><td>&nbsp;</td><td class="c">&nbsp;</td><td>&nbsp;</td></tr>
      <?php endif; ?>
      <tr><td colspan="2" class="r"><b>Итого за услуги</b></td>
          <td class="r"><b><?= t('{{услуги_сумма}}') ?></b></td></tr>
    </tbody>
  </table>

  <p>Общий размер вознаграждения Хранителя за хранение и оказываемые услуги составляет
     <b><?= t('{{сумма}}') ?></b> (<?= t('{{сумма_прописью}}') ?>).
     <?= (int)$it['services_paid'] >= $svcSum
        ? 'Оплата произведена в полном объёме при подписании Акта.'
        : 'Хранение оплачено при подписании Акта, дополнительные услуги оплачиваются по факту оказания.' ?></p>

  <p>Срок хранения: с <?= t('{{срок_с}}') ?> по <?= t('{{срок_по}}') ?>.</p>

  <div class="photobox">
    <b>Фотофиксация состояния Имущества</b>
    <p style="font-size:9.5pt;margin:1.5mm 0">
      Снимков при приёме: <b><?= count($accept) ?></b>.
      Съёмка после мойки выполняется дополнительно.
      Все фотографии доступны Поклажедателю по адресу
      <b><?= e(str_replace('https://', '', $link)) ?></b>
      либо по коду ниже. Претензии по внешнему состоянию принимаются в течение
      <?= PHOTO_CLAIM_DAYS ?> рабочих дней с момента уведомления о размещении снимков после мойки.
    </p>
    <div class="qrrow">
      <div class="qr" id="qr1"></div>
      <div>
        <span class="code"><?= e($token) ?></span>
        <div style="font-size:9pt;margin-top:1.5mm">
          Наведите камеру телефона на квадрат — откроются ваши фотографии.<br>
          Или введите код вручную: <?= e(str_replace('https://', '', $link)) ?>
        </div>
      </div>
    </div>
    <script>document.getElementById('qr1').innerHTML =
      qrSvg(<?= json_encode($link, JSON_UNESCAPED_SLASHES) ?>, 27);</script>
    <?php if ($accept): ?>
      <div class="pg">
        <?php foreach (array_slice($accept, 0, 8) as $p): ?>
          <img src="photo.php?f=<?= e($p['file']) ?>" alt="состояние при приёме">
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <p style="font-size:9.5pt"><?= t(actNote()) ?></p>

  <?php signatures(); ?>
</div>
<?php endif; ?>

<?php /* ═══════════════ АКТ ВОЗВРАТА ═══════════════ */ ?>
<?php if ($doc === 'vozvrat'): ?>
<div class="sheet">
  <?php pageHead(); ?>
  <h1>Акт возврата имущества с хранения</h1>
  <p class="sub">к Договору сезонного хранения автомобильных шин № <?= t('{{номер}}') ?>
     от <?= t('{{дата}}') ?><br>
     дата возврата: <?= $it['issued_at'] ? t(ruDateFull($it['issued_at']))
        : '«____» ______________ ' . date('Y') . ' г.' ?></p>

  <?php parties(); ?>
  <p>именуемый в дальнейшем «Поклажедатель», с другой стороны, подписали настоящий Акт
     о том, что Хранитель возвратил, а Поклажедатель принял Имущество в следующем составе.</p>

  <table class="d">
    <thead><tr>
      <th style="width:34mm">Имущество</th>
      <th>Наименование, характеристики</th>
      <th style="width:20mm">Кол-во</th>
      <th style="width:30mm">Состояние</th>
    </tr></thead>
    <tbody>
      <tr>
        <td><?= e(kindName($it['kind'])) ?></td>
        <td><?= e($it['descr']) ?><?php if ($it['size']): ?> · <?= e($it['size']) ?><?php endif; ?></td>
        <td class="c"><?= $qty ?> ед.</td>
        <td class="c">исправное</td>
      </tr>
      <?php if ($it['services']): ?>
      <tr>
        <td>Оказанные услуги</td>
        <td colspan="2"><?= e($it['services']) ?></td>
        <td class="c">выполнены</td>
      </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <p>Срок хранения по Договору: с <?= t('{{срок_с}}') ?> по <?= t('{{срок_по}}') ?>.
     Оплачено по Договору: <b><?= t('{{сумма}}') ?></b>.
     <?php $debt = max(0, $svcSum - (int)$it['services_paid']);
     if ($debt): ?>При возврате доплачено за дополнительные услуги
       <b><?= number_format($debt, 0, ',', ' ') ?> ₽</b>.<?php endif; ?></p>

  <p style="font-size:9.5pt"><?= t(returnNote()) ?></p>

  <div class="qrrow" style="margin:4mm 0">
    <div class="qr" id="qr2"></div>
    <div style="font-size:9.5pt">
      Фотоматериалы по настоящему хранению — снимки при приёме, после мойки и при выдаче —
      доступны по коду <b><?= e($token) ?></b> либо по квадрату слева.
      Хранятся один год с даты возврата.
    </div>
  </div>
  <script>document.getElementById('qr2').innerHTML =
    qrSvg(<?= json_encode($link, JSON_UNESCAPED_SLASHES) ?>, 24);</script>

  <?php signatures(); ?>
</div>
<?php endif; ?>

<?php /* ═══════════════ БИРКИ НА КОЛЁСА ═══════════════ */ ?>
<?php if ($doc === 'birki'):
  // по одной этикетке на колесо, лист всегда добивается до четырёх
  $need   = max(4, (int)ceil($qty / 4) * 4);
  $sheets = (int)ceil($need / 4);
  $numTxt = (string)(int)$it['num'];
  // сезон печатается рядом с адресом: номер повторяется каждый сезон,
  // и без сезона прошлогоднюю бирку не отличить от нынешней
  $sName  = trim((string)($it['season'] ?? ''));
  /* Стартовый кегль: приблизительный, на случай печати с отключённым JS.
     Точный подбирает скрипт в конце — он мерит реальное свободное место
     и не зависит от того, нашёлся ли в системе Arial Narrow. */
  $numPt  = [1 => 300, 2 => 215, 3 => 148, 4 => 110][strlen($numTxt)] ?? 86;
  $short  = mb_substr(trim($it['descr']), 0, 48, 'UTF-8');
?>
  <?php for ($sh = 0; $sh < $sheets; $sh++): ?>
  <div class="sheet">
    <div class="labels">
      <?php for ($i = 0; $i < 4; $i++): $n = $sh * 4 + $i + 1; ?>
        <div class="lbl">
          <div class="lbl-top">
            <b><?= e(mb_strtoupper(ORG_BRAND, 'UTF-8')) ?></b>
            <span><?= $n ?>/<?= $need ?> · <?= e(kindShort($it['kind'])) ?></span>
          </div>

          <div class="lbl-num">
            <div class="n" style="font-size:<?= $numPt ?>pt"><?= e($numTxt) ?></div>
          </div>

          <div class="lbl-mid">
            <div class="addr">
              <div class="scode"><?= e($sName ?: ruDate($it['accepted_at'])) ?></div>
              <?php if ($it['cell']): ?>
                <div class="cell"><?= e($it['cell']) ?></div>
              <?php else: ?>
                <div class="cell none">ячейка<br>не задана</div>
              <?php endif; ?>
            </div>
            <div class="who">
              <span class="nm"><?= e(mb_substr($it['client'], 0, 40, 'UTF-8')) ?></span>
              <?php if ($it['phone']): ?><span class="tel"><?= e($it['phone']) ?></span><?php endif; ?>
            </div>
          </div>

          <div class="lbl-foot">
            <div class="d">
              Принято <?= e(ruDate($it['accepted_at'])) ?> · до <b><?= e(ruDate($it['due_at'])) ?></b><br>
              <?= e($short) ?>
            </div>
            <div class="lbl-qr"></div>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php endfor; ?>

  <script>
  (function(){
    var url = <?= json_encode(baseUrl() . '/item.php?id=' . $id, JSON_UNESCAPED_SLASHES) ?>;
    var svg = qrSvg(url, 14);
    var boxes = document.querySelectorAll('.lbl-qr');
    for (var i = 0; i < boxes.length; i++) boxes[i].innerHTML = svg;
  })();

  /* ─── номер растягивается на всё свободное место ───
     Раньше кегль брался из таблицы «столько-то цифр — столько-то пунктов».
     Таблицу приходилось пересчитывать после каждой правки вёрстки, и она
     врала, если в системе не нашлось Arial Narrow. Теперь размер подбирается
     делением пополам по фактическому размеру коробки: цифра всегда настолько
     крупная, насколько помещается. */
  function fitNumbers(){
    var boxes = document.querySelectorAll('.lbl-num');
    for (var i = 0; i < boxes.length; i++) {
      var box = boxes[i], n = box.querySelector('.n');
      if (!n) continue;
      var w = box.clientWidth, h = box.clientHeight;
      if (!w || !h) continue;
      var lo = 20, hi = 460, best = lo;
      for (var k = 0; k < 20; k++) {
        var mid = (lo + hi) / 2;
        n.style.fontSize = mid + 'pt';
        if (n.scrollWidth <= w && n.scrollHeight <= h) { best = mid; lo = mid; }
        else hi = mid;
      }
      n.style.fontSize = Math.floor(best) + 'pt';
    }
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNumbers);
  else window.addEventListener('load', fitNumbers);
  window.addEventListener('beforeprint', fitNumbers);
  </script>
<?php endif; ?>

</body>
</html>
