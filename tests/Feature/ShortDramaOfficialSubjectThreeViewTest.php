<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;

class ShortDramaOfficialSubjectThreeViewTest extends TestCase
{
    public function testOptionalUploadRoundTripAndRemoval(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional fixture opt-in');
        (new \think\App())->initialize();
        Db::startTrans();
        try {
            $params = ['name' => '测试主体', 'category' => 'character'];
            $row = Service::saveAdminSubject(2000000719, $params);
            self::assertSame('', $row['three_view_url']);
            $params['id'] = $row['id'];
            $params['three_view_image'] = '/fixture/three.png';
            $row = Service::saveAdminSubject(2000000719, $params);
            self::assertSame('/fixture/three.png', $row['raw_three_view_image']);
            self::assertStringContainsString('/fixture/three.png', $row['three_view_url']);
            $format = new \ReflectionMethod(Service::class, 'formatSubjectLibrary'); $format->setAccessible(true);
            $stored = Db::name('aigc_short_drama_subject')->where('id', $row['id'])->find();
            self::assertSame($row['three_view_url'], $format->invoke(null, $stored)['three_view_url']);
            unset($params['three_view_image']);
            self::assertSame('/fixture/three.png', Service::saveAdminSubject(2000000719, $params)['raw_three_view_image']);
            $params['three_view_image'] = '';
            self::assertSame('', Service::saveAdminSubject(2000000719, $params)['three_view_url']);
        } finally { Db::rollback(); }
    }
}
