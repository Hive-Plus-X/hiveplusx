<?php
// センサページは sensor.php に名前を変えた。前のブックマークのためにそちらへ移す(機器や期間の指定はそのまま渡す)。
$q = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: sensor.php' . ($q !== '' ? '?' . $q : ''), true, 301);
exit;
