<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db = db();

$stored = (int)$db->query("SELECT COUNT(*) FROM items WHERE status='stored'")->fetchColumn();
$busy   = (int)$db->query("SELECT COUNT(*) FROM items WHERE status='stored' AND cell<>''")->fetchColumn();
$paid   = (int)$db->query("SELECT COALESCE(SUM(paid),0) FROM items WHERE status='stored'")->fetchColumn();
$over   = (int)$db->query("SELECT COUNT(*) FROM items WHERE status='stored' AND due_at<>''
                           AND date(due_at) < date('now')")->fetchColumn();

$sort = $_GET['sort'] ?? 'num';
$ORD = [
    'num'    => 'CAST(num AS INTEGER)',
    'cell'   => "CASE WHEN cell='' THEN 1 ELSE 0 END, cell",
    'client' => 'client COLLATE NOCASE',
    'date'   => 'accepted_at DESC',
    'paid'   => 'paid DESC',
];
$order = $ORD[$sort] ?? $ORD['num'];

$rows = $db->query("SELECT i.*,
        (SELECT COUNT(*) FROM photos p WHERE p.item_id = i.id) AS nph
        FROM items i WHERE status='stored' ORDER BY $order")->fetchAll();

$search = [];
foreach ($rows as $r) {
    $search[(int)$r['id']] = mb_strtolower(implode(' ', [
        $r['num'], $r['cell'], $r['client'], $r['phone'], $r['phone_digits'],
        $r['descr'], $r['size'], $r['brand'], $r['services'], $r['note'], $r['season'],
        fullNum($r),          // «12 · ЗИМА 2026» — чтобы искалось и так
    ]), 'UTF-8');
}

/* ─────────── Разбивка по сезонам ───────────
   Каждый сезон — своя таблица. Порядок групп: по самой свежей приёмке,
   то есть текущий сезон всегда сверху. Записи без сезона идут последними. */
$groups = [];
foreach ($rows as $r) {
    $key = trim((string)$r['season']) !== '' ? trim($r['season']) : '';
    if (!isset($groups[$key])) {
        $groups[$key] = ['rows' => [], 'paid' => 0, 'over' => 0, 'last' => ''];
    }
    $g = &$groups[$key];
    $g['rows'][] = $r;
    $g['paid']  += (int)$r['paid'];
    $dl = daysLeft($r['due_at'] ?: null);
    if ($dl !== null && $dl < 0) $g['over']++;
    if ((string)$r['accepted_at'] > $g['last']) $g['last'] = (string)$r['accepted_at'];
    unset($g);
}
uksort($groups, function ($a, $b) use ($groups) {
    if ($a === '') return 1;        // «без сезона» — всегда в конце
    if ($b === '') return -1;
    return strcmp($groups[$b]['last'], $groups[$a]['last']);
});
$gi = 0;   // порядковый номер группы, нужен для id блоков
?>
<?php head('Хранение', 'list', true); ?>

<div class="stats">
  <div class="stat"><b><?= $stored ?></b><span>комплектов на хранении</span></div>
  <a class="stat" href="map.php"><b><?= capacity() - $busy ?></b><span>свободных ячеек из <?= capacity() ?></span></a>
  <div class="stat"><b><?= number_format($paid, 0, ',', ' ') ?></b><span>₽ получено</span></div>
  <a class="stat warn" href="money.php#srok"><b><?= $over ?></b><span>истёк срок</span></a>
</div>

<div class="search">
  <input id="q" type="search" inputmode="search" autocomplete="off"
         placeholder="ФИО, телефон, размер, ячейка…">
  <button class="x" id="qx" type="button" aria-label="Очистить" hidden>×</button>
</div>

<div class="chips">
  <button class="chip on" data-f="all">Все</button>
  <button class="chip" data-f="assembled">В сборе</button>
  <button class="chip" data-f="tires">Шины</button>
  <button class="chip" data-f="preseason">До сезона</button>
  <button class="chip" data-f="nocell">Без ячейки</button>
  <button class="chip" data-f="svc">Есть услуги</button>
  <button class="chip" data-f="over">Срок истёк</button>
  <button class="chip sep" id="dens" type="button" title="Показать текст целиком">⇕ Развернуть</button>
</div>

<p class="small muted" style="margin:-4px 0 9px;display:flex;gap:16px;flex-wrap:wrap">
  <span><i class="lg a"></i> колёса в сборе</span>
  <span><i class="lg t"></i> шины без дисков</span>
  <span><i class="lg p"></i> новые до сезона</span>
  <span><i class="lg n"></i> цифра с «ф» — сколько фотографий</span>
  <span class="muted">строка кликается — откроется карточка</span>
</p>

<p class="small muted" style="margin:-4px 0 9px">
  Номер считается заново в каждом сезоне, поэтому один и тот же № встречается
  в разных таблицах. Полное обозначение комплекта — номер плюс сезон,
  например <b>№ 12 · ЗИМА 2026</b>. Так же напечатано на бирке.
</p>

<?php foreach ($groups as $season => $g): $gi++; ?>
<section class="sgroup" data-g="<?= $gi ?>" data-season="<?= e($season) ?>">
  <div class="shead">
    <button class="stog" type="button" aria-expanded="true" aria-controls="gt<?= $gi ?>">
      <span class="car">▾</span>
      <b><?= $season !== '' ? e($season) : 'Без сезона' ?></b>
    </button>
    <span class="sct"><?= count($g['rows']) ?> <?= plural(count($g['rows']), 'комплект', 'комплекта', 'комплектов') ?></span>
    <span class="ssum"><?= number_format($g['paid'], 0, ',', ' ') ?> ₽</span>
    <?php if ($g['over']): ?><span class="sov"><?= $g['over'] ?> с истёкшим сроком</span><?php endif; ?>
    <span class="svis"></span>
  </div>

<div class="tblwrap" id="gt<?= $gi ?>">
  <table class="tbl">
    <colgroup>
      <col style="width:46px"><col style="width:84px"><col style="width:280px">
      <col style="width:172px"><col style="width:300px"><col style="width:86px">
      <col style="width:260px"><col style="width:240px">
    </colgroup>
    <thead>
      <tr>
        <th class="c"><a href="?sort=num" class="<?= $sort==='num'?'on':'' ?>">№</a></th>
        <th class="c"><a href="?sort=cell" class="<?= $sort==='cell'?'on':'' ?>">Ячейка</a></th>
        <th><a href="?sort=client" class="<?= $sort==='client'?'on':'' ?>">ФИО</a></th>
        <th>Телефон</th>
        <th>Что храним</th>
        <th class="r"><a href="?sort=paid" class="<?= $sort==='paid'?'on':'' ?>">Оплачено</a></th>
        <th>Доп. услуги</th>
        <th>Комментарий</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($g['rows'] as $r):
      $dl   = daysLeft($r['due_at'] ?: null);
      $svcD = max(0, (int)$r['services_sum'] - (int)$r['services_paid']); ?>
      <tr data-id="<?= (int)$r['id'] ?>"
          data-kind="<?= e($r['kind']) ?>"
          data-cell="<?= $r['cell'] ? 1 : 0 ?>"
          data-svc="<?= $r['services'] ? 1 : 0 ?>"
          data-over="<?= ($dl !== null && $dl < 0) ? 1 : 0 ?>"
          onclick="location='item.php?id=<?= (int)$r['id'] ?>'">
        <td class="c mono num" title="<?= e(fullNum($r)) ?>"><?= (int)$r['num'] ?></td>
        <td class="c">
          <?php if ($r['cell']): ?><span class="cellcode"><?= e($r['cell']) ?></span>
          <?php else: ?><span class="nocell">—</span><?php endif; ?>
        </td>
        <td title="<?= e($r['client']) ?>">
          <div class="cf">
            <span class="fio"><?= e($r['client']) ?></span>
            <?php if ($dl !== null && $dl < 0): ?><span class="ov" title="срок хранения истёк">срок</span><?php endif; ?>
            <?php if ($r['nph']): ?><span class="ph" title="фотографий: <?= (int)$r['nph'] ?>"><?= (int)$r['nph'] ?>&#8202;ф</span><?php endif; ?>
          </div>
        </td>
        <td class="mono nw phcell">
          <?php if ($r['phone_digits']): ?>
            <a href="tel:+<?= e($r['phone_digits']) ?>" onclick="event.stopPropagation()"><?= e($r['phone']) ?></a>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td class="what <?= kindCls($r['kind']) ?>"
            title="<?= e(kindName($r['kind'])) . ' · ' . e($r['descr']) ?>"><?= e($r['descr']) ?></td>
        <td class="r mono nw"><?= number_format((int)$r['paid'], 0, ',', ' ') ?></td>
        <td class="svcw" title="<?= e($r['services']) ?>">
          <div class="cf">
            <span class="tx"><?= e($r['services']) ?></span>
            <?php if ($svcD > 0): ?><span class="ov" title="услуги не оплачены">долг <?= number_format($svcD,0,',',' ') ?></span><?php endif; ?>
          </div>
        </td>
        <td class="note notew" title="<?= e($r['note']) ?>"><?= e($r['note']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</section>
<?php endforeach; ?>

<p class="mn" id="cnt" style="margin:10px 0 0"></p>
<?php if (!$rows): ?>
  <p class="empty">Пусто. Нажмите «Принять», чтобы добавить первый комплект.</p>
<?php endif; ?>

<style>
.tblwrap{overflow-x:auto;border:1.5px solid var(--rule);border-radius:3px;background:var(--paper);
  -webkit-overflow-scrolling:touch}
table.tbl{border-collapse:collapse;width:100%;table-layout:fixed;font-size:.875rem;min-width:1468px}
table.tbl th{position:sticky;top:0;z-index:2;background:var(--ink);color:var(--paper);
  text-align:left;padding:6px 8px;font-weight:600;font-size:.72rem;letter-spacing:.02em;
  white-space:nowrap}
table.tbl th a{color:rgba(242,239,232,.72);text-decoration:none}
table.tbl th a.on{color:#fff;text-decoration:underline;text-underline-offset:3px}
table.tbl td{padding:4px 8px;border-bottom:1px solid var(--rule2);vertical-align:middle;
  line-height:1.35;height:30px}

/* каждая запись — ровно одна строка, длинный текст обрезается многоточием */
table.tbl td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
table.tbl.wrapmode td{white-space:normal;overflow:visible;text-overflow:clip;
  vertical-align:top;padding:7px 8px}

table.tbl tbody tr{cursor:pointer}
table.tbl tbody tr:nth-child(even){background:rgba(22,22,15,.03)}
table.tbl tbody tr:hover{background:var(--sig-t)}
.tbl .c{text-align:center}.tbl .r{text-align:right}
.tbl .num{color:var(--ink3);font-size:.8rem}
.tbl .fio{font-weight:600}
.tbl .cellcode{font-family:ui-monospace,monospace;font-size:.76rem;font-weight:700;
  background:var(--paper3);border:1.2px solid var(--rule);border-radius:2px;padding:.1em .35em}
.tbl .nocell{color:var(--sig);font-weight:700}
.tbl td a{color:var(--sig);text-decoration:none}
/* тип хранения — только цветом текста и мягкой заливкой */
.tbl .what{font-weight:500}
.tbl .what.a{color:#9c3208;background:rgba(216,65,12,.06)}
.tbl .what.t{color:#1c4155;background:rgba(38,88,111,.06)}
.tbl .what.p{color:#31461f;background:rgba(75,106,56,.08)}
table.tbl tbody tr:hover .what.a{background:rgba(216,65,12,.12)}
table.tbl tbody tr:hover .what.t{background:rgba(38,88,111,.12)}
table.tbl tbody tr:hover .what.p{background:rgba(75,106,56,.16)}
.tbl .phcell{font-size:.82rem;letter-spacing:-.03em}
.tbl .phcell a{white-space:nowrap}
/* содержимое ячейки: текст ужимается, значки держат своё место */
.tbl .cf{display:flex;align-items:baseline;gap:6px;min-width:0}
.tbl .cf>.fio,.tbl .cf>.tx{flex:1 1 auto;min-width:0;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.tbl .cf>.ov,.tbl .cf>.ph{flex:0 0 auto}
table.tbl.wrapmode .cf{display:block}
table.tbl.wrapmode .cf>.fio,table.tbl.wrapmode .cf>.tx{white-space:normal;overflow:visible}
.tbl .ov{font-size:.62rem;font-family:ui-monospace,monospace;color:#8a1c1c;
  background:rgba(138,28,28,.1);padding:.12em .34em;border-radius:2px;line-height:1.4}
.tbl .ph{font-size:.62rem;font-family:ui-monospace,monospace;color:var(--ink3);
  background:var(--paper3);padding:.12em .34em;border-radius:2px;line-height:1.4}
.tbl .svc,.tbl .note{font-size:.82rem;color:var(--ink2)}
.lg{display:inline-block;width:13px;height:11px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.lg.a{background:rgba(216,65,12,.16)}
.lg.t{background:rgba(38,88,111,.16)}
.lg.p{background:rgba(75,106,56,.18)}
.lg.n{background:var(--paper3);border:1px solid var(--rule)}
.chip.sep{margin-left:auto;border-style:dashed}
.chip.sep.on{background:var(--cold);border-color:var(--cold);border-style:solid;color:#fff}
.tbl .svcw,.tbl .notew{font-size:.85rem;color:var(--ink2)}
.tbl .note{color:var(--ink2)}
@media(max-width:700px){table.tbl{font-size:.82rem;min-width:1360px}}

/* ── группы по сезонам ── */
.sgroup{margin-bottom:18px}
.sgroup[hidden]{display:none}
.shead{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;
  padding:0 2px 7px;border-bottom:2px solid var(--ink);margin-bottom:0}
.stog{display:flex;align-items:baseline;gap:7px;background:none;border:0;padding:0;
  cursor:pointer;font:inherit;color:var(--ink)}
.stog b{font-size:1.02rem;letter-spacing:-.02em}
.stog .car{font-size:.7rem;color:var(--ink3);transition:transform .15s;line-height:1}
.sgroup.closed .stog .car{transform:rotate(-90deg)}
.sgroup.closed .tblwrap{display:none}
/* во время поиска свёрнутый сезон раскрывается сам, иначе находки не видно */
.sgroup.closed.forced .tblwrap{display:block}
.sgroup.closed.forced .stog .car{transform:none}
.shead .sct{font-family:ui-monospace,monospace;font-size:.74rem;color:var(--ink3)}
.shead .ssum{font-family:ui-monospace,monospace;font-size:.74rem;color:var(--ink2)}
.shead .sov{font-family:ui-monospace,monospace;font-size:.7rem;color:#8a1c1c;
  background:rgba(138,28,28,.1);padding:.14em .4em;border-radius:2px}
.shead .svis{margin-left:auto;font-family:ui-monospace,monospace;font-size:.72rem;
  color:var(--sig)}
.sgroup .tblwrap{border-top:0;border-radius:0 0 3px 3px}
</style>

<script>
const SEARCH = <?= json_encode($search, JSON_UNESCAPED_UNICODE) ?>;
const q = document.getElementById('q'), qx = document.getElementById('qx');
const cnt = document.getElementById('cnt');
const groups = Array.from(document.querySelectorAll('.sgroup'));
let filter = 'all';
const norm = s => (s||'').toLowerCase().replace(/ё/g,'е');

function apply(){
  const t = norm(q.value.trim());
  const dig = t.replace(/\D/g,'');
  const digging = !!t || filter !== 'all';
  let n = 0, shown = 0;
  for (const g of groups){
    g.classList.toggle('forced', digging);
    const trs = g.querySelectorAll('tbody tr');
    let gn = 0;
    for (const tr of trs){
      const d = tr.dataset;
      let ok = true;
      if (filter === 'assembled') ok = d.kind === 'assembled';
      else if (filter === 'tires') ok = d.kind === 'tires';
      else if (filter === 'preseason') ok = d.kind === 'preseason';
      else if (filter === 'nocell') ok = d.cell === '0';
      else if (filter === 'svc')   ok = d.svc === '1';
      else if (filter === 'over')  ok = d.over === '1';
      if (ok && t){
        const s = SEARCH[d.id] || '';
        ok = s.includes(t) || (dig.length >= 3 && s.includes(dig));
      }
      tr.hidden = !ok;
      if (ok) gn++;
    }
    /* пустой сезон не показываем совсем: при поиске экран не засоряется
       заголовками групп, в которых ничего не нашлось */
    g.hidden = gn === 0;
    if (gn) shown++;
    const vis = g.querySelector('.svis');
    vis.textContent = (gn && gn < trs.length) ? ('найдено ' + gn) : '';
    n += gn;
  }
  cnt.textContent = n + ' ' + plural(n,'запись','записи','записей')
                  + (shown > 1 ? ' · сезонов: ' + shown : '');
  qx.hidden = !q.value;
}
function plural(n,a,b,c){
  const t = n%10, h = n%100;
  if (t===1 && h!==11) return a;
  if (t>=2 && t<=4 && (h<10||h>=20)) return b;
  return c;
}
q.addEventListener('input', apply);
qx.addEventListener('click', () => { q.value=''; apply(); q.focus(); });
document.querySelectorAll('.chip[data-f]').forEach(c => c.addEventListener('click', () => {
  document.querySelectorAll('.chip[data-f]').forEach(x => x.classList.remove('on'));
  c.classList.add('on'); filter = c.dataset.f; apply();
}));

// плотность строк: компактно (по умолчанию) или с полным текстом
const tbls = Array.from(document.querySelectorAll('table.tbl'));
const dens = document.getElementById('dens');
let wrapOn = false;
function setDens(on){
  wrapOn = on;
  tbls.forEach(t => t.classList.toggle('wrapmode', on));
  dens.classList.toggle('on', on);
  dens.textContent = on ? '⇕ Свернуть' : '⇕ Развернуть';
  try { localStorage.setItem('sklad_wrap', on ? '1' : '0'); } catch(e){}
}
dens.addEventListener('click', () => setDens(!wrapOn));
try { setDens(localStorage.getItem('sklad_wrap') === '1'); } catch(e){ setDens(false); }

/* сворачивание сезона: старые сезоны обычно не нужны каждый день,
   закрытые запоминаются до следующего захода */
let closed = [];
try { closed = JSON.parse(localStorage.getItem('sklad_closed') || '[]'); } catch(e){}
groups.forEach(g => {
  const season = g.dataset.season;
  const tog = g.querySelector('.stog');
  if (closed.includes(season)) { g.classList.add('closed'); tog.setAttribute('aria-expanded','false'); }
  tog.addEventListener('click', () => {
    const now = !g.classList.contains('closed');
    g.classList.toggle('closed', now);
    tog.setAttribute('aria-expanded', now ? 'false' : 'true');
    closed = closed.filter(s => s !== season);
    if (now) closed.push(season);
    try { localStorage.setItem('sklad_closed', JSON.stringify(closed)); } catch(e){}
  });
});

apply();
</script>

<?php foot('list');
