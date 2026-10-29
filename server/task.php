<?php
// タスクページ: これからの作業(タスク)。中身は work.php にあり(保存の処理も共通)、ここは表示をタスクにするだけ。
//   work.php は済んだ作業の記録(かかった時間)を出す
declare(strict_types=1);
const W_TASK_PAGE = true;
require __DIR__ . '/work.php';
