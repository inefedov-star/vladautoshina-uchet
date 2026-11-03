<?php
// ═══════════════════════════════════════════════════════════════════════
//   zakladka.php — страница с закладкой для браузера
//
//   Закладка нужна для магазинов, которые отвечают только настоящему
//   браузеру: 4tochki и koleso с сервера отдают пустую страницу.
//   У вас в браузере она уже открыта и разобрана — закладка просто
//   читает с неё разметку и отправляет в буфер.
//
//   Тот же код, что в postavshchik.php, только выполняется не на сервере,
//   а в вашей вкладке. Порядок поиска одинаковый: JSON-LD, разметка
//   в тегах, og:, заголовок страницы.
// ═══════════════════════════════════════════════════════════════════════

require_once dirname(__FILE__) . '/obshee.php';
trebuem_vhod();

// Адрес приёмника подставляем тот, с которого открыли эту страницу,
// иначе закладка с рабочего компьютера будет слать данные не туда.
$shema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'vladautoshina.ru';
$put = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$PRIEMNIK = $shema . '://' . $host . $put . '/dobavit.php';

// Код закладки. Пишется в одну строку — так его хранит браузер.
$kod = <<<'JS'
javascript:(function(){
var P='__PRIEMNIK__';
function cena(v){v=String(v).replace(/[\s ]/g,'').replace(',','.');var m=v.match(/(\d+(?:\.\d+)?)/);return m?Math.round(parseFloat(m[1])):0;}
var n='',p=0,img='';
var ld=document.querySelectorAll('script[type="application/ld+json"]');
for(var i=0;i<ld.length&&!n;i++){
  var d;try{d=JSON.parse(ld[i].textContent);}catch(e){continue;}
  var k=[d];if(d&&d['@graph'])k=k.concat(d['@graph']);if(d&&d.length)k=k.concat(d);
  for(var j=0;j<k.length;j++){var o=k[j];if(!o||!o['@type'])continue;
    var t=[].concat(o['@type']).join(' ');if(t.toLowerCase().indexOf('product')<0)continue;
    if(o.name&&!n)n=String(o.name).trim();
    if(o.image&&!img){var im=o.image;if(im.length&&typeof im!=='string')im=im[0];if(im&&im.url)im=im.url;if(typeof im==='string')img=im;}
    var of=o.offers;if(of&&!p){if(of.length)of=of[0];if(of.price!=null)p=cena(of.price);else if(of.lowPrice!=null)p=cena(of.lowPrice);}
  }
}
function meta(s,a){var e=document.querySelector(s);return e?(e.getAttribute(a||'content')||''):'';}
if(!p)p=cena(meta('[itemprop="price"]','content')||meta('[itemprop="price"]',null)||'');
if(!img)img=meta('[itemprop="image"]','src')||meta('[itemprop="image"]','content')||meta('[itemprop="image"]','href');
if(!n)n=meta('meta[property="og:title"]');
if(!img)img=meta('meta[property="og:image"]');
if(!n){var h=document.querySelector('h1');n=h?h.textContent.replace(/\s+/g,' ').trim():document.title;}
if(img&&img.indexOf('//')===0)img='https:'+img;
if(img&&img.indexOf('http')!==0)img=location.origin+(img.charAt(0)==='/'?'':'/')+img;
n=n.replace(/\s*[-–—]\s*купить.*$/i,'').replace(/^Шины\s+/,'').trim();
n=nashFormat(n,location.href);
function nashFormat(name,url){
  var t=' '+String(name).replace(/\s+/g,' ').trim()+' ',ish=t+' '+url,m,razmer='',lg='',ind='';
  m=t.match(/(\d{3})\s*[/\\]\s*(\d{2})\s*[/\\ ]?\s*[RrРр]?\s*(\d{2}(?:[.,]5)?)\s*([CcСс])?/);
  if(!m)return String(name).trim();
  razmer=m[1]+'/'+m[2]+'/'+m[3].replace(',','.');if(m[4])lg=' C';
  t=t.slice(0,m.index)+' '+t.slice(m.index+m[0].length);
  m=t.match(/(?:^|[^\d/.,])(\d{2,3}(?:\s*\/\s*\d{2,3})?)\s*([A-Za-z])(?![A-Za-z0-9])/);
  if(m){ind=m[1].replace(/\s+/g,'')+m[2].toUpperCase();
    var i=t.indexOf(m[1],m.index);t=t.slice(0,i)+' '+t.slice(m.index+m[0].length);}
  var rxXl=/(^|[^A-Za-zА-Яа-яЁё])XL(?![A-Za-z0-9])/g,
      rxRf=/(^|[^A-Za-zА-Яа-яЁё])(?:run\s*flat|runflat|RFT?)(?![A-Za-z0-9])/gi;
  var xl=rxXl.test(t);t=t.replace(rxXl,'$1 ');
  var rf=rxRf.test(t);t=t.replace(rxRf,'$1 ');
  var shipy=/шип|ошипован|шипован|studded|(^|[^a-z])spike(?![a-z])|shipovan/i.test(ish);
  t=t.replace(/\s*шипован\S*|\s*ошипован\S*|\s*шип\.?(?=\s)/gi,' ')
     .replace(/(?:зимн\S*|летн\S*|всесезонн\S*|шин[аыу]?|автошин\S*|покрышк\S*|комплект\s*\d*\s*шт\.?)/gi,' ')
     .replace(/[«»"]/g,'').replace(/\s+/g,' ').replace(/^[\s,.\-–—]+|[\s,.\-–—]+$/g,'');
  var k=[razmer+lg,t,ind];if(xl)k.push('XL');if(rf)k.push('RunFlat');if(shipy)k.push('шип.');
  return k.filter(function(x){return x!=='';}).join(' ');
}
window.open(P+'?n='+encodeURIComponent(n)+'&p='+p+'&img='+encodeURIComponent(img)+'&src='+encodeURIComponent(location.href),'_blank','width=460,height=420');
})();
JS;
$kod = str_replace('__PRIEMNIK__', $PRIEMNIK, $kod);
$kod_odnoy_strokoy = preg_replace('~\n\s*~', '', $kod);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Закладка «В предложение» — IVANOR</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Sans+Extra+Condensed:wght@700;800&family=Golos+Text:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="redaktor.css">
<style>
  .txt{max-width:720px;padding:26px 24px 60px;font-size:15.5px;line-height:1.62}
  .txt h1{font-family:var(--f-display);font-weight:800;font-size:30px;
    letter-spacing:-.02em;margin:0 0 6px}
  .txt h2{font-family:var(--f-display);font-weight:800;font-size:20px;margin:30px 0 10px}
  .txt p{margin:0 0 12px;color:var(--ink-2)}
  .txt ol{margin:0 0 14px;padding-left:22px;color:var(--ink-2)}
  .txt li{margin-bottom:7px}
  .tyanut{display:inline-block;background:var(--signal);color:#fff;text-decoration:none;
    font-weight:700;font-size:16px;padding:13px 22px;border-radius:2px;
    box-shadow:4px 4px 0 rgba(22,22,15,.18);cursor:grab;margin:6px 0 4px}
  .tyanut:active{cursor:grabbing}
  .ramka{border:1.5px solid var(--rule);border-radius:2px;padding:16px 18px;
    background:var(--paper-2);margin:16px 0}
  .kod{font-family:var(--f-mono);font-size:11px;word-break:break-all;
    background:#fff;border:1.5px solid var(--rule);border-radius:2px;
    padding:11px 13px;max-height:120px;overflow:auto;color:var(--ink-2)}
  .spis{font-size:14.5px}
  .spis b{font-family:var(--f-mono);font-size:13px}
</style>
</head>
<body>

<header class="top">
  <a class="logo" href="index.php">
    <svg viewBox="0 0 32 32" aria-hidden="true">
      <rect width="32" height="32" rx="2" fill="#16160F"/>
      <circle cx="16" cy="16" r="9.5" fill="none" stroke="#D8410C" stroke-width="3"/>
      <circle cx="16" cy="16" r="3.4" fill="#F2EFE8"/></svg>
    <span>Предложения<i>IVANOR</i></span>
  </a>
  <a href="index.php" class="tihaya">К предложениям</a>
</header>

<div class="txt">
  <h1>Закладка «В предложение»</h1>
  <p>Для магазинов, которые не отвечают нашему серверу, — сейчас это
     koleso.ru с его защитой. Открыли карточку товара — нажали закладку —
     позиция в буфере. Остальные три сайта берутся обычной ссылкой.</p>

  <div class="ramka">
    <p style="margin-bottom:10px"><b>Перетащите эту кнопку на панель закладок браузера:</b></p>
    <a class="tyanut" href="<?= h($kod_odnoy_strokoy) ?>">В предложение IVANOR</a>
    <p style="margin:10px 0 0;font-size:13.5px">
      Если панель закладок скрыта — включите её: <b>Ctrl+Shift+B</b>
      (на Маке <b>⌘+Shift+B</b>).
    </p>
  </div>

  <h2>Как пользоваться</h2>
  <ol>
    <li>Открываете страницу нужной шины у поставщика — именно карточку
        конкретного размера, а не список.</li>
    <li>Нажимаете закладку на панели. Откроется окошко с подтверждением.</li>
    <li>Возвращаетесь в редактор и нажимаете «Вставить из буфера».</li>
  </ol>
  <p>Можно набрать несколько позиций подряд, а потом вставить все разом.</p>

  <h2>Какие сайты как работают</h2>
  <table class="redt spis">
    <thead><tr><th>Магазин</th><th>Ссылкой</th><th>Закладкой</th></tr></thead>
    <tbody>
      <tr><td><b>shinservice.ru</b></td><td>да</td><td>да</td></tr>
      <tr><td><b>mosautoshina.ru</b></td><td>да</td><td>да</td></tr>
      <tr><td><b>4tochki.ru</b></td><td>да</td><td>да</td></tr>
      <tr><td><b>koleso.ru</b></td><td>нет, стоит защита</td><td>да</td></tr>
      <tr><td>любой другой магазин</td><td>если пустит</td><td>если размечен</td></tr>
    </tbody>
  </table>
  <p>Закладка работает и на сайтах, которых тут нет: она читает стандартную
     разметку товара, а её ставят почти все — без неё магазин не попадёт
     в Яндекс и Google.</p>

  <h2>Если перетащить не получается</h2>
  <p>Создайте закладку вручную: правой кнопкой по панели → «Добавить страницу»,
     название любое, а в поле адреса вставьте это:</p>
  <div class="kod"><?= h($kod_odnoy_strokoy) ?></div>

  <h2>Что именно забирается</h2>
  <p>Название, цена и снимок. Снимок сразу копируется к нам на сервер:
     ссылка на чужой сайт в документе ненадёжна — картинка может пропасть,
     смениться или не напечататься.</p>
  <p>Цена приходит такой, как на сайте поставщика. Это чужая розница,
     поэтому в редакторе строка подсвечивается — чтобы не забыть поставить свою.</p>
</div>

</body>
</html>
