<?php
// オフラインのページ (設定メニューの「オフライン」)。電波が無い所で HPX-note を使うための説明と、
// 送信待ちの一覧・まとめて保存のボタン。中身の動きは js/hpx_offline.js と sw.js (この端末の中だけで動く)
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

page_head('オフライン - ' . S_TITLE, 'offline.php');
?>
<style>
#ofl-net{font-weight:bold}#ofl-q li{margin:4px 0}#ofl-q button{font-size:.85em;padding:2px 8px;margin-left:6px}
#ofl-pages li{margin:2px 0}#ofl-pages small,#ofl-q small{color:#777}.ofl-btns{display:flex;flex-wrap:wrap;gap:8px;margin:.4em 0}
</style>
<p class="muted">電波の無い蜂場でも、メモ・作業記録・タスクを書いたり、前に見たページを見たりできます。</p>

<div class="card"><p style="margin:0">いまの状態: <span id="ofl-net">確かめています…</span></p>
<p id="ofl-nojs" class="ng" style="margin:.4em 0 0">このブラウザでは、オフラインのしくみが使えません。新しい Safari・Chrome などで開いてください。</p></div>

<h2>送信待ち</h2>
<p class="muted">電波が無いときに保存したものです。電波のある所で HPX-note を開くと、自動で送ります。</p>
<ul id="ofl-q"><li class="muted">ありません。</li></ul>
<div class="ofl-btns"><button type="button" id="ofl-send">今すぐ送る</button></div>

<h2>この端末に保存してあるページ</h2>
<p class="muted">一度開いたページは自動で保存されます。主なページは6時間おきに自動で保存し直します。
「まとめて保存」を押すと、各蜂場・巣箱・センサのページと、メモの過去のページもまとめて保存します (数分かかることがあり、通信量も使います。Wi-Fi のある所がおすすめです)。</p>
<p>最後にまとめて保存した日時: <b id="ofl-last">-</b></p>
<div class="ofl-btns"><button type="button" id="ofl-crawl">まとめて保存</button> <button type="button" id="ofl-clear" class="link">保存してあるページを消す</button></div>
<p id="ofl-prog" class="muted"></p>
<details><summary>保存してあるページの一覧 (<span id="ofl-n">0</span>)</summary><ul id="ofl-pages"></ul></details>

<h2>使い方と注意</h2>
<div class="card">
<ul>
<li><b>スマホでは「ホーム画面に追加」して使うのがおすすめです。</b>iPhone は Safari の共有ボタン → 「ホーム画面に追加」、Android は Chrome のメニュー → 「ホーム画面に追加」(または「アプリをインストール」)。ホーム画面から開いたときは、最初に一度ログインしてください。</li>
<li><b>iPhone の Safari で開いているだけだと、しばらく (7日ほど) 開かない間に保存したページが消えることがあります。</b>ホーム画面に追加すると消えにくくなります。</li>
<li>iPhone は、HPX-note を閉じている間には送れません。電波のある所で、もう一度 HPX-note を開いたときに送ります。</li>
<li>電波が無い間に書いたメモ・記録は、送るまで一覧には出ません。上の「送信待ち」で確かめられます。</li>
<li>送るときは、サーバーがログインと権限 (チームの役割) を確かめます。ログインが切れていたら、ログインし直すと送ります。</li>
<li>電波が無いときにできるのは、メモ・作業記録・タスクの保存 (新しく書く・変える・完了にする) です。削除や設定の変更、地図・天気予報・カメラはできません。</li>
<li>センサのグラフは、保存したときの内容です。最新のデータは電波のある所で見てください。</li>
<li>ログアウトすると、この端末に保存したページと送信待ちは消えます。家族などと同じ端末を使うときは、ログアウトしてください。</li>
</ul>
</div>

<script>
(function () {
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function fmt(t) { var d = new Date(t); return (d.getMonth() + 1) + '月' + d.getDate() + '日 ' + d.getHours() + ':' + ('0' + d.getMinutes()).slice(-2); }
  function $(id) { return document.getElementById(id); }
  function net() { $('ofl-net').textContent = navigator.onLine ? 'オンライン (電波あり)' : 'オフライン (電波なし)'; $('ofl-net').className = navigator.onLine ? 'ok' : 'warn'; }
  function pages() {
    var last = Number(localStorage.getItem('hpx-crawl') || 0);
    $('ofl-last').textContent = last ? fmt(last) : 'まだありません';
    caches.open('hpx-pages').then(function (c) {
      return c.keys().then(function (ks) {
        return Promise.all(ks.map(function (k) { return c.match(k).then(function (r) { return { u: k.url, t: decodeURIComponent(r.headers.get('X-HPX-Title') || ''), s: Number(r.headers.get('X-HPX-Saved')) }; }); }));
      });
    }).then(function (a) {
      a.sort(function (x, y) { return x.t < y.t ? -1 : x.t > y.t ? 1 : 0; });
      $('ofl-n').textContent = a.length;
      $('ofl-pages').innerHTML = a.map(function (x) {
        var u = new URL(x.u); return '<li><a href="' + esc(x.u) + '">' + esc(x.t || u.pathname) + '</a> <small>' + esc(u.search) + ' ' + esc(fmt(x.s)) + '</small></li>';
      }).join('');
    });
  }
  function go() {
    var H = window.HPX_OFFLINE;
    if (!H) return setTimeout(go, 100);
    $('ofl-nojs').style.display = 'none';
    net(); pages();
    window.addEventListener('online', net); window.addEventListener('offline', net);
    H.onchange = function (q) {
      $('ofl-q').innerHTML = q.length ? q.map(function (x) {
        var photos = x.f.filter(function (f) { return f.length > 2; }).length;
        return '<li>' + esc(x.label) + (photos ? ' (写真 ' + photos + ' 枚)' : '') + ' <small>' + esc(fmt(x.at)) + ' に保存</small>'
          + '<button type="button" data-oid="' + esc(x.oid) + '">取り消す</button></li>';
      }).join('') : '<li class="muted">ありません。</li>';
      $('ofl-send').disabled = !q.length;
    };
    H.render();
    $('ofl-q').onclick = function (e) {
      var oid = e.target.getAttribute('data-oid');
      if (oid && confirm('この送信待ちを取り消しますか? (この端末から消えます)')) H.drop(oid);
    };
    $('ofl-send').onclick = function () {
      if (!navigator.onLine) { alert('電波がありません。電波のある所でもう一度押してください。'); return; }
      H.send();
    };
    $('ofl-crawl').onclick = function () {
      if (!navigator.onLine) { alert('電波がありません。電波のある所で押してください。'); return; }
      var b = this; b.disabled = true;
      H.crawl(true, function (d, n) { $('ofl-prog').textContent = '保存しています… ' + d + ' / ' + n + ' ページ'; })
        .then(function (n) { $('ofl-prog').textContent = n + ' ページを保存しました。'; b.disabled = false; pages(); });
    };
    $('ofl-clear').onclick = function () {
      if (confirm('この端末に保存してあるページを消しますか? (送信待ちは消えません)')) H.clearSaved().then(pages);
    };
  }
  if ('serviceWorker' in navigator && window.caches && window.indexedDB) go();
})();
</script>
</body></html>
