<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use app\common\service\app\aigc_short_drama\ShortDramaCanvasService;
use app\common\service\database\SqlMigrationExecutor as Sql;
use think\facade\Db;

/** P6 local-only source/install parity and backwards-read evidence. */
function bindingDdl(string $path): string
{
    foreach (Sql::split((string)file_get_contents($path)) as $statement) {
        if (str_starts_with($statement, 'CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_binding`')) return $statement;
    }
    throw new RuntimeException('canvas binding DDL missing from ' . $path);
}
function compactSql(string $statement): string
{
    return preg_replace('/\s+/', ' ', trim($statement)) ?: '';
}

$appInstall = bindingDdl(dirname(__DIR__, 2) . '/migrations/install.sql');
$fullInstall = bindingDdl(root_path() . 'public/install/db/like.sql');
$upgrade = bindingDdl(dirname(__DIR__, 2) . '/migrations/upgrade_20260922_canvas_binding.sql');
agentCheck(compactSql($appInstall) === compactSql($upgrade), 'O01 app install and upgrade binding schemas are identical');
agentCheck(compactSql($fullInstall) === compactSql($upgrade), 'O01 full install snapshot and upgrade binding schemas are identical');

// Re-run the exact source upgrade on the existing local schema. CREATE IF NOT
// EXISTS is the upgrade's intended idempotence contract and changes no row.
Db::execute($upgrade . ';');
Db::execute($upgrade . ';');
$columns = Db::query("SHOW COLUMNS FROM `la_aigc_short_drama_canvas_binding`");
$indexes = Db::query("SHOW INDEX FROM `la_aigc_short_drama_canvas_binding`");
agentCheck(count($columns) === 10, 'O02 repeated local binding migration preserves expected columns');
agentCheck(count(array_filter($indexes, static fn(array $index): bool => $index['Key_name'] === 'uk_canvas')) === 3, 'O02 repeated local binding migration preserves scoped unique index');

// A legacy graph row has no binding and must remain readable byte-for-byte at
// the JSON document boundary. The fixture is rolled back after the read.
Db::startTrans();
try {
    $nodes = '[{"id":"legacy-text","type":"text","metadata":{"content":"旧画布内容"}}]';
    $edges = '[{"from":"legacy-text","to":"legacy-text-note","type":"annotation"}]';
    $viewport = '{"x":-88,"y":42,"k":0.75}';
    $id = Db::name('aigc_short_drama_canvas')->insertGetId([
        'tenant_id' => 91660, 'user_id' => 92660, 'title' => 'P6 legacy JSON',
        'nodes_json' => $nodes, 'edges_json' => $edges, 'viewport_json' => $viewport,
        'removed_node_ids_json' => '[]', 'graph_revision' => 0, 'schema_version' => 1,
        'create_time' => time(), 'update_time' => time(), 'delete_time' => 0,
    ]);
    $loaded = ShortDramaCanvasService::current(91660, 92660, $id);
    agentCheck(json_encode($loaded['nodes'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $nodes, 'O03 old node JSON remains readable without normalization loss');
    agentCheck(json_encode($loaded['edges'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $edges, 'O03 old edge JSON remains readable without normalization loss');
    agentCheck(json_encode($loaded['viewport'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) === $viewport, 'O03 old viewport JSON remains readable without normalization loss');
} finally {
    Db::rollback();
}

echo "NOT_RUN O04-O10: feature-toggle history, trace redaction, event reconnect, load percentiles, real model and rollback require their dedicated local scenarios; no Provider was called.\n";
