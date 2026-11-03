<?php
/* ═══════════════════════════════════════════════════════════════════
   ШИННЫЙ ОТЕЛЬ ВНУТРИ ПУЛЬТА (vladautoshina.ru/montazh/hotel/)
   Та же программа, что на sklad.shinhotel33.ru, и та же база:
   файл sklad.db и папка uploads берутся из папки старого адреса.
   Вход — по PIN пульта, отдельного пароля нет.
   ═══════════════════════════════════════════════════════════════════ */
require_once dirname(__DIR__) . '/hotel_path.php';
$__pult = is_file(dirname(__DIR__) . '/config.php') ? (include dirname(__DIR__) . '/config.php') : [];
$__hd = hotelDir(is_array($__pult) ? $__pult : []);
if ($__hd === '') {
    // новую пустую базу не создаём ни в коем случае — иначе данные разъедутся
    http_response_code(500); header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><body style="font:16px/1.5 system-ui;max-width:640px;margin:40px auto;padding:0 16px">'
       . '<h2>Не нашёл базу шинного отеля</h2><p>Пульт ищет файл <b>sklad.db</b> в папке сайта sklad.shinhotel33.ru. '
       . 'Откройте в FileZilla файл <b>montazh/config.php</b> и добавьте строку с путём к папке, где лежит sklad.db:</p>'
       . '<pre style="background:#eee;padding:10px;white-space:pre-wrap">\'hotel_dir\' => \'' . htmlspecialchars(dirname(__DIR__, 3)) . '/sklad.shinhotel33.ru\',</pre>'
       . '<p style="color:#666">Пульт сейчас лежит в ' . htmlspecialchars(dirname(__DIR__)) . '</p></body>';
    exit;
}
// Цены, сроки, геометрия склада и реквизиты — из настроек старого адреса (sklad.shinhotel33.ru/config.php):
// правите там — меняется и в пульте. Пароль оттуда пульт не использует.
if (is_readable($__hd . '/config.php')) {
    require_once $__hd . '/config.php';          // задаёт и DB_FILE, PHOTO_DIR — та же папка
    if (!defined('SHARE_BASE')) define('SHARE_BASE', 'https://sklad.shinhotel33.ru');
    return;
}
// запасной вариант, если файл настроек старого адреса не прочитался
define('DB_FILE', $__hd . '/sklad.db');
define('PHOTO_DIR', $__hd . '/uploads');
define('APP_PASS', bin2hex(random_bytes(16)));   // пароль не используется: вход через пульт
// ссылка на фотоотчёт для клиента — по-прежнему короткая, на старом адресе
define('SHARE_BASE', 'https://sklad.shinhotel33.ru');

// Цены за сезон, ₽
const PRICE_ASSEMBLED = 3500;   // колёса в сборе
const PRICE_TIRES     = 2500;   // шины без дисков
const PRICE_PRESEASON = 0;      // новые шины до сезона — бонус к покупке
const PRICE_MONTH     = 450;    // продление помесячно
const SEASON_MONTHS   = 7;
const PRESEASON_MONTHS = 4;

// Геометрия склада — как на sklad.shinhotel33.ru: 8 рядов × 13 мест × 3 яруса
const ROWS = [
    ['lines' => ['1'],      'kind' => 'wall'],
    ['lines' => ['2', '3'], 'kind' => 'double'],
    ['lines' => ['4', '5'], 'kind' => 'double'],
    ['lines' => ['6', '7'], 'kind' => 'double'],
    ['lines' => ['8'],      'kind' => 'wall'],
];
const SECTIONS = 13;
const TIERS    = 3;
const CROSS_AISLE_AFTER = 6;

const PHOTO_MAX_SIDE = 1600;
const PHOTO_QUALITY  = 82;
const PHOTO_MAX_MB   = 12;

const ORG_SHORT   = 'ИП Нефедов И. А.';
const ORG_PERSON  = 'Нефедова Ильи Алексеевича';
const ORG_SIGN    = 'Нефедов И. А.';
const ORG_OGRNIP  = '316774600460403';
const ORG_INN     = '772704339012';
const ORG_ADDR    = '600901, г. Владимир, мкр. Юрьевец, ул. Ноябрьская, д. 129';
const ORG_ADDR_UR = '117452, г. Москва, ул. Азовская, д. 24/1, кв. 399';
const ORG_PHONE   = '8 (4922) 26-19-98, +7 (999) 7-100-108';
const ORG_EMAIL   = 'vladautoshina@mail.ru';
const ORG_SITE    = 'shinhotel33.ru';
const ORG_BRAND   = 'Шинный отель IVANOR';

const PHOTO_CLAIM_DAYS = 3;

date_default_timezone_set('Europe/Moscow');
