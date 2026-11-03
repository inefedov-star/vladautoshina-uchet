/* ═══════════════════════════════════════════════════════════════════
   redaktor.js — сборка коммерческого предложения

   Деньги считаются и здесь, и на сервере одной и той же формулой.
   На бумагу идёт серверный расчёт: правка цены в браузере на итог
   в готовом КП не влияет.
   ═══════════════════════════════════════════════════════════════════ */

/* ── деньги ───────────────────────────────────────────────────────── */
/* Округление «от нуля», как в PHP: 11562,5 → 11563. Math.round в JS
   округляет к плюс-бесконечности, и на отрицательных числах расходится
   с сервером — тут это важно, потому что печатает документ сервер. */
function okruglit(v){
  return v < 0 ? -Math.round(-v) : Math.round(v);
}

/* Цена за единицу, округлённая до рубля. Скидок в коммерческом
   предложении нет: особые условия пишутся словами в комментарии. */
function cenaZaEdinicu(r){
  return okruglit(Number(r.p) || 0);
}

function strokaSumma(r){
  var q = Number(r.q) || 0;
  return okruglit(cenaZaEdinicu(r) * q);
}

function itogiPoGruppam(kp){
  var out = {items:0, services:0};
  ['items','services'].forEach(function(g){
    (kp[g] || []).forEach(function(r){ out[g] += strokaSumma(r); });
  });
  out.items = okruglit(out.items);
  out.services = okruglit(out.services);
  out.vsego = out.items + out.services;
  return out;
}

var dengi = function(v){
  return okruglit(v).toLocaleString('ru-RU', {maximumFractionDigits: 0});
};

/* ── во сколько листов уложится ────────────────────────────────────
   Документ сам подбирает плотность: от крупных снимков к прайс-листу.
   Пределы прогнаны в браузере на живой вёрстке — 1–4 позиции идут
   крупно, 5–7 обычно, 8–9 плотно, 10–12 мелкими снимками, дальше
   снимки убираются. На втором листе обвязки нет, туда входит больше. */
var PREDEL = {
  pervyyFoto: 12,       // со снимками, самая тесная ступень
  pervyyBez: 16,        // без снимков
  pervyyPrays: 16,      // дальше снимки убираются в любом случае
  vtoroyFoto: 20,
  vtoroyBez: 25
};

function listov(strok, estFoto){
  if(strok <= 0) return 0;
  var naPervom, naVtorom;
  // Граница та же, что в самом документе: на двадцатой строке p.php
  // убирает снимки, и дальше считать надо по пределам без фотографий.
  if(estFoto && strok <= PREDEL.pervyyPrays){
    naPervom = PREDEL.pervyyFoto;
    naVtorom = PREDEL.vtoroyFoto;
  } else {
    naPervom = estFoto ? PREDEL.pervyyPrays : PREDEL.pervyyBez;
    naVtorom = PREDEL.vtoroyBez;
  }
  if(strok <= naPervom) return 1;
  return 1 + Math.ceil((strok - naPervom) / naVtorom);
}

/* Для node: модульные тесты дергают расчёт напрямую, без браузера. */
if(typeof module !== 'undefined' && module.exports){
  module.exports = {strokaSumma: strokaSumma, itogiPoGruppam: itogiPoGruppam,
                    cenaZaEdinicu: cenaZaEdinicu, listov: listov, PREDEL: PREDEL,
                    okruglit: okruglit};
}

/* ═══════════════════════════════════════════════════════════════════
   Дальше — только в браузере
   ═══════════════════════════════════════════════════════════════════ */
if(typeof document !== 'undefined'){
(function(){
  var $ = function(s){ return document.querySelector(s); };
  var KP = window.KP;
  KP.items = KP.items || [];
  KP.services = KP.services || [];

  var grjazno = false;              // есть несохранённые правки
  var KATALOG = [];

  /* ── рисуем строки ───────────────────────────────────────────── */
  function el(tag, cls, text){
    var e = document.createElement(tag);
    if(cls) e.className = cls;
    if(text !== undefined) e.textContent = text;
    return e;
  }

  function pole(row, kluch, opts){
    var td = el('td', opts.chislo ? 'chislo' : '');
    var inp = document.createElement('input');
    inp.value = row[kluch] === undefined || row[kluch] === null ? '' : row[kluch];
    if(opts.type) inp.type = opts.type;
    if(opts.ph) inp.placeholder = opts.ph;
    if(opts.step) inp.step = opts.step;
    if(opts.min !== undefined) inp.min = opts.min;
    var tronuli = false;
    inp.addEventListener('input', function(){
      tronuli = true;
      row[kluch] = opts.chislo ? (inp.value === '' ? '' : Number(inp.value.replace(',', '.'))) : inp.value;
      pereschitat();
      pomenyali();
    });
    if(opts.celoe){
      // Округляем, когда человек уходит из поля, а не на каждое нажатие:
      // иначе «1250» не напечатать — после первой же цифры значение
      // перезаписывалось бы прямо под руками.
      inp.addEventListener('blur', function(){
        if(inp.value === '') return;
        var v = okruglit(Number(inp.value.replace(',', '.')) || 0);
        inp.value = v;
        row[kluch] = v;
        /* Тронули поле цены — значит цена уже наша, пометка ни к чему.
           Сравнивать новое значение со старым бесполезно: к этому моменту
           оно уже записано обработчиком ввода, и разницы никакой. */
        if(kluch === 'p' && tronuli && row.istochnik){
          delete row.istochnik;
          narisovat();
          return;
        }
        pereschitat();
      });
    }
    td.appendChild(inp);
    return td;
  }

  function knopkaUbrat(spisok, row){
    var td = el('td');
    var b = el('button', 'ubrat', '×');
    b.title = 'Убрать строку';
    b.addEventListener('click', function(){
      var i = spisok.indexOf(row);
      if(i >= 0) spisok.splice(i, 1);
      narisovat();
      pomenyali();
    });
    td.appendChild(b);
    return td;
  }

  /* Картинка строки: щелчок выбирает файл и грузит его на сервер.
     Снимок из каталога подставляется сам, но его можно заменить. */
  function kartinka(row){
    var td = el('td');
    var pokazat = function(){
      td.innerHTML = '';
      var e;
      if(row.img){
        e = document.createElement('img');
        e.className = 'mini';
        e.src = row.img;
        e.title = 'Заменить картинку';
      } else {
        e = el('div', 'mini-net', 'фото');
        e.title = 'Добавить картинку';
      }
      e.addEventListener('click', vybrat);
      td.appendChild(e);
    };
    var vybrat = function(){
      var inp = document.createElement('input');
      inp.type = 'file';
      inp.accept = 'image/*';
      inp.addEventListener('change', function(){
        if(!inp.files || !inp.files[0]) return;
        var fd = new FormData();
        fd.append('file', inp.files[0]);
        status('Гружу картинку…');
        fetch('api.php?chto=upload', {method:'POST', body:fd})
          .then(function(r){ return r.json(); })
          .then(function(d){
            if(d.error){ status('Картинка не загрузилась: ' + d.error); return; }
            row.img = d.img;
            pokazat();
            pomenyali();
            status('Картинка на месте');
          })
          .catch(function(){ status('Картинка не загрузилась'); });
      });
      inp.click();
    };
    pokazat();
    return td;
  }

  function narisovat(){
    var tb = $('#tabl-poz tbody');
    tb.innerHTML = '';
    KP.items.forEach(function(row){
      var tr = el('tr');
      /* Цена взята у поставщика — подсвечиваем строку, чтобы чужая
         розница случайно не уехала клиенту как наша. Пометка живёт
         только в редакторе, в документ не попадает. */
      if(row.istochnik) tr.className = 'chuzhaya';
      tr.appendChild(kartinka(row));
      tr.appendChild(pole(row, 'n', {ph:'Наименование'}));
      tr.appendChild(pole(row, 'q', {chislo:true, celoe:true, type:'number', step:'1', min:0}));
      tr.appendChild(pole(row, 'unit', {ph:'шт'}));
      var cena = pole(row, 'p', {chislo:true, celoe:true, type:'number', step:'1', min:0});
      if(row.istochnik){
        var m = el('span', 'metka', 'цена с ' + row.istochnik);
        m.title = 'Это цена поставщика. Поставьте свою — пометка исчезнет.';
        cena.appendChild(m);
      }
      tr.appendChild(cena);
      var s = el('td', 'summa');
      s.dataset.summa = '1';
      tr.appendChild(s);
      tr.appendChild(knopkaUbrat(KP.items, row));
      tb.appendChild(tr);
    });

    var tu = $('#tabl-usl tbody');
    tu.innerHTML = '';
    KP.services.forEach(function(row){
      var tr = el('tr');
      tr.appendChild(pole(row, 'n', {ph:'Работа или услуга'}));
      tr.appendChild(pole(row, 'q', {chislo:true, celoe:true, type:'number', step:'1', min:0}));
      tr.appendChild(pole(row, 'unit', {ph:'шт'}));
      tr.appendChild(pole(row, 'p', {chislo:true, celoe:true, type:'number', step:'1', min:0}));
      var s = el('td', 'summa');
      s.dataset.summa = '1';
      tr.appendChild(s);
      tr.appendChild(knopkaUbrat(KP.services, row));
      tu.appendChild(tr);
    });

    pereschitat();
  }

  function pereschitat(){
    var sum = itogiPoGruppam(KP);
    var yach = document.querySelectorAll('[data-summa]');
    var vse = KP.items.concat(KP.services);
    for(var i = 0; i < yach.length; i++){
      yach[i].textContent = vse[i] ? dengi(strokaSumma(vse[i])) : '';
    }
    $('#itog').textContent = dengi(sum.vsego) + ' ₽';
    $('#itog-verh').textContent = dengi(sum.vsego) + ' ₽';
    $('#itog-tov').textContent = dengi(sum.items) + ' ₽';
    $('#itog-usl').textContent = dengi(sum.services) + ' ₽';
    $('#schet-poz').textContent = KP.items.length;
    $('#schet-usl').textContent = KP.services.length;

    /* Итог справа считаем всегда — он нужен нам. Но если в документ он
       не идёт, об этом надо сказать, иначе легко забыть, что клиент
       этой цифры не увидит. */
    var vItog = $('#f-itog') ? $('#f-itog').checked : true;
    var pometka = $('#itog-ne-v-doc');
    if(pometka) pometka.hidden = vItog;

    var strok = KP.items.length + KP.services.length;
    var estFoto = KP.items.some(function(r){ return !!r.img; });
    var l = listov(strok, estFoto);
    var pod = $('#listov');
    if(!strok){
      pod.textContent = 'Пока пусто';
      pod.className = 'mono mal';
    } else if(l === 1){
      pod.textContent = 'Уложится в один лист';
      pod.className = 'mono mal est';
    } else {
      var predel = (estFoto && strok <= PREDEL.pervyyPrays) ? PREDEL.pervyyFoto
                 : PREDEL.pervyyBez;
      pod.textContent = 'Листов при печати: ' + l + ' · в один влезает ' + predel;
      pod.className = 'mono mal';
    }
  }

  function status(t, horosho){
    var s = $('#status');
    s.textContent = t;
    s.className = 'mono mal' + (horosho ? ' est' : '');
  }

  function pomenyali(){
    grjazno = true;
    status('Есть несохранённые правки');
  }

  /* ── поиск по каталогу ───────────────────────────────────────── */
  function normalizovat(s){
    // «205/55 R16» и «205 55 16» должны находить одно и то же
    return String(s).toLowerCase()
      .replace(/ё/g, 'е')
      .replace(/\b[rр](?=\d)/g, '')
      .replace(/[^a-zа-я0-9]+/g, ' ')
      .trim();
}

  function iskat(zapros){
    var slova = normalizovat(zapros).split(' ').filter(function(w){ return w.length > 0; });
    if(!slova.length) return [];
    var out = [];
    for(var i = 0; i < KATALOG.length && out.length < 40; i++){
      var k = KATALOG[i];
      var podhodit = true;
      for(var j = 0; j < slova.length; j++){
        if(k._n.indexOf(slova[j]) === -1){ podhodit = false; break; }
      }
      if(podhodit) out.push(k);
    }
    // в наличии — выше: предлагать то, чего нет, клиенту незачем
    out.sort(function(a, b){ return (b.s > 0) - (a.s > 0); });
    return out;
  }

  var vybran = -1;
  function pokazatPodskazki(spisok){
    var box = $('#podskazki');
    box.innerHTML = '';
    vybran = -1;
    if(!spisok.length){ box.hidden = true; return; }
    spisok.forEach(function(k, i){
      var b = el('button', 'pod');
      b.type = 'button';
      if(k.img){
        var im = document.createElement('img');
        im.src = k.img;
        im.alt = '';
        b.appendChild(im);
      }
      var mid = el('div', 'im');
      mid.appendChild(el('b', '', k.n));
      mid.appendChild(el('i', '', k.c || ''));
      b.appendChild(mid);
      var ce = el('span', 'ce', dengi(k.p) + ' ₽');
      if(!(k.s > 0)){
        ce.innerHTML = dengi(k.p) + ' ₽<br><span class="net">под заказ</span>';
      }
      b.appendChild(ce);
      b.addEventListener('click', function(){ dobavitIzKataloga(k); });
      box.appendChild(b);
    });
    box.hidden = false;
  }

  function dobavitIzKataloga(k){
    KP.items.push({n: k.n, p: k.p, q: 4, unit: 'шт', img: k.img || '', note: ''});
    $('#q').value = '';
    $('#podskazki').hidden = true;
    narisovat();
    pomenyali();
    $('#q').focus();
  }

  var tajmer = null;
  $('#q').addEventListener('input', function(){
    clearTimeout(tajmer);
    var v = this.value;
    tajmer = setTimeout(function(){
      pokazatPodskazki(v.trim().length >= 2 ? iskat(v) : []);
    }, 120);
  });

  $('#q').addEventListener('keydown', function(e){
    var knopki = $('#podskazki').querySelectorAll('.pod');
    if(!knopki.length) return;
    if(e.key === 'ArrowDown' || e.key === 'ArrowUp'){
      e.preventDefault();
      vybran += (e.key === 'ArrowDown' ? 1 : -1);
      if(vybran < 0) vybran = knopki.length - 1;
      if(vybran >= knopki.length) vybran = 0;
      for(var i = 0; i < knopki.length; i++) knopki[i].classList.toggle('vybran', i === vybran);
      knopki[vybran].scrollIntoView({block:'nearest'});
    } else if(e.key === 'Enter'){
      e.preventDefault();
      knopki[vybran >= 0 ? vybran : 0].click();
    } else if(e.key === 'Escape'){
      $('#podskazki').hidden = true;
    }
  });

  document.addEventListener('click', function(e){
    if(!e.target.closest('.poisk')) $('#podskazki').hidden = true;
  });

  /* ── ручные строки и готовые услуги ──────────────────────────── */
  $('#dobavit-ruchnuyu').addEventListener('click', function(){
    KP.items.push({n:'', p:0, q:1, unit:'шт', img:'', note:''});
    narisovat();
    pomenyali();
    var polya = $('#tabl-poz tbody').querySelectorAll('tr:last-child input');
    if(polya[0]) polya[0].focus();
  });

  (window.USLUGI_GOTOVYE || []).forEach(function(u){
    var b = el('button', '', '+ ' + u.n);
    b.addEventListener('click', function(){
      KP.services.push({n:u.n, p:u.p, q:1, unit:'шт'});
      narisovat();
      pomenyali();
    });
    $('#gotovye-uslugi').appendChild(b);
  });

  /* ── товар со страницы поставщика ────────────────────────────── */
  function urlStatus(t, beda){
    var e = $('#url-stat');
    e.textContent = t;
    e.className = beda ? 'hint beda' : 'hint';
  }

  function dobavit_tovar(t){
    KP.items.push({n: t.n, p: t.p || 0, q: 4, unit: 'шт',
                   img: t.img || '', note: '', istochnik: t.istochnik || ''});
    narisovat();
    pomenyali();
  }

  $('#tyanut').addEventListener('click', function(){
    var url = $('#url').value.trim();
    if(!url){ urlStatus('Вставьте ссылку на карточку товара', true); return; }
    urlStatus('Открываю страницу поставщика…');
    fetch('api.php?chto=tovar&url=' + encodeURIComponent(url))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d.error){ urlStatus(d.error, true); return; }
        dobavit_tovar(d.tovar);
        $('#url').value = '';
        var hvost = (d.tovar.img ? ' · снимок забрали' : ' · снимка нет')
                  + (d.tovar.p ? '' : ' · ЦЕНА НЕ НАШЛАСЬ, впишите сами')
                  + (d.tovar.otkuda && d.tovar.otkuda !== 'разметка'
                      ? ' · цена из источника «' + d.tovar.otkuda + '»' : '');
        urlStatus(d.tovar.zametka
          ? 'Добавлено, но ' + d.tovar.zametka
          : 'Добавлено: ' + d.tovar.n.slice(0, 55) + hvost,
          !!d.tovar.zametka || !d.tovar.p);
      })
      .catch(function(){ urlStatus('Сервер не ответил', true); });
  });

  $('#url').addEventListener('keydown', function(e){
    if(e.key === 'Enter'){ e.preventDefault(); $('#tyanut').click(); }
  });

  /* Буфер наполняет закладка со страниц, куда сервер не пускают */
  function proverit_bufer(){
    fetch('api.php?chto=bufer')
      .then(function(r){ return r.json(); })
      .then(function(d){
        var n = (d.items || []).length;
        var k = $('#bufer');
        k.hidden = n === 0;
        k.textContent = 'Вставить из буфера (' + n + ')';
        k.dataset.items = JSON.stringify(d.items || []);
      })
      .catch(function(){});
  }

  $('#bufer').addEventListener('click', function(){
    var spisok = [];
    try { spisok = JSON.parse(this.dataset.items || '[]'); } catch(e){}
    if(!spisok.length) return;
    spisok.forEach(dobavit_tovar);
    fetch('api.php?chto=bufer_ochistit').then(function(){ proverit_bufer(); });
    urlStatus('Вставлено позиций: ' + spisok.length);
  });

  proverit_bufer();
  /* вернулись во вкладку — вдруг успели набрать ещё */
  document.addEventListener('visibilitychange', function(){
    if(!document.hidden) proverit_bufer();
  });

  /* ── сохранение ──────────────────────────────────────────────── */
  function sobrat(){
    // Пустые строки отсеиваем: добавил строку, отвлёкся, не вписал
    // название — и в документе повисает пустая позиция с ценой.
    var chistye = function(spisok){
      return spisok.filter(function(r){
        return String(r.n || '').trim() !== '' || Number(r.p) > 0;
      });
    };
    KP.items = chistye(KP.items);
    KP.services = chistye(KP.services);
    return {
      id: KP.id || '',
      num: KP.num || '',
      date: $('#f-date').value,
      valid_days: Number($('#f-valid').value) || 0,
      manager: $('#f-manager').value,
      faksimile: $('#f-faksimile').checked,
      itog: $('#f-itog').checked,
      predmet: $('#f-predmet').value,
      comment: $('#f-comment').value,
      usloviya: $('#f-usloviya').value,
      client: {
        org: $('#f-org').value,
        person: $('#f-person').value,
        phone: $('#f-phone').value,
        email: $('#f-email').value
      },
      items: KP.items,
      services: KP.services
    };
  }

  function sohranit(){
    status('Сохраняю…');
    return fetch('api.php?chto=save', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(sobrat())
      })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d.error){ status('Не сохранилось: ' + d.error); return false; }
        KP.id = d.id;
        KP.num = d.num;
        grjazno = false;
        $('#nom').textContent = d.num;
        var smotret = $('#smotret');
        smotret.href = d.link;
        smotret.classList.remove('off');
        status('Сохранено в ' + new Date().toLocaleTimeString('ru-RU').slice(0, 5), true);
        // адрес в строке браузера тоже обновляем: обновление страницы
        // больше не потеряет только что созданное предложение
        if(history.replaceState) history.replaceState(null, '', 'index.php?id=' + d.id);
        return true;
      })
      .catch(function(){ status('Сервер не ответил'); return false; });
  }

  $('#sohranit').addEventListener('click', function(){ sohranit(); });

  var knopkaKopiya = $('#kopiya');
  if(knopkaKopiya){
    knopkaKopiya.addEventListener('click', function(){
      status('Делаю копию…');
      fetch('api.php?chto=copy&id=' + encodeURIComponent(KP.id))
        .then(function(r){ return r.json(); })
        .then(function(d){
          if(d.error){ status('Копия не вышла: ' + d.error); return; }
          grjazno = false;     // уходим со страницы — предупреждать не о чем
          location.href = 'index.php?id=' + d.id;
        })
        .catch(function(){ status('Сервер не ответил'); });
    });
  }

  var knopkaUdalit = $('#udalit');
  if(knopkaUdalit){
    knopkaUdalit.addEventListener('click', function(){
      if(!confirm('Удалить предложение ' + (KP.num || '') + '? Ссылка у клиента перестанет открываться.')) return;
      fetch('api.php?chto=delete&id=' + encodeURIComponent(KP.id))
        .then(function(r){ return r.json(); })
        .then(function(d){
          if(d.error){ status('Не удалилось: ' + d.error); return; }
          grjazno = false;
          location.href = 'index.php';
        })
        .catch(function(){ status('Сервер не ответил'); });
    });
  }

  $('#smotret').addEventListener('click', function(e){
    // Открывать печать с несохранёнными правками бессмысленно: на бумагу
    // пойдёт то, что лежит на сервере. Поэтому сначала сохраняем.
    if(grjazno){
      e.preventDefault();
      var ssylka = this;
      sohranit().then(function(ok){ if(ok) window.open(ssylka.href, '_blank'); });
    }
  });

  document.addEventListener('keydown', function(e){
    if((e.metaKey || e.ctrlKey) && e.key === 's'){ e.preventDefault(); sohranit(); }
  });

  window.addEventListener('beforeunload', function(e){
    if(grjazno){ e.preventDefault(); e.returnValue = ''; }
  });

  ['f-org','f-person','f-phone','f-email','f-predmet','f-date','f-valid',
   'f-manager','f-comment','f-usloviya'].forEach(function(id){
    var e = document.getElementById(id);
    if(e) e.addEventListener('input', pomenyali);
  });
  $('#f-faksimile').addEventListener('change', pomenyali);
  $('#f-itog').addEventListener('change', function(){ pomenyali(); pereschitat(); });

  /* ── загрузка каталога ───────────────────────────────────────── */
  narisovat();
  if(KP.id) status('Сохранено', true);

  fetch('api.php?chto=catalog')
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(d.error){
        $('#cat-stat').textContent = d.error;
        $('#cat-stat').className = 'hint beda';
        return;
      }
      KATALOG = d.items || [];
      KATALOG.forEach(function(k){ k._n = normalizovat(k.n + ' ' + (k.c || '')); });
      var vNalichii = KATALOG.filter(function(k){ return k.s > 0; }).length;
      $('#cat-stat').textContent = 'В каталоге ' + KATALOG.length + ' позиций, в наличии ' +
        vNalichii + (d.updated ? ' · обновлён ' + d.updated : '');
    })
    .catch(function(){
      $('#cat-stat').textContent = 'Каталог не загрузился — сервер не ответил';
      $('#cat-stat').className = 'hint beda';
    });
})();
}
