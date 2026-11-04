import test from 'node:test'
import assert from 'node:assert/strict'
import { assertSnapshotMigrationCoverage } from '../../scripts/snapshot-migration-coverage.mjs'
const code='aigc_action_transfer'
const source=`app/apps/${code}/migrations/upgrade_20261103_generation_snapshot_capacity_${code}.sql`
const sql='ALTER TABLE `la_aigc_action_transfer_task` MODIFY COLUMN `pricing_snapshot` MEDIUMTEXT;'
test('optional snapshot migration is covered only by the same exact core intent',()=>{
    assert.doesNotThrow(()=>assertSnapshotMigrationCoverage(code,source,sql,sql+'\nALTER TABLE la_ai_consumption_log MODIFY price_snapshot MEDIUMTEXT;'))
    assert.throws(()=>assertSnapshotMigrationCoverage(code,source,sql,'ALTER TABLE la_ai_consumption_log MODIFY price_snapshot MEDIUMTEXT;'))
})
test('optional coverage cannot hide unrelated apps, SQL or alternate filenames',()=>{
    for(const [c,p,a,b]of [['other',source,sql,sql],[code,source.replace('20261103','20261104'),sql,sql],[code,source,sql+' DROP TABLE la_user;',sql],[code,source,'ALTER TABLE la_aigc_action_transfer_task MODIFY prompt MEDIUMTEXT;',sql]])assert.throws(()=>assertSnapshotMigrationCoverage(c,p,a,b))
})
