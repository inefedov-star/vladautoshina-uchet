<?php
/* Пульт шиномонтажа IVANOR — серверная часть.
   Хранит смены, расходы и отметку «с помощником» в SQLite.
   Проверка хостинга: откройте api.php?a=selftest в браузере. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

$a = $_GET['a'] ?? '';

/* ---------- самопроверка: без входа, без данных ---------- */
if ($a === 'selftest') {
  $cfgOk = is_file(__DIR__ . '/config.php');
  $cfg = $cfgOk ? require __DIR__ . '/config.php' : [];
  $dir = dirname($cfg['db_path'] ?? (__DIR__ . '/data/x'));
  $weak = $cfgOk && (in_array($cfg['owner_pin'] ?? '', ['000000','111111','0000','1234'], true) || in_array($cfg['master_pin'] ?? '', ['000000','111111','0000','1234'], true) || ($cfg['owner_pin'] ?? '') === ($cfg['master_pin'] ?? ''));
  // база не должна открываться из браузера: пробуем скачать пробный файл из папки data
  $public = null;
  if (is_dir($dir) && is_writable($dir) && strpos(realpath($dir) ?: '', realpath(__DIR__)) === 0) {
    $probe = 'probe-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents("$dir/$probe", 'probe');
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) ? 'https' : 'http';
    $rel = trim(str_replace(realpath(__DIR__), '', realpath($dir)), '/');
    $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . "/$rel/$probe";
    $got = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false]]));
    $public = ($got === 'probe');
    @unlink("$dir/$probe");
  }
  out([
    'data_dir_public' => $public,
    'php' => PHP_VERSION,
    'sqlite_version' => extension_loaded('pdo_sqlite') ? (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn() : null,
    'php_ok' => version_compare(PHP_VERSION, '7.4', '>='),
    'sqlite' => extension_loaded('pdo_sqlite'),
    'config' => $cfgOk,
    'pins_changed' => $cfgOk && !$weak,
    'data_dir_writable' => is_dir($dir) && is_writable($dir),
    'https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
  ]);
}

if (!is_file(__DIR__ . '/config.php')) fail('Нет файла config.php — скопируйте config.sample.php в config.php и поменяйте PIN-коды.', 500);
$cfg = require __DIR__ . '/config.php';

/* ---------- сессия ---------- */
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('ivanor_montazh');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
ini_set('session.gc_maxlifetime', (string)(60 * 60 * 24 * 30));
session_start();
$role = $_SESSION['role'] ?? null;

/* ---------- база ---------- */
function db(array $cfg): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . $cfg['db_path']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA journal_mode=WAL');
  $pdo->exec('CREATE TABLE IF NOT EXISTS orders (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, t INTEGER NOT NULL, body TEXT NOT NULL, author TEXT, updated INTEGER)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS orders_day ON orders(day)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS days (day TEXT PRIMARY KEY, helper INTEGER NOT NULL DEFAULT 0)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS expenses (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, sum INTEGER NOT NULL, kind TEXT NOT NULL, cat TEXT, note TEXT, t INTEGER)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS log (t INTEGER, role TEXT, action TEXT, ref TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, tm TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'planned\', body TEXT NOT NULL, updated INTEGER)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS bookings_day ON bookings(day)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS spins (id INTEGER PRIMARY KEY AUTOINCREMENT, month TEXT NOT NULL, t INTEGER NOT NULL, prize TEXT NOT NULL, given INTEGER NOT NULL DEFAULT 0)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
  // ---- склад: товары, документы, движения. Остаток = сумма движений проведённых документов
  $pdo->exec('CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY AUTOINCREMENT, ext_id TEXT UNIQUE, name TEXT NOT NULL, grp TEXT, cat TEXT, kind TEXT, attrs TEXT, barcode TEXT, article TEXT, unit TEXT, price REAL, supplier TEXT, country TEXT, archived INTEGER NOT NULL DEFAULT 0, created INTEGER, updated INTEGER)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS products_kind ON products(kind)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS docs (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, num TEXT, day TEXT NOT NULL, partner TEXT, note TEXT, status TEXT NOT NULL DEFAULT \'draft\', author TEXT, created INTEGER, posted_at INTEGER, cancelled_at INTEGER, cancelled_by TEXT)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS moves (id INTEGER PRIMARY KEY AUTOINCREMENT, doc_id INTEGER NOT NULL, product_id INTEGER NOT NULL, wh TEXT NOT NULL DEFAULT \'shop\', qty REAL NOT NULL, cost REAL NOT NULL DEFAULT 0, price REAL)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS moves_prod ON moves(product_id)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS moves_doc ON moves(doc_id)');
  $cols = array_column($pdo->query('PRAGMA table_info(docs)')->fetchAll(), 'name');
  if (!in_array('wh', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN wh TEXT NOT NULL DEFAULT 'shop'");
  if (!in_array('cancel_reason', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN cancel_reason TEXT");
  // продажа/возврат: телефон покупателя, способ оплаты, ссылка возврата на продажу; выдача с ответхранения: % магазину
  if (!in_array('phone', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN phone TEXT NOT NULL DEFAULT ''");
  if (!in_array('pay', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN pay TEXT NOT NULL DEFAULT ''");
  if (!in_array('base_id', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN base_id INTEGER");
  if (!in_array('fee', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN fee REAL NOT NULL DEFAULT 0");
  // приход: сколько оплачено бонусами поставщика (уменьшает закупочную цену строк)
  if (!in_array('bonus', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN bonus REAL NOT NULL DEFAULT 0");
  $mcols = array_column($pdo->query('PRAGMA table_info(moves)')->fetchAll(), 'name');
  // цена по прайсу на момент продажи — чтобы видеть скидку
  if (!in_array('list', $mcols, true)) $pdo->exec("ALTER TABLE moves ADD COLUMN list REAL");
  $pdo->exec('CREATE TABLE IF NOT EXISTS weather (day TEXT PRIMARY KEY, tmax REAL, tmin REAL, prec REAL, code INTEGER, updated INTEGER)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS clients (phone TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', model TEXT NOT NULL DEFAULT \'\', updated INTEGER)');
  return $pdo;
}
function logit(array $cfg, ?string $role, string $action, string $ref = ''): void {
  db($cfg)->prepare('INSERT INTO log VALUES (?,?,?,?)')->execute([time(), $role, $action, $ref]);
}
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '', true); return is_array($j) ? $j : []; }
function isDay($s): bool { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1; }
function needRole(?string $role, array $allowed): void { if (!$role) fail('Нужно войти', 401); if (!in_array($role, $allowed, true)) fail('Недостаточно прав', 403); }
function digits($s): string { return preg_replace('/\D/', '', (string)$s); }
// телефон — основа клиента: храним как 7XXXXXXXXXX
function phoneKey($s): string { $d = digits($s); if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1); if (strlen($d) === 10) $d = '7' . $d; return (strlen($d) === 11 && $d[0] === '7') ? $d : ''; }
function touchClient(array $cfg, string $key, string $name, string $model): void {
  if ($key === '') return;
  $st = db($cfg)->prepare('SELECT name, model FROM clients WHERE phone = ?'); $st->execute([$key]); $c = $st->fetch();
  if (!$c) { db($cfg)->prepare('INSERT INTO clients (phone, name, model, updated) VALUES (?,?,?,?)')->execute([$key, $name, $model, time()]); return; }
  // действует последняя правка: новое имя или марка заменяют старые; пустое поле ничего не стирает
  $n2 = $name !== '' ? $name : $c['name']; $m2 = $model !== '' ? $model : $c['model'];
  if ($n2 !== $c['name'] || $m2 !== $c['model'])
    db($cfg)->prepare('UPDATE clients SET name = ?, model = ?, updated = ? WHERE phone = ?')->execute([$n2, $m2, time(), $key]);
}
function withClients(array $cfg, array $rows): array {
  $keys = array_values(array_unique(array_filter(array_map(fn($r) => phoneKey($r['phone'] ?? ''), $rows))));
  if (!$keys) return $rows;
  $map = [];
  foreach (array_chunk($keys, 400) as $ch) { $st = db($cfg)->prepare('SELECT phone, name, model FROM clients WHERE phone IN (' . implode(',', array_fill(0, count($ch), '?')) . ')'); $st->execute($ch); foreach ($st->fetchAll() as $c) $map[$c['phone']] = $c; }
  foreach ($rows as &$r) { $k = phoneKey($r['phone'] ?? ''); if ($k && isset($map[$k])) { $r['cname'] = $map[$k]['name']; $r['cmodel'] = $map[$k]['model']; } }
  return $rows;
}
function clean(array $b, array $keys): array { $o = []; foreach ($keys as $k) $o[$k] = mb_substr(trim((string)($b[$k] ?? '')), 0, 200); return $o; }
function bookingRows(array $cfg, string $where, array $args): array {
  $st = db($cfg)->prepare("SELECT id, day, tm, status, body FROM bookings WHERE $where ORDER BY day, tm"); $st->execute($args);
  return array_map(fn($r) => ['id' => (int)$r['id'], 'day' => $r['day'], 'tm' => $r['tm'], 'status' => $r['status']] + json_decode($r['body'], true), $st->fetchAll());
}
const DEFAULT_PRIZES = ['Кофе от директора', 'Улыбка от Алексея', '+100 ₽ сверху', 'Шоколадка', 'Обед за счёт заведения', 'Ещё одно вращение', 'Почётный значок недели', 'Пицца на смену'];
function prizes(array $cfg): array { $v = db($cfg)->query("SELECT v FROM settings WHERE k = 'prizes'")->fetchColumn(); $p = $v ? json_decode($v, true) : null; return is_array($p) && count($p) >= 2 ? $p : DEFAULT_PRIZES; }
function masterBase(array $cfg, string $month): int {
  $st = db($cfg)->prepare('SELECT o.body, IFNULL(d.helper,0) helper FROM orders o LEFT JOIN days d ON d.day = o.day WHERE o.day LIKE ?'); $st->execute([$month . '-%']);
  $sum = 0; foreach ($st->fetchAll() as $r) { $b = json_decode($r['body'], true); $sum += (int)($b['list'] ?? 0) - ($r['helper'] ? (int)($b['h'] ?? 0) : 0); } return $sum;
}
function spinState(array $cfg, string $month): array {
  $earned = intdiv(max(0, masterBase($cfg, $month)), 10000);
  $st = db($cfg)->prepare('SELECT id, t, prize, given FROM spins WHERE month = ? ORDER BY t DESC'); $st->execute([$month]);
  $won = array_map(fn($r) => ['id' => (int)$r['id'], 't' => (int)$r['t'], 'prize' => $r['prize'], 'given' => (bool)$r['given']], $st->fetchAll());
  $bonusSpins = count(array_filter($won, fn($w) => $w['prize'] === 'Ещё одно вращение'));
  return ['earned' => $earned + $bonusSpins, 'used' => count($won), 'left' => max(0, $earned + $bonusSpins - count($won)), 'won' => $won, 'prizes' => prizes($cfg)];
}
function couponValue(array $cfg): int { $v = db($cfg)->query("SELECT v FROM settings WHERE k = 'coupon'")->fetchColumn(); return $v !== false ? (int)$v : 2600; }
/* остатки и средняя себестоимость по проведённым документам */
function stockMap(array $cfg, string $wh = 'shop'): array {
  $st = db($cfg)->prepare("SELECT m.product_id pid, SUM(m.qty) q, SUM(m.qty * m.cost) c FROM moves m JOIN docs d ON d.id = m.doc_id WHERE d.status = 'posted' AND m.wh = ? GROUP BY m.product_id");
  $st->execute([$wh]); $out = [];
  foreach ($st->fetchAll() as $r) $out[(int)$r['pid']] = ['q' => (float)$r['q'], 'c' => (float)$r['c']];
  return $out;
}
function docLabel(string $t): string { return ['open' => 'Ввод остатков', 'in' => 'Приход', 'iss' => 'Выдача с ответхранения', 'sale' => 'Продажа', 'ret' => 'Возврат', 'off' => 'Списание', 'inv' => 'Инвентаризация', 'rin' => 'Возврат поставщику'][$t] ?? $t; }
// последняя закупочная цена по товарам (для подсказки в приходе и как запасная себестоимость)
function lastCosts(array $cfg): array {
  $last = [];
  foreach (db($cfg)->query("SELECT m.product_id pid, m.cost FROM moves m JOIN docs d ON d.id = m.doc_id WHERE d.status = 'posted' AND d.type IN ('in','open') AND m.qty > 0 AND m.wh = 'shop' ORDER BY d.day, m.id")->fetchAll() as $r) $last[(int)$r['pid']] = (float)$r['cost'];
  return $last;
}
// знак строки движения: продажа и выдача уменьшают склад, приход и возврат — увеличивают
function docSign(string $t): int { return in_array($t, ['sale', 'iss', 'off', 'rout'], true) ? -1 : 1; }
function docWh(string $t, string $wh): string { return $t === 'iss' ? 'custody' : (in_array($t, ['sale', 'ret'], true) ? 'shop' : $wh); }
// сколько уже возвращено по продаже (проведённые возвраты), по товарам
function returnedMap(array $cfg, int $saleId, int $exceptId = 0): array {
  $st = db($cfg)->prepare("SELECT m.product_id pid, SUM(m.qty) q FROM moves m JOIN docs d ON d.id = m.doc_id WHERE d.type = 'ret' AND d.status = 'posted' AND d.base_id = ? AND d.id != ? GROUP BY m.product_id");
  $st->execute([$saleId, $exceptId]); $o = []; foreach ($st->fetchAll() as $r) $o[(int)$r['pid']] = (float)$r['q']; return $o;
}
// бонусы в кабинетах поставщиков: начисляются за выдачу с ответхранения, тратятся в приходе
function bonusMap(array $cfg, int $exceptDoc = 0): array {
  $o = [];
  $acc = function ($name, $add, $spend) use (&$o) { $n = trim((string)$name); if ($n === '') $n = 'Без поставщика'; $k = mb_strtoupper($n);
    if (!isset($o[$k])) $o[$k] = ['name' => $n, 'acc' => 0.0, 'spent' => 0.0]; $o[$k]['acc'] += $add; $o[$k]['spent'] += $spend; };
  foreach (db($cfg)->query("SELECT d.partner, d.fee, SUM(ABS(m.qty) * COALESCE(m.price, 0)) v FROM docs d JOIN moves m ON m.doc_id = d.id WHERE d.type = 'iss' AND d.status = 'posted' GROUP BY d.id")->fetchAll() as $r) $acc($r['partner'], (float)$r['v'] * (float)$r['fee'] / 100, 0);
  $st = db($cfg)->prepare("SELECT partner, bonus FROM docs WHERE type = 'in' AND status = 'posted' AND bonus > 0 AND id != ?"); $st->execute([$exceptDoc]);
  foreach ($st->fetchAll() as $r) $acc($r['partner'], 0, (float)$r['bonus']);
  foreach ($o as &$x) { $x['acc'] = round($x['acc'], 2); $x['spent'] = round($x['spent'], 2); $x['left'] = round($x['acc'] - $x['spent'], 2); } unset($x);
  return array_values($o);
}
function post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405); }

try {
  switch ($a) {

  case 'me':
    out(['role' => $role, 'coupon' => $role ? couponValue($cfg) : null]);

  case 'login':
    post();
    $pin = preg_replace('/\D/', '', (string)(body()['pin'] ?? ''));
    $now = time();
    $_SESSION['tries'] = array_values(array_filter($_SESSION['tries'] ?? [], fn($t) => $t > $now - 300));
    if (count($_SESSION['tries']) >= 8) fail('Слишком много попыток. Подождите пять минут.', 429);
    usleep(400000);
    $r = null;
    if ($pin !== '' && hash_equals((string)$cfg['owner_pin'], $pin)) $r = 'owner';
    elseif ($pin !== '' && hash_equals((string)$cfg['master_pin'], $pin)) $r = 'master';
    elseif ($pin !== '' && !empty($cfg['cashier_pin']) && hash_equals((string)$cfg['cashier_pin'], $pin)) $r = 'cashier';
    if (!$r) { $_SESSION['tries'][] = $now; fail('Неверный PIN', 403); }
    session_regenerate_id(true);
    $_SESSION['role'] = $r; $_SESSION['tries'] = [];
    logit($cfg, $r, 'login');
    out(['role' => $r]);

  case 'logout':
    post();
    $_SESSION = []; session_destroy();
    out(['ok' => true]);

  case 'day':
    needRole($role, ['owner', 'master', 'cashier']);
    $day = $_GET['day'] ?? '';
    if (!isDay($day)) fail('Неверная дата');
    $st = db($cfg)->prepare('SELECT id, body FROM orders WHERE day = ? ORDER BY t');
    $st->execute([$day]);
    $orders = array_map(fn($r) => ['id' => (int)$r['id']] + json_decode($r['body'], true), $st->fetchAll());
    $h = db($cfg)->prepare('SELECT helper FROM days WHERE day = ?'); $h->execute([$day]);
    out(['orders' => withClients($cfg, $orders), 'helper' => (bool)$h->fetchColumn(), 'bookings' => withClients($cfg, bookingRows($cfg, 'day = ?', [$day]))]);

  case 'helper':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $b = body(); if (!isDay($b['day'] ?? null)) fail('Неверная дата');
    db($cfg)->prepare('INSERT OR REPLACE INTO days (day, helper) VALUES (?, ?)')->execute([$b['day'], !empty($b['helper']) ? 1 : 0]);
    logit($cfg, $role, 'helper', $b['day'] . '=' . (!empty($b['helper']) ? 1 : 0));
    out(['ok' => true]);

  case 'save':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $o = body();
    if (!isDay($o['day'] ?? null) || !is_array($o['lines'] ?? null) || !count($o['lines'])) fail('Пустая запись');
    $list = 0; $h = 0;
    foreach ($o['lines'] as $l) { $q = max(0, (int)($l['q'] ?? 0)); $list += $q * (int)($l['p'] ?? 0); $h += $q * (int)($l['h'] ?? 0); }
    $o['noHelper'] = !empty($o['noHelper']);
    $o['list'] = $list; $o['h'] = $o['noHelper'] ? 0 : min($h, $list); // «без помощника»: снятие/установку делал мастер
    $id = isset($o['id']) ? (int)$o['id'] : 0; unset($o['id']);
    $prev = null;
    if ($id) { $s = db($cfg)->prepare('SELECT body FROM orders WHERE id = ?'); $s->execute([$id]); $prev = $s->fetchColumn(); if ($prev === false) fail('Запись уже удалена', 404); $prev = json_decode($prev, true); }
    // отметка «оплачено» ставится отдельной кнопкой; правка работ её не сбрасывает
    $o['paidOk'] = (bool)($prev['paidOk'] ?? false); $o['paidAt'] = $prev['paidAt'] ?? null;
    if ($o['paidOk'] && $list !== (int)($prev['list'] ?? 0)) $o['changedAfterPaid'] = true; else $o['changedAfterPaid'] = (bool)($prev['changedAfterPaid'] ?? false);
    if ($role !== 'owner') {
      // скидку и купон ставит только собственник; правка мастера их не сбрасывает
      $mode = $prev['mode'] ?? 'full';
      $o['mode'] = $mode;
      $o['coupon'] = (int)($prev['coupon'] ?? 0);
      $o['extra'] = 0; // по купону клиент не платит
      $o['paid'] = $mode === 'coupon' ? $o['coupon'] + $o['extra'] : ($mode === 'disc' ? (int)($prev['paid'] ?? 0) : $list);
      if ($mode === 'full') $o['pay'] = in_array($o['pay'] ?? '', ['cash','card','transfer'], true) ? $o['pay'] : 'card';
    } else {
      $o['mode'] = in_array($o['mode'] ?? '', ['full','disc','coupon'], true) ? $o['mode'] : 'full';
      // купон: производитель возмещает фиксированную сумму, клиент не платит; разница с прайсом — наш плюс или минус
      $o['coupon'] = $o['mode'] === 'coupon' ? max(0, (int)($o['coupon'] ?? couponValue($cfg))) : 0;
      $o['extra'] = 0;
      $o['paid'] = $o['mode'] === 'coupon' ? $o['coupon'] + $o['extra'] : ($o['mode'] === 'disc' ? max(0, (int)($o['paid'] ?? 0)) : $list);
    }
    $o['t'] = (int)($prev['t'] ?? ($o['t'] ?? round(microtime(true) * 1000)));
    foreach (['name', 'phone', 'plate', 'model', 'note'] as $k) $o[$k] = mb_substr(trim((string)($o[$k] ?? '')), 0, 200);
    $pk = phoneKey($o['phone']); if ($pk === '' && strlen(digits($o['phone'])) <= 1) $o['phone'] = '';
    $o['phoneD'] = $pk !== '' ? $pk : digits($o['phone']);
    $o['sk'] = mb_strtoupper(trim($o['name'] . ' ' . $o['plate'] . ' ' . $o['model']));
    touchClient($cfg, $pk, $o['name'], $o['model']);
    $json = json_encode($o, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 50000) fail('Слишком большая запись');
    if ($id) {
      db($cfg)->prepare('UPDATE orders SET day = ?, body = ?, updated = ? WHERE id = ?')->execute([$o['day'], $json, time(), $id]);
    } else {
      db($cfg)->prepare('INSERT INTO orders (day, t, body, author, updated) VALUES (?,?,?,?,?)')->execute([$o['day'], $o['t'], $json, $role, time()]);
      $id = (int)db($cfg)->lastInsertId();
    }
    if (!empty($o['bookingId'])) db($cfg)->prepare("UPDATE bookings SET status = 'done', updated = ? WHERE id = ?")->execute([time(), (int)$o['bookingId']]);
    logit($cfg, $role, 'save', (string)$id);
    out(['id' => $id]);

  case 'paid':
    // касса отмечает оплату — мастер видит и отпускает машину
    post(); needRole($role, ['owner', 'cashier']);
    $b = body(); $id = (int)($b['id'] ?? 0);
    $st = db($cfg)->prepare('SELECT body FROM orders WHERE id = ?'); $st->execute([$id]); $prev = $st->fetchColumn();
    if ($prev === false) fail('Запись уже удалена', 404);
    $o = json_decode($prev, true);
    $o['paidOk'] = !empty($b['paid']); $o['paidAt'] = $o['paidOk'] ? time() : null; $o['changedAfterPaid'] = false;
    if (in_array($b['pay'] ?? '', ['cash', 'card', 'transfer'], true)) $o['pay'] = $b['pay'];
    db($cfg)->prepare('UPDATE orders SET body = ?, updated = ? WHERE id = ?')->execute([json_encode($o, JSON_UNESCAPED_UNICODE), time(), $id]);
    logit($cfg, $role, 'paid', $id . '=' . ($o['paidOk'] ? 1 : 0));
    out(['ok' => true]);

  case 'del':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $id = (int)(body()['id'] ?? 0);
    db($cfg)->prepare('DELETE FROM orders WHERE id = ?')->execute([$id]);
    logit($cfg, $role, 'del', (string)$id);
    out(['ok' => true]);

  case 'exp':
    needRole($role, ['owner']);
    $m = $_GET['month'] ?? ''; if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Неверный месяц');
    $st = db($cfg)->prepare('SELECT id, day, sum, kind, cat, note FROM expenses WHERE day LIKE ? ORDER BY day DESC, id DESC');
    $st->execute([$m . '-%']);
    out(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'sum' => (int)$r['sum']] + $r, $st->fetchAll())]);

  case 'exp_add':
    post(); needRole($role, ['owner']);
    $b = body(); $sum = (int)($b['sum'] ?? 0);
    if (!isDay($b['day'] ?? null) || $sum <= 0) fail('Укажите дату и сумму');
    $kind = ($b['kind'] ?? '') === 'cap' ? 'cap' : 'cur';
    db($cfg)->prepare('INSERT INTO expenses (day, sum, kind, cat, note, t) VALUES (?,?,?,?,?,?)')->execute([$b['day'], $sum, $kind, mb_substr((string)($b['cat'] ?? ''), 0, 100), mb_substr((string)($b['note'] ?? ''), 0, 300), time()]);
    logit($cfg, $role, 'exp_add', (string)db($cfg)->lastInsertId());
    out(['ok' => true]);

  case 'exp_del':
    post(); needRole($role, ['owner']);
    $id = (int)(body()['id'] ?? 0);
    db($cfg)->prepare('DELETE FROM expenses WHERE id = ?')->execute([$id]);
    logit($cfg, $role, 'exp_del', (string)$id);
    out(['ok' => true]);

  case 'year':
    needRole($role, ['owner']);
    $y = (int)($_GET['year'] ?? 0); if ($y < 2000 || $y > 2100) fail('Неверный год');
    $st = db($cfg)->prepare('SELECT day, body FROM orders WHERE day BETWEEN ? AND ?'); $st->execute(["$y-01-01", "$y-12-31"]);
    $orders = array_map(function ($r) { $b = json_decode($r['body'], true); return ['day' => $r['day'], 'list' => (int)($b['list'] ?? 0), 'paid' => (int)($b['paid'] ?? 0), 'h' => (int)($b['h'] ?? 0), 'mode' => $b['mode'] ?? 'full', 'coupon' => (int)($b['coupon'] ?? 0)]; }, $st->fetchAll());
    $d = db($cfg)->prepare('SELECT day FROM days WHERE helper = 1 AND day BETWEEN ? AND ?'); $d->execute(["$y-01-01", "$y-12-31"]);
    $e = db($cfg)->prepare('SELECT day, sum, kind FROM expenses WHERE day BETWEEN ? AND ?'); $e->execute(["$y-01-01", "$y-12-31"]);
    out(['orders' => $orders, 'helperDays' => $d->fetchAll(PDO::FETCH_COLUMN), 'expenses' => array_map(fn($r) => ['day' => $r['day'], 'sum' => (int)$r['sum'], 'kind' => $r['kind']], $e->fetchAll())]);

  case 'book_save':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $b = body();
    $kind = in_array($b['kind'] ?? '', ['time', 'leave', 'live', 'task'], true) ? $b['kind'] : 'time';
    if (!isDay($b['day'] ?? null)) fail('Укажите день');
    if ($kind === 'time' && !preg_match('/^\d{2}:\d{2}$/', (string)($b['tm'] ?? ''))) fail('Выберите время');
    if ($kind !== 'time') $b['tm'] = '';
    $id = (int)($b['id'] ?? 0);
    $status = in_array($b['status'] ?? '', ['planned', 'done', 'noshow'], true) ? $b['status'] : 'planned';
    $keep = clean($b, ['name', 'phone', 'plate', 'model', 'note', 'car', 'preset']) + ['d' => isset($b['d']) ? (int)$b['d'] : null, 'est' => max(0, (int)($b['est'] ?? 0)), 'kind' => $kind, 'nodue' => $kind === 'task' && !empty($b['nodue'])];
    $keep['plate'] = mb_strtoupper($keep['plate']);
    $pk = phoneKey($keep['phone']); if ($pk === '' && strlen(digits($keep['phone'])) <= 1) $keep['phone'] = '';
    $keep['phoneD'] = $pk !== '' ? $pk : digits($keep['phone']);
    $keep['sk'] = mb_strtoupper(trim($keep['name'] . ' ' . $keep['plate'] . ' ' . $keep['model']));
    touchClient($cfg, $pk, $keep['name'], $keep['model']);
    $json = json_encode($keep, JSON_UNESCAPED_UNICODE);
    if (!preg_match('/^(\d{2}:\d{2})?$/', (string)$b['tm'])) fail('Неверное время');
    if ($id) db($cfg)->prepare('UPDATE bookings SET day = ?, tm = ?, status = ?, body = ?, updated = ? WHERE id = ?')->execute([$b['day'], $b['tm'], $status, $json, time(), $id]);
    else { db($cfg)->prepare('INSERT INTO bookings (day, tm, status, body, updated) VALUES (?,?,?,?,?)')->execute([$b['day'], $b['tm'], $status, $json, time()]); $id = (int)db($cfg)->lastInsertId(); }
    logit($cfg, $role, 'book_save', (string)$id);
    out(['id' => $id]);

  case 'book_del':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $id = (int)(body()['id'] ?? 0);
    db($cfg)->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
    logit($cfg, $role, 'book_del', (string)$id);
    out(['ok' => true]);

  case 'bookings':
    needRole($role, ['owner', 'master', 'cashier']);
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
    if (!isDay($from) || !isDay($to) || $to < $from) fail('Неверный период');
    if ((strtotime($to) - strtotime($from)) > 45 * 86400) fail('Слишком длинный период');
    out(['bookings' => withClients($cfg, bookingRows($cfg, 'day BETWEEN ? AND ?', [$from, $to]))]);

  case 'queue':
    // работы без времени, которые ещё не сделаны: «оставили на день» и «когда будет время»
    needRole($role, ['owner', 'master', 'cashier']);
    out(['items' => withClients($cfg, bookingRows($cfg, "status = 'planned' AND tm = ''", []))]);

  case 'mark':
    // отметка «клиенту написали» — у записи или у машины
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $b = body(); $id = (int)($b['id'] ?? 0); $tbl = ($b['what'] ?? '') === 'order' ? 'orders' : 'bookings';
    $st = db($cfg)->prepare("SELECT body FROM $tbl WHERE id = ?"); $st->execute([$id]); $j = $st->fetchColumn();
    if ($j === false) fail('Запись не найдена', 404);
    $o = json_decode($j, true); $o['msgAt'] = time();
    db($cfg)->prepare("UPDATE $tbl SET body = ? WHERE id = ?")->execute([json_encode($o, JSON_UNESCAPED_UNICODE), $id]);
    out(['ok' => true]);

  case 'week':
    // ближайшие дни: сколько записей и на какую сумму
    needRole($role, ['owner', 'master', 'cashier']);
    $from = $_GET['from'] ?? ''; if (!isDay($from)) fail('Неверная дата');
    $to = date('Y-m-d', strtotime($from . ' +13 days'));
    // работы «в свободное время» к дню не привязаны — в полосу дней не считаем
    $rows = []; foreach (bookingRows($cfg, "status = 'planned' AND day BETWEEN ? AND ?", [$from, $to]) as $r) { if (($r['kind'] ?? '') === 'task') continue; $rows[$r['day']]['day'] = $r['day']; $rows[$r['day']]['n'] = ($rows[$r['day']]['n'] ?? 0) + 1; $rows[$r['day']]['est'] = ($rows[$r['day']]['est'] ?? 0) + (int)($r['est'] ?? 0); } $rows = array_values($rows);
    out(['days' => array_map(fn($r) => ['day' => $r['day'], 'n' => (int)$r['n'], 'est' => (int)$r['est']], $rows)]);

  case 'clients':
    // поиск по телефону, имени, марке или госномеру; клиент = номер телефона
    needRole($role, ['owner', 'master', 'cashier']);
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) out(['orders' => [], 'bookings' => [], 'clients' => new stdClass()]);
    $dq = digits($q); if (strlen($dq) === 11 && $dq[0] === '8') $dq = '7' . substr($dq, 1);
    $Q = mb_strtoupper($q);
    $like = '%' . str_replace(['%', '_'], ['', ''], $Q) . '%';
    // 1) клиенты из справочника
    // SQLite не умеет сравнивать кириллицу без учёта регистра — фильтруем здесь
    $phones = [];
    foreach (db($cfg)->query('SELECT phone, name, model FROM clients')->fetchAll() as $c) if (mb_strpos(mb_strtoupper($c['name'] . ' ' . $c['model']), $Q) !== false || (strlen($dq) >= 4 && strpos($c['phone'], $dq) !== false)) $phones[$c['phone']] = true;
    // 2) машины и записи
    $conds = ['body LIKE ?']; $cargs = [$like];
    if (strlen($dq) >= 4) { $conds[] = 'body LIKE ?'; $cargs[] = '%' . $dq . '%'; }
    foreach (array_slice(array_keys($phones), 0, 30) as $p) { $conds[] = 'body LIKE ?'; $cargs[] = '%"phoneD":"' . $p . '"%'; }
    $match = function ($b) use ($Q, $dq, $phones) {
      $k = phoneKey($b['phone'] ?? '');
      if ($k && isset($phones[$k])) return true;
      if (mb_strpos(mb_strtoupper(($b['name'] ?? '') . ' ' . ($b['plate'] ?? '') . ' ' . ($b['model'] ?? '')), $Q) !== false) return true;
      return strlen($dq) >= 4 && strpos($k ?: digits($b['phone'] ?? ''), $dq) !== false;
    };
    $o = db($cfg)->prepare('SELECT id, day, body FROM orders WHERE ' . implode(' OR ', $conds) . ' ORDER BY day DESC LIMIT 400'); $o->execute($cargs);
    $orders = [];
    foreach ($o->fetchAll() as $r) {
      $b = json_decode($r['body'], true); if (!$match($b)) continue;
      $orders[] = ['id' => (int)$r['id'], 'day' => $r['day'], 'name' => $b['name'] ?? '', 'phone' => $b['phone'] ?? '', 'plate' => $b['plate'] ?? '', 'model' => $b['model'] ?? '', 'car' => $b['car'] ?? '', 'd' => $b['d'] ?? null,
        'works' => implode(', ', array_map(fn($l) => $l['n'] . (($l['q'] ?? 1) > 1 ? ' ×' . $l['q'] : ''), $b['lines'] ?? [])), 'list' => (int)($b['list'] ?? 0), 'paid' => $role === 'owner' ? (int)($b['paid'] ?? 0) : null, 'mode' => $b['mode'] ?? 'full',
        'lines' => array_map(fn($l) => ['k' => $l['k'] ?? 'x', 'n' => $l['n'] ?? '', 'q' => (int)($l['q'] ?? 1), 'p0' => (int)($l['p0'] ?? 0), 'n6' => !empty($l['n6'])], $b['lines'] ?? []), 'mods' => $b['mods'] ?? new stdClass()];
    }
    $bk = array_values(array_filter(bookingRows($cfg, implode(' OR ', $conds), $cargs), $match));
    // справочник по всем найденным телефонам
    $all = array_merge($orders, $bk); $keys = [];
    foreach ($all as $x) { $k = phoneKey($x['phone'] ?? ''); if ($k) $keys[$k] = true; }
    foreach ($phones as $k => $_) $keys[$k] = true;
    $map = [];
    if ($keys) { $kk = array_keys($keys); $st = db($cfg)->prepare('SELECT phone, name, model FROM clients WHERE phone IN (' . implode(',', array_fill(0, count($kk), '?')) . ')'); $st->execute($kk); foreach ($st->fetchAll() as $c) $map[$c['phone']] = ['name' => $c['name'], 'model' => $c['model']]; }
    out(['orders' => $orders, 'bookings' => $bk, 'clients' => $map ?: new stdClass()]);

  case 'client_set':
    // имя и машина клиента — как вам удобнее его называть
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $b = body(); $k = phoneKey($b['phone'] ?? ''); if ($k === '') fail('Нужен полный номер телефона');
    db($cfg)->prepare('INSERT OR REPLACE INTO clients (phone, name, model, updated) VALUES (?,?,?,?)')->execute([$k, mb_substr(trim((string)($b['name'] ?? '')), 0, 100), mb_substr(trim((string)($b['model'] ?? '')), 0, 100), time()]);
    logit($cfg, $role, 'client_set', $k);
    out(['ok' => true]);

  case 'month':
    // для игры мастера: без сумм «получено» и расходов
    needRole($role, ['owner', 'master', 'cashier']);
    $m = $_GET['month'] ?? ''; if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Неверный месяц');
    $st = db($cfg)->prepare('SELECT day, body FROM orders WHERE day LIKE ?'); $st->execute([$m . '-%']);
    $orders = array_map(function ($r) { $b = json_decode($r['body'], true); $big = 0; $rep = 0; $bal = 0;
      foreach ($b['lines'] ?? [] as $l) {
        if (in_array($l['k'] ?? '', ['cx', 'mount'], true)) $big = 1;
        if (preg_match('/Ремонт|заплат|жгут|грибк|пластыр|Герметизац/u', $l['n'] ?? '')) $rep += (int)($l['q'] ?? 1);
        if (($l['k'] ?? '') === 'bal') $bal += (int)($l['q'] ?? 1); elseif (($l['k'] ?? '') === 'cx') $bal += 4;
      }
      return ['day' => $r['day'], 'list' => (int)($b['list'] ?? 0), 'h' => (int)($b['h'] ?? 0), 'd' => $b['d'] ?? null, 'car' => $b['car'] ?? '', 'mount' => $big, 'rep' => $rep, 'bal' => $bal, 'phone' => phoneKey($b['phone'] ?? '')]; }, $st->fetchAll());
    $d = db($cfg)->prepare('SELECT day FROM days WHERE helper = 1 AND day LIKE ?'); $d->execute([$m . '-%']);
    // лучший день мастера до этого месяца — для значка «Рекорд»
    $prev = db($cfg)->prepare('SELECT o.day, o.body, IFNULL(dd.helper,0) helper FROM orders o LEFT JOIN days dd ON dd.day = o.day WHERE o.day < ?'); $prev->execute([$m . '-01']);
    $byDay = [];
    foreach ($prev->fetchAll() as $r) { $b = json_decode($r['body'], true); $byDay[$r['day']] = ($byDay[$r['day']] ?? 0) + (int)($b['list'] ?? 0) - ($r['helper'] ? (int)($b['h'] ?? 0) : 0); }
    out(['orders' => $orders, 'helperDays' => $d->fetchAll(PDO::FETCH_COLUMN), 'bestBefore' => $byDay ? max($byDay) : 0]);

  case 'spins':
    needRole($role, ['owner', 'master', 'cashier']);
    $m = $_GET['month'] ?? ''; if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Неверный месяц');
    out(spinState($cfg, $m));

  case 'spin':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $m = date('Y-m');
    $s = spinState($cfg, $m);
    if ($s['left'] < 1) fail('Вращений пока нет — следующее за каждые 10 000 ₽ работ', 409);
    $p = $s['prizes']; $i = random_int(0, count($p) - 1);
    db($cfg)->prepare('INSERT INTO spins (month, t, prize) VALUES (?,?,?)')->execute([$m, time(), $p[$i]]);
    logit($cfg, $role, 'spin', $p[$i]);
    out(['index' => $i, 'prize' => $p[$i], 'state' => spinState($cfg, $m)]);

  case 'spin_given':
    post(); needRole($role, ['owner']);
    $b = body();
    db($cfg)->prepare('UPDATE spins SET given = ? WHERE id = ?')->execute([!empty($b['given']) ? 1 : 0, (int)($b['id'] ?? 0)]);
    out(['ok' => true]);

  case 'prizes_set':
    post(); needRole($role, ['owner']);
    $list = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), (array)(body()['prizes'] ?? [])), fn($x) => $x !== ''));
    if (count($list) < 2 || count($list) > 12) fail('Нужно от 2 до 12 призов');
    db($cfg)->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['prizes', json_encode($list, JSON_UNESCAPED_UNICODE)]);
    out(['prizes' => $list]);

  case 'coupon_set':
    post(); needRole($role, ['owner']);
    $v = (int)(body()['value'] ?? -1); if ($v < 0 || $v > 100000) fail('Неверная сумма');
    db($cfg)->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['coupon', (string)$v]);
    out(['coupon' => $v]);

  case 'weather_save':
    // фактическая погода прошедших дней — для статистики «выручка и погода»
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $days = (array)(body()['days'] ?? []); $n = 0;
    $st = db($cfg)->prepare('INSERT OR REPLACE INTO weather (day, tmax, tmin, prec, code, updated) VALUES (?,?,?,?,?,?)');
    foreach (array_slice($days, 0, 120) as $d) {
      if (!isDay($d['day'] ?? null) || $d['day'] >= date('Y-m-d')) continue;
      $st->execute([$d['day'], is_numeric($d['tmax'] ?? null) ? (float)$d['tmax'] : null, is_numeric($d['tmin'] ?? null) ? (float)$d['tmin'] : null, is_numeric($d['prec'] ?? null) ? (float)$d['prec'] : null, is_numeric($d['code'] ?? null) ? (int)$d['code'] : null, time()]); $n++;
    }
    out(['saved' => $n]);

  case 'weather':
    needRole($role, ['owner', 'master', 'cashier']);
    $from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? ''; if (!isDay($from) || !isDay($to)) fail('Неверный период');
    $st = db($cfg)->prepare('SELECT day, tmax, tmin, prec, code FROM weather WHERE day BETWEEN ? AND ?'); $st->execute([$from, $to]);
    out(['days' => $st->fetchAll()]);

  // ======================= СКЛАД =======================
  case 'cat_state':
    // есть ли уже товары и введены ли стартовые остатки
    needRole($role, ['owner']);
    $n = (int)db($cfg)->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $open = db($cfg)->query("SELECT id, day, posted_at FROM docs WHERE type = 'open' AND status = 'posted' ORDER BY id LIMIT 1")->fetch();
    out(['products' => $n, 'open' => $open ?: null]);

  case 'cat_import':
    // загрузка каталога из выгрузки CloudShop порциями: создаёт новые карточки, обновляет названия и цены
    post(); needRole($role, ['owner']);
    $rows = (array)(body()['rows'] ?? []); if (count($rows) > 1000) fail('Слишком большая порция');
    $p = db($cfg); $p->beginTransaction(); $new = 0; $upd = 0; $now = time();
    $sel = $p->prepare('SELECT id FROM products WHERE ext_id = ?');
    $ins = $p->prepare('INSERT INTO products (ext_id, name, grp, cat, kind, attrs, barcode, article, unit, price, supplier, country, created, updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $up = $p->prepare('UPDATE products SET name = ?, grp = ?, cat = ?, kind = ?, attrs = ?, barcode = ?, article = ?, unit = ?, price = ?, supplier = ?, country = ?, updated = ? WHERE id = ?');
    foreach ($rows as $r) {
      $ext = mb_substr(trim((string)($r['ext_id'] ?? '')), 0, 64); $name = mb_substr(trim((string)($r['name'] ?? '')), 0, 300);
      if ($ext === '' || $name === '') continue;
      $vals = [$name, mb_substr((string)($r['grp'] ?? ''), 0, 200), mb_substr((string)($r['cat'] ?? ''), 0, 200), mb_substr((string)($r['kind'] ?? 'acc'), 0, 20),
        json_encode($r['attrs'] ?? new stdClass(), JSON_UNESCAPED_UNICODE), mb_substr((string)($r['barcode'] ?? ''), 0, 64), mb_substr((string)($r['article'] ?? ''), 0, 64),
        mb_substr((string)($r['unit'] ?? 'шт'), 0, 20), (float)($r['price'] ?? 0), mb_substr((string)($r['supplier'] ?? ''), 0, 200), mb_substr((string)($r['country'] ?? ''), 0, 60)];
      $sel->execute([$ext]); $id = $sel->fetchColumn();
      if ($id) { $up->execute(array_merge($vals, [$now, $id])); $upd++; }
      else { $ins->execute(array_merge([$ext], $vals, [$now, $now])); $new++; }
    }
    $p->commit();
    logit($cfg, $role, 'cat_import', "$new+$upd");
    out(['new' => $new, 'updated' => $upd]);

  case 'open_start':
    // документ «Ввод остатков» — один раз, на дату старта учёта
    post(); needRole($role, ['owner']);
    $b = body(); if (!isDay($b['day'] ?? null)) fail('Укажите дату');
    $ex = db($cfg)->query("SELECT id, status FROM docs WHERE type = 'open' AND status != 'cancelled' ORDER BY id LIMIT 1")->fetch();
    if ($ex && $ex['status'] === 'posted') fail('Стартовые остатки уже введены. Изменить их можно только инвентаризацией.', 409);
    if ($ex) { db($cfg)->prepare('DELETE FROM moves WHERE doc_id = ?')->execute([$ex['id']]); db($cfg)->prepare('UPDATE docs SET day = ? WHERE id = ?')->execute([$b['day'], $ex['id']]); out(['id' => (int)$ex['id']]); }
    db($cfg)->prepare("INSERT INTO docs (type, num, day, partner, note, status, author, created) VALUES ('open', '1', ?, '', 'Перенос остатков из CloudShop', 'draft', ?, ?)")->execute([$b['day'], $role, time()]);
    out(['id' => (int)db($cfg)->lastInsertId()]);

  case 'open_lines':
    post(); needRole($role, ['owner']);
    $b = body(); $doc = (int)($b['doc'] ?? 0); $lines = (array)($b['lines'] ?? []);
    $d = db($cfg)->prepare("SELECT status FROM docs WHERE id = ? AND type = 'open'"); $d->execute([$doc]); if ($d->fetchColumn() !== 'draft') fail('Документ уже проведён или не найден', 409);
    $p = db($cfg); $p->beginTransaction(); $n = 0;
    $sel = $p->prepare('SELECT id, price FROM products WHERE ext_id = ?'); $ins = $p->prepare("INSERT INTO moves (doc_id, product_id, wh, qty, cost, price) VALUES (?,?, 'shop', ?,?,?)");
    foreach (array_slice($lines, 0, 1000) as $l) { $q = (float)($l['qty'] ?? 0); if ($q == 0) continue; $sel->execute([(string)($l['ext_id'] ?? '')]); $pr = $sel->fetch(); if (!$pr) continue;
      $ins->execute([$doc, $pr['id'], $q, max(0, (float)($l['cost'] ?? 0)), $pr['price']]); $n++; }
    $p->commit(); out(['lines' => $n]);

  case 'open_post':
    post(); needRole($role, ['owner']);
    $doc = (int)(body()['doc'] ?? 0);
    db($cfg)->prepare("UPDATE docs SET status = 'posted', posted_at = ? WHERE id = ? AND type = 'open' AND status = 'draft'")->execute([time(), $doc]);
    logit($cfg, $role, 'open_post', (string)$doc);
    out(['ok' => true]);

  case 'stock':
    // список товаров с остатками; себестоимость — только собственнику
    needRole($role, ['owner', 'master', 'cashier']);
    $q = mb_strtoupper(trim((string)($_GET['q'] ?? ''))); $kind = (string)($_GET['kind'] ?? ''); $only = ($_GET['instock'] ?? '1') === '1';
    $lim = min(300, max(20, (int)($_GET['limit'] ?? 120)));
    $wh = ($_GET['wh'] ?? 'shop') === 'custody' ? 'custody' : 'shop';
    $sm = stockMap($cfg, $wh); $owner = $role === 'owner';
    $toks = array_values(array_filter(preg_split('/[\s\/]+/u', str_replace(['*', '×', 'х'], ' ', $q))));
    $rows = []; $tot = ['n' => 0, 'qty' => 0, 'cost' => 0, 'retail' => 0];
    foreach (db($cfg)->query("SELECT id, name, kind, attrs, barcode, article, price, unit FROM products WHERE archived = 0 AND kind != 'svc' ORDER BY name")->fetchAll() as $p) {
      $s = $sm[(int)$p['id']] ?? ['q' => 0, 'c' => 0]; if ($only && $s['q'] <= 0) continue;
      if ($kind !== '' && $p['kind'] !== $kind && !($kind === 'other' && in_array($p['kind'], ['acc', 'tube', 'tool', 'tireo'], true))) continue;
      if ($toks) { $hay = mb_strtoupper(str_replace('/', ' ', $p['name'])) . ' ' . $p['barcode'] . ' ' . mb_strtoupper($p['article']); $a = json_decode($p['attrs'], true) ?: [];
        $ok = true; foreach ($toks as $t) { if (preg_match('/^[RР](\d{2})$/u', $t, $m)) { if ((int)($a['d'] ?? 0) !== (int)$m[1]) { $ok = false; break; } continue; } if (mb_strpos($hay, $t) === false) { $ok = false; break; } } if (!$ok) continue; }
      $tot['n']++; $tot['qty'] += max(0, $s['q']); $tot['retail'] += max(0, $s['q']) * (float)$p['price']; if ($owner) $tot['cost'] += max(0, $s['c']);
      if (count($rows) < $lim) $rows[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'barcode' => $p['barcode'], 'article' => $p['article'], 'unit' => $p['unit'], 'price' => (float)$p['price'], 'qty' => $s['q'], 'cost' => $owner && $s['q'] > 0 ? round($s['c'] / $s['q'], 2) : null];
    }
    if (!$owner) unset($tot['cost']);
    out(['rows' => $rows, 'total' => $tot, 'more' => $tot['n'] > count($rows)]);

  case 'product':
    needRole($role, ['owner', 'master', 'cashier']);
    $id = (int)($_GET['id'] ?? 0); $st = db($cfg)->prepare('SELECT * FROM products WHERE id = ?'); $st->execute([$id]); $p = $st->fetch(); if (!$p) fail('Товар не найден', 404);
    $p['attrs'] = json_decode($p['attrs'], true) ?: new stdClass(); $owner = $role === 'owner';
    if (!$owner) unset($p['supplier']);
    $mv = db($cfg)->prepare("SELECT d.id doc, d.type, d.day, d.num, d.partner, d.wh dwh, m.qty, m.cost, m.wh FROM moves m JOIN docs d ON d.id = m.doc_id WHERE m.product_id = ? AND d.status = 'posted' ORDER BY d.day DESC, d.id DESC LIMIT 100");
    $mv->execute([$id]); $moves = array_map(function ($r) use ($owner) { $r['label'] = docLabel($r['type']); if (!$owner) { unset($r['cost']); $r['partner'] = ''; } return $r; }, $mv->fetchAll());
    $s = stockMap($cfg)[$id] ?? ['q' => 0, 'c' => 0]; $cu = stockMap($cfg, 'custody')[$id] ?? ['q' => 0];
    out(['product' => $p, 'qty' => $s['q'], 'custody' => $cu['q'], 'cost' => $owner && $s['q'] > 0 ? round($s['c'] / $s['q'], 2) : null, 'moves' => $moves]);

  case 'prod_find':
    // поиск товара для строки документа: все карточки, и в наличии, и нет; штрихкод — точное совпадение первым
    needRole($role, ['owner']);
    $q = trim((string)($_GET['q'] ?? '')); if (mb_strlen($q) < 2) out(['rows' => []]);
    $Q = mb_strtoupper($q); $toks = array_values(array_filter(preg_split('/[\s\/]+/u', str_replace(['*', '×', 'х'], ' ', $Q))));
    $wh = ($_GET['wh'] ?? 'shop') === 'custody' ? 'custody' : 'shop';
    $sm = stockMap($cfg, $wh); $exact = []; $rows = [];
    $last = lastCosts($cfg);
    foreach (db($cfg)->query("SELECT id, name, kind, barcode, article, price, unit FROM products WHERE archived = 0 AND kind != 'svc'")->fetchAll() as $p) {
      $row = ['id' => (int)$p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'barcode' => $p['barcode'], 'price' => (float)$p['price'], 'unit' => $p['unit'], 'qty' => ($sm[(int)$p['id']]['q'] ?? 0), 'last' => $last[(int)$p['id']] ?? null];
      $sq = $sm[(int)$p['id']] ?? null; $row['avg'] = $sq && $sq['q'] > 0 ? round($sq['c'] / $sq['q'], 2) : ($wh === 'shop' ? $row['last'] : null);
      if ($p['barcode'] !== '' && ($p['barcode'] === $q || ltrim($p['barcode'], '0') === ltrim($q, '0'))) { $exact[] = $row; continue; }
      if ($p['article'] !== '' && mb_strtoupper($p['article']) === $Q) { $exact[] = $row; continue; }
      $hay = mb_strtoupper(str_replace('/', ' ', $p['name'])) . ' ' . $p['barcode'];
      $ok = true; foreach ($toks as $t) if (mb_strpos($hay, $t) === false) { $ok = false; break; }
      if ($ok) $rows[] = $row;
    }
    usort($rows, fn($a, $b) => ($b['qty'] > 0) <=> ($a['qty'] > 0) ?: strcmp($a['name'], $b['name']));
    out(['exact' => $exact, 'rows' => array_slice($rows, 0, 25)]);

  case 'prod_create':
    // новая карточка (товара нет в CloudShop — пришёл впервые)
    post(); needRole($role, ['owner']);
    $b = body(); $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 300); if ($name === '') fail('Впишите название');
    $bc = mb_substr(trim((string)($b['barcode'] ?? '')), 0, 64);
    if ($bc !== '') { $st = db($cfg)->prepare('SELECT name FROM products WHERE barcode = ?'); $st->execute([$bc]); if ($n = $st->fetchColumn()) fail('Такой штрихкод уже у карточки «' . $n . '»', 409); }
    db($cfg)->prepare('INSERT INTO products (ext_id, name, grp, cat, kind, attrs, barcode, article, unit, price, supplier, country, created, updated) VALUES (NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$name, (string)($b['grp'] ?? ''), '', (string)($b['kind'] ?? 'acc'), json_encode($b['attrs'] ?? new stdClass(), JSON_UNESCAPED_UNICODE), $bc, mb_substr(trim((string)($b['article'] ?? '')), 0, 64), (string)($b['unit'] ?? 'шт'), max(0, (float)($b['price'] ?? 0)), '', '', time(), time()]);
    $id = (int)db($cfg)->lastInsertId(); logit($cfg, $role, 'prod_create', (string)$id);
    out(['id' => $id]);

  case 'partners':
    needRole($role, ['owner']);
    $names = [];
    foreach (db($cfg)->query("SELECT partner FROM docs WHERE partner != '' ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN) as $n) $names[$n] = true;
    foreach (db($cfg)->query("SELECT DISTINCT supplier FROM products WHERE supplier != ''")->fetchAll(PDO::FETCH_COLUMN) as $n) $names[$n] = true;
    out(['names' => array_keys($names)]);

  case 'docs':
    needRole($role, ['owner']);
    $m = $_GET['month'] ?? ''; if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Неверный месяц');
    $type = (string)($_GET['type'] ?? '');
    $sql = "SELECT d.*, COUNT(m.id) n, SUM(ABS(m.qty)) qty, SUM(ABS(m.qty) * m.cost) sum, SUM(ABS(m.qty) * COALESCE(m.price, 0)) rev, SUM(ABS(m.qty) * COALESCE(m.list, m.price, 0)) lst FROM docs d LEFT JOIN moves m ON m.doc_id = d.id WHERE d.day LIKE ?" . ($type !== '' ? ' AND d.type = ?' : '') . " GROUP BY d.id ORDER BY d.day DESC, d.id DESC";
    $st = db($cfg)->prepare($sql); $st->execute($type !== '' ? [$m . '-%', $type] : [$m . '-%']);
    out(['docs' => array_map(fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'label' => docLabel($r['type']), 'day' => $r['day'], 'num' => $r['num'], 'partner' => $r['partner'], 'wh' => $r['wh'], 'status' => $r['status'], 'n' => (int)$r['n'], 'qty' => (float)$r['qty'], 'sum' => round((float)$r['sum'], 2), 'cost' => round((float)$r['sum'], 2), 'rev' => round((float)$r['rev'], 2), 'list' => round((float)$r['lst'], 2), 'fee' => (float)$r['fee'], 'bonus' => (float)$r['bonus'], 'phone' => $r['phone'], 'pay' => $r['pay'], 'base_id' => $r['base_id'] !== null ? (int)$r['base_id'] : null, 'note' => $r['note']], $st->fetchAll())]);

  case 'doc':
    needRole($role, ['owner']);
    $id = (int)($_GET['id'] ?? 0); $st = db($cfg)->prepare('SELECT * FROM docs WHERE id = ?'); $st->execute([$id]); $d = $st->fetch(); if (!$d) fail('Документ не найден', 404);
    $l = db($cfg)->prepare('SELECT m.id, m.product_id, m.qty, m.cost, m.price, m.list, p.name, p.unit, p.price cur_price, p.barcode FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id = ? ORDER BY m.id'); $l->execute([$id]);
    $d['label'] = docLabel($d['type']); $d['fee'] = (float)$d['fee'];
    // у продажи — какие возвраты по ней уже есть
    $rets = []; $retq = [];
    if ($d['type'] === 'sale') { $r = db($cfg)->prepare("SELECT id, day, status FROM docs WHERE type = 'ret' AND base_id = ? ORDER BY id"); $r->execute([$id]); $rets = $r->fetchAll(); $retq = returnedMap($cfg, $id); }
    out(['doc' => $d, 'returns' => $rets, 'lines' => array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'name' => $r['name'], 'unit' => $r['unit'], 'barcode' => $r['barcode'], 'qty' => abs((float)$r['qty']), 'cost' => (float)$r['cost'], 'price' => $r['price'] !== null ? (float)$r['price'] : (float)$r['cur_price'], 'list' => $r['list'] !== null ? (float)$r['list'] : null, 'cur_price' => (float)$r['cur_price'], 'returned' => $retq[(int)$r['product_id']] ?? 0], $l->fetchAll())]);

  case 'doc_save':
    // черновик прихода: шапка и строки целиком; проведённый документ так не меняется
    post(); needRole($role, ['owner']);
    $b = body(); $type = (string)($b['type'] ?? ''); if (!in_array($type, ['in', 'sale', 'ret', 'iss'], true)) fail('Этот вид документа пока не поддерживается');
    if (!isDay($b['day'] ?? null)) fail('Укажите дату');
    $wh = docWh($type, ($b['wh'] ?? 'shop') === 'custody' ? 'custody' : 'shop'); $sign = docSign($type);
    $phone = phoneKey((string)($b['phone'] ?? '')); $pay = in_array($b['pay'] ?? '', ['cash', 'card', 'sbp', 'bank'], true) ? $b['pay'] : '';
    $base = (int)($b['base_id'] ?? 0) ?: null; $fee = $type === 'iss' ? max(0, min(100, (float)($b['fee'] ?? 5))) : 0;
    $bonus = $type === 'in' && $wh === 'shop' ? max(0, round((float)($b['bonus'] ?? 0), 2)) : 0;
    $id = (int)($b['id'] ?? 0); $p = db($cfg);
    if ($id) { $st = $p->prepare('SELECT status FROM docs WHERE id = ?'); $st->execute([$id]); $stt = $st->fetchColumn(); if ($stt === false) fail('Документ не найден', 404); if ($stt !== 'draft') fail('Документ уже проведён — его можно отменить и создать исправленную копию', 409); }
    $p->beginTransaction();
    $hdr = [$b['day'], mb_substr(trim((string)($b['partner'] ?? '')), 0, 200), mb_substr(trim((string)($b['num'] ?? '')), 0, 60), mb_substr(trim((string)($b['note'] ?? '')), 0, 500), $wh];
    $hdr = array_merge($hdr, [$phone, $pay, $base, $fee, $bonus]);
    if ($id) { $p->prepare('UPDATE docs SET day = ?, partner = ?, num = ?, note = ?, wh = ?, phone = ?, pay = ?, base_id = ?, fee = ?, bonus = ? WHERE id = ?')->execute(array_merge($hdr, [$id])); $p->prepare('DELETE FROM moves WHERE doc_id = ?')->execute([$id]); }
    else { $p->prepare("INSERT INTO docs (type, day, partner, num, note, wh, phone, pay, base_id, fee, bonus, status, author, created) VALUES (?,?,?,?,?,?,?,?,?,?,?, 'draft', ?, ?)")->execute(array_merge([$type], $hdr, [$role, time()])); $id = (int)$p->lastInsertId(); }
    $ins = $p->prepare('INSERT INTO moves (doc_id, product_id, wh, qty, cost, price, list) VALUES (?,?,?,?,?,?,?)'); $chk = $p->prepare('SELECT price FROM products WHERE id = ?');
    $num = fn($v) => isset($v) && $v !== '' && $v !== null ? max(0, round((float)$v, 2)) : null;
    foreach (array_slice((array)($b['lines'] ?? []), 0, 500) as $l) { $pid = (int)($l['product_id'] ?? 0); $q = abs((float)($l['qty'] ?? 0)); if (!$pid || $q <= 0) continue; $chk->execute([$pid]); $cp = $chk->fetchColumn(); if ($cp === false) continue;
      // себестоимость продажи и выдачи считается при проведении; в приходе — из строки
      $cost = $type === 'in' ? (float)$num($l['cost'] ?? 0) : 0;
      // в продаже list — цена по прайсу; в приходе — цена по накладной до бонусов
      $list = $type === 'sale' ? ($num($l['list'] ?? null) ?? (float)$cp) : ($type === 'in' ? $cost : null);
      $ins->execute([$id, $pid, $wh, $sign * $q, $cost, $num($l['price'] ?? null), $list]); }
    $p->commit(); logit($cfg, $role, 'doc_save', (string)$id);
    out(['id' => $id]);

  case 'doc_post':
    // провести: остатки меняются; новые цены продажи из строк прихода записываются в карточки
    post(); needRole($role, ['owner']);
    $id = (int)(body()['id'] ?? 0); $p = db($cfg);
    $st = $p->prepare('SELECT * FROM docs WHERE id = ?'); $st->execute([$id]); $d = $st->fetch(); if (!$d) fail('Документ не найден', 404); if ($d['status'] !== 'draft') fail('Документ уже проведён или отменён', 409);
    $l = $p->prepare('SELECT product_id, qty, cost, price FROM moves WHERE doc_id = ?'); $l->execute([$id]); $lines = $l->fetchAll(); if (!$lines) fail('В документе нет строк');
    if ($d['type'] === 'in' && $d['wh'] === 'shop') foreach ($lines as $x) if ((float)$x['cost'] <= 0) fail('Не у всех строк указана цена закупки');
    $force = !empty(body()['force']); $setCost = [];
    $applyBonus = null;
    if ($d['type'] === 'in' && $d['wh'] === 'shop' && (float)$d['bonus'] > 0) {
      $tot = 0; foreach ($lines as $x) $tot += (float)$x['qty'] * (float)$x['cost'];
      if ((float)$d['bonus'] > $tot - 0.01) fail('Бонусами можно оплатить меньше, чем сумма прихода');
      $applyBonus = 1 - (float)$d['bonus'] / $tot;
    }
    if (in_array($d['type'], ['sale', 'iss'], true)) {
      // списываем по средней себестоимости на момент проведения; проверяем, хватает ли товара
      $sm = stockMap($cfg, $d['wh']); $last = lastCosts($cfg); $need = []; $short = [];
      foreach ($lines as $x) $need[(int)$x['product_id']] = ($need[(int)$x['product_id']] ?? 0) - (float)$x['qty'];
      $nm = $p->prepare('SELECT name FROM products WHERE id = ?');
      foreach ($need as $pid => $q) { $have = $sm[$pid]['q'] ?? 0; if ($have + 1e-9 < $q) { $nm->execute([$pid]); $short[] = $nm->fetchColumn() . ' (есть ' . $have . ', нужно ' . $q . ')'; }
        $setCost[$pid] = $have > 0 ? round($sm[$pid]['c'] / $have, 2) : ($d['type'] === 'sale' ? ($last[$pid] ?? 0) : 0); }
      if ($short && !$force) out(['needForce' => true, 'short' => $short]);
    }
    if ($d['type'] === 'ret') {
      // возврат: себестоимость — как в продаже (если указана), иначе средняя/последняя; больше проданного не вернуть
      $sm = stockMap($cfg, 'shop'); $last = lastCosts($cfg); $bc = [];
      if ($d['base_id']) {
        $bs = $p->prepare("SELECT type, status FROM docs WHERE id = ?"); $bs->execute([$d['base_id']]); $bd = $bs->fetch();
        if (!$bd || $bd['type'] !== 'sale' || $bd['status'] !== 'posted') fail('Продажа, по которой возврат, не найдена или отменена', 409);
        $sold = []; $bm = $p->prepare('SELECT product_id, qty, cost FROM moves WHERE doc_id = ?'); $bm->execute([$d['base_id']]);
        foreach ($bm->fetchAll() as $r) { $sold[(int)$r['product_id']] = ($sold[(int)$r['product_id']] ?? 0) - (float)$r['qty']; $bc[(int)$r['product_id']] = (float)$r['cost']; }
        $done = returnedMap($cfg, (int)$d['base_id'], $id); $want = [];
        foreach ($lines as $x) $want[(int)$x['product_id']] = ($want[(int)$x['product_id']] ?? 0) + (float)$x['qty'];
        foreach ($want as $pid => $q) if ($q > ($sold[$pid] ?? 0) - ($done[$pid] ?? 0) + 1e-9) fail('Возвращают больше, чем было продано в продаже № ' . $d['base_id'] . ' (с учётом прошлых возвратов)');
      }
      foreach ($lines as $x) { $pid = (int)$x['product_id']; $sq = $sm[$pid] ?? null; $setCost[$pid] = $bc[$pid] ?? ($sq && $sq['q'] > 0 ? round($sq['c'] / $sq['q'], 2) : ($last[$pid] ?? 0)); }
    }
    $p->beginTransaction();
    if ($setCost) { $uc = $p->prepare('UPDATE moves SET cost = ? WHERE doc_id = ? AND product_id = ?'); foreach ($setCost as $pid => $c) $uc->execute([$c, $id, $pid]); }
    // бонусы поставщика уменьшают закупочную цену строк пропорционально сумме; цена по накладной остаётся в list
    if ($applyBonus !== null) $p->prepare('UPDATE moves SET list = cost, cost = ROUND(cost * ?, 2) WHERE doc_id = ?')->execute([$applyBonus, $id]);
    if (in_array($d['type'], ['sale', 'ret'], true) && $d['phone'] !== '') touchClient($cfg, $d['phone'], trim((string)$d['partner']), '');
    if ($d['type'] === 'in' && $d['wh'] === 'shop') { $up = $p->prepare('UPDATE products SET price = ?, updated = ? WHERE id = ? AND price != ?'); foreach ($lines as $x) if ($x['price'] !== null && (float)$x['price'] > 0) $up->execute([(float)$x['price'], time(), $x['product_id'], (float)$x['price']]); }
    $p->prepare("UPDATE docs SET status = 'posted', posted_at = ? WHERE id = ?")->execute([time(), $id]);
    $p->commit(); logit($cfg, $role, 'doc_post', (string)$id);
    out(['ok' => true]);

  case 'doc_cancel':
    // отмена проведения: документ остаётся в списке зачёркнутым, с причиной и временем
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 300); if ($reason === '') fail('Напишите причину отмены');
    $st = db($cfg)->prepare('SELECT * FROM docs WHERE id = ?'); $st->execute([$id]); $d = $st->fetch(); if (!$d) fail('Документ не найден', 404);
    if ($d['type'] === 'open') fail('Стартовые остатки не отменяются — исправляются инвентаризацией', 409);
    if ($d['status'] === 'draft') { db($cfg)->prepare('DELETE FROM moves WHERE doc_id = ?')->execute([$id]); db($cfg)->prepare('DELETE FROM docs WHERE id = ?')->execute([$id]); out(['deleted' => true]); }
    if ($d['status'] !== 'posted') fail('Документ уже отменён', 409);
    if ($d['type'] === 'sale') { $r = db($cfg)->prepare("SELECT id FROM docs WHERE type = 'ret' AND status = 'posted' AND base_id = ?"); $r->execute([$id]); $ids = $r->fetchAll(PDO::FETCH_COLUMN);
      if ($ids) fail('По этой продаже проведён возврат № ' . implode(', ', $ids) . ' — сначала отмените его', 409); }
    // проверка: не уйдёт ли остаток в минус
    $sm = stockMap($cfg, $d['wh']); $l = db($cfg)->prepare('SELECT m.product_id, m.qty, p.name FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id = ?'); $l->execute([$id]); $neg = [];
    foreach ($l->fetchAll() as $x) { $after = ($sm[(int)$x['product_id']]['q'] ?? 0) - (float)$x['qty']; if ($after < 0) $neg[] = $x['name'] . ' (станет ' . $after . ')'; }
    if ($neg && empty($b['force'])) out(['needForce' => true, 'negative' => $neg]);
    db($cfg)->prepare("UPDATE docs SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE id = ?")->execute([time(), $role, $reason, $id]);
    logit($cfg, $role, 'doc_cancel', $id . ': ' . $reason);
    out(['ok' => true]);

  case 'bonuses':
    needRole($role, ['owner']);
    out(['bonuses' => bonusMap($cfg)]);

  case 'price_set':
    // цена продажи в карточке
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $pr = (float)($b['price'] ?? -1); if ($pr < 0) fail('Неверная цена');
    db($cfg)->prepare('UPDATE products SET price = ?, updated = ? WHERE id = ?')->execute([$pr, time(), $id]); logit($cfg, $role, 'price_set', $id . '=' . $pr);
    out(['ok' => true]);

  case 'export':
    // полная выгрузка для переноса в будущую систему (только собственник)
    needRole($role, ['owner']);
    $p = db($cfg);
    out(['products' => $p->query('SELECT * FROM products')->fetchAll(), 'docs' => $p->query('SELECT * FROM docs')->fetchAll(), 'moves' => $p->query('SELECT * FROM moves')->fetchAll(), 'weather' => $p->query('SELECT * FROM weather')->fetchAll(), 'clients' => $p->query('SELECT * FROM clients')->fetchAll(), 'spins' => $p->query('SELECT * FROM spins')->fetchAll(), 'settings' => $p->query('SELECT * FROM settings')->fetchAll(), 'bookings' => $p->query('SELECT * FROM bookings')->fetchAll(), 'orders' => $p->query('SELECT id, day, body FROM orders ORDER BY day, t')->fetchAll(), 'days' => $p->query('SELECT * FROM days')->fetchAll(), 'expenses' => $p->query('SELECT * FROM expenses')->fetchAll()]);

  default:
    fail('Неизвестное действие', 404);
  }
} catch (Throwable $e) {
  error_log('montazh: ' . $e->getMessage());
  fail('Ошибка на сервере. Проверьте api.php?a=selftest', 500);
}
