<?php
/* Фотоотчёт для клиента. Открывается по коду из акта, без пароля.
   Видны только снимки одного комплекта, никаких других данных. */
require_once __DIR__ . '/db.php';

$tok = strtoupper(trim((string)($_GET['t'] ?? $_POST['t'] ?? '')));
$it  = null;

if ($tok !== '' && preg_match('/^[A-Z0-9]{4,10}$/', $tok)) {
    $st = db()->prepare("SELECT * FROM items WHERE share_token=? AND status<>'deleted'");
    $st->execute([$tok]);
    $it = $st->fetch() ?: null;
}

$accept = $wash = [];
if ($it) {
    $ps = db()->prepare('SELECT * FROM photos WHERE item_id=? ORDER BY id');
    $ps->execute([(int)$it['id']]);
    foreach ($ps as $p) {
        // клиенту не показываем битые плитки: если файла нет — записи как будто нет
        if (!photoExists($p['file'])) continue;
        if (($p['stage'] ?? 'accept') === 'wash') $wash[] = $p; else $accept[] = $p;
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#16160F">
<!-- Страница открывается по короткому адресу /s/КОД. Для браузера это «папка» s,
     поэтому без base все относительные ссылки уехали бы в /s/ и картинки
     отдавали бы 404. Base возвращает их в корень приложения. -->
<base href="<?= e(appBase()) ?>">
<title>Фотоотчёт · <?= e(ORG_BRAND) ?></title>
<style>
:root{--paper:#F2EFE8;--paper2:#EAE6DB;--paper3:#E1DCCE;--ink:#16160F;--ink2:#3A382E;
      --ink3:#6E6B5D;--rule:rgba(22,22,15,.16);--sig:#D8410C;--cold:#26586F}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);
  font:16px/1.5 'Golos Text',system-ui,-apple-system,sans-serif}
.top{background:var(--ink);color:var(--paper);padding:13px 16px}
.top b{font-size:1.02rem;letter-spacing:-.02em}
.top span{display:block;font-size:.76rem;color:rgba(242,239,232,.6);margin-top:2px}
.wrap{max-width:760px;margin:0 auto;padding:16px 14px 40px}
h1{font-size:1.25rem;letter-spacing:-.03em;margin:.2em 0 .6em}
h2{font-size:.98rem;margin:1.6em 0 .5em;letter-spacing:-.01em}
.mn{font-family:ui-monospace,monospace;font-size:.66rem;letter-spacing:.13em;
  text-transform:uppercase;color:var(--ink3)}
.card{background:var(--paper2);border:1.5px solid var(--rule);border-radius:3px;
  padding:15px;margin-bottom:14px}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:0;font-size:.94rem}
dt{color:var(--ink3);font-size:.86rem}dd{margin:0}
.gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
.gal a{display:block}
.gal img{width:100%;aspect-ratio:4/3;object-fit:cover;border:1.4px solid var(--rule);
  border-radius:3px;display:block;background:var(--paper3)}
.stamp{font-size:.78rem;color:var(--ink3);margin-top:3px;font-family:ui-monospace,monospace}
.empty{color:var(--ink3);padding:18px 0;font-size:.92rem}
input[type=text]{width:100%;font:inherit;font-size:16px;padding:.8em;text-transform:uppercase;
  border:1.5px solid var(--ink);border-radius:3px;background:#fff;letter-spacing:.2em;
  font-family:ui-monospace,monospace;text-align:center}
button{width:100%;font:inherit;font-weight:600;padding:.85em;border:0;border-radius:3px;
  background:var(--ink);color:var(--paper);cursor:pointer;margin-top:10px}
.err{color:var(--sig);font-size:.9rem;margin:10px 0 0}
.foot{margin-top:26px;padding-top:14px;border-top:1.5px solid var(--rule);
  font-size:.84rem;color:var(--ink3)}
.foot a{color:var(--sig)}
.badge{display:inline-block;font-family:ui-monospace,monospace;font-size:.64rem;
  padding:.2em .5em;border-radius:2px;letter-spacing:.06em}
.badge.a{color:var(--cold);background:rgba(38,88,111,.12)}
.badge.w{color:var(--sig);background:rgba(216,65,12,.12)}
</style>
</head>
<body>

<header class="top">
  <b><?= e(ORG_BRAND) ?></b>
  <span>Фотоотчёт по вашему хранению</span>
</header>

<main class="wrap">
<?php if (!$it): ?>
  <p class="mn">Доступ по коду</p>
  <h1>Введите код из акта</h1>
  <p style="color:var(--ink2);font-size:.94rem">
    Шесть символов в рамке внизу акта приёма-передачи. Регистр не важен.</p>
  <form method="post" action="share.php" class="card">
    <input type="text" name="t" maxlength="10" autocomplete="off" autofocus
           placeholder="XXXXXX" value="<?= e($tok) ?>">
    <button type="submit">Показать фотографии</button>
    <?php if ($tok !== ''): ?><p class="err">Код не найден. Проверьте написание или позвоните нам.</p><?php endif; ?>
  </form>
  <p class="foot">Вопросы — <?= e(ORG_PHONE) ?>, <?= e(ORG_EMAIL) ?></p>

<?php else: ?>
  <p class="mn">Хранение № <?= e(fullNum($it)) ?></p>
  <h1><?= e($it['descr'] ?: kindName($it['kind'])) ?></h1>

  <div class="card">
    <dl>
      <dt>Принято</dt><dd><?= e(ruDate($it['accepted_at'])) ?></dd>
      <dt>Хранится до</dt><dd><?= e(ruDate($it['due_at'])) ?></dd>
      <dt>Что храним</dt><dd><?= e(kindName($it['kind'])) ?>, <?= (int)$it['qty'] ?> шт.</dd>
      <?php if ($it['services']): ?><dt>Услуги</dt><dd><?= e($it['services']) ?></dd><?php endif; ?>
      <?php if ($it['status'] === 'issued'): ?>
        <dt>Выдано</dt><dd><?= e(ruDate($it['issued_at'])) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

  <h2>При приёме <span class="badge a"><?= count($accept) ?></span></h2>
  <?php if ($accept): ?>
    <div class="gal">
      <?php foreach ($accept as $p): ?>
        <div>
          <a href="photo.php?f=<?= e($p['file']) ?>&t=<?= e($it['share_token']) ?>" target="_blank" rel="noopener">
            <img src="photo.php?f=<?= e($p['file']) ?>&t=<?= e($it['share_token']) ?>" alt="состояние при приёме" loading="lazy">
          </a>
          <div class="stamp"><?= e(substr((string)$p['at'], 0, 16)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="empty">Снимки при приёме ещё не выложены. Позвоните нам, если они вам нужны:
       <?= e(ORG_PHONE) ?>.</p>
  <?php endif; ?>

  <h2>После мойки <span class="badge w"><?= count($wash) ?></span></h2>
  <?php if ($wash): ?>
    <div class="gal">
      <?php foreach ($wash as $p): ?>
        <div>
          <a href="photo.php?f=<?= e($p['file']) ?>&t=<?= e($it['share_token']) ?>" target="_blank" rel="noopener">
            <img src="photo.php?f=<?= e($p['file']) ?>&t=<?= e($it['share_token']) ?>" alt="состояние после мойки" loading="lazy">
          </a>
          <div class="stamp"><?= e(substr((string)$p['at'], 0, 16)) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="foot">
      Если на снимках после мойки вы видите повреждение, о котором не знали, —
      сообщите нам в течение <?= PHOTO_CLAIM_DAYS ?> рабочих дней:
      <?= e(ORG_PHONE) ?>, <?= e(ORG_EMAIL) ?>.
      По истечении этого срока состояние считается согласованным.
    </p>
  <?php else: ?>
    <p class="empty">Мойка ещё не выполнена. Как только помоем — снимки появятся здесь,
       и мы пришлём вам сообщение.</p>
  <?php endif; ?>

  <p class="foot">
    <?= e(ORG_SHORT) ?> · <?= e(ORG_ADDR) ?><br>
    <?= e(ORG_PHONE) ?> · <a href="mailto:<?= e(ORG_EMAIL) ?>"><?= e(ORG_EMAIL) ?></a><br>
    Страница доступна только по вашему коду и в поиске не показывается.
  </p>
<?php endif; ?>
</main>
</body>
</html>
