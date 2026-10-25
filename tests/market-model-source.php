<?php
require getenv('MARKET_MODEL_SOURCE_FILE') ?: dirname(__DIR__).'/app/common/service/power/MarketModelSource.php';
use app\common\service\power\MarketModelSource as S;
foreach ([['official','official'],['OpenAIYI','third_party'],['minimax','third_party'],['','unknown']] as [$code,$expected]) {
    if (S::channel($code)['channel_source'] !== $expected) throw new RuntimeException('Incorrect source: '.$code);
}
if (S::product(['name'=>'官方 GPT','upstream_channel_code'=>'relay'])['channel_source'] !== 'third_party') throw new RuntimeException('Brand name asserted official ownership');
if (S::product(['name'=>'Third party','upstream_channel_code'=>'official'])['channel_source'] !== 'official') throw new RuntimeException('Upstream channel identity lost');
echo "PASS authoritative upstream channel classification; names and provider brands do not assert official ownership\n";
