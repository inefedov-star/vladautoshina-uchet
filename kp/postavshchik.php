<?php
// ═══════════════════════════════════════════════════════════════════════
//   postavshchik.php — вытаскивает товар со страницы поставщика
//
//   Разборщик один на все сайты, а не четыре отдельных. Интернет-магазины
//   размечают карточку товара по стандарту schema.org — иначе их товары
//   не попадут в Яндекс и Google. Этой разметкой мы и пользуемся:
//   она меняется куда реже, чем вёрстка.
//
//   Порядок поиска, от надёжного к запасному:
//     1. JSON-LD с типом Product — название, снимок, цена разом;
//     2. разметка внутри тегов (itemprop) — тем же стандартом, но иначе;
//     3. og:title и og:image — то, что сайт отдаёт для пересылки в чатах;
//     4. заголовок h1 и первая крупная картинка — на самый крайний случай.
//
//   Проверено на shinservice.ru и mosautoshina.ru: сервер их пускает.
//   4tochki.ru и koleso.ru отвечают только настоящему браузеру — для них
//   есть закладка, см. zakladka.php.
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/obshee.php';

// Браузерный заголовок: без него часть магазинов отвечает пустой страницей
function postavshchik_fetch($url) {
    $zagolovki = array(
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml',
        'Accept-Language: ru-RU,ru;q=0.9',
    );
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $zagolovki);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code == 200) { return $body; }
        return null;
    }
    if (!ini_get('allow_url_fopen')) { return null; }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 20, 'follow_location' => 1, 'max_redirects' => 5,
        'header' => implode("\r\n", $zagolovki) . "\r\n",
    )));
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

// Относительный адрес картинки превращаем в полный
function polnyy_adres($src, $stranica) {
    if ($src === '') { return ''; }
    if (strpos($src, '//') === 0) { return 'https:' . $src; }
    if (preg_match('~^https?://~i', $src)) { return $src; }
    $ch = parse_url($stranica);
    if (!$ch || empty($ch['host'])) { return ''; }
    $koren = (isset($ch['scheme']) ? $ch['scheme'] : 'https') . '://' . $ch['host'];
    if (strpos($src, '/') === 0) { return $koren . $src; }
    $put = isset($ch['path']) ? preg_replace('~/[^/]*$~', '/', $ch['path']) : '/';
    return $koren . $put . $src;
}

// Цена может прийти как «13 230 ₽», «13230.00», «от 5 770 руб.»
function chislo_iz_ceny($v) {
    $v = str_replace(array("\xC2\xA0", ' '), '', (string) $v);
    $v = str_replace(',', '.', $v);
    if (!preg_match('~(\d+(?:\.\d+)?)~', $v, $m)) { return 0; }
    return (int) round((float) $m[1]);
}

// Вырезаем содержимое тегов <script type="application/ld+json">
// руками, без регулярных выражений.
//
// Регулярка с ленивым «(.*?)» по всему документу выглядит проще, но на
// большой странице упирается в предел отката PCRE и молча возвращает
// «ничего не найдено». У 4tochki страница весит 326 КБ — именно поэтому
// оттуда приходило название (его брал запасной источник), а цена, которая
// есть только в разметке, терялась. Поиск по позициям этой беды не знает.
function bloki_ld($html) {
    $out = array();
    $poz = 0;
    while (($n = stripos($html, '<script', $poz)) !== false) {
        $konec_tega = strpos($html, '>', $n);
        if ($konec_tega === false) { break; }
        $teg = substr($html, $n, $konec_tega - $n);
        $poz = $konec_tega + 1;
        if (stripos($teg, 'ld+json') === false) { continue; }
        $zakr = stripos($html, '</script', $poz);
        if ($zakr === false) { break; }
        $out[] = substr($html, $poz, $zakr - $poz);
        $poz = $zakr;
    }
    return $out;
}

// Текст первого заголовка h1 — тоже без ленивой регулярки
function tekst_h1($html) {
    $n = stripos($html, '<h1');
    if ($n === false) { return ''; }
    $k1 = strpos($html, '>', $n);
    if ($k1 === false) { return ''; }
    $k2 = stripos($html, '</h1', $k1);
    if ($k2 === false) { return ''; }
    return substr($html, $k1 + 1, $k2 - $k1 - 1);
}

// ── 1. JSON-LD ─────────────────────────────────────────────────────────
function iz_json_ld($html) {
    $out = array();
    foreach (bloki_ld($html) as $kusok) {
        $d = json_decode(trim($kusok), true);
        if (!is_array($d)) { continue; }
        // Product бывает и в корне, и внутри @graph, и в списке
        $kandidaty = array($d);
        if (isset($d['@graph']) && is_array($d['@graph'])) {
            $kandidaty = array_merge($kandidaty, $d['@graph']);
        }
        if (isset($d[0])) { $kandidaty = array_merge($kandidaty, $d); }
        foreach ($kandidaty as $k) {
            if (!is_array($k) || !isset($k['@type'])) { continue; }
            $tip = is_array($k['@type']) ? implode(' ', $k['@type']) : $k['@type'];
            if (stripos($tip, 'Product') === false) { continue; }

            if (!empty($k['name']) && empty($out['n'])) { $out['n'] = trim($k['name']); }
            if (!empty($k['image']) && empty($out['img'])) {
                $im = $k['image'];
                if (is_array($im)) { $im = isset($im[0]) ? $im[0] : reset($im); }
                if (is_array($im) && isset($im['url'])) { $im = $im['url']; }
                if (is_string($im)) { $out['img'] = $im; }
            }
            if (isset($k['offers']) && empty($out['p'])) {
                $of = $k['offers'];
                if (isset($of[0])) { $of = $of[0]; }
                if (isset($of['price']))      { $out['p'] = chislo_iz_ceny($of['price']); }
                else if (isset($of['lowPrice'])) { $out['p'] = chislo_iz_ceny($of['lowPrice']); }
                if (isset($of['availability'])) {
                    $out['est'] = stripos($of['availability'], 'InStock') !== false;
                }
            }
        }
    }
    return $out;
}

// ── 2. разметка внутри тегов ───────────────────────────────────────────
function iz_itemprop($html) {
    $out = array();
    if (preg_match('~<meta[^>]+itemprop=["\']price["\'][^>]+content=["\']([^"\']+)~i', $html, $m)
     || preg_match('~itemprop=["\']price["\'][^>]*content=["\']([^"\']+)~i', $html, $m)) {
        $out['p'] = chislo_iz_ceny($m[1]);
    }
    if (preg_match('~<(?:img|link|meta)[^>]+itemprop=["\']image["\'][^>]*?(?:src|href|content)=["\']([^"\']+)~i', $html, $m)
     || preg_match('~itemprop=["\']image["\'][^>]*?(?:src|href|content)=["\']([^"\']+)~i', $html, $m)) {
        $out['img'] = $m[1];
    }
    return $out;
}

// ── 3. og: то, что сайт отдаёт для пересылки в чатах ───────────────────
function iz_og($html) {
    $out = array();
    if (preg_match('~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) {
        $out['n'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('~<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) {
        $out['img'] = $m[1];
    }
    return $out;
}

// ── 4. заголовок и цена из описания ────────────────────────────────────
function iz_teksta($html) {
    $out = array();
    $h1 = tekst_h1($html);
    if ($h1 !== '') {
        $t = preg_replace('~<[^>]+>~', ' ', $h1);
        $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        $t = trim(preg_replace('~\s+~u', ' ', $t));
        if ($t !== '') { $out['n'] = $t; }
    }
    // «купить по цене 13230 руб» в описании страницы
    if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)~i', $html, $m)) {
        if (preg_match('~цене?\s*([\d\s\xC2\xA0]+)\s*руб~ui', $m[1], $c)) {
            $out['p'] = chislo_iz_ceny($c[1]);
        }
    }
    return $out;
}

// ── 5. последний рубеж: ищем прямо в тексте страницы ───────────────────
// Нужен на случай, если разметку по какой-то причине не удалось прочитать
// целиком. Диагноз с пределом отката проверить на живом хостинге я не мог,
// поэтому оставляю запасной путь: цена в разметке записана как
// "price": "7230", и найти её можно и без разбора всего объекта.
function iz_syrogo($html) {
    $out = array();
    if (preg_match('~"price"\s*:\s*"?([0-9]+(?:\.[0-9]+)?)~', $html, $m)) {
        $out['p'] = chislo_iz_ceny($m[1]);
    }
    if (preg_match('~"image"\s*:\s*"(https?://[^"]+)~', $html, $m)) {
        $out['img'] = $m[1];
    }
    return $out;
}

// ── название в нашем формате ───────────────────────────────────────────
// У каждого магазина свой порядок слов: «Шины Gislaved Nord Frost 200
// 185/55R15 86T XL», «Landsail Ice Star iS33 185/65 R15 88T», «Зимняя
// шина …». В каталоге у нас один вид:
//
//     265/60/18 Ikon Character Ice 8 SUV (Nordman 8 SUV) 114T XL шип.
//     размер  [C]  бренд и модель  индексы  [XL] [RunFlat] [шип.]
//
// Разбираем название на части, лишнее выкидываем, собираем заново.
// Что не узнали — оставляем как было: лучше чужой порядок слов,
// чем потерянное слово из названия модели.
//
// ПАТТЕРНЫ — помечены для проверка-formata.py, он берёт их отсюда
// и гоняет те же примеры, чтобы PHP и проверка не разъезжались.
function nf_shablony() {
    return array(
        // 185/65R15, 185/65 R15, 195/75 R16C, 265/60/18, 225/55 R19.5
        'razmer'  => '~(\d{3})\s*[/\\\\]\s*(\d{2})\s*[/\\\\ ]?\s*[RrРр]?\s*(\d{2}(?:[.,]5)?)\s*([CcСс])?~u',
        // 88T, 114T, 107/105R — индексы нагрузки и скорости
        'indeksy' => '~(?<![\d/.,])(\d{2,3}(?:\s*/\s*\d{2,3})?)\s*([A-Za-z])(?![A-Za-z0-9])~u',
        'xl'      => '~(?<![A-Za-zА-Яа-яЁё])XL(?![A-Za-z0-9])~u',
        'rf'      => '~(?<![A-Za-zА-Яа-яЁё])(?:run\s*flat|runflat|RFT?)(?![A-Za-z0-9])~ui',
        // Шипы. «spike» засчитываем только отдельным словом: в названии
        // модели SpikeControl это часть имени, а не признак шипов.
        // В адресах страниц шипы пишут латиницей: …-88t----shipovannaya/
        'shipy'   => '~шип|ошипован|шипован|studded|(?<![a-z])spike(?![a-z])|shipovan~ui',
        // то же, но для вырезания из названия
        'shipy_v' => '~\s*шипован\w*|\s*ошипован\w*|\s*шип\.?(?=\s)~ui',
        'shum'    => '~(?:зимн\w*|летн\w*|всесезонн\w*|шин[аыу]?|автошин\w*|покрышк\w*|комплект\s*\d*\s*шт\.?)~ui'
    );
}

function v_nash_format($name, $url) {
    $sh = nf_shablony();
    $t = ' ' . preg_replace('~\s+~u', ' ', trim((string) $name)) . ' ';
    $ishodnik = $t . ' ' . (string) $url;

    // размер
    $razmer = '';
    $legkogruz = '';
    if (preg_match($sh['razmer'], $t, $m, PREG_OFFSET_CAPTURE)) {
        $razmer = $m[1][0] . '/' . $m[2][0] . '/' . str_replace(',', '.', $m[3][0]);
        if (isset($m[4]) && $m[4][0] !== '') { $legkogruz = ' C'; }
        $t = substr($t, 0, $m[0][1]) . ' ' . substr($t, $m[0][1] + strlen($m[0][0]));
    }
    if ($razmer === '') { return trim((string) $name); } // не шина — не трогаем

    // индексы нагрузки и скорости
    $indeksy = '';
    if (preg_match($sh['indeksy'], $t, $m, PREG_OFFSET_CAPTURE)) {
        $indeksy = preg_replace('~\s+~u', '', $m[1][0]) . strtoupper($m[2][0]);
        $t = substr($t, 0, $m[0][1]) . ' ' . substr($t, $m[0][1] + strlen($m[0][0]));
    }

    $xl = preg_match($sh['xl'], $t) ? true : false;
    $t = preg_replace($sh['xl'], ' ', $t);
    $rf = preg_match($sh['rf'], $t) ? true : false;
    $t = preg_replace($sh['rf'], ' ', $t);
    $shipy = preg_match($sh['shipy'], $ishodnik) ? true : false;
    $t = preg_replace($sh['shipy_v'], ' ', $t);
    $t = preg_replace($sh['shum'], ' ', $t);

    $t = preg_replace('~[«»"]~u', '', $t);
    $t = trim(preg_replace('~\s+~u', ' ', $t), " ,.-\xe2\x80\x93\xe2\x80\x94");

    $kuski = array($razmer . $legkogruz, $t, $indeksy);
    if ($xl)    { $kuski[] = 'XL'; }
    if ($rf)    { $kuski[] = 'RunFlat'; }
    if ($shipy) { $kuski[] = 'шип.'; }

    $gotovo = array();
    foreach ($kuski as $k) { if ($k !== '') { $gotovo[] = $k; } }
    return implode(' ', $gotovo);
}

// ── собираем всё вместе ────────────────────────────────────────────────
function razobrat_tovar($html, $url) {
    $ld   = iz_json_ld($html);
    $ip   = iz_itemprop($html);
    $og   = iz_og($html);
    $txt  = iz_teksta($html);
    $syr  = iz_syrogo($html);

    $tovar = array('n' => '', 'p' => 0, 'img' => '', 'est' => null,
                   'zametka' => '', 'otkuda' => '');

    foreach (array($ld, $og, $txt, $ip) as $src) {
        if ($tovar['n'] === '' && !empty($src['n'])) { $tovar['n'] = $src['n']; }
    }
    foreach (array($ld, $ip, $og, $syr) as $src) {
        if ($tovar['img'] === '' && !empty($src['img'])) { $tovar['img'] = $src['img']; }
    }
    // Откуда взялась цена, запоминаем: при разборе полётов это первое,
    // что хочется знать.
    $istochniki_ceny = array('разметка' => $ld, 'теги' => $ip,
                             'описание' => $txt, 'текст страницы' => $syr);
    foreach ($istochniki_ceny as $imya => $src) {
        if ($tovar['p'] === 0 && !empty($src['p'])) {
            $tovar['p'] = (int) $src['p'];
            $tovar['otkuda'] = $imya;
        }
    }
    if (isset($ld['est'])) { $tovar['est'] = $ld['est']; }

    // Бывает, что на странице две разные цены: одна в разметке для
    // поисковиков, другая в самой карточке. Молчать об этом нельзя —
    // в предложение уйдёт не та.
    $ceny = array();
    foreach (array($ld, $ip, $txt) as $src) {
        if (!empty($src['p'])) { $ceny[] = (int) $src['p']; }
    }
    $ceny = array_values(array_unique($ceny));
    if (count($ceny) > 1) {
        $tovar['zametka'] = 'на странице нашлось несколько цен: ' . implode(' и ', $ceny)
                          . ' ₽ — проверьте, какая нужна';
    }

    $tovar['img'] = polnyy_adres($tovar['img'], $url);
    // Лишние хвосты вроде «купить в Москве» в названии не нужны
    $tovar['n'] = preg_replace('~\s*[-–—]\s*купить.*$~ui', '', $tovar['n']);
    $tovar['n'] = trim(preg_replace('~^Шины\s+~u', '', $tovar['n']));
    // и приводим к нашему виду: размер впереди, индексы и пометки в конце
    $tovar['n'] = v_nash_format($tovar['n'], $url);

    return $tovar;
}

// ── картинку забираем к себе ───────────────────────────────────────────
// Ссылка на чужой сайт в документе — плохая опора: картинка может
// пропасть, смениться или просто не напечататься. Поэтому копируем.
function zabrat_kartinku($url, $dir, $url_dir) {
    if ($url === '') { return ''; }
    $bin = postavshchik_fetch($url);
    if ($bin === null || strlen($bin) < 900) { return ''; }

    $vremenno = tempnam(sys_get_temp_dir(), 'kp');
    if (!$vremenno || !@file_put_contents($vremenno, $bin)) { return ''; }
    $info = @getimagesize($vremenno);
    $tipy = array(IMAGETYPE_JPEG => '.jpg', IMAGETYPE_PNG => '.png',
                  IMAGETYPE_WEBP => '.webp', IMAGETYPE_GIF => '.gif');
    if (!$info || !isset($tipy[$info[2]])) { @unlink($vremenno); return ''; }

    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $imya = novyy_id() . $tipy[$info[2]];
    if (!@rename($vremenno, $dir . '/' . $imya)) {
        @copy($vremenno, $dir . '/' . $imya);
        @unlink($vremenno);
    }
    if (!file_exists($dir . '/' . $imya)) { return ''; }
    @chmod($dir . '/' . $imya, 0644);
    return $url_dir . '/' . $imya;
}
