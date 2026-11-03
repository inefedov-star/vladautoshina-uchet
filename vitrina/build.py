"""Сборка витрины из выгрузки каталога CloudShop.

Запуск:  python vitrina/build.py путь/к/catalog.xlsx 31.10.2026
Результат: vitrina/dist/vitrina.html — для просмотра в Claude,
           vitrina/dist/site/index.html — для сайта (папка vitrina на хостинге).
Себестоимость и закупка в файлы не попадают.
"""
import json, subprocess, sys, pathlib
here = pathlib.Path(__file__).parent
src, stamp = sys.argv[1], sys.argv[2]
for script in ("parse_tires.py", "parse_other.py"):
    subprocess.run([sys.executable, script, src], cwd=here, check=True)
tires = json.loads((here / "tires.json").read_text())
other = json.loads((here / "other.json").read_text())
for o in tires:
    if o["b"] == "GENERAL": o["b"] = "General"
    if o["b"] == "Кама-365": o["b"], o["m"] = "КАМА", "365 " + o["m"]
html = (here / "template.html").read_text()
dump = lambda x: json.dumps(x, ensure_ascii=False, separators=(",", ":"))
html = html.replace("__TIRES__", dump(tires)).replace("__OTHER__", dump(other)).replace("__STAMP__", stamp)
(here / "dist").mkdir(exist_ok=True)
(here / "dist" / "vitrina.html").write_text(html)

# версия для сайта: полноценная страница, поисковикам закрыта
site = html.replace('<p class="note">Прототип. Наличие и цены — из выгрузки каталога CloudShop, фото пока заменены схемами.</p>',
                    '<p class="note">Цены и наличие на дату выше. Фото моделей появятся позже — пока схемы.</p>')
site = site.replace('<meta name="claude:viewport-fit-sides" content="cover">\n', '')
head = """<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#FFFFFF">
<meta name="mobile-web-app-capable" content="yes">
"""
title_end = site.index("</style>") + len("</style>")
site = head + site[:title_end].replace("*,*::before,*::after{box-sizing:border-box}", "*,*::before,*::after{box-sizing:border-box}\nhtml,body{margin:0}\n[hidden]{display:none!important}", 1) + "\n</head>\n<body>\n" + site[title_end:] + "\n</body>\n</html>\n"
(here / "dist" / "site").mkdir(exist_ok=True)
(here / "dist" / "site" / "index.html").write_text(site)
(here / "dist" / "site" / "sw.js").write_text((here / "sw.js").read_text())
(here / "dist" / "site" / ".htaccess").write_text('<IfModule mod_headers.c>\n  Header set X-Robots-Tag "noindex, nofollow"\n</IfModule>\nDirectoryIndex index.html\n')
print("ok:", len(tires), "шин,", len(other), "прочих позиций")
