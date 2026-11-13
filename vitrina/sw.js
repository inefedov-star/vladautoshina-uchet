/* Витрина IVANOR без интернета: сама страница, наличие и фото — последние, что видели на этом планшете. */
const C = 'vitrina-v1';
self.addEventListener('install', e => e.waitUntil(caches.open(C).then(c => c.addAll(['./', 'index.html'])).then(() => self.skipWaiting())));
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (u.origin !== location.origin) return;
  if (r.method !== 'GET') { e.respondWith(fetch(r).catch(() => new Response(JSON.stringify({ error: 'Нет интернета — продажу отправим, когда связь вернётся' }), { status: 503, headers: { 'Content-Type': 'application/json; charset=utf-8' } }))); return; }
  // в кэш: страница витрины, наличие, список фото и сами фото; продавцу — «мои продажи»
  const ok = u.pathname.startsWith('/vitrina/') || /[?&]a=(vitrina|photos_public|me|my_sales)(&|$)/.test(u.search) || /^\/(pult|montazh)\/photos\//.test(u.pathname);
  if (!ok) return;
  e.respondWith(fetch(r).then(res => { if (res.ok && res.type === 'basic') { const cp = res.clone(); caches.open(C).then(c => c.put(r, cp)); } return res; })
    .catch(() => caches.match(r, { ignoreVary: true }).then(m => m || (r.mode === 'navigate' ? caches.match('index.html') : new Response('', { status: 503 })))));
});
