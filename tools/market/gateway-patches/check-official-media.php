<?php
/** Offline contract checks. Argument: the patched platform-api-saas server directory. */
declare(strict_types=1);

use app\common\service\ai\official\OfficialRequestService as Requests;
use app\common\service\ai\official\OfficialMaterialContractService as Materials;

$root = realpath($argv[1] ?? '');
if (!$root || !is_file($root.'/resources/official/catalog.json')) {
    fwrite(STDERR, "Usage: php check-official-media.php /path/to/platform-api-saas/server\n");
    exit(2);
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'app\\common\\service\\ai\\official\\';
    if (str_starts_with($class, $prefix)) {
        require $root.'/app/common/service/ai/official/'.substr($class, strlen($prefix)).'.php';
    }
});
function check(bool $ok, string $case): void {
    if (!$ok) throw new RuntimeException($case);
}
foreach (['gpt-image-2', 'gpt-image-2-5-flare', 'gpt-image-2-5-sunburst'] as $model) {
    foreach (['text-to-image', 'image-to-image'] as $mode) {
        $profile = Requests::profile("$model-$mode");
        foreach (['1K','2K','4K'] as $resolution) {
            $params = ['prompt'=>'Local fixture', 'aspect_ratio'=>'1:1', 'resolution'=>$resolution];
            if ($mode === 'image-to-image') $params['input_urls'] = ['https://fixtures.invalid/input.png'];
            foreach ([null,'opaque','transparent'] as $background) {
                $request = $params;
                if ($background !== null) $request['background'] = $background;
                $input = Requests::build($profile, $request)['input'];
                check($resolution === '1K' ? ($input['background'] ?? null) === ($background ?? 'opaque')
                    : !array_key_exists('background', $input), "$model $mode $resolution background");
            }
            $excluded = $model === 'gpt-image-2' ? ['5:4','4:5','3:1','1:3','9:21'] : ['27:16','16:27','9:8','8:9'];
            foreach (array_merge(['1:1'], $excluded) as $ratio) {
                $input = Requests::business($profile, array_replace($params, ['aspect_ratio'=>$ratio]));
                $allowed = $resolution === '1K' || $ratio === '1:1'
                    || ($model === 'gpt-image-2' && $resolution === '4K' && in_array($ratio,['5:4','4:5'],true));
                check((Materials::errors($profile, $input) === []) === $allowed, "$model $mode $resolution $ratio");
            }
        }
    }
}
$wan = Requests::profile('wan/2-7-image-pro');
foreach ([[], ['enable_sequential'=>true], ['input_urls'=>['https://fixtures.invalid/input.png']]] as $condition) {
    $input = Requests::business($wan, ['resolution'=>'4K', 'thinking_mode'=>true] + $condition);
    check(array_key_exists('thinking_mode',$input) === ($condition === []), 'Wan thinking mode');
    check((Materials::errors($wan,$input) === []) === ($condition === []), 'Wan 4K');
}
$grok = Requests::profile('grok-imagine/image-to-video');
foreach (['spicy','normal'] as $mode) {
    $input = Requests::business($grok, ['image_urls'=>['https://fixtures.invalid/input.png'], 'mode'=>$mode]);
    check((Materials::errors($grok,$input) === []) === ($mode === 'normal'), "Grok external image $mode");
}
echo "Official media parameter checks passed\n";
