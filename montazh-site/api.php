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

  case 'export':
    // полная выгрузка для переноса в будущую систему (только собственник)
    needRole($role, ['owner']);
    $p = db($cfg);
    out(['weather' => $p->query('SELECT * FROM weather')->fetchAll(), 'clients' => $p->query('SELECT * FROM clients')->fetchAll(), 'spins' => $p->query('SELECT * FROM spins')->fetchAll(), 'settings' => $p->query('SELECT * FROM settings')->fetchAll(), 'bookings' => $p->query('SELECT * FROM bookings')->fetchAll(), 'orders' => $p->query('SELECT id, day, body FROM orders ORDER BY day, t')->fetchAll(), 'days' => $p->query('SELECT * FROM days')->fetchAll(), 'expenses' => $p->query('SELECT * FROM expenses')->fetchAll()]);

  default:
    fail('Неизвестное действие', 404);
  }
} catch (Throwable $e) {
  error_log('montazh: ' . $e->getMessage());
  fail('Ошибка на сервере. Проверьте api.php?a=selftest', 500);
}
