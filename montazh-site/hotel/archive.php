<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db  = db();
$tab = ($_GET['tab'] ?? '') === 'trash' ? 'trash' : 'issued';

$rows = $tab === 'trash'
  ? $db->query("SELECT i.*,
        (SELECT COUNT(*) FROM photos p WHERE p.item_id = i.id) AS nph
        FROM items i WHERE status='deleted'
        ORDER BY COALESCE(deleted_at,'') DESC, id DESC")->fetchAll()
  : $db->query("SELECT i.*,
        (SELECT COUNT(*) FROM photos p WHERE p.item_id = i.id) AS nph
        FROM items i WHERE status='issued'
        ORDER BY COALESCE(NULLIF(issued_at,''),'0000-00-00') DESC, id DESC")->fetchAll();

$nTrash = (int)$db->query("SELECT COUNT(*) FROM items WHERE status='deleted'")->fetchColumn();

$total   = count($rows);
$sumPaid = 0;
$byYear  = [];
$search  = [];
foreach ($rows as $r) {
    $sumPaid += (int)$r['paid'];
    $y = substr((string)($r['issued_at'] ?: $r['deleted_at']), 0, 4) ?: '—';
    $byYear[$y] = ($byYear[$y] ?? 0) + 1;
    $search[(int)$r['id']] = mb_strtolower(implode(' ', [
        $r['num'], fullNum($r), $r['client'], $r['phone'], $r['phone_digits'],
        $r['descr'], $r['size'], $r['brand'], $r['season'], $r['note'],
    ]), 'UTF-8');
}
krsort($byYear);
$OKA = ['1' => 'Комплект выдан и перенесён в архив',
        '2' => 'Запись перенесена в корзину — её можно вернуть'];
if (isset($_GET['ok'], $OKA[$_GET['ok']])) $msg = $OKA[$_GET['ok']];
?>
<?php head('Архив', 'arch'); ?>

<h1><?= $tab === 'trash' ? 'Корзина' : 'Архив выданных' ?></h1>

<div class="chips" style="margin-bottom:12px">
  <a class="chip <?= $tab === 'issued' ? 'on' : '' ?>" href="archive.php" style="text-decoration:none">Выданные</a>
  <a class="chip <?= $tab === 'trash' ? 'on' : '' ?>" href="archive.php?tab=trash" style="text-decoration:none">Корзина<?= $nTrash ? ' · ' . $nTrash : '' ?></a>
</div>
<?php if (!empty($msg)): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>

<div class="stats">
  <div class="stat"><b><?= $total ?></b><span><?= $tab === 'trash' ? 'в корзине' : 'выдано всего' ?></span></div>
  <div class="stat"><b><?= number_format($sumPaid, 0, ',', ' ') ?></b><span>₽ по этим записям</span></div>
  <?php $y = array_key_first($byYear); ?>
  <div class="stat"><b><?= $y ? (int)($byYear[$y] ?? 0) : 0 ?></b><span>за <?= e($y ?: '—') ?> год</span></div>
  <a class="stat sig" href="index.php"><b>→</b><span>к текущему хранению</span></a>
</div>

<?php if (!$rows): ?>
  <p class="empty"><?= $tab === 'trash'
    ? 'Корзина пуста.<br>Сюда попадают удалённые записи — их всегда можно вернуть.'
    : 'Архив пуст.<br>Сюда попадают комплекты после нажатия «Выдать клиенту».' ?></p>
<?php else: ?>

<div class="search">
  <input id="q" type="search" inputmode="search" autocomplete="off"
         placeholder="ФИО, телефон, размер…">
  <button class="x" id="qx" type="button" aria-label="Очистить" hidden>×</button>
</div>

<div class="tblwrap">
  <table class="tbl" id="tbl">
    <thead>
      <tr>
        <th class="c">№</th>
        <th>Сезон</th>
        <th><?= $tab === 'trash' ? 'Удалено' : 'Выдано' ?></th>
        <th>ФИО</th>
        <th>Телефон</th>
        <th>Что хранили</th>
        <th class="r">Оплачено</th>
        <th>Принято</th>
        <th>Комментарий</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-id="<?= (int)$r['id'] ?>" onclick="location='item.php?id=<?= (int)$r['id'] ?>'">
          <td class="c mono num"><?= (int)$r['num'] ?></td>
          <td class="mono nw seas"><?= e($r['season'] ?: '—') ?></td>
          <td class="mono nw"><?= $tab === 'trash'
            ? e(substr((string)$r['deleted_at'], 0, 10) ? ruDate(substr((string)$r['deleted_at'], 0, 10)) : '—')
            : e(ruDate($r['issued_at'])) ?></td>
          <td><span class="fio"><?= e($r['client']) ?></span>
            <?php if ($r['nph']): ?><span class="ph">◨<?= (int)$r['nph'] ?></span><?php endif; ?></td>
          <td class="mono nw">
            <?php if ($r['phone_digits']): ?>
              <a href="tel:+<?= e($r['phone_digits']) ?>" onclick="event.stopPropagation()"><?= e($r['phone']) ?></a>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="what <?= kindCls($r['kind']) ?>"
              title="<?= e(kindName($r['kind'])) ?>"><?= e($r['descr']) ?></td>
          <td class="r mono nw"><?= number_format((int)$r['paid'], 0, ',', ' ') ?></td>
          <td class="mono nw small"><?= e(ruDate($r['accepted_at'])) ?></td>
          <td class="note"><?= e($r['note']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="mn" id="cnt" style="margin:10px 0 0"></p>

<p class="small muted" style="margin-top:12px">
  <?= $tab === 'trash'
    ? 'Открыли карточку — там кнопки «Восстановить на хранение» и «Стереть навсегда». Фотографии и история сохраняются, пока запись лежит в корзине.'
    : 'Записи не удаляются: по любой можно открыть карточку, посмотреть фотографии и при необходимости вернуть на хранение. Так же виден весь список того, что клиент сдавал раньше — удобно, когда он звонит снова.' ?>
</p>

<style>
.tblwrap{overflow-x:auto;border:1.5px solid var(--rule);border-radius:3px;background:var(--paper);
  -webkit-overflow-scrolling:touch}
table.tbl{border-collapse:collapse;width:100%;font-size:.9rem;min-width:930px}
table.tbl th{position:sticky;top:0;z-index:2;background:var(--ink3);color:var(--paper);
  text-align:left;padding:8px 9px;font-weight:600;font-size:.76rem;white-space:nowrap}
table.tbl td{padding:7px 9px;border-bottom:1px solid var(--rule2);vertical-align:top}
table.tbl tbody tr{cursor:pointer}
table.tbl tbody tr:nth-child(even){background:rgba(22,22,15,.028)}
table.tbl tbody tr:hover{background:var(--sig-t)}
.tbl .c{text-align:center}.tbl .r{text-align:right}.tbl .nw{white-space:nowrap}
.tbl .num{color:var(--ink3);font-size:.82rem}
.tbl .seas{font-size:.72rem;color:var(--ink2);letter-spacing:.02em}
.tbl .fio{font-weight:600}
.tbl td a{color:var(--sig);text-decoration:none}
.tbl .what{font-weight:500}
.tbl .what.a{color:#9c3208;background:rgba(216,65,12,.06)}
.tbl .what.t{color:#1c4155;background:rgba(38,88,111,.06)}
.tbl .what.p{color:#31461f;background:rgba(75,106,56,.08)}
.tbl .ph{font-size:.66rem;font-family:ui-monospace,monospace;color:var(--cold);
  background:var(--cold-t);padding:.15em .35em;border-radius:2px;margin-left:5px}
.tbl .note{font-size:.85rem;color:var(--ink2);max-width:220px}
</style>

<script>
const SEARCH = <?= json_encode($search, JSON_UNESCAPED_UNICODE) ?>;
const q=document.getElementById('q'), qx=document.getElementById('qx'), cnt=document.getElementById('cnt');
const trs=Array.from(document.querySelectorAll('#tbl tbody tr'));
const norm=s=>(s||'').toLowerCase().replace(/ё/g,'е');
function apply(){
  const t=norm(q.value.trim()), dig=t.replace(/\D/g,''); let n=0;
  for(const tr of trs){
    const s=SEARCH[tr.dataset.id]||'';
    const ok=!t || s.includes(t) || (dig.length>=3 && s.includes(dig));
    tr.hidden=!ok; if(ok)n++;
  }
  cnt.textContent=n+' '+plural(n,'запись','записи','записей'); qx.hidden=!q.value;
}
function plural(n,a,b,c){const t=n%10,h=n%100;
  if(t===1&&h!==11)return a; if(t>=2&&t<=4&&(h<10||h>=20))return b; return c;}
q.addEventListener('input',apply);
qx.addEventListener('click',()=>{q.value='';apply();q.focus();});
apply();
</script>
<?php endif; ?>

<?php foot('arch');
