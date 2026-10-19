const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const file = path.join(__dirname, '../../public/wechat/callback/index.html')

test('root callback has a standalone static entry for sites without PHP rewrites', () => {
    assert.ok(fs.existsSync(file), 'public/wechat/callback/index.html must ship with the server')
    const html = fs.readFileSync(file, 'utf8')
    assert.match(html, /name="referrer" content="no-referrer"/)
    assert.doesNotMatch(html, /<script[^>]+src=/)
})

test('static OAuth bridge preserves one-use code and state on a fixed same-origin target', () => {
    const html = fs.readFileSync(file, 'utf8')
    const source = html.match(/<script>([\s\S]*?)<\/script>/)[1]
    for (const search of ['?tenant_id=8&code=one%2Buse%26code&state=' + 'a'.repeat(64), '?next=https://evil.test&redirect=//evil.test&tenant_id=8', '']) {
        const hash = '#wechat_redirect'
        let target
        const link = {}
        vm.runInNewContext(source, {
            window: { location: { search, hash, replace: value => { target = value } } },
            document: { getElementById: () => link }
        })
        assert.equal(target, '/pc/wechat/callback/' + search + hash)
        assert.equal(link.href, target)
        const url = new URL(target, 'https://pc.example.test')
        assert.equal(url.origin, 'https://pc.example.test')
        assert.equal(url.pathname, '/pc/wechat/callback/')
        assert.equal(url.search, search)
        assert.equal(url.hash, hash)
    }
})
