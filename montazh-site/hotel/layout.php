<?php
/* Общая обёртка страницы и экран входа. */

function head(string $title, string $active = '', bool $wide = false): void { ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#16160F">
<title><?= e($title) ?> · Склад IVANOR</title>
<link rel="apple-touch-icon" href="icon.png">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='3' fill='%2316160F'/%3E%3Ccircle cx='16' cy='16' r='9' fill='none' stroke='%23D8410C' stroke-width='3.4'/%3E%3Ccircle cx='16' cy='16' r='3.2' fill='%23F2EFE8'/%3E%3C/svg%3E">
<style>
:root{--paper:#F2EFE8;--paper2:#EAE6DB;--paper3:#E1DCCE;--ink:#16160F;--ink2:#3A382E;
      --ink3:#6E6B5D;--rule:rgba(22,22,15,.16);--rule2:rgba(22,22,15,.07);
      --sig:#D8410C;--sig-t:rgba(216,65,12,.10);--cold:#26586F;--cold-t:rgba(38,88,111,.10);
      --ok:#4B6A38;--ok-t:rgba(75,106,56,.12);--warn:#B8860B}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--paper);color:var(--ink);
     font:16px/1.5 'Golos Text',system-ui,-apple-system,'Segoe UI',sans-serif;
     padding-bottom:calc(64px + env(safe-area-inset-bottom,0px))}
.mono{font-family:ui-monospace,'SF Mono',Menlo,Consolas,monospace}
a{color:inherit}
::selection{background:var(--sig);color:#fff}
:focus-visible{outline:2px solid var(--sig);outline-offset:2px}

/* шапка */
.top{position:sticky;top:0;z-index:20;background:var(--ink);color:var(--paper);
     padding:11px 14px;display:flex;align-items:center;gap:12px}
.top b{font-size:1rem;letter-spacing:-.02em;white-space:nowrap}
.top .sp{margin-left:auto}
.top a{color:rgba(242,239,232,.75);text-decoration:none;font-size:.85rem;white-space:nowrap}
@media(max-width:400px){.top b{font-size:.92rem}.top{gap:9px}.top a{font-size:.8rem}}
.top a:hover{color:var(--paper)}

/* нижняя навигация */
nav.tabs{position:fixed;left:0;right:0;bottom:0;z-index:30;display:grid;
  grid-template-columns:repeat(5,1fr);background:var(--paper);
  border-top:1.5px solid var(--ink);padding-bottom:env(safe-area-inset-bottom,0px)}
nav.tabs a{display:flex;flex-direction:column;align-items:center;gap:3px;padding:9px 2px 8px;
  text-decoration:none;color:var(--ink3);font-size:.66rem;letter-spacing:.02em}
nav.tabs a svg{width:21px;height:21px}
nav.tabs a.on{color:var(--sig);font-weight:600}
nav.tabs a.add{color:var(--paper)}
nav.tabs a.add span.b{position:absolute;inset:6px 8px;background:var(--sig);border-radius:4px;z-index:-1}
nav.tabs a.add{position:relative;z-index:0;color:#fff}
/* на широком экране — компактная панель по центру, а не полоса во всю ширину */
@media(min-width:900px){nav.tabs{left:50%;right:auto;bottom:14px;transform:translateX(-50%);display:flex;gap:2px;padding:4px;border:1px solid var(--rule);border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.12)}
  nav.tabs a{flex-direction:row;gap:7px;padding:9px 16px;font-size:.84rem;border-radius:10px}nav.tabs a svg{width:18px;height:18px}nav.tabs a.add span.b{inset:0;border-radius:10px}}

.wrap{max-width:920px;margin:0 auto;padding:14px}
.wrap.wide{max-width:1500px}
h1{font-size:1.35rem;letter-spacing:-.03em;margin:.1em 0 .5em}
h2{font-size:1.05rem;letter-spacing:-.02em;margin:1.4em 0 .6em}
.muted{color:var(--ink3)}
.small{font-size:.86rem}
.mn{font-family:ui-monospace,monospace;font-size:.68rem;letter-spacing:.13em;
    text-transform:uppercase;color:var(--ink3)}

/* плитки */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1.5px;background:var(--rule);
       border:1.5px solid var(--rule);margin-bottom:14px}
@media(max-width:560px){.stats{grid-template-columns:repeat(2,1fr)}}
.stat{background:var(--paper);padding:12px 13px;text-decoration:none;display:block}
.stat b{display:block;font-size:1.5rem;letter-spacing:-.04em;line-height:1.1}
.stat span{font-size:.76rem;color:var(--ink3);line-height:1.3;display:block;margin-top:2px}
.stat.sig b{color:var(--sig)}
.stat.warn b{color:var(--warn)}

/* поиск */
.search{position:relative;margin-bottom:12px}
.search input{width:100%;font:inherit;font-size:16px;padding:.85em 2.4em .85em .9em;
  border:1.5px solid var(--ink);border-radius:3px;background:#fff}
.search .x{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;
  background:none;font-size:1.3rem;color:var(--ink3);cursor:pointer;padding:.2em .4em;line-height:1}

/* фильтры */
.chips{display:flex;gap:7px;overflow-x:auto;padding-bottom:4px;margin-bottom:12px;
       scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex:none;font:inherit;font-size:.83rem;padding:.45em .85em;border:1.4px solid var(--rule);
  background:var(--paper);border-radius:100px;cursor:pointer;white-space:nowrap;color:var(--ink2)}
.chip.on{background:var(--ink);border-color:var(--ink);color:var(--paper);font-weight:600}

/* карточки списка */
.list{display:flex;flex-direction:column;gap:8px}
.row{display:block;background:var(--paper2);border:1.5px solid var(--rule);border-radius:3px;
  padding:11px 13px;text-decoration:none;transition:border-color .15s}
.row:active{border-color:var(--ink)}
.row .l1{display:flex;align-items:baseline;gap:10px}
.row .nm{font-weight:600;font-size:1.02rem;letter-spacing:-.01em;flex:1;min-width:0;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row .l2{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;align-items:center}
.tag{font-family:ui-monospace,monospace;font-size:.66rem;letter-spacing:.06em;
  padding:.28em .5em;border-radius:2px;border:1.2px solid currentColor;white-space:nowrap}
.tag.a{color:var(--sig);background:var(--sig-t)}
.tag.t{color:var(--cold);background:var(--cold-t)}
.tag.p{color:var(--ok);background:var(--ok-t)}
.tag.c{color:var(--ink2);background:var(--paper3);border-color:var(--rule)}
.tag.due{color:#9a3412;background:rgba(154,52,18,.1)}
.tag.debt{color:#8a1c1c;background:rgba(138,28,28,.1)}
.tag.out{color:var(--ink3);background:var(--paper3);border-color:var(--rule)}
.row .sz{font-family:ui-monospace,monospace;font-size:.85rem;color:var(--ink2)}

/* формы */
.card{background:var(--paper2);border:1.5px solid var(--rule);border-radius:3px;
      padding:15px;margin-bottom:12px}
.card h3{margin:0 0 12px;font-size:1rem;letter-spacing:-.02em;
  padding-bottom:10px;border-bottom:1.5px solid var(--rule)}
.f{margin-bottom:12px}
.f label{display:block;font-family:ui-monospace,monospace;font-size:.64rem;letter-spacing:.12em;
  text-transform:uppercase;color:var(--ink3);margin-bottom:5px}
.f input,.f select,.f textarea{width:100%;font:inherit;font-size:16px;padding:.7em .75em;
  border:1.4px solid var(--rule);border-radius:3px;background:#fff;color:var(--ink)}
.f input:focus,.f select:focus,.f textarea:focus{outline:none;border-color:var(--sig)}
.f textarea{min-height:70px;resize:vertical}
.f2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
@media(max-width:460px){.f2{grid-template-columns:1fr}}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:.5em;font:inherit;
  font-weight:600;padding:.85em 1.2em;border:1.5px solid var(--ink);border-radius:3px;
  background:var(--ink);color:var(--paper);cursor:pointer;text-decoration:none;width:100%}
.btn:active{transform:translateY(1px)}
.btn.g{background:transparent;color:var(--ink)}
.btn.s{background:var(--sig);border-color:var(--sig);color:#fff}
.btn.d{background:transparent;border-color:#8a1c1c;color:#8a1c1c}
.btn.sm{width:auto;padding:.55em .9em;font-size:.88rem}
.btns{display:flex;gap:8px;flex-wrap:wrap}
.btns .btn{flex:1;min-width:130px}

dl.kv{display:grid;grid-template-columns:auto 1fr;gap:7px 14px;margin:0;font-size:.95rem}
dl.kv dt{color:var(--ink3);font-size:.88rem}
dl.kv dd{margin:0}

.empty{text-align:center;color:var(--ink3);padding:44px 16px}
.flash{background:var(--ok-t);border:1.5px solid var(--ok);color:#33501f;
  border-radius:3px;padding:10px 13px;margin-bottom:12px;font-size:.93rem}

/* вход */
.login{max-width:330px;margin:70px auto;padding:0 18px}
.login input{width:100%;font:inherit;font-size:16px;padding:.85em;border:1.5px solid var(--ink);
  border-radius:3px;margin-bottom:10px;background:#fff}
.err{color:var(--sig);font-size:.9rem;margin-bottom:10px}
/* ═══ внутри пульта IVANOR: те же шрифты, белые карточки, скругления; фон раздела — тёплый песок ═══ */
:root{--paper:#F6F1E9;--paper2:#FFFFFF;--paper3:#F1ECE3;--ink:#1B1D21;--ink2:#4A4F57;--ink3:#6B7079;
  --rule:#E6DFD3;--rule2:#EFE9DF;--sig:#FF6600;--sig-t:#FFF1E8;--cold:#2563C9;--cold-t:#E5EEFC;--ok:#1E7A44;--ok-t:#E8F5EC;--warn:#8A4B00;--acc:#B0661A}
body{font-family:'Golos Text',system-ui,sans-serif;background:var(--paper)}
h1,h2,.stat b{font-family:'Montserrat','Golos Text',sans-serif;font-weight:700;letter-spacing:-.01em}
.mn,.f label{font-family:'Golos Text',sans-serif;letter-spacing:0;text-transform:none;font-size:.78rem;font-weight:500}
.top{background:#fff;color:var(--ink);border-bottom:1px solid var(--rule);padding:8px 14px}
.top b{font-family:'Montserrat',sans-serif;color:var(--acc)}
.top a{color:var(--ink2);font-weight:600;padding:6px 10px;border-radius:9px;background:var(--paper3)}
.top a:hover{color:var(--ink)}
.stats{gap:10px;background:none;border:0}
.stat{background:#fff;border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.06)}
.search input,.f input,.f select,.f textarea{border:1px solid var(--rule);border-radius:10px}
.search input:focus{outline:none;border-color:var(--sig)}
.chip{border:1px solid var(--rule);background:#fff;font-weight:600}
.chip.on{background:var(--ink);border-color:var(--ink)}
.row,.card{background:#fff;border:0;border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.06)}
.card h3{font-family:'Montserrat',sans-serif;border-bottom:1px solid var(--rule)}
.tag{font-family:'Golos Text',sans-serif;letter-spacing:0;border:0;border-radius:6px;font-weight:600;font-size:.72rem}
.btn{border-radius:10px;border-width:1px}
.btn.g{border-color:var(--rule)}
nav.tabs{background:#fff;border-top:1px solid var(--rule)}
nav.tabs a.on{color:var(--acc)}
nav.tabs a.add span.b{border-radius:12px}
table{border-collapse:separate;border-spacing:0}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Golos+Text:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<header class="top">
  <b>Шинный отель</b>
  <span class="sp"></span>
  <a href="client.php">Клиенты</a>
  <a href="export.php">Выгрузка</a>
</header>
<main class="wrap<?= $wide ? ' wide' : '' ?>">
<?php }

function foot(string $active = ''): void {
    $tabs = [
        ['index.php',  'Хранение', 'list',  '<path d="M4 7h16M4 12h16M4 17h10"/>'],
        ['map.php',    'Склад',    'map',   '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M8 4v16M13 4v16M18 4v16"/>'],
        ['item.php?new=1','Принять','add',  '<path d="M12 5v14M5 12h14"/>'],
        ['archive.php','Архив',    'arch',  '<path d="M3 7h18v3H3zM5 10v9h14v-9M10 14h4"/>'],
        ['money.php',  'Деньги',   'money', '<circle cx="12" cy="12" r="8"/><path d="M12 8v8M9.5 10.5h5M9.5 13.5h5"/>'],
    ];
    echo '</main><nav class="tabs">';
    foreach ($tabs as [$href, $label, $key, $path]) {
        $cls = $key === 'add' ? 'add' : ($active === $key ? 'on' : '');
        echo '<a class="' . $cls . '" href="' . $href . '">'
           . ($key === 'add' ? '<span class="b"></span>' : '')
           . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
           . 'stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>'
           . '<span>' . $label . '</span></a>';
    }
    echo '</nav></body></html>';
}

function loginPage(): void { ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Вход · Склад IVANOR</title>
<style>body{margin:0;background:#F2EFE8;color:#16160F;font:16px/1.5 system-ui,sans-serif}
.login{max-width:360px;margin:80px auto;padding:0 18px}a.b{display:block;text-align:center;font-weight:600;padding:.9em;border-radius:3px;background:#16160F;color:#F2EFE8;text-decoration:none}</style></head><body>
<div class="login"><p style="color:#6E6B5D;font-size:.8rem;letter-spacing:.1em;text-transform:uppercase">Шинный отель IVANOR</p>
<h1 style="font-size:1.4rem">Войдите в пульт</h1><p>Хранение теперь открывается из пульта, по тому же PIN.</p>
<a class="b" href="../" target="_top">Открыть пульт</a></div></body></html>
<?php }
