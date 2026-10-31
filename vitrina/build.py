"""Сборка витрины из выгрузки каталога CloudShop.

Запуск:  python vitrina/build.py путь/к/catalog.xlsx 31.10.2026
Результат: vitrina/dist/vitrina.html (себестоимость и закупка в файл не попадают).
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
print("ok:", len(tires), "шин,", len(other), "прочих позиций")
