// Optional app upgrades keep their own future-install migration, while this
// system step executes the exact same bounded capacity intent in its core SQL.
const optionalApps = new Set(['aigc_action_transfer', 'aigc_person_replacement', 'aigc_watermark_removal'])
export const snapshotCoreSql = 'sql/structure/20261103_generation_snapshot_capacity.sql'
export const historyIndexCoreSql = 'sql/structure/20261114_query_performance_indexes.sql'
export function assertHistoryIndexMigrationCoverage(code, source, appSql, coreSql) {
    if (!/^[a-z][a-z0-9_]+$/.test(code) || source !== `app/apps/${code}/migrations/20261114_owner_history_indexes.sql`) throw new Error('Unreviewed history index migration: ' + source)
    if (!/^-- likeadmin:query-performance-indexes-v1\s*$/m.test(coreSql)) throw new Error('Missing guarded system index executor')
    const statements = sql => sql.replace(/--[^\r\n]*/g, '').split(';').map(s => s.trim()).filter(Boolean)
    const intent = statement => {
        const match = statement.match(/^ALTER\s+TABLE\s+`la_([a-z0-9_]+)`\s+ADD\s+INDEX\s+`idx_owner_history_page`\s*\(\s*`tenant_id`\s*,\s*`user_id`\s*,\s*`delete_time`\s*,\s*`id`\s*\)$/i)
        return match?.[1]?.toLowerCase()
    }
    const allowed = new Set([code+'_task',code+'_result', ...(code === 'aigc_short_drama' ? [code+'_episode_task',code+'_generation_task'] : [])])
    const core = new Set(statements(coreSql).map(intent).filter(Boolean))
    const app = statements(appSql).map(statement => { const table = intent(statement); if (!table || !allowed.has(table) || !core.has(table)) throw new Error('System index does not cover exact app intent: ' + source); return table })
    if (!app.length || app.length > 2 || new Set(app).size !== app.length) throw new Error('Invalid app index intent count')
}
export function assertSnapshotMigrationCoverage(code, source, appSql, coreSql) {
    if (!optionalApps.has(code) || source !== `app/apps/${code}/migrations/upgrade_20261103_generation_snapshot_capacity_${code}.sql`) throw new Error('Optional app migration requires explicit system coverage: ' + source)
    const intents = sql => sql.replace(/--[^\r\n]*/g, '').split(';').map(s => s.trim()).filter(Boolean).map(statement => {
        const match = statement.match(/^ALTER\s+TABLE\s+`?la_([a-z0-9_]+)`?\s+MODIFY\s+(?:COLUMN\s+)?`?([a-z0-9_]+)`?\s+MEDIUMTEXT$/i)
        if (!match) throw new Error('Unreviewed snapshot capacity intent')
        return match[1].toLowerCase() + '.' + match[2].toLowerCase()
    })
    const app = intents(appSql), core = intents(coreSql)
    if (app.length !== 1 || app[0] !== code + '_task.pricing_snapshot' || !core.includes(app[0])) throw new Error('Core snapshot migration does not cover optional app: ' + code)
}
