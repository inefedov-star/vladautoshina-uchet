/* ════════════════════════════════════════════════════════════════
   Генератор QR-кода. Байтовый режим, уровень коррекции Q (25%),
   версии 1–6. Без зависимостей. Возвращает матрицу true/false.

   Этого хватает на 74 символа — ссылка вида
   sklad.shinhotel33.ru/s/K7M2QX занимает 30.
   ════════════════════════════════════════════════════════════════ */
function qrMatrix(text) {
  // ── данные о версиях: [всего кодовых слов, слов коррекции на блок,
  //                       блоков группы 1, слов данных в блоке г1,
  //                       блоков группы 2, слов данных в блоке г2]
  var VER = {
    1: [26,  13, 1, 13, 0, 0],
    2: [44,  22, 1, 22, 0, 0],
    3: [70,  18, 2, 17, 0, 0],
    4: [100, 26, 2, 24, 0, 0],
    5: [134, 18, 2, 15, 2, 16],
    6: [172, 24, 4, 19, 0, 0]
  };
  var ALIGN = {1: [], 2: [6,18], 3: [6,22], 4: [6,26], 5: [6,30], 6: [6,34]};
  var REM   = {1: 0, 2: 7, 3: 7, 4: 7, 5: 7, 6: 7};

  // ── текст в байты UTF-8
  var bytes = [];
  for (var i = 0; i < text.length; i++) {
    var c = text.charCodeAt(i);
    if (c < 0x80) bytes.push(c);
    else if (c < 0x800) bytes.push(0xC0 | (c >> 6), 0x80 | (c & 63));
    else if (c < 0xD800 || c >= 0xE000) bytes.push(0xE0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    else { // суррогатная пара
      i++;
      var u = 0x10000 + (((c & 0x3FF) << 10) | (text.charCodeAt(i) & 0x3FF));
      bytes.push(0xF0 | (u >> 18), 0x80 | ((u >> 12) & 63), 0x80 | ((u >> 6) & 63), 0x80 | (u & 63));
    }
  }

  // ── подбор версии
  var ver = 0;
  for (var v = 1; v <= 6; v++) {
    var d = VER[v], total = d[2] * d[3] + d[4] * d[5];
    if (bytes.length + 2 <= total) { ver = v; break; }
  }
  if (!ver) return null;                    // слишком длинно
  var info = VER[ver];
  var dataWords = info[2] * info[3] + info[4] * info[5];

  // ── битовый поток: режим 0100, длина (8 бит), данные
  var bits = [];
  function put(val, len) { for (var k = len - 1; k >= 0; k--) bits.push((val >> k) & 1); }
  put(4, 4);
  put(bytes.length, 8);
  for (var b = 0; b < bytes.length; b++) put(bytes[b], 8);
  for (var z = 0; z < 4 && bits.length < dataWords * 8; z++) bits.push(0);
  while (bits.length % 8) bits.push(0);
  var words = [];
  for (var w = 0; w < bits.length; w += 8) {
    var byteVal = 0;
    for (var q = 0; q < 8; q++) byteVal = (byteVal << 1) | bits[w + q];
    words.push(byteVal);
  }
  var pad = [0xEC, 0x11], pi = 0;
  while (words.length < dataWords) words.push(pad[pi++ % 2]);

  // ── арифметика GF(256)
  var EXP = new Array(512), LOG = new Array(256);
  for (var x = 0, e = 1; x < 255; x++) {
    EXP[x] = e; LOG[e] = x;
    e <<= 1; if (e & 0x100) e ^= 0x11D;
  }
  for (var x2 = 255; x2 < 512; x2++) EXP[x2] = EXP[x2 - 255];
  function mul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }

  // ── порождающий многочлен степени n
  function genPoly(n) {
    var g = [1];
    for (var d = 0; d < n; d++) {
      var ng = new Array(g.length + 1).fill(0);
      for (var j = 0; j < g.length; j++) {
        ng[j] ^= mul(g[j], 1);
        ng[j + 1] ^= mul(g[j], EXP[d]);
      }
      g = ng;
    }
    return g;
  }
  function ecc(block, n) {
    var g = genPoly(n), rem = block.concat(new Array(n).fill(0));
    for (var j = 0; j < block.length; j++) {
      var coef = rem[j];
      if (coef === 0) continue;
      for (var k = 0; k < g.length; k++) rem[j + k] ^= mul(g[k], coef);
    }
    return rem.slice(block.length);
  }

  // ── разбиение на блоки, коррекция, чередование
  var blocks = [], eccs = [], off = 0;
  for (var gi = 0; gi < 2; gi++) {
    var cnt = gi === 0 ? info[2] : info[4], len = gi === 0 ? info[3] : info[5];
    for (var bi = 0; bi < cnt; bi++) {
      var blk = words.slice(off, off + len); off += len;
      blocks.push(blk); eccs.push(ecc(blk, info[1]));
    }
  }
  var out = [], maxLen = Math.max(info[3], info[5]);
  for (var c1 = 0; c1 < maxLen; c1++)
    for (var b1 = 0; b1 < blocks.length; b1++)
      if (c1 < blocks[b1].length) out.push(blocks[b1][c1]);
  for (var c2 = 0; c2 < info[1]; c2++)
    for (var b2 = 0; b2 < eccs.length; b2++) out.push(eccs[b2][c2]);

  var stream = [];
  for (var o = 0; o < out.length; o++) for (var k2 = 7; k2 >= 0; k2--) stream.push((out[o] >> k2) & 1);
  for (var r = 0; r < REM[ver]; r++) stream.push(0);

  // ── подготовка холста
  var size = ver * 4 + 17;
  var m = [], res = [];
  for (var y = 0; y < size; y++) {
    m.push(new Array(size).fill(0));
    res.push(new Array(size).fill(false));
  }
  function set(y, x, val) { m[y][x] = val ? 1 : 0; res[y][x] = true; }

  function finder(y0, x0) {
    for (var dy = -1; dy <= 7; dy++) for (var dx = -1; dx <= 7; dx++) {
      var y = y0 + dy, x = x0 + dx;
      if (y < 0 || x < 0 || y >= size || x >= size) continue;
      var on = (dy >= 0 && dy <= 6 && (dx === 0 || dx === 6)) ||
               (dx >= 0 && dx <= 6 && (dy === 0 || dy === 6)) ||
               (dy >= 2 && dy <= 4 && dx >= 2 && dx <= 4);
      set(y, x, on);
    }
  }
  finder(0, 0); finder(0, size - 7); finder(size - 7, 0);

  for (var t = 8; t < size - 8; t++) { set(6, t, t % 2 === 0); set(t, 6, t % 2 === 0); }

  var al = ALIGN[ver];
  for (var ai = 0; ai < al.length; ai++) for (var aj = 0; aj < al.length; aj++) {
    var cy = al[ai], cx = al[aj];
    if ((cy <= 8 && cx <= 8) || (cy <= 8 && cx >= size - 9) || (cy >= size - 9 && cx <= 8)) continue;
    for (var dy2 = -2; dy2 <= 2; dy2++) for (var dx2 = -2; dx2 <= 2; dx2++)
      set(cy + dy2, cx + dx2, Math.max(Math.abs(dy2), Math.abs(dx2)) !== 1);
  }

  set(size - 8, 8, true);                       // всегда тёмный модуль

  // ── резерв под информацию о формате
  for (var f = 0; f < 9; f++) {
    if (!res[8][f]) res[8][f] = true;
    if (!res[f][8]) res[f][8] = true;
  }
  for (var f2 = 0; f2 < 8; f2++) {
    res[8][size - 1 - f2] = true;
    res[size - 1 - f2][8] = true;
  }

  // ── укладка данных змейкой
  var idx = 0, up = true;
  for (var col = size - 1; col > 0; col -= 2) {
    if (col === 6) col = 5;
    for (var rr = 0; rr < size; rr++) {
      var row = up ? size - 1 - rr : rr;
      for (var s = 0; s < 2; s++) {
        var cc = col - s;
        if (res[row][cc]) continue;
        m[row][cc] = idx < stream.length ? stream[idx] : 0;
        idx++;
      }
    }
    up = !up;
  }

  // ── маски и штрафы
  function maskFn(k, y, x) {
    switch (k) {
      case 0: return (y + x) % 2 === 0;
      case 1: return y % 2 === 0;
      case 2: return x % 3 === 0;
      case 3: return (y + x) % 3 === 0;
      case 4: return (Math.floor(y / 2) + Math.floor(x / 3)) % 2 === 0;
      case 5: return ((y * x) % 2) + ((y * x) % 3) === 0;
      case 6: return (((y * x) % 2) + ((y * x) % 3)) % 2 === 0;
      default: return (((y + x) % 2) + ((y * x) % 3)) % 2 === 0;
    }
  }
  function penalty(g) {
    var p = 0, n = g.length, i2, j2;
    for (i2 = 0; i2 < n; i2++) {
      for (var dir = 0; dir < 2; dir++) {
        var run = 1, prev = dir ? g[0][i2] : g[i2][0];
        for (j2 = 1; j2 < n; j2++) {
          var cur = dir ? g[j2][i2] : g[i2][j2];
          if (cur === prev) { run++; }
          else { if (run >= 5) p += 3 + (run - 5); run = 1; prev = cur; }
        }
        if (run >= 5) p += 3 + (run - 5);
      }
    }
    for (i2 = 0; i2 < n - 1; i2++) for (j2 = 0; j2 < n - 1; j2++) {
      var a = g[i2][j2];
      if (a === g[i2][j2 + 1] && a === g[i2 + 1][j2] && a === g[i2 + 1][j2 + 1]) p += 3;
    }
    var PAT = [1,0,1,1,1,0,1,0,0,0,0], PAT2 = [0,0,0,0,1,0,1,1,1,0,1];
    for (i2 = 0; i2 < n; i2++) for (j2 = 0; j2 <= n - 11; j2++) {
      var ok1 = true, ok2 = true, ok3 = true, ok4 = true;
      for (var k3 = 0; k3 < 11; k3++) {
        if (g[i2][j2 + k3] !== PAT[k3])  ok1 = false;
        if (g[i2][j2 + k3] !== PAT2[k3]) ok2 = false;
        if (g[j2 + k3][i2] !== PAT[k3])  ok3 = false;
        if (g[j2 + k3][i2] !== PAT2[k3]) ok4 = false;
      }
      if (ok1) p += 40; if (ok2) p += 40; if (ok3) p += 40; if (ok4) p += 40;
    }
    var dark = 0;
    for (i2 = 0; i2 < n; i2++) for (j2 = 0; j2 < n; j2++) dark += g[i2][j2];
    p += Math.floor(Math.abs(dark * 100 / (n * n) - 50) / 5) * 10;
    return p;
  }

  // ── информация о формате: уровень Q = 11, BCH(15,5)
  function formatBits(mask) {
    var d = (0x03 << 3) | mask, rem = d << 10;
    for (var k4 = 14; k4 >= 10; k4--) if ((rem >> k4) & 1) rem ^= 0x537 << (k4 - 10);
    return ((d << 10) | rem) ^ 0x5412;
  }

  var best = null, bestScore = Infinity;
  for (var mk = 0; mk < 8; mk++) {
    var g2 = [];
    for (var y2 = 0; y2 < size; y2++) g2.push(m[y2].slice());
    for (var y3 = 0; y3 < size; y3++) for (var x3 = 0; x3 < size; x3++)
      if (!res[y3][x3] && maskFn(mk, y3, x3)) g2[y3][x3] ^= 1;

    var fb = formatBits(mk);
    for (var k5 = 0; k5 < 15; k5++) {
      var bit = (fb >> (14 - k5)) & 1;   // старший бит идёт первым
      // левый верхний угол
      if (k5 < 6)       g2[8][k5] = bit;
      else if (k5 === 6) g2[8][7] = bit;
      else if (k5 === 7) g2[8][8] = bit;
      else if (k5 === 8) g2[7][8] = bit;
      else               g2[14 - k5][8] = bit;
      // дубль: биты 0–6 снизу вверх по столбцу 8, биты 7–14 вправо по строке 8
      if (k5 < 7) g2[size - 1 - k5][8] = bit;
      else        g2[8][size - 15 + k5] = bit;
    }
    g2[size - 8][8] = 1;

    var sc = penalty(g2);
    if (sc < bestScore) { bestScore = sc; best = g2; }
  }

  var outM = [];
  for (var y4 = 0; y4 < size; y4++) {
    var row2 = [];
    for (var x4 = 0; x4 < size; x4++) row2.push(best[y4][x4] === 1);
    outM.push(row2);
  }
  return outM;
}

/** Матрица → SVG заданного размера в миллиметрах. */
function qrSvg(text, mm, quiet) {
  var m = qrMatrix(text);
  if (!m) return '';
  var n = m.length, q = (quiet === undefined ? 2 : quiet), tot = n + q * 2;
  var d = '';
  for (var y = 0; y < n; y++) {
    var x = 0;
    while (x < n) {
      if (!m[y][x]) { x++; continue; }
      var run = 0;
      while (x + run < n && m[y][x + run]) run++;
      d += 'M' + (x + q) + ' ' + (y + q) + 'h' + run + 'v1h-' + run + 'z';
      x += run;
    }
  }
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + tot + ' ' + tot +
         '" width="' + mm + 'mm" height="' + mm + 'mm" shape-rendering="crispEdges">' +
         '<rect width="' + tot + '" height="' + tot + '" fill="#fff"/>' +
         '<path d="' + d + '" fill="#000"/></svg>';
}

if (typeof module !== 'undefined') module.exports = { qrMatrix: qrMatrix, qrSvg: qrSvg };
