<?php
/* Работа с ячейкой: занять, освободить, переместить комплект. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db   = db();
$cell = strtoupper(trim($_GET['cell'] ?? ''));
$move = (int)($_GET['move'] ?? 0);          // режим «выбрать ячейку для этого комплекта»
$msg  = $err = '';

$valid = in_array($cell, allCells(), true);

// ─────────── действия ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $to  = strtoupper(trim($_POST['cell'] ?? ''));

    if ($act === 'place') {
        // ищем комплект по номеру или по id
        $item = null;
        if (!empty($_POST['item_id'])) {
            $st = $db->prepare("SELECT * FROM items WHERE id=? AND status='stored'");
            $st->execute([(int)$_POST['item_id']]);
            $item = $st->fetch();
        } elseif (($n = (int)($_POST['num'] ?? 0)) > 0) {
            /* Номера повторяются в каждом сезоне, поэтому рядом с номером
               выбирается сезон. Если номер и так один на складе — сезон не важен. */
            $seas = trim((string)($_POST['pseason'] ?? ''));
            $st = $db->prepare("SELECT * FROM items WHERE num=? AND status='stored'");
            $st->execute([$n]);
            $hits = $st->fetchAll();
            if (count($hits) > 1 && $seas !== '') {
                $hits = array_values(array_filter($hits, fn($r) => trim($r['season']) === $seas));
            }
            if (count($hits) === 1) {
                $item = $hits[0];
            } elseif (!$hits) {
                $err = 'Комплект № ' . $n . ' на хранении не найден';
            } else {
                $err = 'Номер ' . $n . ' есть в нескольких сезонах: '
                     . implode('; ', array_map(fn($r) => $r['season'] . ' — ' . $r['client'], $hits))
                     . '. Выберите сезон рядом с номером.';
            }
        } else {
            $err = 'Не указан номер комплекта';
        }

        if ($item && !$err) {
            if (!in_array($to, allCells(), true)) {
                $err = 'Неверный адрес ячейки';
            } else {
                $st = $db->prepare("SELECT num, season, client FROM items
                                    WHERE cell=? AND status='stored' AND id<>?");
                $st->execute([$to, $item['id']]);
                if ($busy = $st->fetch()) {
                    $err = 'Ячейка ' . $to . ' занята: № ' . fullNum($busy) . ', ' . $busy['client'];
                } else {
                    $from = $item['cell'];
                    $db->prepare('UPDATE items SET cell=?, updated_at=? WHERE id=?')
                       ->execute([$to, date('Y-m-d H:i:s'), $item['id']]);
                    logit((int)$item['id'], 'cell',
                          $from ? "перемещён $from → $to" : "размещён в $to");
                    header('Location: cell.php?cell=' . urlencode($to) . '&ok=' . ($from ? 2 : 1));
                    exit;
                }
            }
        }
    }

    if ($act === 'clear') {
        $iid = (int)($_POST['item_id'] ?? 0);
        $st = $db->prepare('SELECT cell FROM items WHERE id=?');
        $st->execute([$iid]);
        if ($from = $st->fetchColumn()) {
            $db->prepare("UPDATE items SET cell='', updated_at=? WHERE id=?")
               ->execute([date('Y-m-d H:i:s'), $iid]);
            logit($iid, 'cell', "снят с ячейки $from");
        }
        header('Location: map.php'); exit;
    }
}

$OKM = ['1' => 'Комплект размещён в ячейке', '2' => 'Комплект перемещён'];
if (isset($_GET['ok'], $OKM[$_GET['ok']])) $msg = $OKM[$_GET['ok']];

// ─────────── данные ───────────
$occupant = null;
if ($valid) {
    $st = $db->prepare("SELECT * FROM items WHERE cell=? AND status='stored'");
    $st->execute([$cell]);
    $occupant = $st->fetch();
}

$moveItem = null;
if ($move) {
    $st = $db->prepare("SELECT * FROM items WHERE id=? AND status='stored'");
    $st->execute([$move]);
    $moveItem = $st->fetch();
    if (!$moveItem) { http_response_code(404); exit('Комплект не найден'); }
}

// комплекты без ячейки — для быстрого выбора
$free = $db->query("SELECT id, num, season, client, descr, size, kind FROM items
                    WHERE status='stored' AND cell=''
                    ORDER BY season DESC, CAST(num AS INTEGER)")->fetchAll();
/* Подсказка по номеру: ключ «номер|сезон», плюс список сезонов для выбора.
   Если номер встречается один раз — сезон не спрашиваем. */
$all = $nums = $seasons = [];
foreach ($db->query("SELECT id, num, season, client, descr, cell FROM items
                     WHERE status='stored' ORDER BY season DESC, CAST(num AS INTEGER)") as $r) {
    $sn = trim((string)$r['season']);
    $all[(int)$r['num'] . '|' . $sn] = ['id' => (int)$r['id'], 'c' => $r['client'],
        'd' => $r['descr'], 'cell' => $r['cell'], 'se' => $sn];
    $nums[(int)$r['num']] = true;
    if ($sn !== '') $seasons[$sn] = true;
}
$nums = array_keys($nums); sort($nums);
$seasons = array_keys($seasons); rsort($seasons);

$taken = array_flip($db->query("SELECT cell FROM items WHERE status='stored' AND cell<>''")
                       ->fetchAll(PDO::FETCH_COLUMN));
?>
<?php head($move ? 'Куда положить' : ('Ячейка ' . $cell), 'map'); ?>

<p style="margin:0 0 10px"><a class="small muted" href="map.php" style="text-decoration:none">← К карте склада</a></p>

<?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="flash" style="background:rgba(216,65,12,.1);border-color:var(--sig);color:#8a2c06"><?= e($err) ?></div><?php endif; ?>

<?php /* ═══════════ РЕЖИМ 1: выбираем ячейку для комплекта ═══════════ */ ?>
<?php if ($move): ?>
  <h1>Куда положить</h1>
  <div class="card">
    <dl class="kv">
      <dt>Комплект</dt><dd><b>№ <?= e(fullNum($moveItem)) ?></b> · <?= e($moveItem['client']) ?></dd>
      <dt>Что это</dt><dd><?= e($moveItem['descr']) ?></dd>
      <dt>Сейчас</dt><dd><?= $moveItem['cell']
        ? '<span class="mono">' . e($moveItem['cell']) . '</span>'
        : '<span style="color:var(--sig)">без ячейки</span>' ?></dd>
    </dl>
  </div>
  <p class="small muted" style="margin:0 0 10px">
    Нажмите свободную ячейку — комплект переедет туда.
    <?= $moveItem['kind'] === 'assembled'
        ? 'Колёса в сборе тяжёлые, кладите на ярусы 1 и 2.'
        : 'Шины лёгкие, им подходит ярус 3.' ?>
  </p>
  <?php renderMap($taken, $moveItem, null); ?>

  <?php if ($moveItem['cell']): ?>
    <form method="post" style="margin:14px 0 24px" onsubmit="return confirm('Снять комплект с ячейки? Он останется на хранении, но без адреса.')">
      <input type="hidden" name="act" value="clear">
      <input type="hidden" name="item_id" value="<?= (int)$moveItem['id'] ?>">
      <button class="btn g sm" type="submit">Снять с ячейки <?= e($moveItem['cell']) ?></button>
    </form>
  <?php endif; ?>

<?php /* ═══════════ РЕЖИМ 2: ячейка занята ═══════════ */ ?>
<?php elseif ($valid && $occupant): ?>
  <h1>Ячейка <span class="mono"><?= e($cell) ?></span></h1>
  <div class="card">
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px">
      <span class="tag mono" style="background:var(--paper3);border-color:var(--rule);color:var(--ink2)">№ <?= e(fullNum($occupant)) ?></span>
      <span class="tag <?= kindCls($occupant['kind']) ?>"><?= e(kindName($occupant['kind'])) ?></span>
    </div>
    <dl class="kv">
      <dt>Клиент</dt><dd><b><?= e($occupant['client']) ?></b></dd>
      <?php if ($occupant['phone_digits']): ?>
        <dt>Телефон</dt><dd><a class="mono" style="color:var(--sig);font-weight:700;text-decoration:none"
          href="tel:+<?= e($occupant['phone_digits']) ?>"><?= e($occupant['phone']) ?></a></dd>
      <?php endif; ?>
      <dt>Что лежит</dt><dd><?= e($occupant['descr']) ?></dd>
      <dt>Принято</dt><dd><?= e(ruDate($occupant['accepted_at'])) ?> · до <?= e(ruDate($occupant['due_at'])) ?></dd>
    </dl>
    <div class="btns" style="margin-top:14px">
      <a class="btn" href="item.php?id=<?= (int)$occupant['id'] ?>">Открыть карточку</a>
      <a class="btn g" href="cell.php?move=<?= (int)$occupant['id'] ?>">Переместить</a>
    </div>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('Снять комплект с ячейки? Он останется на хранении, но без адреса.')">
      <input type="hidden" name="act" value="clear">
      <input type="hidden" name="item_id" value="<?= (int)$occupant['id'] ?>">
      <button class="btn g sm" type="submit">Освободить ячейку</button>
    </form>
  </div>

<?php /* ═══════════ РЕЖИМ 3: ячейка свободна ═══════════ */ ?>
<?php elseif ($valid): ?>
  <h1>Ячейка <span class="mono"><?= e($cell) ?></span> свободна</h1>
  <?php
    [$line, $sec, $tier] = explode('-', $cell);
    $tierN = (int)$tier;
  ?>
  <p class="small muted" style="margin:0 0 12px">
    Ряд <?= e($line) ?>, место <?= e($sec) ?> от ворот, ярус <?= $tierN ?> —
    <?= $tierN <= 2 ? 'нижний, под колёса в сборе' : 'верхний, под шины без дисков' ?>.
    <?= (int)$sec <= 3 ? 'Близко ко входу — удобно для тех, кто заберёт раньше.'
        : ((int)$sec >= SECTIONS - 2 ? 'Дальний угол — под тех, кто точно до конца сезона.' : '') ?>
  </p>

  <div class="card">
    <h3>Разместить уже принятый комплект</h3>
    <form method="post">
      <input type="hidden" name="act" value="place">
      <input type="hidden" name="cell" value="<?= e($cell) ?>">
      <div class="f">
        <label for="num">Номер с бирки</label>
        <div class="numrow">
          <input id="num" name="num" type="number" inputmode="numeric" class="mono"
                 placeholder="например 42" autocomplete="off" list="nums">
          <select id="pseason" name="pseason" aria-label="Сезон">
            <?php foreach ($seasons as $sn): ?>
              <option value="<?= e($sn) ?>"><?= e($sn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <datalist id="nums">
          <?php foreach ($nums as $n): ?><option value="<?= (int)$n ?>"><?php endforeach; ?>
        </datalist>
        <p id="hint" class="small" style="margin:7px 0 0;min-height:1.3em;color:var(--ink3)"></p>
      </div>
      <button class="btn" type="submit">Положить сюда</button>
    </form>
  </div>

  <?php if ($free): ?>
  <div class="card">
    <h3>Ждут адреса · <?= count($free) ?></h3>
    <p class="small muted" style="margin:-6px 0 10px">
      Эти комплекты на складе, но ячейка им не присвоена. Нажмите — положим в <?= e($cell) ?>.</p>
    <div class="picklist">
      <?php foreach (array_slice($free, 0, 40) as $f): ?>
        <form method="post" class="pick">
          <input type="hidden" name="act" value="place">
          <input type="hidden" name="cell" value="<?= e($cell) ?>">
          <input type="hidden" name="item_id" value="<?= (int)$f['id'] ?>">
          <button type="submit" title="<?= e($f['descr']) ?>">
            <span class="pn">№<?= (int)$f['num'] ?></span>
            <span class="pc"><?= e($f['client']) ?></span>
            <span class="ps mono"><?= e($f['size']) ?></span>
            <span class="pk <?= kindCls($f['kind']) ?>"><?= e(kindShort($f['kind'])) ?></span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if (count($free) > 40): ?>
      <p class="small muted" style="margin:10px 0 0">Показаны первые 40 из <?= count($free) ?>.
         Остальные ищите по номеру выше.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>Или принять новый</h3>
    <a class="btn s" href="item.php?new=1&cell=<?= e($cell) ?>">Новый комплект в <?= e($cell) ?></a>
  </div>

<?php else: ?>
  <h1>Ячейка не найдена</h1>
  <p class="empty">Адрес <span class="mono"><?= e($cell) ?></span> не входит в схему склада.<br>
    <a href="map.php">Вернуться к карте</a></p>
<?php endif; ?>

<style>
.picklist{display:flex;flex-direction:column;gap:5px;max-height:340px;overflow-y:auto}
.pick button{display:flex;align-items:baseline;gap:9px;width:100%;text-align:left;font:inherit;
  background:var(--paper);border:1.3px solid var(--rule);border-radius:3px;padding:8px 10px;
  cursor:pointer;color:var(--ink)}
.pick button:hover{border-color:var(--sig);background:var(--sig-t)}
.pick .pn{font-family:ui-monospace,monospace;font-size:.76rem;color:var(--ink3);flex:none;width:42px}
.numrow{display:flex;gap:8px}
.numrow input{flex:0 0 110px}
.numrow select{flex:1;min-width:0;font:inherit;padding:.55em .6em;border:1.4px solid var(--rule);
  border-radius:3px;background:var(--paper);color:var(--ink)}
.pick .pc{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pick .ps{font-size:.8rem;color:var(--ink2);flex:none}
.pick .pk{font-family:ui-monospace,monospace;font-size:.6rem;padding:.12em .34em;border-radius:2px;flex:none}
.pick .pk.a{color:var(--sig);background:var(--sig-t)}
.pick .pk.t{color:var(--cold);background:var(--cold-t)}
.pick .pk.p{color:var(--ok);background:var(--ok-t)}
</style>

<script>
const ALL = <?= json_encode($all, JSON_UNESCAPED_UNICODE) ?>;
const num = document.getElementById('num'), hint = document.getElementById('hint');
const pse = document.getElementById('pseason');
function look(){
  const n = parseInt(num.value, 10);
  if (!n) { hint.textContent = ''; return; }
  /* сколько комплектов с этим номером вообще есть — от этого зависит,
     нужен ли выбор сезона */
  const keys = Object.keys(ALL).filter(k => parseInt(k, 10) === n);
  if (!keys.length) {
    hint.style.color = 'var(--sig)';
    hint.textContent = 'Комплект с таким номером не найден';
    pse.disabled = true; return;
  }
  pse.disabled = keys.length < 2;
  const key = keys.length > 1 ? (n + '|' + pse.value) : keys[0];
  const v = ALL[key];
  if (!v) {
    hint.style.color = 'var(--sig)';
    hint.textContent = 'В сезоне ' + pse.value + ' номера ' + n + ' нет';
    return;
  }
  hint.style.color = 'var(--ink3)';
  hint.textContent = '№ ' + n + ' · ' + v.se + ' · ' + v.c + ' · ' + v.d
                   + (v.cell ? ' · сейчас в ' + v.cell : ' · без ячейки');
}
if (num) { num.addEventListener('input', look); pse.addEventListener('change', look); look(); }
</script>

<?php foot('map');


/* ─────────── мини-карта для выбора ячейки ─────────── */
function renderMap(array $taken, array $item, ?string $current): void { ?>
  <div class="planwrap">
    <div class="plan">
      <div class="farwall">дальняя стена · место <?= sprintf('%02d', SECTIONS) ?></div>
      <div class="rows">
        <?php foreach (ROWS as $ri => $row): ?>
          <?php if ($ri > 0): ?><div class="aisle"><span>проход</span></div><?php endif; ?>
          <div class="rack <?= $row['kind'] ?>">
            <div class="rname"><?= e(rackName($row)) ?></div>
            <div class="lines">
              <?php foreach ($row['lines'] as $line): ?>
                <div class="line">
                  <div class="lname"><?= e($line) ?></div>
                  <?php for ($s = SECTIONS; $s >= 1; $s--): ?>
                    <?php if (CROSS_AISLE_AFTER && $s === CROSS_AISLE_AFTER): ?>
                      <div class="xaisle"></div>
                    <?php endif; ?>
                    <div class="pos">
                      <span class="pn"><?= sprintf('%02d', $s) ?></span>
                      <?php for ($t = 1; $t <= TIERS; $t++):
                        $code = cellCode($line, $s, $t);
                        $isMine = $code === $item['cell'];
                        $busy   = isset($taken[$code]) && !$isMine;
                        if ($isMine): ?>
                          <span class="cl mine" title="сейчас здесь"><?= $t ?></span>
                        <?php elseif ($busy): ?>
                          <span class="cl taken" title="занято"><?= $t ?></span>
                        <?php else: ?>
                          <form method="post" style="display:contents">
                            <input type="hidden" name="act" value="place">
                            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
                            <input type="hidden" name="cell" value="<?= e($code) ?>">
                            <button class="cl free" type="submit" title="<?= e($code) ?>"><?= $t ?></button>
                          </form>
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
      <div class="gatezone"><div class="gate">ВОРОТА · место 01</div></div>
    </div>
  </div>
  <div class="legend">
    <span><i class="sw free"></i> свободна — нажмите</span>
    <span><i class="sw taken"></i> занята</span>
    <span><i class="sw mine"></i> сейчас здесь</span>
  </div>
  <style>
  .planwrap{overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:6px}
  .plan{min-width:660px;border:1.5px solid var(--ink);border-radius:3px;background:var(--paper2)}
  .farwall,.gate{font-family:ui-monospace,monospace;font-size:.66rem;letter-spacing:.12em;
    text-transform:uppercase;text-align:center;padding:7px 4px;color:var(--ink3)}
  .farwall{border-bottom:1.5px solid var(--rule);background:var(--paper3)}
  .gatezone{border-top:1.5px solid var(--rule);
    background:repeating-linear-gradient(45deg,var(--paper3) 0 6px,var(--paper2) 6px 12px)}
  .gate{color:var(--sig);font-weight:700}
  .rows{display:flex;align-items:stretch;justify-content:center;padding:10px 8px}
  .rack{display:flex;flex-direction:column;align-items:center}
  .rname{font-family:ui-monospace,monospace;font-size:.68rem;font-weight:700;margin-bottom:5px}
  .lines{display:flex;gap:2px;border:1.4px solid var(--rule);border-radius:2px;
    background:var(--paper3);padding:2px}
  .rack.double .lines{border-color:var(--ink)}
  .line{display:flex;flex-direction:column;gap:2px}
  .lname{font-family:ui-monospace,monospace;font-size:.6rem;font-weight:700;color:var(--ink3);
    text-align:center;padding-bottom:2px}
  .pos{display:flex;gap:1px;align-items:center}
  .xaisle{height:9px;margin:2px 0;border-top:1px dashed rgba(22,22,15,.32);
    border-bottom:1px dashed rgba(22,22,15,.32)}
  .pn{font-family:ui-monospace,monospace;font-size:.52rem;color:var(--ink3);width:14px;
    text-align:right;padding-right:2px}
  .cl{width:16px;height:16px;border-radius:2px;display:flex;align-items:center;
    justify-content:center;font-family:ui-monospace,monospace;font-size:.54rem;line-height:1;
    padding:0;border:1px solid transparent}
  button.cl.free{background:var(--paper);border:1px dashed rgba(22,22,15,.3);
    color:rgba(22,22,15,.35);cursor:pointer}
  button.cl.free:hover{background:var(--sig);border-color:var(--sig);color:#fff}
  .cl.taken{background:var(--ink3);color:rgba(242,239,232,.55)}
  .cl.mine{background:var(--cold);color:#fff;outline:2px solid var(--cold);outline-offset:1px}
  .aisle{width:20px;display:flex;align-items:center;justify-content:center}
  .aisle span{writing-mode:vertical-rl;transform:rotate(180deg);font-family:ui-monospace,monospace;
    font-size:.55rem;color:var(--ink3)}
  .legend{display:flex;flex-wrap:wrap;gap:14px;margin-top:10px;font-size:.82rem;color:var(--ink2)}
  .legend i.sw{display:inline-block;width:12px;height:12px;border-radius:2px;margin-right:5px;
    vertical-align:-2px}
  .sw.free{background:var(--paper);border:1px dashed rgba(22,22,15,.35)}
  .sw.taken{background:var(--ink3)}.sw.mine{background:var(--cold)}
  </style>
<?php }
