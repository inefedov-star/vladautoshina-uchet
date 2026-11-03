<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db = db();

/* Режим выбора: пришли из формы приёма, надо вернуть человека обратно
   с проставленным адресом и не потерять уже введённое. */
$pick  = isset($_GET['pick']);
$carry = [];
foreach (['num','kind','client','phone','descr','size','qty','accepted_at','due_at','paid','season','services','services_sum','services_paid','note'] as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $carry[$k] = (string)$_GET[$k];
}
$qs = $carry ? '&' . http_build_query($carry) : '';

$occ = [];
foreach ($db->query("SELECT id, num, cell, client, phone, kind, size, descr, qty,
                            season, due_at FROM items
                     WHERE status='stored' AND cell<>''") as $r) {
    $occ[$r['cell']] = $r;
}
$used   = count($occ);
$cap    = capacity();
$noCell = (int)$db->query("SELECT COUNT(*) FROM items WHERE status='stored' AND cell=''")->fetchColumn();

// занятость по ярусам
$byTier = array_fill(1, TIERS, 0);
foreach (array_keys($occ) as $c) {
    $t = (int)substr($c, -1);
    if (isset($byTier[$t])) $byTier[$t]++;
}
$perTier = count(allLines()) * SECTIONS;
?>
<?php head('Карта склада', 'map'); ?>

<h1><?= $pick ? 'Выберите ячейку' : 'Карта склада' ?></h1>

<?php if ($pick): ?>
  <div class="flash" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <span style="flex:1;min-width:200px">
      Нажмите свободную клетку — вернётесь к приёму
      <?= !empty($carry['client']) ? '<b>' . e($carry['client']) . '</b>' : 'комплекта' ?>
      с уже вписанным адресом. Введённое не потеряется.
    </span>
    <a class="btn g sm" href="item.php?new=1<?= e($qs) ?>">Назад без выбора</a>
  </div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><b><?= $used ?></b><span>ячеек занято</span></div>
  <div class="stat sig"><b><?= $cap - $used ?></b><span>свободно из <?= $cap ?></span></div>
  <div class="stat"><b><?= round($used / max(1, $cap) * 100) ?>%</b><span>заполнение</span></div>
  <a class="stat warn" href="index.php"><b><?= $noCell ?></b><span>без адреса</span></a>
</div>

<p class="small muted" style="margin:0 0 12px">
  План склада, вид сверху. Ворота внизу, дальняя стена вверху.
  Места нумеруются <b>от ворот</b>: 01 — самое ближнее ко входу, <?= sprintf('%02d', SECTIONS) ?> — самое дальнее.
  <?php if (CROSS_AISLE_AFTER): ?>Штриховая полоса после места
  <?= sprintf('%02d', CROSS_AISLE_AFTER) ?> — сквозной проход: можно перейти между рядами,
  не обходя стеллаж. До прохода <?= CROSS_AISLE_AFTER ?> мест, после —
  <?= SECTIONS - CROSS_AISLE_AFTER ?>.<?php endif; ?>
  В каждой клетке три квадратика — три яруса: <b>1</b> и <b>2</b> внизу под колёса в сборе,
  <b>3</b> наверху под шины. Нажмите свободный, чтобы принять туда комплект.
  <b>Наведите на занятый</b> — покажет, чей комплект и что лежит.
</p>

<div class="planwrap">
  <div class="plan">
    <div class="farwall">дальняя стена · место <?= sprintf('%02d', SECTIONS) ?></div>

    <div class="rows">
      <?php foreach (ROWS as $ri => $row): ?>
        <?php if ($ri > 0): ?>
          <div class="aisle"><span>проход 1,2 м</span></div>
        <?php endif; ?>
        <div class="rack <?= $row['kind'] ?>">
          <div class="rname"><?= e(rackName($row)) ?></div>
          <div class="lines">
            <?php foreach ($row['lines'] as $line): ?>
              <div class="line">
                <div class="lname"><?= e($line) ?></div>
                <?php for ($s = SECTIONS; $s >= 1; $s--): ?>
                  <?php if (CROSS_AISLE_AFTER && $s === CROSS_AISLE_AFTER): ?>
                    <div class="xaisle" title="сквозной проход между рядами"></div>
                  <?php endif; ?>
                  <div class="pos">
                    <span class="pn"><?= sprintf('%02d', $s) ?></span>
                    <?php for ($t = 1; $t <= TIERS; $t++):
                      $code = cellCode($line, $s, $t);
                      $o = $occ[$code] ?? null;
                      if ($o):
                        $dl = daysLeft($o['due_at'] ?: null); ?>
                        <a class="cl busy k<?= kindCls($o['kind']) ?>"
                           href="<?= $pick ? '#' : 'cell.php?cell=' . e($code) ?>"
                           data-c="<?= e($code) ?>"
                           data-n="<?= (int)$o['num'] ?>"
                           data-k="<?= e(kindName($o['kind'])) ?>"
                           data-kc="<?= kindCls($o['kind']) ?>"
                           data-cl="<?= e($o['client']) ?>"
                           data-ph="<?= e($o['phone']) ?>"
                           data-d="<?= e($o['descr'] ?: $o['size']) ?>"
                           data-q="<?= (int)$o['qty'] ?>"
                           data-se="<?= e($o['season']) ?>"
                           data-due="<?= e($o['due_at'] ? ruDate($o['due_at']) : '') ?>"
                           data-ov="<?= ($dl !== null && $dl < 0) ? 1 : 0 ?>"
                        ><?= $t ?></a>
                      <?php else: ?>
                        <a class="cl free" title="<?= e($code) ?> — свободна"
                           href="<?= $pick
                             ? 'item.php?new=1&cell=' . e($code) . e($qs)
                             : 'cell.php?cell=' . e($code) ?>"><?= $t ?></a>
                      <?php endif;
                    endfor; ?>
                  </div>
                <?php endfor; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="gatezone">
      <div class="gate">ВОРОТА · вход · место 01</div>
    </div>
  </div>
</div>

<div class="legend">
  <span><i class="sw free"></i> свободна</span>
  <span><i class="sw ka"></i> колёса в сборе</span>
  <span><i class="sw kt"></i> шины без дисков</span>
  <span><i class="sw kp"></i> новые до сезона</span>
  <span class="muted">ярусы 1 · 2 · 3 слева направо в каждой клетке</span>
</div>

<div class="card" style="margin-top:14px">
  <h3>Занятость по ярусам</h3>
  <?php for ($t = 1; $t <= TIERS; $t++):
    $p = round($byTier[$t] / max(1, $perTier) * 100); ?>
    <div style="margin-bottom:10px">
      <div style="display:flex;justify-content:space-between;font-size:.88rem;margin-bottom:4px">
        <span>Ярус <?= $t ?> · <?= $t <= 2 ? 'колёса в сборе' : 'шины без дисков' ?></span>
        <span class="mono"><?= $byTier[$t] ?> / <?= $perTier ?></span>
      </div>
      <div class="bar"><div class="fill" style="width:<?= $p ?>%"></div></div>
    </div>
  <?php endfor; ?>
</div>

<?php if ($noCell): ?>
<div class="card">
  <h3>Без адреса ячейки · <?= $noCell ?></h3>
  <p class="small muted" style="margin-top:-4px">
    Эти комплекты лежат на складе, но где именно — приложение не знает.
    Пока адреса нет, выдача идёт поиском по полкам.</p>
  <a class="btn g sm" href="index.php">Показать список</a>
  <p class="small muted" style="margin:10px 0 0">
    Быстрее всего расставить так: нажимаете свободную ячейку на карте, а в ней —
    комплект из списка «Ждут адреса». Два касания на комплект.</p>
</div>
<?php endif; ?>

<style>
.planwrap{overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:6px}
.plan{min-width:660px;border:1.5px solid var(--ink);border-radius:3px;background:var(--paper2);
  padding:0 0 0 0}
.farwall,.gate{font-family:ui-monospace,monospace;font-size:.66rem;letter-spacing:.12em;
  text-transform:uppercase;text-align:center;padding:7px 4px;color:var(--ink3)}
.farwall{border-bottom:1.5px solid var(--rule);background:var(--paper3)}
.gatezone{border-top:1.5px solid var(--rule);
  background:repeating-linear-gradient(45deg,var(--paper3) 0 6px,var(--paper2) 6px 12px)}
.gate{color:var(--sig);font-weight:700;letter-spacing:.14em}
.rows{display:flex;align-items:stretch;justify-content:center;padding:10px 8px}
.rack{display:flex;flex-direction:column;align-items:center}
.rname{font-family:ui-monospace,monospace;font-size:.68rem;font-weight:700;letter-spacing:.08em;
  color:var(--ink);margin-bottom:5px}
.rack.double .lines{border:1.4px solid var(--ink);border-radius:2px;background:var(--paper3);padding:2px}
.rack.wall .lines{border:1.4px solid var(--rule);border-radius:2px;background:var(--paper3);padding:2px}
.lines{display:flex;gap:2px}
.line{display:flex;flex-direction:column;gap:2px}
.lname{font-family:ui-monospace,monospace;font-size:.6rem;font-weight:700;color:var(--ink3);
  text-align:center;padding-bottom:2px}
.pos{display:flex;gap:1px;align-items:center}
/* сквозной проход поперёк склада: разрыв в линии между местами 6 и 7 */
.xaisle{height:9px;margin:2px 0;border-top:1px dashed rgba(22,22,15,.32);
  border-bottom:1px dashed rgba(22,22,15,.32);
  background:repeating-linear-gradient(45deg,transparent 0 3px,rgba(22,22,15,.07) 3px 6px)}
.pn{font-family:ui-monospace,monospace;font-size:.52rem;color:var(--ink3);width:14px;
  text-align:right;padding-right:2px}
.cl{width:15px;height:15px;border-radius:2px;display:flex;align-items:center;justify-content:center;
  font-family:ui-monospace,monospace;font-size:.52rem;text-decoration:none;line-height:1}
.cl.free{background:var(--paper);border:1px dashed rgba(22,22,15,.28);color:rgba(22,22,15,.3)}
.cl.free:hover{background:var(--sig-t);border-color:var(--sig);color:var(--sig)}
.cl.busy{color:#fff;border:1px solid transparent}
.cl.ka{background:var(--sig)}
.cl.kt{background:var(--cold)}
.cl.kp{background:var(--ok)}
.cl.busy:hover{outline:2px solid var(--ink);outline-offset:1px}
.aisle{width:22px;display:flex;align-items:center;justify-content:center}
.aisle span{writing-mode:vertical-rl;transform:rotate(180deg);font-family:ui-monospace,monospace;
  font-size:.55rem;letter-spacing:.1em;color:var(--ink3);white-space:nowrap}
.legend{display:flex;flex-wrap:wrap;gap:14px;align-items:center;margin-top:10px;
  font-size:.82rem;color:var(--ink2)}
.legend i.sw{display:inline-block;width:12px;height:12px;border-radius:2px;margin-right:5px;
  vertical-align:-2px}
.sw.free{background:var(--paper);border:1px dashed rgba(22,22,15,.35)}
.sw.ka{background:var(--sig)}.sw.kt{background:var(--cold)}.sw.kp{background:var(--ok)}
.bar{height:8px;background:var(--paper3);border:1px solid var(--rule);border-radius:2px;overflow:hidden}
.bar .fill{height:100%;background:var(--ink)}
@media(max-width:560px){
  .plan{min-width:620px}
  .cl{width:14px;height:14px}
}

/* ── всплывающая карточка занятой ячейки ── */
#tip{position:fixed;z-index:50;max-width:290px;pointer-events:none;opacity:0;
  transform:translateY(-4px);transition:opacity .09s;
  background:var(--ink);color:var(--paper);border-radius:4px;padding:9px 11px;
  box-shadow:0 6px 22px rgba(0,0,0,.3);font-size:.84rem;line-height:1.4}
#tip.on{opacity:1;transform:none}
#tip .t-top{display:flex;align-items:center;gap:7px;margin-bottom:5px}
#tip .t-code{font-family:ui-monospace,monospace;font-size:.72rem;font-weight:700;
  letter-spacing:.06em;background:rgba(242,239,232,.16);padding:.14em .4em;border-radius:2px}
#tip .t-num{font-family:ui-monospace,monospace;font-size:.72rem;color:rgba(242,239,232,.6)}
#tip .t-fio{font-weight:600;font-size:.95rem;letter-spacing:-.01em}
#tip .t-ph{font-family:ui-monospace,monospace;font-size:.8rem;color:rgba(242,239,232,.72);
  margin-top:1px}
#tip .t-what{margin-top:6px;padding-top:6px;border-top:1px solid rgba(242,239,232,.18)}
#tip .t-kind{font-size:.72rem;letter-spacing:.02em}
#tip .t-kind.a{color:#FF8A4C}#tip .t-kind.t{color:#7FB8D4}#tip .t-kind.p{color:#9FD07A}
#tip .t-d{margin-top:1px}
#tip .t-meta{margin-top:5px;font-size:.74rem;color:rgba(242,239,232,.58);
  font-family:ui-monospace,monospace}
#tip .t-ov{color:#FF8A8A}
#tip .t-hint{margin-top:6px;font-size:.7rem;color:rgba(242,239,232,.45)}
@media(hover:none){#tip{display:none}}
</style>

<div id="tip" role="tooltip" aria-hidden="true"></div>

<script>
/* Наведение на занятую ячейку показывает, чей это комплект и что внутри.
   На телефонах наведения нет — там по касанию сразу открывается ячейка. */
(function(){
  const tip = document.getElementById('tip');
  if (!tip || !window.matchMedia('(hover:hover)').matches) return;
  const esc = s => (s||'').replace(/[&<>"]/g, c => (
    {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  function build(d){
    let h = '<div class="t-top"><span class="t-code">' + esc(d.c) + '</span>'
          + '<span class="t-num">№ ' + esc(d.n) + '</span></div>'
          + '<div class="t-fio">' + (esc(d.cl) || 'без имени') + '</div>';
    if (d.ph) h += '<div class="t-ph">' + esc(d.ph) + '</div>';
    h += '<div class="t-what"><div class="t-kind ' + esc(d.kc) + '">' + esc(d.k)
       + (d.q ? ' · ' + esc(d.q) + ' шт.' : '') + '</div>';
    if (d.d) h += '<div class="t-d">' + esc(d.d) + '</div>';
    h += '</div>';
    const meta = [];
    if (d.se)  meta.push(esc(d.se));   // сезон: номера повторяются каждый сезон
    if (d.due) meta.push((d.ov === '1' ? '<span class="t-ov">срок истёк ' : 'до ')
                         + esc(d.due) + (d.ov === '1' ? '</span>' : ''));
    if (meta.length) h += '<div class="t-meta">' + meta.join(' · ') + '</div>';
    h += '<div class="t-hint">нажмите — откроется ячейка</div>';
    return h;
  }

  function place(x, y){
    const r = tip.getBoundingClientRect();
    let left = x + 14, top = y + 16;
    if (left + r.width  > innerWidth  - 8) left = x - r.width - 14;
    if (top  + r.height > innerHeight - 8) top  = y - r.height - 12;
    tip.style.left = Math.max(8, left) + 'px';
    tip.style.top  = Math.max(8, top)  + 'px';
  }
  function show(el, x, y){
    tip.innerHTML = build(el.dataset);
    tip.classList.add('on'); tip.setAttribute('aria-hidden','false');
    place(x, y);
  }
  function hide(){ tip.classList.remove('on'); tip.setAttribute('aria-hidden','true'); }

  document.addEventListener('mouseover', e => {
    const el = e.target.closest('.cl.busy');
    if (el) show(el, e.clientX, e.clientY);
  });
  document.addEventListener('mousemove', e => {
    if (tip.classList.contains('on') && e.target.closest('.cl.busy')) place(e.clientX, e.clientY);
  });
  document.addEventListener('mouseout', e => {
    if (e.target.closest('.cl.busy') && !e.relatedTarget?.closest('.cl.busy')) hide();
  });
  /* клавиатура: таб по ячейкам тоже показывает карточку */
  document.addEventListener('focusin', e => {
    const el = e.target.closest('.cl.busy');
    if (!el) return;
    const r = el.getBoundingClientRect();
    show(el, r.right, r.bottom - 10);
  });
  document.addEventListener('focusout', e => { if (e.target.closest('.cl.busy')) hide(); });
  addEventListener('scroll', hide, true);
  addEventListener('keydown', e => { if (e.key === 'Escape') hide(); });
})();
</script>

<?php foot('map');
