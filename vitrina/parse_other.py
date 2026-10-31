import pandas as pd,glob,re,json,collections,sys
c=pd.read_excel(sys.argv[1])
s=c[(c['Общий остаток']>0)&(c['Цена продажи']>0)]
tires=json.load(open('tires.json')); tid={t['id'] for t in tires}
items=[];
DR=re.compile(r'^\s*(\d{2})\s+(\d+(?:[.,]\d+)?)\s*[/ ]\s*(\d)\s*[*хx×]\s*(\d+(?:[.,]\d*)?(?:\s*/\s*(?:\d\s*[*хx×]\s*)?\d+(?:[.,]\d+)?)?)\s*ET\s*(-?\d+(?:[.,]\d+)?)\s*(?:dia|d)\s*([\d.,?]+)\s*(.*)$',re.I)
unp=collections.Counter()
for _,r in s.iterrows():
    i=str(r['ID']);
    if i in tid: continue
    n=re.sub(r'\s+',' ',str(r['Наименование'])).strip();cat=str(r['Категория']);g=str(r['Группа'])
    o=dict(id=i,n=n,pr=int(r['Цена продажи']),q=int(r['Общий остаток']))
    if 'колпак' in n.lower(): o['t']='acc'; o['g']='caps'
    elif 'клемм' in n.lower() or 'тестер акб' in n.lower(): o['t']='acc'; o['g']='akbacc'
    elif 'Диски' in cat:
        m=DR.match(n)
        kind={'Диски литые':'cast','Диски штампованые':'steel','Диски некомплект':'odd','Диски в окнах':'cast'}.get(cat,'cast')
        o.update(t='disk',k=kind)
        if m:
            d,w,h,pcd,et,dia,rest=m.groups()
            pcd=re.sub(r'\s','',pcd).replace(',','.').rstrip('.');pcd=re.sub(r'[*хx]','×',pcd)
            parts=pcd.split('/');parts=[x if '×' in x else f'{h}×{x}' for x in parts]
            parts[0]=f'{h}×{parts[0]}' if not parts[0].startswith(h+'×') else parts[0]
            o['pcds']=parts
            o.update(d=int(d),w=w.replace(',','.'),pcd=f'{h}×{pcd}',et=et.replace(',','.'),dia=dia.replace(',','.').strip('.'),m=rest.strip())
        else: unp['disk']+=1; o.update(d=None,m=n)
    elif 'зарядное' in n.lower(): o['t']='acc'; o['g']='akbacc'
    elif cat=='Аккумуляторы' and 'электроинструмент' not in n.lower():
        o['t']='akb'
        m=re.search(r'(\d{1,3})\s*а/?ч',n,re.I); o['ah']=int(m.group(1)) if m else None
        low=n.lower()
        o['pol']='rev' if re.search(r'о/п|обр',low) else ('dir' if re.search(r'п/п|прям',low) else None)
        o['std']='moto' if 'moto' in low else 'truck' if (o.get('ah') or 0)>=140 else 'asia' if re.search(r'asia|jis|\b\d{2,3}d\d{2}|b19|d2[36]|d31',low) else 'euro'
        m=re.search(r'(?:EN|en|CCA)\s*(\d{3,4})|(\d{3,4})\s*[AАА]\b',n); o['en']=int(m.group(1) or m.group(2)) if m else None
        o['m']=re.sub(r'^Аккумулятор\s+','',n)
        if not o['ah']: unp['akb']+=1
    elif cat=='Крепеж' or 'Крепеж' in g:
        o['t']='fast'; low=n.lower()
        m=re.search(r'(?<!\d)М?(1[024])\s*[*х×x]\s*(1[,.]25|1[,.]5)',n)
        o['th']=(m.group(1)+'×'+m.group(2).replace('.',',')) if m else None
        mr=re.search(r'Кольцо центровочное\s*([\d.]+)\s*-\s*([\d.]+)',n)
        if mr: o['ro'],o['ri']=mr.group(1),mr.group(2)
        o['seat']='сфера' if 'сфер' in low else 'плоская' if 'плоск' in low else 'конус' if 'конус' in low else None
        o['k']=('lock' if 'секрет' in low else 'ring' if 'кольц' in low else 'nut' if 'гайк' in low else 'bolt' if 'болт' in low else 'stud' if 'шпильк' in low else 'other')
    elif 'шин' in cat.lower() or cat in('Грузовые шины','С/Х Шины'):
        o['t']='tireo'
    elif cat=='Камеры': o['t']='tube'
    elif cat=='электроинструмент' or 'электроинструмент' in n.lower(): o['t']='tool'
    else:
        o['t']='acc'; o['g']='chem' if cat=='Автохимия' else 'acc'
    items.append(o)
print(collections.Counter(x['t'] for x in items),unp,file=sys.stderr)
print(collections.Counter(x.get('th') for x in items if x['t']=='fast'),file=sys.stderr)
print(collections.Counter(x.get('k') for x in items if x['t']=='fast'),file=sys.stderr)
print(collections.Counter((x.get('pol'),x.get('std')) for x in items if x['t']=='akb'),file=sys.stderr)
print([x['n'] for x in items if x['t']=='akb' and not x.get('ah')],file=sys.stderr)
print([x['n'] for x in items if x['t']=='disk' and not x.get('d')],file=sys.stderr)
print(collections.Counter(x.get('pcd') for x in items if x['t']=='disk').most_common(15),file=sys.stderr)
json.dump(items,open('other.json','w'),ensure_ascii=False,separators=(',',':'))
