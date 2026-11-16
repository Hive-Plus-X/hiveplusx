// HPX-note のオフライン対応 (Service Worker)
// ・ページ (蜂場・巣箱・メモ・タスク・記録・センサ) は、開くたびに最新を取りに行き、この端末に控えを残す。
//   電波が無いとき (または弱くて返事が来ないとき) は控えを出す。控えた日時はページの上に出る (js/hpx_offline.js)
// ・電波が無いときにメモ・作業記録・タスクを保存すると「送信待ち」に入れる。送るのは js/hpx_offline.js
//   (電波が戻ってページを開いたとき)。サーバー側は今までどおりログインとチームの権限を確かめる
// ・写真・画像も見たものは控える
// この端末の中だけの控えなので、ログアウトすると消す (js/hpx_offline.js)
'use strict';
const SW_VER = 'hpx-sw-1';
const C_PAGES = 'hpx-pages';     // ページの控え
const C_PHOTOS = 'hpx-photos';   // 写真の控え
const C_STATIC = 'hpx-static';   // 画像・JS など
const PHOTO_MAX = 300;           // 写真の控えはこの枚数まで (古いものから消す)
const WAIT_MS = 12000;           // この時間返事が無ければ、控えがあれば控えを出す (電波が弱いとき)
// 控えるページ
const PAGES = ['apiary.php', 'hive.php', 'memo.php', 'task.php', 'work.php', 'sensor.php', 'offline.php'];
// 電波が無くても「送信待ち」にできる操作 (それ以外の削除・設定の変更などはオフラインではできない)
const QUEUE_ACTS = ['memo_save', 'log_save', 'todo_save', 'todo_done', 'todo_undo'];
const BASE = new URL('./', self.location).pathname;   // 例: /hpx/

// 最初に控えておくもの (ページの見た目と、このしくみ自身)
const PRECACHE = ['js/hpx_offline.js?v=1', 'js/qrcode.js', 'js/hpx_qr.js', 'manifest.json', 'img/favicon.svg', 'img/hivex_note_reverse.svg', 'img/icon-192.png', 'img/apple-touch-icon.png'];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(caches.open(C_STATIC).then(c => Promise.all(PRECACHE.map(u => c.add(u).catch(() => {})))));
});
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || !url.pathname.startsWith(BASE)) return;
  const name = url.pathname.slice(BASE.length);
  if (url.searchParams.has('logout') || url.searchParams.has('download')) return;   // ログアウト・ダウンロードはそのまま
  if (req.method === 'POST') {
    if (req.mode === 'navigate' && PAGES.includes(name)) e.respondWith(pagePost(req));
    return;
  }
  if (req.method !== 'GET') return;
  if (/^(img|js)\//.test(name) || name === 'manifest.json') { e.respondWith(staticGet(req)); return; }
  if (!PAGES.includes(name)) return;
  if (url.searchParams.has('photo') || url.searchParams.has('img')) { e.respondWith(photoGet(req)); return; }
  e.respondWith(pageGet(req, req.headers.get('X-HPX-Crawl') === '1'));
});

// ---------------------------------------------------------------- ページ
async function pageGet(req, crawl) {
  const net = fetch(req).then(async res => {
    // ログイン画面へ飛ばされたもの・エラー・HTML 以外は控えない
    if (res.ok && !res.redirected && res.type === 'basic' && (res.headers.get('content-type') || '').includes('text/html')) {
      try { await savePage(req.url, res.clone()); } catch (err) { /* 容量不足などは控えないだけ */ }
    }
    return res;
  });
  if (crawl) return net;   // まとめて保存のときは控えを出さずに待つ
  // 返事が遅いとき、控えがあれば先に出す
  let timer;
  const slow = new Promise(ok => { timer = setTimeout(ok, WAIT_MS); });
  try {
    const first = await Promise.race([net.then(r => ({ r })), slow.then(() => null)]);
    if (first) { clearTimeout(timer); return first.r; }
    const c = await cachedPage(req.url, 'slow');
    return c || await net;
  } catch (err) {
    clearTimeout(timer);
    return (await cachedPage(req.url, 'off')) || offlineList(req.url);
  }
}

async function savePage(url, res) {
  const text = await res.text();
  const m = text.match(/<title>([^<]*)<\/title>/);
  const hd = new Headers({ 'Content-Type': 'text/html; charset=utf-8', 'X-HPX-Saved': String(Date.now()),
    'X-HPX-Title': encodeURIComponent(m ? m[1].replace(/&amp;/g, '&') : '') });
  const c = await caches.open(C_PAGES);
  await c.put(stripHash(url), new Response(text, { status: 200, headers: hd }));
}

// 控えを探す。同じ URL が無ければ、同じ巣箱・HPX-Core の近い表示 (期間やページ番号の違い) を探す
async function cachedPage(url, why) {
  const c = await caches.open(C_PAGES);
  let hit = await c.match(stripHash(url)), near = false;
  if (!hit) {
    const want = new URL(url), wn = norm(want);
    const strict = ['h', 'hub'].concat(/^(apiary|hive)\.php$/.test(want.pathname.slice(BASE.length)) ? ['a'] : []);
    let best = null, bestScore = -1;
    for (const k of await c.keys()) {
      const u = new URL(k.url);
      if (u.pathname !== want.pathname) continue;
      const un = norm(u);
      if (un.toString() === wn.toString()) { best = k; near = false; bestScore = 1e9; break; }
      if (strict.some(s => (wn.get(s) || '') !== (un.get(s) || ''))) continue;
      let score = 0;
      for (const [key, v] of wn) if (un.get(key) === v) score++;
      if (score > bestScore) { best = k; bestScore = score; near = true; }
    }
    if (best) hit = await c.match(best);
  }
  if (!hit) return null;
  const text = await hit.text();
  const info = '<script>window.HPX_SAVED=' + (Number(hit.headers.get('X-HPX-Saved')) || 0) + ';window.HPX_NEAR=' + (near ? 1 : 0)
    + ';window.HPX_WHY=' + JSON.stringify(why) + ';</script>';
  return new Response(text.replace('<head>', '<head>' + info), { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

// 比べるときは、空の値と初期値 (期間 3日・1ページ目・記録の今月) を外して並べ直す
function norm(u) {
  const p = new URLSearchParams(), d = new Date(), ym = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  [...u.searchParams].filter(([k, v]) => v !== '' && !(/^(p|pt|ph|pw|pv)$/.test(k) && v === '3') && !(k === 'pg' && v === '1') && !(k === 'm' && v === ym))
    .sort((a, b) => a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0).forEach(([k, v]) => p.append(k, v));
  return p;
}
function stripHash(url) { const u = new URL(url); u.hash = ''; return u.toString(); }

// 控えが無いときの画面 (控えてあるページの一覧)
async function offlineList(url) {
  const c = await caches.open(C_PAGES);
  const rows = [];
  for (const k of await c.keys()) {
    const r = await c.match(k);
    const t = decodeURIComponent(r.headers.get('X-HPX-Title') || '') || new URL(k.url).pathname;
    rows.push('<li><a href="' + esc(k.url) + '">' + esc(t) + '</a> <small>' + esc(fmt(Number(r.headers.get('X-HPX-Saved')))) + '</small></li>');
  }
  return htmlPage('電波がありません',
    '<p>このページは、この端末に保存されていません。電波のある所でもう一度開いてください。</p>'
    + (rows.length ? '<p>保存してあるページ:</p><ul>' + rows.join('') + '</ul>' : '<p>保存してあるページはまだありません。</p>'));
}

// ---------------------------------------------------------------- 保存 (POST)
async function pagePost(req) {
  const copy = req.clone();
  try {
    return await fetch(req);
  } catch (err) {
    // 電波が無くて送れなかった
    let fd;
    try { fd = await copy.formData(); } catch (e) { return htmlPage('送れませんでした', '<p>電波が無いため送れませんでした。電波のある所でもう一度保存してください。</p>'); }
    const act = String(fd.get('act') || '');
    if (!QUEUE_ACTS.includes(act) || fd.has('plant_del')) {
      return htmlPage('オフラインではできません', '<p>電波が無いため、この操作はできません。メモ・作業記録・タスクの保存は、電波が無くてもこの端末に保存できます。'
        + '削除や設定の変更は、電波のある所でしてください。</p><p><a href="javascript:history.back()">← 戻る</a></p>');
    }
    const f = [];
    for (const [k, v] of fd.entries()) {
      if (typeof v === 'string') f.push([k, v]);
      else if (v && v.size > 0) f.push([k, await v.arrayBuffer(), v.name || 'photo.jpg', v.type || 'image/jpeg']);   // 写真
    }
    const oid = rid();
    f.push(['oid', oid]);
    const u = await idb('meta', 'readonly', s => s.get('u'));
    const label = labelOf(fd);
    await idb('queue', 'readwrite', s => s.put({ oid, u: u || '', url: req.url, f, at: Date.now(), label }));
    await idb('meta', 'readwrite', s => s.put(label, 'notice'));
    // 元のページ (back) を開き直す。控えが出て、上に「送信待ち」が出る
    const back = String(fd.get('back') || '');
    const to = /^[a-z_]+\.php(\?[^\s<>"]*)?(#[A-Za-z0-9_-]*)?$/.test(back) ? new URL(back, req.url).toString() : req.url;
    return Response.redirect(to, 303);
  }
}

function labelOf(fd) {
  const act = String(fd.get('act') || ''), id = String(fd.get('id') || '');
  const name = { memo_save: id ? 'メモの変更' : '新しいメモ', log_save: id ? '作業記録の変更' : '新しい作業記録',
    todo_save: id ? 'タスクの変更' : '新しいタスク', todo_done: 'タスクの完了', todo_undo: 'タスクを未完了に戻す' }[act] || '保存';
  const txt = String(fd.get('text') || fd.get('note') || fd.get('title') || '').trim().replace(/\s+/g, ' ');
  const d = String(fd.get('date') || '');
  return name + (d ? ' (' + d + ')' : '') + (txt ? ' 「' + (txt.length > 30 ? txt.slice(0, 30) + '…' : txt) + '」' : '');
}

// ---------------------------------------------------------------- 写真・画像
async function photoGet(req) {
  const c = await caches.open(C_PHOTOS);
  const hit = await c.match(req.url);
  if (hit) return hit;   // 写真は書き換えないので、控えがあればそれを使う
  const res = await fetch(req);
  if (res.ok && !res.redirected && (res.headers.get('content-type') || '').startsWith('image/')) {
    try {
      await c.put(req.url, res.clone());
      const keys = await c.keys();
      for (let i = 0; i < keys.length - PHOTO_MAX; i++) await c.delete(keys[i]);
    } catch (err) { }
  }
  return res;
}

async function staticGet(req) {
  const c = await caches.open(C_STATIC);
  const hit = await c.match(req) || await c.match(req, { ignoreSearch: true });   // ?v= が変わっても、電波が無ければ前のものを使う
  const net = fetch(req).then(res => { if (res.ok) c.put(req, res.clone()).catch(() => {}); return res; });
  if (hit) { net.catch(() => {}); return hit; }
  return net;
}

// ---------------------------------------------------------------- 道具
function htmlPage(title, body) {
  return new Response('<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>' + esc(title) + '</title><style>body{font-family:system-ui,sans-serif;margin:0 auto;padding:16px;max-width:700px;background:#f6f7f8;color:#222;line-height:1.6}'
    + 'a{color:#1565c0}small{color:#777}li{margin:4px 0}</style></head><body><h1 style="font-size:1.2em">' + esc(title) + '</h1>' + body + '</body></html>',
    { status: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}
function esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }
function fmt(t) { if (!t) return ''; const d = new Date(t); return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + d.getHours() + ':' + String(d.getMinutes()).padStart(2, '0'); }
function rid() { const a = new Uint8Array(12); crypto.getRandomValues(a); return Array.from(a, b => b.toString(16).padStart(2, '0')).join(''); }

// IndexedDB (送信待ち queue と、メモ meta)。js/hpx_offline.js と同じ作り
function idbOpen() {
  return new Promise((ok, ng) => {
    const r = indexedDB.open('hpx-offline', 1);
    r.onupgradeneeded = () => {
      const d = r.result;
      if (!d.objectStoreNames.contains('queue')) d.createObjectStore('queue', { keyPath: 'oid' });
      if (!d.objectStoreNames.contains('meta')) d.createObjectStore('meta');
    };
    r.onsuccess = () => ok(r.result);
    r.onerror = () => ng(r.error);
  });
}
async function idb(store, mode, fn) {
  const d = await idbOpen();
  return new Promise((ok, ng) => {
    const t = d.transaction(store, mode), q = fn(t.objectStore(store));
    t.oncomplete = () => { d.close(); ok(q && 'result' in q ? q.result : undefined); };
    t.onerror = t.onabort = () => { d.close(); ng(t.error); };
  });
}
