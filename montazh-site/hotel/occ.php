<?php
/* ═══════════════════════════════════════════════════════════════════
   Сводка занятости для лендинга shinhotel33.ru.

   Отдаёт РОВНО три числа: всего мест, занято, свободно. Ни имён,
   ни телефонов, ни адресов ячеек, ни сумм — по этому адресу узнать
   о клиентах нечего. Поэтому он и открыт без пароля.

   Проверить: откройте sklad.shinhotel33.ru/occ.php в браузере,
   должно быть что-то вроде {"ok":true,"total":312,"busy":90,...}

   Выключить: удалите файл. Сайт этого не заметит — он подставит
   запасную цифру из своего CONFIG.
   ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';   // нужен только ради capacity(), сам не подключается

/* Кто имеет право читать это из браузера. Чужой сайт вставить наши цифры
   к себе не сможет: браузер не отдаст ему ответ. */
$allowed = [
    'https://shinhotel33.ru',
    'https://www.shinhotel33.ru',
    'https://sklad.shinhotel33.ru',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');   // 5 минут — незачем дёргать базу чаще
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

/* Читаем базу напрямую и только на чтение: миграции схемы и запись здесь
   не нужны, а лишний захват файла на публичном адресе ни к чему. */
$busy = null;
try {
    if (is_file(DB_FILE)) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA busy_timeout=2000');
        $busy = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE status='stored'")
                         ->fetchColumn();
    }
} catch (Throwable $e) {
    $busy = null;   // база занята или сломана — честно скажем «нет данных»
}

if ($busy === null) {
    http_response_code(503);
    exit(json_encode(['ok' => false, 'error' => 'нет данных'], JSON_UNESCAPED_UNICODE));
}

/* Один комплект — одна ячейка, поэтому занятость считается по комплектам
   на хранении, а не по проставленным адресам: комплект физически лежит
   на складе и без записанного адреса. */
$total = capacity();
$busy  = max(0, min($busy, $total));

echo json_encode([
    'ok'      => true,
    'total'   => $total,
    'busy'    => $busy,
    'free'    => $total - $busy,
    'updated' => date('c'),
], JSON_UNESCAPED_UNICODE);
