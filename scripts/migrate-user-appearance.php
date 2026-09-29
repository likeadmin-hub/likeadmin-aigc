<?php
/** Explicit migration; no DDL during normal API requests. */
require dirname(__DIR__) . '/vendor/autoload.php';
(new \think\App())->initialize();
$db = \think\facade\Db::connect();
$prefix = $db->getConfig('prefix');
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new \RuntimeException('Invalid database prefix');
$sql = str_replace('`la_user_appearance`', '`' . $prefix . 'user_appearance`', file_get_contents(dirname(__DIR__) . '/upgrade/20260930_user_appearance.sql'));
if (in_array('--apply', $argv, true)) { $db->execute($sql); echo "User appearance migration complete\n"; }
else echo $sql;
