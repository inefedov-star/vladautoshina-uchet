<?php
/* История по клиенту и список постоянных клиентов. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db  = db();
$key = trim($_GET['k'] ?? '');

// ═══════════ карточка одного клиента ═══════════
if ($key !== '') {
    $rows = clientItems($db, $key);
    if (!$rows) { http_response_code(404); exit('Клиент не найден'); }

    $name = $phone = $dig = '';
    $paid = $svc = $svcPaid = $now = 0;
    $first = '9999';
    foreach ($rows as $r) {
        if ($name === '' && trim($r['client']) !== '') $name = trim($r['client']);
        if ($dig === '' && $r['phone_digits']) { $dig = $r['phone_digits']; $phone = $r['phone']; }
        $paid    += (int)$r['paid'];
        $svc     += (int)$r['services_sum'];
        $svcPaid += (int)$r['services_paid'];
        if ($r['status'] === 'stored') $now++;
        if ($r['accepted_at'] && $r['accepted_at'] < $first) $first = $r['accepted_at'];
    }
    $debt = max(0, $svc - $svcPaid);
    ?>
    <?php head($name, 'list'); ?>

    <p style="margin:0 0 10px"><a class="small muted" href="client.php" style="text-decoration:none">← Все клиенты</a></p>
    <h1><?= e($name) ?></h1>

    <div class="stats">
      <div class="stat"><b><?= count($rows) ?></b><span>раз сдавал</span></div>
      <div class="stat sig"><b><?= $now ?></b><span>сейчас на складе</span></div>
      <div class="stat"><b><?= number_format($paid + $svcPaid, 0, ',', ' ') ?></b><span>₽ принесено всего</span></div>
      <?php if ($debt): ?>
        <div class="stat warn"><b><?= number_format($debt, 0, ',', ' ') ?></b><span>₽ долг по услугам</span></div>
      <?php else: ?>
        <div class="stat"><b><?= e(ruDate($first)) ?></b><span>первый раз</span></div>
      <?php endif; ?>
    </div>

    <?php if ($dig): ?>
      <div class="btns" style="margin-bottom:14px">
        <a class="btn" href="tel:+<?= e($dig) ?>">Позвонить <?= e($phone) ?></a>
        <a class="btn g" href="https://wa.me/<?= e($dig) ?>" target="_blank" rel="noopener">WhatsApp</a>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Что сдавал</h3>
      <div class="tblwrap">
        <table class="tbl">
          <colgroup><col style="width:42px"><col style="width:96px"><col style="width:78px"><col>
            <col style="width:92px"><col style="width:86px"><col style="width:88px"></colgroup>
          <thead><tr>
            <th class="c">№</th><th>Сезон</th><th class="c">Ячейка</th><th>Что храним</th>
            <th>Принято</th><th class="r">Оплачено</th><th>Статус</th>
          </tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr onclick="location='item.php?id=<?= (int)$r['id'] ?>'">
              <td class="c mono" style="color:var(--ink3);font-size:.8rem"><?= (int)$r['num'] ?></td>
              <td class="mono nw" style="font-size:.74rem;color:var(--ink2)"><?= e($r['season'] ?: '—') ?></td>
              <td class="c"><?= $r['cell']
                ? '<span class="cellcode">' . e($r['cell']) . '</span>'
                : '<span style="color:var(--ink3)">—</span>' ?></td>
              <td title="<?= e($r['descr']) ?>">
                <span class="kd <?= kindCls($r['kind']) ?>"><?= e(kindShort($r['kind'])) ?></span><?= e($r['descr']) ?>
              </td>
              <td class="mono nw" style="font-size:.8rem"><?= e(ruDate($r['accepted_at'])) ?></td>
              <td class="r mono nw"><?= number_format((int)$r['paid'], 0, ',', ' ') ?></td>
              <td><?= $r['status'] === 'stored'
                ? '<span class="hb in">на хранении</span>'
                : '<span class="hb out">выдано ' . e(ruDate($r['issued_at'])) . '</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <h3>Принять новый комплект</h3>
      <p class="small muted" style="margin:-6px 0 10px">ФИО и телефон подставятся сами.</p>
      <a class="btn s" href="item.php?new=1&amp;client=<?= urlencode($name) ?>&amp;phone=<?= urlencode($phone) ?>">Принять от <?= e($name) ?></a>
    </div>

    <?php styles(); ?>
    <?php foot('list'); exit;
}

// ═══════════ список всех клиентов ═══════════
$all = clientList($db);
$js = [];
foreach ($all as $c) {
    $js[$c['key']] = mb_strtolower(str_replace('ё', 'е', $c['name'] . ' ' . $c['digits']), 'UTF-8');
}
$repeat = 0;
foreach ($all as $c) if ($c['n'] > 1) $repeat++;
?>
<?php head('Клиенты', 'list'); ?>

<h1>Клиенты</h1>

<div class="stats">
  <div class="stat"><b><?= count($all) ?></b><span>всего в базе</span></div>
  <div class="stat sig"><b><?= $repeat ?></b><span>сдавали не первый раз</span></div>
  <div class="stat"><b><?= count($all) ? round($repeat / count($all) * 100) : 0 ?>%</b><span>возвращаются</span></div>
</div>

<div class="search">
  <input id="q" type="search" inputmode="search" autocomplete="off" placeholder="ФИО или телефон…">
  <button class="x" id="qx" type="button" aria-label="Очистить" hidden>×</button>
</div>

<div class="card">
  <?php foreach ($all as $c): ?>
    <a class="hrow" href="client.php?k=<?= urlencode($c['key']) ?>" data-k="<?= e($c['key']) ?>">
      <span class="hd"><?= e($c['name']) ?></span>
      <?php if ($c['phone']): ?><span class="hs mono"><?= e($c['phone']) ?></span><?php endif; ?>
      <?php if ($c['now']): ?><span class="hb in">на складе <?= (int)$c['now'] ?></span><?php endif; ?>
      <?php if ($c['n'] > 1): ?><span class="hb rep">×<?= (int)$c['n'] ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if (!$all): ?><p class="empty">Пока никого.</p><?php endif; ?>
</div>
<p class="mn" id="cnt" style="margin:10px 0 24px"></p>

<?php styles(); ?>

<script>
const S = <?= json_encode($js, JSON_UNESCAPED_UNICODE) ?>;
const q = document.getElementById('q'), qx = document.getElementById('qx'),
      cnt = document.getElementById('cnt');
const rows = Array.from(document.querySelectorAll('.hrow'));
function apply(){
  const t = q.value.trim().toLowerCase().replace(/ё/g,'е');
  const d = t.replace(/\D/g,'');
  let n = 0;
  for (const r of rows){
    const s = S[r.dataset.k] || '';
    const ok = !t || s.includes(t) || (d.length >= 3 && s.includes(d));
    r.hidden = !ok; if (ok) n++;
  }
  cnt.textContent = n + ' из ' + rows.length;
  qx.hidden = !q.value;
}
q.addEventListener('input', apply);
qx.addEventListener('click', () => { q.value=''; apply(); q.focus(); });
apply();
</script>

<?php foot('list');


function styles(): void { ?>
<style>
.tblwrap{overflow-x:auto;border:1.5px solid var(--rule);border-radius:3px;background:var(--paper)}
table.tbl{border-collapse:collapse;width:100%;table-layout:fixed;font-size:.875rem;min-width:740px}
table.tbl th{background:var(--ink);color:var(--paper);text-align:left;padding:6px 8px;
  font-weight:600;font-size:.72rem;white-space:nowrap}
table.tbl td{padding:5px 8px;border-bottom:1px solid var(--rule2);white-space:nowrap;
  overflow:hidden;text-overflow:ellipsis;height:30px}
table.tbl tbody tr{cursor:pointer}
table.tbl tbody tr:nth-child(even){background:rgba(22,22,15,.03)}
table.tbl tbody tr:hover{background:var(--sig-t)}
.tbl .c{text-align:center}.tbl .r{text-align:right}
.tbl .cellcode{font-family:ui-monospace,monospace;font-size:.76rem;font-weight:700;
  background:var(--paper3);border:1.2px solid var(--rule);border-radius:2px;padding:.1em .35em}
.tbl .kd{font-family:ui-monospace,monospace;font-size:.6rem;padding:.1em .3em;border-radius:2px;margin-right:5px}
.tbl .kd.a{color:var(--sig);background:var(--sig-t)}
.tbl .kd.t{color:var(--cold);background:var(--cold-t)}
.tbl .kd.p{color:var(--ok);background:var(--ok-t)}
.hrow{display:flex;align-items:baseline;gap:9px;padding:8px 0;border-bottom:1px solid var(--rule2);
  text-decoration:none;color:var(--ink);font-size:.9rem}
.hrow:last-of-type{border-bottom:0}
.hrow .hd{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.hrow .hs{font-size:.8rem;color:var(--ink2);flex:none}
.hb{font-family:ui-monospace,monospace;font-size:.62rem;padding:.14em .36em;border-radius:2px;
  flex:none;white-space:nowrap}
.hb.in{color:var(--sig);background:var(--sig-t)}
.hb.out{color:var(--ink3);background:var(--paper3)}
.hb.rep{color:var(--cold);background:var(--cold-t)}
</style>
<?php }
