<?php
/* Пульт шиномонтажа IVANOR — серверная часть.
   Хранит смены, расходы и отметку «с помощником» в SQLite.
   Проверка хостинга: откройте api.php?a=selftest в браузере. */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Moscow');
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
  // кто работал: мастер и помощник по именам (пусто — как в прошлую смену)
  $dcols = array_column($pdo->query('PRAGMA table_info(days)')->fetchAll(), 'name');
  if (!in_array('master', $dcols, true)) $pdo->exec("ALTER TABLE days ADD COLUMN master TEXT NOT NULL DEFAULT ''");
  if (!in_array('helper_name', $dcols, true)) $pdo->exec("ALTER TABLE days ADD COLUMN helper_name TEXT NOT NULL DEFAULT ''");
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
  // продажа/возврат: телефон покупателя, способ оплаты, ссылка возврата на продажу; fee не используется
  if (!in_array('phone', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN phone TEXT NOT NULL DEFAULT ''");
  if (!in_array('pay', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN pay TEXT NOT NULL DEFAULT ''");
  if (!in_array('base_id', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN base_id INTEGER");
  if (!in_array('fee', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN fee REAL NOT NULL DEFAULT 0");
  // зарезервировано (бонусы поставщика решили не учитывать отдельно, 02.11)
  if (!in_array('bonus', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN bonus REAL NOT NULL DEFAULT 0");
  // продажа по заказу клиента: при проведении заказ закрывается сам
  if (!in_array('zak_id', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN zak_id INTEGER");
  // продажа, оформленная продавцом на витрине: кто оформил и что ждёт подтверждения собственника
  if (!in_array('seller', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN seller TEXT NOT NULL DEFAULT ''");
  if (!in_array('req', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN req INTEGER NOT NULL DEFAULT 0");
  $mcols = array_column($pdo->query('PRAGMA table_info(moves)')->fetchAll(), 'name');
  // цена по прайсу на момент продажи — чтобы видеть скидку
  if (!in_array('list', $mcols, true)) $pdo->exec("ALTER TABLE moves ADD COLUMN list REAL");
  $pdo->exec('CREATE TABLE IF NOT EXISTS weather (day TEXT PRIMARY KEY, tmax REAL, tmin REAL, prec REAL, code INTEGER, updated INTEGER)');
  // коды поставщиков: «у этого поставщика код X — это наш товар Y» (запоминается при загрузке документов)
  $pdo->exec('CREATE TABLE IF NOT EXISTS supcodes (pk TEXT NOT NULL, code TEXT NOT NULL, product_id INTEGER NOT NULL, updated INTEGER, PRIMARY KEY (pk, code))');
  $pdo->exec('CREATE TABLE IF NOT EXISTS photos (k TEXT PRIMARY KEY, file TEXT NOT NULL, source TEXT, src_url TEXT, updated INTEGER)');
  // заказы клиентов (раньше — листок): что заказали, сумма и аванс, у кого везём, обещанный шиномонтаж
  $pdo->exec('CREATE TABLE IF NOT EXISTS zak (id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL, phone TEXT NOT NULL DEFAULT \'\', name TEXT NOT NULL DEFAULT \'\', items TEXT NOT NULL DEFAULT \'\', total REAL NOT NULL DEFAULT 0, prepay REAL NOT NULL DEFAULT 0, supplier TEXT NOT NULL DEFAULT \'\', promo TEXT NOT NULL DEFAULT \'\', note TEXT NOT NULL DEFAULT \'\', status TEXT NOT NULL DEFAULT \'wait\', created INTEGER, updated INTEGER, closed INTEGER)');
  // архив продаж из отчётов CloudShop «Продажи по товарам» за выбранный период — для аналитики и советов
  $pdo->exec('CREATE TABLE IF NOT EXISTS hist (id INTEGER PRIMARY KEY AUTOINCREMENT, pfrom TEXT NOT NULL, pto TEXT NOT NULL, name TEXT NOT NULL, barcode TEXT, article TEXT, qty REAL NOT NULL DEFAULT 0, checks REAL NOT NULL DEFAULT 0, rev REAL NOT NULL DEFAULT 0, cost REAL NOT NULL DEFAULT 0, product_id INTEGER, kind TEXT, gkey TEXT, created INTEGER)');
  if (!in_array('ext', array_column($pdo->query('PRAGMA table_info(hist)')->fetchAll(), 'name'), true)) $pdo->exec('ALTER TABLE hist ADD COLUMN ext TEXT');
  // архив CloudShop: строки документов до даты перехода (на остатки не влияют) и контрагенты
  $pdo->exec('CREATE TABLE IF NOT EXISTS arch (id INTEGER PRIMARY KEY AUTOINCREMENT, ym TEXT NOT NULL, day TEXT NOT NULL, doc TEXT, num TEXT, type TEXT NOT NULL, party_id TEXT, party TEXT, phone TEXT, pid INTEGER, ext TEXT, name TEXT, qty REAL NOT NULL DEFAULT 0, price REAL NOT NULL DEFAULT 0, cost REAL NOT NULL DEFAULT 0, pay TEXT, evotor TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS arch_day ON arch(day)'); $pdo->exec('CREATE INDEX IF NOT EXISTS arch_ym ON arch(ym)'); $pdo->exec('CREATE INDEX IF NOT EXISTS arch_pty ON arch(party_id)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS arch_party (id TEXT PRIMARY KEY, kind TEXT, name TEXT, phones TEXT, note TEXT)');
  // CAE — код товара у «Пауэр Интернэшнл-шины» (Форточки): по нему узнаём наши карточки в их документах
  $pcols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(), 'name');
  if (!in_array('cae', $pcols, true)) $pdo->exec("ALTER TABLE products ADD COLUMN cae TEXT NOT NULL DEFAULT ''");
  // справочник номенклатуры Пауэра (их открытая выгрузка Tires.xml / Rims.xml) — для подбора CAE
  $pdo->exec('CREATE TABLE IF NOT EXISTS p4t (cae TEXT PRIMARY KEY, kind TEXT NOT NULL, brand TEXT, model TEXT, name TEXT, w REAL, p REAL, d REAL, c INTEGER NOT NULL DEFAULT 0, li TEXT, stud INTEGER NOT NULL DEFAULT 0, pcd TEXT, et REAL, dia REAL, color TEXT)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS p4t_size ON p4t(kind, d, w)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS clients (phone TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT \'\', model TEXT NOT NULL DEFAULT \'\', updated INTEGER)');
  // CRM: профиль клиента, его машины и касания (звонки, сообщения)
  $ccols = array_column($pdo->query('PRAGMA table_info(clients)')->fetchAll(), 'name');
  foreach (['phone2' => "TEXT NOT NULL DEFAULT ''", 'bday' => "TEXT NOT NULL DEFAULT ''", 'note' => "TEXT NOT NULL DEFAULT ''", 'src' => "TEXT NOT NULL DEFAULT ''", 'tags' => "TEXT NOT NULL DEFAULT ''", 'consent' => 'INTEGER NOT NULL DEFAULT 0', 'created' => 'INTEGER'] as $c => $t)
    if (!in_array($c, $ccols, true)) $pdo->exec("ALTER TABLE clients ADD COLUMN $c $t");
  $pdo->exec('CREATE TABLE IF NOT EXISTS client_cars (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL, car TEXT NOT NULL DEFAULT \'\', plate TEXT NOT NULL DEFAULT \'\', year TEXT NOT NULL DEFAULT \'\', size TEXT NOT NULL DEFAULT \'\', note TEXT NOT NULL DEFAULT \'\', created INTEGER)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS client_cars_ph ON client_cars(phone)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS crm_touch (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL, day TEXT NOT NULL, kind TEXT NOT NULL DEFAULT \'\', why TEXT NOT NULL DEFAULT \'\', note TEXT NOT NULL DEFAULT \'\', author TEXT, created INTEGER)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS crm_touch_ph ON crm_touch(phone)');
  // организации клиента: покупки на юрлицо (ООО, ИП) считаем его покупками
  $pdo->exec('CREATE TABLE IF NOT EXISTS client_orgs (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT NOT NULL, org TEXT NOT NULL, okey TEXT NOT NULL, created INTEGER)');
  $pdo->exec('CREATE INDEX IF NOT EXISTS client_orgs_k ON client_orgs(okey)');
  // роль человека в организации: owner — владелец (покупки фирмы — его), contact — представитель (водитель, менеджер)
  $ocols = array_column($pdo->query('PRAGMA table_info(client_orgs)')->fetchAll(), 'name');
  if (!in_array('role', $ocols, true)) $pdo->exec("ALTER TABLE client_orgs ADD COLUMN role TEXT NOT NULL DEFAULT 'owner'");
  // организация как клиент сама по себе (в т.ч. без владельца-клиента): реквизиты и заметка
  $pdo->exec('CREATE TABLE IF NOT EXISTS orgs (okey TEXT PRIMARY KEY, name TEXT NOT NULL, inn TEXT NOT NULL DEFAULT \'\', note TEXT NOT NULL DEFAULT \'\', created INTEGER)');
  // реквизиты и итоги из Контур.Эльбы (список контрагентов): kind — client / supplier / other (налоговая, банк)
  $gcols = array_column($pdo->query('PRAGMA table_info(orgs)')->fetchAll(), 'name');
  foreach (['kpp' => "TEXT NOT NULL DEFAULT ''", 'addr' => "TEXT NOT NULL DEFAULT ''", 'contact' => "TEXT NOT NULL DEFAULT ''", 'ophone' => "TEXT NOT NULL DEFAULT ''", 'email' => "TEXT NOT NULL DEFAULT ''",
    'kind' => "TEXT NOT NULL DEFAULT 'client'", 'elba_in' => 'REAL NOT NULL DEFAULT 0', 'elba_out' => 'REAL NOT NULL DEFAULT 0', 'elba_debt' => 'REAL NOT NULL DEFAULT 0', 'src' => "TEXT NOT NULL DEFAULT ''", 'upd' => 'INTEGER'] as $c => $t)
    if (!in_array($c, $gcols, true)) $pdo->exec("ALTER TABLE orgs ADD COLUMN $c $t");
  foreach ($pdo->query('SELECT okey, name FROM orgs')->fetchAll() as $o) if (orgJunk($o['name'])) $pdo->prepare('DELETE FROM orgs WHERE okey = ?')->execute([$o['okey']]);
  // все известные организации — чтобы «АДМИНИСТРАЦИЯ …», «АНО …» и т.п. узнавались как организации
  $GLOBALS['ORGK'] = array_flip(array_filter($pdo->query('SELECT okey FROM orgs')->fetchAll(PDO::FETCH_COLUMN), fn($k) => !preg_match('/^\d+$/', (string)$k)));
  // планировщик задач: кому поручено и к какому дню
  // сверка: чеки из ОФД и операции из выписки банка (загружаются файлами)
  $pdo->exec("CREATE TABLE IF NOT EXISTS ofd (fd TEXT PRIMARY KEY, day TEXT NOT NULL, tm TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'sale', total REAL NOT NULL DEFAULT 0, cash REAL NOT NULL DEFAULT 0, cashless REAL NOT NULL DEFAULT 0, shift TEXT NOT NULL DEFAULT '')");
  $pdo->exec('CREATE INDEX IF NOT EXISTS ofd_day ON ofd(day)');
  $pdo->exec("CREATE TABLE IF NOT EXISTS bank (k TEXT PRIMARY KEY, acc TEXT NOT NULL DEFAULT '', day TEXT NOT NULL, dir TEXT NOT NULL, amount REAL NOT NULL, kind TEXT NOT NULL, ref_day TEXT NOT NULL DEFAULT '', fee REAL NOT NULL DEFAULT 0, payer TEXT NOT NULL DEFAULT '', inn TEXT NOT NULL DEFAULT '', purpose TEXT NOT NULL DEFAULT '', inv_no TEXT NOT NULL DEFAULT '', term TEXT NOT NULL DEFAULT '')");
  $pdo->exec('CREATE INDEX IF NOT EXISTS bank_day ON bank(day)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, note TEXT NOT NULL DEFAULT \'\', who TEXT NOT NULL DEFAULT \'\', due TEXT NOT NULL DEFAULT \'\', prio INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT \'open\', phone TEXT NOT NULL DEFAULT \'\', created INTEGER, done_at INTEGER, author TEXT)');
  return $pdo;
}
function logit(array $cfg, ?string $role, string $action, string $ref = ''): void {
  db($cfg)->prepare('INSERT INTO log VALUES (?,?,?,?)')->execute([time(), $role, $action, $ref]);
}
function body(): array { $j = json_decode(file_get_contents('php://input') ?: '', true); return is_array($j) ? $j : []; }
function isDay($s): bool { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1; }
function needRole(?string $role, array $allowed): void { if (!$role) fail('Нужно войти', 401); if (!in_array($role, $allowed, true)) fail('Недостаточно прав', 403); }
/* шинный отель: та же база, что у sklad.shinhotel33.ru; пульт её только читает */
require_once __DIR__ . '/hotel_path.php';
function hotelPdo(array $cfg): ?PDO {
  static $h = false; if ($h !== false) return $h;
  $d = hotelDir($cfg); if ($d === '') return $h = null;
  try { $h = new PDO('sqlite:' . $d . '/sklad.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); $h->exec('PRAGMA busy_timeout=4000'); }
  catch (Throwable $e) { $h = null; }
  return $h;
}
function storRow(array $r): array {
  return ['id' => (int)$r['id'], 'num' => (int)$r['num'], 'season' => (string)$r['season'], 'kind' => (string)$r['kind'], 'name' => (string)$r['client'], 'phone' => (string)$r['phone_digits'],
    'size' => (string)$r['size'], 'descr' => (string)$r['descr'], 'qty' => (int)$r['qty'], 'cell' => (string)$r['cell'], 'day' => substr((string)$r['accepted_at'], 0, 10), 'due' => (string)$r['due_at'], 'status' => (string)$r['status'], 'issued' => substr((string)$r['issued_at'], 0, 10), 'paid' => (int)$r['paid']];
}
/* розничная цена по закупке: шины +23%, диски +20%, АКБ +30%, вниз до десятков (так же считает страница) */
function retailPrice(string $kind, float $cost): float { $mk = ['tire' => 23, 'disk' => 20, 'akb' => 30][$kind] ?? null; return $mk === null || $cost <= 0 ? 0.0 : floor($cost * (1 + $mk / 100) / 10) * 10; }
/* ===== резервные копии: раз в сутки сами, на хостинге в data/backup (закрыто от браузера) =====
   montazh-ДАТА.sqlite.gz — пульт (магазин, шиномонтаж, заказы, клиенты);
   hotel-ДАТА.db.gz — шинный отель; kp-ДАТА.json.gz — сохранённые КП.
   Хранятся 30 последних дней и копия за 1-е число каждого месяца. */
function backupDir(array $cfg): string { $d = dirname($cfg['db_path']) . '/backup'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function sqliteSnapshot(string $file): ?string {
  if (!is_file($file)) return null;
  try { $x = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $x->exec('PRAGMA busy_timeout=5000');
    try { $x->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll(); } catch (Throwable $e) {}
    $x->beginTransaction(); $x->query('SELECT COUNT(*) FROM sqlite_master')->fetchColumn(); $raw = file_get_contents($file); $x->commit(); return $raw === false ? null : $raw; }
  catch (Throwable $e) { $raw = @file_get_contents($file); return $raw === false ? null : $raw; }
}
function backupNow(array $cfg, bool $force = false): array {
  $dir = backupDir($cfg); $day = date('Y-m-d'); $f = $dir . '/montazh-' . $day . '.sqlite.gz'; $made = [];
  if (!$force && is_file($f)) return [];
  if (($raw = sqliteSnapshot($cfg['db_path'])) !== null && @file_put_contents($f, gzencode($raw, 6)) !== false) $made[] = basename($f);
  $hd = hotelDir($cfg); if ($hd !== '' && ($raw = sqliteSnapshot($hd . '/sklad.db')) !== null && @file_put_contents($dir . '/hotel-' . $day . '.db.gz', gzencode($raw, 6)) !== false) $made[] = 'hotel-' . $day . '.db.gz';
  $kd = dirname(__DIR__) . '/kp/data'; if (is_dir($kd)) { $all = []; foreach ((array)glob($kd . '/*.json') as $j) $all[basename($j)] = @file_get_contents($j); if ($all && @file_put_contents($dir . '/kp-' . $day . '.json.gz', gzencode(json_encode($all, JSON_UNESCAPED_UNICODE), 6)) !== false) $made[] = 'kp-' . $day . '.json.gz'; }
  // чистка: старше 30 дней удаляем, кроме копий за 1-е число
  foreach ((array)glob($dir . '/*-????-??-??.*') as $old) if (preg_match('/-(\d{4}-\d{2}-(\d{2}))\./', basename($old), $m) && $m[2] !== '01' && strtotime($m[1]) < time() - 30 * 86400) @unlink($old);
  // по желанию — раз в неделю копия пульта на почту (config.php: 'backup_email' => 'адрес')
  if ($made && !empty($cfg['backup_email']) && date('N') === '1' && is_file($f) && function_exists('mail')) {
    $bd = md5((string)time()); $body = "--$bd\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nРезервная копия пульта IVANOR за $day.\r\n--$bd\r\nContent-Type: application/gzip; name=\"" . basename($f) . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . basename($f) . "\"\r\n\r\n" . chunk_split(base64_encode((string)file_get_contents($f))) . "--$bd--";
    @mail((string)$cfg['backup_email'], '=?UTF-8?B?' . base64_encode('Резервная копия пульта ' . $day) . '?=', $body, "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$bd\""); }
  return $made;
}
/* ===== аналитика ===== */
// группа для советов: шины — размер и сезон, диски — диаметр и разболтовка, АКБ — ёмкость и полярность; остальное — сама позиция
function groupOf(string $kind, array $a, string $name): array {
  if ($kind === 'tire' && !empty($a['w'])) { $s = ($a['season'] ?? '') === 'winter' ? (!empty($a['stud']) ? 'зима, шипы' : 'зима, липучка') : (($a['season'] ?? '') === 'summer' ? 'лето' : (($a['season'] ?? '') === 'all' ? 'всесезон' : ''));
    return ['tire', $a['w'] . '/' . $a['p'] . ' R' . $a['d'] . (!empty($a['c']) ? 'C' : '') . ($s ? ' · ' . $s : ''), (int)$a['d'], ($a['season'] ?? '')]; }
  if ($kind === 'disk' && !empty($a['d'])) { $pc = (array)($a['pcds'] ?? []); return ['disk', 'R' . $a['d'] . ' ' . ($pc ? $pc[0] : '') . (($a['type'] ?? '') === 'steel' ? ' · штамп.' : ''), (int)$a['d'], '']; }
  if ($kind === 'akb' && !empty($a['ah'])) return ['akb', $a['ah'] . ' А·ч · ' . (($a['pol'] ?? '') === 'dir' ? 'прямая' : 'обратная') . (($a['std'] ?? '') === 'asia' ? ' · Азия' : ''), 0, ''];
  // архив без карточки: шину узнаём по размеру в названии
  if ($kind === '' && ($t = tireSize($name))) { $st = preg_match('/шип/ui', $name) ? 'зима, шипы' : ''; return ['tire', $t['w'] . '/' . $t['p'] . ' R' . $t['d'] . ($st ? ' · ' . $st : ''), $t['d'], $st ? 'winter' : '']; }
  if ($kind === '' && preg_match('/^Аккумулятор/ui', $name)) return ['akb', trim(mb_substr($name, 0, 60)), 0, ''];
  return [$kind ?: 'other', trim(mb_substr($name, 0, 80)), 0, ''];
}
// до какой даты включительно итоги берутся из архива CloudShop (дальше — операции пульта)
function getSetting(array $cfg, string $k): ?string { $st = db($cfg)->prepare('SELECT v FROM settings WHERE k = ?'); $st->execute([$k]); $v = $st->fetchColumn(); return $v === false ? null : (string)$v; }
function setSetting(array $cfg, string $k, string $v): void { db($cfg)->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]); }
function archUntil(array $cfg): string { $v = db($cfg)->query("SELECT v FROM settings WHERE k = 'arch_until'")->fetchColumn(); return $v ? (string)$v : ''; }
// карточки пульта для сопоставления: по ID CloudShop, штрихкоду, артикулу, названию
function prodIndex(array $cfg): array { $I = ['ext' => [], 'bc' => [], 'ar' => [], 'nm' => [], 'p' => []];
  foreach (db($cfg)->query('SELECT id, ext_id, name, kind, attrs, barcode, article FROM products')->fetchAll() as $r) { $I['p'][(int)$r['id']] = $r; if ($r['ext_id']) $I['ext'][$r['ext_id']] = (int)$r['id']; if ($r['barcode'] !== '') $I['bc'][ltrim($r['barcode'], '0')] = (int)$r['id']; if ($r['article'] !== '') $I['ar'][mb_strtoupper($r['article'])] = (int)$r['id']; $I['nm'][mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $r['name'])))] = (int)$r['id']; }
  return $I; }
function prodMatch(array $I, string $ext, string $bc, string $ar, string $nm): ?int { return $I['ext'][$ext] ?? ($bc !== '' ? ($I['bc'][ltrim($bc, '0')] ?? null) : null) ?? ($ar !== '' ? ($I['ar'][mb_strtoupper($ar)] ?? null) : null) ?? ($I['nm'][mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $nm)))] ?? null); }
function kindGroup(string $k): string { return in_array($k, ['tire', 'disk', 'akb', 'fast'], true) ? $k : 'other'; }
function zakRow(array $r, ?string $role): array {
  $x = ['id' => (int)$r['id'], 'day' => $r['day'], 'phone' => $r['phone'], 'name' => $r['name'], 'items' => $r['items'], 'total' => (float)$r['total'], 'prepay' => (float)$r['prepay'], 'promo' => $r['promo'], 'note' => $r['note'], 'status' => $r['status'], 'supplier' => $r['supplier']];
  if ($role !== 'owner') unset($x['supplier']);
  return $x;
}
function digits($s): string { return preg_replace('/\D/', '', (string)$s); }
// телефон — основа клиента: храним как 7XXXXXXXXXX
function phoneKey($s): string { $d = digits($s); if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1); if (strlen($d) === 10) $d = '7' . $d; return (strlen($d) === 11 && $d[0] === '7') ? $d : ''; }
function touchClient(array $cfg, string $key, string $name, string $model, string $plate = ''): void {
  if ($key === '') return;
  carRemember($cfg, $key, $model, $plate);
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
function docLabel(string $t): string { return ['open' => 'Ввод остатков', 'in' => 'Приход', 'iss' => 'Выдача с ответхранения', 'sale' => 'Продажа', 'ret' => 'Возврат', 'off' => 'Списание', 'inv' => 'Корректировка остатков', 'rin' => 'Возврат поставщику'][$t] ?? $t; }
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
// быстрый выбор в каталоге (как на витрине): значения полей товара по видам
function facetVals(string $kind, array $a): array {
  if ($kind === 'tire') { $s = $a['season'] ?? 'all'; $sv = $s === 'winter' ? ['winter', !empty($a['stud']) ? 'stud' : 'fric'] : [$s];
    return ['d' => isset($a['d']) ? [(int)$a['d']] : [], 'w' => isset($a['w']) ? [(int)$a['w']] : [], 'p' => isset($a['p']) ? [(int)$a['p']] : [], 's' => $sv]; }
  if ($kind === 'disk') return ['k' => [$a['type'] ?? 'cast'], 'd' => isset($a['d']) ? [(int)$a['d']] : [], 'pcd' => array_values(array_filter((array)($a['pcds'] ?? [])))];
  if ($kind === 'akb') { $ah = (int)($a['ah'] ?? 0); $cap = !$ah ? [] : [$ah <= 55 ? 's' : ($ah <= 65 ? 'm' : ($ah <= 80 ? 'l' : ($ah <= 110 ? 'xl' : 'xxl')))];
    return ['std' => [$a['std'] ?? 'euro'], 'pol' => !empty($a['pol']) ? [$a['pol']] : [], 'cap' => $cap]; }
  if ($kind === 'fast') return ['k' => [$a['type'] ?? 'other'], 'th' => !empty($a['thread']) ? [$a['thread']] : []];
  return [];
}
// ===== фото товаров: одно фото на модель (шины — бренд+модель+шипы, диски — модель), остальное — на карточку =====
function photoKey(string $kind, array $a, string $name, int $id): string {
  $U = fn($x) => trim(preg_replace('/\s+/u', ' ', mb_strtoupper((string)$x)));
  if ($kind === 'tire' && !empty($a['brand'])) return 'tire|' . $U($a['brand']) . '|' . $U($a['model'] ?? '') . '|' . (!empty($a['stud']) ? 'S' : '');
  if ($kind === 'disk' && !empty($a['model'])) return 'disk|' . $U(preg_replace('/\([^)]*\)/u', '', $a['model']));
  if ($kind === 'akb') return 'akb|' . $U(preg_replace('/^Аккумулятор\s*|\b(о\/п|п\/п|обр\.?|прям\.?)\b/ui', ' ', $name));
  return 'p|' . $id;
}
function photoLabel(string $kind, array $a, string $name): string {
  if ($kind === 'tire' && !empty($a['brand'])) return trim($a['brand'] . ' ' . ($a['model'] ?? '') . (!empty($a['stud']) ? ' (шип.)' : ''));
  if ($kind === 'disk' && !empty($a['model'])) return trim(preg_replace('/\([^)]*\)/u', '', $a['model']));
  return $name;
}
function photoDir(): string { $d = __DIR__ . '/photos'; if (!is_dir($d)) { @mkdir($d, 0755, true); }
  // в папке фото не должно исполняться ничего, кроме картинок. Без «Options»: на части хостингов он даёт ошибку 500
  $ht = "# v2\n<FilesMatch \"(?i)\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</FilesMatch>\n";
  if (@file_get_contents($d . '/.htaccess') !== $ht) @file_put_contents($d . '/.htaccess', $ht);
  return $d; }
function photoMap(array $cfg): array { $o = []; foreach (db($cfg)->query('SELECT k, file, source, updated FROM photos')->fetchAll() as $r) $o[$r['k']] = ['url' => 'photos/' . $r['file'] . '?v=' . $r['updated'], 'source' => $r['source']]; return $o; }
// принимает байты картинки, уменьшает до 900 px и сохраняет как jpg (если на хостинге есть GD), иначе — как есть
function photoSave(array $cfg, string $key, string $bytes, string $source, string $srcUrl = ''): string {
  if (strlen($bytes) < 200) fail('Это не картинка'); if (strlen($bytes) > 12 * 1024 * 1024) fail('Файл больше 12 МБ');
  $info = @getimagesizefromstring($bytes); if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) fail('Нужна картинка: jpg, png или webp');
  $name = substr(sha1($key), 0, 16); $dir = photoDir(); $ext = 'jpg'; $out = null;
  if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bytes))) {
    $w = imagesx($im); $h = imagesy($im); $k = min(1, 900 / max($w, $h)); $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
    $dst = imagecreatetruecolor($nw, $nh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); ob_start(); imagejpeg($dst, null, 84); $out = ob_get_clean(); imagedestroy($im); imagedestroy($dst);
  }
  if ($out === null) { $out = $bytes; $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2]]; }
  foreach (glob($dir . '/' . $name . '.*') ?: [] as $old) @unlink($old);
  if (@file_put_contents($dir . '/' . $name . '.' . $ext, $out) === false) fail('Не удалось сохранить файл — проверьте права на папку photos', 500);
  db($cfg)->prepare('INSERT OR REPLACE INTO photos (k, file, source, src_url, updated) VALUES (?,?,?,?,?)')->execute([$key, $name . '.' . $ext, $source, $srcUrl, time()]);
  return 'photos/' . $name . '.' . $ext . '?v=' . time();
}
function fetchUrl(string $url): string {
  if (!preg_match('~^https?://~i', $url)) fail('Ссылка должна начинаться с http:// или https://');
  $ua = 'Mozilla/5.0 (IVANOR catalog photo loader)';
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => $ua, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    $b = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); if ($b === false || $code >= 400) fail('Не удалось скачать картинку по ссылке (' . ($code ?: 'нет связи') . ')'); return $b; }
  $b = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'user_agent' => $ua]])); if ($b === false) fail('Не удалось скачать картинку по ссылке'); return $b;
}
// ===== API поставщика «Форточки» (b2b.4tochki.ru): подбор фото по модели =====
function api4t(array $cfg): SoapClient {
  if (empty($cfg['api4t_login']) || empty($cfg['api4t_password'])) fail('Не заданы api4t_login и api4t_password в config.php');
  if (!class_exists('SoapClient')) fail('На хостинге нет модуля PHP SOAP — нужно включить его в панели Джино (расширения PHP)', 500);
  ini_set('default_socket_timeout', '25');
  try { return new SoapClient($cfg['api4t_wsdl'] ?? 'http://api-b2b.4tochki.ru/WCF/ClientService.svc?wsdl', ['exceptions' => true, 'connection_timeout' => 20, 'cache_wsdl' => WSDL_CACHE_DISK, 'trace' => false]); }
  catch (Throwable $e) { fail('Не удалось подключиться к API поставщика: ' . $e->getMessage(), 502); }
}
// обход любого ответа: собираем «товары» — объекты, где есть название/модель и ссылки на картинки
function api4tItems($x, array &$out, int $depth = 0): void {
  if ($depth > 8 || count($out) > 400) return;
  if (is_object($x)) $x = get_object_vars($x);
  if (!is_array($x)) return;
  $imgs = []; $txt = [];
  foreach ($x as $k => $v) {
    $u = is_string($v) ? trim($v) : ''; if (strpos($u, '//') === 0) $u = 'https:' . $u;
    if ($u !== '' && preg_match('~^https?://\S+$~i', $u) && (preg_match('~\.(jpe?g|png|webp|gif)(\?\S*)?$~i', $u) || preg_match('/img|image|photo|foto|pic/i', (string)$k))) $imgs[(string)$k] = $u;
    elseif (is_string($v) && preg_match('/name|model|brand|marka|title/i', (string)$k)) $txt[(string)$k] = $v;
  }
  if ($imgs && $txt) $out[] = ['txt' => $txt, 'imgs' => $imgs];
  foreach ($x as $v) if (is_array($v) || is_object($v)) api4tItems($v, $out, $depth + 1);
}
function normModel(string $s): string { return trim(preg_replace('/[^0-9A-ZА-ЯЁ]+/u', ' ', mb_strtoupper($s))); }
// ===== CRM: клиент = телефон. Профиль, машины, касания + сводка по всем разделам =====
// госномер для сравнения: латиница-двойники → кириллица, без пробелов
function plateKey($s): string { $s = mb_strtoupper(preg_replace('/[^0-9A-Za-zА-Яа-яЁё]/u', '', (string)$s)); return strtr($s, ['A' => 'А', 'B' => 'В', 'E' => 'Е', 'K' => 'К', 'M' => 'М', 'H' => 'Н', 'O' => 'О', 'P' => 'Р', 'C' => 'С', 'T' => 'Т', 'Y' => 'У', 'X' => 'Х']); }
// название организации для сравнения: без ООО/ИП/кавычек и знаков — «ООО "Ресурс-АД"» = «РЕСУРС АД»
function orgKey($s): string { $s = mb_strtoupper(str_replace(['Ё', 'ё'], ['Е', 'е'], (string)$s)); $s = preg_replace('/(^|[\s"«»\'(])(ООО|ОАО|ЗАО|ПАО|АО|ИП|ГБУЗ|ГБУ|МБУ|МУП|ГУП|ФГУП|СПК|ТОО|НКО|LLC|ООО\.)(?=[\s"«»\')]|$)/u', ' ', $s); return preg_replace('/[^0-9A-ZА-Я]+/u', '', $s); }
// служебные «покупатели» CloudShop и Эльбы — не организации и не клиенты
function orgJunk($s): bool { $k = orgKey($s); return $k === '' || preg_match('/^\d+$/', $k) || strpos($k, 'ЧАСТНОЕЛИЦО') === 0 || in_array($k, ['ПОСТОЯННЫЙКЛИЕНТ', 'РОЗНИЧНЫЙПОКУПАТЕЛЬ', 'ЮРИДИЧЕСКОЕЛИЦО', 'ФИЗИЧЕСКОЕЛИЦО', 'ПОКУПАТЕЛЬ', 'КЛИЕНТ', 'AVITOАВИТО', 'AVITO', 'АВИТО', 'OZON', 'ОЗОН', 'WILDBERRIES', 'ЯНДЕКСМАРКЕТ'], true); }
// похоже на организацию: ООО, ИП, АО и т.п.
function isOrgName($s): bool { $u = mb_strtoupper((string)$s); if (orgJunk($s)) return false;
  if (preg_match('/(^|[\s"«(])(ООО|ОАО|ЗАО|ПАО|АО|НАО|ИП|ГБУЗ|ГБУЗВО|ГБУ|ГБУСО|ГБУСОВО|ГБОУ|ГБПОУ|ГАУ|ГАУВО|ГКУ|МБУ|МБОУ|МБДОУ|МАДОУ|МАУ|МКУ|МУП|ГУП|ФГУП|ФГБУ|ФКУ|СПК|ТОО|АНО|НКО|КФХ|ТСЖ|ТСН|LLC)(?=[\s"«»)]|$)/u', $u)) return true;
  if (preg_match('/^(АДМИНИСТРАЦИЯ|УПРАВЛЕНИЕ|КОМИТЕТ|ДЕПАРТАМЕНТ|ФИЛИАЛ|КОЛХОЗ|СЕЛЬСКОХОЗЯЙСТВЕННЫЙ|ВЛАДИМИРСКИЙ ФИЛИАЛ)\b/u', $u)) return true;
  $k = orgKey($s); return $k !== '' && isset($GLOBALS['ORGK'][$k]); }
// организация — клиенту как представителю (если ещё ни за кем не числится); владельцем его отмечают в CRM
function orgLink(array $cfg, string $phone, string $org): void { $ok = orgKey($org); if ($phone === '' || $ok === '' || orgJunk($org)) return; $ex = db($cfg)->prepare('SELECT 1 FROM client_orgs WHERE okey = ?'); $ex->execute([$ok]);
  if (!$ex->fetchColumn()) db($cfg)->prepare("INSERT INTO client_orgs (phone, org, okey, created, role) VALUES (?,?,?,?, 'contact')")->execute([$phone, mb_substr(trim($org), 0, 200), $ok, time()]); }
function carKey($s): string { return mb_strtoupper(preg_replace('/[^0-9A-Za-zА-Яа-яЁё]/u', '', (string)$s)); }
// машина из визита/записи — в список машин клиента (если такой ещё нет)
function carRemember(array $cfg, string $phone, string $model, string $plate): void {
  $model = trim($model); $plate = mb_strtoupper(trim($plate)); if ($phone === '' || ($model === '' && $plate === '')) return;
  $p = db($cfg); $st = $p->prepare('SELECT id, car, plate FROM client_cars WHERE phone = ?'); $st->execute([$phone]); $cars = $st->fetchAll();
  $pk = plateKey($plate); $mk = carKey($model);
  foreach ($cars as $c) {
    if ($pk !== '' && plateKey($c['plate']) === $pk) { if ($mk !== '' && trim($c['car']) === '') $p->prepare('UPDATE client_cars SET car = ? WHERE id = ?')->execute([$model, $c['id']]); return; }
    if ($mk !== '' && carKey($c['car']) === $mk && ($pk === '' || trim($c['plate']) === '')) { if ($pk !== '') $p->prepare('UPDATE client_cars SET plate = ? WHERE id = ?')->execute([$plate, $c['id']]); return; }
    if ($mk === '' && $pk !== '' && trim($c['plate']) === '' && count($cars) === 1) { $p->prepare('UPDATE client_cars SET plate = ? WHERE id = ?')->execute([$plate, $c['id']]); return; }
  }
  $p->prepare('INSERT INTO client_cars (phone, car, plate, year, size, note, created) VALUES (?,?,?,?,?,?,?)')->execute([$phone, mb_substr($model, 0, 100), mb_substr($plate, 0, 20), '', '', '', time()]);
}
// мастер и помощник по дням: имя держится, пока его не поменяют (как в прошлую смену)
function staffDays(array $cfg, string $from, string $to): array {
  $p = db($cfg); $m = ''; $h = '';
  $st = $p->prepare("SELECT master, helper_name FROM days WHERE day < ? AND master != '' ORDER BY day DESC LIMIT 1"); $st->execute([$from]); if ($r = $st->fetch()) $m = $r['master'];
  $st = $p->prepare("SELECT helper_name FROM days WHERE day < ? AND helper_name != '' ORDER BY day DESC LIMIT 1"); $st->execute([$from]); if ($r = $st->fetch()) $h = $r['helper_name'];
  $rows = []; $st = $p->prepare('SELECT day, helper, master, helper_name FROM days WHERE day BETWEEN ? AND ? ORDER BY day'); $st->execute([$from, $to]); foreach ($st->fetchAll() as $r) $rows[$r['day']] = $r;
  $out = []; for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) { $d = date('Y-m-d', $t); $r = $rows[$d] ?? null;
    if ($r && $r['master'] !== '') $m = $r['master']; if ($r && $r['helper_name'] !== '') $h = $r['helper_name'];
    $out[$d] = ['helper' => $r ? (int)$r['helper'] : 0, 'master' => $m, 'helperName' => ($r && (int)$r['helper']) ? $h : '', 'mSet' => $r && $r['master'] !== '', 'hSet' => $r && $r['helper_name'] !== '']; }
  return $out;
}
function staffNames(array $cfg): array { $n = []; foreach (db($cfg)->query("SELECT master, helper_name FROM days")->fetchAll() as $r) { if ($r['master'] !== '') $n[$r['master']] = 1; if ($r['helper_name'] !== '') $n[$r['helper_name']] = 1; } return array_keys($n); }
// сезон переобувки: осень (сент–дек) и весна (март–июнь)
function seasonOf(string $day): string { $m = (int)substr($day, 5, 2); $y = substr($day, 0, 4); return $m >= 9 ? $y . 'о' : ($m >= 3 && $m <= 6 ? $y . 'в' : ''); }
// все организации, какие мы знаем: CRM и Эльба, продажи CloudShop и пульта, счета шиномонтажа
function orgDirectory(array $cfg): array {
  $p = db($cfg); $D = [];
  $add = function ($name, array $x = []) use (&$D) { $name = trim((string)$name); $k = orgKey($name); if (orgJunk($name)) return;
    if (!isset($D[$k])) $D[$k] = ['okey' => $k, 'org' => $name, 'inn' => '', 'kind' => 'client', 'ophone' => '', 'contact' => '', 'n' => 0]; foreach ($x as $f => $v) if ($v !== '' && $v !== null) $D[$k][$f] = $v; };
  foreach ($p->query('SELECT okey, name, inn, kind, ophone, contact FROM orgs')->fetchAll() as $o) { if ($o['kind'] === 'other') { $D[$o['okey']] = null; continue; } $add($o['name'], ['inn' => $o['inn'], 'kind' => $o['kind'], 'ophone' => $o['ophone'], 'contact' => $o['contact']]); }
  foreach ($p->query('SELECT org FROM client_orgs')->fetchAll(PDO::FETCH_COLUMN) as $o) if (!array_key_exists(orgKey($o), $D)) $add($o);
  foreach ($p->query("SELECT party, COUNT(DISTINCT doc) n FROM arch WHERE type = 'sale' AND party != '' GROUP BY party")->fetchAll() as $r) if (isOrgName($r['party'])) { $k = orgKey($r['party']); if (!array_key_exists($k, $D)) $add($r['party']); if (!empty($D[$k])) $D[$k]['n'] += (int)$r['n']; }
  foreach ($p->query("SELECT partner, COUNT(*) n FROM docs WHERE type = 'sale' AND status = 'posted' AND partner != '' GROUP BY partner")->fetchAll() as $r) if (isOrgName($r['partner'])) { $k = orgKey($r['partner']); if (!array_key_exists($k, $D)) $add($r['partner']); if (!empty($D[$k])) $D[$k]['n'] += (int)$r['n']; }
  foreach ($p->query('SELECT body FROM orders ORDER BY day DESC LIMIT 5000')->fetchAll(PDO::FETCH_COLUMN) as $bj) { $b = json_decode($bj, true) ?: [];
    foreach ([($b['pay'] ?? '') === 'invoice' ? ($b['org'] ?? '') : '', isOrgName($b['name'] ?? '') ? $b['name'] : ''] as $o) if (trim((string)$o) !== '') { $k = orgKey($o); if (!array_key_exists($k, $D)) $add($o); if (!empty($D[$k])) $D[$k]['n']++; } }
  return array_filter($D);
}
function crmAll(array $cfg): array {
  $p = db($cfg); $R = []; $today = date('Y-m-d');
  $row = function (string $k) use (&$R) { if (!isset($R[$k])) $R[$k] = ['phone' => $k, 'name' => '', 'phone2' => '', 'cars' => [], 'bday' => '', 'note' => '', 'src' => '', 'tags' => '', 'consent' => 0, 'first' => '', 'last' => '',
      'm_n' => 0, 'm_sum' => 0.0, 'm_last' => '', 'seasons' => [], 's_n' => 0, 's_sum' => 0.0, 's_prof' => 0.0, 's_last' => '', 'tire_last' => '', 'tire_size' => '', 'h_now' => 0, 'h_n' => 0, 'h_sum' => 0.0, 'h_due' => '', 'h_what' => '',
      'z_open' => 0, 'z_n' => 0, 'touch' => '', 'touch_kind' => '', 'known' => 0, 'h_for' => [], 'm_first' => '', 's_first' => '', 's_last_arch' => '', 'orgs' => [], 'works' => [], 'inv_due' => 0.0, 'inv_n' => 0, 'inv_old' => '', 'inv_list' => [], 'type' => strpos($k, 'org:') === 0 ? 'org' : 'person', 'key' => $k, 'contacts' => []]; };
  $seen = function (string $k, string $day) use (&$R) { if ($day === '') return; if ($R[$k]['first'] === '' || $day < $R[$k]['first']) $R[$k]['first'] = $day; if ($day > $R[$k]['last']) $R[$k]['last'] = $day; };
  foreach ($p->query('SELECT * FROM clients')->fetchAll() as $c) { $k = (string)$c['phone']; if ($k === '') continue; $row($k);
    foreach (['name', 'phone2', 'bday', 'note', 'src', 'tags'] as $f) $R[$k][$f] = (string)($c[$f] ?? ''); $R[$k]['consent'] = (int)($c['consent'] ?? 0); $R[$k]['known'] = 1;
    if ((string)$c['model'] !== '') $R[$k]['cars'][carKey($c['model'])] = ['car' => $c['model'], 'plate' => '']; }
  $byPlate = [];
  foreach ($p->query('SELECT phone, car, plate FROM client_cars ORDER BY id')->fetchAll() as $c) { $k = (string)$c['phone']; $row($k);
    $ck = carKey($c['car']); unset($R[$k]['cars'][$ck]); $R[$k]['cars'][$ck . '|' . plateKey($c['plate'])] = ['car' => $c['car'], 'plate' => $c['plate']]; if (plateKey($c['plate']) !== '') $byPlate[plateKey($c['plate'])] = $k; }
  // организации: у владельца-клиента деньги фирмы — его; у фирмы без владельца — отдельная строка CRM «организация»
  $orgName = []; $orgOther = []; foreach ($p->query('SELECT okey, name, inn, note, kind, contact, ophone, elba_in, elba_debt FROM orgs')->fetchAll() as $o) { if ($o['kind'] === 'other' || orgJunk($o['name'])) { $orgOther[$o['okey']] = 1; continue; } $orgName[$o['okey']] = $o; }
  $orgOf = []; $orgPeople = [];
  foreach ($p->query('SELECT phone, org, okey, role FROM client_orgs ORDER BY id')->fetchAll() as $o) {
    if (!isset($orgName[$o['okey']])) $orgName[$o['okey']] = ['okey' => $o['okey'], 'name' => $o['org'], 'inn' => '', 'note' => ''];
    if ($o['phone'] === '') continue; $row($o['phone']); $orgPeople[$o['okey']][$o['phone']] = $o['role'];
    if ($o['role'] === 'owner' && !isset($orgOf[$o['okey']])) { $orgOf[$o['okey']] = $o['phone']; $R[$o['phone']]['orgs'][] = $o['org']; } else $R[$o['phone']]['works'][] = $o['org']; }
  $orgR = function (string $name, string $contact = '') use (&$orgOf, &$orgName, &$orgPeople, $row): string { $ok = orgKey($name); if ($ok === '' || orgJunk($name)) return '';
    if (isset($orgOf[$ok])) return $orgOf[$ok]; if (!isset($orgName[$ok])) $orgName[$ok] = ['okey' => $ok, 'name' => trim($name), 'inn' => '', 'note' => ''];
    if ($contact !== '' && !isset($orgPeople[$ok][$contact])) $orgPeople[$ok][$contact] = 'contact'; $row('org:' . $ok); return 'org:' . $ok; };
  foreach ($orgName as $ok => $o) if (!isset($orgOf[$ok])) $row('org:' . $ok);
  // шиномонтаж: визит без телефона узнаём по госномеру или по организации из счёта
  $ords = $p->query('SELECT day, body FROM orders')->fetchAll(); $ob = [];
  foreach ($ords as $o) { $b = json_decode($o['body'], true) ?: []; $k = phoneKey($b['phone'] ?? ''); if ($k !== '' && plateKey($b['plate'] ?? '') !== '') $byPlate[plateKey($b['plate'])] = $byPlate[plateKey($b['plate'])] ?? $k; $ob[] = [$o['day'], $b, $k]; }
  foreach ($ob as [$day, $b, $k]) { if (($b['pay'] ?? '') === 'invoice' && trim((string)($b['org'] ?? '')) !== '') $k = $orgR((string)$b['org'], $k) ?: $k; if ($k === '') $k = $byPlate[plateKey($b['plate'] ?? '')] ?? ''; if ($k === '' && isOrgName($b['name'] ?? '')) $k = $orgR((string)$b['name']); if ($k === '') continue; $row($k);
    $x = &$R[$k];
    if (($b['pay'] ?? '') === 'invoice' && ($b['invPaid'] ?? '') === '') { $x['inv_due'] += (float)($b['paid'] ?? 0); $x['inv_n']++; if ($x['inv_old'] === '' || $day < $x['inv_old']) $x['inv_old'] = $day; $x['inv_list'][] = ['day' => $day, 'no' => $b['invNo'] ?? '', 'org' => $b['org'] ?? '', 'sum' => (int)($b['paid'] ?? 0)]; } $x['m_n']++; $x['m_sum'] += (float)($b['paid'] ?? $b['list'] ?? 0); if ($day > $x['m_last']) $x['m_last'] = $day;
    if (($s = seasonOf($day)) !== '') $x['seasons'][$s] = true; if ($x['name'] === '' && trim((string)($b['name'] ?? '')) !== '') $x['name'] = trim($b['name']);
    if (trim((string)($b['model'] ?? '')) !== '' || trim((string)($b['plate'] ?? '')) !== '') { $ck = carKey($b['model'] ?? ''); $pk = plateKey($b['plate'] ?? ''); $has = false;
      foreach ($x['cars'] as $c) if (($pk !== '' && plateKey($c['plate']) === $pk) || ($ck !== '' && carKey($c['car']) === $ck)) { $has = true; break; }
      if (!$has) $x['cars'][$ck . '|' . $pk] = ['car' => trim((string)($b['model'] ?? '')), 'plate' => mb_strtoupper(trim((string)($b['plate'] ?? '')))]; }
    if ($x['m_first'] === '' || $day < $x['m_first']) $x['m_first'] = $day; unset($x); $seen($k, $day); }
  // магазин: проведённые продажи пульта (без телефона, но на организацию клиента — тоже его)
  foreach ($p->query("SELECT d.day, d.type, d.phone, d.partner, SUM(ABS(m.qty) * IFNULL(m.price,0)) s, SUM(ABS(m.qty) * IFNULL(m.cost,0)) c, MAX(CASE WHEN p.kind = 'tire' THEN 1 ELSE 0 END) t, MAX(CASE WHEN p.kind = 'tire' THEN p.attrs ELSE '' END) a
      FROM docs d JOIN moves m ON m.doc_id = d.id JOIN products p ON p.id = m.product_id WHERE d.type IN ('sale','ret') AND d.status = 'posted' AND (d.phone != '' OR d.partner != '') GROUP BY d.id")->fetchAll() as $d) {
    $k = (string)$d['phone']; $org = false; if (isOrgName($d['partner'])) { $k = $orgR((string)$d['partner'], $k); $org = true; } elseif ($k === '') $k = $orgOf[orgKey($d['partner'])] ?? ''; if ($k === '') continue; $row($k); if ($org) $R[$k]['s_org'] = ($R[$k]['s_org'] ?? 0) + 1; $sg = $d['type'] === 'ret' ? -1 : 1; $x = &$R[$k];
    if ($sg > 0) $x['s_n']++; $x['s_sum'] += $sg * (float)$d['s']; $x['s_prof'] += $sg * ((float)$d['s'] - (float)$d['c']); if ($d['day'] > $x['s_last']) $x['s_last'] = $d['day']; if ($x['s_first'] === '' || $d['day'] < $x['s_first']) $x['s_first'] = $d['day'];
    if (($sn = seasonOf($d['day'])) !== '' && (int)$d['t']) $x['seasons'][$sn] = true;
    if ($sg > 0 && (int)$d['t'] && $d['day'] > $x['tire_last']) { $x['tire_last'] = $d['day']; $a = json_decode((string)$d['a'], true) ?: []; if (!empty($a['w'])) $x['tire_size'] = $a['w'] . '/' . ($a['p'] ?? '') . ' R' . ($a['d'] ?? ''); }
    if ($x['name'] === '' && !$org && trim((string)$d['partner']) !== '') $x['name'] = trim($d['partner']); unset($x); $seen($k, $d['day']); }
  // магазин: история CloudShop
  foreach ($p->query("SELECT doc, day, type, phone, party, SUM(ABS(qty) * price) s, SUM(ABS(qty) * cost) c, MAX(CASE WHEN name GLOB '[0-9][0-9][0-9]/[0-9][0-9]/[0-9][0-9]*' THEN 1 ELSE 0 END) t, MAX(CASE WHEN name GLOB '[0-9][0-9][0-9]/[0-9][0-9]/[0-9][0-9]*' THEN name ELSE '' END) tn
      FROM arch WHERE type IN ('sale','ret') AND ((phone IS NOT NULL AND phone != '') OR party != '') GROUP BY doc")->fetchAll() as $d) {
    $k = phoneKey($d['phone']); $org = false; if (isOrgName($d['party'])) { $k = $orgR((string)$d['party'], $k); $org = true; } elseif ($k === '') $k = $orgOf[orgKey($d['party'])] ?? ''; if ($k === '') continue; $row($k); if ($org) $R[$k]['s_org'] = ($R[$k]['s_org'] ?? 0) + 1; $sg = $d['type'] === 'ret' ? -1 : 1; $x = &$R[$k];
    if ($sg > 0) $x['s_n']++; $x['s_sum'] += $sg * (float)$d['s']; $x['s_prof'] += $sg * ((float)$d['s'] - (float)$d['c']); if ($d['day'] > $x['s_last']) $x['s_last'] = $d['day']; if ($d['day'] > $x['s_last_arch']) $x['s_last_arch'] = $d['day'];
    if ($sg > 0 && (int)$d['t'] && $d['day'] > $x['tire_last']) { $x['tire_last'] = $d['day']; if (preg_match('/^(\d{3})\/(\d{2})\/(\d{2})/', (string)$d['tn'], $mm)) $x['tire_size'] = "$mm[1]/$mm[2] R$mm[3]"; }
    if ($x['name'] === '' && !$org && trim((string)$d['party']) !== '') $x['name'] = trim($d['party']); unset($x); $seen($k, $d['day']); }
  // заказы
  foreach ($p->query('SELECT phone, name, status, day FROM zak')->fetchAll() as $z) { $k = (string)$z['phone']; if ($k === '') continue; $row($k);
    $R[$k]['z_n']++; if (in_array($z['status'], ['wait', 'came'], true)) $R[$k]['z_open']++; if ($R[$k]['name'] === '' && $z['name'] !== '') $R[$k]['name'] = $z['name']; $seen($k, $z['day']); }
  // хранение (шинный отель)
  if ($hp = hotelPdo($cfg)) { try { foreach ($hp->query("SELECT client, phone_digits, status, paid, accepted_at, issued_at, due_at, descr, size, season FROM items WHERE (deleted_at IS NULL OR deleted_at = '')")->fetchAll() as $h) {
    $k = phoneKey($h['phone_digits']); if ($k === '') continue; $row($k); $x = &$R[$k]; $x['h_n']++; $x['h_sum'] += (float)$h['paid'];
    if ($h['status'] === 'stored') { $x['h_now']++; $x['h_what'] = trim((string)($h['descr'] ?: $h['size'])); if ($x['h_due'] === '' || (string)$h['due_at'] < $x['h_due']) $x['h_due'] = (string)$h['due_at'];
      // что лежит: летние шины понадобятся весной, зимние — осенью
      $hs = mb_strtolower((string)$h['season']); if (mb_strpos($hs, 'лет') !== false) $x['h_for']['в'] = true; elseif (mb_strpos($hs, 'зим') !== false) $x['h_for']['о'] = true; }
    foreach ([substr((string)$h['accepted_at'], 0, 10), substr((string)$h['issued_at'], 0, 10)] as $hd) if ($hd !== '' && ($sn = seasonOf($hd)) !== '') $x['seasons'][$sn] = true;
    if ($x['name'] === '' && trim((string)$h['client']) !== '') $x['name'] = trim($h['client']); unset($x); $seen($k, substr((string)$h['accepted_at'], 0, 10)); } } catch (Throwable $e) {} }
  foreach ($p->query('SELECT phone, MAX(day) d, kind FROM crm_touch GROUP BY phone')->fetchAll() as $t) if (isset($R[$t['phone']])) { $R[$t['phone']]['touch'] = $t['d']; $R[$t['phone']]['touch_kind'] = $t['kind']; }
  // строки-организации: название, реквизиты, люди (владелец / представители)
  foreach ($R as $k => &$x) if ($x['type'] === 'org') { $ok = substr($k, 4); $o = $orgName[$ok] ?? ['name' => $ok, 'inn' => '', 'note' => '']; $x['name'] = $o['name']; $x['inn'] = $o['inn']; $x['note'] = $o['note']; $x['okey'] = $ok; $x['phone'] = ''; $x['kind'] = $o['kind'] ?? 'client'; $x['ocontact'] = $o['contact'] ?? ''; $x['ophone'] = $o['ophone'] ?? ''; $x['elba_in'] = round((float)($o['elba_in'] ?? 0)); $x['elba_debt'] = round((float)($o['elba_debt'] ?? 0));
    foreach ($orgPeople[$ok] ?? [] as $ph => $rl) { $ph = (string)$ph; $x['contacts'][] = ['phone' => $ph, 'name' => $R[$ph]['name'] ?? '', 'role' => $rl]; }
    usort($x['contacts'], fn($a, $b) => ($a['role'] === 'owner' ? 0 : 1) <=> ($b['role'] === 'owner' ? 0 : 1)); } unset($x);
  // итоги и метки
  $prof = [];
  foreach ($R as $k => &$x) {
    $x['rev'] = round($x['m_sum'] + $x['s_sum'] + $x['h_sum']); $x['prof'] = round($x['s_prof'] + $x['m_sum'] * 0.7 + $x['h_sum']);
    $x['m_sum'] = round($x['m_sum']); $x['s_sum'] = round($x['s_sum']); $x['s_prof'] = round($x['s_prof']); $x['h_sum'] = round($x['h_sum']);
    $x['cars'] = array_values($x['cars']); $x['nseas'] = count($x['seasons']); $x['seasons'] = array_keys($x['seasons']); $x['h_for'] = array_keys($x['h_for']);
    if ($x['prof'] > 0) $prof[] = $x['prof'];
  } unset($x);
  $hist0 = (string)$p->query("SELECT MIN(day) FROM orders")->fetchColumn(); $a0 = (string)$p->query("SELECT MIN(day) FROM arch")->fetchColumn(); if ($a0 !== '' && ($hist0 === '' || $a0 < $hist0)) $hist0 = $a0;
  rsort($prof); $vip = $prof ? max(20000, $prof[max(0, (int)floor(count($prof) * 0.1) - 1)]) : PHP_INT_MAX;
  $cur = seasonOf($today) ?: (((int)date('n') <= 2) ? date('Y') . 'в' : date('Y') . 'о');
  foreach ($R as &$x) { $t = [];
    if ($x['prof'] >= $vip) $t[] = 'vip';
    if ($x['m_n'] >= 3 || $x['nseas'] >= 2) $t[] = 'loyal';
    // «новый» — первый визит или покупка за 60 дней; пока у пульта нет полугода истории, не размечаем (иначе новые все)
    $f1 = min(array_filter([$x['m_first'] ?: '9', $x['s_first'] ?: '9'])); if ($hist0 !== '' && $hist0 < date('Y-m-d', strtotime('-6 months')) && $f1 !== '9' && $f1 >= date('Y-m-d', strtotime('-60 days')) && $x['s_last_arch'] === '') $t[] = 'new';
    if ($x['last'] !== '' && $x['last'] < date('Y-m-d', strtotime('-12 months'))) $t[] = 'sleep';
    if ($x['h_now'] > 0) $t[] = 'store';
    if ($x['m_n'] >= 1 && $x['s_n'] === 0) $t[] = 'noshop';
    if ($x['bday'] !== '' && ($dd = bdayIn($x['bday'])) !== null && $dd <= 10) { $t[] = 'bday'; $x['bday_in'] = $dd; }
    if (in_array($cur, $x['seasons'], true)) $t[] = 'season';
    if ($x['inv_n'] > 0) $t[] = 'debt';
    if ($x['orgs']) $t[] = 'org';
    if ($x['type'] === 'org') $t[] = 'company';
    if (($x['kind'] ?? '') === 'supplier') $t[] = 'supplier';
    $x['seg'] = $t; } unset($x);
  return $R;
}
// через сколько дней день рождения (ДД.ММ или ГГГГ-ММ-ДД)
function bdayIn(string $b): ?int {
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $b, $m)) { $d = (int)$m[3]; $mo = (int)$m[2]; } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})/', $b, $m)) { $d = (int)$m[1]; $mo = (int)$m[2]; } else return null;
  if (!checkdate($mo, $d, 2024)) return null; $y = (int)date('Y'); $t = strtotime(date('Y-m-d'));
  $x = @mktime(0, 0, 0, $mo, $d, $y); if ($x < $t) $x = mktime(0, 0, 0, $mo, $d, $y + 1); return (int)round(($x - $t) / 86400);
}
function ruPlural(int $n, string $a, string $b, string $c): string { $m = $n % 100; $l = $n % 10; return ($m >= 11 && $m <= 14) ? $c : ($l === 1 ? $a : ($l >= 2 && $l <= 4 ? $b : $c)); }
// имя для обращения: «Шиколов Никита» → Никита, «Светлана 2979» → Светлана, «СТ Солюшн» → без имени
function crmFirstName(string $s): string {
  $w = preg_split('/\s+/u', trim($s)); $ok = fn($x) => (bool)preg_match('/^[А-ЯЁ][а-яё]{1,20}$/u', $x); $sur = fn($x) => (bool)preg_match('/(ов|ев|ёв|ин|ын|ова|ева|ёва|ина|ына|ский|цкий|ская|цкая|ко|ук|юк|их|ых)$/u', $x);
  if (isset($w[0], $w[1]) && $ok($w[0]) && $ok($w[1]) && $sur($w[0]) && !$sur($w[1])) return $w[1];
  return isset($w[0]) && $ok($w[0]) && !(isset($w[1]) && $ok($w[1]) && $sur($w[0])) ? $w[0] : '';
}
// история организации: шиномонтаж по счёту, продажи пульта и CloudShop на её название
function orgHistory(array $cfg, array $keys, bool $noPhone): array { $ob = []; if (!$keys) return $ob; $p = db($cfg);
  foreach ($p->query("SELECT doc, day, type, party, MAX(phone) ph, SUM(ABS(qty) * price) s, GROUP_CONCAT(name, ', ') n FROM arch WHERE type IN ('sale','ret') AND party != '' GROUP BY doc")->fetchAll() as $r)
    if (isset($keys[orgKey($r['party'])]) && (!$noPhone || phoneKey($r['ph']) === '')) $ob[] = ['day' => $r['day'], 'type' => $r['type'], 'org' => $r['party'], 'items' => $r['n'], 'sum' => round((float)$r['s']), 'arch' => 1, 'phone' => phoneKey($r['ph'])];
  foreach ($p->query("SELECT day, body FROM orders WHERE body LIKE '%invoice%'")->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; if (($b['pay'] ?? '') !== 'invoice' || !isset($keys[orgKey($b['org'] ?? '')]) || ($noPhone && phoneKey($b['phone'] ?? '') !== '')) continue;
    $ob[] = ['day' => $r['day'], 'type' => 'mont', 'org' => $b['org'], 'items' => trim(($b['model'] ?? '') . ' ' . ($b['plate'] ?? '') . ($b['invNo'] ?? '' ? ' · счёт № ' . $b['invNo'] : '') . (($b['invPaid'] ?? '') === '' ? ' · не оплачен' : '')), 'sum' => (int)($b['paid'] ?? 0), 'arch' => 0, 'phone' => phoneKey($b['phone'] ?? '')]; }
  foreach ($p->query("SELECT d.id, d.day, d.type, d.partner, d.phone, SUM(ABS(m.qty) * IFNULL(m.price,0)) s, GROUP_CONCAT(p.name, ', ') n FROM docs d JOIN moves m ON m.doc_id = d.id JOIN products p ON p.id = m.product_id WHERE d.type IN ('sale','ret') AND d.status = 'posted' AND d.partner != '' GROUP BY d.id")->fetchAll() as $r)
    if (isset($keys[orgKey($r['partner'])]) && (!$noPhone || (string)$r['phone'] === '')) $ob[] = ['day' => $r['day'], 'type' => $r['type'], 'org' => $r['partner'], 'items' => $r['n'], 'sum' => round((float)$r['s']), 'arch' => 0, 'phone' => (string)$r['phone']];
  usort($ob, fn($a, $b) => strcmp($b['day'], $a['day'])); return $ob;
}
// ===== вся история клиента одной лентой: шиномонтаж, записи, магазин (пульт и CloudShop), заказы, хранение, задачи, звонки, КП =====
// $phones — телефоны человека (или людей организации); $okeys — организации, чьи деньги считаются тут; $moneyByPhone — визиты и покупки по телефону тоже его
function kpDir(array $cfg): string { $d = (string)($cfg['kp_dir'] ?? ''); if ($d === '') $d = dirname(__DIR__) . '/kp/data'; return is_dir($d) ? $d : ''; }
function crmTimeline(array $cfg, array $phones, array $okeys, bool $moneyByPhone): array {
  $p = db($cfg); $ev = []; $P = array_flip(array_filter($phones)); $O = array_flip(array_filter($okeys));
  $mine = fn($ph, $org) => ($moneyByPhone && $ph !== '' && isset($P[$ph])) || ($org !== '' && isset($O[orgKey($org)]));
  $plates = []; if ($moneyByPhone && $P) { $st = $p->prepare('SELECT plate FROM client_cars WHERE phone = ?'); foreach (array_keys($P) as $ph) { $st->execute([(string)$ph]); foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pl) if (plateKey($pl) !== '') $plates[plateKey($pl)] = 1; } }
  foreach ($p->query('SELECT id, day, body FROM orders')->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; $ph = phoneKey($b['phone'] ?? ''); $inv = ($b['pay'] ?? '') === 'invoice';
    $ok = $mine($ph, $inv ? (string)($b['org'] ?? '') : '') || ($ph === '' && isOrgName($b['name'] ?? '') && isset($O[orgKey($b['name'])])) || ($moneyByPhone && $ph === '' && isset($plates[plateKey($b['plate'] ?? '')])); if (!$ok) continue;
    $w = implode(', ', array_map(fn($l) => ($l['n'] ?? '') . ((int)($l['q'] ?? 1) > 1 ? ' ×' . $l['q'] : ''), array_filter($b['lines'] ?? [], fn($l) => (int)($l['q'] ?? 0) > 0)));
    $ev[] = ['day' => $r['day'], 'kind' => 'mont', 'title' => 'Шиномонтаж' . ($inv ? ' по счёту' . (($b['org'] ?? '') !== '' ? ' — ' . $b['org'] : '') : ''), 'detail' => trim(trim(($b['model'] ?? '') . ' ' . ($b['plate'] ?? '')) . ($w !== '' ? ': ' . $w : ''), ': '),
      'sum' => (int)($b['paid'] ?? 0), 'status' => $inv ? (($b['invPaid'] ?? '') !== '' ? 'счёт оплачен' : (($b['invNo'] ?? '') !== '' ? 'счёт № ' . $b['invNo'] . ' не оплачен' : 'счёт не выставлен')) : '', 'warn' => $inv && ($b['invPaid'] ?? '') === '', 'ref' => ['id' => (int)$r['id'], 'day' => $r['day']] + $b]; }
  if ($moneyByPhone && $P) foreach ($p->query("SELECT id, day, tm, status, body FROM bookings WHERE status != 'cancelled'")->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; if (!isset($P[phoneKey($b['phone'] ?? '')])) continue;
    $ev[] = ['day' => $r['day'], 'kind' => 'book', 'title' => 'Запись на ' . $r['tm'], 'detail' => trim(($b['model'] ?? '') . ' ' . ($b['works'] ?? $b['note'] ?? '')), 'sum' => 0, 'status' => $r['status'] === 'planned' ? 'запланировано' : ($r['status'] === 'done' ? 'приехал' : $r['status']), 'warn' => false, 'ref' => null]; }
  foreach ($p->query("SELECT d.id, d.day, d.type, d.phone, d.partner, d.status, d.req, SUM(ABS(m.qty) * IFNULL(m.price,0)) s, GROUP_CONCAT(pr.name || CASE WHEN ABS(m.qty) > 1 THEN ' ×' || CAST(ABS(m.qty) AS INTEGER) ELSE '' END, ', ') n FROM docs d JOIN moves m ON m.doc_id = d.id JOIN products pr ON pr.id = m.product_id WHERE d.type IN ('sale','ret') AND d.status != 'cancelled' GROUP BY d.id")->fetchAll() as $r) {
    $org = isOrgName($r['partner']) ? (string)$r['partner'] : ''; if (!$mine((string)$r['phone'], $org)) continue;
    $ev[] = ['day' => $r['day'], 'kind' => 'shop', 'title' => ($r['type'] === 'ret' ? 'Возврат' : 'Покупка') . ($org !== '' ? ' на ' . $org : ''), 'detail' => (string)$r['n'], 'sum' => ($r['type'] === 'ret' ? -1 : 1) * round((float)$r['s']), 'status' => $r['status'] === 'draft' ? ((int)$r['req'] ? 'ждёт подтверждения' : 'черновик') : '', 'warn' => $r['status'] === 'draft', 'ref' => ['doc' => (int)$r['id']]]; }
  foreach ($p->query("SELECT doc, day, type, MAX(phone) ph, party, SUM(ABS(qty) * price) s, GROUP_CONCAT(name, ', ') n FROM arch WHERE type IN ('sale','ret') GROUP BY doc")->fetchAll() as $r) {
    $org = isOrgName($r['party']) ? (string)$r['party'] : ''; if (!$mine(phoneKey($r['ph']), $org)) continue;
    $ev[] = ['day' => $r['day'], 'kind' => 'shop', 'title' => ($r['type'] === 'ret' ? 'Возврат' : 'Покупка') . ($org !== '' ? ' на ' . $org : '') . ' (CloudShop)', 'detail' => (string)$r['n'], 'sum' => ($r['type'] === 'ret' ? -1 : 1) * round((float)$r['s']), 'status' => '', 'warn' => false, 'ref' => null]; }
  if ($P) foreach ($p->query('SELECT * FROM zak ORDER BY id')->fetchAll() as $r) { if (!isset($P[(string)$r['phone']])) continue;
    $ev[] = ['day' => $r['day'], 'kind' => 'zak', 'title' => 'Заказ', 'detail' => $r['items'] . ($r['supplier'] !== '' ? ' · ' . $r['supplier'] : ''), 'sum' => (float)$r['total'], 'status' => ['wait' => 'ждём товар', 'came' => 'пришёл, позвонить', 'done' => 'выдан', 'cancel' => 'отменён'][$r['status']] ?? $r['status'], 'warn' => in_array($r['status'], ['wait', 'came'], true), 'ref' => ['zak' => zakRow($r, 'owner')]]; }
  if ($moneyByPhone && $P && ($hp = hotelPdo($cfg))) { try { foreach ($hp->query("SELECT id, client, phone_digits, status, paid, accepted_at, issued_at, due_at, descr, size, season, cell FROM items WHERE (deleted_at IS NULL OR deleted_at = '')")->fetchAll() as $h) {
    if (!isset($P[phoneKey($h['phone_digits'])])) continue; $what = trim(($h['season'] ? $h['season'] . ': ' : '') . ($h['descr'] ?: $h['size']));
    $ev[] = ['day' => substr((string)$h['accepted_at'], 0, 10), 'kind' => 'store', 'title' => 'Сдал на хранение', 'detail' => $what . ($h['cell'] ? ' · ячейка ' . $h['cell'] : ''), 'sum' => (float)$h['paid'], 'status' => $h['status'] === 'stored' ? 'хранится' . ($h['due_at'] ? ' до ' . date('d.m.y', strtotime($h['due_at'])) : '') : '', 'warn' => false, 'ref' => ['hotel' => (int)$h['id']]];
    if ($h['status'] === 'issued' && $h['issued_at']) $ev[] = ['day' => substr((string)$h['issued_at'], 0, 10), 'kind' => 'store', 'title' => 'Забрал с хранения', 'detail' => $what, 'sum' => 0, 'status' => '', 'warn' => false, 'ref' => ['hotel' => (int)$h['id']]]; } } catch (Throwable $e) {} }
  if ($P) { $in = implode(',', array_fill(0, count($P), '?')); $ks = array_map('strval', array_keys($P));
    $st = $p->prepare("SELECT * FROM tasks WHERE phone IN ($in)"); $st->execute($ks);
    foreach ($st->fetchAll() as $t) $ev[] = ['day' => $t['status'] === 'done' && $t['done_at'] ? date('Y-m-d', (int)$t['done_at']) : ($t['due'] ?: date('Y-m-d', (int)$t['created'])), 'kind' => 'task', 'title' => 'Задача' . ($t['who'] !== '' ? ' — ' . $t['who'] : ''), 'detail' => $t['title'] . ($t['note'] !== '' ? ' · ' . $t['note'] : ''), 'sum' => 0, 'status' => $t['status'] === 'done' ? 'сделано' : ($t['due'] !== '' && $t['due'] < date('Y-m-d') ? 'просрочено' : 'в работе'), 'warn' => $t['status'] !== 'done', 'ref' => ['task' => $t]];
    $st = $p->prepare("SELECT * FROM crm_touch WHERE phone IN ($in)"); $st->execute($ks);
    $KN = ['call' => 'Звонок', 'msg' => 'Сообщение', 'visit' => 'Приезжал', 'no' => 'Не дозвонились'];
    foreach ($st->fetchAll() as $t) $ev[] = ['day' => $t['day'], 'kind' => 'touch', 'title' => $KN[$t['kind']] ?? $t['kind'], 'detail' => $t['note'], 'sum' => 0, 'status' => '', 'warn' => false, 'ref' => ['touch' => (int)$t['id']]]; }
  if ($kd = kpDir($cfg)) foreach (glob($kd . '/*.json') ?: [] as $f) { $k = json_decode((string)@file_get_contents($f), true); if (!is_array($k)) continue; $c = $k['client'] ?? [];
    $ph = phoneKey($c['phone'] ?? ''); $org = (string)($c['org'] ?? ''); if (!(($ph !== '' && isset($P[$ph])) || ($org !== '' && isset($O[orgKey($org)])))) continue;
    $sum = 0; foreach (['items', 'services'] as $g) foreach ((array)($k[$g] ?? []) as $x) $sum += (float)($x['p'] ?? 0) * (float)($x['q'] ?? 1);
    $ev[] = ['day' => (string)($k['date'] ?? substr((string)($k['created'] ?? ''), 0, 10)), 'kind' => 'kp', 'title' => (mb_strpos((string)($k['num'] ?? ''), 'КП') === 0 ? (string)$k['num'] : trim('КП ' . ($k['num'] ?? ''))), 'detail' => trim(($org !== '' ? $org . ' · ' : '') . ($k['predmet'] ?? '')), 'sum' => round($sum), 'status' => '', 'warn' => false, 'ref' => ['kp' => (string)($k['id'] ?? '')]]; }
  usort($ev, fn($a, $b) => strcmp($b['day'], $a['day'])); return $ev;
}
// кому позвонить: причины и готовый текст сообщения
function crmCalls(array $R): array {
  $out = []; $today = date('Y-m-d'); $m = (int)date('n'); $md = date('m-d');
  // окна переобувки: осень — с 20 сентября по декабрь, весна — с марта по май; летом и в январе–феврале сезонных звонков нет
  $up = ($md >= '09-20') ? 'о' : (($m >= 3 && $m <= 5) ? 'в' : ''); $yy = (int)date('Y'); $cur = $yy . $up; $prev = ($yy - 1) . $up; $prev2 = ($yy - 2) . $up;
  $sName = $up === 'о' ? 'зимние' : 'летние';
  foreach ($R as $x) {
    if (($x['type'] ?? '') === 'org') { $c0 = $x['contacts'][0] ?? null; if (!$c0 || !$x['inv_n']) continue; $x['phone'] = $c0['phone']; $x['name'] = $c0['name']; $x['orgname'] = $x['name']; }
    if (strlen($x['phone']) !== 11) continue;
    // обращение — только имя (в базе бывает «Светлана 2979», «Елена Ford Transit»)
    $n = crmFirstName($x['name']);
    $recent = $x['touch'] !== '' && $x['touch'] >= date('Y-m-d', strtotime('-14 days'));
    $hi = $n !== '' ? 'Здравствуйте, ' . $n . '! ' : 'Здравствуйте! ';
    $add = function ($why, $txt, $prio) use (&$out, $x) { $out[] = ['phone' => $x['phone'], 'key' => $x['key'] ?? $x['phone'], 'org' => ($x['type'] ?? '') === 'org' ? ($x['inv_list'][0]['org'] ?? '') : '', 'name' => $x['name'], 'cars' => array_slice($x['cars'], 0, 2), 'why' => $why, 'text' => $txt, 'prio' => $prio, 'rev' => $x['rev'], 'prof' => $x['prof'], 'last' => $x['last'], 'touch' => $x['touch'], 'consent' => $x['consent']]; };
    if (!empty($x['bday_in']) || (isset($x['bday_in']) && $x['bday_in'] === 0)) { $add('bday', $hi . 'Шинный центр IVANOR поздравляет вас с днём рождения! Дарим скидку на шиномонтаж до конца месяца — просто назовите это сообщение.', 1); continue; }
    if ($x['inv_n'] > 0 && $x['inv_old'] <= date('Y-m-d', strtotime('-7 days')) && !$recent) { $L = $x['inv_list'];
      if ($x['inv_n'] > 3) $add('debt', $hi . 'Напоминаем: в IVANOR не оплачено' . (($x['type'] ?? '') === 'org' ? ' по ' . ($x['inv_list'][0]['org'] ?? 'организации') : '') . ' ' . $x['inv_n'] . ' ' . ruPlural($x['inv_n'], 'счёт', 'счёта', 'счетов') . ' за шиномонтаж на ' . number_format($x['inv_due'], 0, ',', ' ') . ' ₽, самый ранний от ' . date('d.m.Y', strtotime($x['inv_old'])) . '. Пришлём сверку, если нужно. Спасибо!', 2); else
      $add('debt', $hi . 'Напоминаем об оплате ' . ($x['inv_n'] > 1 ? 'счетов' : 'счёта') . ' за шиномонтаж в IVANOR: ' . implode('; ', array_map(fn($i) => ($i['no'] !== '' ? '№ ' . $i['no'] . ' ' : '') . 'от ' . date('d.m', strtotime($i['day'])) . ($i['org'] !== '' ? ' (' . $i['org'] . ')' : '') . ' — ' . number_format($i['sum'], 0, ',', ' ') . ' ₽', array_slice($L, 0, 4))) . '. Спасибо!', 2); continue; }
    if ($recent) continue;
    if ($up !== '' && $x['h_now'] > 0 && in_array($up, $x['h_for'], true) && !in_array($cur, $x['seasons'], true)) { $add('store', $hi . 'Ваши ' . ($x['h_what'] ?: 'колёса') . ' хранятся у нас в IVANOR. Подходит время переобуваться — запишем вас на удобное время и заранее подготовим комплект. Ноябрьская, 129.', 2); continue; }
    if ($up !== '' && !in_array($cur, $x['seasons'], true) && (in_array($prev, $x['seasons'], true) || in_array($prev2, $x['seasons'], true))) { $add('season', $hi . 'Пора менять резину на ' . $sName . '. В IVANOR (Ноябрьская, 129) запишем на удобное время без очереди. Если ваши шины уже уставшие — подберём новые, шиномонтаж при покупке со скидкой.', 3); continue; }
    if ($x['tire_last'] !== '' && $x['tire_last'] < date('Y-m-d', strtotime('-4 years'))) { $add('tires', $hi . 'Шинам, которые вы брали у нас в ' . substr($x['tire_last'], 0, 4) . ' году, уже ' . ((int)date('Y') - (int)substr($x['tire_last'], 0, 4)) . ' лет. Приезжайте, бесплатно проверим протектор и резину' . ($x['tire_size'] ? ' (' . $x['tire_size'] . ')' : '') . ', а если пора — подберём замену со скидкой на шиномонтаж.', 4); continue; }
    if ($x['m_n'] >= 2 && $x['s_n'] === 0) { $add('noshop', $hi . 'Спасибо, что обслуживаетесь в IVANOR! Когда будете менять шины — купите у нас: шиномонтаж при покупке комплекта бесплатно, подберём под ваш бюджет.', 5); continue; }
    if ($x['m_n'] >= 2 && $x['h_n'] === 0 && $x['nseas'] >= 2) { $add('nostore', $hi . 'Чтобы не возить колёса туда-обратно, оставьте их на хранение в IVANOR: моем, упаковываем, храним в тёплом складе, к сезону всё готово.', 6); continue; }
    if (in_array('sleep', $x['seg'], true) && $x['last'] >= date('Y-m-d', strtotime('-36 months')) && ($x['m_n'] >= 2 || $x['rev'] >= 10000)) { $add('sleep', $hi . 'Давно вас не видели в IVANOR! Будем рады снова помочь — шиномонтаж, шины, диски, хранение. Для вас скидка 10% на шиномонтаж до конца месяца.', 7); }
  }
  usort($out, fn($a, $b) => [$a['prio'], -$a['prof']] <=> [$b['prio'], -$b['prof']]);
  return $out;
}
// ===== Пауэр Интернэшнл-шины (Форточки): CAE и их открытая выгрузка номенклатуры =====
function isPower(string $pk): bool { return mb_strpos($pk, 'ПАУЭР') !== false || mb_strpos($pk, 'POWER INT') !== false || mb_strpos($pk, 'ФОРТОЧКИ') !== false || mb_strpos($pk, '4ТОЧКИ') !== false; }
// слова для сравнения названий: без дефисов, звёздочек и точек внутри слов (i*Pike = IPIKE, FSR-802 = FSR802)
function p4tWords(string $s): array {
  $s = mb_strtoupper(str_replace(['Ё', 'ё'], ['Е', 'е'], $s));
  $s = preg_replace('/(?<=[0-9A-ZА-Я])[\-\*\.\'’+](?=[0-9A-ZА-Я])/u', '', $s);
  $w = preg_split('/[^0-9A-ZА-Я]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
  $tr = ['KAMA' => 'КАМА', 'BELSHINA' => 'БЕЛШИНА', 'NOKIANTYRES' => 'NOKIAN', 'TYRES' => '', 'TIRES' => '', 'SUV' => '', 'XL' => '', 'TL' => '', 'MS' => '', 'LAUFEN' => 'LAUFENN'];
  $o = []; foreach ($w as $x) { $x = $tr[$x] ?? $x; if ($x !== '') $o[$x] = true; } return $o;
}
// у Пауэра один товар бывает под несколькими кодами: «…old», «…old15» — старые, «…U»/«…S» — уценка/особая партия; основной — без хвоста
function p4tCodeRank(string $c): int { return (preg_match('/old\d*$/i', $c) ? 0 : 5) + (preg_match('/\d[US]$/i', $c) ? 0 : 2) + (preg_match('/^CTS/i', $c) ? 0 : 1); }
// цвет диска: Sil = silver = серебро и т.п.
function p4tColor(string $s): array {
  $m = ['SIL' => 'SILVER', 'S' => 'SILVER', 'СЕРЕБРО' => 'SILVER', 'СЕРЕБРИСТЫЙ' => 'SILVER', 'СИЛЬВЕР' => 'SILVER', 'BK' => 'BLACK', 'ЧЕРНЫЙ' => 'BLACK', 'ЧЕРНЫЕ' => 'BLACK', 'БЕЛЫЙ' => 'WHITE', 'WH' => 'WHITE'];
  $o = []; foreach (p4tWords($s) as $w => $_) $o[$m[$w] ?? $w] = true; return $o;
}
function p4tNum($v): float { return (float)str_replace(',', '.', preg_replace('/[^0-9,\.]/', '', (string)$v)); }
function p4tDownload(string $url, string $to): void {
  @set_time_limit(600);
  if (function_exists('curl_init')) { $fh = fopen($to, 'wb'); if (!$fh) fail('Не могу записать временный файл в папку data');
    $c = curl_init($url); curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 400, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_USERAGENT => 'IVANOR montazh (catalog sync)']);
    $ok = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c); fclose($fh);
    if (!$ok || $code >= 400) { @unlink($to); fail('Не скачался справочник Пауэра (' . ($code ?: $err ?: 'нет связи') . ')', 502); } return; }
  if (!@copy($url, $to, stream_context_create(['http' => ['timeout' => 400, 'user_agent' => 'IVANOR montazh']]))) fail('Не скачался справочник Пауэра', 502);
}
// разбор выгрузки потоком (файл 30–50 МБ — целиком в память не читаем)
function p4tParse(string $file, string $part, PDO $p): int {
  if (!class_exists('XMLReader')) fail('На хостинге нет модуля PHP XMLReader', 500);
  $r = new XMLReader(); if (!$r->open($file, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) fail('Файл справочника не читается');
  $tag = $part === 'rims' ? 'rims' : 'tires'; $kind = $part === 'rims' ? 'disk' : 'tire'; $n = 0;
  $p->beginTransaction(); $p->prepare('DELETE FROM p4t WHERE kind = ?')->execute([$kind]);
  $ins = $p->prepare('INSERT OR REPLACE INTO p4t (cae, kind, brand, model, name, w, p, d, c, li, stud, pcd, et, dia, color) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  while ($r->read() && !($r->nodeType === XMLReader::ELEMENT && $r->name === $tag));
  for ($go = $r->nodeType === XMLReader::ELEMENT && $r->name === $tag; $go; $go = $r->next($tag)) {
    $x = @simplexml_load_string($r->readOuterXml()); if (!$x) continue;
    $cae = trim((string)$x->cae); if ($cae === '') continue; $name = trim((string)$x->name);
    if ($kind === 'tire') {
      $d = p4tNum(str_replace('R', '', (string)$x->diameter)); $c = preg_match('/\dC\b|R\d+(,\d+)?C\b/u', $name) ? 1 : 0;
      $ins->execute([$cae, 'tire', trim((string)$x->brand), trim((string)$x->model), $name, p4tNum($x->width), p4tNum($x->height), $d, $c,
        trim((string)$x->load_index) . trim((string)$x->speed_index), mb_strtolower(trim((string)$x->is_studded)) === 'шип' ? 1 : 0, null, null, null, null]);
    } else {
      $bc = (int)p4tNum($x->bolts_count); $bs = p4tNum($x->bolts_spacing); $pcd = $bc && $bs ? $bc . 'x' . rtrim(rtrim(number_format($bs, 2, '.', ''), '0'), '.') : '';
      $ins->execute([$cae, 'disk', trim((string)$x->brand), trim((string)$x->model), $name, p4tNum($x->width), null, p4tNum($x->diameter), 0, null, 0, $pcd, (float)str_replace(',', '.', (string)$x->et), p4tNum($x->dia), trim((string)$x->color)]);
    }
    $n++;
  }
  $r->close(); $p->commit(); return $n;
}
// подбор CAE для одной нашей карточки: всё строго — размер, бренд, модель, индексы, шипы (у диска — все размеры и модель)
function p4tFind(PDO $p, array $prod): array {
  static $q = [];
  $a = json_decode((string)$prod['attrs'], true) ?: []; $kind = $prod['kind'];
  if ($kind === 'tire') {
    if (empty($a['w']) || empty($a['d'])) return ['st' => 'nosize'];
    $key = 't' . $a['w'] . '/' . ($a['p'] ?? 0) . '/' . $a['d'];
    if (!isset($q[$key])) { $st = $p->prepare("SELECT * FROM p4t WHERE kind = 'tire' AND d = ? AND w = ? AND p = ?"); $st->execute([(float)$a['d'], (float)$a['w'], (float)($a['p'] ?? 0)]); $q[$key] = $st->fetchAll(); }
    $ours = p4tWords(($a['brand'] ?? '') . ' ' . ($a['model'] ?? '') . ' ' . ($a['alias'] ?? '') . ' ' . $prod['name']);
    $ix = mb_strtoupper(str_replace(' ', '', (string)($a['index'] ?? ''))); $hit = [];
    foreach ($q[$key] as $c) {
      if ((int)$c['c'] !== (!empty($a['c']) ? 1 : 0)) continue;
      if ((int)$c['stud'] !== (!empty($a['stud']) ? 1 : 0)) continue;
      if ($ix !== '' && mb_strtoupper(str_replace(' ', '', (string)$c['li'])) !== $ix) continue;
      $bw = p4tWords((string)$c['brand']); $bok = false; foreach ($bw as $w => $_) if (isset($ours[$w])) { $bok = true; break; } if (!$bok) continue;
      // модель поставщика бывает «Character Ice 8 (Nordman 8)» — годится любое из двух названий
      $m = (string)$c['model']; $alt = preg_match('/\(([^)]*)\)/u', $m, $mm) ? $mm[1] : ''; $main = trim(preg_replace('/\([^)]*\)/u', ' ', $m)); $best = 0;
      foreach ([$main, $alt] as $v) { $mw = p4tWords($v); if (!$mw) continue; $all = true; foreach ($mw as $w => $_) if (!isset($ours[$w])) { $all = false; break; } if ($all) $best = max($best, count($mw)); }
      if (!$best) continue;
      // у Ikon одна шина под двумя кодами: «Character Ice 8 (Nordman 8)» и «Nordman 8 (Character Ice 8)» — берём тот, где основное название как у нас
      $om = p4tWords((string)($a['model'] ?? '')); $pm = p4tWords($main); if ($om && $pm && array_keys($om) == array_keys($pm)) $best += 0.3;
      $hit[] = ['cae' => $c['cae'], 'name' => $c['brand'] . ' ' . $c['name'], 'score' => (int)round($best * 10) + p4tCodeRank($c['cae'])];
    }
  } elseif ($kind === 'disk') {
    if (empty($a['d']) || empty($a['w'])) return ['st' => 'nosize'];
    $key = 'd' . $a['d'] . '/' . $a['w'];
    if (!isset($q[$key])) { $st = $p->prepare("SELECT * FROM p4t WHERE kind = 'disk' AND d = ? AND w = ?"); $st->execute([(float)$a['d'], (float)str_replace(',', '.', (string)$a['w'])]); $q[$key] = $st->fetchAll(); }
    $pcds = array_map(fn($x) => str_replace(['×', '*', 'Х', 'х'], 'x', mb_strtolower((string)$x)), (array)($a['pcds'] ?? [$a['pcd'] ?? '']));
    $ours = p4tWords(($a['model'] ?? '') . ' ' . $prod['name']); $oc = p4tColor(($a['model'] ?? '') . ' ' . $prod['name']); $hit = [];
    foreach ($q[$key] as $c) {
      if (!in_array(mb_strtolower((string)$c['pcd']), $pcds, true)) continue;
      if (isset($a['et']) && $a['et'] !== '' && abs((float)$c['et'] - (float)str_replace(',', '.', (string)$a['et'])) > 0.01) continue;
      if (!empty($a['dia']) && abs((float)$c['dia'] - (float)str_replace(',', '.', (string)$a['dia'])) > 0.05) continue;
      $need = p4tWords($c['brand'] . ' ' . $c['model']); $all = true; foreach ($need as $w => $_) if (!isset($ours[$w])) { $all = false; break; } if (!$all || !$need) continue;
      $cw = p4tColor((string)$c['color']); $cok = 0; foreach ($cw as $w => $_) if (isset($oc[$w])) $cok++;
      // явные цвета разные (у нас silver, у них Black) — не он
      $K = ['SILVER' => 1, 'BLACK' => 1, 'WHITE' => 1, 'GRAY' => 1, 'GREY' => 1, 'GOLD' => 1, 'BRONZE' => 1, 'RED' => 1];
      $k1 = array_intersect_key($oc, $K); $k2 = array_intersect_key($cw, $K); if ($k1 && $k2 && !array_intersect_key($k1, $k2)) continue;
      $hit[] = ['cae' => $c['cae'], 'name' => $c['brand'] . ' ' . $c['name'], 'model' => mb_strtoupper($c['brand'] . '|' . $c['model']), 'color' => mb_strtoupper((string)$c['color']), 'cok' => $cw && $cok === count($cw),
        'score' => count($need) * 10 + ($cw && $cok === count($cw) ? 8 : 0) + p4tCodeRank($c['cae'])];
    }
    // у модели несколько цветов — цвет должен совпасть, иначе не угадываем
    $colors = []; foreach ($hit as $h) $colors[$h['model']][$h['color']] = true;
    $hit = array_values(array_filter($hit, fn($h) => $h['cok'] || count($colors[$h['model']]) < 2));
  } else return ['st' => 'kind'];
  if (!$hit) return ['st' => 'none'];
  usort($hit, fn($x, $y) => $y['score'] <=> $x['score']);
  if (count($hit) > 1 && $hit[1]['score'] === $hit[0]['score']) return ['st' => 'amb', 'alts' => array_slice($hit, 0, 4)];
  return ['st' => 'ok', 'cae' => $hit[0]['cae'], 'name' => $hit[0]['name']];
}
// ===== загрузка документов поставщиков: сопоставление строк с карточками =====
function codeNorm(string $c): string { return ltrim(preg_replace('/[\s\-\.\/]+/u', '', mb_strtoupper(trim($c))), '0'); }
function partnerKey(string $p): string { return trim(preg_replace('/[^0-9A-ZА-ЯЁ]+/u', ' ', mb_strtoupper(preg_replace('/^(ООО|ОАО|АО|ЗАО|ИП)\s+/ui', '', str_replace(['«', '»', '"'], '', $p))))); }
function nameTokens(string $s): array {
  $s = mb_strtoupper(str_replace(['*', '×', ',', '\\', 'Х'], ['X', 'X', '.', ' ', 'X'], $s));
  $stop = ['АВТОШИНА', 'АВТОШИНЫ', 'ШИНА', 'ДИСК', 'TL', 'TYRES', 'ШТ', 'ШИП', 'Ш', 'XL', 'ET', 'DIA', 'D', 'R', 'ЛИТОЙ', 'ШТАМП'];
  $t = preg_split('/[\s\/()\[\]]+/u', $s); $o = [];
  foreach ($t as $w) { $w = trim($w, '.-'); if ($w === '' || in_array($w, $stop, true) || mb_strlen($w) < 2 && !ctype_digit($w)) continue; $o[$w] = true; }
  // центровочное кольцо: «65,1 60,1» и «65.1-60.1» — один и тот же размер
  if (preg_match('/КОЛЬЦ/u', $s) && preg_match('/(\d{2,3}(?:\.\d)?)\s*[-\s]\s*(\d{2,3}(?:\.\d)?)/u', $s, $m)) $o['RING' . (float)$m[1] . '-' . (float)$m[2]] = true;
  return array_map('strval', array_keys($o));
}
function tireSize(string $s): ?array { if (preg_match('~(\d{3})\s*/\s*(\d{2})\s*(?:/|\s|Z?R)\s*(\d{2})(\s*C\b)?~iu', $s, $m)) return ['w' => (int)$m[1], 'p' => (int)$m[2], 'd' => (int)$m[3]]; return null; }
function diskSpec(string $s): ?array { $s = str_replace(',', '.', $s); $d = null; if (preg_match('~(?:\\|\bR|^)\s*(1[3-9]|2[0-4])\b~u', $s, $m)) $d = (int)$m[1];
  $pcd = preg_match('~(\d)\s*[*xх×]\s*(\d{2,3}(?:\.\d+)?)~u', $s, $m) ? $m[1] . '×' . (float)$m[2] : null; $et = preg_match('~ET\s*(-?\d+(?:\.\d+)?)~i', $s, $m) ? (float)$m[1] : null;
  return $d || $pcd ? ['d' => $d, 'pcd' => $pcd, 'et' => $et] : null; }
function post(): void { if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Нужен POST', 405); }

try {
  switch ($a) {

  case 'me':
    if ($role === 'owner' || $role === 'master') { try { backupNow($cfg); } catch (Throwable $e) {} }
    out(['role' => $role, 'seller' => $_SESSION['seller'] ?? '', 'coupon' => $role && $role !== 'seller' ? couponValue($cfg) : null]);

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
    // продавцы витрины: в config.php 'sellers' => ['Сергей' => '2468', ...]
    $seller = '';
    if (!$r && $pin !== '') foreach ((array)($cfg['sellers'] ?? []) as $nm => $sp) if ((string)$sp !== '' && hash_equals((string)$sp, $pin)) { $r = 'seller'; $seller = (string)$nm; break; }
    if (!$r) { $_SESSION['tries'][] = $now; fail('Неверный PIN', 403); }
    session_regenerate_id(true);
    $_SESSION['role'] = $r; $_SESSION['tries'] = []; $_SESSION['seller'] = $seller !== '' ? $seller : ($r === 'cashier' ? 'Касса' : ($r === 'owner' ? 'Собственник' : ''));
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
    $sd = staffDays($cfg, $day, $day)[$day];
    out(['orders' => withClients($cfg, $orders), 'helper' => (bool)$h->fetchColumn(), 'bookings' => withClients($cfg, bookingRows($cfg, 'day = ?', [$day])), 'staff' => $sd, 'names' => staffNames($cfg)]);

  case 'helper':
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $b = body(); if (!isDay($b['day'] ?? null)) fail('Неверная дата');
    db($cfg)->prepare('INSERT OR IGNORE INTO days (day, helper) VALUES (?, 0)')->execute([$b['day']]);
    db($cfg)->prepare('UPDATE days SET helper = ? WHERE day = ?')->execute([!empty($b['helper']) ? 1 : 0, $b['day']]);
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
      if ($mode === 'full') $o['pay'] = in_array($o['pay'] ?? '', ['cash','card','qr','transfer','invoice'], true) ? $o['pay'] : 'card';
    } else {
      $o['mode'] = in_array($o['mode'] ?? '', ['full','disc','coupon'], true) ? $o['mode'] : 'full';
      // купон: производитель возмещает фиксированную сумму, клиент не платит; разница с прайсом — наш плюс или минус
      $o['coupon'] = $o['mode'] === 'coupon' ? max(0, (int)($o['coupon'] ?? couponValue($cfg))) : 0;
      $o['extra'] = 0;
      $o['paid'] = $o['mode'] === 'coupon' ? $o['coupon'] + $o['extra'] : ($o['mode'] === 'disc' ? max(0, (int)($o['paid'] ?? 0)) : $list);
      $o['pay'] = in_array($o['pay'] ?? '', ['cash','card','qr','transfer','invoice'], true) ? $o['pay'] : 'card';
    }
    // счёт организации: название и № счёта пишет кто оформляет; дату оплаты — только касса/собственник (inv_set)
    if (($o['pay'] ?? '') === 'invoice') { $o['org'] = mb_substr(trim((string)($o['org'] ?? '')), 0, 200); $o['invNo'] = mb_substr(trim((string)($o['invNo'] ?? ($prev['invNo'] ?? ''))), 0, 40);
      $o['invDay'] = isDay($o['invDay'] ?? null) ? $o['invDay'] : ($prev['invDay'] ?? ''); $o['invPaid'] = $prev['invPaid'] ?? ''; }
    else { unset($o['org'], $o['invNo'], $o['invDay'], $o['invPaid']); }
    $o['t'] = (int)($prev['t'] ?? ($o['t'] ?? round(microtime(true) * 1000)));
    foreach (['name', 'phone', 'plate', 'model', 'note'] as $k) $o[$k] = mb_substr(trim((string)($o[$k] ?? '')), 0, 200);
    $pk = phoneKey($o['phone']); if ($pk === '' && strlen(digits($o['phone'])) <= 1) $o['phone'] = '';
    $o['phoneD'] = $pk !== '' ? $pk : digits($o['phone']);
    $o['sk'] = mb_strtoupper(trim($o['name'] . ' ' . $o['plate'] . ' ' . $o['model']));
    touchClient($cfg, $pk, $o['name'], $o['model'], $o['plate']);
    // счёт на организацию — она сама попадёт в организации клиента в CRM (если ещё ни за кем не числится)
    if (($o['pay'] ?? '') === 'invoice') orgLink($cfg, $pk, (string)($o['org'] ?? ''));
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

  case 'staff':
    // кто сегодня мастер и помощник
    post(); needRole($role, ['owner', 'master', 'cashier']);
    $b = body(); if (!isDay($b['day'] ?? null)) fail('Неверная дата');
    db($cfg)->prepare('INSERT OR IGNORE INTO days (day, helper) VALUES (?, 0)')->execute([$b['day']]);
    if (array_key_exists('master', $b)) db($cfg)->prepare('UPDATE days SET master = ? WHERE day = ?')->execute([mb_substr(trim((string)$b['master']), 0, 40), $b['day']]);
    if (array_key_exists('helperName', $b)) db($cfg)->prepare('UPDATE days SET helper_name = ? WHERE day = ?')->execute([mb_substr(trim((string)$b['helperName']), 0, 40), $b['day']]);
    logit($cfg, $role, 'staff', $b['day'] . ' ' . ($b['master'] ?? '') . '/' . ($b['helperName'] ?? ''));
    out(['ok' => true, 'staff' => staffDays($cfg, $b['day'], $b['day'])[$b['day']]]);

  case 'perf':
    // итоги шиномонтажа за период: машины, работы, оплата, кто работал, расходы
    needRole($role, ['owner']);
    $f = $_GET['from'] ?? ''; $t = $_GET['to'] ?? ''; if (!isDay($f) || !isDay($t) || $t < $f) fail('Неверный период');
    $st = db($cfg)->prepare('SELECT id, day, body FROM orders WHERE day BETWEEN ? AND ? ORDER BY day, t'); $st->execute([$f, $t]);
    $orders = array_map(function ($r) { $b = json_decode($r['body'], true) ?: []; $L = [];
      foreach ($b['lines'] ?? [] as $l) if ((int)($l['q'] ?? 0) > 0) $L[] = ['k' => $l['k'] ?? '', 'n' => $l['n'] ?? '', 'q' => (int)$l['q'], 's' => (int)$l['q'] * (int)($l['p'] ?? 0)];
      return ['id' => (int)$r['id'], 'day' => $r['day'], 'list' => (int)($b['list'] ?? 0), 'paid' => (int)($b['paid'] ?? 0), 'h' => (int)($b['h'] ?? 0), 'mode' => $b['mode'] ?? 'full', 'coupon' => (int)($b['coupon'] ?? 0),
        'pay' => $b['pay'] ?? 'card', 'car' => $b['car'] ?? 'car', 'd' => $b['d'] ?? null, 'lines' => $L, 'phone' => phoneKey($b['phone'] ?? ''), 'org' => $b['org'] ?? '', 'invNo' => $b['invNo'] ?? '', 'invPaid' => $b['invPaid'] ?? '']; }, $st->fetchAll());
    $e = db($cfg)->prepare('SELECT day, sum, kind, cat FROM expenses WHERE day BETWEEN ? AND ?'); $e->execute([$f, $t]);
    out(['orders' => $orders, 'days' => staffDays($cfg, $f, $t), 'expenses' => $e->fetchAll()]);

  case 'inv_list':
    // счета организациям: что выставлено, что оплачено
    needRole($role, ['owner', 'cashier']);
    $rows = [];
    foreach (db($cfg)->query('SELECT id, day, body FROM orders ORDER BY day DESC, t DESC')->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; if (($b['pay'] ?? '') !== 'invoice') continue;
      $rows[] = ['id' => (int)$r['id'], 'day' => $r['day'], 'org' => $b['org'] ?? '', 'name' => $b['name'] ?? '', 'phone' => $b['phone'] ?? '', 'model' => $b['model'] ?? '', 'plate' => $b['plate'] ?? '', 'sum' => (int)($b['paid'] ?? 0), 'invNo' => $b['invNo'] ?? '', 'invDay' => $b['invDay'] ?? '', 'invPaid' => $b['invPaid'] ?? '']; }
    out(['rows' => $rows]);

  case 'inv_set':
    // № счёта, дата счёта, дата прихода оплаты
    post(); needRole($role, ['owner', 'cashier']);
    $b = body(); $id = (int)($b['id'] ?? 0);
    $st = db($cfg)->prepare('SELECT body FROM orders WHERE id = ?'); $st->execute([$id]); $prev = $st->fetchColumn(); if ($prev === false) fail('Запись не найдена', 404);
    $o = json_decode($prev, true);
    foreach (['org' => 200, 'invNo' => 40] as $k => $n) if (array_key_exists($k, $b)) $o[$k] = mb_substr(trim((string)$b[$k]), 0, $n);
    foreach (['invDay', 'invPaid'] as $k) if (array_key_exists($k, $b)) { $v = (string)$b[$k]; if ($v !== '' && !isDay($v)) fail('Неверная дата'); $o[$k] = $v; }
    if (($o['invPaid'] ?? '') !== '') { $o['paidOk'] = true; $o['paidAt'] = $o['paidAt'] ?? time(); }
    db($cfg)->prepare('UPDATE orders SET body = ?, updated = ? WHERE id = ?')->execute([json_encode($o, JSON_UNESCAPED_UNICODE), time(), $id]);
    logit($cfg, $role, 'inv_set', $id . ' ' . ($o['invNo'] ?? '') . ' ' . ($o['invPaid'] ?? ''));
    out(['ok' => true]);

  case 'paid':
    // касса отмечает оплату — мастер видит и отпускает машину
    post(); needRole($role, ['owner', 'cashier']);
    $b = body(); $id = (int)($b['id'] ?? 0);
    $st = db($cfg)->prepare('SELECT body FROM orders WHERE id = ?'); $st->execute([$id]); $prev = $st->fetchColumn();
    if ($prev === false) fail('Запись уже удалена', 404);
    $o = json_decode($prev, true);
    $o['paidOk'] = !empty($b['paid']); $o['paidAt'] = $o['paidOk'] ? time() : null; $o['changedAfterPaid'] = false;
    if (in_array($b['pay'] ?? '', ['cash', 'card', 'qr', 'transfer', 'invoice'], true)) $o['pay'] = $b['pay'];
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
    touchClient($cfg, $pk, $keep['name'], $keep['model'], $keep['plate']);
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
    $oq = orgKey($q); if (mb_strlen($oq) >= 2) foreach (db($cfg)->query('SELECT phone, okey FROM client_orgs')->fetchAll() as $o) if (mb_strpos($o['okey'], $oq) !== false) $phones[$o['phone']] = true;
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
    // 3) покупки в магазине (проведённые продажи и возвраты) — те же клиенты, что и на шиномонтаже
    $buys = []; $bid = [];
    foreach (db($cfg)->query("SELECT id, type, day, phone, partner FROM docs WHERE type IN ('sale','ret') AND status = 'posted' ORDER BY day DESC, id DESC LIMIT 5000")->fetchAll() as $r) {
      $k = (string)$r['phone'];
      if (!(($k !== '' && isset($phones[$k])) || ($r['partner'] !== '' && mb_strpos(mb_strtoupper($r['partner']), $Q) !== false) || (strlen($dq) >= 4 && $k !== '' && strpos($k, $dq) !== false))) continue;
      $buys[(int)$r['id']] = ['id' => (int)$r['id'], 'type' => $r['type'], 'day' => $r['day'], 'phone' => $k, 'name' => (string)$r['partner'], 'items' => [], 'sum' => 0.0];
      if (count($buys) >= 200) break;
    }
    // покупки из архива CloudShop
    $ab = []; $aw = []; $aa = [];
    foreach (array_keys($phones) as $k) { $aw[] = 'a.phone = ?'; $aa[] = $k; }
    if (strlen($dq) >= 4) { $aw[] = "a.phone LIKE ?"; $aa[] = '%' . $dq . '%'; }
    if ($aw) { $st = db($cfg)->prepare("SELECT a.doc, a.type, a.day, a.phone, a.party, a.name, a.qty, a.price FROM arch a WHERE a.type IN ('sale','ret') AND (" . implode(' OR ', $aw) . ") ORDER BY a.day DESC LIMIT 600"); $st->execute($aa);
      foreach ($st->fetchAll() as $r) { $k = 'a' . $r['doc']; if (!isset($ab[$k])) $ab[$k] = ['id' => 0, 'type' => $r['type'], 'day' => $r['day'], 'phone' => (string)$r['phone'], 'name' => (string)$r['party'], 'items' => [], 'sum' => 0.0, 'arch' => 1];
        $q = abs((float)$r['qty']); $ab[$k]['items'][] = $r['name'] . ($q != 1 ? ' ×' . (0 + $q) : ''); $ab[$k]['sum'] += ($r['type'] === 'ret' ? -1 : 1) * $q * (float)$r['price']; } }
    if ($buys) { $ids = array_keys($buys); $mv = db($cfg)->prepare('SELECT m.doc_id, m.qty, m.price, p.name FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY m.id'); $mv->execute($ids);
      foreach ($mv->fetchAll() as $m) { $x = &$buys[(int)$m['doc_id']]; $q = abs((float)$m['qty']); $x['items'][] = $m['name'] . ($q != 1 ? ' ×' . (0 + $q) : ''); $x['sum'] += ($x['type'] === 'ret' ? -1 : 1) * $q * (float)$m['price']; unset($x); } }
    $buys = array_values(array_map(function ($x) { $x['items'] = implode(', ', $x['items']); $x['sum'] = round($x['sum'], 2); return $x; }, array_merge($buys, $ab)));
    // 4) заказы клиентов
    $zk = [];
    foreach (db($cfg)->query('SELECT * FROM zak ORDER BY day DESC, id DESC LIMIT 3000')->fetchAll() as $r) {
      $k = (string)$r['phone'];
      if (!(($k !== '' && isset($phones[$k])) || ($r['name'] !== '' && mb_strpos(mb_strtoupper($r['name']), $Q) !== false) || (strlen($dq) >= 4 && $k !== '' && strpos($k, $dq) !== false))) continue;
      $zk[] = zakRow($r, $role); if (count($zk) >= 100) break;
    }
    // 5) шинный отель: что клиент сдавал на хранение
    $stor = [];
    if ($hp = hotelPdo($cfg)) { try {
      foreach ($hp->query("SELECT * FROM items WHERE status IN ('stored','issued') AND (deleted_at IS NULL OR deleted_at = '') ORDER BY accepted_at DESC, id DESC LIMIT 3000")->fetchAll() as $r) {
        $k = (string)$r['phone_digits'];
        if (!(($k !== '' && isset($phones[$k])) || mb_strpos(mb_strtoupper((string)$r['client']), $Q) !== false || (strlen($dq) >= 4 && $k !== '' && strpos($k, $dq) !== false))) continue;
        $stor[] = storRow($r); if (count($stor) >= 60) break;
      } } catch (Throwable $e) {} }
    // справочник по всем найденным телефонам
    $all = array_merge($orders, $bk, $buys, $zk, $stor); $keys = [];
    foreach ($all as $x) { $k = phoneKey($x['phone'] ?? ''); if ($k) $keys[$k] = true; }
    foreach ($phones as $k => $_) $keys[$k] = true;
    $map = [];
    if ($keys) { $kk = array_keys($keys); $st = db($cfg)->prepare('SELECT phone, name, model, note, bday, tags FROM clients WHERE phone IN (' . implode(',', array_fill(0, count($kk), '?')) . ')'); $st->execute($kk);
      foreach ($st->fetchAll() as $c) { $bi = $c['bday'] !== '' ? bdayIn($c['bday']) : null; $map[$c['phone']] = ['name' => $c['name'], 'model' => $c['model'], 'note' => $c['note'], 'tags' => $c['tags'], 'bday_in' => $bi, 'orgs' => [], 'inv' => []]; }
      // организации клиента и неоплаченные счета (по телефону или на его организацию)
      $okeys = []; foreach (db($cfg)->query('SELECT phone, org, okey FROM client_orgs')->fetchAll() as $o) if (isset($keys[$o['phone']])) { if (!isset($map[$o['phone']])) $map[$o['phone']] = ['name' => '', 'model' => '', 'orgs' => [], 'inv' => []]; $map[$o['phone']]['orgs'][] = $o['org']; $okeys[$o['okey']] = $o['phone']; }
      foreach (db($cfg)->query("SELECT day, body FROM orders WHERE body LIKE '%invoice%'")->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; if (($b['pay'] ?? '') !== 'invoice' || ($b['invPaid'] ?? '') !== '') continue;
        $k = phoneKey($b['phone'] ?? ''); if ($k === '' || !isset($keys[$k])) $k = $okeys[orgKey($b['org'] ?? '')] ?? ''; if ($k === '' || !isset($map[$k])) continue;
        $map[$k]['inv'][] = ['day' => $r['day'], 'no' => $b['invNo'] ?? '', 'org' => $b['org'] ?? '', 'sum' => (int)($b['paid'] ?? 0)]; } }
    out(['orders' => $orders, 'bookings' => $bk, 'buys' => $buys, 'zak' => $zk, 'stor' => $stor, 'clients' => $map ?: new stdClass()]);

  // ===== CRM (только собственник: тут деньги и прибыль) =====
  case 'crm_list':
    needRole($role, ['owner']);
    $R = crmAll($cfg); $q = mb_strtoupper(trim((string)($_GET['q'] ?? ''))); $dq = digits($q); $seg = (string)($_GET['seg'] ?? ''); $sort = (string)($_GET['sort'] ?? 'prof');
    $cnt = ['all' => 0]; $tot = ['n' => 0, 'phone' => 0, 'active' => 0, 'store' => 0, 'bday' => 0, 'rev' => 0];
    $act = date('Y-m-d', strtotime('-12 months')); $rows = [];
    foreach ($R as $x) {
      $cnt['all']++; foreach ($x['seg'] as $t) $cnt[$t] = ($cnt[$t] ?? 0) + 1;
      $tot['n']++; if ($x['last'] >= $act) $tot['active']++; if ($x['h_now']) $tot['store']++; if (in_array('bday', $x['seg'], true)) $tot['bday']++; $tot['rev'] += $x['rev'];
      if ($seg !== '' && $seg !== 'all' && !in_array($seg, $x['seg'], true)) continue;
      if ($q !== '') { $hay = mb_strtoupper($x['name'] . ' ' . $x['note'] . ' ' . $x['tags'] . ' ' . ($x['inn'] ?? '') . ' ' . ($x['ocontact'] ?? '') . ' ' . implode(' ', $x['orgs'] ?? []) . ' ' . implode(' ', array_map(fn($c) => $c['car'] . ' ' . $c['plate'] . ' ' . plateKey($c['plate']), $x['cars'])));
        if (mb_strpos($hay, $q) === false && !(strlen($dq) >= 3 && (strpos($x['phone'], $dq) !== false || strpos(digits($x['phone2']), $dq) !== false))) continue; }
      $rows[] = $x; }
    $key = ['prof' => fn($x) => -$x['prof'], 'rev' => fn($x) => -$x['rev'], 'visits' => fn($x) => -($x['m_n'] * 1000 + $x['s_n']), 'last' => fn($x) => $x['last'] === '' ? 0 : -(int)str_replace('-', '', $x['last']), 'name' => fn($x) => $x['name'] === '' ? 'яяя' : mb_strtolower($x['name'])][$sort] ?? fn($x) => -$x['prof'];
    usort($rows, fn($a, $b) => $key($a) <=> $key($b));
    out(['rows' => array_slice($rows, 0, 400), 'n' => count($rows), 'cnt' => $cnt, 'total' => $tot]);

  case 'crm_card':
    needRole($role, ['owner']);
    $k = phoneKey($_GET['phone'] ?? ''); if ($k === '') fail('Нужен номер телефона');
    $R = crmAll($cfg); $x = $R[$k] ?? null;
    $st = db($cfg)->prepare('SELECT id, car, plate, year, size, note FROM client_cars WHERE phone = ? ORDER BY id'); $st->execute([$k]); $cars = $st->fetchAll();
    $tt = db($cfg)->prepare('SELECT id, day, kind, why, note, author FROM crm_touch WHERE phone = ? ORDER BY day DESC, id DESC LIMIT 50'); $tt->execute([$k]);
    $calls = []; foreach (crmCalls($x ? [$k => $x] : []) as $c) $calls[] = $c;
    $og = db($cfg)->prepare('SELECT org, okey, role FROM client_orgs WHERE phone = ? ORDER BY id'); $og->execute([$k]); $orgs = $og->fetchAll();
    $own = []; foreach ($orgs as $o) if ($o['role'] === 'owner') $own[$o['okey']] = 1;
    $ob = orgHistory($cfg, $own, true);
    out(['client' => $x, 'cars' => $cars, 'touches' => $tt->fetchAll(), 'offer' => $calls, 'orgs' => array_map(fn($o) => ['org' => $o['org'], 'okey' => $o['okey'], 'role' => $o['role']], $orgs), 'org_buys' => $ob]);

  // ===== планировщик задач (собственник) =====
  case 'task_list':
    needRole($role, ['owner']);
    $st = ($_GET['st'] ?? 'open') === 'done' ? 'done' : 'open';
    $q = db($cfg)->prepare("SELECT * FROM tasks WHERE status = ? ORDER BY " . ($st === 'done' ? 'done_at DESC LIMIT 300' : "CASE WHEN due = '' THEN 1 ELSE 0 END, due, prio DESC, id")); $q->execute([$st]);
    $who = db($cfg)->query("SELECT who, COUNT(*) n, SUM(status = 'open') o FROM tasks WHERE who != '' GROUP BY who ORDER BY MAX(id) DESC")->fetchAll();
    out(['rows' => $q->fetchAll(), 'who' => $who, 'open' => (int)db($cfg)->query("SELECT COUNT(*) FROM tasks WHERE status = 'open'")->fetchColumn(),
      'late' => (int)db($cfg)->query("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due != '' AND due < '" . date('Y-m-d') . "'")->fetchColumn()]);

  case 'task_save':
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $t = mb_substr(trim((string)($b['title'] ?? '')), 0, 300); if ($t === '') fail('Напишите, что сделать');
    $due = (string)($b['due'] ?? ''); if ($due !== '' && !isDay($due)) fail('Неверная дата');
    $v = [$t, mb_substr(trim((string)($b['note'] ?? '')), 0, 2000), mb_substr(trim((string)($b['who'] ?? '')), 0, 60), $due, !empty($b['prio']) ? 1 : 0, phoneKey($b['phone'] ?? '')];
    if ($id) { $v[] = $id; db($cfg)->prepare('UPDATE tasks SET title = ?, note = ?, who = ?, due = ?, prio = ?, phone = ? WHERE id = ?')->execute($v); }
    else { db($cfg)->prepare("INSERT INTO tasks (title, note, who, due, prio, phone, status, created, author) VALUES (?,?,?,?,?,?, 'open', ?, ?)")->execute(array_merge($v, [time(), $role])); $id = (int)db($cfg)->lastInsertId(); }
    out(['ok' => true, 'id' => $id]);

  case 'task_done':
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0);
    if (!empty($b['del'])) { db($cfg)->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]); out(['ok' => true]); }
    $d = !empty($b['done']); db($cfg)->prepare('UPDATE tasks SET status = ?, done_at = ? WHERE id = ?')->execute([$d ? 'done' : 'open', $d ? time() : null, $id]);
    out(['ok' => true]);

  case 'sv_upload':
    // сверка: загрузка чеков ОФД (отчёт «Сверка») или выписки банка; повторная загрузка того же периода ничего не дублирует
    post(); needRole($role, ['owner']);
    $b = body(); $p = db($cfg); $kind = (string)($b['kind'] ?? ''); $rows = array_slice((array)($b['rows'] ?? []), 0, 20000); $n = 0; $days = [];
    $rd = function ($v) { $v = trim((string)$v); if (preg_match('/^(\d{4})-(\d\d)-(\d\d)/', $v, $m)) return "$m[1]-$m[2]-$m[3]"; if (preg_match('/^(\d\d)\.(\d\d)\.(\d{4})/', $v, $m)) return "$m[3]-$m[2]-$m[1]"; return ''; };
    $p->beginTransaction();
    if ($kind === 'ofd') {
      $ins = $p->prepare('INSERT OR REPLACE INTO ofd (fd, day, tm, type, total, cash, cashless, shift) VALUES (?,?,?,?,?,?,?,?)');
      foreach ($rows as $r) { $day = $rd($r['day'] ?? ''); $fd = preg_replace('/\D/', '', (string)($r['fd'] ?? '')); if ($day === '' || $fd === '') continue;
        $op = mb_strtolower((string)($r['op'] ?? '')); $type = mb_strpos($op, 'возврат прихода') !== false ? 'ret' : (mb_strpos($op, 'приход') === 0 ? 'sale' : ''); if ($type === '') continue;
        $tm = preg_match('/(\d\d:\d\d)/', (string)($r['tm'] ?? ''), $m) ? $m[1] : '';
        $ins->execute([mb_substr((string)($r['kkt'] ?? ''), -8) . '-' . $fd, $day, $tm, $type, (float)($r['total'] ?? 0), (float)($r['cash'] ?? 0), (float)($r['cashless'] ?? 0), mb_substr((string)($r['shift'] ?? ''), 0, 10)]); $n++; $days[$day] = 1; }
    } elseif ($kind === 'bank') {
      $ins = $p->prepare('INSERT OR REPLACE INTO bank (k, acc, day, dir, amount, kind, ref_day, fee, payer, inn, purpose, inv_no, term) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
      foreach ($rows as $r) { $day = $rd($r['day'] ?? ''); $amt = round((float)($r['amount'] ?? 0), 2); if ($day === '' || $amt == 0) continue;
        $dir = mb_strpos(mb_strtolower((string)($r['dir'] ?? '')), 'кредит') === 0 ? 'in' : 'out'; $t = trim((string)($r['purpose'] ?? '')); $payer = mb_substr(trim((string)($r['payer'] ?? '')), 0, 200);
        $k2 = 'other'; $ref = ''; $fee = 0.0; $inv = ''; $term = '';
        if (preg_match('/терминалам эквайринга от (\d\d)\.(\d\d)\.(\d{4})/u', $t, $m)) { $k2 = $dir === 'in' ? 'acq' : 'acq_fee'; $ref = "$m[3]-$m[2]-$m[1]"; }
        elseif ($dir === 'in' && preg_match('/операциям эквайринга/u', $t)) { $k2 = 'acq'; $ref = $day; if (preg_match('/Комиссия\s+([\d.]+)/u', $t, $m)) $fee = (float)$m[1]; }
        elseif (preg_match('/операции СБП/u', $t)) { if (preg_match('/Терминал\s+([^.]+)/u', $t, $m)) $term = trim($m[1]); $mine = $term === '' || preg_match('/' . preg_quote((string)($cfg['sbp_terminal'] ?? 'Shinnyi'), '/') . '/iu', $term); $k2 = $dir === 'in' ? ($mine ? 'sbp' : 'other') : ($mine ? 'sbp_fee' : 'other'); $ref = $day; }
        elseif ($dir === 'in' && preg_match('/собственных средств|овердрафт|депозит/iu', $t)) $k2 = 'own';
        elseif ($dir === 'in' && preg_match('/сч[её]т\S*(?:\s*\([^)]*\))?(?:\s+на\s+оплату)?\s*(?:№|N|#)?\s*№?\s*(\d{1,6})\b/iu', $t, $m)) { $k2 = 'invoice'; $inv = $m[1]; }
        elseif ($dir === 'in' && preg_match('/сч\.\s*(?:оферта)?(?:\s*\([^)]*\))?\s*№\s*(\d{1,6})\b/iu', $t, $m)) { $k2 = 'invoice'; $inv = $m[1]; }
        elseif ($dir === 'in') $k2 = 'in_other';
        $ins->execute([mb_substr((string)($r['acc'] ?? ''), -6) . '|' . $day . '|' . (string)($r['doc'] ?? '') . '|' . $amt . '|' . $dir, mb_substr((string)($r['acc'] ?? ''), 0, 24), $day, $dir, $amt, $k2, $ref, $fee, $payer, mb_substr(preg_replace('/\D/', '', (string)($r['inn'] ?? '')), 0, 12), mb_substr($t, 0, 400), $inv, mb_substr($term, 0, 60)]); $n++; $days[$day] = 1; }
    } else { $p->rollBack(); fail('Не понял, что за файл'); }
    $p->commit(); ksort($days); $dk = array_keys($days); logit($cfg, $role, 'sv_upload', $kind . ' ' . $n);
    out(['ok' => true, 'n' => $n, 'from' => $dk[0] ?? '', 'to' => $dk ? end($dk) : '']);

  case 'sv_report':
    // сверка за период: пульт ↔ чеки ОФД ↔ банк; счета организаций из выписки ↔ счета в пульте
    needRole($role, ['owner']);
    $p = db($cfg); $lim = $p->query('SELECT MIN(day) a, MAX(day) b FROM ofd')->fetch(); $bl = $p->query('SELECT MIN(day) a, MAX(day) b FROM bank')->fetch();
    $from = isDay($_GET['from'] ?? '') ? $_GET['from'] : ($lim['b'] ? date('Y-m-d', strtotime($lim['b'] . ' -6 days')) : date('Y-m-01')); $to = isDay($_GET['to'] ?? '') ? $_GET['to'] : ($lim['b'] ?: date('Y-m-d'));
    $D = []; $day = function ($d) use (&$D) { if (!isset($D[$d])) $D[$d] = ['day' => $d, 'p_cash' => 0.0, 'p_nc' => 0.0, 'o_cash' => 0.0, 'o_nc' => 0.0, 'o_n' => 0, 'b_acq' => 0.0, 'b_sbp' => 0.0, 'b_fee' => 0.0]; };
    // пульт: всё, что должно пройти через кассу
    $P = []; $st = $p->prepare('SELECT id, day, body FROM orders WHERE day >= ? AND day <= ?'); $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; $sum = (float)($b['paid'] ?? 0); $pay = (string)($b['pay'] ?? ''); if ($sum <= 0 || ($b['mode'] ?? '') === 'coupon' || !in_array($pay, ['cash', 'card', 'qr'], true)) continue;
      $P[] = ['day' => $r['day'], 'sum' => round($sum, 2), 'cls' => $pay === 'cash' ? 'cash' : 'nc', 'what' => 'Шиномонтаж: ' . trim(($b['name'] ?? '') . ' ' . ($b['model'] ?? '') . ' ' . ($b['plate'] ?? '')), 'pay' => $pay, 'ref' => ['order' => ['id' => (int)$r['id'], 'day' => $r['day']] + $b]]; }
    $st = $p->prepare("SELECT d.id, d.day, d.type, d.pay, d.partner, SUM(ABS(m.qty) * IFNULL(m.price, 0)) s FROM docs d JOIN moves m ON m.doc_id = d.id WHERE d.type IN ('sale','ret') AND d.status = 'posted' AND d.day >= ? AND d.day <= ? GROUP BY d.id"); $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) { if (!in_array($r['pay'], ['cash', 'card', 'qr', ''], true)) continue; $sum = round((float)$r['s'], 2); if ($sum <= 0) continue;
      $P[] = ['day' => $r['day'], 'sum' => ($r['type'] === 'ret' ? -1 : 1) * $sum, 'cls' => $r['pay'] === 'cash' ? 'cash' : 'nc', 'what' => ($r['type'] === 'ret' ? 'Возврат' : 'Продажа') . ' № ' . $r['id'] . ($r['partner'] !== '' ? ' · ' . $r['partner'] : ''), 'pay' => (string)$r['pay'], 'ref' => ['doc' => (int)$r['id']]]; }
    foreach ($P as $x) { $day($x['day']); $D[$x['day']][$x['cls'] === 'cash' ? 'p_cash' : 'p_nc'] += $x['sum']; }
    $O = []; $st = $p->prepare('SELECT * FROM ofd WHERE day >= ? AND day <= ? ORDER BY day, tm'); $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) { $sg = $r['type'] === 'ret' ? -1 : 1; $day($r['day']); $D[$r['day']]['o_cash'] += $sg * (float)$r['cash']; $D[$r['day']]['o_nc'] += $sg * (float)$r['cashless']; $D[$r['day']]['o_n']++;
      $O[] = ['day' => $r['day'], 'tm' => $r['tm'], 'sum' => $sg * round((float)$r['total'], 2), 'cls' => (float)$r['cash'] > 0 && (float)$r['cashless'] == 0 ? 'cash' : ((float)$r['cash'] > 0 ? 'mix' : 'nc'), 'fd' => substr($r['fd'], strpos($r['fd'], '-') + 1)]; }
    $st = $p->prepare("SELECT * FROM bank WHERE (day >= ? AND day <= ?) OR (ref_day >= ? AND ref_day <= ?) ORDER BY day"); $st->execute([$from, date('Y-m-d', strtotime($to . ' +6 days')), $from, $to]); $INV = []; $OTH = []; $invIn = 0.0;
    foreach ($st->fetchAll() as $r) { $rd2 = $r['ref_day'] !== '' ? $r['ref_day'] : $r['day']; $in = $rd2 >= $from && $rd2 <= $to;
      if ($r['kind'] === 'acq' && $in) { $day($rd2); $D[$rd2]['b_acq'] += (float)$r['amount'] + (float)$r['fee']; $D[$rd2]['b_fee'] += (float)$r['fee']; }
      elseif ($r['kind'] === 'sbp' && $in) { $day($rd2); $D[$rd2]['b_sbp'] += (float)$r['amount']; }
      elseif (in_array($r['kind'], ['acq_fee', 'sbp_fee'], true) && $in) { $day($rd2); $D[$rd2]['b_fee'] += (float)$r['amount']; }
      elseif ($r['kind'] === 'invoice' && $r['day'] >= $from && $r['day'] <= $to) { $INV[] = $r; $invIn += (float)$r['amount']; }
      elseif ($r['kind'] === 'in_other' && $r['day'] >= $from && $r['day'] <= $to) $OTH[] = ['day' => $r['day'], 'amount' => (float)$r['amount'], 'payer' => $r['payer'], 'purpose' => $r['purpose']]; }
    // чек ↔ запись пульта: тот же день и сумма (сначала с тем же способом оплаты, потом с любым)
    $free = $O; $noCheck = []; $payDiff = 0;
    foreach ([true, false] as $strict) { foreach ($P as $i => $x) { if (!empty($x['ok'])) continue; foreach ($free as $j => $o) { if ($o['day'] === $x['day'] && abs($o['sum'] - $x['sum']) < 0.01 && (!$strict || $o['cls'] === $x['cls'] || $o['cls'] === 'mix')) { $P[$i]['ok'] = true; if (!$strict) $payDiff++; unset($free[$j]); break; } } } }
    foreach ($P as $x) if (empty($x['ok'])) $noCheck[] = ['day' => $x['day'], 'sum' => $x['sum'], 'what' => $x['what'], 'pay' => $x['pay'], 'ref' => $x['ref']];
    // счета организаций: оплата из выписки ↔ счёт в пульте (шиномонтаж по счёту)
    $byNo = []; foreach ($p->query("SELECT id, day, body FROM orders WHERE body LIKE '%invoice%'")->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; if (($b['pay'] ?? '') !== 'invoice') continue; $no = ltrim(preg_replace('/\D/', '', (string)($b['invNo'] ?? '')), '0'); if ($no !== '') $byNo[$no][] = ['id' => (int)$r['id'], 'day' => $r['day'], 'sum' => (float)($b['paid'] ?? 0), 'org' => (string)($b['org'] ?? ''), 'paid' => (string)($b['invPaid'] ?? '')]; }
    $invRows = []; foreach ($INV as $r) { $c = $byNo[ltrim($r['inv_no'], '0')] ?? []; $tot = array_sum(array_column($c, 'sum')); $un = array_values(array_filter($c, fn($x) => $x['paid'] === ''));
      $stt = !$c ? 'none' : (!$un ? 'done' : (abs($tot - (float)$r['amount']) < 0.01 ? 'match' : 'diff'));
      $invRows[] = ['k' => $r['k'], 'day' => $r['day'], 'amount' => (float)$r['amount'], 'payer' => $r['payer'], 'inv_no' => $r['inv_no'], 'purpose' => $r['purpose'], 'st' => $stt, 'pult_sum' => $tot, 'orders' => array_column($c, 'id')]; }
    ksort($D); $T = ['p_cash' => 0, 'p_nc' => 0, 'o_cash' => 0, 'o_nc' => 0, 'o_n' => 0, 'b_acq' => 0, 'b_sbp' => 0, 'b_fee' => 0]; foreach ($D as $d) foreach ($T as $k => $v) $T[$k] += $d[$k];
    out(['from' => $from, 'to' => $to, 'ofd' => $lim, 'bank' => $bl, 'days' => array_values(array_map(fn($d) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $d), $D)), 'tot' => array_map(fn($v) => round($v, 2), $T),
      'no_check' => $noCheck, 'no_pult' => array_values($free), 'pay_diff' => $payDiff, 'inv' => $invRows, 'inv_in' => round($invIn, 2), 'other' => array_slice($OTH, 0, 200)]);

  case 'sv_inv_apply':
    // оплата счёта пришла на расчётный счёт — отмечаем визиты по этому счёту оплаченными (дата — день поступления)
    post(); needRole($role, ['owner']);
    $b = body(); $p = db($cfg); $st = $p->prepare("SELECT * FROM bank WHERE k = ? AND kind = 'invoice'"); $st->execute([(string)($b['k'] ?? '')]); $r = $st->fetch(); if (!$r) fail('Платёж не найден', 404);
    $no = ltrim($r['inv_no'], '0'); $n = 0;
    foreach ($p->query("SELECT id, body FROM orders WHERE body LIKE '%invoice%'")->fetchAll() as $o) { $x = json_decode($o['body'], true) ?: []; if (($x['pay'] ?? '') !== 'invoice' || ($x['invPaid'] ?? '') !== '' || ltrim(preg_replace('/\D/', '', (string)($x['invNo'] ?? '')), '0') !== $no) continue;
      $x['invPaid'] = $r['day']; $x['paidOk'] = true; $x['paidAt'] = $x['paidAt'] ?? time(); $p->prepare('UPDATE orders SET body = ? WHERE id = ?')->execute([json_encode($x, JSON_UNESCAPED_UNICODE), (int)$o['id']]); $n++; }
    logit($cfg, $role, 'sv_inv_apply', 'счёт ' . $r['inv_no'] . ': ' . $n); out(['ok' => true, 'n' => $n]);

  case 'pay_stat':
    // чем платили за период: наличные, карта, QR, перевод, счёт организации — шиномонтаж и магазин (основа для сверки с кассой и банком)
    needRole($role, ['owner']);
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01'); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
    $p = db($cfg); $K = ['cash', 'card', 'qr', 'transfer', 'invoice', 'coupon', 'none']; $R = []; foreach ($K as $k) $R[$k] = ['m' => 0.0, 'mn' => 0, 's' => 0.0, 'sn' => 0];
    $days = []; $due = 0.0; $dueN = 0;
    $st = $p->prepare('SELECT day, body FROM orders WHERE day >= ? AND day <= ?'); $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; $sum = (float)($b['paid'] ?? 0); if ($sum == 0) continue;
      $k = ($b['mode'] ?? '') === 'coupon' ? 'coupon' : (in_array($b['pay'] ?? '', $K, true) ? $b['pay'] : 'none');
      $R[$k]['m'] += $sum; $R[$k]['mn']++; $days[$r['day']][$k] = ($days[$r['day']][$k] ?? 0) + $sum;
      if ($k === 'invoice' && ($b['invPaid'] ?? '') === '') { $due += $sum; $dueN++; } }
    $st = $p->prepare("SELECT d.day, d.type, d.pay, SUM(ABS(m.qty) * IFNULL(m.price, 0)) s FROM docs d JOIN moves m ON m.doc_id = d.id WHERE d.type IN ('sale','ret') AND d.status = 'posted' AND d.day >= ? AND d.day <= ? GROUP BY d.id"); $st->execute([$from, $to]);
    foreach ($st->fetchAll() as $r) { $k = ['cash' => 'cash', 'card' => 'card', 'qr' => 'qr', 'sbp' => 'transfer', 'bank' => 'invoice'][(string)$r['pay']] ?? 'none'; $sum = ($r['type'] === 'ret' ? -1 : 1) * round((float)$r['s'], 2);
      $R[$k]['s'] += $sum; if ($r['type'] === 'sale') $R[$k]['sn']++; $days[$r['day']][$k] = ($days[$r['day']][$k] ?? 0) + $sum; }
    ksort($days); $dl = []; foreach ($days as $d => $v) $dl[] = ['day' => $d] + array_map(fn($x) => round($x), $v);
    out(['from' => $from, 'to' => $to, 'rows' => $R, 'days' => $dl, 'inv_due' => round($due), 'inv_due_n' => $dueN]);

  case 'crm_timeline':
    needRole($role, ['owner']);
    $k = phoneKey($_GET['phone'] ?? ''); $ok = orgKey($_GET['okey'] ?? ''); $p = db($cfg);
    if ($k !== '') { $c = $p->prepare('SELECT phone2 FROM clients WHERE phone = ?'); $c->execute([$k]); $ph2 = (string)$c->fetchColumn();
      $o = $p->prepare("SELECT okey FROM client_orgs WHERE phone = ? AND role = 'owner'"); $o->execute([$k]);
      out(['rows' => crmTimeline($cfg, [$k, $ph2], $o->fetchAll(PDO::FETCH_COLUMN), true)]); }
    if ($ok !== '') { $o = $p->prepare('SELECT phone FROM client_orgs WHERE okey = ?'); $o->execute([$ok]); out(['rows' => crmTimeline($cfg, $o->fetchAll(PDO::FETCH_COLUMN), [$ok], false)]); }
    fail('Не указан клиент');

  case 'org_import':
    // список контрагентов из Контур.Эльбы: новые организации заводим, у известных (по ИНН или названию) дополняем реквизиты и итоги
    post(); needRole($role, ['owner']);
    $b = body(); $p = db($cfg); $byInn = []; $have = [];
    foreach ($p->query('SELECT okey, inn FROM orgs')->fetchAll() as $o) { $have[$o['okey']] = 1; if ($o['inn'] !== '') $byInn[$o['inn']] = $o['okey']; }
    $n = ['new' => 0, 'upd' => 0, 'skip' => 0, 'sup' => 0]; $p->beginTransaction();
    foreach (array_slice((array)($b['rows'] ?? []), 0, 5000) as $r) {
      $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($r['name'] ?? ''))), 0, 200); $inn = mb_substr(preg_replace('/\D/', '', (string)($r['inn'] ?? '')), 0, 12);
      if ($name === '' || orgJunk($name)) { $n['skip']++; continue; }
      if (mb_strpos(mb_strtoupper($name), 'НЕФЕДОВ ИЛЬЯ') !== false) { $n['skip']++; continue; } // это мы сами
      $in = (float)($r['in'] ?? 0); $outS = (float)($r['out'] ?? 0); $grp = mb_strtolower((string)($r['group'] ?? ''));
      $kind = mb_strpos($grp, 'налог') !== false || preg_match('/БАНК|КАЗНАЧЕЙСТВО|ФНС|СКБ КОНТУР|СОЦИАЛЬНОГО ФОНДА|ПЕНСИОННОГО/u', mb_strtoupper($name)) ? 'other' : ((mb_strpos($grp, 'поставщ') !== false || ($outS > 0 && $in <= 0)) ? 'supplier' : 'client');
      if ($kind === 'supplier') $n['sup']++;
      $ok = ($inn !== '' && isset($byInn[$inn])) ? $byInn[$inn] : orgKey($name);
      $v = ['kpp' => mb_substr(preg_replace('/\D/', '', (string)($r['kpp'] ?? '')), 0, 9), 'addr' => mb_substr(trim((string)($r['addr'] ?? '')), 0, 300), 'contact' => mb_substr(trim((string)($r['contact'] ?? '')), 0, 200),
        'ophone' => mb_substr(trim((string)($r['phone'] ?? '')), 0, 100), 'email' => mb_substr(trim((string)($r['email'] ?? '')), 0, 100), 'kind' => $kind, 'elba_in' => $in, 'elba_out' => $outS, 'elba_debt' => (float)($r['debt'] ?? 0)];
      if (isset($have[$ok])) {
        // у известной организации название и заметку не трогаем, пустые реквизиты дополняем, итоги Эльбы обновляем
        $st = $p->prepare('SELECT * FROM orgs WHERE okey = ?'); $st->execute([$ok]); $cur = $st->fetch();
        foreach (['kpp', 'addr', 'contact', 'ophone', 'email'] as $f) if ((string)$cur[$f] !== '' && $v[$f] === '') $v[$f] = $cur[$f]; elseif ((string)$cur[$f] !== '') $v[$f] = $cur[$f];
        $p->prepare("UPDATE orgs SET inn = CASE WHEN inn = '' THEN ? ELSE inn END, kpp = ?, addr = ?, contact = ?, ophone = ?, email = ?, kind = ?, elba_in = ?, elba_out = ?, elba_debt = ?, src = 'elba', upd = ? WHERE okey = ?")
          ->execute([$inn, $v['kpp'], $v['addr'], $v['contact'], $v['ophone'], $v['email'], $v['kind'], $v['elba_in'], $v['elba_out'], $v['elba_debt'], time(), $ok]); $n['upd']++;
      } else {
        $p->prepare("INSERT INTO orgs (okey, name, inn, note, created, kpp, addr, contact, ophone, email, kind, elba_in, elba_out, elba_debt, src, upd) VALUES (?,?,?,'',?,?,?,?,?,?,?,?,?,?,'elba',?)")
          ->execute([$ok, $name, $inn, time(), $v['kpp'], $v['addr'], $v['contact'], $v['ophone'], $v['email'], $v['kind'], $v['elba_in'], $v['elba_out'], $v['elba_debt'], time()]); $have[$ok] = 1; if ($inn !== '') $byInn[$inn] = $ok; $n['new']++;
      }
    }
    $p->commit(); logit($cfg, $role, 'org_import', json_encode($n)); out(['ok' => true] + $n);

  case 'crm_org':
    // карточка организации: реквизиты, люди (владелец, представители), итоги, история
    needRole($role, ['owner']);
    $ok = orgKey($_GET['okey'] ?? ($_GET['name'] ?? '')); if ($ok === '') fail('Не указана организация');
    $p = db($cfg); $st = $p->prepare('SELECT * FROM orgs WHERE okey = ?'); $st->execute([$ok]); $o = $st->fetch() ?: null;
    $pp = $p->prepare('SELECT o.phone, o.org, o.role, c.name FROM client_orgs o LEFT JOIN clients c ON c.phone = o.phone WHERE o.okey = ? ORDER BY CASE o.role WHEN \'owner\' THEN 0 ELSE 1 END, o.id'); $pp->execute([$ok]); $people = $pp->fetchAll();
    $name = $o['name'] ?? ($people[0]['org'] ?? trim((string)($_GET['name'] ?? '')));
    $R = crmAll($cfg); $owner = null; foreach ($people as $x) if ($x['role'] === 'owner') { $owner = $x; break; }
    $row = $R['org:' . $ok] ?? ($owner ? null : null);
    out(['okey' => $ok, 'name' => $name, 'inn' => $o['inn'] ?? '', 'note' => $o['note'] ?? '', 'saved' => (bool)$o, 'kpp' => $o['kpp'] ?? '', 'addr' => $o['addr'] ?? '', 'contact' => $o['contact'] ?? '', 'ophone' => $o['ophone'] ?? '', 'email' => $o['email'] ?? '', 'kind' => $o['kind'] ?? 'client', 'elba_in' => round((float)($o['elba_in'] ?? 0)), 'elba_out' => round((float)($o['elba_out'] ?? 0)), 'elba_debt' => round((float)($o['elba_debt'] ?? 0)), 'src' => $o['src'] ?? '', 'people' => array_map(fn($x) => ['phone' => $x['phone'], 'name' => (string)$x['name'], 'role' => $x['role']], $people),
      'owner' => $owner ? ['phone' => $owner['phone'], 'name' => (string)$owner['name']] : null, 'stat' => $row, 'hist' => orgHistory($cfg, [$ok => 1], false)]);

  case 'org_save':
    post(); needRole($role, ['owner']);
    $b = body(); $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 200); $ok = orgKey($b['okey'] ?? '') ?: orgKey($name); if ($ok === '' || $name === '') fail('Впишите название организации');
    $p = db($cfg); $p->beginTransaction();
    $ov = [$name, mb_substr(preg_replace('/\D/', '', (string)($b['inn'] ?? '')), 0, 12), mb_substr(trim((string)($b['note'] ?? '')), 0, 2000), mb_substr(trim((string)($b['contact'] ?? '')), 0, 200), mb_substr(trim((string)($b['ophone'] ?? '')), 0, 100), mb_substr(trim((string)($b['email'] ?? '')), 0, 100), time(), $ok];
    $ex = $p->prepare('SELECT * FROM orgs WHERE okey = ?'); $ex->execute([$ok]); $cur = $ex->fetch();
    if ($cur) foreach (['contact' => 3, 'ophone' => 4, 'email' => 5] as $f => $i) if (!isset($b[$f])) $ov[$i] = (string)$cur[$f];
    if ($cur) $p->prepare('UPDATE orgs SET name = ?, inn = ?, note = ?, contact = ?, ophone = ?, email = ?, upd = ? WHERE okey = ?')->execute($ov);
    else $p->prepare('INSERT INTO orgs (name, inn, note, contact, ophone, email, upd, okey, created) VALUES (?,?,?,?,?,?,?,?,?)')->execute(array_merge($ov, [time()]));
    if (isset($b['people'])) { $p->prepare('DELETE FROM client_orgs WHERE okey = ?')->execute([$ok]); $ins = $p->prepare('INSERT INTO client_orgs (phone, org, okey, created, role) VALUES (?,?,?,?,?)'); $hasOwner = false;
      foreach (array_slice((array)$b['people'], 0, 30) as $x) { $ph = phoneKey($x['phone'] ?? ''); if ($ph === '') continue; $rl = ($x['role'] ?? '') === 'owner' && !$hasOwner ? 'owner' : 'contact'; if ($rl === 'owner') $hasOwner = true;
        $ins->execute([$ph, $name, $ok, time(), $rl]); $nm = trim((string)($x['name'] ?? ''));
        $c = $p->prepare('SELECT name FROM clients WHERE phone = ?'); $c->execute([$ph]); $cur = $c->fetchColumn();
        if ($cur === false) $p->prepare('INSERT INTO clients (phone, name, model, updated, created) VALUES (?,?,?,?,?)')->execute([$ph, mb_substr($nm, 0, 100), '', time(), time()]);
        elseif ($cur === '' && $nm !== '') $p->prepare('UPDATE clients SET name = ? WHERE phone = ?')->execute([mb_substr($nm, 0, 100), $ph]); } }
    $p->prepare('UPDATE client_orgs SET org = ? WHERE okey = ?')->execute([$name, $ok]);
    $p->commit(); logit($cfg, $role, 'org_save', $ok); out(['ok' => true, 'okey' => $ok]);

  case 'crm_orgs':
    // покупатели-организации из продаж (CloudShop и пульт) — чтобы привязать к клиенту
    needRole($role, ['owner']);
    $q = orgKey($_GET['q'] ?? ''); $t = []; $own = [];
    foreach (db($cfg)->query('SELECT o.okey, o.phone, c.name FROM client_orgs o LEFT JOIN clients c ON c.phone = o.phone')->fetchAll() as $o) $own[$o['okey']] = $o['name'] ?: $o['phone'];
    $add = function ($name, $day, $sum) use (&$t, $q) { $k = orgKey($name); if ($k === '' || ($q !== '' && mb_strpos($k, $q) === false)) return; if (!preg_match('/ООО|ОАО|ЗАО|ПАО|АО|ИП|ГБУ|МБУ|МУП|ГУП|СПК|"|«/u', mb_strtoupper($name))) return;
      if (!isset($t[$k])) $t[$k] = ['org' => $name, 'n' => 0, 'sum' => 0, 'last' => '']; $t[$k]['n']++; $t[$k]['sum'] += $sum; if ($day > $t[$k]['last']) $t[$k]['last'] = $day; };
    foreach (db($cfg)->query("SELECT party, MAX(day) day, SUM(ABS(qty) * price) s FROM arch WHERE type = 'sale' AND party != '' GROUP BY doc")->fetchAll() as $r) $add($r['party'], $r['day'], (float)$r['s']);
    foreach (db($cfg)->query("SELECT d.partner, d.day, SUM(ABS(m.qty) * IFNULL(m.price,0)) s FROM docs d JOIN moves m ON m.doc_id = d.id WHERE d.type = 'sale' AND d.status = 'posted' AND d.partner != '' GROUP BY d.id")->fetchAll() as $r) $add($r['partner'], $r['day'], (float)$r['s']);
    foreach ($t as $k => &$x) { $x['sum'] = round($x['sum']); $x['owner'] = $own[$k] ?? ''; } unset($x);
    $rows = array_values($t); usort($rows, fn($a, $b) => $b['sum'] <=> $a['sum']);
    out(['rows' => array_slice($rows, 0, 40)]);

  case 'crm_save':
    post(); needRole($role, ['owner']);
    $b = body(); $k = phoneKey($b['phone'] ?? ''); if ($k === '') fail('Нужен полный номер телефона');
    $old = phoneKey($b['old'] ?? ''); $p = db($cfg);
    $bd = trim((string)($b['bday'] ?? '')); if ($bd !== '' && bdayIn($bd) === null) fail('День рождения: впишите как 25.03 или 25.03.1985');
    $ph2 = trim((string)($b['phone2'] ?? '')); if ($ph2 !== '' && phoneKey($ph2) === '') fail('Второй телефон — нужен полный номер');
    $p->beginTransaction();
    // смена основного номера: переносим профиль, машины и касания (визиты и покупки остаются под старым номером — он попадёт во «второй телефон»)
    if ($old !== '' && $old !== $k) { foreach (['client_cars', 'crm_touch'] as $t) $p->prepare("UPDATE $t SET phone = ? WHERE phone = ?")->execute([$k, $old]); $p->prepare('DELETE FROM clients WHERE phone = ?')->execute([$old]); if ($ph2 === '') $ph2 = $old; }
    $c = $p->prepare('SELECT created FROM clients WHERE phone = ?'); $c->execute([$k]); $cr = $c->fetchColumn();
    $cars = array_values(array_filter((array)($b['cars'] ?? []), fn($c) => trim((string)($c['car'] ?? '')) !== '' || trim((string)($c['plate'] ?? '')) !== ''));
    $p->prepare('INSERT OR REPLACE INTO clients (phone, name, model, updated, phone2, bday, note, src, tags, consent, created) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$k, mb_substr(trim((string)($b['name'] ?? '')), 0, 100),
      mb_substr(trim((string)($cars[0]['car'] ?? '')), 0, 100), time(), phoneKey($ph2) ?: '', $bd, mb_substr(trim((string)($b['note'] ?? '')), 0, 2000), mb_substr(trim((string)($b['src'] ?? '')), 0, 60), mb_substr(trim((string)($b['tags'] ?? '')), 0, 200), !empty($b['consent']) ? 1 : 0, $cr ?: time()]);
    $p->prepare('DELETE FROM client_cars WHERE phone = ?')->execute([$k]);
    $ins = $p->prepare('INSERT INTO client_cars (phone, car, plate, year, size, note, created) VALUES (?,?,?,?,?,?,?)');
    foreach (array_slice($cars, 0, 10) as $c) $ins->execute([$k, mb_substr(trim((string)($c['car'] ?? '')), 0, 100), mb_substr(mb_strtoupper(trim((string)($c['plate'] ?? ''))), 0, 20), mb_substr(trim((string)($c['year'] ?? '')), 0, 10), mb_substr(trim((string)($c['size'] ?? '')), 0, 40), mb_substr(trim((string)($c['note'] ?? '')), 0, 200), time()]);
    if (isset($b['orgs'])) { $p->prepare('DELETE FROM client_orgs WHERE phone = ?')->execute([$k]); $oi = $p->prepare('INSERT INTO client_orgs (phone, org, okey, created, role) VALUES (?,?,?,?,?)');
      foreach (array_slice((array)$b['orgs'], 0, 20) as $o) { $rl = is_array($o) && ($o['role'] ?? '') === 'contact' ? 'contact' : 'owner'; $o = mb_substr(trim((string)(is_array($o) ? ($o['org'] ?? '') : $o)), 0, 200); $ok = orgKey($o); if ($ok === '') continue;
        // владелец у организации один: прежнего владельца делаем представителем
        if ($rl === 'owner') $p->prepare("UPDATE client_orgs SET role = 'contact' WHERE okey = ? AND role = 'owner'")->execute([$ok]);
        $oi->execute([$k, $o, $ok, time(), $rl]); } }
    if ($old !== '' && $old !== $k) $p->prepare('UPDATE client_orgs SET phone = ? WHERE phone = ?')->execute([$k, $old]);
    $p->commit(); logit($cfg, $role, 'crm_save', $k);
    out(['ok' => true, 'phone' => $k]);

  case 'crm_touch':
    // касание: позвонили, написали, приезжал — чтобы не звонить дважды и видеть, что сработало
    post(); needRole($role, ['owner']);
    $b = body(); $k = phoneKey($b['phone'] ?? ''); if ($k === '') fail('Нужен номер');
    if (!empty($b['del'])) { db($cfg)->prepare('DELETE FROM crm_touch WHERE id = ?')->execute([(int)$b['del']]); out(['ok' => true]); }
    $kind = in_array($b['kind'] ?? '', ['call', 'msg', 'visit', 'no'], true) ? $b['kind'] : 'call';
    db($cfg)->prepare('INSERT INTO crm_touch (phone, day, kind, why, note, author, created) VALUES (?,?,?,?,?,?,?)')->execute([$k, isDay($b['day'] ?? null) ? $b['day'] : date('Y-m-d'), $kind, mb_substr((string)($b['why'] ?? ''), 0, 20), mb_substr(trim((string)($b['note'] ?? '')), 0, 500), $role, time()]);
    out(['ok' => true]);

  case 'crm_calls':
    needRole($role, ['owner']);
    $why = (string)($_GET['why'] ?? ''); $all = crmCalls(crmAll($cfg)); $cnt = [];
    foreach ($all as $c) $cnt[$c['why']] = ($cnt[$c['why']] ?? 0) + 1;
    if ($why !== '') $all = array_values(array_filter($all, fn($c) => $c['why'] === $why));
    out(['rows' => array_slice($all, 0, 300), 'cnt' => $cnt]);

  case 'client_suggest':
    // подсказка при вводе имени: клиенты и их организации (без денег — доступно и мастеру)
    needRole($role, ['owner', 'master', 'cashier', 'seller']);
    $q = mb_strtoupper(trim((string)($_GET['q'] ?? ''))); if (mb_strlen($q) < 2) out(['rows' => []]);
    $p = db($cfg); $cars = []; $orgs = [];
    foreach ($p->query('SELECT phone, car, plate FROM client_cars ORDER BY id')->fetchAll() as $c) $cars[$c['phone']][] = trim($c['car'] . ' ' . $c['plate']);
    $byOrg = []; foreach ($p->query('SELECT o.phone, o.org, o.okey, o.role, c.name FROM client_orgs o LEFT JOIN clients c ON c.phone = o.phone ORDER BY o.id')->fetchAll() as $o) { $orgs[$o['phone']][] = $o; $byOrg[$o['okey']][] = $o; }
    $dir = mb_strlen(orgKey($q)) >= 2 ? orgDirectory($cfg) : [];
    foreach ($dir as $ok => $o) if (!isset($byOrg[$ok])) $byOrg[$ok] = [['okey' => $ok, 'org' => $o['org'], 'phone' => '', 'role' => '', 'name' => '']];
    $rows = []; $pq = plateKey($q); $oq = orgKey($q);
    foreach ($p->query("SELECT phone, name, model, updated FROM clients ORDER BY updated DESC")->fetchAll() as $c) {
      $nm = mb_strtoupper($c['name']); $cs = $cars[$c['phone']] ?? ($c['model'] !== '' ? [$c['model']] : []); $os = $orgs[$c['phone']] ?? [];
      $hit = $nm === '' ? 0 : (mb_strpos($nm, $q) === 0 ? 3 : (preg_match('/(^|\s)' . preg_quote($q, '/') . '/u', $nm) ? 2 : (mb_strpos($nm, $q) !== false ? 1 : 0))); $org = '';
      if (mb_strlen($oq) >= 2) foreach ($os as $o) if (mb_strpos($o['okey'], $oq) !== false) { $org = $o['org'] . ($o['role'] === 'contact' ? ' (представитель)' : ''); $hit = max($hit, mb_strpos($o['okey'], $oq) === 0 ? 3 : 2); break; }
      if (!$hit && mb_strlen($pq) >= 3) foreach ($cs as $cc) if (mb_strpos(plateKey($cc), $pq) !== false) { $hit = 1; break; }
      if (!$hit) continue;
      $rows[] = ['phone' => $c['phone'], 'name' => $c['name'], 'cars' => array_slice($cs, 0, 3), 'org' => $org, 'orgs' => array_column($os, 'org'), 'type' => 'person', 'h' => $hit, 'u' => (int)$c['updated']];
    }
    // организации без владельца-клиента — отдельной строкой (с телефоном представителя, если есть)
    if (mb_strlen($oq) >= 2) foreach ($byOrg as $ok => $L) { $hasOwner = false; foreach ($L as $o) if ($o['role'] === 'owner') $hasOwner = true; if ($hasOwner || mb_strpos($ok, $oq) === false) continue;
      $c = null; foreach ($L as $o) if ($o['phone'] !== '') { $c = $o; break; } $d = $dir[$ok] ?? [];
      $sub = array_values(array_filter([($d['kind'] ?? '') === 'supplier' ? 'поставщик' : '', !empty($d['inn']) ? 'ИНН ' . $d['inn'] : '', !$c && !empty($d['contact']) ? $d['contact'] : '', !$c && !empty($d['ophone']) ? $d['ophone'] : '', !empty($d['n']) ? $d['n'] . ' ' . ruPlural((int)$d['n'], 'покупка', 'покупки', 'покупок') : '']));
      $rows[] = ['phone' => $c['phone'] ?? '', 'name' => $c ? (string)$c['name'] : '', 'cars' => [], 'sub' => implode(' · ', $sub), 'org' => ($dir[$ok]['org'] ?? $L[0]['org']), 'orgs' => [], 'type' => 'org', 'h' => (mb_strpos($ok, $oq) === 0 ? 3 : 2) + (mb_strpos(mb_strtoupper(str_replace(['«', '»'], '"', $dir[$ok]['org'] ?? $L[0]['org'])), str_replace(['«', '»'], '"', $q)) !== false ? 2 : 0), 'u' => (int)($d['n'] ?? 0)]; }
    usort($rows, fn($a, $b) => [$b['h'], $b['u']] <=> [$a['h'], $a['u']]);
    out(['rows' => array_map(fn($r) => array_diff_key($r, ['h' => 1, 'u' => 1]), array_slice($rows, 0, 8))]);

  case 'org_suggest':
    // организации для оплаты по счёту: сначала организации этого клиента из CRM, потом все из CRM и прошлых счетов
    needRole($role, ['owner', 'master', 'cashier']);
    $k = phoneKey($_GET['phone'] ?? ''); $oq = orgKey($_GET['q'] ?? ''); $p = db($cfg); $out = [];
    $add = function ($org, $who, $mine) use (&$out, $oq) { $ok = orgKey($org); if ($ok === '' || ($oq !== '' && mb_strpos($ok, $oq) === false)) return; if (!isset($out[$ok]) || $mine) $out[$ok] = ['org' => $org, 'who' => $who, 'mine' => $mine]; };
    foreach ($p->query('SELECT o.phone, o.org, o.role, c.name FROM client_orgs o LEFT JOIN clients c ON c.phone = o.phone ORDER BY o.id DESC')->fetchAll() as $o) $add($o['org'], ($o['name'] ?: $o['phone']) . ($o['role'] === 'owner' ? ' — владелец' : ' — представитель'), $k !== '' && $o['phone'] === $k);
    foreach ($p->query('SELECT name FROM orgs')->fetchAll() as $o) $add($o['name'], '', false);
    foreach ($p->query("SELECT body FROM orders ORDER BY day DESC LIMIT 3000")->fetchAll() as $r) { $b = json_decode($r['body'], true) ?: []; if (($b['pay'] ?? '') === 'invoice' && trim((string)($b['org'] ?? '')) !== '') $add($b['org'], trim((string)($b['name'] ?? '')), false); }
    $rows = array_values($out); usort($rows, fn($a, $b) => $b['mine'] <=> $a['mine']);
    out(['rows' => array_slice($rows, 0, 12), 'mine' => array_values(array_filter($rows, fn($r) => $r['mine']))]);

  case 'hotel_info':
    // нашлась ли база отеля и сколько сейчас на хранении — для кнопки «Хранение»
    needRole($role, ['owner', 'master', 'cashier']);
    $hp = hotelPdo($cfg); if (!$hp) out(['ok' => false]);
    try { $n = (int)$hp->query("SELECT COUNT(*) FROM items WHERE status = 'stored'")->fetchColumn(); } catch (Throwable $e) { out(['ok' => false]); }
    out(['ok' => true, 'stored' => $n]);

  case 'zak_list':
    // заказы клиентов: поиск по телефону, имени, товару, поставщику
    needRole($role, ['owner']);
    $st = (string)($_GET['st'] ?? 'open'); $q = mb_strtoupper(trim((string)($_GET['q'] ?? ''))); $dq = digits($q);
    $rows = []; $tot = ['n' => 0, 'total' => 0, 'prepay' => 0, 'due' => 0, 'wait' => 0, 'came' => 0];
    foreach (db($cfg)->query('SELECT * FROM zak ORDER BY day DESC, id DESC')->fetchAll() as $r) {
      $open = in_array($r['status'], ['wait', 'came'], true);
      if ($open) { $tot[$r['status']]++; }
      if ($st === 'open' && !$open) continue; if (in_array($st, ['wait', 'came', 'done', 'cancel'], true) && $r['status'] !== $st) continue;
      if ($q !== '') { $hay = mb_strtoupper($r['name'] . ' ' . $r['items'] . ' ' . $r['supplier'] . ' ' . $r['note']); if (mb_strpos($hay, $q) === false && !(strlen($dq) >= 4 && strpos($r['phone'], $dq) !== false)) continue; }
      $x = zakRow($r, $role); $rows[] = $x;
      if ($r['status'] !== 'cancel') { $tot['n']++; $tot['total'] += $x['total']; $tot['prepay'] += $x['prepay']; if ($r['status'] !== 'done') $tot['due'] += max(0, $x['total'] - $x['prepay']); }
      if (count($rows) >= 400) break;
    }
    $sup = [];
    foreach (db($cfg)->query("SELECT supplier FROM zak WHERE supplier != '' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) as $n) $sup[$n] = true;
    foreach (db($cfg)->query("SELECT partner FROM docs WHERE type = 'in' AND partner != '' ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_COLUMN) as $n) $sup[$n] = true;
    out(['rows' => $rows, 'total' => $tot, 'suppliers' => array_slice(array_keys($sup), 0, 60)]);

  case 'zak_save':
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $day = (string)($b['day'] ?? ''); if (!isDay($day)) $day = date('Y-m-d');
    $items = mb_substr(trim((string)($b['items'] ?? '')), 0, 2000); if ($items === '') fail('Впишите, что заказали');
    $ph = phoneKey($b['phone'] ?? ''); if (trim((string)($b['phone'] ?? '')) !== '' && $ph === '') fail('Проверьте телефон — нужен полный номер');
    $stt = (string)($b['status'] ?? ''); if (!in_array($stt, ['wait', 'came', 'done', 'cancel'], true)) $stt = 'wait';
    $promo = (string)($b['promo'] ?? ''); if (!in_array($promo, ['', 'bshm', 'm20'], true)) $promo = '';
    $v = [$day, $ph, mb_substr(trim((string)($b['name'] ?? '')), 0, 100), $items, max(0, round((float)($b['total'] ?? 0), 2)), max(0, round((float)($b['prepay'] ?? 0), 2)), mb_substr(trim((string)($b['supplier'] ?? '')), 0, 100), $promo, mb_substr(trim((string)($b['note'] ?? '')), 0, 1000), $stt, time(), in_array($stt, ['done', 'cancel'], true) ? time() : null];
    if ($id) { $v[] = $id; db($cfg)->prepare('UPDATE zak SET day=?, phone=?, name=?, items=?, total=?, prepay=?, supplier=?, promo=?, note=?, status=?, updated=?, closed=? WHERE id=?')->execute($v); }
    else { db($cfg)->prepare('INSERT INTO zak (day, phone, name, items, total, prepay, supplier, promo, note, status, updated, closed, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($v, [time()])); $id = (int)db($cfg)->lastInsertId(); }
    // имя клиента — в общий справочник, если его там ещё нет
    if ($ph !== '' && $v[2] !== '') { $c = db($cfg)->prepare('SELECT name FROM clients WHERE phone = ?'); $c->execute([$ph]); $cn = $c->fetchColumn();
      if ($cn === false) db($cfg)->prepare('INSERT INTO clients (phone, name, model, updated) VALUES (?,?,?,?)')->execute([$ph, $v[2], '', time()]);
      elseif ($cn === '') db($cfg)->prepare('UPDATE clients SET name = ?, updated = ? WHERE phone = ?')->execute([$v[2], time(), $ph]); }
    logit($cfg, $role, 'zak_save', (string)$id);
    out(['ok' => true, 'id' => $id]);

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
    // wh=all — общий каталог: наш склад и ответхранение вместе, ОХ показывается отдельной цифрой
    $wh = in_array($_GET['wh'] ?? 'shop', ['custody', 'all'], true) ? $_GET['wh'] : 'shop';
    $sm = stockMap($cfg, $wh === 'custody' ? 'custody' : 'shop'); $smc = $wh === 'all' ? stockMap($cfg, 'custody') : []; $owner = $role === 'owner';
    $toks = array_values(array_filter(preg_split('/[\s\/]+/u', str_replace(['*', '×', 'х'], ' ', $q))));
    $rows = []; $tot = ['n' => 0, 'qty' => 0, 'cost' => 0, 'retail' => 0];
    // выбранные кнопки быстрого выбора: {"d":"16","s":"stud",...}
    $F = []; foreach ((array)json_decode((string)($_GET['f'] ?? ''), true) as $k => $v) if ($v !== null && $v !== '') $F[(string)$k] = (string)$v;
    $fAll = []; $fAvail = []; $pmap = photoMap($cfg);
    foreach (db($cfg)->query("SELECT id, name, kind, attrs, barcode, article, price, unit FROM products WHERE archived = 0 AND kind != 'svc' ORDER BY name")->fetchAll() as $p) {
      $s = $sm[(int)$p['id']] ?? ['q' => 0, 'c' => 0]; $qc = $wh === 'all' ? (float)($smc[(int)$p['id']]['q'] ?? 0) : 0; $cc = $wh === 'all' ? (float)($smc[(int)$p['id']]['c'] ?? 0) : 0;
      if ($only && $s['q'] <= 0 && $qc <= 0) continue;
      if ($kind !== '' && $p['kind'] !== $kind && !($kind === 'other' && in_array($p['kind'], ['acc', 'tube', 'tool', 'tireo'], true))) continue;
      if ($kind === 'other') { $g = $p['kind'] === 'acc' ? ((json_decode($p['attrs'], true) ?: [])['group'] ?? 'acc') : $p['kind']; if ($g === 'consumable') $g = 'acc'; $fv = ['g' => [$g]]; }
      else $fv = $kind !== '' ? facetVals($kind, json_decode($p['attrs'], true) ?: []) : [];
      $tm = true;
      if ($toks) { $hay = mb_strtoupper(str_replace('/', ' ', $p['name'])) . ' ' . $p['barcode'] . ' ' . mb_strtoupper($p['article']); $a = json_decode($p['attrs'], true) ?: [];
        foreach ($toks as $t) { if (preg_match('/^[RР](\d{2})$/u', $t, $m)) { if ((int)($a['d'] ?? 0) !== (int)$m[1]) { $tm = false; break; } continue; } if (mb_strpos($hay, $t) === false) { $tm = false; break; } } }
      // какие значения вообще есть и какие ещё подходят при остальных выбранных кнопках и поиске
      $miss = []; foreach ($F as $k => $v) if (!in_array($v, array_map('strval', $fv[$k] ?? []), true)) $miss[] = $k;
      foreach ($fv as $k => $vals) foreach ($vals as $v) { $fAll[$k][(string)$v] = true; if ($tm && (!$miss || $miss === [$k])) $fAvail[$k][(string)$v] = ($fAvail[$k][(string)$v] ?? 0) + 1; }
      if ($miss || !$tm) continue;
      $tot['n']++; $tot['qty'] += max(0, $s['q']); $tot['retail'] += max(0, $s['q']) * (float)$p['price']; if ($owner) $tot['cost'] += max(0, $s['c']);
      if ($qc > 0) { $tot['qc'] = ($tot['qc'] ?? 0) + $qc; $tot['retailc'] = ($tot['retailc'] ?? 0) + $qc * (float)$p['price']; }
      if (count($rows) < $lim) $rows[] = ['photo' => $pmap ? ($pmap[photoKey($p['kind'], json_decode($p['attrs'], true) ?: [], $p['name'], (int)$p['id'])]['url'] ?? null) : null, 'id' => (int)$p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'barcode' => $p['barcode'], 'article' => $p['article'], 'unit' => $p['unit'], 'price' => (float)$p['price'], 'qty' => $s['q'], 'qc' => $qc, 'cost' => $owner && $s['q'] > 0 ? round($s['c'] / $s['q'], 2) : null, 'costc' => $owner && $qc > 0 ? round($cc / $qc, 2) : null];
    }
    if (!$owner) unset($tot['cost']);
    $facets = []; foreach ($fAll as $k => $vals) { $facets[$k] = []; foreach (array_keys($vals) as $v) $facets[$k][] = [(string)$v, $fAvail[$k][(string)$v] ?? 0]; }
    out(['rows' => $rows, 'total' => $tot, 'more' => $tot['n'] > count($rows), 'facets' => $facets ?: new stdClass()]);

  case 'product':
    needRole($role, ['owner', 'master', 'cashier']);
    $id = (int)($_GET['id'] ?? 0); $st = db($cfg)->prepare('SELECT * FROM products WHERE id = ?'); $st->execute([$id]); $p = $st->fetch(); if (!$p) fail('Товар не найден', 404);
    $p['attrs'] = json_decode($p['attrs'], true) ?: new stdClass(); $owner = $role === 'owner';
    if (!$owner) unset($p['supplier']);
    $mv = db($cfg)->prepare("SELECT d.id doc, d.type, d.day, d.num, d.partner, d.wh dwh, m.qty, m.cost, m.wh FROM moves m JOIN docs d ON d.id = m.doc_id WHERE m.product_id = ? AND d.status = 'posted' ORDER BY d.day DESC, d.id DESC LIMIT 100");
    $mv->execute([$id]); $moves = array_map(function ($r) use ($owner) { $r['label'] = docLabel($r['type']); if (!$owner) { unset($r['cost']); $r['partner'] = ''; } return $r; }, $mv->fetchAll());
    $s = stockMap($cfg)[$id] ?? ['q' => 0, 'c' => 0]; $cu = stockMap($cfg, 'custody')[$id] ?? ['q' => 0];
    $pk = photoKey($p['kind'], (array)$p['attrs'], $p['name'], (int)$p['id']); $pm = photoMap($cfg);
    out(['photo' => $pm[$pk]['url'] ?? null, 'photo_key' => $pk, 'product' => $p, 'qty' => $s['q'], 'custody' => $cu['q'], 'cost' => $owner && $s['q'] > 0 ? round($s['c'] / $s['q'], 2) : null, 'moves' => $moves]);

  case 'prod_find':
    // поиск товара для строки документа: все карточки, и в наличии, и нет; штрихкод — точное совпадение первым
    needRole($role, ['owner']);
    $q = trim((string)($_GET['q'] ?? '')); if (mb_strlen($q) < 2) out(['rows' => []]);
    $Q = mb_strtoupper($q); $toks = array_values(array_filter(preg_split('/[\s\/]+/u', str_replace(['*', '×', 'х'], ' ', $Q))));
    $wh = ($_GET['wh'] ?? 'shop') === 'custody' ? 'custody' : 'shop';
    $sm = stockMap($cfg, $wh); $smc = $wh === 'custody' ? $sm : stockMap($cfg, 'custody'); $exact = []; $rows = [];
    $last = lastCosts($cfg);
    foreach (db($cfg)->query("SELECT id, name, kind, barcode, article, cae, price, unit FROM products WHERE archived = 0 AND kind != 'svc'")->fetchAll() as $p) {
      $row = ['id' => (int)$p['id'], 'name' => $p['name'], 'kind' => $p['kind'], 'barcode' => $p['barcode'], 'price' => (float)$p['price'], 'unit' => $p['unit'], 'qty' => ($sm[(int)$p['id']]['q'] ?? 0), 'last' => $last[(int)$p['id']] ?? null];
      $sq = $sm[(int)$p['id']] ?? null; $row['avg'] = $sq && $sq['q'] > 0 ? round($sq['c'] / $sq['q'], 2) : ($wh === 'shop' ? $row['last'] : null);
      $cq = $smc[(int)$p['id']] ?? null; $row['qc'] = $cq['q'] ?? 0; $row['avgc'] = $cq && $cq['q'] > 0 ? round($cq['c'] / $cq['q'], 2) : null;
      if ($p['barcode'] !== '' && ($p['barcode'] === $q || ltrim($p['barcode'], '0') === ltrim($q, '0'))) { $exact[] = $row; continue; }
      if ($p['article'] !== '' && mb_strtoupper($p['article']) === $Q) { $exact[] = $row; continue; }
      if ((string)$p['cae'] !== '' && mb_strtoupper($p['cae']) === $Q) { $exact[] = $row; continue; }
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
    $l = db($cfg)->prepare('SELECT m.id, m.product_id, m.wh, m.qty, m.cost, m.price, m.list, p.kind, p.name, p.unit, p.price cur_price, p.barcode FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id = ? ORDER BY m.id'); $l->execute([$id]);
    $d['label'] = docLabel($d['type']); $d['fee'] = (float)$d['fee'];
    // у продажи — какие возвраты по ней уже есть
    $rets = []; $retq = [];
    if ($d['type'] === 'sale') { $r = db($cfg)->prepare("SELECT id, day, status FROM docs WHERE type = 'ret' AND base_id = ? ORDER BY id"); $r->execute([$id]); $rets = $r->fetchAll(); $retq = returnedMap($cfg, $id); }
    $lines = array_map(fn($r) => ['product_id' => (int)$r['product_id'], 'wh' => $r['wh'], 'kind' => $r['kind'], 'name' => $r['name'], 'unit' => $r['unit'], 'barcode' => $r['barcode'], 'qty' => $d['type'] === 'inv' ? (float)$r['qty'] : abs((float)$r['qty']), 'cost' => (float)$r['cost'], 'price' => $r['price'] !== null ? (float)$r['price'] : (float)$r['cur_price'], 'list' => $r['list'] !== null ? (float)$r['list'] : null, 'cur_price' => (float)$r['cur_price'], 'returned' => $retq[(int)$r['product_id']] ?? 0], $l->fetchAll());
    // черновик продажи (в т. ч. с витрины): остатки наш / ОХ и средняя себестоимость — для выбора склада и оценки прибыли
    if ($d['type'] === 'sale' && $d['status'] === 'draft') { $sm = stockMap($cfg, 'shop'); $smc = stockMap($cfg, 'custody'); $last = lastCosts($cfg);
      foreach ($lines as $i => $x) { $k = $x['product_id']; $q = $sm[$k]['q'] ?? 0; $qc = $smc[$k]['q'] ?? 0; $lines[$i]['stock'] = $q; $lines[$i]['qc'] = $qc;
        $lines[$i]['avgs'] = $q > 0 ? round($sm[$k]['c'] / $q, 2) : ($last[$k] ?? null); $lines[$i]['avgc'] = $qc > 0 ? round($smc[$k]['c'] / $qc, 2) : null; $lines[$i]['avg'] = $x['wh'] === 'custody' ? $lines[$i]['avgc'] : $lines[$i]['avgs']; } }
    out(['doc' => $d, 'returns' => $rets, 'lines' => $lines]);

  case 'doc_save':
    // черновик прихода: шапка и строки целиком; проведённый документ так не меняется
    post(); needRole($role, ['owner']);
    $b = body(); $type = (string)($b['type'] ?? ''); if (!in_array($type, ['in', 'sale', 'ret', 'iss'], true)) fail('Этот вид документа пока не поддерживается');
    if (!isDay($b['day'] ?? null)) fail('Укажите дату');
    $wh = docWh($type, ($b['wh'] ?? 'shop') === 'custody' ? 'custody' : 'shop'); $sign = docSign($type);
    $phone = phoneKey((string)($b['phone'] ?? '')); $pay = in_array($b['pay'] ?? '', ['cash', 'card', 'qr', 'sbp', 'bank'], true) ? $b['pay'] : '';
    $base = (int)($b['base_id'] ?? 0) ?: null; $fee = 0; $bonus = 0; $zak = $type === 'sale' ? ((int)($b['zak_id'] ?? 0) ?: null) : null;
    $id = (int)($b['id'] ?? 0); $p = db($cfg);
    if ($id) { $st = $p->prepare('SELECT status FROM docs WHERE id = ?'); $st->execute([$id]); $stt = $st->fetchColumn(); if ($stt === false) fail('Документ не найден', 404); if ($stt !== 'draft') fail('Документ уже проведён — его можно отменить и создать исправленную копию', 409); }
    $p->beginTransaction();
    $hdr = [$b['day'], mb_substr(trim((string)($b['partner'] ?? '')), 0, 200), mb_substr(trim((string)($b['num'] ?? '')), 0, 60), mb_substr(trim((string)($b['note'] ?? '')), 0, 500), $wh];
    $hdr = array_merge($hdr, [$phone, $pay, $base, $fee, $bonus, $zak]);
    if ($id) { $p->prepare('UPDATE docs SET day = ?, partner = ?, num = ?, note = ?, wh = ?, phone = ?, pay = ?, base_id = ?, fee = ?, bonus = ?, zak_id = ? WHERE id = ?')->execute(array_merge($hdr, [$id])); $p->prepare('DELETE FROM moves WHERE doc_id = ?')->execute([$id]); }
    else { $p->prepare("INSERT INTO docs (type, day, partner, num, note, wh, phone, pay, base_id, fee, bonus, zak_id, status, author, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?, ?)")->execute(array_merge([$type], $hdr, [$role, time()])); $id = (int)$p->lastInsertId(); }
    $ins = $p->prepare('INSERT INTO moves (doc_id, product_id, wh, qty, cost, price, list) VALUES (?,?,?,?,?,?,?)'); $chk = $p->prepare('SELECT price FROM products WHERE id = ?');
    $num = fn($v) => isset($v) && $v !== '' && $v !== null ? max(0, round((float)$v, 2)) : null;
    foreach (array_slice((array)($b['lines'] ?? []), 0, 500) as $l) { $pid = (int)($l['product_id'] ?? 0); $q = abs((float)($l['qty'] ?? 0)); if (!$pid || $q <= 0) continue; $chk->execute([$pid]); $cp = $chk->fetchColumn(); if ($cp === false) continue;
      // себестоимость продажи и выдачи считается при проведении; в приходе — из строки
      $cost = $type === 'in' ? (float)$num($l['cost'] ?? 0) : 0;
      // в продаже list — цена по прайсу на момент продажи (для расчёта скидки)
      $list = $type === 'sale' ? ($num($l['list'] ?? null) ?? (float)$cp) : null;
      // в продаже у каждой строки свой склад: наш или ответхранение поставщика
      $lwh = $type === 'sale' && ($l['wh'] ?? '') === 'custody' ? 'custody' : $wh;
      $ins->execute([$id, $pid, $lwh, $sign * $q, $cost, $num($l['price'] ?? null), $list]); }
    $p->commit(); logit($cfg, $role, 'doc_save', (string)$id);
    out(['id' => $id]);

  case 'doc_post':
    // провести: остатки меняются; новые цены продажи из строк прихода записываются в карточки
    post(); needRole($role, ['owner']);
    $id = (int)(body()['id'] ?? 0); $p = db($cfg);
    $st = $p->prepare('SELECT * FROM docs WHERE id = ?'); $st->execute([$id]); $d = $st->fetch(); if (!$d) fail('Документ не найден', 404); if ($d['status'] !== 'draft') fail('Документ уже проведён или отменён', 409);
    $l = $p->prepare('SELECT product_id, wh, qty, cost, price FROM moves WHERE doc_id = ?'); $l->execute([$id]); $lines = $l->fetchAll(); if (!$lines) fail('В документе нет строк');
    if ($d['type'] === 'in' && $d['wh'] === 'shop') foreach ($lines as $x) if ((float)$x['cost'] <= 0) fail('Не у всех строк указана цена закупки');
    $force = !empty(body()['force']); $setCost = [];

    if (in_array($d['type'], ['sale', 'iss'], true)) {
      // списываем по средней себестоимости на момент проведения; проверяем, хватает ли товара
      // у каждой строки свой склад (в продаже — наш или ОХ, в выдаче — только ОХ)
      $SM = ['shop' => stockMap($cfg, 'shop'), 'custody' => stockMap($cfg, 'custody')]; $last = lastCosts($cfg); $need = []; $short = [];
      foreach ($lines as $x) { $k = (int)$x['product_id'] . '|' . ($x['wh'] === 'custody' ? 'custody' : 'shop'); $need[$k] = ($need[$k] ?? 0) - (float)$x['qty']; }
      $nm = $p->prepare('SELECT name FROM products WHERE id = ?');
      foreach ($need as $k => $q) { [$pid, $w] = explode('|', $k); $pid = (int)$pid; $have = $SM[$w][$pid]['q'] ?? 0;
        if ($have + 1e-9 < $q) { $nm->execute([$pid]); $short[] = $nm->fetchColumn() . ($w === 'custody' ? ' — на ОХ' : '') . ' (есть ' . $have . ', нужно ' . $q . ')'; }
        $setCost[$k] = $have > 0 ? round($SM[$w][$pid]['c'] / $have, 2) : ($d['type'] === 'sale' && $w === 'shop' ? ($last[$pid] ?? 0) : 0); }
      // с ответхранения выдаём только то, что на него пришло — без «всё равно провести»
      if ($short && $d['type'] === 'iss') fail('На ответхранении нет столько товара: ' . implode('; ', $short) . '. Выдать можно только то, что пришло на ОХ.', 409);
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
      foreach ($lines as $x) { $pid = (int)$x['product_id']; $sq = $sm[$pid] ?? null; $setCost[$pid . '|' . $x['wh']] = $bc[$pid] ?? ($sq && $sq['q'] > 0 ? round($sq['c'] / $sq['q'], 2) : ($last[$pid] ?? 0)); }
    }
    $p->beginTransaction();
    if ($setCost) { $uc = $p->prepare('UPDATE moves SET cost = ? WHERE doc_id = ? AND product_id = ? AND wh = ?'); foreach ($setCost as $k => $c) { [$pid, $w] = explode('|', $k); $uc->execute([$c, $id, (int)$pid, $w]); } }
    if (in_array($d['type'], ['sale', 'ret'], true) && $d['phone'] !== '') { $pt = trim((string)$d['partner']);
      // покупатель — организация: имя клиента не трогаем, а организацию привязываем к нему в CRM
      if (isOrgName($pt)) { touchClient($cfg, $d['phone'], '', ''); orgLink($cfg, $d['phone'], $pt); } else touchClient($cfg, $d['phone'], orgJunk($pt) ? '' : $pt, ''); }
    if ($d['type'] === 'in' && $d['wh'] === 'shop') { $up = $p->prepare('UPDATE products SET price = ?, updated = ? WHERE id = ? AND price != ?'); foreach ($lines as $x) if ($x['price'] !== null && (float)$x['price'] > 0) $up->execute([(float)$x['price'], time(), $x['product_id'], (float)$x['price']]); }
    // ответхранение: если у карточки ещё нет цены продажи — ставим по оценке поставщика с нашей наценкой
    if ($d['type'] === 'in' && $d['wh'] === 'custody') { $k = $p->prepare('SELECT kind, price FROM products WHERE id = ?'); $up = $p->prepare('UPDATE products SET price = ?, updated = ? WHERE id = ? AND (price IS NULL OR price <= 0)');
      foreach ($lines as $x) { $k->execute([$x['product_id']]); $pr = $k->fetch(); if ($pr && (float)$pr['price'] <= 0) { $rp = retailPrice((string)$pr['kind'], (float)$x['cost']); if ($rp > 0) $up->execute([$rp, time(), $x['product_id']]); } } }
    // продажа по заказу клиента — заказ выдан
    if ($d['type'] === 'sale' && !empty($d['zak_id'])) $p->prepare("UPDATE zak SET status = 'done', closed = ?, updated = ? WHERE id = ? AND status IN ('wait','came')")->execute([time(), time(), (int)$d['zak_id']]);
    $p->prepare("UPDATE docs SET status = 'posted', posted_at = ? WHERE id = ?")->execute([time(), $id]);
    $p->commit(); logit($cfg, $role, 'doc_post', (string)$id);
    out(['ok' => true]);

  case 'evo_export':
    // каталог для кассы Эвотор: всё, что в наличии (наш склад + ОХ), розничные цены, штрихкоды. Без себестоимости
    needRole($role, ['owner']);
    $all = ($_GET['all'] ?? '') === '1'; $sm = stockMap($cfg, 'shop'); $smc = stockMap($cfg, 'custody'); $rows = [];
    foreach (db($cfg)->query("SELECT id, name, kind, barcode, article, unit, price FROM products WHERE archived = 0 AND kind != 'svc' ORDER BY name")->fetchAll() as $r) {
      $q = max(0, $sm[(int)$r['id']]['q'] ?? 0) + max(0, $smc[(int)$r['id']]['q'] ?? 0); if (!$all && $q <= 0) continue;
      $rows[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'kind' => $r['kind'], 'barcode' => $r['barcode'], 'article' => $r['article'], 'unit' => $r['unit'] ?: 'шт', 'price' => (float)$r['price'], 'q' => $q]; }
    out(['rows' => $rows]);

  case 'backups':
    // список резервных копий и «сделать сейчас»
    needRole($role, ['owner']);
    if (($_GET['now'] ?? '') === '1') backupNow($cfg, true); else backupNow($cfg);
    $L = []; foreach ((array)glob(backupDir($cfg) . '/*.gz') as $f) $L[] = ['f' => basename($f), 'size' => filesize($f), 't' => filemtime($f)];
    usort($L, fn($a, $b) => $b['t'] <=> $a['t']); out(['files' => $L, 'email' => !empty($cfg['backup_email'])]);

  case 'backup_get':
    needRole($role, ['owner']);
    $f = basename((string)($_GET['f'] ?? '')); if (!preg_match('/^(montazh|hotel|kp)-\d{4}-\d{2}-\d{2}\.(sqlite|db|json)\.gz$/', $f)) fail('Неверное имя файла');
    $path = backupDir($cfg) . '/' . $f; if (!is_file($path)) fail('Копия не найдена', 404);
    header('Content-Type: application/gzip'); header('Content-Disposition: attachment; filename="' . $f . '"'); header('Content-Length: ' . filesize($path)); readfile($path); exit;

  case 'sale_request':
    // продажа с витрины: продавец собирает товары, собственник подтверждает (проводит) в пульте
    post(); needRole($role, ['seller', 'cashier', 'owner']);
    $b = body(); $p = db($cfg); $L = (array)($b['lines'] ?? []); if (!$L) fail('Добавьте товары');
    $byExt = []; foreach ($p->query("SELECT id, ext_id, price FROM products WHERE archived = 0")->fetchAll() as $r) { if ($r['ext_id'] !== null && $r['ext_id'] !== '') $byExt[(string)$r['ext_id']] = $r; $byExt['p' . $r['id']] = $r; }
    $sm = stockMap($cfg, 'shop'); $smc = stockMap($cfg, 'custody');
    $phone = phoneKey((string)($b['phone'] ?? '')); $pay = in_array($b['pay'] ?? '', ['cash', 'card', 'qr', 'sbp', 'bank'], true) ? $b['pay'] : '';
    $who = (string)($_SESSION['seller'] ?? '') ?: 'Продавец';
    $p->beginTransaction();
    $p->prepare("INSERT INTO docs (type, day, partner, num, note, wh, phone, pay, status, author, created, seller, req) VALUES ('sale', ?, ?, '', ?, 'shop', ?, ?, 'draft', ?, ?, ?, 1)")
      ->execute([date('Y-m-d'), mb_substr(trim((string)($b['name'] ?? '')), 0, 200), mb_substr(trim((string)($b['note'] ?? '')), 0, 500), $phone, $pay, $role, time(), $who]);
    $id = (int)$p->lastInsertId(); $ins = $p->prepare('INSERT INTO moves (doc_id, product_id, wh, qty, cost, price, list) VALUES (?,?,?,?,0,?,?)'); $n = 0;
    foreach (array_slice($L, 0, 100) as $l) { $pr = $byExt[(string)($l['id'] ?? '')] ?? null; $q = max(0, (float)($l['q'] ?? 0)); if (!$pr || $q <= 0) continue;
      $pid = (int)$pr['id']; $wh = ($sm[$pid]['q'] ?? 0) >= $q || ($smc[$pid]['q'] ?? 0) <= 0 ? 'shop' : 'custody';
      $price = isset($l['price']) && (float)$l['price'] > 0 ? round((float)$l['price'], 2) : (float)$pr['price'];
      $ins->execute([$id, $pid, $wh, -$q, $price, (float)$pr['price']]); $n++; }
    if (!$n) { $p->rollBack(); fail('Товары не найдены — обновите витрину'); }
    $p->commit(); if ($phone !== '' && trim((string)($b['name'] ?? '')) !== '') touchClient($cfg, $phone, trim((string)$b['name']), '');
    logit($cfg, $role, 'sale_request', $id . ' ' . $who);
    out(['ok' => true, 'id' => $id]);

  case 'my_sales':
    // продавцу: его продажи за сегодня и их состояние
    needRole($role, ['seller', 'cashier', 'owner']);
    $who = (string)($_SESSION['seller'] ?? ''); $st = db($cfg)->prepare("SELECT id, status, created, cancel_reason FROM docs WHERE type = 'sale' AND seller = ? AND day = ? ORDER BY id DESC"); $st->execute([$who, date('Y-m-d')]);
    $rows = []; $m = db($cfg)->prepare('SELECT m.qty, m.price, p.name FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id = ?');
    foreach ($st->fetchAll() as $d) { $m->execute([$d['id']]); $it = []; $sum = 0; foreach ($m->fetchAll() as $x) { $q = abs((float)$x['qty']); $it[] = $x['name'] . ($q != 1 ? ' ×' . (0 + $q) : ''); $sum += $q * (float)$x['price']; }
      $rows[] = ['id' => (int)$d['id'], 'status' => $d['status'], 'time' => date('H:i', (int)$d['created']), 'items' => implode(', ', $it), 'sum' => round($sum, 2), 'reason' => (string)$d['cancel_reason']]; }
    out(['seller' => $who, 'rows' => $rows]);

  case 'req_count':
    needRole($role, ['owner']);
    out(['n' => (int)db($cfg)->query("SELECT COUNT(*) FROM docs WHERE type = 'sale' AND status = 'draft' AND req = 1")->fetchColumn()]);

  case 'an_sum':
    // итоги за период: выручка, себестоимость, прибыль, чеки; по дням или месяцам, категориям, диаметрам, брендам, товарам
    needRole($role, ['owner']);
    $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); if (!isDay($from) || !isDay($to)) fail('Укажите период');
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1; $gb = $days > 62 ? 'month' : 'day';
    // до даты перехода — архив CloudShop, после — документы пульта; одна и та же продажа не считается дважды
    $until = archUntil($cfg);
    $agg = function (string $f, string $t) use ($cfg, $until) { $out = [];
      $lf = $until !== '' && $f <= $until ? date('Y-m-d', strtotime($until . ' +1 day')) : $f;
      if ($lf <= $t) { $st = db($cfg)->prepare("SELECT d.id doc, d.type, d.day, d.pay, d.seller, m.product_id pid, m.wh, m.qty, m.cost, m.price, m.list, p.name, p.kind, p.attrs FROM moves m JOIN docs d ON d.id = m.doc_id JOIN products p ON p.id = m.product_id WHERE d.status = 'posted' AND d.type IN ('sale','ret') AND d.day >= ? AND d.day <= ?");
        $st->execute([$lf, $t]); $out = $st->fetchAll(); }
      if ($until !== '' && $f <= $until) { $st = db($cfg)->prepare("SELECT 'a' || a.doc doc, a.type, a.day, a.pay, 'CloudShop' seller, COALESCE(a.pid, 0) pid, 'shop' wh, a.qty, a.cost, a.price, a.price list, COALESCE(p.name, a.name) name, COALESCE(p.kind, '') kind, COALESCE(p.attrs, '') attrs, a.name aname FROM arch a LEFT JOIN products p ON p.id = a.pid WHERE a.type IN ('sale','ret') AND a.day >= ? AND a.day <= ?");
        $st->execute([$f, min($t, $until)]);
        foreach ($st->fetchAll() as $r) { if ($r['kind'] === '') { [$kg] = groupOf('', [], (string)$r['aname']); $r['kind'] = $kg === 'other' ? 'acc' : $kg; if ($kg === 'tire' && ($ts = tireSize((string)$r['aname']))) $r['attrs'] = json_encode($ts); } if (!(int)$r['pid']) $r['pid'] = 'n' . md5((string)$r['aname']); $out[] = $r; } }
      return $out; };
    $K = ['rev' => 0, 'cost' => 0, 'qty' => 0, 'checks' => 0, 'disc' => 0]; $ser = []; $sel = []; $selc = []; $kinds = []; $dia = []; $brands = []; $prod = []; $pay = []; $whs = ['shop' => ['rev' => 0, 'cost' => 0], 'custody' => ['rev' => 0, 'cost' => 0]]; $docs = [];
    $add = function (array &$b, float $r, float $c, float $q) { $b['rev'] = ($b['rev'] ?? 0) + $r; $b['cost'] = ($b['cost'] ?? 0) + $c; $b['qty'] = ($b['qty'] ?? 0) + $q; };
    foreach ($agg($from, $to) as $m) {
      $q = -(float)$m['qty']; $r = $q * (float)$m['price']; $c = $q * (float)$m['cost']; $a = json_decode($m['attrs'], true) ?: [];
      $K['rev'] += $r; $K['cost'] += $c; $K['qty'] += $q; if ($m['type'] === 'sale') { $docs[$m['doc']] = true; $K['disc'] += max(0, $q * ((float)($m['list'] ?? $m['price']) - (float)$m['price'])); }
      $key = $gb === 'month' ? substr($m['day'], 0, 7) : $m['day']; $ser[$key] = $ser[$key] ?? ['rev' => 0, 'cost' => 0, 'qty' => 0]; $add($ser[$key], $r, $c, $q);
      $kg = kindGroup((string)$m['kind']); $kinds[$kg] = $kinds[$kg] ?? []; $add($kinds[$kg], $r, $c, $q);
      if ($m['kind'] === 'tire' && !empty($a['d'])) { $dk = (int)$a['d']; $dia[$dk] = $dia[$dk] ?? []; $add($dia[$dk], $r, $c, $q);
        $bk = trim((string)($a['brand'] ?? '')) ?: '—'; $brands[$bk] = $brands[$bk] ?? []; $add($brands[$bk], $r, $c, $q); }
      $pk = (string)$m['pid']; $prod[$pk] = $prod[$pk] ?? ['id' => (int)$m['pid'], 'name' => $m['name'], 'kind' => $m['kind']]; $add($prod[$pk], $r, $c, $q);
      $pm = $m['pay'] ?: '—'; $pay[$pm] = $pay[$pm] ?? []; $add($pay[$pm], $r, $c, $q);
      $w = $m['wh'] === 'custody' ? 'custody' : 'shop'; $whs[$w]['rev'] += $r; $whs[$w]['cost'] += $c;
      $sk = (string)$m['seller'] !== '' ? (string)$m['seller'] : 'В пульте'; $sel[$sk] = $sel[$sk] ?? []; $add($sel[$sk], $r, $c, $q); if ($m['type'] === 'sale') $selc[$sk][$m['doc']] = true;
    }
    $K['checks'] = count($docs);
    // прошлый такой же период — для сравнения
    $pf = date('Y-m-d', strtotime($from . ' -' . $days . ' days')); $pt = date('Y-m-d', strtotime($from . ' -1 day')); $P = ['rev' => 0, 'cost' => 0, 'checks' => 0]; $pd = [];
    foreach ($agg($pf, $pt) as $m) { $q = -(float)$m['qty']; $P['rev'] += $q * (float)$m['price']; $P['cost'] += $q * (float)$m['cost']; if ($m['type'] === 'sale') $pd[$m['doc']] = true; } $P['checks'] = count($pd);
    $R = fn($x) => round($x, 2); $fix = function ($arr) use ($R) { $o = []; foreach ($arr as $k => $v) $o[] = ['k' => (string)$k, 'rev' => $R($v['rev'] ?? 0), 'cost' => $R($v['cost'] ?? 0), 'qty' => round($v['qty'] ?? 0, 2)]; return $o; };
    // полная шкала периода, даже без продаж
    $axis = []; if ($gb === 'day') { for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) $axis[] = date('Y-m-d', $t); } else { for ($t = strtotime(substr($from, 0, 7) . '-01'); $t <= strtotime($to); $t = strtotime('+1 month', $t)) $axis[] = date('Y-m', $t); }
    $series = array_map(fn($k) => ['k' => $k, 'rev' => $R($ser[$k]['rev'] ?? 0), 'cost' => $R($ser[$k]['cost'] ?? 0), 'qty' => round($ser[$k]['qty'] ?? 0, 2)], $axis);
    $pr = $fix($prod); foreach ($pr as $i => $x) { $pr[$i]['id'] = $prod[$x['k']]['id']; $pr[$i]['name'] = $prod[$x['k']]['name']; $pr[$i]['kind'] = $prod[$x['k']]['kind']; }
    $byRev = $pr; usort($byRev, fn($a, $b) => $b['rev'] <=> $a['rev']); $byProf = $pr; usort($byProf, fn($a, $b) => ($b['rev'] - $b['cost']) <=> ($a['rev'] - $a['cost']));
    $low = array_values(array_filter($pr, fn($x) => $x['rev'] > 0 && $x['qty'] >= 2 && ($x['rev'] - $x['cost']) / $x['rev'] < 0.12)); usort($low, fn($a, $b) => $b['rev'] <=> $a['rev']);
    $br = $fix($brands); usort($br, fn($a, $b) => $b['rev'] <=> $a['rev']); $dd = $fix($dia); usort($dd, fn($a, $b) => (int)$a['k'] <=> (int)$b['k']);
    out(['until' => $until, 'from' => $from, 'to' => $to, 'gb' => $gb, 'kpi' => array_map($R, $K), 'prev' => array_map($R, $P), 'series' => $series, 'kinds' => $fix($kinds), 'dia' => $dd, 'brands' => array_slice($br, 0, 12),
      'top' => array_slice($byRev, 0, 15), 'topProfit' => array_slice($byProf, 0, 15), 'low' => array_slice($low, 0, 15), 'pay' => $fix($pay), 'wh' => $fix($whs), 'sellers' => array_map(function ($x) use ($selc) { $x['checks'] = count($selc[$x['k']] ?? []); return $x; }, $fix($sel))]);

  case 'arch_info':
    needRole($role, ['owner']); $p = db($cfg);
    $r = $p->query("SELECT MIN(day) a, MAX(day) b, COUNT(*) n, COUNT(DISTINCT doc) d FROM arch")->fetch();
    $h = $p->query("SELECT COUNT(DISTINCT pfrom) FROM hist WHERE ext IS NOT NULL")->fetchColumn();
    out(['from' => $r['a'], 'to' => $r['b'], 'lines' => (int)$r['n'], 'docs' => (int)$r['d'], 'months' => (int)$h, 'until' => archUntil($cfg), 'parties' => (int)$p->query('SELECT COUNT(*) FROM arch_party')->fetchColumn()]);

  case 'arch_parties':
    // клиенты и поставщики CloudShop; клиенты с телефоном — в общий справочник (имя не перезаписываем)
    post(); needRole($role, ['owner']); $b = body(); $p = db($cfg); $p->beginTransaction(); $n = 0; $nc = 0;
    $ins = $p->prepare('INSERT OR REPLACE INTO arch_party (id, kind, name, phones, note) VALUES (?,?,?,?,?)');
    $cs = $p->prepare('SELECT name FROM clients WHERE phone = ?'); $ci = $p->prepare('INSERT INTO clients (phone, name, model, updated) VALUES (?,?,?,?)');
    foreach ((array)($b['clients'] ?? []) as $c) { $ph = []; foreach (explode(',', (string)($c[3] ?? '')) as $x) if ($k = phoneKey($x)) $ph[] = $k;
      $ins->execute([(string)$c[0], 'client', mb_substr((string)$c[2], 0, 200), implode(',', $ph), mb_substr((string)($c[6] ?? ''), 0, 300)]); $n++;
      $nm = trim((string)$c[2]); if ($ph && $nm !== '' && !preg_match('/^(Розничный покупатель|Частное лицо|Постоянный клиент)$/u', $nm)) { $cs->execute([$ph[0]]); if ($cs->fetchColumn() === false) { $ci->execute([$ph[0], mb_substr($nm, 0, 100), '', time()]); $nc++; } } }
    foreach ((array)($b['suppliers'] ?? []) as $c) { $ins->execute([(string)$c[0], 'supplier', mb_substr((string)$c[1], 0, 200), '', '']); $n++; }
    $p->commit(); out(['ok' => true, 'parties' => $n, 'newClients' => $nc]);

  case 'arch_monthly':
    // помесячные продажи по товарам с себестоимостью (CloudShop) → архив для советов; месяц загружается заново поверх
    post(); needRole($role, ['owner']); $b = body(); $p = db($cfg); $I = prodIndex($cfg); $n = 0; $hit = 0;
    $p->beginTransaction(); $ins = $p->prepare('INSERT INTO hist (pfrom, pto, name, barcode, article, qty, checks, rev, cost, product_id, kind, gkey, created, ext) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ((array)($b['months'] ?? []) as $ym => $rows) { if (!preg_match('/^\d{4}-\d{2}$/', (string)$ym)) continue; $f = $ym . '-01'; $t = date('Y-m-t', strtotime($f));
      $p->prepare('DELETE FROM hist WHERE pfrom = ? AND pto = ?')->execute([$f, $t]);
      foreach ((array)$rows as $r) { $nm = trim((string)$r[1]); $q = (float)$r[4]; if ($nm === '' || $q == 0) continue; $pid = prodMatch($I, (string)$r[0], (string)$r[2], (string)$r[3], $nm); if ($pid) $hit++;
        [$kg, $gk] = $pid ? groupOf((string)$I['p'][$pid]['kind'], json_decode($I['p'][$pid]['attrs'], true) ?: [], $I['p'][$pid]['name']) : groupOf('', [], $nm);
        $ins->execute([$f, $t, mb_substr($nm, 0, 300), (string)$r[2], (string)$r[3], $q, (float)$r[5], (float)$r[6], (float)$r[7], $pid, $kg, $gk, time(), (string)$r[0]]); $n++; } }
    $p->commit(); out(['ok' => true, 'rows' => $n, 'matched' => $hit]);

  case 'arch_docs':
    // документы CloudShop за месяц → строки архива; себестоимость продажи — средняя за этот месяц из помесячного отчёта
    post(); needRole($role, ['owner']); $b = body(); $ym = (string)($b['ym'] ?? ''); if (!preg_match('/^\d{4}-\d{2}$/', $ym)) fail('Неверный месяц');
    $p = db($cfg); $I = prodIndex($cfg); date_default_timezone_set('Europe/Moscow');
    $uc = []; $st = $p->prepare('SELECT ext, qty, cost FROM hist WHERE pfrom = ?'); $st->execute([$ym . '-01']); foreach ($st->fetchAll() as $r) if ($r['ext'] && (float)$r['qty'] > 0) $uc[$r['ext']] = (float)$r['cost'] / (float)$r['qty'];
    $ph = []; foreach ($p->query("SELECT id, phones FROM arch_party WHERE phones != ''")->fetchAll() as $r) $ph[$r['id']] = explode(',', $r['phones'])[0];
    $p->beginTransaction(); $p->prepare('DELETE FROM arch WHERE ym = ?')->execute([$ym]); $n = 0;
    $ins = $p->prepare('INSERT INTO arch (ym, day, doc, num, type, party_id, party, phone, pid, ext, name, qty, price, cost, pay, evotor) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ((array)($b['docs'] ?? []) as $d) { if (!empty($d[14]) || empty($d[15])) continue; $day = date('Y-m-d', (int)$d[4]); $party = (string)$d[7]; $oh = mb_strpos($party, 'ОХ ') === 0;
      $t = ['sales' => 'sale', 'return_sales' => 'ret', 'purchases' => $oh ? 'oh_in' : 'in', 'return_purchases' => $oh ? 'oh_out' : 'rin', 'changes' => 'chg', 'movements' => 'mov'][(string)$d[1]] ?? (string)$d[1];
      $pay = !empty($d[11]) ? 'bank' : ($d[10] === null ? '' : ((int)$d[10] ? 'cash' : 'card'));
      foreach ((array)$d[16] as $l) { $q = (float)$l[4]; if ($q == 0) continue; $ext = (string)$l[0]; $pid = prodMatch($I, $ext, (string)$l[3], (string)$l[2], (string)$l[1]);
        $price = abs($q) > 0 ? abs((float)$l[6]) / abs($q) : (float)$l[5];
        $cost = in_array($t, ['sale', 'ret'], true) ? ($uc[$ext] ?? 0) : (float)$l[5];
        $ins->execute([$ym, $day, (string)$d[0], (string)$d[3], $t, (string)$d[5], mb_substr($party, 0, 200), $ph[(string)$d[5]] ?? '', $pid, $ext, mb_substr((string)$l[1], 0, 300), $q, round($price, 2), round($cost, 2), $pay, (string)$d[12]]); $n++; } }
    $p->commit(); out(['ok' => true, 'lines' => $n]);

  case 'arch_done':
    // граница: до этой даты итоги из архива, после — из пульта
    post(); needRole($role, ['owner']); $u = (string)(body()['until'] ?? ''); if (!isDay($u)) fail('Неверная дата');
    db($cfg)->prepare("INSERT OR REPLACE INTO settings (k, v) VALUES ('arch_until', ?)")->execute([$u]); logit($cfg, $role, 'arch_done', $u); out(['ok' => true]);

  case 'hist_upload':
    // отчёт CloudShop «Продажи по товарам» за период → архив; тот же период загружается заново поверх
    post(); needRole($role, ['owner']);
    $b = body(); $f = (string)($b['pfrom'] ?? ''); $t = (string)($b['pto'] ?? ''); if (!isDay($f) || !isDay($t) || $t < $f) fail('Укажите период отчёта: с какого по какое число');
    $p = db($cfg); $byBc = []; $byAr = []; $byNm = [];
    foreach ($p->query('SELECT id, name, kind, attrs, barcode, article FROM products')->fetchAll() as $r) { if ($r['barcode'] !== '') $byBc[ltrim($r['barcode'], '0')] = $r; if ($r['article'] !== '') $byAr[mb_strtoupper($r['article'])] = $r; $byNm[mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $r['name'])))] = $r; }
    $p->beginTransaction(); $p->prepare('DELETE FROM hist WHERE pfrom = ? AND pto = ?')->execute([$f, $t]);
    $ins = $p->prepare('INSERT INTO hist (pfrom, pto, name, barcode, article, qty, checks, rev, cost, product_id, kind, gkey, created) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'); $n = 0; $hit = 0;
    foreach (array_slice((array)($b['rows'] ?? []), 0, 20000) as $r) {
      $nm = trim((string)($r['n'] ?? '')); $q = (float)($r['q'] ?? 0); if ($nm === '' || $q == 0) continue; $bc = trim((string)($r['bc'] ?? '')); $ar = trim((string)($r['ar'] ?? ''));
      $pr = ($bc !== '' ? ($byBc[ltrim($bc, '0')] ?? null) : null) ?: ($ar !== '' ? ($byAr[mb_strtoupper($ar)] ?? null) : null) ?: ($byNm[mb_strtoupper(preg_replace('/\s+/u', ' ', $nm))] ?? null);
      if ($pr) $hit++;
      [$kg, $gk] = $pr ? groupOf((string)$pr['kind'], json_decode($pr['attrs'], true) ?: [], $pr['name']) : groupOf('', [], $nm);
      $ins->execute([$f, $t, mb_substr($nm, 0, 300), $bc, $ar, $q, (float)($r['ck'] ?? 0), (float)($r['rev'] ?? 0), (float)($r['cost'] ?? 0), $pr ? (int)$pr['id'] : null, $kg, $gk, time()]); $n++;
    }
    $p->commit(); logit($cfg, $role, 'hist_upload', "$f..$t: $n");
    out(['ok' => true, 'rows' => $n, 'matched' => $hit]);

  case 'hist_list':
    needRole($role, ['owner']);
    out(['periods' => db($cfg)->query('SELECT pfrom, pto, COUNT(*) n, SUM(qty) q, SUM(rev) rev, SUM(rev - cost) prof, MAX(created) at FROM hist GROUP BY pfrom, pto ORDER BY pto DESC, pfrom DESC')->fetchAll()]);

  case 'hist_del':
    post(); needRole($role, ['owner']); $b = body();
    db($cfg)->prepare('DELETE FROM hist WHERE pfrom = ? AND pto = ?')->execute([(string)($b['pfrom'] ?? ''), (string)($b['pto'] ?? '')]); out(['ok' => true]);

  case 'an_advice':
    // советы: что довезти, что залежалось, где поднять цену, хиты без остатка, тренды
    needRole($role, ['owner']);
    $p = db($cfg); $now = time(); $mon = (int)date('n');
    // архив: берём самый свежий период (если их несколько — с самой поздней датой окончания, из них самый короткий)
    // архив: свежий период (самый короткий из закончившихся за последний год) весит 70%, самый длинный — 30%.
    // Всё приводим к «штук за период длиной hdays», чтобы дальше считать как с одним отчётом.
    $all = $p->query('SELECT pfrom, pto, (julianday(pto) - julianday(pfrom) + 1) len FROM hist GROUP BY pfrom, pto')->fetchAll();
    $recent = null; $long = null;
    foreach ($all as $x) { if (strtotime($x['pto']) >= $now - 400 * 86400 && (!$recent || $x['len'] < $recent['len'])) $recent = $x; if (!$long || $x['len'] > $long['len']) $long = $x; }
    if (!$recent) $recent = $long; if ($long && $recent && $long['pfrom'] === $recent['pfrom'] && $long['pto'] === $recent['pto']) $long = null;
    $H = []; $HP = []; $hper = $recent; $hdays = $recent ? max(1, (int)round($recent['len'])) : 0;
    $load = function ($per, $w) use ($p, &$H, &$HP, $hdays) { if (!$per) return; $k0 = $hdays / max(1, $per['len']) * $w;
      $st = $p->prepare('SELECT * FROM hist WHERE pfrom = ? AND pto = ?'); $st->execute([$per['pfrom'], $per['pto']]);
      foreach ($st->fetchAll() as $r) { $g = $r['kind'] . '|' . $r['gkey']; $H[$g] = $H[$g] ?? ['kind' => $r['kind'], 'g' => $r['gkey'], 'q' => 0, 'rev' => 0, 'cost' => 0]; $H[$g]['q'] += $r['qty'] * $k0; $H[$g]['rev'] += $r['rev'] * $k0; $H[$g]['cost'] += $r['cost'] * $k0;
        if ($r['product_id']) { $k = (int)$r['product_id']; $HP[$k] = $HP[$k] ?? ['q' => 0, 'rev' => 0, 'cost' => 0]; $HP[$k]['q'] += $r['qty'] * $k0; $HP[$k]['rev'] += $r['rev'] * $k0; $HP[$k]['cost'] += $r['cost'] * $k0; } } };
    // помесячная история CloudShop: спрос = те же месяцы прошлых лет (60%) + три последних месяца (40%), «в месяц»
    $mlist = $p->query("SELECT DISTINCT pfrom FROM hist WHERE ext IS NOT NULL AND substr(pfrom, 9, 2) = '01' ORDER BY pfrom")->fetchAll(PDO::FETCH_COLUMN); $mode = 'periods';
    if (count($mlist) >= 12) { $mode = 'seasonal'; $hdays = 30; $long = null; $have = array_flip($mlist); $cy = (int)date('Y'); $cm = (int)date('n');
      $sea = []; foreach ([1, 2] as $yb) foreach ([0, 1] as $mo) { $t = mktime(0, 0, 0, $cm + $mo, 1, $cy - $yb); $k = date('Y-m-01', $t); if (isset($have[$k])) $sea[] = $k; }
      $rec = []; for ($i = 1; $i <= 3; $i++) { $k = date('Y-m-01', mktime(0, 0, 0, $cm - $i, 1, $cy)); if (isset($have[$k])) $rec[] = $k; }
      if (!$rec) $rec = array_slice($mlist, -3);
      $hper = ['pfrom' => $mlist[0], 'pto' => date('Y-m-t', strtotime(end($mlist))), 'len' => 30, 'season' => $sea, 'recent' => $rec];
      $loadM = function (array $ks, float $w) use ($p, &$H, &$HP) { if (!$ks) return; $k0 = $w / count($ks); $st = $p->prepare('SELECT * FROM hist WHERE pfrom IN (' . implode(',', array_fill(0, count($ks), '?')) . ')'); $st->execute($ks);
        foreach ($st->fetchAll() as $r) { $g = $r['kind'] . '|' . $r['gkey']; $H[$g] = $H[$g] ?? ['kind' => $r['kind'], 'g' => $r['gkey'], 'q' => 0, 'rev' => 0, 'cost' => 0]; $H[$g]['q'] += $r['qty'] * $k0; $H[$g]['rev'] += $r['rev'] * $k0; $H[$g]['cost'] += $r['cost'] * $k0;
          if ($r['product_id']) { $k = (int)$r['product_id']; $HP[$k] = $HP[$k] ?? ['q' => 0, 'rev' => 0, 'cost' => 0]; $HP[$k]['q'] += $r['qty'] * $k0; $HP[$k]['rev'] += $r['rev'] * $k0; $HP[$k]['cost'] += $r['cost'] * $k0; } } };
      $loadM($sea ?: $rec, $sea ? 0.6 : 1); if ($sea) $loadM($rec, 0.4);
    } else { $load($recent, $long ? 0.7 : 1); $load($long, 0.3); }
    // пульт: продажи за последние 90 дней
    $first = $p->query("SELECT MIN(day) FROM docs WHERE type = 'sale' AND status = 'posted'")->fetchColumn(); $ldays = $first ? min(90, max(1, (int)round(($now - strtotime($first)) / 86400) + 1)) : 0;
    $L = []; $LP = []; $lastSale = [];
    if ($first) { $st = $p->prepare("SELECT m.product_id pid, m.qty, m.price, m.cost, d.day, d.type, p.kind, p.attrs, p.name FROM moves m JOIN docs d ON d.id = m.doc_id JOIN products p ON p.id = m.product_id WHERE d.status = 'posted' AND d.type IN ('sale','ret') AND d.day >= ?"); $st->execute([date('Y-m-d', $now - 90 * 86400)]);
      foreach ($st->fetchAll() as $r) { $q = -(float)$r['qty']; [$kg, $gk] = groupOf((string)$r['kind'], json_decode($r['attrs'], true) ?: [], $r['name']); $g = $kg . '|' . $gk;
        $L[$g] = $L[$g] ?? ['kind' => $kg, 'g' => $gk, 'q' => 0, 'rev' => 0, 'cost' => 0]; $L[$g]['q'] += $q; $L[$g]['rev'] += $q * $r['price']; $L[$g]['cost'] += $q * $r['cost'];
        $k = (int)$r['pid']; $LP[$k] = ($LP[$k] ?? 0) + $q; if ($r['type'] === 'sale') $lastSale[$k] = max($lastSale[$k] ?? '', $r['day']); } }
    // за всё время в пульте — последняя продажа
    foreach ($p->query("SELECT pid, MAX(day) day FROM arch WHERE type = 'sale' AND pid IS NOT NULL GROUP BY pid")->fetchAll() as $r) $lastSale[(int)$r['pid']] = $r['day'];
    foreach ($p->query("SELECT m.product_id pid, MAX(d.day) day FROM moves m JOIN docs d ON d.id = m.doc_id WHERE d.status = 'posted' AND d.type = 'sale' GROUP BY m.product_id")->fetchAll() as $r) $lastSale[(int)$r['pid']] = max($lastSale[(int)$r['pid']] ?? '', $r['day']);
    // остатки по группам и товарам (наш + ОХ)
    $sm = stockMap($cfg, 'shop'); $smc = stockMap($cfg, 'custody'); $S = []; $prods = []; $lastIn = [];
    foreach ($p->query("SELECT m.product_id pid, MAX(d.day) day FROM moves m JOIN docs d ON d.id = m.doc_id WHERE d.status = 'posted' AND d.type IN ('in','open') AND m.qty > 0 GROUP BY m.product_id")->fetchAll() as $r) $lastIn[(int)$r['pid']] = $r['day'];
    foreach ($p->query("SELECT id, name, kind, attrs, price FROM products WHERE archived = 0 AND kind != 'svc'")->fetchAll() as $r) { $id = (int)$r['id']; $q = max(0, $sm[$id]['q'] ?? 0); $qc = max(0, $smc[$id]['q'] ?? 0);
      [$kg, $gk, $d, $season] = groupOf((string)$r['kind'], json_decode($r['attrs'], true) ?: [], $r['name']); $g = $kg . '|' . $gk;
      $S[$g] = $S[$g] ?? ['q' => 0, 'qc' => 0, 'season' => $season]; $S[$g]['q'] += $q; $S[$g]['qc'] += $qc;
      $prods[$id] = ['id' => $id, 'name' => $r['name'], 'kind' => $r['kind'], 'g' => $g, 'q' => $q, 'qc' => $qc, 'c' => $q > 0 ? $sm[$id]['c'] : 0, 'price' => (float)$r['price']]; }
    // темп продаж, шт в месяц: пульт (если продаж больше 60 дней) вместе с архивом
    $rate = function ($g) use ($H, $L, $hdays, $ldays) { $h = isset($H[$g]) ? $H[$g]['q'] / $hdays * 30 : null; $l = isset($L[$g]) && $ldays ? $L[$g]['q'] / $ldays * 30 : 0;
      if ($ldays >= 60) return $h === null ? $l : 0.6 * $l + 0.4 * $h; return $h ?? $l; };
    $winterNow = in_array($mon, [9, 10, 11, 12, 1, 2], true);
    $keys = array_unique(array_merge(array_keys($H), array_keys($L)));
    $dov = [];
    foreach ($keys as $g) { $r = $rate($g); if ($r < 0.5) continue; $src = $H[$g] ?? $L[$g];
      // служебные строки CloudShop и снятые с продажи мелочи (их нет в каталоге) не советуем
      if (preg_match('/^(Продажи |Услуг)/u', $src['g'])) continue; if (!in_array($src['kind'], ['tire', 'disk', 'akb'], true) && !isset($S[$g])) continue; $st = $S[$g] ?? null;
      // архивная строка без сезона (карточки уже нет) — остаток берём по всему размеру
      if ($st === null) { $st = ['q' => 0, 'qc' => 0, 'season' => '']; foreach ($S as $kk => $v) if (strpos($kk, $g . ' · ') === 0) { $st['q'] += $v['q']; $st['qc'] += $v['qc']; } }
      $tot = $st['q'] + $st['qc']; $cover = $r > 0 ? $tot / $r : 99; if ($cover >= 1.2) continue;
      $isTire = $src['kind'] === 'tire'; $sea = $isTire ? (strpos($src['g'], 'зима') !== false ? 'winter' : (strpos($src['g'], 'лето') !== false ? 'summer' : '')) : '';
      $inSeason = !$isTire || $sea === '' || ($sea === 'winter') === $winterNow;
      $unit = ($src['q'] > 0) ? ($src['rev'] - $src['cost']) / $src['q'] : 0; $need = max(0, ceil($r * 2 - $tot)); if (in_array($src['kind'], ['tire', 'disk'], true)) $need = max(4, (int)ceil($need / 4) * 4); elseif ($need < 1) $need = 1;
      $dov[] = ['kind' => $src['kind'], 'g' => $src['g'], 'rate' => round($r, 1), 'stock' => $tot, 'qc' => $st['qc'], 'weeks' => round($cover * 4.3, 1), 'need' => $need, 'unit' => round($unit), 'profit' => round($r * $unit), 'season' => $inSeason, 'sold' => round($src['q'], 1)]; }
    usort($dov, fn($a, $b) => [$b['season'], $b['profit']] <=> [$a['season'], $a['profit']]);
    // залежалось: на нашем складе, без продаж давно и почти без спроса в архиве
    $dead = [];
    foreach ($prods as $id => $x) { if ($x['q'] <= 0) continue; $ls = $lastSale[$id] ?? ''; $since = $ls ? (int)(($now - strtotime($ls)) / 86400) : null;
      $hr = isset($HP[$id]) ? $HP[$id]['q'] / $hdays * 30 : 0; if (($since !== null && $since < 120) || $hr >= 0.3) continue;
      $dead[] = ['id' => $id, 'name' => $x['name'], 'kind' => $x['kind'], 'q' => $x['q'], 'money' => round($x['c']), 'retail' => round($x['q'] * $x['price']), 'last' => $ls, 'in' => $lastIn[$id] ?? '', 'hist' => round($HP[$id]['q'] ?? 0, 1)]; }
    usort($dead, fn($a, $b) => $b['money'] <=> $a['money']);
    // низкая наценка в архиве при хорошем спросе: предложить цену по нашему правилу
    $low = [];
    foreach ($HP as $id => $h) { if ($h['q'] < 4 || $h['rev'] <= 0 || !isset($prods[$id])) continue; $m = ($h['rev'] - $h['cost']) / $h['rev']; if ($m >= 0.15) continue;
      $uc = $h['cost'] / $h['q']; $rp = retailPrice((string)$prods[$id]['kind'], $uc);
      $low[] = ['id' => $id, 'name' => $prods[$id]['name'], 'q' => round($h['q']), 'margin' => round($m * 100, 1), 'price' => $prods[$id]['price'], 'unitcost' => round($uc), 'suggest' => $rp, 'gain' => $rp > $prods[$id]['price'] ? round(($rp - $prods[$id]['price']) * $h['q'] / $hdays * 30) : 0]; }
    usort($low, fn($a, $b) => $b['gain'] <=> $a['gain']);
    // хиты архива, которых сейчас нет ни у нас, ни на ОХ
    $hits = [];
    foreach ($HP as $id => $h) { if (!isset($prods[$id]) || $prods[$id]['q'] + $prods[$id]['qc'] > 0) continue; $hits[] = ['id' => $id, 'name' => $prods[$id]['name'], 'q' => round($h['q']), 'profit' => round($h['rev'] - $h['cost']), 'rate' => round($h['q'] / $hdays * 30, 1)]; }
    usort($hits, fn($a, $b) => $b['profit'] <=> $a['profit']);
    // тренды по шинам: доля R17+ и бренды (архив)
    $trend = ['r17h' => null, 'r17l' => null, 'brands' => []];
    $sh = function ($arr) { $all = 0; $big = 0; foreach ($arr as $x) { if ($x['kind'] !== 'tire') continue; $all += $x['q']; if (preg_match('/ R(\d{2})/', $x['g'], $m) && (int)$m[1] >= 17) $big += $x['q']; } return $all ? round($big / $all * 100) : null; };
    $rows = function ($per) use ($p) { if (!$per) return []; $st = $p->prepare('SELECT kind, gkey g, qty q FROM hist WHERE pfrom = ? AND pto = ?'); $st->execute([$per['pfrom'], $per['pto']]); return $st->fetchAll(); };
    $trend['r17h'] = $sh($rows($recent)); $trend['r17l'] = $sh($L); if ($long) { $trend['r17long'] = $sh($rows($long)); $trend['long'] = $long; }
    $bb = []; if ($hper) { $st = $p->prepare("SELECT h.qty, h.rev, h.cost, p.attrs FROM hist h JOIN products p ON p.id = h.product_id WHERE h.pfrom = ? AND h.pto = ? AND p.kind = 'tire'"); $st->execute([$hper['pfrom'], $hper['pto']]);
      foreach ($st->fetchAll() as $r) { $a = json_decode($r['attrs'], true) ?: []; $k = trim((string)($a['brand'] ?? '')) ?: '—'; $bb[$k] = $bb[$k] ?? ['k' => $k, 'q' => 0, 'rev' => 0, 'cost' => 0]; $bb[$k]['q'] += $r['qty']; $bb[$k]['rev'] += $r['rev']; $bb[$k]['cost'] += $r['cost']; } }
    usort($bb, fn($a, $b) => $b['rev'] <=> $a['rev']); $trend['brands'] = array_map(fn($x) => ['k' => $x['k'], 'q' => round($x['q']), 'rev' => round($x['rev']), 'margin' => $x['rev'] ? round(($x['rev'] - $x['cost']) / $x['rev'] * 100, 1) : 0], array_slice($bb, 0, 10));
    out(['mode' => $mode, 'period' => $hper, 'long' => $long, 'hdays' => $hdays, 'ldays' => $ldays, 'winterNow' => $winterNow, 'dovezti' => array_slice($dov, 0, 40), 'dead' => array_slice($dead, 0, 40), 'deadMoney' => array_sum(array_column($dead, 'money')), 'deadN' => count($dead), 'low' => array_slice($low, 0, 25), 'hits' => array_slice($hits, 0, 25), 'trend' => $trend]);

  case 'doc_reprice':
    // новая оценка поставщика в проведённом акте ОХ: не молча — изменение дописывается в комментарий документа
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $pid = (int)($b['product_id'] ?? 0); $new = round((float)($b['cost'] ?? 0), 2); if ($new <= 0) fail('Укажите новую цену');
    $p = db($cfg); $st = $p->prepare('SELECT * FROM docs WHERE id = ?'); $st->execute([$id]); $d = $st->fetch(); if (!$d) fail('Документ не найден', 404);
    if ($d['type'] !== 'in' || $d['wh'] !== 'custody' || $d['status'] !== 'posted') fail('Цену так можно менять только в проведённом акте приёма на ответхранение', 409);
    $m = $p->prepare('SELECT m.cost, p.name FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id = ? AND m.product_id = ? LIMIT 1'); $m->execute([$id, $pid]); $old = $m->fetch(); if (!$old) fail('Строка не найдена', 404);
    $p->beginTransaction();
    $p->prepare('UPDATE moves SET cost = ? WHERE doc_id = ? AND product_id = ?')->execute([$new, $id, $pid]);
    // по желанию — и в уже проведённых продажах этой позиции с ОХ после даты акта
    $n = 0; if (!empty($b['resale'])) { $u = $p->prepare("UPDATE moves SET cost = ? WHERE product_id = ? AND wh = 'custody' AND qty < 0 AND doc_id IN (SELECT id FROM docs WHERE type = 'sale' AND status = 'posted' AND day >= ?)"); $u->execute([$new, $pid, $d['day']]); $n = $u->rowCount(); }
    $line = date('d.m.Y') . ': оценка «' . $old['name'] . '» ' . (0 + (float)$old['cost']) . ' → ' . (0 + $new) . ' ₽' . ($n ? ' (и в ' . $n . ' стр. продаж с ОХ)' : '');
    $p->prepare('UPDATE docs SET note = ? WHERE id = ?')->execute([trim(trim((string)$d['note']) . "\n" . $line), $id]);
    $p->commit(); logit($cfg, $role, 'doc_reprice', $id . ': ' . $line);
    out(['ok' => true, 'sales' => $n]);

  case 'doc_cancel':
    // отмена проведения: документ остаётся в списке зачёркнутым, с причиной и временем
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $reason = mb_substr(trim((string)($b['reason'] ?? '')), 0, 300); if ($reason === '') fail('Напишите причину отмены');
    $st = db($cfg)->prepare('SELECT * FROM docs WHERE id = ?'); $st->execute([$id]); $d = $st->fetch(); if (!$d) fail('Документ не найден', 404);
    if ($d['type'] === 'open') fail('Стартовые остатки не отменяются — исправляются инвентаризацией', 409);
    if ($d['status'] === 'draft' && !empty($d['req'])) { db($cfg)->prepare("UPDATE docs SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE id = ?")->execute([time(), $role, $reason, $id]); out(['ok' => true]); }
    if ($d['status'] === 'draft') { db($cfg)->prepare('DELETE FROM moves WHERE doc_id = ?')->execute([$id]); db($cfg)->prepare('DELETE FROM docs WHERE id = ?')->execute([$id]); out(['deleted' => true]); }
    if ($d['status'] !== 'posted') fail('Документ уже отменён', 409);
    if ($d['type'] === 'sale') { $r = db($cfg)->prepare("SELECT id FROM docs WHERE type = 'ret' AND status = 'posted' AND base_id = ?"); $r->execute([$id]); $ids = $r->fetchAll(PDO::FETCH_COLUMN);
      if ($ids) fail('По этой продаже проведён возврат № ' . implode(', ', $ids) . ' — сначала отмените его', 409); }
    // проверка: не уйдёт ли остаток в минус
    $SM = ['shop' => stockMap($cfg, 'shop'), 'custody' => stockMap($cfg, 'custody')]; $l = db($cfg)->prepare('SELECT m.product_id, m.wh, m.qty, p.name FROM moves m JOIN products p ON p.id = m.product_id WHERE m.doc_id = ?'); $l->execute([$id]); $neg = [];
    foreach ($l->fetchAll() as $x) { $w = $x['wh'] === 'custody' ? 'custody' : 'shop'; $after = ($SM[$w][(int)$x['product_id']]['q'] ?? 0) - (float)$x['qty']; if ($after < 0) $neg[] = $x['name'] . ($w === 'custody' ? ' (ОХ)' : '') . ' (станет ' . $after . ')'; }
    if ($neg && empty($b['force'])) out(['needForce' => true, 'negative' => $neg]);
    db($cfg)->prepare("UPDATE docs SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE id = ?")->execute([time(), $role, $reason, $id]);
    if ($d['type'] === 'sale' && !empty($d['zak_id'])) db($cfg)->prepare("UPDATE zak SET status = 'came', closed = NULL, updated = ? WHERE id = ? AND status = 'done'")->execute([time(), (int)$d['zak_id']]);
    logit($cfg, $role, 'doc_cancel', $id . ': ' . $reason);
    out(['ok' => true]);

  case 'rep_sales':
    // отчёт «Продажи по товарам» за период: продано минус возвращено, выручка, себестоимость, прибыль
    needRole($role, ['owner']);
    $from = (string)($_GET['from'] ?? ''); $to = (string)($_GET['to'] ?? ''); if (!isDay($from) || !isDay($to)) fail('Укажите период');
    $st = db($cfg)->prepare("SELECT m.product_id pid, p.name, p.kind, d.type, SUM(ABS(m.qty)) q, SUM(ABS(m.qty) * COALESCE(m.price, 0)) rev, SUM(ABS(m.qty) * m.cost) cost, SUM(ABS(m.qty) * COALESCE(m.list, m.price, 0)) lst, COUNT(DISTINCT d.id) nd FROM moves m JOIN docs d ON d.id = m.doc_id JOIN products p ON p.id = m.product_id WHERE d.status = 'posted' AND d.type IN ('sale','ret') AND d.day >= ? AND d.day <= ? GROUP BY m.product_id, d.type");
    $st->execute([$from, $to]); $rows = [];
    foreach ($st->fetchAll() as $r) { $k = (int)$r['pid']; $sg = $r['type'] === 'ret' ? -1 : 1;
      if (!isset($rows[$k])) $rows[$k] = ['id' => $k, 'name' => $r['name'], 'kind' => $r['kind'], 'q' => 0, 'rev' => 0, 'cost' => 0, 'disc' => 0, 'retq' => 0];
      $rows[$k]['q'] += $sg * (float)$r['q']; $rows[$k]['rev'] += $sg * (float)$r['rev']; $rows[$k]['cost'] += $sg * (float)$r['cost'];
      if ($sg > 0) $rows[$k]['disc'] += max(0, (float)$r['lst'] - (float)$r['rev']); else $rows[$k]['retq'] += (float)$r['q']; }
    $rows = array_values(array_map(function ($x) { foreach (['rev', 'cost', 'disc'] as $f) $x[$f] = round($x[$f], 2); $x['profit'] = round($x['rev'] - $x['cost'], 2); return $x; }, $rows));
    usort($rows, fn($a, $b) => $b['rev'] <=> $a['rev']);
    $c = db($cfg)->prepare("SELECT COUNT(*) FROM docs WHERE status = 'posted' AND type = 'sale' AND day >= ? AND day <= ?"); $c->execute([$from, $to]);
    out(['rows' => $rows, 'checks' => (int)$c->fetchColumn()]);

  case 'suppliers':
    // поставщики из приходов: сколько приходов, на какую сумму, когда последний
    needRole($role, ['owner']);
    $o = [];
    foreach (db($cfg)->query("SELECT d.id, d.partner, d.day, d.wh, SUM(m.qty * m.cost) s, SUM(m.qty) q FROM docs d LEFT JOIN moves m ON m.doc_id = d.id WHERE d.type = 'in' AND d.status = 'posted' GROUP BY d.id")->fetchAll() as $r) {
      $n = trim((string)$r['partner']); if ($n === '') $n = 'Без поставщика'; $k = mb_strtoupper($n);
      if (!isset($o[$k])) $o[$k] = ['name' => $n, 'n' => 0, 'sum' => 0, 'qty' => 0, 'custody' => 0, 'last' => ''];
      $o[$k]['n']++; if ($r['wh'] === 'custody') $o[$k]['custody'] += (float)$r['q']; else { $o[$k]['sum'] += (float)$r['s']; $o[$k]['qty'] += (float)$r['q']; }
      if ($r['day'] > $o[$k]['last']) $o[$k]['last'] = $r['day'];
    }
    $o = array_values($o); usort($o, fn($a, $b) => strcmp($b['last'], $a['last']));
    out(['suppliers' => array_map(function ($x) { $x['sum'] = round($x['sum'], 2); return $x; }, $o)]);

  case 'photo_models':
    // модели товаров для раздела «Фото товаров»: по умолчанию то, что есть в наличии (наш склад и ответхранение)
    needRole($role, ['owner']);
    $all = ($_GET['all'] ?? '0') === '1'; $sm = stockMap($cfg); $cu = stockMap($cfg, 'custody'); $pm = photoMap($cfg); $g = [];
    foreach (db($cfg)->query("SELECT id, name, kind, attrs FROM products WHERE archived = 0 AND kind != 'svc' ORDER BY name")->fetchAll() as $p) {
      $id = (int)$p['id']; $q = ($sm[$id]['q'] ?? 0) + ($cu[$id]['q'] ?? 0); if (!$all && $q <= 0) continue;
      $a = json_decode($p['attrs'], true) ?: []; $k = photoKey($p['kind'], $a, $p['name'], $id);
      if (!isset($g[$k])) $g[$k] = ['k' => $k, 'kind' => $p['kind'], 'label' => photoLabel($p['kind'], $a, $p['name']), 'n' => 0, 'qty' => 0, 'names' => [], 'photo' => $pm[$k]['url'] ?? null, 'source' => $pm[$k]['source'] ?? null];
      $g[$k]['n']++; $g[$k]['qty'] += max(0, $q); if (count($g[$k]['names']) < 3) $g[$k]['names'][] = $p['name'];
    }
    $ord = ['tire' => 1, 'disk' => 2, 'akb' => 3, 'fast' => 4];
    $g = array_values($g); usort($g, fn($x, $y) => (($ord[$x['kind']] ?? 9) <=> ($ord[$y['kind']] ?? 9)) ?: ($y['qty'] <=> $x['qty']));
    out(['models' => $g, 'gd' => function_exists('imagecreatefromstring')]);

  case 'photo_upload':
    // загрузка своего фото (multipart: k, file)
    post(); needRole($role, ['owner']);
    $k = (string)($_POST['k'] ?? ''); if ($k === '' || strlen($k) > 400) fail('Не указана модель');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Файл не дошёл до сервера' . (!empty($_FILES['file']) ? ' (код ' . $_FILES['file']['error'] . ')' : ''));
    $url = photoSave($cfg, $k, (string)file_get_contents($_FILES['file']['tmp_name']), 'manual'); logit($cfg, $role, 'photo_upload', $k);
    out(['url' => $url]);

  case 'photo_url':
    // фото по ссылке (например, с сайта производителя): сервер скачивает и хранит у себя
    post(); needRole($role, ['owner']);
    $b = body(); $k = (string)($b['k'] ?? ''); $u = trim((string)($b['url'] ?? '')); if ($k === '') fail('Не указана модель');
    $url = photoSave($cfg, $k, fetchUrl($u), 'url', $u); logit($cfg, $role, 'photo_url', $k);
    out(['url' => $url]);

  case 'photo_del':
    post(); needRole($role, ['owner']);
    $k = (string)(body()['k'] ?? ''); $st = db($cfg)->prepare('SELECT file FROM photos WHERE k = ?'); $st->execute([$k]);
    if ($f = $st->fetchColumn()) @unlink(photoDir() . '/' . $f);
    db($cfg)->prepare('DELETE FROM photos WHERE k = ?')->execute([$k]); logit($cfg, $role, 'photo_del', $k);
    out(['ok' => true]);

  case 'photos_public':
    // для витрины: ID карточки CloudShop → адрес фото. Только картинки, без цен и остатков
    $pm = photoMap($cfg); $o = []; if ($pm) photoDir();
    if ($pm) foreach (db($cfg)->query("SELECT id, ext_id, name, kind, attrs FROM products WHERE archived = 0 AND ext_id IS NOT NULL AND ext_id != ''")->fetchAll() as $p) {
      $k = photoKey($p['kind'], json_decode($p['attrs'], true) ?: [], $p['name'], (int)$p['id']); if (isset($pm[$k])) $o[$p['ext_id']] = $pm[$k]['url']; }
    header('Cache-Control: public, max-age=300'); header('Access-Control-Allow-Origin: *');
    out(['base' => basename(__DIR__) . '/', 'photos' => $o ?: new stdClass()]);

  case 'api4t_test':
    // проверка связи с API поставщика: какие методы и типы он описывает (без паролей)
    needRole($role, ['owner']);
    if (!class_exists('SoapClient')) out(['soap' => false, 'hint' => 'Включите расширение soap в настройках PHP на Джино']);
    $c = api4t($cfg); $types = array_map(fn($t) => mb_substr(preg_replace('/\s+/', ' ', $t), 0, 600), $c->__getTypes());
    $res = ['soap' => true, 'login_set' => !empty($cfg['api4t_login']), 'functions' => $c->__getFunctions(), 'types' => array_values(array_filter($types, fn($t) => preg_match('/Tyre|Tire|Disk|Filter|Rest|Goods|img/i', $t)))];
    // пробный поиск: 205/55 R16
    try { $r = $c->GetFindTyre(['login' => $cfg['api4t_login'], 'password' => $cfg['api4t_password'], 'filter' => ['width' => 205, 'height' => 55, 'diameter' => 16], 'page' => 0, 'pageSize' => 5]);
      $it = []; api4tItems($r, $it); $res['probe'] = ['ok' => true, 'items' => array_slice($it, 0, 3), 'raw' => mb_substr(json_encode($r, JSON_UNESCAPED_UNICODE), 0, 3000)]; }
    catch (Throwable $e) { $res['probe'] = ['ok' => false, 'error' => $e->getMessage()]; }
    out($res);

  case 'photo_suggest':
    // предложить фото модели из API поставщика: ищем по размеру одной из шин модели и сверяем бренд+модель
    needRole($role, ['owner']);
    $k = (string)($_GET['k'] ?? ''); $auto = ($_GET['auto'] ?? '0') === '1';
    if (strpos($k, 'tire|') !== 0) out(['items' => [], 'note' => 'Автоподбор пока только для шин']);
    [, $brand, $model, $stud] = array_pad(explode('|', $k), 4, '');
    $sizes = [];
    foreach (db($cfg)->query("SELECT name, attrs FROM products WHERE kind = 'tire' AND archived = 0")->fetchAll() as $p) { $a = json_decode($p['attrs'], true) ?: [];
      if (photoKey('tire', $a, $p['name'], 0) === $k && !empty($a['w'])) $sizes[$a['w'] . '/' . $a['p'] . '/' . $a['d']] = $a; if (count($sizes) >= 2) break; }
    if (!$sizes) out(['items' => [], 'note' => 'Нет размеров этой модели']);
    $c = api4t($cfg); $cand = []; $nb = normModel($brand); $nm = normModel($model); $err = null;
    foreach ($sizes as $a) {
      try { $r = $c->GetFindTyre(['login' => $cfg['api4t_login'], 'password' => $cfg['api4t_password'], 'filter' => ['width' => (float)$a['w'], 'height' => (float)$a['p'], 'diameter' => (float)$a['d']], 'page' => 0, 'pageSize' => 200]); }
      catch (Throwable $e) { $err = $e->getMessage(); continue; }
      $it = []; api4tItems($r, $it);
      foreach ($it as $x) { $t = normModel(implode(' ', $x['txt']));
        // совпадение: бренд и все слова модели есть в названии у поставщика
        $words = array_filter(explode(' ', $nm)); $hit = ($nb === '' || strpos(' ' . $t . ' ', ' ' . $nb . ' ') !== false); foreach ($words as $w) if (strpos(' ' . $t . ' ', ' ' . $w . ' ') === false) { $hit = false; break; }
        if (!$hit) continue; $score = 100 - abs(mb_strlen($t) - mb_strlen($nb . ' ' . $nm)); if ($stud === 'S' && preg_match('/ШИП|STUD/u', $t)) $score += 5;
        // у поставщика бывает несколько вариантов фото (с водяным знаком и без): предлагаем все крупные,
        // выше — те, что по названию поля похожи на «чистые»; варианты с пометкой my/wm/logo — в конце
        foreach ($x['imgs'] as $kk => $u) {
          if (preg_match('/small|thumb|mini|_s$/i', $kk) && count($x['imgs']) > 1) continue;
          $pri = preg_match('/my|wm|water|logo|mark/i', $kk) ? -20 : (preg_match('/pish|clean|orig|nowm|without/i', $kk) ? 15 : (preg_match('/big|large|full/i', $kk) ? 5 : 0));
          $sc = $score + $pri;
          if (!isset($cand[$u]) || $cand[$u]['score'] < $sc) $cand[$u] = ['url' => $u, 'field' => (string)$kk, 'title' => implode(' · ', array_unique(array_values($x['txt']))) . ' · ' . $kk, 'score' => $sc]; } }
      if (count($cand) >= 4) break;
    }
    usort($cand, fn($x, $y) => $y['score'] <=> $x['score']); $cand = array_slice(array_values($cand), 0, 6);
    if ($auto && $cand) { $u = photoSave($cfg, $k, fetchUrl($cand[0]['url']), '4tochki', $cand[0]['url']); out(['saved' => $u, 'field' => $cand[0]['field'] ?? '', 'items' => $cand]); }
    out(['items' => $cand, 'error' => $cand ? null : $err]);

  case 'doc_match':
    // строки документа поставщика → наши карточки: запомненный код, артикул производителя, штрихкод, затем по названию
    post(); needRole($role, ['owner']);
    $b = body(); $pk = partnerKey((string)($b['partner'] ?? '')); $lines = array_slice((array)($b['lines'] ?? []), 0, 300);
    $prods = db($cfg)->query("SELECT id, name, kind, attrs, article, barcode, price, unit, cae FROM products WHERE archived = 0 AND kind != 'svc'")->fetchAll();
    $byArt = []; $byBc = []; $byId = []; $byCae = [];
    foreach ($prods as $p) { $byId[(int)$p['id']] = $p; if ($p['article'] !== '') $byArt[codeNorm($p['article'])][] = (int)$p['id']; if ($p['barcode'] !== '') $byBc[ltrim($p['barcode'], '0')][] = (int)$p['id']; if ((string)$p['cae'] !== '') $byCae[codeNorm($p['cae'])][] = (int)$p['id']; }
    $sup = []; if ($pk !== '') { $st = db($cfg)->prepare('SELECT code, product_id FROM supcodes WHERE pk = ?'); $st->execute([$pk]); foreach ($st->fetchAll() as $r) $sup[$r['code']] = (int)$r['product_id']; }
    $sm = stockMap($cfg); $cu = stockMap($cfg, 'custody'); $last = lastCosts($cfg);
    $card = fn($id, $how) => ['id' => $id, 'kind' => $byId[$id]['kind'], 'name' => $byId[$id]['name'], 'article' => $byId[$id]['article'], 'price' => (float)$byId[$id]['price'], 'unit' => $byId[$id]['unit'], 'qty' => $sm[$id]['q'] ?? 0, 'custody' => $cu[$id]['q'] ?? 0, 'last' => $last[$id] ?? null, 'how' => $how];
    $out = [];
    foreach ($lines as $l) {
      $code = trim((string)($l['code'] ?? '')); $scode = trim((string)($l['scode'] ?? '')); $name = (string)($l['name'] ?? ''); $res = ['match' => null, 'alts' => []];
      foreach ([$code, $scode] as $c) if ($c !== '' && isset($sup[codeNorm($c)]) && isset($byId[$sup[codeNorm($c)]])) { $res['match'] = $card($sup[codeNorm($c)], 'saved'); break; }
      if (!$res['match'] && $code !== '' && !empty($byCae[codeNorm($code)])) { $ids = $byCae[codeNorm($code)]; $res['match'] = $card($ids[0], 'cae'); foreach (array_slice($ids, 1, 4) as $i) $res['alts'][] = $card($i, 'cae'); }
      if (!$res['match'] && $code !== '' && !empty($byArt[codeNorm($code)])) { $ids = $byArt[codeNorm($code)]; $res['match'] = $card($ids[0], 'article'); foreach (array_slice($ids, 1, 4) as $i) $res['alts'][] = $card($i, 'article'); }
      if (!$res['match'] && $code !== '' && !empty($byBc[ltrim($code, '0')])) $res['match'] = $card($byBc[ltrim($code, '0')][0], 'barcode');
      // проверка найденного по коду: у шины должны совпасть размер и бренд, у диска — диаметр. Не совпало — код считаем ошибочным
      if ($res['match']) { $lk = (string)($l['kind'] ?? ''); $la = (array)($l['attrs'] ?? []); $mp = $byId[$res['match']['id']]; $ma = json_decode($mp['attrs'], true) ?: []; $bad = false;
        $nb0 = fn($x) => preg_replace('/[^0-9A-ZА-ЯЁ]+/u', '', str_replace(['TYRES', 'TIRES'], '', mb_strtoupper((string)$x)));
        if ($lk === 'tire' && !empty($la['w'])) $bad = $mp['kind'] !== 'tire' || (int)($ma['w'] ?? 0) !== (int)$la['w'] || (int)($ma['p'] ?? 0) !== (int)$la['p'] || (int)($ma['d'] ?? 0) !== (int)$la['d'] || ($nb0($ma['brand'] ?? '') !== '' && $nb0($la['brand'] ?? '') !== '' && $nb0($ma['brand'] ?? '') !== $nb0($la['brand'] ?? ''));
        elseif ($lk === 'disk' && !empty($la['d'])) $bad = $mp['kind'] !== 'disk' || (int)($ma['d'] ?? 0) !== (int)$la['d'];
        if ($bad) { if ($res['match']['how'] === 'saved' && $pk !== '') foreach ([$code, $scode] as $c) if ($c !== '') db($cfg)->prepare('DELETE FROM supcodes WHERE pk = ? AND code = ?')->execute([$pk, codeNorm($c)]);
          $res['wrong'] = $res['match']['name']; $res['match'] = null; $res['alts'] = []; } }
      if (!$res['match']) {
        // по названию — строго: шина = тот же размер, бренд, модель и шипы; диск = те же диаметр, разболтовка, вылет и модель.
        // Совпало не всё — ничего не выбираем сами, только показываем варианты того же размера.
        $kind = (string)($l['kind'] ?? ''); $at = (array)($l['attrs'] ?? []); $exact = []; $near = [];
        $nm = fn($x) => preg_replace('/[^0-9A-ZА-ЯЁ]+/u', '', mb_strtoupper(preg_replace('/\(.*$/u', '', preg_replace('/\([^)]*\)/u', '', (string)$x))));
        $nb = fn($x) => preg_replace('/[^0-9A-ZА-ЯЁ]+/u', '', str_replace(['TYRES', 'TIRES'], '', mb_strtoupper((string)$x)));
        if ($kind === 'tire' && !empty($at['w'])) {
          foreach ($prods as $p) { if ($p['kind'] !== 'tire') continue; $a = json_decode($p['attrs'], true) ?: [];
            if ((int)($a['w'] ?? 0) !== (int)$at['w'] || (int)($a['p'] ?? 0) !== (int)$at['p'] || (int)($a['d'] ?? 0) !== (int)$at['d']) continue;
            if ($nb($a['brand'] ?? '') !== $nb($at['brand'] ?? '')) continue;
            $same = $nm($a['model'] ?? '') === $nm($at['model'] ?? '') && !empty($a['stud']) === !empty($at['stud']);
            if ($same) $exact[] = ['id' => (int)$p['id'], 'ix' => ($a['index'] ?? '') === ($at['index'] ?? '')]; else $near[] = (int)$p['id']; }
          usort($exact, fn($x, $y) => $y['ix'] <=> $x['ix']);
          if ($exact && (count($exact) === 1 || $exact[0]['ix'] && !$exact[1]['ix'])) $res['match'] = $card($exact[0]['id'], 'name');
          foreach ($exact as $e) if (!$res['match'] || $e['id'] !== $res['match']['id']) $res['alts'][] = $card($e['id'], 'name');
          foreach (array_slice($near, 0, 6) as $i2) $res['alts'][] = $card($i2, 'size');
        } elseif ($kind === 'disk' && !empty($at['d'])) {
          foreach ($prods as $p) { if ($p['kind'] !== 'disk') continue; $a = json_decode($p['attrs'], true) ?: [];
            if ((int)($a['d'] ?? 0) !== (int)$at['d']) continue;
            $pc = array_map(fn($x) => str_replace(',', '.', (string)$x), (array)($a['pcds'] ?? [])); if (!empty($at['pcd']) && !in_array(str_replace(',', '.', (string)$at['pcd']), $pc, true)) continue;
            if (isset($at['et'], $a['et']) && abs((float)$a['et'] - (float)$at['et']) > 0.01) continue;
            if (isset($at['dia'], $a['dia']) && abs((float)$a['dia'] - (float)$at['dia']) > 0.15) continue;
            $tm = array_filter(explode(' ', preg_replace('/[^0-9A-ZА-ЯЁ\- ]+/u', ' ', mb_strtoupper((string)($at['model'] ?? '')))), fn($w) => mb_strlen($w) > 1);
            $pm = array_filter(explode(' ', preg_replace('/[^0-9A-ZА-ЯЁ\- ]+/u', ' ', mb_strtoupper((string)($a['model'] ?? '')))), fn($w) => mb_strlen($w) > 1);
            // модель диска: все слова с цифрами (X-131, KHW1711, 6355) должны совпасть
            $code = fn($ws) => array_values(array_filter($ws, fn($w) => preg_match('/\d/', $w)));
            $ct = $code($tm); $cp = $code($pm);
            if ($ct && !array_diff($ct, $cp)) $exact[] = (int)$p['id']; else $near[] = (int)$p['id']; }
          if (count($exact) === 1) $res['match'] = $card($exact[0], 'name');
          foreach ($exact as $e) if (!$res['match'] || $e !== $res['match']['id']) $res['alts'][] = $card($e, 'name');
          foreach (array_slice($near, 0, 6) as $i2) $res['alts'][] = $card($i2, 'size');
        } else {
          // прочее: кольца — по двум диаметрам точно, остальное — только варианты по словам
          $tk = nameTokens($name); $ring = preg_grep('/^RING/', $tk); $sc = [];
          foreach ($prods as $p) { if (in_array($p['kind'], ['tire', 'disk'], true)) continue; $ptk = nameTokens($p['name']);
            if ($ring) { if (array_intersect($ring, $ptk)) $exact[] = (int)$p['id']; continue; }
            $hit = count(array_intersect($tk, $ptk)); if ($hit >= 2) $sc[(int)$p['id']] = $hit - 0.3 * count(array_diff($ptk, $tk)); }
          if (count($exact) === 1) $res['match'] = $card($exact[0], 'name');
          arsort($sc); foreach (array_slice(array_keys($sc), 0, 5) as $i2) $res['alts'][] = $card($i2, 'words');
        }
        $res['alts'] = array_slice($res['alts'], 0, 6);
      }
      $out[] = $res;
    }
    $dup = null; $num = trim((string)($b['num'] ?? ''));
    if ($num !== '') { $st = db($cfg)->prepare("SELECT id, type, status, partner FROM docs WHERE num = ? AND status != 'cancelled'"); $st->execute([$num]); foreach ($st->fetchAll() as $r) if (partnerKey((string)$r['partner']) === $pk || $pk === '') { $dup = ['id' => (int)$r['id'], 'label' => docLabel($r['type'])]; break; } }
    out(['lines' => $out, 'dup' => $dup]);

  case 'supmap_save':
    // запомнить соответствие «код поставщика → наша карточка»
    post(); needRole($role, ['owner']);
    $b = body(); $pk = partnerKey((string)($b['partner'] ?? '')); if ($pk === '') out(['ok' => true]);
    $ins = db($cfg)->prepare('INSERT OR REPLACE INTO supcodes (pk, code, product_id, updated) VALUES (?,?,?,?)'); $n = 0;
    foreach (array_slice((array)($b['pairs'] ?? []), 0, 500) as $x) { $pid = (int)($x['product_id'] ?? 0); if (!$pid) continue;
      foreach ([(string)($x['code'] ?? ''), (string)($x['scode'] ?? '')] as $c) if (trim($c) !== '') { $ins->execute([$pk, codeNorm($c), $pid, time()]); $n++; }
      // код номенклатуры поставщика (CAE / артикул производителя) — в карточку, если артикула ещё нет
      $code0 = trim((string)($x['code'] ?? '')); if ($code0 !== '' && preg_match('/^[0-9A-Za-z\-\.\/]{4,40}$/', $code0)) db($cfg)->prepare("UPDATE products SET article = ?, updated = ? WHERE id = ? AND (article IS NULL OR article = '')")->execute([$code0, time(), $pid]); 
      // документ от Пауэра (в т.ч. «ОХ Пауэр…»): их код — это CAE, кладём в карточку
      if ($code0 !== '' && isPower($pk) && preg_match('/^\S{3,40}$/u', $code0)) db($cfg)->prepare('UPDATE products SET cae = ?, updated = ? WHERE id = ?')->execute([$code0, time(), $pid]); }
    out(['ok' => true, 'saved' => $n]);

  case 'vitrina':
    // для витрины: живое наличие (наш склад + ОХ) и розничные цены. Без себестоимости, поставщиков и закупок
    // наличие = наш склад + ответхранение: товар с ОХ тоже продаём, клиенту разница не важна
    $sm = stockMap($cfg, 'shop'); $smc = stockMap($cfg, 'custody'); $tires = []; $other = [];
    foreach (db($cfg)->query("SELECT id, ext_id, name, kind, attrs, price, country FROM products WHERE archived = 0 AND kind != 'svc' AND price > 0")->fetchAll() as $p) {
      $q = (int)floor(max(0, $sm[(int)$p['id']]['q'] ?? 0) + max(0, $smc[(int)$p['id']]['q'] ?? 0)); if ($q <= 0) continue;
      $a = json_decode($p['attrs'], true) ?: []; $id = $p['ext_id'] !== null && $p['ext_id'] !== '' ? (string)$p['ext_id'] : 'p' . $p['id'];
      $n = trim(preg_replace('/\s+/u', ' ', $p['name'])); $pr = (int)round((float)$p['price']);
      if ($p['kind'] === 'tire' && !empty($a['w'])) {
        $tires[] = ['id' => $id, 'w' => (int)$a['w'], 'p' => (int)$a['p'], 'd' => (int)$a['d'], 'c' => !empty($a['c']), 'b' => (string)($a['brand'] ?? ''), 'm' => (string)($a['model'] ?? ''), 'a' => $a['alias'] ?? null,
          's' => $a['season'] ?? 'all', 'st' => !empty($a['stud']), 'xl' => !empty($a['xl']), 'i' => $a['index'] ?? null, 'pr' => $pr, 'q' => $q, 'suv' => !empty($a['suv']), 'cy' => $p['country'] !== '' ? $p['country'] : null];
        continue; }
      $o = ['id' => $id, 'n' => $n, 'pr' => $pr, 'q' => $q];
      if ($p['kind'] === 'disk') { $o += ['t' => 'disk', 'k' => $a['type'] ?? 'cast', 'pcds' => $a['pcds'] ?? [], 'd' => isset($a['d']) ? (int)$a['d'] : null, 'w' => $a['w'] ?? null, 'pcd' => $a['pcd'] ?? null, 'et' => $a['et'] ?? null, 'dia' => $a['dia'] ?? null, 'm' => ($a['model'] ?? '') !== '' ? $a['model'] : $n]; }
      elseif ($p['kind'] === 'akb') { $o += ['t' => 'akb', 'ah' => $a['ah'] ?? null, 'pol' => $a['pol'] ?? null, 'std' => $a['std'] ?? 'euro', 'en' => $a['en'] ?? null, 'm' => preg_replace('/^Аккумулятор\s+/u', '', $n)]; }
      elseif ($p['kind'] === 'fast') { $o += ['t' => 'fast', 'th' => $a['thread'] ?? null, 'seat' => $a['seat'] ?? null, 'k' => $a['type'] ?? 'other']; if (!empty($a['ring'])) { $o['ro'] = $a['ring']['out'] ?? null; $o['ri'] = $a['ring']['in'] ?? null; } }
      elseif (in_array($p['kind'], ['tireo', 'tube', 'tool'], true)) $o['t'] = $p['kind'];
      else { $g = $a['group'] ?? 'acc'; $o += ['t' => 'acc', 'g' => $g === 'consumable' ? 'acc' : $g]; }
      $other[] = $o;
    }
    $last = (int)db($cfg)->query("SELECT MAX(COALESCE(posted_at, created)) FROM docs WHERE status = 'posted'")->fetchColumn();
    header('Cache-Control: public, max-age=60'); header('Access-Control-Allow-Origin: *');
    out(['live' => true, 'stamp' => date('d.m.Y H:i'), 'changed' => $last ? date('d.m.Y H:i', $last) : null, 'tires' => $tires, 'other' => $other]);

  case 'stock_compare':
  case 'stock_sync':
    // сверка остатков нашего склада с выгрузкой каталога CloudShop; stock_sync — проводит корректировку разницы
    post(); needRole($role, ['owner']);
    $b = body(); $file = [];
    foreach ((array)($b['lines'] ?? []) as $l) { $e = trim((string)($l['ext_id'] ?? '')); if ($e !== '') $file[$e] = ['q' => (float)($l['qty'] ?? 0), 'c' => max(0, (float)($l['cost'] ?? 0))]; }
    if (count($file) < 10) fail('В файле слишком мало строк — это не полная выгрузка каталога');
    $sm = stockMap($cfg, 'shop'); $diffs = []; $tot = ['ours_q' => 0, 'file_q' => 0, 'ours_c' => 0, 'file_c' => 0, 'n' => 0, 'plus' => 0, 'minus' => 0];
    foreach (db($cfg)->query("SELECT id, ext_id, name, kind FROM products WHERE kind != 'svc' AND ext_id IS NOT NULL AND ext_id != ''")->fetchAll() as $p) {
      $id = (int)$p['id']; $o = $sm[$id] ?? ['q' => 0, 'c' => 0]; $f = $file[$p['ext_id']] ?? ['q' => 0, 'c' => 0];
      $tot['ours_q'] += max(0, $o['q']); $tot['file_q'] += max(0, $f['q']); $tot['ours_c'] += max(0, $o['c']); $tot['file_c'] += max(0, $f['q']) * $f['c'];
      $d = round($f['q'] - $o['q'], 3); if (abs($d) < 0.001) continue;
      $avg = $o['q'] > 0 ? $o['c'] / $o['q'] : 0;
      $diffs[] = ['id' => $id, 'name' => $p['name'], 'ours' => $o['q'], 'file' => $f['q'], 'd' => $d, 'cost' => round($d > 0 ? ($f['c'] ?: $avg) : ($avg ?: $f['c']), 2)];
      $tot['n']++; if ($d > 0) $tot['plus'] += $d; else $tot['minus'] += -$d;
    }
    $known = array_flip(db($cfg)->query("SELECT ext_id FROM products WHERE ext_id IS NOT NULL AND ext_id != ''")->fetchAll(PDO::FETCH_COLUMN));
    $tot['missing'] = 0; foreach ($file as $e => $f) if ($f['q'] > 0 && !isset($known[$e])) $tot['missing']++;
    foreach (['ours_c', 'file_c'] as $k) $tot[$k] = round($tot[$k], 2);
    usort($diffs, fn($x, $y) => abs($y['d'] * $y['cost']) <=> abs($x['d'] * $x['cost']));
    if ($a === 'stock_compare') out(['total' => $tot, 'diffs' => array_slice($diffs, 0, 400)]);
    if (!$diffs) out(['ok' => true, 'id' => null]);
    $day = isDay($b['day'] ?? null) ? $b['day'] : date('Y-m-d'); $p = db($cfg); $p->beginTransaction();
    $p->prepare("INSERT INTO docs (type, num, day, partner, note, wh, status, author, created, posted_at) VALUES ('inv', '', ?, '', ?, 'shop', 'posted', ?, ?, ?)")->execute([$day, 'Сверка остатков с выгрузкой CloudShop: ' . count($diffs) . ' поз.', $role, time(), time()]);
    $id = (int)$p->lastInsertId(); $ins = $p->prepare("INSERT INTO moves (doc_id, product_id, wh, qty, cost, price) VALUES (?,?, 'shop', ?,?, NULL)");
    foreach ($diffs as $x) $ins->execute([$id, $x['id'], $x['d'], $x['cost']]);
    $p->commit(); logit($cfg, $role, 'stock_sync', (string)$id);
    out(['ok' => true, 'id' => $id, 'n' => count($diffs)]);

  // ===== справочник Пауэра: скачать выгрузку (шины / диски) и подобрать CAE нашим карточкам =====
  case 'p4t_load':
    post(); needRole($role, ['owner']); @set_time_limit(900); @ini_set('memory_limit', '256M');
    $part = ($_GET['part'] ?? '') === 'rims' ? 'rims' : 'tires';
    $base = rtrim((string)($cfg['p4t_base'] ?? 'https://b2b.4tochki.ru/export_data'), '/');
    $tmp = dirname($cfg['db_path']) . '/p4t-' . $part . '.xml';
    p4tDownload($base . '/' . ($part === 'rims' ? 'Rims' : 'Tires') . '.xml', $tmp);
    $n = p4tParse($tmp, $part, db($cfg)); @unlink($tmp);
    setSetting($cfg, 'p4t_' . $part, date('d.m.Y H:i') . ' · ' . $n);
    logit($cfg, $role, 'p4t_load', $part . ':' . $n); out(['part' => $part, 'n' => $n]);

  case 'p4t_match':
    // всем шинам и дискам без CAE — подобрать по справочнику; ручные и уже стоящие коды не трогаем
    post(); needRole($role, ['owner']); @set_time_limit(600);
    $p = db($cfg); if (!(int)$p->query('SELECT COUNT(*) FROM p4t')->fetchColumn()) fail('Сначала скачайте справочник Пауэра', 409);
    $sm = stockMap($cfg, 'shop'); $cu = stockMap($cfg, 'custody');
    $st = ['ok' => 0, 'amb' => 0, 'none' => 0, 'nosize' => 0, 'had' => 0]; $miss = []; $amb = [];
    $up = $p->prepare('UPDATE products SET cae = ?, updated = ? WHERE id = ? AND cae = \'\''); $p->beginTransaction();
    foreach ($p->query("SELECT id, name, kind, attrs, cae FROM products WHERE archived = 0 AND kind IN ('tire','disk')")->fetchAll() as $pr) {
      if ((string)$pr['cae'] !== '') { $st['had']++; continue; }
      $r = p4tFind($p, $pr); $st[$r['st']] = ($st[$r['st']] ?? 0) + 1; $q = ($sm[(int)$pr['id']]['q'] ?? 0) + ($cu[(int)$pr['id']]['q'] ?? 0);
      if ($r['st'] === 'ok') $up->execute([$r['cae'], time(), (int)$pr['id']]);
      elseif ($q > 0 && $r['st'] === 'amb') $amb[] = ['id' => (int)$pr['id'], 'name' => $pr['name'], 'q' => $q, 'alts' => $r['alts']];
      elseif ($q > 0) $miss[] = ['id' => (int)$pr['id'], 'name' => $pr['name'], 'q' => $q];
    }
    $p->commit(); logit($cfg, $role, 'p4t_match', json_encode($st));
    usort($miss, fn($x, $y) => $y['q'] <=> $x['q']);
    out(['stat' => $st, 'amb' => array_slice($amb, 0, 80), 'miss' => array_slice($miss, 0, 150), 'miss_n' => count($miss)]);

  case 'p4t_state':
    needRole($role, ['owner']);
    $p = db($cfg);
    out(['tires' => getSetting($cfg, 'p4t_tires'), 'rims' => getSetting($cfg, 'p4t_rims'),
      'with' => (int)$p->query("SELECT COUNT(*) FROM products WHERE archived = 0 AND kind IN ('tire','disk') AND cae != ''")->fetchColumn(),
      'all' => (int)$p->query("SELECT COUNT(*) FROM products WHERE archived = 0 AND kind IN ('tire','disk')")->fetchColumn()]);

  case 'prod_cae':
    // CAE в карточке вручную (пусто — убрать)
    post(); needRole($role, ['owner']);
    $b = body(); $id = (int)($b['id'] ?? 0); $c = mb_substr(trim((string)($b['cae'] ?? '')), 0, 40);
    db($cfg)->prepare('UPDATE products SET cae = ?, updated = ? WHERE id = ?')->execute([$c, time(), $id]); logit($cfg, $role, 'prod_cae', $id . '=' . $c);
    out(['ok' => true]);

  // ===== переход с CloudShop: чистый лист, ОХ, наш склад =====
  case 'start_info':
    needRole($role, ['owner']);
    $p = db($cfg); $by = [];
    foreach ($p->query("SELECT type, status, COUNT(*) n FROM docs GROUP BY type, status")->fetchAll() as $r) $by[] = ['label' => docLabel($r['type']), 'type' => $r['type'], 'status' => $r['status'], 'n' => (int)$r['n']];
    $sum = function (array $m) { $q = 0; $c = 0; $n = 0; foreach ($m as $x) if ($x['q'] > 0) { $q += $x['q']; $c += $x['c']; $n++; } return ['n' => $n, 'q' => $q, 'c' => round($c, 2)]; };
    out(['docs' => $by, 'total' => (int)$p->query('SELECT COUNT(*) FROM docs')->fetchColumn(), 'shop' => $sum(stockMap($cfg, 'shop')), 'custody' => $sum(stockMap($cfg, 'custody')),
      'open' => $p->query("SELECT id, day FROM docs WHERE type = 'open' AND status = 'posted' ORDER BY id LIMIT 1")->fetch() ?: null]);

  case 'start_reset':
    // убрать все документы (пробные) — перед этим полная копия базы. Карточки, архив CloudShop, заказы и шиномонтаж не трогаем
    post(); needRole($role, ['owner']);
    if (mb_strtoupper(trim((string)(body()['confirm'] ?? ''))) !== 'УДАЛИТЬ') fail('Для подтверждения напишите слово УДАЛИТЬ');
    $raw = sqliteSnapshot($cfg['db_path']); $bf = backupDir($cfg) . '/montazh-pered-ochistkoj-' . date('Y-m-d-His') . '.sqlite.gz';
    if ($raw === null || @file_put_contents($bf, gzencode($raw, 6)) === false) fail('Не получилось сделать резервную копию — ничего не удалено', 500);
    $p = db($cfg); $n = (int)$p->query('SELECT COUNT(*) FROM docs')->fetchColumn();
    $p->beginTransaction(); $p->exec('DELETE FROM moves'); $p->exec('DELETE FROM docs'); $p->exec("DELETE FROM sqlite_sequence WHERE name IN ('docs','moves')");
    $p->exec("UPDATE zak SET status = 'came' WHERE status = 'done'"); $p->commit();
    logit($cfg, $role, 'start_reset', $n . ' docs; backup ' . basename($bf));
    out(['ok' => true, 'deleted' => $n, 'backup' => basename($bf)]);

  case 'start_custody':
    // черновик прихода на ответхранение из файла CloudShop (для поставщика без кабинета, напр. «ОХ Север Авто»)
    post(); needRole($role, ['owner']);
    $b = body(); $partner = mb_substr(trim((string)($b['partner'] ?? '')), 0, 200); if ($partner === '') fail('Не указан поставщик');
    $p = db($cfg); $note = 'Стартовый остаток ОХ из CloudShop';
    $ex = $p->prepare("SELECT id FROM docs WHERE type = 'in' AND wh = 'custody' AND partner = ? AND note = ? AND status != 'cancelled' LIMIT 1"); $ex->execute([$partner, $note]);
    if ($id = $ex->fetchColumn()) out(['id' => (int)$id, 'exists' => true]);
    $sel = $p->prepare('SELECT id FROM products WHERE ext_id = ?'); $L = []; $miss = 0;
    foreach (array_slice((array)($b['rows'] ?? []), 0, 2000) as $r) { $q = (float)($r['qty'] ?? 0); if ($q <= 0) continue; $sel->execute([(string)($r['ext'] ?? '')]); $pid = $sel->fetchColumn(); if (!$pid) { $miss++; continue; } $L[] = [(int)$pid, $q, max(0, (float)($r['cost'] ?? 0))]; }
    if (!$L) fail('Ни одна строка не нашлась в каталоге');
    $p->beginTransaction();
    $p->prepare("INSERT INTO docs (type, num, day, partner, note, wh, status, author, created) VALUES ('in', '', ?, ?, ?, 'custody', 'draft', ?, ?)")->execute([isDay($b['day'] ?? null) ? $b['day'] : date('Y-m-d'), $partner, $note, $role, time()]);
    $id = (int)$p->lastInsertId(); $ins = $p->prepare("INSERT INTO moves (doc_id, product_id, wh, qty, cost, price) VALUES (?,?, 'custody', ?,?, NULL)");
    foreach ($L as [$pid, $q, $c]) $ins->execute([$id, $pid, $q, $c]);
    $p->commit(); logit($cfg, $role, 'start_custody', $id . ' ' . $partner);
    out(['id' => $id, 'lines' => count($L), 'missing' => $miss]);

  case 'start_cmp':
  case 'start_sync':
    // наш склад = остаток в CloudShop минус то, что сейчас числится на ответхранении (ОХ вводится раньше, из кабинета Пауэра)
    post(); needRole($role, ['owner']);
    $b = body(); $file = [];
    foreach ((array)($b['rows'] ?? []) as $r) { $e = trim((string)($r['ext'] ?? '')); if ($e !== '') $file[$e] = ['q' => (float)($r['total'] ?? 0), 'c' => max(0, (float)($r['cost'] ?? 0))]; }
    if (count($file) < 10) fail('В файле слишком мало строк — это не выгрузка остатков');
    $sm = stockMap($cfg, 'shop'); $cu = stockMap($cfg, 'custody'); $diffs = []; $conf = [];
    $tot = ['file_q' => 0, 'file_c' => 0, 'cust_q' => 0, 'target_q' => 0, 'ours_q' => 0, 'n' => 0, 'plus' => 0, 'minus' => 0, 'neg' => 0];
    foreach (db($cfg)->query("SELECT id, ext_id, name FROM products WHERE kind != 'svc'")->fetchAll() as $pr) {
      $id = (int)$pr['id']; $f = $file[(string)$pr['ext_id']] ?? ['q' => 0, 'c' => 0]; $o = $sm[$id] ?? ['q' => 0, 'c' => 0]; $c = max(0, $cu[$id]['q'] ?? 0);
      if ($f['q'] < 0) $tot['neg']++;
      $fq = max(0, $f['q']); $tgt = max(0, $fq - $c);
      if ($c > $fq + 1e-9 && (string)$pr['ext_id'] !== '') $conf[] = ['id' => $id, 'name' => $pr['name'], 'file' => $f['q'], 'custody' => $c];
      $tot['file_q'] += $fq; $tot['file_c'] += $fq * $f['c']; $tot['cust_q'] += $c; $tot['target_q'] += $tgt; $tot['ours_q'] += max(0, $o['q']);
      $d = round($tgt - $o['q'], 3); if (abs($d) < 0.001) continue;
      $avg = $o['q'] > 0 ? $o['c'] / $o['q'] : 0;
      $diffs[] = ['id' => $id, 'name' => $pr['name'], 'ours' => $o['q'], 'file' => $f['q'], 'custody' => $c, 'target' => $tgt, 'd' => $d, 'cost' => round($d > 0 ? ($f['c'] ?: $avg) : ($avg ?: $f['c']), 2)];
      $tot['n']++; if ($d > 0) $tot['plus'] += $d; else $tot['minus'] += -$d;
    }
    $known = array_flip(db($cfg)->query("SELECT ext_id FROM products WHERE ext_id IS NOT NULL AND ext_id != ''")->fetchAll(PDO::FETCH_COLUMN));
    $tot['missing'] = []; foreach ($file as $e => $f) if ($f['q'] > 0 && !isset($known[$e])) $tot['missing'][] = $e;
    $tot['file_c'] = round($tot['file_c'], 2);
    usort($diffs, fn($x, $y) => abs($y['d'] * $y['cost']) <=> abs($x['d'] * $x['cost']));
    // карточки без ID CloudShop с остатком (созданы в пульте) — чаще всего дубли: предлагаем, с какой объединить
    $orph = []; $nk = fn($s) => preg_replace('/[^0-9A-ZА-Я]+/u', '', mb_strtoupper(str_replace(['Laufen ', 'IIkon'], ['Laufenn ', 'Ikon'], preg_replace('/\(\d+ шип\.?\)|шип\.?/u', '', (string)$s))));
    $withExt = db($cfg)->query("SELECT id, name, ext_id FROM products WHERE archived = 0 AND ext_id IS NOT NULL AND ext_id != '' AND kind != 'svc'")->fetchAll();
    foreach (db($cfg)->query("SELECT id, name FROM products WHERE archived = 0 AND (ext_id IS NULL OR ext_id = '') AND kind != 'svc'")->fetchAll() as $pr) {
      $id = (int)$pr['id']; $q = ($sm[$id]['q'] ?? 0) + ($cu[$id]['q'] ?? 0); if (abs($q) < 1e-9) continue;
      $k = $nk($pr['name']); $sz = strtok($pr['name'], ' '); $ix = preg_match('/\b(\d{2,3}(?:\/\d{2,3})?[A-Z])\b/u', $pr['name'], $m) ? $m[1] : ''; $sug = [];
      foreach ($withExt as $e) { if (strpos($e['name'], $sz . ' ') !== 0) continue; $ek = $nk($e['name']);
        $sc = $ek === $k ? 100 : 0; if (!$sc) { if ($ix === '' || !preg_match('/\b' . preg_quote($ix, '/') . '\b/u', $e['name'])) continue; similar_text($k, $ek, $pc); $sc = (int)$pc; if ($sc < 70) continue; }
        $sug[] = ['id' => (int)$e['id'], 'name' => $e['name'], 'score' => $sc, 'file' => $file[(string)$e['ext_id']]['q'] ?? 0]; }
      usort($sug, fn($x, $y) => $y['score'] <=> $x['score']);
      $orph[] = ['id' => $id, 'name' => $pr['name'], 'shop' => $sm[$id]['q'] ?? 0, 'custody' => $cu[$id]['q'] ?? 0, 'sug' => array_slice($sug, 0, 4)]; }
    if ($a === 'start_cmp') out(['total' => $tot, 'diffs' => array_slice($diffs, 0, 300), 'conflicts' => $conf, 'orphans' => $orph]);
    if ($tot['missing']) fail('Сначала добавьте недостающие карточки (' . count($tot['missing']) . ')', 409);
    if (!$diffs) out(['ok' => true, 'id' => null]);
    $p = db($cfg); $open = $p->query("SELECT id FROM docs WHERE type = 'open' AND status = 'posted' LIMIT 1")->fetchColumn();
    $type = $open ? 'inv' : 'open'; $day = isDay($b['day'] ?? null) ? $b['day'] : date('Y-m-d');
    $p->beginTransaction();
    $p->prepare("INSERT INTO docs (type, num, day, partner, note, wh, status, author, created, posted_at) VALUES (?, '', ?, '', ?, 'shop', 'posted', ?, ?, ?)")
      ->execute([$type, $day, ($type === 'open' ? 'Стартовые остатки нашего склада из CloudShop' : 'Сверка нашего склада с CloudShop') . ' (без товара на ОХ): ' . count($diffs) . ' поз.', $role, time(), time()]);
    $id = (int)$p->lastInsertId(); $ins = $p->prepare("INSERT INTO moves (doc_id, product_id, wh, qty, cost, price) VALUES (?,?, 'shop', ?,?, NULL)");
    foreach ($diffs as $x) $ins->execute([$id, $x['id'], $x['d'], $x['cost']]);
    $p->commit(); logit($cfg, $role, 'start_sync', $type . ' ' . $id);
    out(['ok' => true, 'id' => $id, 'type' => $type, 'n' => count($diffs)]);

  case 'prod_move':
  case 'prod_merge':
    // перенос остатка с карточки на карточку (дубли из CloudShop, не та карточка в акте) — проведённым документом «Корректировка».
    // prod_merge: переносит всё (наш склад и ОХ), коды поставщиков и CAE, а старую карточку убирает из каталога
    post(); needRole($role, ['owner']);
    $b = body(); $from = (int)($b['from'] ?? 0); $to = (int)($b['to'] ?? 0); if (!$from || !$to || $from === $to) fail('Выберите другую карточку');
    $p = db($cfg); $g = $p->prepare('SELECT * FROM products WHERE id = ?'); $g->execute([$from]); $A = $g->fetch(); $g->execute([$to]); $B = $g->fetch(); if (!$A || !$B) fail('Карточка не найдена', 404);
    $sm = ['shop' => stockMap($cfg, 'shop'), 'custody' => stockMap($cfg, 'custody')]; $L = [];
    foreach (['shop', 'custody'] as $w) { $have = $sm[$w][$from] ?? ['q' => 0, 'c' => 0];
      $q = $a === 'prod_merge' ? $have['q'] : (($b['wh'] ?? 'custody') === $w ? (float)($b['qty'] ?? 0) : 0);
      if (abs($q) < 1e-9) continue; if ($a === 'prod_move' && $q > $have['q'] + 1e-9) fail('На карточке столько нет: ' . $have['q']);
      $L[] = [$w, $q, $have['q'] > 0 ? round($have['c'] / $have['q'], 2) : 0]; }
    if (!$L && $a === 'prod_move') fail('Нечего переносить');
    $p->beginTransaction(); $id = null;
    if ($L) { $p->prepare("INSERT INTO docs (type, num, day, partner, note, wh, status, author, created, posted_at) VALUES ('inv', '', ?, '', ?, ?, 'posted', ?, ?, ?)")
        ->execute([date('Y-m-d'), ($a === 'prod_merge' ? 'Объединение карточек: ' : 'Перенос остатка: ') . '«' . $A['name'] . '» → «' . $B['name'] . '»', $L[0][0], $role, time(), time()]);
      $id = (int)$p->lastInsertId(); $ins = $p->prepare('INSERT INTO moves (doc_id, product_id, wh, qty, cost, price) VALUES (?,?,?,?,?, NULL)');
      foreach ($L as [$w, $q, $c]) { $ins->execute([$id, $from, $w, -$q, $c]); $ins->execute([$id, $to, $w, $q, $c]); } }
    if ($a === 'prod_merge') {
      $p->prepare('UPDATE OR REPLACE supcodes SET product_id = ? WHERE product_id = ?')->execute([$to, $from]);
      if ((string)$B['cae'] === '' && (string)$A['cae'] !== '') $p->prepare('UPDATE products SET cae = ? WHERE id = ?')->execute([$A['cae'], $to]);
      if ((string)$B['article'] === '' && (string)$A['article'] !== '') $p->prepare('UPDATE products SET article = ? WHERE id = ?')->execute([$A['article'], $to]);
      $p->prepare('UPDATE products SET archived = 1, updated = ? WHERE id = ?')->execute([time(), $from]); }
    $p->commit(); logit($cfg, $role, $a, $from . '>' . $to . ' doc ' . $id);
    out(['ok' => true, 'id' => $id]);

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
