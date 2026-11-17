// HPX-note のオフライン対応 (ページ側)。sw.js と組で動く
// ・sw.js を登録する
// ・電波が無くて控えを出しているときは、ページの上に「○月○日 ○時の内容です」と出す
// ・送信待ちの件数を出し、電波があれば送る (送るときは今のページの合言葉 csrf を使う。サーバーがログインと権限を確かめる)
// ・ときどき (6時間おき) 主なページをまとめて控える。offline.php のボタンでは各蜂場・巣箱・メモの過去ページまで控える
// ・ログアウトするときと、別のアカウントでログインしたときは、この端末の控えを消す
(function () {
  'use strict';
  if (!('serviceWorker' in navigator) || !window.indexedDB || !window.caches) return;
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.getAttribute('content') : ''; };
  var ME = meta('hpx-u'), CSRF = meta('hpx-csrf');
  var SAVED = window.HPX_SAVED || 0;   // 控えを出しているとき、控えた時刻 (sw.js が入れる)
  var AUTO_H = 6;                      // まとめて控える間隔 (時間)
  var HPX = window.HPX_OFFLINE = {};

  navigator.serviceWorker.register('sw.js').catch(function () {});
  // 控えを先に出したページに、遅れて最新が届いた (sw.js)。書きかけでなければ入れ替える。書きかけなら、帯から選べるようにする
  var typed = false;
  document.addEventListener('input', function () { typed = true; }, true);
  navigator.serviceWorker.addEventListener('message', function (ev) {
    var d = ev.data || {};
    if (d.type !== 'hpx-fresh' || !SAVED) return;
    var here = location.href.split('#')[0];
    if (d.url !== here) return;
    if (!typed) { location.reload(); return; }
    msgs.push('🔄 最新の内容が届きました。<a href="' + esc(here) + '">表示する</a> (書きかけの内容は消えます)');
    render();
  });

  // ------------------------------------------------------------ IndexedDB (sw.js と同じ)
  function idbOpen() {
    return new Promise(function (ok, ng) {
      var r = indexedDB.open('hpx-offline', 1);
      r.onupgradeneeded = function () {
        var d = r.result;
        if (!d.objectStoreNames.contains('queue')) d.createObjectStore('queue', { keyPath: 'oid' });
        if (!d.objectStoreNames.contains('meta')) d.createObjectStore('meta');
      };
      r.onsuccess = function () { ok(r.result); };
      r.onerror = function () { ng(r.error); };
    });
  }
  function idb(store, mode, fn) {
    return idbOpen().then(function (d) {
      return new Promise(function (ok, ng) {
        var t = d.transaction(store, mode), q = fn(t.objectStore(store));
        t.oncomplete = function () { d.close(); ok(q && 'result' in q ? q.result : undefined); };
        t.onerror = t.onabort = function () { d.close(); ng(t.error); };
      });
    });
  }
  function myQueue() {
    return idb('queue', 'readonly', function (s) { return s.getAll(); }).then(function (a) {
      return (a || []).filter(function (x) { return !ME || x.u === ME || x.u === ''; }).sort(function (a, b) { return a.at - b.at; });
    });
  }
  HPX.queue = myQueue;
  HPX.drop = function (oid) { return idb('queue', 'readwrite', function (s) { return s.delete(oid); }).then(render); };

  function fmt(t) { var d = new Date(t); return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + d.getHours() + ':' + ('0' + d.getMinutes()).slice(-2); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

  // ------------------------------------------------------------ 上の帯
  var bar = document.createElement('div');
  bar.id = 'hpx-off';
  bar.style.cssText = 'display:none;margin:0 0 8px;padding:8px 12px;border-radius:6px;font-size:.92em;line-height:1.5;background:#fdf0c8;border:1px solid #e0c060;color:#3d2c00';
  var msgs = [];   // 送った結果など、一度だけ出すお知らせ
  function place() {
    var nav = document.querySelector('nav');
    if (nav && nav.parentNode) nav.parentNode.insertBefore(bar, nav.nextSibling); else document.body.insertBefore(bar, document.body.firstChild);
  }
  function render() {
    return myQueue().then(function (q) {
      var h = [];
      if (SAVED) {
        h.push(window.HPX_WHY === 'slow'
          ? '⏳ 読み込みに時間がかかっているため、<b>' + fmt(SAVED) + '</b> に保存した内容を先に表示しています。最新の内容が届いたら入れ替えます。'
          : '📴 電波が無いため、<b>' + fmt(SAVED) + '</b> に保存した内容を表示しています。'
          + (window.HPX_NEAR ? 'この表示は保存されていないので、近い表示を出しています。' : ''));
      }
      msgs.forEach(function (m) { h.push(m); });
      if (q.length) {
        h.push('📤 送信待ちが <b>' + q.length + ' 件</b>あります。電波のある所でページを開くと、自動で送ります。'
          + ' <a href="offline.php">送信待ちを見る</a>' + (navigator.onLine ? ' <button type="button" id="hpx-send" style="padding:2px 10px;font-size:.9em">今すぐ送る</button>' : ''));
      }
      bar.innerHTML = h.join('<br>');
      bar.style.display = h.length ? '' : 'none';
      var b = document.getElementById('hpx-send');
      if (b) b.onclick = function () { b.disabled = true; send(); };
      if (HPX.onchange) HPX.onchange(q);
    }).catch(function () {});
  }
  HPX.render = render;

  // ------------------------------------------------------------ 送る
  var sending = false;
  function send() {
    // ほかのタブと同時に送らないように (使えるブラウザだけ)
    if (navigator.locks && !send.inLock) return navigator.locks.request('hpx-send', { ifAvailable: true }, function (l) {
      if (!l) return; send.inLock = true; return sendNow().then(function () { send.inLock = false; }, function () { send.inLock = false; });
    });
    return sendNow();
  }
  function sendNow() {
    if (sending || !CSRF) return Promise.resolve();
    sending = true;
    var stop = '';
    return myQueue().then(function (q) {
      var p = Promise.resolve();
      q.forEach(function (it) {
        p = p.then(function () {
          if (stop) return;
          var fd = new FormData();
          it.f.forEach(function (x) {
            if (x[0] === 'csrf') fd.append('csrf', CSRF);
            else if (x.length > 2) fd.append(x[0], new Blob([x[1]], { type: x[3] || 'image/jpeg' }), x[2] || 'photo.jpg');
            else fd.append(x[0], x[1]);
          });
          return fetch(it.url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            if (/\/login\.php/.test(r.url)) { stop = 'ログインが切れているため、送れませんでした。ログインし直すと送ります。'; return; }
            if (r.status === 400) { stop = '送れませんでした。ページを開き直すと、もう一度送ります。'; return; }
            if (!r.ok) { msgs.push('⚠️ ' + esc(it.label) + ' は、サーバーのエラー (' + r.status + ') で送れませんでした。あとでもう一度送ります。'); stop = ' '; return; }
            return r.text().then(function (t) {
              var f = new DOMParser().parseFromString(t, 'text/html').querySelector('.flash');
              msgs.push('✅ ' + esc(it.label) + ' を送りました' + (f ? ': ' + esc(f.textContent.trim()) : '。'));
              return idb('queue', 'readwrite', function (s) { return s.delete(it.oid); });
            });
          }, function () { stop = ' '; });   // 電波が無い。あとで送る
        });
      });
      return p;
    }).then(function () {
      if (stop.trim()) msgs.push('⚠️ ' + esc(stop));
      sending = false;
      return render();
    }, function () { sending = false; });
  }
  HPX.send = send;

  // ------------------------------------------------------------ まとめて控える
  var START = ['apiary.php', 'hive.php', 'memo.php', 'task.php', 'work.php', 'work.php?m=all', 'sensor.php'];
  // たどるリンク: ページごとに使ってよい項目
  var ALLOW = { 'apiary.php': ['a'], 'hive.php': ['a', 'h', 'p'], 'memo.php': ['pg'], 'task.php': ['a', 'm'], 'work.php': ['a', 'm'], 'sensor.php': ['hub'] };
  var base = new URL('./', location.href);
  function okLink(u) {
    if (u.origin !== base.origin || u.pathname.indexOf(base.pathname) !== 0) return false;
    var name = u.pathname.slice(base.pathname.length), allow = ALLOW[name];
    if (!allow) return false;
    var ok = true;
    u.searchParams.forEach(function (v, k) {
      if (v === '') return;
      if (allow.indexOf(k) < 0 || v === 'new') ok = false;
      if (k === 'p' && v !== '3') ok = false;                                 // 巣箱のグラフは初めの期間 (3日) だけ
      if (k === 'm' && v !== 'all' && v !== ymd(new Date()).slice(0, 7)) ok = false;   // 記録は今月と「すべて」
    });
    return ok;
  }
  // full: 各ページのリンクもたどる。onp(済んだ数, 全部の数)
  function crawl(full, onp) {
    var todo = START.map(function (s) { return new URL(s, base).toString(); }), seen = {}, done = 0, max = full ? 120 : START.length;
    todo.forEach(function (u) { seen[u] = 1; });
    function next() {
      if (!todo.length || done >= max || !navigator.onLine) return Promise.resolve(done);
      var u = todo.shift();
      return fetch(u, { credentials: 'same-origin', headers: { 'X-HPX-Crawl': '1' } }).then(function (r) {
        if (/\/login\.php/.test(r.url)) { todo = []; return; }
        done++;
        if (onp) onp(done, Math.min(max, done + todo.length));
        if (!full || !r.ok) return;
        return r.text().then(function (t) {
          new DOMParser().parseFromString(t, 'text/html').querySelectorAll('a[href]').forEach(function (a) {
            var x = new URL(a.getAttribute('href'), u); x.hash = '';
            var s = x.toString();
            if (!seen[s] && okLink(x)) { seen[s] = 1; todo.push(s); }
          });
        });
      }).catch(function () {}).then(function () { return new Promise(function (ok) { setTimeout(ok, 150); }); }).then(next);
    }
    return next().then(function (n) { localStorage.setItem('hpx-crawl', String(Date.now())); return n; });
  }
  HPX.crawl = crawl;

  // ------------------------------------------------------------ 消す
  function clearSaved() {
    localStorage.removeItem('hpx-crawl');
    return Promise.all(['hpx-pages', 'hpx-photos'].map(function (c) { return caches.delete(c); }));
  }
  HPX.clearSaved = clearSaved;

  // ログアウト: 送信待ちがあれば確かめてから、控えと送信待ちを消す
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="logout=1"]');
    if (!a) return;
    e.preventDefault();
    myQueue().then(function (q) {
      if (q.length && !confirm('まだ送っていない記録が ' + q.length + ' 件あります。ログアウトすると、この端末から消えます。ログアウトしますか?')) return;
      return Promise.all(q.map(function (x) { return idb('queue', 'readwrite', function (s) { return s.delete(x.oid); }); }))
        .then(clearSaved).then(function () { localStorage.removeItem('hpx-u'); location.href = a.href; });
    }).catch(function () { location.href = a.href; });
  });

  // ------------------------------------------------------------ はじめに
  function start() {
    place();
    if (SAVED) {
      // 控えの古いお知らせは消し、新しく書く日付を今日にする
      document.querySelectorAll('.flash').forEach(function (x) { x.remove(); });
      var was = ymd(new Date(SAVED)), today = ymd(new Date());
      if (was !== today) document.querySelectorAll('form').forEach(function (f) {
        var id = f.querySelector('input[name="id"]');
        if (id && id.value !== '') return;
        f.querySelectorAll('input[type="date"][name="date"]').forEach(function (d) { if (d.value === was) d.value = today; });
      });
    }
    var p = Promise.resolve();
    if (ME) {
      var prev = localStorage.getItem('hpx-u');
      if (prev && prev !== ME) p = clearSaved();   // 別のアカウントでログインした
      localStorage.setItem('hpx-u', ME);
      p = p.then(function () { return idb('meta', 'readwrite', function (s) { return s.put(ME, 'u'); }); });
    }
    p.then(function () { return idb('meta', 'readonly', function (s) { return s.get('notice'); }); }).then(function (n) {
      if (!n) return;
      msgs.push('💾 電波が無いため、<b>' + esc(n) + '</b> をこの端末に保存しました。電波が戻ったら送ります。');
      return idb('meta', 'readwrite', function (s) { return s.delete('notice'); });
    }).catch(function () {}).then(render).then(function () {
      if (SAVED || !navigator.onLine || !ME) return;
      return send().then(function () {
        var last = Number(localStorage.getItem('hpx-crawl') || 0);
        if (Date.now() - last > AUTO_H * 3600e3 && !window.HPX_NO_AUTO) {
          var go = function () { crawl(false); };
          if (window.requestIdleCallback) requestIdleCallback(go, { timeout: 5000 }); else setTimeout(go, 3000);
        }
      });
    });
    window.addEventListener('online', function () { send(); render(); });
    window.addEventListener('offline', render);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
