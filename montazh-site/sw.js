/* Пульт IVANOR без интернета: показываем последнее, что видели на этом устройстве.
   Просмотр — да (каталог, цены, хранение, расписание, заказы), изменения — нет. */
const C = 'ivanor-v1';
const SHELL = ['./', 'index.html'];
// что можно показывать из сохранённого
const READ = /[?&]a=(me|day|week|queue|bookings|stock|product|vitrina|photos_public|clients|zak_list|docs|doc|hotel_info|an_sum|month|weather)(&|$)/;
const json = (o, st) => new Response(JSON.stringify(o), { status: st || 503, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
self.addEventListener('install', e => e.waitUntil(caches.open(C).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
// вход другим PIN — стираем сохранённое, чтобы мастер не увидел данные собственника
self.addEventListener('message', e => { if (e.data === 'clear') e.waitUntil(caches.delete(C).then(() => caches.open(C)).then(c => c.addAll(SHELL))); });
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (u.origin !== location.origin) return;
  if (r.method !== 'GET') { e.respondWith(fetch(r).catch(() => json({ error: 'Нет интернета — сейчас можно только смотреть, изменения не сохраняются' }))); return; }
  const isApi = /\/api\.php$/.test(u.pathname);
  if (isApi && !READ.test(u.search)) return;
  e.respondWith(fetch(r).then(res => { if (res.ok && res.type === 'basic') { const cp = res.clone(); caches.open(C).then(c => c.put(r, cp)); } return res; })
    .catch(() => caches.match(r, { ignoreVary: true }).then(m => m || (r.mode === 'navigate' && (u.pathname === new URL(self.registration.scope).pathname || u.pathname === new URL(self.registration.scope).pathname + 'index.html') ? caches.match('index.html') : (isApi ? json({ error: 'Нет интернета, а эти данные на этом устройстве ещё не сохранялись' }) : new Response('<meta charset=utf-8><p style="font:16px system-ui;padding:24px">Нет интернета, а эта страница на этом устройстве ещё не сохранялась.</p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }))))));
});
