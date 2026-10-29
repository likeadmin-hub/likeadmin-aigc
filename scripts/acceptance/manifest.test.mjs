import test from 'node:test'
import assert from 'node:assert/strict'
import { buildManifest, buildCaseMatrix, summarizeCoverage } from './manifest.mjs'

const catalog = [{ id: 1, name: 'Same', model_type: 'text', status: 1 }, { id: 2, name: 'Same', model_type: 'text', status: 1 }, { id: 3, name: 'Unavailable', model_type: 'text', status: 1 }]
const tenant = [{ product_id: 1, id: '1', channel_code: 'official', protocol: 'openai_responses', market_input_sku_id: 10, market_output_sku_id: 11 }, { product_id: 2, id: '2', channel_code: 'third', protocol: 'openai_chat' }]
test('keeps all platform models, distinct same-name routes and unavailable rows', () => {
    const m = buildManifest({ platformModels: catalog, tenantModels: tenant, applications: ['aigc_llm', 'aigc_canvas'] })
    assert.equal(m.models.length, 3)
    assert.equal(new Set(m.models.map(x => x.key)).size, 3)
    assert.equal(m.models[0].input_sku_id, 10)
    assert.equal(m.models[0].output_sku_id, 11)
    assert.equal(m.models[2].state, 'BLOCKED')
    assert.match(m.models[2].reason, /目录/)
})
test('requires every model by every application and does not drop blocked cases', () => {
    const m = buildManifest({ platformModels: catalog, tenantModels: tenant, applications: ['aigc_llm', 'aigc_canvas'] })
    const rows = buildCaseMatrix(m)
    assert.equal(rows.filter(x => x.case_id === 'A01').length, 6)
    assert.equal(rows.filter(x => x.case_id === 'T01').length, 3)
    assert.equal(rows.filter(x => x.case_id === 'M01').length, 1)
    assert.equal(rows.filter(x => x.case_id === 'M02').length, 1)
    assert.equal(rows.find(x => x.model_key === m.models[2].key).state, 'BLOCKED')
})
test('missing results and unsupported PASS evidence never count as passed', () => {
    const m = buildManifest({ platformModels: catalog.slice(0,1), tenantModels: tenant.slice(0,1), applications: ['aigc_llm'] })
    const rows = buildCaseMatrix(m)
    const report = summarizeCoverage(m, [{ ...rows[0], state: 'PASS', evidence: {} }])
    assert.equal(report.complete, false)
    assert.equal(report.counts.PASS, 0)
    assert.equal(report.counts.FAIL, 1)
    assert.equal(report.total, rows.length)
})
test('a PASS needs exact model identity, checked complete output and task evidence', () => {
    const m = buildManifest({ platformModels: catalog.slice(0,1), tenantModels: tenant.slice(0,1), applications: ['aigc_llm'] })
    const rows = buildCaseMatrix(m)
    const results = rows.map(row => ({...row, state:'PASS', evidence:{ task_id:'task_'+row.id, actual_model_key:row.model_key, complete:true, billing_verified:true, artifact_checked:true }}))
    assert.equal(summarizeCoverage(m, results).complete, true)
    results[0].evidence.actual_model_key = 'default_other_model'
    assert.equal(summarizeCoverage(m, results).complete, false)
})
