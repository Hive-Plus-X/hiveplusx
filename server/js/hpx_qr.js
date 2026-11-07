// 巣箱のQRコードラベルを作る (巣箱ページの「QR」マークから使う)。
//   ラベルは 50mm 角: 上に QR コード、下に巣箱名 (大きく)・蜂場名と hive+x (小さく)。
//   PNG はラベル1枚 (600dpi = 1181px 角)。PDF は A4 に 3列×5段 = 15枚、切り取り用のうすい点線つき。
//   文字はブラウザの日本語フォントで描くので、サーバーにフォントもライブラリも要らない。
//   QR コードは qrcode.js (Kazuhiko Arase、MIT ライセンス) で作る。
(function () {
  'use strict';
  var DPI = 600;
  var MM = DPI / 25.4;                 // 1mm の点の数
  var SIZE = Math.round(50 * MM);      // ラベルの1辺 (1181px)
  // 日本の字形のフォントを先に並べる (中国語の字形のフォントで描かないように)。
  //   Mac・iPhone=ヒラギノ、Windows=メイリオ・游ゴシック、Android=Noto Sans CJK JP、Linux など=IPA・Noto
  var FONT = "'Hiragino Kaku Gothic ProN','Meiryo','Yu Gothic','YuGothic','BIZ UDPGothic','Noto Sans JP','Noto Sans CJK JP','IPAexGothic','IPAPGothic','IPAGothic',sans-serif";
  var GREEN = '#2F5D45';

  // ラベル1枚を canvas に描く。h = {name, apiary, url}
  function label(h) {
    var c = document.createElement('canvas');
    c.width = SIZE; c.height = SIZE;
    c.setAttribute('lang', 'ja');
    var g = c.getContext('2d');
    if ('lang' in g) g.lang = 'ja';    // 日本の字形で描く (対応しているブラウザだけ)
    g.fillStyle = '#fff';
    g.fillRect(0, 0, SIZE, SIZE);

    // QR コード (誤り訂正 Q = 約25%まで汚れても読める。屋外向け)
    var q = qrcode(0, 'Q');
    q.addData(h.url);
    q.make();
    var n = q.getModuleCount();
    var box = 33 * MM;                 // QR の幅 (余白を含めない)
    var cell = Math.floor(box / n);    // 1マスを整数の点にして、にじまないようにする
    var w = cell * n;
    var x0 = Math.round((SIZE - w) / 2), y0 = Math.round(3.2 * MM + (box - w) / 2);
    g.fillStyle = '#000';
    for (var r = 0; r < n; r++) for (var k = 0; k < n; k++) {
      if (q.isDark(r, k)) g.fillRect(x0 + k * cell, y0 + r * cell, cell, cell);
    }

    // 巣箱名 (大きく、太字。入らなければ小さくし、それでも入らなければ … で切る)
    g.textAlign = 'center';
    g.textBaseline = 'alphabetic';
    var maxW = 46 * MM;
    fitText(g, h.name, 'bold', 6.2 * MM, 3.4 * MM, maxW);
    g.fillStyle = '#000';
    g.fillText(fitStr, SIZE / 2, 43.4 * MM);

    // 蜂場名 (左) と hive+x (右)
    g.textAlign = 'left';
    fitText(g, h.apiary, '', 2.8 * MM, 2.2 * MM, 33 * MM);
    g.fillStyle = '#555';
    g.fillText(fitStr, 2.5 * MM, 47.8 * MM);
    g.textAlign = 'right';
    g.font = 'bold ' + Math.round(2.6 * MM) + 'px ' + FONT;
    g.fillStyle = GREEN;
    g.fillText('hive+x', SIZE - 2.5 * MM, 47.8 * MM);
    return c;
  }

  // 文字を幅に合わせる。結果は fitStr に入れ、g.font も決める
  var fitStr = '';
  function fitText(g, s, weight, big, small, maxW) {
    s = String(s || '');
    for (var px = big; px >= small; px -= 0.2 * MM) {
      g.font = (weight ? weight + ' ' : '') + Math.round(px) + 'px ' + FONT;
      if (g.measureText(s).width <= maxW) { fitStr = s; return; }
    }
    var a = Array.from(s);
    while (a.length > 1 && g.measureText(a.join('') + '…').width > maxW) a.pop();
    fitStr = a.join('') + '…';
  }

  function fileSafe(s) { return String(s).replace(/[\\\/:*?"<>|\s]+/g, '_').slice(0, 40); }

  function save(blob, name) {
    var u = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = u; a.download = name;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(u); }, 10000);
  }

  function toBlob(c, type, qual) {
    return new Promise(function (ok) { c.toBlob(ok, type, qual); });
  }

  // ---- PNG (巣箱1つ) ----
  function png(h) {
    return toBlob(label(h), 'image/png').then(function (b) {
      save(b, 'QR_' + fileSafe(h.apiary) + '_' + fileSafe(h.name) + '.png');
    });
  }

  // ---- PDF (A4 に 15 枚ずつ) ----
  // ラベルは JPEG にして貼る (PDF の中身を自分で組み立てる。外部のライブラリは使わない)
  var PT = 72 / 25.4;   // 1mm = 2.835pt
  var COLS = 3, ROWS = 5, GAP_X = 8, GAP_Y = 5;
  var MX = (210 - COLS * 50 - (COLS - 1) * GAP_X) / 2, MY = (297 - ROWS * 50 - (ROWS - 1) * GAP_Y) / 2;

  function pdf(list, filename) {
    var jobs = list.map(function (h) {
      return toBlob(label(h), 'image/jpeg', 0.95).then(function (b) { return b.arrayBuffer(); });
    });
    return Promise.all(jobs).then(function (imgs) {
      var enc = new TextEncoder();
      var parts = [], len = 0, offs = [];
      function put(x) { var u = typeof x === 'string' ? enc.encode(x) : new Uint8Array(x); parts.push(u); len += u.length; }
      function obj(i, body) { offs[i] = len; put(i + ' 0 obj\n'); body(); put('\nendobj\n'); }
      put('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');
      var per = COLS * ROWS, pages = Math.ceil(imgs.length / per) || 1;
      // 番号: 1=Catalog 2=Pages、ページごとに Page と Contents、そのあと画像
      var pageNo = function (p) { return 3 + p * 2; };
      var imgNo = function (i) { return 3 + pages * 2 + i; };
      obj(1, function () { put('<< /Type /Catalog /Pages 2 0 R >>'); });
      var kids = [];
      for (var p = 0; p < pages; p++) kids.push(pageNo(p) + ' 0 R');
      obj(2, function () { put('<< /Type /Pages /Kids [' + kids.join(' ') + '] /Count ' + pages + ' >>'); });
      var f = function (v) { return (Math.round(v * 100) / 100).toString(); };
      for (p = 0; p < pages; p++) {
        var xo = [], cs = '';
        for (var j = 0; j < per; j++) {
          var i = p * per + j;
          if (i >= imgs.length) break;
          var col = j % COLS, row = Math.floor(j / COLS);
          var x = (MX + col * (50 + GAP_X)) * PT, y = (297 - MY - (row + 1) * 50 - row * GAP_Y) * PT, s = 50 * PT;
          xo.push('/Im' + i + ' ' + imgNo(i) + ' 0 R');
          cs += 'q ' + f(s) + ' 0 0 ' + f(s) + ' ' + f(x) + ' ' + f(y) + ' cm /Im' + i + ' Do Q\n';
          // 切り取り線 (うすい灰色の点線)
          cs += 'q 0.75 G 0.4 w [2 2] 0 d ' + f(x) + ' ' + f(y) + ' ' + f(s) + ' ' + f(s) + ' re S Q\n';
        }
        obj(pageNo(p), function () {
          put('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + f(210 * PT) + ' ' + f(297 * PT) + '] /Resources << /XObject << '
            + xo.join(' ') + ' >> >> /Contents ' + (pageNo(p) + 1) + ' 0 R >>');
        });
        obj(pageNo(p) + 1, function () {
          put('<< /Length ' + enc.encode(cs).length + ' >>\nstream\n' + cs + 'endstream');
        });
      }
      imgs.forEach(function (b, i) {
        obj(imgNo(i), function () {
          put('<< /Type /XObject /Subtype /Image /Width ' + SIZE + ' /Height ' + SIZE
            + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + b.byteLength + ' >>\nstream\n');
          put(b);
          put('\nendstream');
        });
      });
      var n = imgNo(imgs.length);
      var xref = len;
      var t = 'xref\n0 ' + n + '\n0000000000 65535 f \n';
      for (var k = 1; k < n; k++) t += String(offs[k]).padStart(10, '0') + ' 00000 n \n';
      put(t + 'trailer\n<< /Size ' + n + ' /Root 1 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');
      save(new Blob(parts, { type: 'application/pdf' }), filename);
    });
  }

  // 巣箱ページのボタンから呼ぶ
  window.hpxQr = {
    label: label,
    png: png,
    pdf: pdf,
    preview: function (img, h) { img.src = label(h).toDataURL('image/png'); }
  };
})();
