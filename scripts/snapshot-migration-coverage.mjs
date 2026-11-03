// Optional app upgrades keep their own future-install migration, while this
// system step executes the exact same bounded capacity intent in its core SQL.
const optionalApps = new Set(['aigc_action_transfer', 'aigc_person_replacement', 'aigc_watermark_removal'])
export const snapshotCoreSql = 'sql/structure/20261103_generation_snapshot_capacity.sql'
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
