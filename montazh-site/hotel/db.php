<?php
/* База, авторизация, помощники. */
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('<h1>Нет модуля pdo_sqlite</h1><p>Попросите хостинг включить расширение '
           . '<code>pdo_sqlite</code> для PHP — без него база не откроется.</p>');
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=4000');
    schema($pdo);
    return $pdo;
}

function schema(PDO $p): void {
    $p->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, num INTEGER, season TEXT,
        kind TEXT NOT NULL, client TEXT NOT NULL, phone TEXT, phone_digits TEXT,
        descr TEXT, size TEXT, brand TEXT, qty INTEGER DEFAULT 4, cell TEXT,
        accepted_at TEXT, due_at TEXT, paid INTEGER DEFAULT 0,
        services TEXT, services_sum INTEGER DEFAULT 0, services_paid INTEGER DEFAULT 0,
        status TEXT DEFAULT 'stored', issued_at TEXT, note TEXT,
        created_at TEXT, updated_at TEXT)");
    $p->exec("CREATE TABLE IF NOT EXISTS photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER NOT NULL,
        file TEXT NOT NULL, at TEXT)");
    $p->exec("CREATE TABLE IF NOT EXISTS log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, item_id INTEGER,
        at TEXT, action TEXT, details TEXT)");
    $p->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT)");
    foreach (['i_client'=>'items(client)', 'i_phone'=>'items(phone_digits)',
              'i_status'=>'items(status)', 'i_cell'=>'items(cell)',
              'i_ph_item'=>'photos(item_id)', 'i_log_item'=>'log(item_id)'] as $n => $on) {
        $p->exec("CREATE INDEX IF NOT EXISTS $n ON $on");
    }
    // добавления к схеме на уже работающих базах
    $have = [];
    foreach ($p->query("PRAGMA table_info(items)") as $c) $have[$c['name']] = true;
    foreach (['share_token' => 'TEXT', 'deleted_at' => 'TEXT',
              'doc_no' => 'TEXT', 'act_at' => 'TEXT'] as $col => $type) {
        if (!isset($have[$col])) $p->exec("ALTER TABLE items ADD COLUMN $col $type");
    }
    $havep = [];
    foreach ($p->query("PRAGMA table_info(photos)") as $c) $havep[$c['name']] = true;
    if (!isset($havep['stage'])) $p->exec("ALTER TABLE photos ADD COLUMN stage TEXT DEFAULT 'accept'");
}

function logit(int $itemId, string $action, string $details = ''): void {
    db()->prepare('INSERT INTO log(item_id,at,action,details) VALUES(?,?,?,?)')
        ->execute([$itemId, date('Y-m-d H:i:s'), $action, $details]);
}

/* ─────────── Нумерация комплектов ───────────
   Номер считается заново в каждом сезоне: ЗИМА 2026 → 1…N, ЛЕТО 2026 → снова 1…N.
   Так цифра на бирке остаётся двузначной и читается с трёх метров, а сквозной
   счётчик к четвёртому сезону дорос бы до четырёх знаков и вдвое помельчал.
   Полное обозначение = номер плюс сезон: «12 · ЗИМА 2026». Путаницы нет. */

function nextNum(?string $season = null): int {
    $s = trim((string)$season);
    if ($s === '') {   // сезон не указан — продолжаем общий счёт, чтобы не было дублей
        return 1 + (int)db()->query('SELECT COALESCE(MAX(num),0) FROM items')->fetchColumn();
    }
    $st = db()->prepare('SELECT COALESCE(MAX(num),0) FROM items WHERE season=?');
    $st->execute([$s]);
    return 1 + (int)$st->fetchColumn();
}

/* Сезон пишем словами, без сокращений: «ЗИМА 2026», а не «З26».
   Сокращённый код пробовали — «З26» на печати и на слух сливается с числом 326,
   а выигрыш в длине нигде не нужен: места хватает везде. */

/** Полный номер комплекта: «12 · ЗИМА 2026». Без сезона — просто «12». */
function fullNum(array $it): string {
    $n = (int)($it['num'] ?? 0);
    $s = trim((string)($it['season'] ?? ''));
    return $s === '' ? (string)$n : $n . ' · ' . $s;
}

/** Сезоны, которые уже есть в базе, и следующий свободный номер в каждом.
    Нужны форме приёмки: поменяли сезон — номер пересчитался. */
function seasonCounters(PDO $p): array {
    $out = [];
    foreach ($p->query("SELECT season, MAX(num) mx FROM items
                        WHERE TRIM(season)<>'' GROUP BY season
                        ORDER BY MAX(accepted_at) DESC") as $r) {
        $out[$r['season']] = 1 + (int)$r['mx'];
    }
    return $out;
}



// ─────────── вход: по PIN пульта IVANOR (vladautoshina.ru/montazh/) ───────────
/* Роль из сессии пульта: owner, master или cashier. Сессию только читаем и сразу закрываем. */
function ivanorRole(): string {
    static $r = null; if ($r !== null) return $r;
    $r = '';
    if (empty($_COOKIE['ivanor_montazh'])) return $r;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    session_name('ivanor_montazh');
    @session_start(['read_and_close' => true, 'gc_probability' => 0]);
    $r = (string)($_SESSION['role'] ?? '');
    return $r;
}
function requireAuth(): void {
    if (ivanorRole() !== '') return;
    loginPage(); exit;
}

// ─────────── помощники ───────────
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((int)$n, 0, ',', ' ') . ' ₽'; }

function phoneDigits($v): string {
    $d = preg_replace('/\D/', '', (string)$v);
    if (strlen($d) === 11 && $d[0] === '8') $d = '7' . substr($d, 1);
    if (strlen($d) === 10) $d = '7' . $d;
    return strlen($d) === 11 ? $d : '';
}
function phoneFmt(string $d): string {
    return strlen($d) === 11
        ? sprintf('+%s (%s) %s-%s-%s', $d[0], substr($d,1,3), substr($d,4,3), substr($d,7,2), substr($d,9,2))
        : '';
}
function parseSize(string $t): string {
    return preg_match('~(\d{3})\s*[/.\-]\s*(\d{2})\s*[/.\-]\s*R?\s*(\d{2})~i', $t, $m)
        ? "{$m[1]}/{$m[2]} R{$m[3]}" : '';
}
function addMonths(string $ymd, int $n): string {
    if (!$ymd) return '';
    $d = DateTime::createFromFormat('Y-m-d', $ymd);
    if (!$d) return '';
    $day = (int)$d->format('j');
    $d->modify('first day of this month')->modify("+$n months");
    return $d->format('Y-m-') . sprintf('%02d', min($day, (int)$d->format('t')));
}
function ruDate(?string $ymd): string {
    if (!$ymd) return '—';
    $d = DateTime::createFromFormat('Y-m-d', substr($ymd, 0, 10));
    return $d ? $d->format('d.m.Y') : $ymd;
}
/* 1 комплект, 2 комплекта, 5 комплектов. */
function plural(int $n, string $one, string $few, string $many): string {
    $a = abs($n) % 100; $b = $a % 10;
    if ($a > 10 && $a < 20) return $many;
    if ($b > 1 && $b < 5)   return $few;
    return $b === 1 ? $one : $many;
}
function daysLeft(?string $due): ?int {
    if (!$due) return null;
    $d = DateTime::createFromFormat('Y-m-d', $due);
    return $d ? (int)(new DateTime('today'))->diff($d)->format('%r%a') : null;
}
/* Три вида хранения. preseason — новые шины, выкупленные клиентом,
   лежат у нас до начала сезона. Хранятся как шины без дисков. */
function kindName(string $k): string {
    return ['assembled' => 'Колёса в сборе',
            'tires'     => 'Шины без дисков',
            'preseason' => 'Новые шины до сезона'][$k] ?? 'Шины без дисков';
}
function kindShort(string $k): string {
    return ['assembled' => 'в сборе', 'tires' => 'шины',
            'preseason' => 'до сезона'][$k] ?? 'шины';
}
/** Класс для раскраски: a — оранжевый, t — синий, p — зелёный. */
function kindCls(string $k): string {
    return ['assembled' => 'a', 'tires' => 't', 'preseason' => 'p'][$k] ?? 't';
}
/** Допустимый вид хранения из чего угодно. */
function kindOf($v): string {
    $v = (string)$v;
    return in_array($v, ['assembled', 'tires', 'preseason'], true) ? $v : 'tires';
}
/** Цена по умолчанию и срок в месяцах для вида хранения. */
function kindPrice(string $k): int {
    return ['assembled' => PRICE_ASSEMBLED, 'tires' => PRICE_TIRES,
            'preseason' => PRICE_PRESEASON][$k] ?? PRICE_TIRES;
}
function kindMonths(string $k): int {
    return $k === 'preseason' ? PRESEASON_MONTHS : SEASON_MONTHS;
}

/** Плоский список рядов слева направо: 1…8 */
/** Подпись стеллажа на карте: «ряд 1» для пристенного, «ряды 2–3» для двустороннего. */
function rackName(array $row): string {
    $l = array_values($row['lines']);
    return count($l) > 1 ? 'ряды ' . $l[0] . '–' . end($l) : 'ряд ' . $l[0];
}
function allLines(): array {
    $out = [];
    foreach (ROWS as $r) foreach ($r['lines'] as $l) $out[] = $l;
    return $out;
}
function cellCode(string $line, int $sec, int $tier): string {
    return sprintf('%s-%02d-%d', $line, $sec, $tier);
}
function allCells(): array {
    $out = [];
    foreach (allLines() as $l)
        for ($s = 1; $s <= SECTIONS; $s++)
            for ($t = 1; $t <= TIERS; $t++) $out[] = cellCode($l, $s, $t);
    return $out;
}
function capacity(): int { return count(allLines()) * SECTIONS * TIERS; }

// ─────────── фотографии ───────────
function photoDir(): string {
    if (!is_dir(PHOTO_DIR)) @mkdir(PHOTO_DIR, 0775, true);
    $ht = PHOTO_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    return PHOTO_DIR;
}

/**
 * Сохраняет загруженный файл, по возможности ужимая.
 * Возвращает имя файла либо null, тогда в $err лежит причина.
 *
 * Айфон нередко отдаёт HEIC, который getimagesize() не знает. Раньше такие
 * снимки молча пропадали — теперь кладём как есть, без сжатия.
 */
function savePhoto(array $f, int $itemId, ?string &$err = null): ?string {
    $err = null;
    $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) return null;              // поле просто пустое
    if ($code !== UPLOAD_ERR_OK) {
        $err = [
            UPLOAD_ERR_INI_SIZE   => 'файл больше лимита сервера',
            UPLOAD_ERR_FORM_SIZE  => 'файл больше лимита формы',
            UPLOAD_ERR_PARTIAL    => 'файл догрузился не полностью',
            UPLOAD_ERR_NO_TMP_DIR => 'на сервере нет временной папки',
            UPLOAD_ERR_CANT_WRITE => 'сервер не смог записать файл',
            UPLOAD_ERR_EXTENSION  => 'загрузку заблокировало расширение PHP',
        ][$code] ?? ('ошибка загрузки № ' . $code);
        return null;
    }
    if (($f['size'] ?? 0) <= 0) { $err = 'пустой файл'; return null; }
    if ($f['size'] > PHOTO_MAX_MB * 1024 * 1024) {
        $err = 'больше ' . PHOTO_MAX_MB . ' МБ'; return null;
    }
    if (!is_uploaded_file($f['tmp_name'])) { $err = 'файл не дошёл до сервера'; return null; }

    $dir = photoDir();
    if (!is_dir($dir) || !is_writable($dir)) {
        $err = 'папка uploads закрыта на запись — поставьте ей права 775';
        return null;
    }

    $stamp = date('Ymd-His') . '-' . $itemId . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $info  = @getimagesize($f['tmp_name']);
    $mime  = $info['mime'] ?? '';

    // ── обычная картинка: ужимаем через GD
    $readers = ['image/jpeg' => 'imagecreatefromjpeg',
                'image/png'  => 'imagecreatefrompng',
                'image/webp' => 'imagecreatefromwebp'];
    if ($info && isset($readers[$mime]) && function_exists($readers[$mime])) {
        $src = @$readers[$mime]($f['tmp_name']);
        if ($src) {
            $dest = $dir . '/' . $stamp . '.jpg';
            [$w, $h] = [imagesx($src), imagesy($src)];
            $k  = min(1, PHOTO_MAX_SIDE / max($w, $h));
            $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $wrote = @imagejpeg($dst, $dest, PHOTO_QUALITY);
            imagedestroy($src); imagedestroy($dst);
            // раньше здесь не проверялся результат: запись в базе появлялась,
            // а файла на диске не было — в галерее получались битые картинки
            if ($wrote && is_file($dest) && filesize($dest) > 0) {
                @chmod($dest, 0644);
                return basename($dest);
            }
            @unlink($dest);
        }
    }

    // ── GD не справился или формат ему незнаком: кладём оригинал
    $ext = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    $byMime = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
               'image/gif'=>'gif','image/heic'=>'heic','image/heif'=>'heif'];
    if (isset($byMime[$mime])) $ext = $byMime[$mime];
    $allow = ['jpg','jpeg','png','webp','gif','heic','heif','bmp','tif','tiff'];
    if (!in_array($ext, $allow, true)) {
        $type = strtolower((string)($f['type'] ?? ''));
        if (strpos($type, 'image/') !== 0) { $err = 'это не изображение'; return null; }
        $ext = 'jpg';
    }
    $dest = $dir . '/' . $stamp . '.' . $ext;
    if (@move_uploaded_file($f['tmp_name'], $dest) && is_file($dest) && filesize($dest) > 0) {
        @chmod($dest, 0644);
        return basename($dest);
    }
    @unlink($dest);
    $err = 'не удалось записать файл в uploads';
    return null;
}

/** Есть ли снимок на диске. Битые ссылки бывают, если папка была закрыта на запись. */
function photoExists(string $file): bool {
    return $file !== '' && is_file(PHOTO_DIR . '/' . $file) && filesize(PHOTO_DIR . '/' . $file) > 0;
}


// ─────────── клиенты ───────────
/** Ключ клиента: телефон важнее имени, имя нормализуется. */
function clientKey(string $digits, string $name): string {
    if (strlen($digits) >= 10) return 'p' . substr($digits, -10);
    $n = mb_strtolower(trim($name), 'UTF-8');
    $n = str_replace('ё', 'е', $n);
    $n = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n);
    return 'n' . trim($n);
}

/**
 * Справочник клиентов по всей базе, включая выданные комплекты.
 * Для каждого: последнее написание ФИО и телефона, сколько раз сдавал, когда последний раз.
 */
function clientList(PDO $p): array {
    $out = [];
    $q = $p->query("SELECT client, phone, phone_digits, accepted_at, status, kind, descr
                    FROM items WHERE status<>'deleted' ORDER BY accepted_at");
    foreach ($q as $r) {
        $name = trim($r['client']);
        if ($name === '') continue;
        $k = clientKey($r['phone_digits'] ?? '', $name);
        if (!isset($out[$k])) $out[$k] = ['key'=>$k,'name'=>$name,'phone'=>'','digits'=>'',
                                          'n'=>0,'now'=>0,'last'=>'','descr'=>''];
        $c =& $out[$k];
        $c['n']++;
        if ($r['status'] === 'stored') $c['now']++;
        // берём самое свежее написание
        if ($r['accepted_at'] >= $c['last']) {
            $c['last']  = $r['accepted_at'];
            $c['name']  = $name;
            $c['descr'] = $r['descr'];
            if (!empty($r['phone_digits'])) {
                $c['phone']  = $r['phone'];
                $c['digits'] = $r['phone_digits'];
            }
        } elseif ($c['digits'] === '' && !empty($r['phone_digits'])) {
            $c['phone'] = $r['phone']; $c['digits'] = $r['phone_digits'];
        }
        unset($c);
    }
    uasort($out, fn($a, $b) => strcmp($b['last'], $a['last']));
    return $out;
}

/** Все комплекты одного клиента, свежие сверху. */
function clientItems(PDO $p, string $key): array {
    $out = [];
    $q = $p->query("SELECT * FROM items WHERE status<>'deleted'
                    ORDER BY accepted_at DESC, CAST(num AS INTEGER) DESC");
    foreach ($q as $r) {
        if (clientKey($r['phone_digits'] ?? '', trim($r['client'])) === $key) $out[] = $r;
    }
    return $out;
}

// ─────────── документы и ссылка для клиента ───────────
/** Короткий человекочитаемый код, без похожих друг на друга символов. */
function shareToken(): string {
    $ab = 'ACDEFGHJKLMNPQRTUVWXY3467989';
    $t = '';
    for ($i = 0; $i < 6; $i++) $t .= $ab[random_int(0, strlen($ab) - 1)];
    return $t;
}

/** Выдаёт код ссылки комплекта, создавая при первом обращении. */
function ensureToken(PDO $p, int $id): string {
    $st = $p->prepare('SELECT share_token FROM items WHERE id=?');
    $st->execute([$id]);
    $t = (string)$st->fetchColumn();
    if ($t !== '') return $t;
    do {
        $t = shareToken();
        $c = $p->prepare('SELECT COUNT(*) FROM items WHERE share_token=?');
        $c->execute([$t]);
    } while ((int)$c->fetchColumn() > 0);
    $p->prepare('UPDATE items SET share_token=? WHERE id=?')->execute([$t, $id]);
    return $t;
}

/** Номер договора: год-сезон-номер, например 2026-ЗИМА-012. Уникален в рамках года. */
function docNo(array $it): string {
    $y = substr($it['accepted_at'] ?: date('Y-m-d'), 0, 4);
    $n = str_pad((string)(int)$it['num'], 3, '0', STR_PAD_LEFT);
    $s = trim((string)($it['season'] ?? ''));
    $w = $s !== '' ? mb_strtoupper(preg_split('/\s+/u', $s)[0], 'UTF-8') : '';
    return $w === '' ? "$y-$n" : "$y-$w-$n";
}

/** Сумма прописью для рублей. */
function rubWords(int $n): string {
    $u = ['','один','два','три','четыре','пять','шесть','семь','восемь','девять'];
    $uf= ['','одна','две','три','четыре','пять','шесть','семь','восемь','девять'];
    $t10=['десять','одиннадцать','двенадцать','тринадцать','четырнадцать','пятнадцать',
          'шестнадцать','семнадцать','восемнадцать','девятнадцать'];
    $t = ['','','двадцать','тридцать','сорок','пятьдесят','шестьдесят','семьдесят','восемьдесят','девяносто'];
    $h = ['','сто','двести','триста','четыреста','пятьсот','шестьсот','семьсот','восемьсот','девятьсот'];
    $trio = function(int $v, bool $fem) use ($u,$uf,$t10,$t,$h): array {
        $out = [];
        $hh = intdiv($v, 100); $rest = $v % 100;
        if ($hh) $out[] = $h[$hh];
        if ($rest >= 10 && $rest < 20) { $out[] = $t10[$rest - 10]; $last = 0; }
        else {
            if (intdiv($rest,10)) $out[] = $t[intdiv($rest,10)];
            $last = $rest % 10;
            if ($last) $out[] = $fem ? $uf[$last] : $u[$last];
        }
        return [implode(' ', $out), $last];
    };
    if ($n === 0) return 'ноль рублей 00 копеек';
    $parts = [];
    $th = intdiv($n, 1000); $rub = $n % 1000;
    if ($th) {
        [$w, $last] = $trio($th, true);
        $form = ($last === 1) ? 'тысяча' : (($last >= 2 && $last <= 4) ? 'тысячи' : 'тысяч');
        $parts[] = $w . ' ' . $form;
    }
    if ($rub) { [$w, ] = $trio($rub, false); $parts[] = $w; }
    $l = $n % 100; $l1 = $n % 10;
    $rf = ($l >= 11 && $l <= 14) ? 'рублей' : (($l1 === 1) ? 'рубль' : (($l1 >= 2 && $l1 <= 4) ? 'рубля' : 'рублей'));
    $s = implode(' ', $parts) . ' ' . $rf . ' 00 копеек';
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/** Полная дата прописью: «17 марта 2026 г.» */
function ruDateFull(?string $ymd): string {
    if (!$ymd) return '____________';
    $m = ['января','февраля','марта','апреля','мая','июня',
          'июля','августа','сентября','октября','ноября','декабря'];
    $t = strtotime($ymd);
    if (!$t) return '____________';
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t) - 1] . ' ' . date('Y', $t) . ' г.';
}

/**
 * Папка приложения для тега <base>: '/' на поддомене, '/sklad/' в подпапке.
 *
 * Нужна из-за короткой ссылки /s/КОД: браузер видит адрес с «папкой» s,
 * и все относительные ссылки на странице — photo.php и прочее — начинает
 * искать в несуществующей /s/. Без <base> картинки отдают 404.
 */
function appBase(): string {
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim($dir, '/') . '/';
}

/** Короткая ссылка на фотоотчёт: sklad.shinhotel33.ru/s/K7M2QX */
function shareUrl(string $token): string { return SHARE_BASE . '/s/' . $token; }

/** Базовый адрес приложения, для ссылок в печатных формах. */
function baseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'sklad.shinhotel33.ru';
    $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return ($https ? 'https://' : 'http://') . $host . $dir;
}
