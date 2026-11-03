<?php
/* Где лежит база шинного отеля — та же sklad.db, с которой работает sklad.shinhotel33.ru.
   Пульт и старый адрес отеля работают с одним файлом, поэтому данные не расходятся.
   Если папка не нашлась сама, впишите в montazh/config.php строку
   'hotel_dir' => '/полный/путь/к/папке/sklad.shinhotel33.ru',               */
function hotelDirCandidates(array $cfg = []): array {
  $c = [];
  if (!empty($cfg['hotel_dir'])) $c[] = rtrim((string)$cfg['hotel_dir'], '/');
  $subs = ['sklad.shinhotel33.ru', 'sklad.shinhotel33.ru/public_html', 'sklad.shinhotel33.ru/www', 'sklad.shinhotel33.ru/htdocs',
    'shinhotel33.ru/sklad', 'shinhotel33.ru/public_html/sklad', 'shinhotel33.ru/www/sklad', 'domains/sklad.shinhotel33.ru', 'domains/sklad.shinhotel33.ru/public_html'];
  for ($up = 1; $up <= 4; $up++) { $b = dirname(__DIR__, $up); foreach ($subs as $s) $c[] = $b . '/' . $s; }
  return array_values(array_unique($c));
}
function hotelDir(array $cfg = []): string {
  static $found = null; if ($found !== null) return $found;
  foreach (hotelDirCandidates($cfg) as $d) if (@is_file($d . '/sklad.db')) return $found = $d;
  // последняя попытка: любая папка со словом sklad рядом с сайтом
  for ($up = 1; $up <= 3; $up++) foreach (array_merge((array)@glob(dirname(__DIR__, $up) . '/*sklad*/sklad.db'), (array)@glob(dirname(__DIR__, $up) . '/*sklad*/*/sklad.db')) as $f) if ($f && @is_file($f)) return $found = dirname($f);
  return $found = '';
}
