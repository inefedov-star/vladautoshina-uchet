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
  return $pdo;
}
function logit(array $cfg, ?string $role, string $action, string $ref = ''): void {
  db($cfg)->prepare('INSERT INTO log VALUES (?,?,?,?)')->execute([time(), $role, $action, $ref]);
}
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '', true); return is_array($j) ? $j : []; }
function isDay($s): bool { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1; }
function needRole(?string $role, array $allowed): void { if (!$role) fail('Нужно войти', 401); if (!in_array($role, $allowed, true)) fail('Недостаточно прав', 403); }
function digits($s): string { return preg_replace('/\D/', '', (string)$s); }
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
function post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405); }

try {
  switch ($a) {

  case 'me':
    out(['role' => $role]);

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
    needRole($role, ['owner', 'master']);
    $day = $_GET['day'] ?? '';
    if (!isDay($day)) fail('Неверная дата');
    $st = db($cfg)->prepare('SELECT id, body FROM orders WHERE day = ? ORDER BY t');
    $st->execute([$day]);
    $orders = array_map(fn($r) => ['id' => (int)$r['id']] + json_decode($r['body'], true), $st->fetchAll());
    $h = db($cfg)->prepare('SELECT helper FROM days WHERE day = ?'); $h->execute([$day]);
    out(['orders' => $orders, 'helper' => (bool)$h->fetchColumn(), 'bookings' => bookingRows($cfg, 'day = ?', [$day])]);

  case 'helper':
    post(); needRole($role, ['owner', 'master']);
    $b = body(); if (!isDay($b['day'] ?? null)) fail('Неверная дата');
    db($cfg)->prepare('INSERT OR REPLACE INTO days (day, helper) VALUES (?, ?)')->execute([$b['day'], !empty($b['helper']) ? 1 : 0]);
    logit($cfg, $role, 'helper', $b['day'] . '=' . (!empty($b['helper']) ? 1 : 0));
    out(['ok' => true]);

  case 'save':
    post(); needRole($role, ['owner', 'master']);
    $o = body();
    if (!isDay($o['day'] ?? null) || !is_array($o['lines'] ?? null) || !count($o['lines'])) fail('Пустая запись');
    $list = 0; $h = 0;
    foreach ($o['lines'] as $l) { $q = max(0, (int)($l['q'] ?? 0)); $list += $q * (int)($l['p'] ?? 0); $h += $q * (int)($l['h'] ?? 0); }
    $o['list'] = $list; $o['h'] = min($h, $list);
    $id = isset($o['id']) ? (int)$o['id'] : 0; unset($o['id']);
    $prev = null;
    if ($id) { $s = db($cfg)->prepare('SELECT body FROM orders WHERE id = ?'); $s->execute([$id]); $prev = $s->fetchColumn(); if ($prev === false) fail('Запись уже удалена', 404); $prev = json_decode($prev, true); }
    if ($role === 'master') {
      // скидку и купон ставит только собственник; правка мастера их не сбрасывает
      $mode = $prev['mode'] ?? 'full';
      $o['mode'] = $mode;
      $o['paid'] = $mode === 'coupon' ? 0 : ($mode === 'disc' ? (int)($prev['paid'] ?? 0) : $list);
      if ($mode === 'full') $o['pay'] = in_array($o['pay'] ?? '', ['cash','card','transfer'], true) ? $o['pay'] : 'card';
    } else {
      $o['mode'] = in_array($o['mode'] ?? '', ['full','disc','coupon'], true) ? $o['mode'] : 'full';
      $o['paid'] = $o['mode'] === 'coupon' ? 0 : ($o['mode'] === 'disc' ? max(0, (int)($o['paid'] ?? 0)) : $list);
    }
    $o['t'] = (int)($prev['t'] ?? ($o['t'] ?? round(microtime(true) * 1000)));
    $o['phoneD'] = digits($o['phone'] ?? '');
    $o['sk'] = mb_strtoupper(trim(($o['name'] ?? '') . ' ' . ($o['plate'] ?? '')));
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

  case 'del':
    post(); needRole($role, ['owner', 'master']);
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
    $orders = array_map(function ($r) { $b = json_decode($r['body'], true); return ['day' => $r['day'], 'list' => (int)($b['list'] ?? 0), 'paid' => (int)($b['paid'] ?? 0), 'h' => (int)($b['h'] ?? 0)]; }, $st->fetchAll());
    $d = db($cfg)->prepare('SELECT day FROM days WHERE helper = 1 AND day BETWEEN ? AND ?'); $d->execute(["$y-01-01", "$y-12-31"]);
    $e = db($cfg)->prepare('SELECT day, sum, kind FROM expenses WHERE day BETWEEN ? AND ?'); $e->execute(["$y-01-01", "$y-12-31"]);
    out(['orders' => $orders, 'helperDays' => $d->fetchAll(PDO::FETCH_COLUMN), 'expenses' => array_map(fn($r) => ['day' => $r['day'], 'sum' => (int)$r['sum'], 'kind' => $r['kind']], $e->fetchAll())]);

  case 'book_save':
    post(); needRole($role, ['owner', 'master']);
    $b = body();
    if (!isDay($b['day'] ?? null) || !preg_match('/^\d{2}:\d{2}$/', (string)($b['tm'] ?? ''))) fail('Укажите день и время');
    $id = (int)($b['id'] ?? 0);
    $status = in_array($b['status'] ?? '', ['planned', 'done', 'noshow'], true) ? $b['status'] : 'planned';
    $keep = clean($b, ['name', 'phone', 'plate', 'note', 'car', 'preset']) + ['d' => isset($b['d']) ? (int)$b['d'] : null, 'est' => max(0, (int)($b['est'] ?? 0))];
    $keep['plate'] = mb_strtoupper($keep['plate']);
    $keep['phoneD'] = digits($keep['phone']);
    $keep['sk'] = mb_strtoupper(trim($keep['name'] . ' ' . $keep['plate']));
    $json = json_encode($keep, JSON_UNESCAPED_UNICODE);
    if ($id) db($cfg)->prepare('UPDATE bookings SET day = ?, tm = ?, status = ?, body = ?, updated = ? WHERE id = ?')->execute([$b['day'], $b['tm'], $status, $json, time(), $id]);
    else { db($cfg)->prepare('INSERT INTO bookings (day, tm, status, body, updated) VALUES (?,?,?,?,?)')->execute([$b['day'], $b['tm'], $status, $json, time()]); $id = (int)db($cfg)->lastInsertId(); }
    logit($cfg, $role, 'book_save', (string)$id);
    out(['id' => $id]);

  case 'book_del':
    post(); needRole($role, ['owner', 'master']);
    $id = (int)(body()['id'] ?? 0);
    db($cfg)->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
    logit($cfg, $role, 'book_del', (string)$id);
    out(['ok' => true]);

  case 'week':
    // ближайшие дни: сколько записей и на какую сумму
    needRole($role, ['owner', 'master']);
    $from = $_GET['from'] ?? ''; if (!isDay($from)) fail('Неверная дата');
    $to = date('Y-m-d', strtotime($from . ' +13 days'));
    try { $st = db($cfg)->prepare("SELECT day, COUNT(*) n, SUM(json_extract(body,'$.est')) est FROM bookings WHERE status = 'planned' AND day BETWEEN ? AND ? GROUP BY day"); $st->execute([$from, $to]); $rows = $st->fetchAll(); }
    catch (Throwable $e) { // старая SQLite без json_extract
      $rows = []; foreach (bookingRows($cfg, "status = 'planned' AND day BETWEEN ? AND ?", [$from, $to]) as $r) { $rows[$r['day']]['day'] = $r['day']; $rows[$r['day']]['n'] = ($rows[$r['day']]['n'] ?? 0) + 1; $rows[$r['day']]['est'] = ($rows[$r['day']]['est'] ?? 0) + (int)$r['est']; } $rows = array_values($rows); }
    out(['days' => array_map(fn($r) => ['day' => $r['day'], 'n' => (int)$r['n'], 'est' => (int)$r['est']], $rows)]);

  case 'clients':
    // поиск по телефону, номеру или имени — по машинам и записям
    needRole($role, ['owner', 'master']);
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) out(['orders' => [], 'bookings' => []]);
    $dq = digits($q);
    $like = '%' . str_replace(['%', '_'], ['', ''], mb_strtoupper($q)) . '%';
    $o = db($cfg)->prepare('SELECT id, day, body FROM orders WHERE body LIKE ? ' . (strlen($dq) >= 4 ? 'OR body LIKE ?' : '') . ' ORDER BY day DESC LIMIT 400');
    $o->execute(strlen($dq) >= 4 ? [$like, '%' . $dq . '%'] : [$like]);
    $orders = [];
    foreach ($o->fetchAll() as $r) {
      $b = json_decode($r['body'], true);
      $hay = mb_strtoupper(($b['name'] ?? '') . ' ' . ($b['plate'] ?? '')); $ph = digits($b['phone'] ?? '');
      if (mb_strpos($hay, mb_strtoupper($q)) === false && !(strlen($dq) >= 4 && strpos($ph, $dq) !== false)) continue;
      $orders[] = ['id' => (int)$r['id'], 'day' => $r['day'], 'name' => $b['name'] ?? '', 'phone' => $b['phone'] ?? '', 'plate' => $b['plate'] ?? '', 'car' => $b['car'] ?? '', 'd' => $b['d'] ?? null,
        'works' => implode(', ', array_map(fn($l) => $l['n'] . (($l['q'] ?? 1) > 1 ? ' ×' . $l['q'] : ''), $b['lines'] ?? [])), 'list' => (int)($b['list'] ?? 0), 'paid' => $role === 'owner' ? (int)($b['paid'] ?? 0) : null, 'mode' => $b['mode'] ?? 'full'];
    }
    $bk = array_values(array_filter(bookingRows($cfg, "body LIKE ?" . (strlen($dq) >= 4 ? " OR body LIKE ?" : ''), strlen($dq) >= 4 ? [$like, '%' . $dq . '%'] : [$like]), function ($b) use ($q, $dq) {
      return mb_strpos(mb_strtoupper(($b['name'] ?? '') . ' ' . ($b['plate'] ?? '')), mb_strtoupper($q)) !== false || (strlen($dq) >= 4 && strpos(digits($b['phone'] ?? ''), $dq) !== false); }));
    out(['orders' => $orders, 'bookings' => $bk]);

  case 'month':
    // для игры мастера: без сумм «получено» и расходов
    needRole($role, ['owner', 'master']);
    $m = $_GET['month'] ?? ''; if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Неверный месяц');
    $st = db($cfg)->prepare('SELECT day, body FROM orders WHERE day LIKE ?'); $st->execute([$m . '-%']);
    $orders = array_map(function ($r) { $b = json_decode($r['body'], true); $big = 0;
      foreach ($b['lines'] ?? [] as $l) if (in_array($l['k'] ?? '', ['cx', 'mount'], true)) $big = 1;
      return ['day' => $r['day'], 'list' => (int)($b['list'] ?? 0), 'h' => (int)($b['h'] ?? 0), 'd' => $b['d'] ?? null, 'car' => $b['car'] ?? '', 'mount' => $big]; }, $st->fetchAll());
    $d = db($cfg)->prepare('SELECT day FROM days WHERE helper = 1 AND day LIKE ?'); $d->execute([$m . '-%']);
    // лучший день мастера до этого месяца — для значка «Рекорд»
    $prev = db($cfg)->prepare('SELECT o.day, o.body, IFNULL(dd.helper,0) helper FROM orders o LEFT JOIN days dd ON dd.day = o.day WHERE o.day < ?'); $prev->execute([$m . '-01']);
    $byDay = [];
    foreach ($prev->fetchAll() as $r) { $b = json_decode($r['body'], true); $byDay[$r['day']] = ($byDay[$r['day']] ?? 0) + (int)($b['list'] ?? 0) - ($r['helper'] ? (int)($b['h'] ?? 0) : 0); }
    out(['orders' => $orders, 'helperDays' => $d->fetchAll(PDO::FETCH_COLUMN), 'bestBefore' => $byDay ? max($byDay) : 0]);

  case 'spins':
    needRole($role, ['owner', 'master']);
    $m = $_GET['month'] ?? ''; if (!preg_match('/^\d{4}-\d{2}$/', $m)) fail('Неверный месяц');
    out(spinState($cfg, $m));

  case 'spin':
    post(); needRole($role, ['owner', 'master']);
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

  case 'export':
    // полная выгрузка для переноса в будущую систему (только собственник)
    needRole($role, ['owner']);
    $p = db($cfg);
    out(['bookings' => $p->query('SELECT * FROM bookings')->fetchAll(), 'orders' => $p->query('SELECT id, day, body FROM orders ORDER BY day, t')->fetchAll(), 'days' => $p->query('SELECT * FROM days')->fetchAll(), 'expenses' => $p->query('SELECT * FROM expenses')->fetchAll()]);

  default:
    fail('Неизвестное действие', 404);
  }
} catch (Throwable $e) {
  error_log('montazh: ' . $e->getMessage());
  fail('Ошибка на сервере. Проверьте api.php?a=selftest', 500);
}
