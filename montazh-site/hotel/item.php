<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$new = isset($_GET['new']) || !$id;
$msg = '';

// ─────────── действия ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = $_POST['act'];

    if ($act === 'save') {
        $descr = trim($_POST['descr'] ?? '');
        $dig   = phoneDigits($_POST['phone'] ?? '');
        $acc   = trim($_POST['accepted_at'] ?? '') ?: date('Y-m-d');
        $kind  = kindOf($_POST['kind'] ?? 'tires');
        $seas  = trim($_POST['season'] ?? '');

        $f = [
            // номер считается внутри сезона, см. nextNum() в db.php
            'num'           => (int)($_POST['num'] ?? 0) ?: nextNum($seas),
            'season'        => $seas,
            'kind'          => $kind,
            'client'        => trim($_POST['client'] ?? ''),
            'phone'         => phoneFmt($dig),
            'phone_digits'  => $dig,
            'descr'         => $descr,
            'size'          => trim($_POST['size'] ?? '') ?: parseSize($descr),
            'brand'         => trim($_POST['brand'] ?? ''),
            'qty'           => max(1, (int)($_POST['qty'] ?? 4)),
            'cell'          => strtoupper(trim($_POST['cell'] ?? '')),
            'accepted_at'   => $acc,
            'due_at'        => trim($_POST['due_at'] ?? '') ?: addMonths($acc, kindMonths($kind)),
            'paid'          => (int)($_POST['paid'] ?? 0),
            'services'      => trim($_POST['services'] ?? ''),
            'services_sum'  => (int)($_POST['services_sum'] ?? 0),
            'services_paid' => (int)($_POST['services_paid'] ?? 0),
            'note'          => trim($_POST['note'] ?? ''),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($f['client'] === '') {
            $msg = 'Не указан клиент';
        } else {
            $busy = null;
            if ($f['cell']) {
                $st = $db->prepare("SELECT id, client FROM items
                                    WHERE cell=? AND status='stored' AND id<>?");
                $st->execute([$f['cell'], $id]);
                $busy = $st->fetch();
            }
            if ($busy) {
                $msg = 'Ячейка ' . $f['cell'] . ' занята: ' . $busy['client'];
            } else {
                if ($id) {
                    $set = implode(',', array_map(fn($k) => "$k=:$k", array_keys($f)));
                    $db->prepare("UPDATE items SET $set WHERE id=:id")->execute($f + ['id' => $id]);
                    logit($id, 'edit', 'карточка изменена');
                } else {
                    /* Номер уникален внутри сезона. Если такой уже занят —
                       например, сезон поменяли руками после подстановки —
                       тихо берём следующий свободный, а не плодим двойников. */
                    $dup = $db->prepare('SELECT COUNT(*) FROM items WHERE num=? AND season=?');
                    $dup->execute([$f['num'], $f['season']]);
                    if ((int)$dup->fetchColumn() > 0) $f['num'] = nextNum($f['season']);

                    $f['status'] = 'stored';
                    $f['created_at'] = date('Y-m-d H:i:s');
                    $cols = implode(',', array_keys($f));
                    $ph   = ':' . implode(',:', array_keys($f));
                    $db->prepare("INSERT INTO items ($cols) VALUES ($ph)")->execute($f);
                    $id = (int)$db->lastInsertId();
                    logit($id, 'accept', $f['client'] . ' · ' . $f['descr']);
                }
                // фотографии, приложенные к той же форме
                [$n, $errs] = takePhotos($db, $id, ($_POST['pstage'] ?? 'accept'));
                $q = 'item.php?id=' . $id . '&ok=' . ($_POST['isnew'] ?? '') . '1';
                if ($n)    $q .= '&ph=' . $n;
                if ($errs) $q .= '&pherr=' . rawurlencode(implode('; ', $errs));
                header('Location: ' . $q); exit;
            }
        }
    }

    if ($act === 'issue' && $id) {
        $db->prepare("UPDATE items SET status='issued', issued_at=?, cell='', updated_at=?
                      WHERE id=?")->execute([date('Y-m-d'), date('Y-m-d H:i:s'), $id]);
        logit($id, 'issue', 'выдано клиенту');
        header('Location: archive.php?ok=1'); exit;
    }
    if ($act === 'return' && $id) {
        $db->prepare("UPDATE items SET status='stored', issued_at=NULL, updated_at=?
                      WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        logit($id, 'return', 'возвращено на хранение');
        header('Location: item.php?id=' . $id . '&ok=4'); exit;
    }
    if ($act === 'addphoto' && $id) {
        [$n, $errs] = takePhotos($db, $id, ($_POST['pstage'] ?? 'accept'));
        $q = 'item.php?id=' . $id;
        if ($n)    $q .= '&ph=' . $n;
        if ($errs) $q .= '&pherr=' . rawurlencode(implode('; ', $errs));
        header('Location: ' . $q . '#foto'); exit;
    }
    if ($act === 'stage' && $id) {
        $pid = (int)($_POST['pid'] ?? 0);
        $to  = ($_POST['to'] ?? '') === 'wash' ? 'wash' : 'accept';
        $db->prepare('UPDATE photos SET stage=? WHERE id=? AND item_id=?')->execute([$to, $pid, $id]);
        header('Location: item.php?id=' . $id . '#foto'); exit;
    }
    if ($act === 'delphoto' && $id) {
        $pid = (int)($_POST['pid'] ?? 0);
        $st = $db->prepare('SELECT file FROM photos WHERE id=? AND item_id=?');
        $st->execute([$pid, $id]);
        if ($file = $st->fetchColumn()) {
            @unlink(PHOTO_DIR . '/' . $file);
            $db->prepare('DELETE FROM photos WHERE id=?')->execute([$pid]);
            logit($id, 'photo', 'фото удалено');
        }
        header('Location: item.php?id=' . $id); exit;
    }
    // удаление мягкое: запись уходит в корзину, оттуда её можно вернуть
    if ($act === 'delete' && $id) {
        $db->prepare("UPDATE items SET status='deleted', deleted_at=?, cell='', updated_at=?
                      WHERE id=?")->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        logit($id, 'delete', 'перенесено в корзину');
        header('Location: archive.php?tab=trash&ok=2'); exit;
    }
    if ($act === 'undelete' && $id) {
        $db->prepare("UPDATE items SET status='stored', deleted_at=NULL, updated_at=?
                      WHERE id=?")->execute([date('Y-m-d H:i:s'), $id]);
        logit($id, 'return', 'восстановлено из корзины');
        header('Location: item.php?id=' . $id . '&ok=5'); exit;
    }
    if ($act === 'purge' && $id) {
        foreach ($db->query("SELECT file FROM photos WHERE item_id=$id") as $r)
            @unlink(PHOTO_DIR . '/' . $r['file']);
        $db->prepare('DELETE FROM photos WHERE item_id=?')->execute([$id]);
        $db->prepare('DELETE FROM log WHERE item_id=?')->execute([$id]);
        $db->prepare('DELETE FROM items WHERE id=?')->execute([$id]);
        header('Location: archive.php?tab=trash'); exit;
    }
}

/** Забирает все файлы поля photos[], возвращает [сколько сохранено, список ошибок]. */
function takePhotos(PDO $db, int $id, string $stageRaw): array {
    $stage = $stageRaw === 'wash' ? 'wash' : 'accept';
    $n = 0; $errs = [];
    if (empty($_FILES['photos']['name'])) return [0, []];
    $names = (array)$_FILES['photos']['name'];
    foreach ($names as $i => $nm) {
        $one = [
            'name'     => $nm,
            'type'     => $_FILES['photos']['type'][$i]     ?? '',
            'tmp_name' => $_FILES['photos']['tmp_name'][$i] ?? '',
            'error'    => $_FILES['photos']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $_FILES['photos']['size'][$i]     ?? 0,
        ];
        $err = null;
        if ($file = savePhoto($one, $id, $err)) {
            $db->prepare('INSERT INTO photos(item_id,file,at,stage) VALUES(?,?,?,?)')
               ->execute([$id, $file, date('Y-m-d H:i:s'), $stage]);
            $n++;
        } elseif ($err !== null) {
            $errs[] = ($nm !== '' ? $nm . ' — ' : '') . $err;
        }
    }
    if ($n) logit($id, 'photo',
        'добавлено фото: ' . $n . ($stage === 'wash' ? ' (после мойки)' : ' (при приёме)'));
    return [$n, $errs];
}

// ─────────── данные ───────────
if ($id) {
    $st = $db->prepare('SELECT * FROM items WHERE id=?');
    $st->execute([$id]);
    $it = $st->fetch();
    if (!$it) { http_response_code(404); exit('Комплект не найден'); }
    $new = false;
} else {
    $mon = (int)date('n');
    $preDig = phoneDigits($_GET['phone'] ?? '');
    // сезон определяется первым: от него зависит номер по порядку
    $newSeason = ($mon >= 4 && $mon <= 9 ? 'ЗИМА ' : 'ЛЕТО ') . date('Y');
    $it = [
        'id' => 0, 'num' => nextNum($newSeason), 'kind' => 'tires',
        'client' => trim($_GET['client'] ?? ''), 'phone' => phoneFmt($preDig),
        'phone_digits' => $preDig, 'descr' => '', 'size' => '', 'brand' => '', 'qty' => 4,
        'cell' => strtoupper(trim($_GET['cell'] ?? '')), 'accepted_at' => date('Y-m-d'),
        'due_at' => addMonths(date('Y-m-d'), SEASON_MONTHS),
        'paid' => PRICE_TIRES, 'services' => '', 'services_sum' => 0, 'services_paid' => 0,
        'status' => 'stored', 'issued_at' => null, 'note' => '',
        'season' => $newSeason,
    ];
    // возврат с карты: подхватываем всё, что было введено до ухода
    foreach (['num','kind','descr','size','qty','accepted_at','due_at',
              'paid','season','services','services_sum','services_paid','note'] as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') $it[$k] = trim((string)$_GET[$k]);
    }
    $it['kind'] = kindOf($it['kind']);
    if (!isset($_GET['paid']))   $it['paid']   = kindPrice($it['kind']);
    if (!isset($_GET['due_at'])) $it['due_at'] = addMonths($it['accepted_at'], kindMonths($it['kind']));
}

$OK = ['1'=>'Сохранено', '11'=>'Комплект принят на хранение', '4'=>'Возвращено на хранение',
       '5'=>'Восстановлено из корзины'];
if (isset($_GET['ok'], $OK[$_GET['ok']])) $msg = $OK[$_GET['ok']];
if (!empty($_GET['ph'])) {
    $np = (int)$_GET['ph'];
    $w  = ($np % 10 === 1 && $np % 100 !== 11) ? 'снимок'
        : (($np % 10 >= 2 && $np % 10 <= 4 && ($np % 100 < 10 || $np % 100 >= 20)) ? 'снимка' : 'снимков');
    $msg = trim($msg . ($msg ? ' · ' : '') . "добавлено $np $w");
}
$phErr = isset($_GET['pherr']) ? (string)$_GET['pherr'] : '';

$taken = $db->query("SELECT cell FROM items WHERE status='stored' AND cell<>''")
            ->fetchAll(PDO::FETCH_COLUMN);
$takenSet = array_flip($taken);
$freeCells = array_values(array_filter(allCells(),
    fn($c) => !isset($takenSet[$c]) || $c === $it['cell']));

$photos = $hist = [];
if ($id) {
    $st = $db->prepare('SELECT * FROM photos WHERE item_id=? ORDER BY id');
    $st->execute([$id]); $photos = $st->fetchAll();
    $st = $db->prepare('SELECT * FROM log WHERE item_id=? ORDER BY id DESC LIMIT 20');
    $st->execute([$id]); $hist = $st->fetchAll();
}
$svcDebt = max(0, (int)$it['services_sum'] - (int)$it['services_paid']);
$dl = $it['status'] === 'stored' ? daysLeft($it['due_at'] ?: null) : null;

// справочник постоянных клиентов для подстановки
$clients = clientList($db);
$AC = [];
foreach ($clients as $c) {
    $AC[] = [
        'k' => $c['key'], 'n' => $c['name'], 'p' => $c['phone'], 'd' => $c['digits'],
        'c' => (int)$c['n'], 'w' => (int)$c['now'], 'l' => ruDate($c['last']),
        's' => mb_strtolower(str_replace('ё', 'е', $c['name'] . ' ' . $c['digits']), 'UTF-8'),
    ];
}
// история текущего клиента
$myKey = $it['client'] ? clientKey($it['phone_digits'] ?? '', trim($it['client'])) : '';
$mine  = [];
if (!$new && $myKey) {
    foreach (clientItems($db, $myKey) as $r) if ((int)$r['id'] !== $id) $mine[] = $r;
}
?>
<?php head($new ? 'Приём комплекта' : $it['client'], $new ? 'add' : 'list'); ?>

<p style="margin:0 0 10px"><a class="small muted" href="index.php" style="text-decoration:none">← К списку</a></p>
<h1><?= $new ? 'Приём комплекта' : e($it['client']) ?></h1>
<?php if ($msg): ?><div class="flash"><?= e($msg) ?></div><?php endif; ?>
<?php if ($phErr): ?>
  <div class="flash" style="background:rgba(216,65,12,.1);border-color:var(--sig);color:#8a2c06">
    Не удалось добавить: <?= e($phErr) ?>
  </div>
<?php endif; ?>

<?php if (!$new): ?>
  <div class="card">
    <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:12px">
      <span class="tag mono" style="background:var(--paper3);border-color:var(--rule);color:var(--ink2)">№ <?= e(fullNum($it)) ?></span>
      <span class="tag <?= kindCls($it['kind']) ?>"><?= e(kindName($it['kind'])) ?></span>
      <?php if ($it['cell']): ?><span class="tag c mono"><?= e($it['cell']) ?></span><?php endif; ?>
      <?php if ($it['status']==='deleted'): ?>
        <span class="tag debt">в корзине</span>
      <?php elseif ($it['status']==='issued'): ?>
        <span class="tag out">выдано <?= e(ruDate($it['issued_at'])) ?></span>
      <?php elseif ($dl !== null && $dl < 0): ?>
        <span class="tag due">срок истёк <?= abs($dl) ?> дн. назад</span>
      <?php elseif ($dl !== null && $dl <= 30): ?>
        <span class="tag due">срок через <?= $dl ?> дн.</span>
      <?php endif; ?>
      <?php if ($svcDebt > 0): ?><span class="tag debt">услуги не оплачены: <?= number_format($svcDebt,0,',',' ') ?> ₽</span><?php endif; ?>
    </div>
    <dl class="kv">
      <?php if ($it['phone_digits']): ?>
        <dt>Телефон</dt><dd><a class="mono" style="font-size:1.05rem;font-weight:700;color:var(--sig);text-decoration:none"
          href="tel:+<?= e($it['phone_digits']) ?>"><?= e($it['phone']) ?></a></dd>
      <?php endif; ?>
      <dt>Что храним</dt><dd><?= e($it['descr'] ?: '—') ?></dd>
      <dt>Принято</dt><dd><?= e(ruDate($it['accepted_at'])) ?> · до <?= e(ruDate($it['due_at'])) ?></dd>
      <dt>Оплачено</dt><dd><?= money($it['paid']) ?></dd>
      <?php if ($it['services']): ?><dt>Доп. услуги</dt><dd><?= e($it['services']) ?></dd><?php endif; ?>
      <?php if ($it['note']): ?><dt>Комментарий</dt><dd><?= e($it['note']) ?></dd><?php endif; ?>
    </dl>
    <div class="btns" style="margin-top:14px">
      <?php if ($it['status'] === 'stored'): ?>
        <form method="post" style="flex:1;min-width:150px" onsubmit="return confirm('Выдать комплект клиенту? Ячейка освободится.')">
          <input type="hidden" name="act" value="issue">
          <button class="btn s" type="submit">Выдать клиенту</button>
        </form>
        <a class="btn g" style="flex:1;min-width:150px" href="cell.php?move=<?= $id ?>"><?=
          $it['cell'] ? 'Переместить' : 'Положить в ячейку' ?></a>
      <?php elseif ($it['status'] === 'issued'): ?>
        <form method="post" style="flex:1;min-width:150px">
          <input type="hidden" name="act" value="return">
          <button class="btn g" type="submit">Вернуть на хранение</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
<input type="hidden" name="act" value="save">
<?php if ($new): ?><input type="hidden" name="isnew" value="1"><?php endif; ?>

<div class="card">
  <h3><?= $new ? 'Данные комплекта' : 'Редактирование' ?></h3>
  <div class="f2">
    <div class="f"><label for="num">№ по порядку <span class="hint" id="fullnum"><?= e(fullNum($it)) ?></span></label>
      <input id="num" name="num" type="number" inputmode="numeric" class="mono" value="<?= (int)$it['num'] ?>"></div>
    <div class="f"><label for="kind">Что сдаёт</label>
      <select id="kind" name="kind">
        <option value="tires" <?= $it['kind']==='tires'?'selected':'' ?>>Шины без дисков</option>
        <option value="assembled" <?= $it['kind']==='assembled'?'selected':'' ?>>Колёса в сборе</option>
        <option value="preseason" <?= $it['kind']==='preseason'?'selected':'' ?>>Новые шины до сезона</option>
      </select></div>
  </div>
  <div class="f ac-wrap">
    <label for="client">ФИО клиента</label>
    <input id="client" name="client" value="<?= e($it['client']) ?>" required autocomplete="off"
           placeholder="начните вводить — подставим постоянного">
    <div class="ac" id="ac-client" hidden></div>
  </div>
  <div class="f ac-wrap">
    <label for="phone">Телефон</label>
    <input id="phone" name="phone" type="tel" inputmode="tel" value="<?= e($it['phone']) ?>"
           placeholder="+7 (___) ___-__-__" autocomplete="off">
    <div class="ac" id="ac-phone" hidden></div>
  </div>
  <p class="small muted" id="acnote" style="margin:-6px 0 14px"><?php
    $nc = count($AC); echo 'В базе ' . $nc . ' ' . ($nc % 10 === 1 && $nc % 100 !== 11 ? 'клиент' :
      ($nc % 10 >= 2 && $nc % 10 <= 4 && ($nc % 100 < 10 || $nc % 100 >= 20) ? 'клиента' : 'клиентов'));
  ?>. Введите три буквы фамилии или четыре цифры телефона — данные подставятся сами.</p>
  <div class="f"><label for="descr">Что храним</label>
    <input id="descr" name="descr" value="<?= e($it['descr']) ?>" autocomplete="off"
           placeholder="205/55 R16 Nokian Hakkapeliitta 9, шип + диски"></div>
  <div class="f2">
    <div class="f"><label for="size">Размер <span style="text-transform:none">(сам)</span></label>
      <input id="size" name="size" class="mono" value="<?= e($it['size']) ?>"></div>
    <div class="f"><label for="qty">Штук</label>
      <input id="qty" name="qty" type="number" min="1" max="8" value="<?= (int)$it['qty'] ?>"></div>
  </div>
</div>

<div class="card">
  <h3>Ячейка</h3>
  <div class="f">
    <label for="cell">Адрес на складе</label>
    <input id="cell" name="cell" class="mono" list="cells" value="<?= e($it['cell']) ?>"
           placeholder="3-08-2" inputmode="numeric" autocomplete="off">
    <datalist id="cells">
      <?php foreach ($freeCells as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
    </datalist>
    <p id="cellhint" class="small" style="margin:7px 0 0;min-height:1.3em;color:var(--ink3)"></p>
  </div>
  <p class="small muted" style="margin:0">
    Свободно <?= count($freeCells) ?> ячеек. Формат: ряд — место — ярус,
    например <span class="mono">3-08-2</span>: третий ряд, восьмое место от ворот, второй ярус.
    <?php if ($new): ?>
      Проще <a href="map.php?pick=1" id="pickcell">выбрать на карте склада</a> —
      вернётесь сюда с готовым адресом, введённое сохранится.
    <?php else: ?>
      Проще выбрать <a href="cell.php?move=<?= $id ?>">на карте склада</a>.
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <h3>Сроки и деньги</h3>
  <div class="f2">
    <div class="f"><label for="accepted_at">Принято</label>
      <input id="accepted_at" name="accepted_at" type="date" value="<?= e($it['accepted_at']) ?>"></div>
    <div class="f"><label for="due_at">Хранится до</label>
      <input id="due_at" name="due_at" type="date" value="<?= e($it['due_at']) ?>"></div>
  </div>
  <div class="f2">
    <div class="f"><label for="paid">Оплачено за хранение, ₽</label>
      <input id="paid" name="paid" type="number" inputmode="numeric" value="<?= (int)$it['paid'] ?>"></div>
    <div class="f"><label for="season">Сезон <span class="hint">задаёт нумерацию</span></label>
      <input id="season" name="season" value="<?= e($it['season']) ?>" list="seasons" autocomplete="off">
      <datalist id="seasons">
        <?php foreach (array_keys(seasonCounters($db)) as $s): ?>
          <option value="<?= e($s) ?>"></option>
        <?php endforeach; ?>
      </datalist></div>
  </div>
  <div class="f"><label for="services">Доп. услуги — что нужно сделать</label>
    <input id="services" name="services" value="<?= e($it['services']) ?>" autocomplete="off"
           placeholder="отбалансировать, дошиповать, правка дисков…"></div>
  <div class="f2">
    <div class="f"><label for="services_sum">Стоимость услуг, ₽</label>
      <input id="services_sum" name="services_sum" type="number" inputmode="numeric" value="<?= (int)$it['services_sum'] ?>"></div>
    <div class="f"><label for="services_paid">Из них оплачено, ₽</label>
      <input id="services_paid" name="services_paid" type="number" inputmode="numeric" value="<?= (int)$it['services_paid'] ?>"></div>
  </div>
  <div class="f" style="margin-bottom:0"><label for="note">Комментарий</label>
    <textarea id="note" name="note" placeholder="Что важно помнить об этом комплекте"><?= e($it['note']) ?></textarea></div>
</div>

<?php if ($new): ?>
<div class="card">
  <h3>Фотографии</h3>
  <div class="f">
    <label for="pstage">Этап съёмки</label>
    <select id="pstage" name="pstage">
      <option value="accept">При приёме — состояние «как привезли»</option>
      <option value="wash">После мойки — чистые диски крупно</option>
    </select>
  </div>
  <div class="f" style="margin-bottom:0">
    <label for="photos">Снимки</label>
    <input id="photos" name="photos[]" type="file" accept="image/*" multiple>
    <p class="small muted" style="margin:8px 0 0">
      Прикрепятся, когда нажмёте «Принять на хранение». После этого снимать
      можно будет по одному кадру, каждый сохраняется сразу.
    </p>
  </div>
</div>
<?php endif; ?>

<div class="btns" style="margin-bottom:16px">
  <button class="btn" type="submit"><?= $new ? 'Принять на хранение' : 'Сохранить' ?></button>
</div>
</form>

<?php if (!$new): ?>
  <?php
    $pAcc = $pWash = [];
    foreach ($photos as $p) { if (($p['stage'] ?? 'accept') === 'wash') $pWash[] = $p; else $pAcc[] = $p; }
  ?>
  <div class="card" id="foto">
    <h3>Фотографии</h3>
    <?php foreach ([['accept', 'При приёме', $pAcc], ['wash', 'После мойки', $pWash]] as [$sk, $sname, $set]): ?>
      <?php if ($set): ?>
        <p class="small" style="margin:0 0 6px;color:var(--ink3)">
          <span class="pstage <?= $sk ?>"><?= e($sname) ?></span> · <?= count($set) ?></p>
        <div class="gal">
          <?php foreach ($set as $p): ?>
            <div class="gi">
              <?php if (!photoExists($p['file'])): ?>
                <div class="gone" title="<?= e($p['file']) ?>">файл<br>не найден</div>
              <?php else: ?>
                <a href="photo.php?f=<?= e($p['file']) ?>" target="_blank" rel="noopener">
                  <img src="photo.php?f=<?= e($p['file']) ?>" alt="фото комплекта" loading="lazy">
                </a>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$photos): ?>
      <p class="small muted" style="margin:-4px 0 12px">
        Снимков пока нет. Снимайте при приёме, после мойки и при выдаче —
        это закрывает споры о царапинах.</p>
    <?php endif; ?>

    <?php if (!is_dir(PHOTO_DIR) || !is_writable(PHOTO_DIR)): ?>
      <div class="flash" style="background:rgba(216,65,12,.1);border-color:var(--sig);color:#8a2c06">
        Папка <span class="mono">uploads</span> закрыта на запись — снимки сохраняться не будут.
        Поставьте ей права <b>775</b> в файловом менеджере хостинга.
      </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="pf">
      <input type="hidden" name="act" value="addphoto">
      <div class="f">
        <label for="pstage">Этап съёмки</label>
        <select id="pstage" name="pstage">
          <option value="accept">При приёме — состояние «как привезли»</option>
          <option value="wash" <?= $pAcc && !$pWash ? 'selected' : '' ?>>После мойки — чистые диски крупно</option>
        </select>
      </div>
      <div class="btns">
        <label class="btn s pick">Снять камерой
          <input type="file" name="photos[]" accept="image/*" capture="environment"></label>
        <label class="btn g pick">Выбрать из галереи
          <input type="file" name="photos[]" accept="image/*" multiple></label>
      </div>
      <p class="small muted" id="pfhint" style="margin:10px 0 0">
        Каждый кадр сохраняется сразу — можно снимать подряд, ничего больше нажимать не нужно.
        Клиент видит оба набора по коду из акта. Из интернета папка закрыта.
      </p>
    </form>
  </div>

  <?php $tok = ensureToken($db, $id); $link = shareUrl($tok); ?>
  <div class="card">
    <h3>Документы</h3>
    <p class="small muted" style="margin:-6px 0 12px">
      Открываются готовыми к печати на А4. Данные подставляются из карточки:
      что храним, размер, ячейка, сроки, суммы.</p>
    <div class="btns">
      <a class="btn" href="print.php?id=<?= $id ?>&doc=all" target="_blank" rel="noopener">Договор + акт</a>
      <a class="btn g" href="print.php?id=<?= $id ?>&doc=akt" target="_blank" rel="noopener">Только акт</a>
      <a class="btn g" href="print.php?id=<?= $id ?>&doc=vozvrat" target="_blank" rel="noopener">Акт возврата</a>
    </div>
    <div class="btns" style="margin-top:8px">
      <a class="btn s" href="print.php?id=<?= $id ?>&doc=birki" target="_blank" rel="noopener">Бирки на колёса</a>
    </div>
    <p class="small muted" style="margin:10px 0 0">
      Бирки — лист А4 на четыре этикетки, по одной на колесо. Крупный номер виден
      с прохода, сбоку ФИО и телефон, внизу QR: отсканировали — открылась карточка.
      Разрежьте по пунктиру.
    </p>
  </div>

  <div class="card">
    <h3>Фотоотчёт для клиента</h3>
    <p class="small muted" style="margin:-6px 0 10px">
      Код печатается в акте. По нему клиент открывает свои снимки — и те, что сделаны
      при приёме, и те, что появятся после мойки. Больше на странице ничего нет.</p>
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:12px">
      <div id="qrbox" class="qrbox"></div>
      <div>
        <span class="tokbox mono"><?= e($tok) ?></span>
        <p class="mono small" style="color:var(--ink2);word-break:break-all;margin:7px 0 0"><?= e(str_replace('https://', '', $link)) ?></p>
      </div>
    </div>
    <script src="qr.js"></script>
    <script>document.getElementById('qrbox').innerHTML =
      qrSvg(<?= json_encode($link, JSON_UNESCAPED_SLASHES) ?>, 30);</script>

    <?php
      $msgTxt = 'Шинный отель IVANOR. Фотографии ваших '
        . mb_strtolower(kindName($it['kind']), 'UTF-8')
        . ' готовы: ' . $link . ' Код ' . $tok . '.';
      $msgFull = $msgTxt . ' Если увидите что-то не так — сообщите в течение '
        . PHOTO_CLAIM_DAYS . ' рабочих дней. Тел. ' . ORG_PHONE . '.';
      $dig = $it['phone_digits'];
    ?>
    <p class="mn" style="margin:0 0 7px">Отправить клиенту</p>
    <div class="btns">
      <?php if ($dig): ?>
        <a class="btn s" href="sms:+<?= e($dig) ?>&body=<?= rawurlencode($msgTxt) ?>">СМС</a>
      <?php endif; ?>
      <a class="btn g" target="_blank" rel="noopener"
         href="https://t.me/share/url?url=<?= rawurlencode($link) ?>&text=<?= rawurlencode($msgFull) ?>">Telegram</a>
      <?php if ($dig): ?>
        <a class="btn g" target="_blank" rel="noopener"
           href="https://wa.me/<?= e($dig) ?>?text=<?= rawurlencode($msgFull) ?>">WhatsApp</a>
      <?php endif; ?>
      <a class="btn g" href="mailto:?subject=<?= rawurlencode('Фотоотчёт по хранению № ' . fullNum($it)) ?>&body=<?= rawurlencode($msgFull) ?>">Почтой</a>
    </div>
    <div class="btns" style="margin-top:8px">
      <button class="btn g sm" type="button" id="copylink" data-l="<?= e($msgFull) ?>">Скопировать текст</button>
      <a class="btn g sm" href="<?= e($link) ?>" target="_blank" rel="noopener">Открыть как клиент</a>
    </div>
    <p class="small muted" style="margin:12px 0 0">
      СМС доходит всем и не зависит от мессенджера — самый надёжный вариант.
      Telegram открывает выбор чата. Если клиент ничего не получил, код из акта
      работает всегда: <?= e(ORG_SITE) ?> → «Мои фото».
    </p>
  </div>

  <script>
  (function(){
    const b = document.getElementById('copylink');
    if (!b) return;
    b.addEventListener('click', async () => {
      const t = b.dataset.l;
      try { await navigator.clipboard.writeText(t); }
      catch(e) {
        const ta = document.createElement('textarea');
        ta.value = t; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(e2) {}
        ta.remove();
      }
      const old = b.textContent; b.textContent = 'Скопировано';
      setTimeout(() => b.textContent = old, 1600);
    });
  })();
  </script>
<?php endif; ?>

<?php if (!$new && $photos): ?>
  <div class="card">
    <h3>Управление снимками</h3>
    <p class="small muted" style="margin:-6px 0 10px">
      Можно переставить этап, если сняли не в ту вкладку, или удалить кадр.</p>
    <?php foreach ($photos as $p): $st0 = ($p['stage'] ?? 'accept') === 'wash' ? 'wash' : 'accept'; ?>
      <div class="prow">
        <span class="pstage <?= $st0 ?>"><?= $st0 === 'wash' ? 'после мойки' : 'при приёме' ?></span>
        <span class="mono small muted" style="flex:1"><?= e(substr((string)$p['at'], 0, 16)) ?></span>
        <form method="post" style="flex:none">
          <input type="hidden" name="act" value="stage">
          <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
          <input type="hidden" name="to" value="<?= $st0 === 'wash' ? 'accept' : 'wash' ?>">
          <button class="btn g sm" type="submit">→ <?= $st0 === 'wash' ? 'при приёме' : 'после мойки' ?></button>
        </form>
        <form method="post" style="flex:none" onsubmit="return confirm('Удалить снимок?')">
          <input type="hidden" name="act" value="delphoto">
          <input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
          <button class="btn d sm" type="submit">✕</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$new && $mine): ?>
  <div class="card">
    <h3>Этот клиент у нас <?= count($mine) + 1 ?>-й раз</h3>
    <p class="small muted" style="margin:-6px 0 10px">Другие комплекты <?= e($it['client']) ?>.</p>
    <?php foreach (array_slice($mine, 0, 6) as $m): ?>
      <a class="hrow" href="item.php?id=<?= (int)$m['id'] ?>">
        <span class="hn mono">№<?= (int)$m['num'] ?></span>
        <span class="hd"><?= e($m['descr'] ?: kindName($m['kind'])) ?></span>
        <span class="hs mono"><?= e(ruDate($m['accepted_at'])) ?></span>
        <span class="hb <?= $m['status']==='stored'?'in':'out' ?>">
          <?= $m['status'] === 'stored' ? ($m['cell'] ?: 'на складе') : 'выдано' ?></span>
      </a>
    <?php endforeach; ?>
    <?php if (count($mine) > 6): ?>
      <p class="small muted" style="margin:8px 0 0">И ещё <?= count($mine) - 6 ?>.</p>
    <?php endif; ?>
    <a class="btn g sm" style="margin-top:10px" href="client.php?k=<?= urlencode($myKey) ?>">Вся история клиента</a>
  </div>
<?php endif; ?>

<?php if (!$new): ?>
  <?php if ($hist): ?>
  <div class="card">
    <h3>История</h3>
    <?php foreach ($hist as $h): ?>
      <div class="small" style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid var(--rule2)">
        <span class="mono muted" style="flex:none;font-size:.72rem"><?= e(substr($h['at'],0,16)) ?></span>
        <span><?= e($h['details'] ?: $h['action']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($it['status'] === 'deleted'): ?>
    <div class="card" style="border-color:var(--sig)">
      <h3>Запись в корзине</h3>
      <p class="small muted" style="margin:-6px 0 12px">
        Удалена <?= e(substr((string)$it['deleted_at'], 0, 16)) ?>. Фотографии и история целы.</p>
      <div class="btns">
        <form method="post" style="flex:1;min-width:150px">
          <input type="hidden" name="act" value="undelete">
          <button class="btn s" type="submit">Восстановить на хранение</button>
        </form>
        <form method="post" style="flex:1;min-width:150px"
              onsubmit="return confirm('Стереть запись и все её фотографии навсегда? Отменить будет нельзя.')">
          <input type="hidden" name="act" value="purge">
          <button class="btn d" type="submit">Стереть навсегда</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <form method="post" style="margin-bottom:24px"
          onsubmit="return confirm('Перенести запись в корзину? Оттуда её можно будет вернуть.')">
      <input type="hidden" name="act" value="delete">
      <button class="btn d sm" type="submit">Удалить в корзину</button>
    </form>
  <?php endif; ?>
<?php endif; ?>

<style>
.gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:7px;margin-bottom:14px}
.gi img{width:100%;aspect-ratio:1/1;object-fit:cover;border:1.4px solid var(--rule);
  border-radius:3px;display:block;background:var(--paper3)}
.f input[type=file]{padding:.55em;background:var(--paper);font-size:.9rem}
.pstage{font-family:ui-monospace,monospace;font-size:.62rem;padding:.18em .45em;border-radius:2px;
  letter-spacing:.05em}
.pstage.accept{color:var(--cold);background:var(--cold-t)}
.pstage.wash{color:var(--sig);background:var(--sig-t)}
.gal{margin-bottom:12px}
.gone{width:100%;aspect-ratio:1/1;border:1.4px dashed var(--sig);border-radius:3px;
  display:flex;align-items:center;justify-content:center;text-align:center;
  font-size:.7rem;color:var(--sig);background:rgba(216,65,12,.06);line-height:1.3}
.btn.pick{position:relative;overflow:hidden;cursor:pointer}
.btn.pick input[type=file]{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}
.btn.pick.busy{opacity:.55;pointer-events:none}
.prow{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--rule2)}
.prow:last-of-type{border-bottom:0}
.qrbox{flex:none;line-height:0;background:#fff;padding:6px;border:1.4px solid var(--rule);border-radius:3px}
.qrbox svg{display:block;width:112px;height:112px}
.tokbox{display:inline-block;font-size:1.35rem;font-weight:700;letter-spacing:.24em;
  border:1.6px solid var(--ink);border-radius:3px;padding:.28em .7em;background:var(--paper)}

/* подстановка постоянных клиентов */
.ac-wrap{position:relative}
.ac{position:absolute;z-index:30;left:0;right:0;top:100%;margin-top:2px;background:var(--paper);
  border:1.5px solid var(--ink);border-radius:3px;box-shadow:0 8px 22px rgba(22,22,15,.16);
  max-height:264px;overflow-y:auto}
.ac b.hl{background:rgba(216,65,12,.18);font-weight:700}
.aci{display:flex;align-items:baseline;gap:9px;width:100%;text-align:left;font:inherit;color:var(--ink);
  background:none;border:0;border-bottom:1px solid var(--rule2);padding:8px 10px;cursor:pointer}
.aci:last-child{border-bottom:0}
.aci:hover,.aci.sel{background:var(--sig-t)}
.aci .an{font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.aci .ap{font-family:ui-monospace,monospace;font-size:.78rem;color:var(--ink2);flex:none}
.aci .ax{font-family:ui-monospace,monospace;font-size:.6rem;color:var(--sig);background:var(--sig-t);
  padding:.12em .34em;border-radius:2px;flex:none}
.acmore{padding:7px 10px;font-size:.78rem;color:var(--ink3);background:var(--paper3)}

/* история клиента */
.hrow{display:flex;align-items:baseline;gap:9px;padding:7px 0;border-bottom:1px solid var(--rule2);
  text-decoration:none;color:var(--ink);font-size:.88rem}
.hrow:last-of-type{border-bottom:0}
.hrow .hn{font-size:.74rem;color:var(--ink3);flex:none;width:40px}
.hrow .hd{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hrow .hs{font-size:.74rem;color:var(--ink3);flex:none}
.hrow .hb{font-family:ui-monospace,monospace;font-size:.62rem;padding:.14em .36em;border-radius:2px;flex:none}
.hrow .hb.in{color:var(--sig);background:var(--sig-t)}
.hrow .hb.out{color:var(--ink3);background:var(--paper3)}
</style>

<script>
<?php if ($new): ?>
// уходим на карту, забрав с собой всё, что уже введено в форму
(function(){
  const link = document.getElementById('pickcell');
  if (!link) return;
  const FIELDS = ["num","kind","client","phone","descr","size","qty","accepted_at","due_at","paid","season","services","services_sum","services_paid","note"];
  link.addEventListener('click', ev => {
    ev.preventDefault();
    const p = new URLSearchParams({pick: '1'});
    for (const f of FIELDS) {
      const el = document.querySelector('[name="' + f + '"]');
      if (el && el.value !== '') p.set(f, el.value);
    }
    location.href = 'map.php?' + p.toString();
  });
})();
<?php endif; ?>

// снимок уходит на сервер сразу после выбора — не дожидаясь «Сохранить»
(function(){
  const pf = document.getElementById('pf');
  if (!pf) return;
  const hint = document.getElementById('pfhint');
  pf.querySelectorAll('input[type=file]').forEach(inp => {
    inp.addEventListener('change', () => {
      if (!inp.files || !inp.files.length) return;
      pf.querySelectorAll('.btn.pick').forEach(b => b.classList.add('busy'));
      hint.textContent = 'Загружаю ' + inp.files.length + '…';
      pf.submit();
    });
  });
})();

const descr=document.getElementById('descr'), size=document.getElementById('size');
const kind=document.getElementById('kind'), paid=document.getElementById('paid');
const acc=document.getElementById('accepted_at'), due=document.getElementById('due_at');
const PRICES={assembled:<?= PRICE_ASSEMBLED ?>,tires:<?= PRICE_TIRES ?>,preseason:<?= PRICE_PRESEASON ?>};
const MONTHS={assembled:<?= SEASON_MONTHS ?>,tires:<?= SEASON_MONTHS ?>,preseason:<?= PRESEASON_MONTHS ?>};
const RE=/(\d{3})\s*[\/.\-]\s*(\d{2})\s*[\/.\-]\s*R?\s*(\d{2})/i;
descr.addEventListener('input',()=>{ if(size.dataset.t) return;
  const m=descr.value.match(RE); if(m) size.value=`${m[1]}/${m[2]} R${m[3]}`; });
size.addEventListener('input',()=>size.dataset.t='1');
<?php if ($new): ?>
kind.addEventListener('change',()=>{
  paid.value = PRICES[kind.value];
  // новые шины лежат до начала сезона, а не весь сезон — срок другой
  if (acc.value && !due.dataset.t) {
    const d = new Date(acc.value+'T12:00:00');
    d.setMonth(d.getMonth() + MONTHS[kind.value]);
    due.value = d.toISOString().slice(0,10);
  }
});
<?php endif; ?>
acc.addEventListener('change',()=>{ if(!acc.value||due.dataset.t) return;
  const d=new Date(acc.value+'T12:00:00'); d.setMonth(d.getMonth()+MONTHS[kind.value]);
  due.value=d.toISOString().slice(0,10); });
due.addEventListener('input',()=>due.dataset.t='1');

/* ─── номер и сезон ходят парой ───
   Номер считается внутри сезона, поэтому при смене сезона у новой записи
   подставляется следующий свободный номер уже в нём. Рядом с полем всё время
   видно полное обозначение «12 · ЗИМА 2026» — оно же попадёт на бирку. */
const seas = document.getElementById('season'), numf = document.getElementById('num');
const fullBox = document.getElementById('fullnum');
const NEXT = <?= json_encode(seasonCounters($db), JSON_UNESCAPED_UNICODE) ?>;
function showFull(){
  if (!fullBox) return;
  const s = (seas.value||'').trim(), n = parseInt(numf.value||'0',10)||0;
  fullBox.textContent = s ? '№ ' + n + ' · ' + s : '№ ' + n;
}
<?php if ($new): ?>
seas.addEventListener('change', () => {
  const s = seas.value.trim();
  if (numf.dataset.t !== '1') numf.value = NEXT[s] || 1;   // сезон новый — счёт с единицы
  showFull();
});
numf.addEventListener('input', () => { numf.dataset.t = '1'; showFull(); });
<?php else: ?>
numf.addEventListener('input', showFull);
seas.addEventListener('input', showFull);
<?php endif; ?>
showFull();

/* ─── адрес ячейки читается вслух под полем ───
   Адрес целиком числовой, а «3-08-2» и «2-08-3» одинаково валидны.
   Расшифровка под полем показывает, куда вы на самом деле кладёте,
   ещё до сохранения. */
(function(){
  const cf = document.getElementById('cell'), ch = document.getElementById('cellhint');
  if (!cf || !ch) return;
  const FREE = new Set(<?= json_encode($freeCells, JSON_UNESCAPED_UNICODE) ?>);
  const MAXS = <?= SECTIONS ?>, MAXT = <?= TIERS ?>, MAXR = <?= count(allLines()) ?>;
  const was = <?= json_encode((string)$it['cell']) ?>;
  function read(){
    const v = cf.value.trim();
    if (!v) { ch.textContent = ''; return; }
    const m = v.match(/^(\d{1,2})-(\d{1,2})-(\d{1,2})$/);
    if (!m) { ch.style.color='var(--sig)'; ch.textContent='Формат: ряд-место-ярус, например 3-08-2'; return; }
    const [r, s, t] = [+m[1], +m[2], +m[3]];
    if (r < 1 || r > MAXR || s < 1 || s > MAXS || t < 1 || t > MAXT) {
      ch.style.color = 'var(--sig)';
      ch.textContent = 'Такой ячейки нет: рядов ' + MAXR + ', мест ' + MAXS + ', ярусов ' + MAXT;
      return;
    }
    const code = r + '-' + String(s).padStart(2,'0') + '-' + t;
    const busy = !FREE.has(code) && code !== was;
    ch.style.color = busy ? 'var(--sig)' : 'var(--ink3)';
    ch.textContent = 'Ряд ' + r + ' · место ' + s + ' от ворот · ярус ' + t
                   + (busy ? ' — занята' : '');
  }
  cf.addEventListener('input', read);
  read();
})();

const ph=document.getElementById('phone');
ph.addEventListener('input',()=>{
  let d=ph.value.replace(/\D/g,''); if(!d){ph.value='';return;}
  if(d[0]==='8') d='7'+d.slice(1); if(d[0]!=='7') d='7'+d; d=d.slice(0,11);
  let v='+7'; if(d.length>1)v+=' ('+d.slice(1,4); if(d.length>=5)v+=') '+d.slice(4,7);
  if(d.length>=8)v+='-'+d.slice(7,9); if(d.length>=10)v+='-'+d.slice(9,11);
  ph.value=v;
});

/* ─── подстановка постоянных клиентов ─── */
const CL = <?= json_encode($AC, JSON_UNESCAPED_UNICODE) ?>;
const fio = document.getElementById('client');
const note = document.getElementById('acnote');
const NOTE0 = note.innerHTML;
const nrm = s => (s||'').toLowerCase().replace(/ё/g,'е').trim();
const esc = s => s.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const plu = (n,a,b,c) => { const t=n%10,h=n%100;
  return (t===1&&h!==11)?a:((t>=2&&t<=4&&(h<10||h>=20))?b:c); };

function mark(text, q){
  if (!q) return esc(text);
  const i = nrm(text).indexOf(q);
  if (i < 0) return esc(text);
  return esc(text.slice(0,i)) + '<b class="hl">' + esc(text.slice(i,i+q.length)) + '</b>'
       + esc(text.slice(i+q.length));
}

function pick(c){
  fio.value = c.n;
  if (c.p) ph.value = c.p;
  hideAll();
  note.innerHTML = 'Постоянный клиент: сдавал ' + c.c + ' ' + plu(c.c,'раз','раза','раз')
    + (c.w ? ', сейчас на складе ' + c.w : '') + ', последний раз ' + c.l
    + ' · <a href="client.php?k=' + encodeURIComponent(c.k) + '">вся история</a>';
  note.style.color = 'var(--ink2)';
  (fio.value && !ph.value ? ph : document.getElementById('descr')).focus();
}

function build(box, list, q){
  if (!list.length) { box.hidden = true; return; }
  box.innerHTML = list.slice(0,8).map((c,i) =>
    '<button type="button" class="aci" data-i="' + i + '">'
    + '<span class="an">' + mark(c.n, q) + '</span>'
    + (c.p ? '<span class="ap">' + mark(c.p, q) + '</span>' : '')
    + (c.c > 1 ? '<span class="ax">×' + c.c + '</span>' : '') + '</button>'
  ).join('') + (list.length > 8
    ? '<div class="acmore">и ещё ' + (list.length-8) + ' — уточните запрос</div>' : '');
  box.hidden = false;
  box._list = list;
  box.querySelectorAll('.aci').forEach(b =>
    b.addEventListener('mousedown', ev => { ev.preventDefault(); pick(list[+b.dataset.i]); }));
}
function hideAll(){ document.querySelectorAll('.ac').forEach(b => { b.hidden = true; b._sel = -1; }); }

function wire(input, box, mode){
  input.addEventListener('input', () => {
    if (note.innerHTML !== NOTE0) { note.innerHTML = NOTE0; note.style.color = ''; }
    let list = [];
    if (mode === 'name') {
      const q = nrm(input.value);
      if (q.length >= 2) list = CL.filter(c => c.s.includes(q));
      build(box, list, q);
    } else {
      const d = input.value.replace(/\D/g,'').replace(/^8/,'7');
      const tail = d.length >= 11 ? d.slice(-10) : d.replace(/^7/,'');
      if (tail.length >= 3) list = CL.filter(c => c.d.includes(tail));
      build(box, list, '');
    }
  });
  input.addEventListener('keydown', ev => {
    if (box.hidden) return;
    const items = box.querySelectorAll('.aci');
    if (!items.length) return;
    let s = box._sel ?? -1;
    if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
      ev.preventDefault();
      s = ev.key === 'ArrowDown' ? Math.min(s+1, items.length-1) : Math.max(s-1, 0);
      items.forEach(x => x.classList.remove('sel'));
      items[s].classList.add('sel'); items[s].scrollIntoView({block:'nearest'});
      box._sel = s;
    } else if (ev.key === 'Enter' && s >= 0) {
      ev.preventDefault(); pick(box._list[s]);
    } else if (ev.key === 'Escape') { hideAll(); }
  });
  input.addEventListener('blur', () => setTimeout(() => { box.hidden = true; }, 120));
}
wire(fio, document.getElementById('ac-client'), 'name');
wire(ph,  document.getElementById('ac-phone'),  'phone');
</script>

<?php foot($new ? 'add' : 'list');
