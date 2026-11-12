<?php
namespace Tests\Feature;
use app\common\service\database\RuntimeMigrationPlan;
use PHPUnit\Framework\TestCase;

final class StudioMigrationOwnershipTest extends TestCase
{
    public function testUnreleasedStudioMigrationsHaveUniqueArchiveOwners(): void
    {
        $files=[];
        foreach(['aigc_product_video','aigc_motion_sync','aigc_story_commerce'] as $app){
            foreach(glob(dirname(__DIR__,2).'/app/apps/'.$app.'/migrations/20261111_*.sql') as $file){
                self::assertStringEndsWith('_'.$app.'.sql',$file);
                $files[]=$file;
                RuntimeMigrationPlan::build(file_get_contents($file));
            }
        }
        self::assertCount(6,$files);
        self::assertCount(count($files),array_unique(array_map('basename',$files)));
    }
}
