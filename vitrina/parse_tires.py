import pandas as pd,glob,re,json,sys
c=pd.read_excel(sys.argv[1])
s=c[(c['Общий остаток']>0)&(c['Цена продажи']>0)]
rx=re.compile(r'^\s*(\d{3})/(\d{2})/(\d{2})\s*([Cc])?\s+(.*)$')
BR={'кама':'КАМА','linglong':'LingLong','ikon':'Ikon','nortec':'NorTec','tracmax':'Tracmax'}
MULTI=['Hankook Laufen','Laufenn','Cordiant','Pirelli','Michelin','Continental']
out=[];bad=[]
for _,r in s.iterrows():
    n=str(r['Наименование']);m=rx.match(n)
    if not m: continue
    w,p,d,cflag,rest=m.groups()
    g=str(r['Группа']);cat=str(r['Категория'])
    low=rest.lower()
    stud=bool(re.search(r'шип',low))
    season='winter' if ('Зим' in g or 'Зим' in cat) else ('summer' if ('Лет' in g or 'Лет' in cat) else 'all')
    if re.search(r'all.?season|4 seasons|всесез',low): season='all'
    if stud: season='winter'
    xl=bool(re.search(r'\bXL\b',rest))
    idx=None
    mm=list(re.finditer(r'\b(\d{2,3}(?:/\d{2,3})?)([HJKLMNPQRSTUVWYZ])\b',rest))
    if mm: idx=mm[-1].group(1)+mm[-1].group(2)
    alias=None
    am=re.search(r'\(([^)]*)\)',rest)
    if am and not re.search(r'шип|\d{3}\s',am.group(1)): alias=am.group(1)
    model=rest
    if mm: model=model[:mm[-1].start()]+model[mm[-1].end():]
    model=re.sub(r'\([^)]*\)','',model)
    model=re.sub(r'\b(XL|шип\.?|TL|ставленные|M\+S|3PMSF|\d+PR)\b','',model,flags=re.I)
    model=re.sub(r'шип\.?','',model,flags=re.I)
    model=re.sub(r'\s+',' ',model).strip(' .,-')
    parts=model.split(' ',1)
    brand=BR.get(parts[0].lower(),parts[0]); mdl=parts[1] if len(parts)>1 else ''
    if brand=='Hankook' and mdl.lower().startswith('laufen'): brand='Laufenn';mdl=mdl.split(' ',1)[1] if ' ' in mdl else mdl
    lt=bool(cflag) or 'Легкогруз' in cat
    suv='SUV' in rest or 'AT/MT' in g or 'at и mt' in cat
    out.append(dict(id=str(r['ID']),w=int(w),p=int(p),d=int(d),c=lt,b=brand,m=mdl,a=alias,s=season,st=stud,xl=xl,i=idx,pr=int(r['Цена продажи']),q=int(r['Общий остаток']),suv=suv,cy=None if pd.isna(r['Страна']) else str(r['Страна'])))
print(len(out),file=sys.stderr)
import collections
print(collections.Counter(o['s'] for o in out),file=sys.stderr)
print(collections.Counter(o['b'] for o in out).most_common(40),file=sys.stderr)
print(len({(o['b'],o['m']) for o in out}),file=sys.stderr)
json.dump(out,open('tires.json','w'),ensure_ascii=False,separators=(',',':'))
