import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import { assertHistoryIndexMigrationCoverage as check } from '../../scripts/snapshot-migration-coverage.mjs'
const code='aigc_action_transfer', source=`app/apps/${code}/migrations/20261114_owner_history_indexes.sql`
const app=fs.readFileSync(new URL('../../'+source,import.meta.url),'utf8')
const core=fs.readFileSync(new URL('../../upgrade/20261114_query_performance_indexes.sql',import.meta.url),'utf8')
test('optional and builtin app history intents are covered by the same guarded migration',()=>{
 const root=new URL('../../app/apps/',import.meta.url)
 let count=0
 for(const appCode of fs.readdirSync(root)){
  const rel=`app/apps/${appCode}/migrations/20261114_owner_history_indexes.sql`, file=new URL('../../'+rel,import.meta.url)
  if(fs.existsSync(file)){check(appCode,rel,fs.readFileSync(file,'utf8'),core);count++}
 }
 assert.ok(count>=24)
})
test('rejects cross-app SQL, altered column order, extra operations and missing guarded core intent',()=>{
 for(const sql of [app.replaceAll('la_aigc_action_transfer','la_aigc_person_replacement'), app.replaceAll('`user_id`,`delete_time`','`delete_time`,`user_id`'),app+'DELETE FROM la_user;',app+'SELECT 1;',app+app])assert.throws(()=>check(code,source,sql,core))
 assert.throws(()=>check(code,source,app,core.replace('-- likeadmin:query-performance-indexes-v1','-- unguarded')))
 assert.throws(()=>check(code,source,app,core.replaceAll('la_aigc_action_transfer','la_other')))
 assert.throws(()=>check(code,source.replace('20261114','20261115'),app,core))
})
