<?php
// 「作った・使ったレポート」の管理ページ (サイト管理者だけ)。届いた報告を確かめて、公開する・非公開にする・削除する。
//   公開した報告だけが、サイトの report.php (一覧) とトップページの新着に出る。
//   報告に、管理者からのひとこと (お礼など) を付けられる。公開すると報告の下に出る。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
require_once __DIR__ . '/hpx_report.php';
if (empty($ME['admin'])) {
    http_response_code(403);
    exit('このページはサイト管理者だけが開けます。');
}

if (is_post()) {
    $id = (string)($_POST['id'] ?? '');
    $act = (string)($_POST['act'] ?? '');
    if (!r_get($id)) redirect('report_admin.php', 'その報告は見つかりませんでした。');
    if ($act === 'delete') {
        r_delete($id);
        redirect('report_admin.php', '報告を削除しました (写真も消しました)。');
    }
    $st = ['publish' => 'public', 'hide' => 'hidden'][$act] ?? '';
    $reply = mb_substr(trim((string)($_POST['reply'] ?? '')), 0, R_REPLY_MAX);
    r_update(function (array $l) use ($id, $act, $st, $reply) {
        if (!isset($l[$id])) return $l;
        if ($st !== '') { $l[$id]['status'] = $st; $l[$id]['decided'] = time(); }
        if ($act === 'reply') $l[$id]['reply'] = $reply;
        return $l;
    });
    redirect('report_admin.php#' . $id, ['publish' => '公開しました。', 'hide' => '非公開にしました。', 'reply' => 'ひとことを保存しました。'][$act] ?? '');
}

page_head('作った・使ったレポートの管理 - ' . S_TITLE, 'admin.php');
echo '<h1>作った・使ったレポートの管理</h1>' . flash_html()
    . '<p class="muted">「公開する」を押した報告だけが、サイトの <a href="/report.php">作った・使ったレポート</a> とトップページに出ます。'
    . '顔・車のナンバー・蜂場の場所がわかるものが写っていないか確かめてから公開してください。メールアドレスは公開されません。</p>';

$list = r_sorted();
$groups = ['pending' => [], 'public' => [], 'hidden' => []];
foreach ($list as $id => $r) $groups[$r['status'] ?? 'pending'][$id] = $r;

foreach ($groups as $st => $rs) {
    echo '<h2>' . h(R_STATUS[$st]) . ' (' . count($rs) . ')</h2>';
    if (!$rs) { echo '<p class="muted">ありません。</p>'; continue; }
    foreach ($rs as $id => $r) {
        $btn = fn(string $act, string $label, string $confirm = '') => '<form class="inl" method="post"'
            . ($confirm !== '' ? ' onsubmit="return window.confirm(\'' . h($confirm) . '\')"' : '') . '>' . csrf_field()
            . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="act" value="' . $act . '"><button type="submit"' . ($act === 'delete' ? ' style="background:#fff;color:#c62828;border-color:#e0b4b4"' : '') . '>' . h($label) . '</button></form> ';
        echo '<div class="card" id="' . h($id) . '">'
            . '<p><b>' . h((string)$r['nick']) . '</b>'
            . ' <span class="muted">' . h(date('Y-m-d H:i', (int)$r['time'])) . '</span></p>'
            . '<p class="muted">作った機器・サーバー・養蜂記録アプリ: ' . h(implode('・', array_map('r_device_label', (array)($r['devices'] ?? [])))) . '</p>'
            . '<p>' . nl((string)$r['comment']) . '</p><div class="mph">';
        for ($n = 1; $n <= (int)($r['photos'] ?? 0); $n++) {
            $u = 'report_img.php?id=' . rawurlencode($id) . '&n=' . $n;
            echo '<a href="' . h($u) . '" target="_blank"><img src="' . h($u . '&s=s') . '" alt="写真' . $n . '"></a>';
        }
        echo '</div>'
            . (($r['email'] ?? '') !== '' ? '<p class="muted">連絡先 (公開しない): ' . h((string)$r['email']) . '</p>' : '')
            . '<details><summary class="muted">管理者からのひとこと' . (($r['reply'] ?? '') !== '' ? ' (あり)' : '') . '</summary><form method="post">' . csrf_field()
            . '<input type="hidden" name="id" value="' . h($id) . '"><input type="hidden" name="act" value="reply">'
            . '<textarea name="reply" maxlength="' . R_REPLY_MAX . '" placeholder="例: 報告ありがとうございます！">' . h((string)($r['reply'] ?? '')) . '</textarea>'
            . '<button type="submit">ひとことを保存</button></form></details><p>'
            . ($st !== 'public' ? $btn('publish', '公開する') : '')
            . ($st !== 'hidden' ? $btn('hide', '非公開にする') : '')
            . $btn('delete', '削除', 'この報告と写真を消します。元に戻せません。よろしいですか？') . '</p></div>';
    }
}
echo '</body></html>';
