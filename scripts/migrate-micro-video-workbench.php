<?php
// Retain the deployed command path without restoring the retired workbench.
$message = "微视频已退役，历史迁移仅供归档；未执行任何迁移。\n";
if (PHP_SAPI === 'cli') { fwrite(STDERR, $message); exit(1); }
http_response_code(410);
echo $message;
