<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaImageDefaults as Defaults;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaImageDefaultsTest extends TestCase
{
    private const SCOPE = ['tenant_id' => 2000000721, 'user_id' => 7];

    private function image(string $id, string $quality = '1k'): array
    {
        return ['model_id' => $id, 'id' => $id, 'name' => $id, 'resolution' => $quality, 'quality' => $quality];
    }

    public function testRequestDefaultsNeverReplaceExplicitTopLevelOrNestedModels(): void
    {
        $settings = ['image' => $this->image('gpt')];
        self::assertSame('gpt', Defaults::request([], $settings)['model_id']);
        self::assertSame('1k', Defaults::request([], $settings)['quality']);
        foreach (['model_id', 'image_model_id', 'channel', 'channel_code', 'model_code'] as $key) {
            $params = [$key => 'nano', 'quality' => '4k'];
            self::assertSame($params, Defaults::request($params, $settings));
            self::assertSame(['params' => $params], Defaults::request(['params' => $params], $settings));
        }
        self::assertSame('2k', Defaults::request(['quality' => '2k'], $settings)['quality']);
        self::assertSame([], Defaults::request([], []));
    }

    public function testAlreadyCreatedAndFutureEpisodesInheritFirstModelWithoutChangingRatioOrVideo(): void
    {
        $this->transaction(function () {
            [$parent, $first, $second] = $this->series();
            $saved = Service::saveProjectGenerationSettings(self::SCOPE['tenant_id'], self::SCOPE['user_id'], [
                'project_id' => $first, 'generation_settings' => ['image' => $this->image('gpt', '2k')],
            ]);
            self::assertSame('gpt', $saved['generation_settings']['image']['model_id']);
            foreach ([$second, $this->child($parent, 500)] as $id) {
                $settings = $this->settings($id);
                self::assertSame('gpt', $settings['image']['model_id']);
                self::assertSame('2k', $settings['image']['resolution']);
                self::assertSame('9:16', $settings['image']['ratio']);
                self::assertSame('video-local', $settings['video']['model_id']);
                // Reading does not rewrite the child or any historical task.
                self::assertSame('nano', json_decode(Db::name('aigc_short_drama_project')->where('id', $id)->value('generation_settings_json'), true)['image']['model_id']);
            }
        });
    }

    public function testExplicitEpisodeOverrideSurvivesFirstEpisodeChangesAndDoesNotAffectNextEpisode(): void
    {
        $this->transaction(function () {
            [$parent, $first, $second] = $this->series('gpt');
            Service::saveProjectGenerationSettings(self::SCOPE['tenant_id'], self::SCOPE['user_id'], [
                'project_id' => $second, 'generation_settings' => ['image' => $this->image('other', '4k')],
            ]);
            Service::saveProjectGenerationSettings(self::SCOPE['tenant_id'], self::SCOPE['user_id'], [
                'project_id' => $first, 'generation_settings' => ['image' => $this->image('gpt', '2k')],
            ]);
            self::assertSame('other', $this->settings($second)['image']['model_id']);
            self::assertSame('4k', $this->settings($second)['image']['resolution']);
            self::assertSame('gpt', $this->settings($this->child($parent, 3))['image']['model_id']);
        });
    }

    public function testSavingUnchangedInheritedChoiceDoesNotPinAutomaticDefaults(): void
    {
        $this->transaction(function () {
            [, $first, $second] = $this->series('gpt');
            Service::saveProjectGenerationSettings(self::SCOPE['tenant_id'], self::SCOPE['user_id'], [
                'project_id' => $second, 'generation_settings' => ['image' => $this->image('gpt')],
            ]);
            self::assertFalse($this->settings($second)['image_selection_explicit']);
            Service::saveProjectGenerationSettings(self::SCOPE['tenant_id'], self::SCOPE['user_id'], [
                'project_id' => $first, 'generation_settings' => ['image' => $this->image('new-gpt')],
            ]);
            self::assertSame('new-gpt', $this->settings($second)['image']['model_id']);
        });
    }

    public function testLegacyUsedModelsAndStandaloneProjectsArePreserved(): void
    {
        $this->transaction(function () {
            [, , $second] = $this->series('gpt');
            Db::name('aigc_short_drama_generation_task')->insert(self::SCOPE + [
                'project_id' => $second, 'task_id' => 'image_default_' . bin2hex(random_bytes(8)),
                'task_type' => 'shot_image', 'status' => 'success', 'request_json' => '{"model_id":"nano"}',
            ]);
            self::assertSame('nano', $this->settings($second)['image']['model_id']);
            $single = $this->project('standalone');
            self::assertSame('standalone', $this->settings($single)['image']['model_id']);
        });
    }

    public function testDefaultsCannotCrossTenantUserOrDeletedSourceBoundaries(): void
    {
        $this->transaction(function () {
            [, $first, $second] = $this->series('gpt');
            foreach ([['tenant_id' => 2000000722], ['user_id' => 8], ['delete_time' => time()]] as $change) {
                Db::name('aigc_short_drama_project')->where('id', $first)->update($change);
                self::assertSame('nano', $this->settings($second)['image']['model_id']);
                Db::name('aigc_short_drama_project')->where('id', $first)->update(self::SCOPE + ['delete_time' => 0]);
            }
        });
    }

    private function transaction(callable $test): void
    {
        if (getenv('SHORT_DRAMA_DB_TESTS') !== '1') self::markTestSkipped('Opt-in transactional DB test; no provider calls');
        (new \think\App())->initialize();
        Db::startTrans();
        try { $test(); } finally { Db::rollback(); }
    }

    private function project(string $model): int
    {
        return Db::name('aigc_short_drama_project')->insertGetId(self::SCOPE + ['ratio' => '9:16', 'generation_settings_json' => json_encode([
            'ratio' => '9:16', 'image' => $this->image($model), 'video' => ['model_id' => 'video-local'],
        ])]);
    }

    private function child(int $parent, int $number, string $model = 'nano'): int
    {
        $id = $this->project($model);
        Db::name('aigc_short_drama_episode_task')->insert(self::SCOPE + ['project_id' => $parent, 'episode_number' => $number, 'production_project_id' => $id]);
        return $id;
    }

    private function series(string $model = 'nano'): array
    {
        $parent = $this->project('nano');
        return [$parent, $this->child($parent, 1, $model), $this->child($parent, 2)];
    }

    private function settings(int $id): array
    {
        $row = Db::name('aigc_short_drama_project')->where(self::SCOPE + ['id' => $id])->find();
        $method = new \ReflectionMethod(Service::class, 'projectGenerationSettingsFromRow');
        $method->setAccessible(true);
        return $method->invoke(null, $row);
    }
}
