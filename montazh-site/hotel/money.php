<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/layout.php';
requireAuth();

$db = db();

// быстрая отметка прямо из списка
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qid = (int)($_POST['id'] ?? 0);
    $act = $_POST['act'] ?? '';
    if ($qid && $act === 'issue') {
        $db->prepare("UPDATE items SET status='issued', issued_at=?, cell='', updated_at=?
                      WHERE id=?")->execute([date('Y-m-d'), date('Y-m-d H:i:s'), $qid]);
        logit($qid, 'issue', 'выдано (быстрая отметка)');
    } elseif ($qid && $act === 'paid') {
        $db->prepare("UPDATE items SET services_paid=services_sum, updated_at=?
                      WHERE id=?")->execute([date('Y-m-d H:i:s'), $qid]);
        logit($qid, 'pay', 'оплата (быстрая отметка)');
    }
    header('Location: money.php' . ($_POST['back'] ?? '')); exit;
}

$sum = fn(string $q) => (int)$db->query($q)->fetchColumn();

$storedCount = $sum("SELECT COUNT(*) FROM items WHERE status='stored'");
$startPaid   = $sum("SELECT COALESCE(SUM(paid),0)  FROM items WHERE status='stored'");
$svcSum      = $sum("SELECT COALESCE(SUM(services_sum),0)  FROM items WHERE status='stored'");
$svcPaid     = $sum("SELECT COALESCE(SUM(services_paid),0) FROM items WHERE status='stored'");
$archPaid    = $sum("SELECT COALESCE(SUM(paid),0)  FROM items WHERE status='issued'");
$freeStore   = $sum("SELECT COUNT(*) FROM items WHERE status='stored' AND paid=0");
$debtTotal   = max(0, $svcSum - $svcPaid);

// у кого не оплачены услуги
$debtors = $db->query("SELECT *, (services_sum - services_paid) AS debt
    FROM items WHERE status='stored' AND services_sum > services_paid
    ORDER BY debt DESC")->fetchAll();

// просроченные и скоро истекающие
$over = $db->query("SELECT * FROM items WHERE status='stored' AND due_at<>''
    AND date(due_at) < date('now') ORDER BY due_at ASC")->fetchAll();
$soon = $db->query("SELECT * FROM items WHERE status='stored' AND due_at<>''
    AND date(due_at) >= date('now') AND date(due_at) <= date('now','+30 day')
    ORDER BY due_at ASC")->fetchAll();

// по сезонам
$seasons = $db->query("SELECT COALESCE(NULLIF(season,''),'без сезона') s,
    COUNT(*) n, COALESCE(SUM(paid),0) pd,
    SUM(CASE WHEN status='issued' THEN 1 ELSE 0 END) iss
    FROM items GROUP BY s ORDER BY s DESC")->fetchAll();

// по типу
$kinds = $db->query("SELECT kind, COUNT(*) n, COALESCE(SUM(paid),0) p
    FROM items WHERE status='stored' GROUP BY kind")->fetchAll();
?>
<?php head('Деньги', 'money'); ?>

<h1>Деньги</h1>

<div class="stats">
  <div class="stat"><b><?= number_format($startPaid, 0, ',', ' ') ?></b><span>₽ на хранении сейчас</span></div>
  <div class="stat sig"><b><?= number_format($debtTotal, 0, ',', ' ') ?></b><span>₽ услуги не оплачены</span></div>
  <div class="stat"><b><?= number_format($archPaid, 0, ',', ' ') ?></b><span>₽ в архиве</span></div>
  <div class="stat warn"><b><?= count($over) ?></b><span>просрочено</span></div>
</div>

<div class="card">
  <h3>Сводка по текущему хранению</h3>
  <dl class="kv">
    <dt>Комплектов на складе</dt><dd><?= $storedCount ?></dd>
    <dt>Получено за хранение</dt><dd><?= money($startPaid) ?></dd>
    <dt>Средний чек</dt><dd><?= money($storedCount ? round($startPaid / $storedCount) : 0) ?></dd>
    <?php if ($freeStore): ?><dt>Без оплаты</dt><dd><?= $freeStore ?> компл. — свои, по договорённости</dd><?php endif; ?>
    <?php if ($svcSum): ?>
      <dt>Услуги начислено</dt><dd><?= money($svcSum) ?></dd>
      <dt>Услуги оплачено</dt><dd><?= money($svcPaid) ?></dd>
    <?php endif; ?>
    <?php foreach ($kinds as $k): ?>
      <dt><?= e(kindName($k['kind'])) ?></dt>
      <dd><?= (int)$k['n'] ?> шт · <?= money($k['p']) ?></dd>
    <?php endforeach; ?>
  </dl>
</div>

<h2 id="dolgi">Услуги не оплачены<?= $debtors ? ' · ' . count($debtors) : '' ?></h2>
<?php if (!$debtors): ?>
  <p class="empty">Всё оплачено. Хранение вы берёте сразу, а услуги закрыты.</p>
<?php else: ?>
  <div class="list">
    <?php foreach ($debtors as $d): ?>
      <a class="row" href="item.php?id=<?= (int)$d['id'] ?>">
        <div class="l1">
          <span class="nm"><?= e($d['client']) ?></span>
          <span class="mono" style="color:#8a1c1c;font-weight:700"><?= number_format((int)$d['debt'],0,',',' ') ?> ₽</span>
        </div>
        <div class="l2">
          <span class="tag <?= kindCls($d['kind']) ?>"><?= e(kindShort($d['kind'])) ?></span>
          <?php if ($d['size']): ?><span class="sz"><?= e($d['size']) ?></span><?php endif; ?>
          <?php if ($d['phone_digits']): ?>
            <span class="small muted"><?= e($d['phone']) ?></span><?php endif; ?>
          <?php if ($d['cell']): ?><span class="tag c"><?= e($d['cell']) ?></span><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2 id="srok">Просрочено<?= $over ? ' · ' . count($over) : '' ?></h2>
<?php if (!$over): ?>
  <p class="empty">Просроченных нет.</p>
<?php else: ?>
  <p class="small muted" style="margin:-6px 0 10px">
    Срок хранения закончился. Позвоните и договоритесь: забрать или продлить
    по <?= money(PRICE_MONTH) ?> в месяц. Кнопка «Выдано» освобождает ячейку
    и убирает запись из списка.
  </p>
  <div class="list">
    <?php foreach ($over as $s): $dl = daysLeft($s['due_at']); ?>
      <div class="row" style="display:flex;gap:10px;align-items:center">
        <a href="item.php?id=<?= (int)$s['id'] ?>" style="flex:1;min-width:0;text-decoration:none">
          <div class="l1"><span class="nm"><?= e($s['client']) ?></span></div>
          <div class="l2">
            <span class="tag due">просрочен <?= abs($dl) ?> дн.</span>
            <?php if ($s['size']): ?><span class="sz"><?= e($s['size']) ?></span><?php endif; ?>
            <?php if ($s['season']): ?><span class="small muted"><?= e($s['season']) ?></span><?php endif; ?>
            <?php if ($s['cell']): ?><span class="tag c"><?= e($s['cell']) ?></span><?php endif; ?>
          </div>
        </a>
        <?php if ($s['phone_digits']): ?>
          <a class="btn g sm" style="flex:none;padding:.5em .7em"
             href="tel:+<?= e($s['phone_digits']) ?>" title="Позвонить">☎</a>
        <?php endif; ?>
        <form method="post" style="flex:none" onsubmit="return confirm('Отметить как выданный?')">
          <input type="hidden" name="act" value="issue">
          <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <input type="hidden" name="back" value="#srok">
          <button class="btn sm" type="submit" style="width:auto">Выдано</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2>Скоро заканчивается срок<?= $soon ? ' · ' . count($soon) : '' ?></h2>
<?php if (!$soon): ?>
  <p class="empty">В ближайший месяц никто не истекает.</p>
<?php else: ?>
  <div class="list">
    <?php foreach ($soon as $s): $dl = daysLeft($s['due_at']); ?>
      <a class="row" href="item.php?id=<?= (int)$s['id'] ?>">
        <div class="l1">
          <span class="nm"><?= e($s['client']) ?></span>
          <span class="tag due"><?= $dl < 0 ? 'просрочен ' . abs($dl) . ' дн.' : 'через ' . $dl . ' дн.' ?></span>
        </div>
        <div class="l2">
          <span class="small muted">до <?= e(ruDate($s['due_at'])) ?></span>
          <?php if ($s['phone_digits']): ?>
            <a class="small mono" style="color:var(--sig);text-decoration:none"
               href="tel:+<?= e($s['phone_digits']) ?>" onclick="event.stopPropagation()">позвонить</a>
          <?php endif; ?>
          <?php if ($s['cell']): ?><span class="tag c"><?= e($s['cell']) ?></span><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <p class="small muted" style="margin-top:8px">
    Продление после сезона — <?= money(PRICE_MONTH) ?> в месяц за комплект.
    Позвоните заранее: клиент не помнит про срок, а вам нужна ячейка.
  </p>
<?php endif; ?>

<h2>По сезонам</h2>
<div class="card">
  <table style="width:100%;border-collapse:collapse;font-size:.94rem">
    <tr style="border-bottom:1.5px solid var(--rule)">
      <th style="text-align:left;padding:6px 0" class="mn">Сезон</th>
      <th style="text-align:right" class="mn">Компл.</th>
      <th style="text-align:right" class="mn">Выдано</th>
      <th style="text-align:right" class="mn">Получено</th>
    </tr>
    <?php foreach ($seasons as $s): ?>
      <tr style="border-bottom:1px solid var(--rule2)">
        <td style="padding:7px 0"><?= e($s['s']) ?></td>
        <td style="text-align:right" class="mono"><?= (int)$s['n'] ?></td>
        <td style="text-align:right" class="mono"><?= (int)$s['iss'] ?></td>
        <td style="text-align:right" class="mono"><?= number_format((int)$s['pd'],0,',',' ') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php foot('money');
