/** No credentials, mutable full provider metadata or prompts belong in the manifest. */
export function buildManifest({ platformModels, tenantModels, applications }) {
    const models = []
    for (const product of platformModels.filter(p => p.model_type === 'text' && Number(p.status) === 1)) {
        const options = tenantModels.filter(m => Number(m.product_id) === Number(product.id))
        for (const option of options.length ? options : [null]) {
            const channel = String(option?.channel_code || product.upstream_channel_code || '')
            const protocol = String(option?.protocol || '')
            const inputSku = Number(option?.market_input_sku_id || option?.market_sku_id || 0)
            const outputSku = Number(option?.market_output_sku_id || 0)
            const key = [product.id, channel, protocol, inputSku, outputSku].join('|')
            if (models.some(m => m.key === key)) continue
            models.push({key, product_id:Number(product.id), selection:String(option?.id || product.id),
                name:String(option?.name || product.name || ''), model_code:String(option?.model_code || product.upstream_model_code || ''),
                channel_code:channel, channel_source:String(option?.channel_source || 'unknown'), protocol,
                input_sku_id:inputSku, output_sku_id:outputSku,
                state:option ? 'NOT_RUN' : 'BLOCKED', reason:option ? '' : '平台模型未出现在本租户可用文本目录，需核验上架、SKU与价格规则'
            })
        }
    }
    return { version:1, created_at:new Date().toISOString(), models, applications:[...new Set(applications)] }
}

export function buildCaseMatrix(manifest) {
    const rows = []
    const add = (model, app, caseId, variant = '') => rows.push({
        id:[model?.key || 'shared', app, caseId, variant].join('::'), model_key:model?.key || null,
        application:app, case_id:caseId, variant,
        state:model?.state === 'BLOCKED' ? 'BLOCKED' : 'NOT_RUN', reason:model?.reason || ''
    })
    for (const model of manifest.models) {
        for (const [id, variant] of [['T01','1'],['T02','3'],['T03','10'],['T03','20'],['T04','import-revise']]) add(model,'aigc_short_drama',id,variant)
        for (const app of manifest.applications) for (const id of ['A01','A02','A03']) add(model,app,id)
    }
    add(null,'media','M01','one-image-model')
    add(null,'media','M02','one-video-model')
    add(null,'configuration','C01')
    add(null,'billing','B01')
    return rows
}

export function summarizeCoverage(manifest, results) {
    const byId = new Map(results.map(r => [r.id,r]))
    const counts = { PASS:0, FAIL:0, BLOCKED:0, NOT_RUN:0 }
    const rows = buildCaseMatrix(manifest).map(expected => {
        const result = byId.get(expected.id)
        let state = result?.state || expected.state
        let reason = result?.reason || expected.reason
        if (!(state in counts)) { state='FAIL'; reason='Invalid report state' }
        if (state === 'PASS') {
            const e = result?.evidence
            if (expected.state === 'BLOCKED' || !e?.task_id || e.complete !== true || e.billing_verified !== true
                || e.artifact_checked !== true || (expected.model_key && e.actual_model_key !== expected.model_key)) {
                state='FAIL'; reason='PASS lacks matching model, complete output, task and billing evidence'
            }
        }
        counts[state]++
        return {...expected, ...result, model_key:expected.model_key, state, reason}
    })
    return { complete:rows.length > 0 && counts.PASS === rows.length, total:rows.length, counts, rows }
}
