(function () {
  'use strict';

  var PATHS = [
    '/ai/tools/aigc_music_cover',
    '/ai/tools/aigc_music_cover/',
    '/pc/ai/tools/aigc_music_cover',
    '/pc/ai/tools/aigc_music_cover/'
  ];
  var ROOT_ID = 'aigc-music-cover-page';
  var mounted = false;
  var pollTimer = null;

  function isPage() { return PATHS.indexOf(window.location.pathname) !== -1; }
  function text(value) { return value === null || value === undefined ? '' : String(value); }
  function points(value) { var number = Number(value || 0); return isFinite(number) ? number.toFixed(number % 1 ? 2 : 0) : '0'; }
  function statusLabel(status) { return { pending: '排队中', running: '处理中', success: '已完成', failed: '失败', canceled: '已取消' }[status] || '处理中'; }

  function loadCss() {
    if (document.querySelector('link[data-music-cover-css]')) return;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/music-cover-page.css?v=20260825-music-cover-v1';
    link.dataset.musicCoverCss = '1';
    document.head.appendChild(link);
  }

  function storedToken() {
    try {
      var cookie = document.cookie.split(';').map(function (item) { return item.trim(); }).find(function (item) { return item.indexOf('token=') === 0; });
      return (cookie ? cookie.slice(6) : '') || window.localStorage.getItem('token') || '';
    } catch (_) { return ''; }
  }

  function unwrap(payload) { return payload && typeof payload === 'object' && payload.data !== undefined ? payload.data : (payload || {}); }

  async function request(path, options) {
    var input = options || {};
    var isForm = typeof FormData !== 'undefined' && input.body instanceof FormData;
    var headers = Object.assign(isForm ? {} : { 'Content-Type': 'application/json' }, input.headers || {});
    var token = storedToken();
    if (token && !headers.token) headers.token = token;
    var init = Object.assign({ credentials: 'include', headers: headers }, input);
    init.headers = headers;
    var response = await fetch('/api/' + path, init);
    var payload = await response.json().catch(function () { return {}; });
    if (!response.ok || (payload.code !== undefined && Number(payload.code) !== 1)) {
      throw new Error(payload.msg || payload.message || '请求失败');
    }
    return unwrap(payload);
  }

  function audioResult(result) {
    var row = document.createElement('div');
    row.className = 'music-cover-result';
    var audio = document.createElement('audio');
    audio.controls = true;
    audio.preload = 'metadata';
    audio.src = text(result.audio_url || result.download_url);
    var download = document.createElement('a');
    download.className = 'music-cover-download';
    download.href = text(result.download_url || result.audio_url);
    download.target = '_blank';
    download.rel = 'noopener';
    download.download = 'music-cover-result.mp3';
    download.textContent = '下载';
    row.append(audio, download);
    return row;
  }

  function render() {
    loadCss();
    var root = document.createElement('section');
    root.id = ROOT_ID;
    root.className = 'music-cover-page';
    root.innerHTML = '<div class="music-cover-shell">' +
      '<header class="music-cover-header"><a class="music-cover-back" href="/ai/tools" aria-label="返回工具列表">‹</a><div><p class="music-cover-eyebrow">VOICE CONVERSION</p><h1>音乐翻唱</h1><p>上传原始歌曲和目标音色参考，生成新的演唱版本</p></div><span class="music-cover-secure">双音频处理 · 异步生成</span></header>' +
      '<main class="music-cover-layout"><section class="music-cover-workbench"><div class="music-cover-section-head"><div><h2>创建翻唱任务</h2><p>音频支持 MP3、WAV、OGG、FLAC、AAC、M4A、OPUS，单个文件不超过 100MB</p></div><span>01</span></div>' +
      '<label class="music-cover-label" for="music-cover-title">作品名称</label><input class="music-cover-input" id="music-cover-title" maxlength="120" placeholder="例如：夏夜翻唱版" value="音乐翻唱">' +
      '<div class="music-cover-audio-grid"><div class="music-cover-upload" data-kind="source"><div class="music-cover-upload__top"><span class="music-cover-number">A</span><div><strong>原始歌曲</strong><p>保留旋律与演唱内容</p></div></div><input id="music-cover-source-file" type="file" accept="audio/*,.mp3,.wav,.ogg,.flac,.aac,.m4a,.opus"><label for="music-cover-source-file">选择音频</label><span id="music-cover-source-name" class="music-cover-file-name">尚未选择文件</span><div class="music-cover-divider"><span>或使用公开地址</span></div><input id="music-cover-source-url" type="url" placeholder="https://.../source.mp3"></div>' +
      '<div class="music-cover-upload" data-kind="reference"><div class="music-cover-upload__top"><span class="music-cover-number is-reference">B</span><div><strong>目标音色参考</strong><p>建议使用清晰、无伴奏的人声</p></div></div><input id="music-cover-reference-file" type="file" accept="audio/*,.mp3,.wav,.ogg,.flac,.aac,.m4a,.opus"><label for="music-cover-reference-file">选择音频</label><span id="music-cover-reference-name" class="music-cover-file-name">尚未选择文件</span><div class="music-cover-divider"><span>或使用公开地址</span></div><input id="music-cover-reference-url" type="url" placeholder="https://.../voice.mp3"></div></div>' +
      '<label class="music-cover-label" for="music-cover-channel">生成通道</label><select id="music-cover-channel" class="music-cover-select"><option value="">加载中...</option></select><p id="music-cover-channel-help" class="music-cover-help"></p><div id="music-cover-dependency" class="music-cover-dependency" hidden></div>' +
      '<label class="music-cover-consent"><input id="music-cover-consent" type="checkbox"><span>我确认拥有所上传音频及目标音色的合法使用授权</span></label>' +
      '<div class="music-cover-submit-row"><div><span>预计消耗</span><strong id="music-cover-points">--</strong><small>算力点 / 次</small></div><button id="music-cover-submit" type="button" disabled>开始生成 <span>↗</span></button></div><p id="music-cover-message" class="music-cover-message" role="status"></p></section>' +
      '<section class="music-cover-history"><div class="music-cover-section-head"><div><h2>生成记录</h2><p>任务完成后可试听并下载结果</p></div><button id="music-cover-refresh" class="music-cover-icon-button" type="button" aria-label="刷新任务">↻</button></div><div id="music-cover-tasks" class="music-cover-tasks"><div class="music-cover-empty">正在加载任务...</div></div></section></main></div>';
    document.body.appendChild(root);
    mounted = true;

    var sourceFile = root.querySelector('#music-cover-source-file');
    var referenceFile = root.querySelector('#music-cover-reference-file');
    var sourceUrl = root.querySelector('#music-cover-source-url');
    var referenceUrl = root.querySelector('#music-cover-reference-url');
    var title = root.querySelector('#music-cover-title');
    var channel = root.querySelector('#music-cover-channel');
    var consent = root.querySelector('#music-cover-consent');
    var submit = root.querySelector('#music-cover-submit');
    var message = root.querySelector('#music-cover-message');
    var dependency = root.querySelector('#music-cover-dependency');
    var tasks = root.querySelector('#music-cover-tasks');
    var pointNode = root.querySelector('#music-cover-points');
    var state = { config: null, options: [], loading: false };

    function selectedOption() {
      return state.options.find(function (option) { return String(option.id || option.value) === String(channel.value); }) || null;
    }

    function hasSource() { return !!sourceFile.files[0] || sourceUrl.value.trim() !== ''; }
    function hasReference() { return !!referenceFile.files[0] || referenceUrl.value.trim() !== ''; }
    function setMessage(value, kind) { message.textContent = text(value); message.className = 'music-cover-message' + (kind ? ' is-' + kind : ''); }
    function updateSubmit() { submit.disabled = state.loading || !hasSource() || !hasReference() || !consent.checked || !selectedOption() || !state.config || Number(state.config.status) !== 1; }

    function bindFile(input, nameNode) {
      input.addEventListener('change', function () {
        var file = input.files[0];
        nameNode.textContent = file ? file.name : '尚未选择文件';
        nameNode.classList.toggle('is-selected', !!file);
        updateSubmit();
      });
    }

    function renderChannels() {
      channel.innerHTML = '';
      if (!state.options.length) {
        channel.appendChild(new Option('暂无可用通道', ''));
        channel.disabled = true;
        dependency.hidden = false;
        dependency.textContent = '算力超市尚未上架 seedsvc / submit 应用 API。请先配置启用的商品、SKU 和租户售价。';
        pointNode.textContent = '--';
        updateSubmit();
        return;
      }
      channel.disabled = false;
      state.options.forEach(function (option, index) {
        var price = Number(option.tenant_unit_price || option.platform_unit_cost || 0);
        var node = new Option(text(option.name || '音乐翻唱通道') + ' · ' + points(price) + ' 点/次', text(option.id || option.value));
        if (index === 0) node.selected = true;
        channel.appendChild(node);
      });
      dependency.hidden = true;
      updateSubmit();
      estimate();
    }

    async function estimate() {
      var option = selectedOption();
      if (!option) { pointNode.textContent = '--'; return; }
      try {
        var quote = await request('app.aigc_music_cover.generate/estimate', { method: 'POST', body: JSON.stringify({ market_product_id: option.market_product_id, market_sku_id: option.market_sku_id }) });
        pointNode.textContent = points(quote.display_points !== undefined ? quote.display_points : quote.user_charge_points);
        root.querySelector('#music-cover-channel-help').textContent = option.description || '按算力超市当前租户售价结算';
      } catch (error) {
        pointNode.textContent = '--';
        root.querySelector('#music-cover-channel-help').textContent = error.message || '暂时无法估价';
      }
      updateSubmit();
    }

    async function upload(file, assetType, label) {
      var body = new FormData();
      body.append('file', file);
      body.append('asset_type', assetType);
      body.append('title', file.name || label);
      body.append('authorization_confirmed', '1');
      body.append('authorization_text', '用户确认拥有音频及音色的合法使用授权');
      body.append('source', 'aigc_music_cover');
      return request('app.aigc_music_cover.asset/upload_audio', { method: 'POST', body: body });
    }

    function renderTasks(rows) {
      tasks.innerHTML = '';
      if (!rows.length) { tasks.appendChild(Object.assign(document.createElement('div'), { className: 'music-cover-empty', textContent: '还没有音乐翻唱记录' })); return; }
      rows.forEach(function (task) {
        var row = document.createElement('article');
        row.className = 'music-cover-task';
        var head = document.createElement('div'); head.className = 'music-cover-task__head';
        var name = document.createElement('strong'); name.textContent = text(task.title || ('任务 #' + (task.task_id || task.id)));
        var badge = document.createElement('span'); badge.className = 'music-cover-badge is-' + text(task.status || 'running'); badge.textContent = text(task.status_label || statusLabel(task.status));
        head.append(name, badge); row.appendChild(head);
        var meta = document.createElement('p'); meta.className = 'music-cover-task__meta'; meta.textContent = '任务 #' + text(task.task_id || task.id) + ' · ' + points(task.user_charge_points) + ' 算力点'; row.appendChild(meta);
        var results = Array.isArray(task.results) ? task.results : [];
        if (results.length) results.forEach(function (result) { row.appendChild(audioResult(result)); });
        else if (task.error) { var error = document.createElement('p'); error.className = 'music-cover-task__error'; error.textContent = text(task.error); row.appendChild(error); }
        else { var pending = document.createElement('div'); pending.className = 'music-cover-pending'; pending.innerHTML = '<span></span>正在转换音色，完成后自动显示结果'; row.appendChild(pending); }
        var actions = document.createElement('div'); actions.className = 'music-cover-task__actions';
        if (String(task.status) === 'failed') { var retry = document.createElement('button'); retry.type = 'button'; retry.textContent = '重试'; retry.dataset.action = 'retry'; retry.dataset.id = text(task.id || task.task_id); actions.appendChild(retry); }
        var remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '删除'; remove.dataset.action = 'delete'; remove.dataset.id = text(task.id || task.task_id); actions.appendChild(remove); row.appendChild(actions);
        tasks.appendChild(row);
      });
      tasks.querySelectorAll('button[data-action]').forEach(function (button) {
        button.addEventListener('click', async function () {
          button.disabled = true;
          try {
            await request('app.aigc_music_cover.task/' + button.dataset.action, { method: 'POST', body: JSON.stringify({ id: Number(button.dataset.id) }) });
            setMessage(button.dataset.action === 'retry' ? '重试任务已提交' : '记录已删除', 'success');
            await loadTasks();
          } catch (error) { setMessage(error.message, 'error'); button.disabled = false; }
        });
      });
    }

    async function loadTasks() {
      try {
        var data = await request('app.aigc_music_cover.task/lists?page_no=1&page_size=20', { method: 'GET', headers: {} });
        var rows = Array.isArray(data.lists) ? data.lists : [];
        renderTasks(rows);
        if (rows.some(function (task) { return ['pending', 'running'].indexOf(String(task.status)) !== -1; })) startPolling(); else stopPolling();
      } catch (_) { tasks.innerHTML = '<div class="music-cover-empty is-error">任务加载失败，请刷新重试</div>'; }
    }

    function startPolling() { if (!pollTimer) pollTimer = window.setInterval(loadTasks, 5000); }
    function stopPolling() { if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null; } }

    async function loadConfig() {
      try {
        state.config = await request('app.aigc_music_cover.config/detail', { method: 'GET', headers: {} });
        state.options = Array.isArray(state.config.options) ? state.config.options : [];
      } catch (error) {
        state.config = { status: 0 }; state.options = [];
        dependency.hidden = false; dependency.textContent = error.message || '应用配置加载失败';
      }
      renderChannels();
    }

    bindFile(sourceFile, root.querySelector('#music-cover-source-name'));
    bindFile(referenceFile, root.querySelector('#music-cover-reference-name'));
    [sourceUrl, referenceUrl, consent].forEach(function (input) { input.addEventListener('input', updateSubmit); input.addEventListener('change', updateSubmit); });
    channel.addEventListener('change', estimate);
    root.querySelector('#music-cover-refresh').addEventListener('click', loadTasks);
    submit.addEventListener('click', async function () {
      var option = selectedOption(); if (!option || !hasSource() || !hasReference() || !consent.checked) return;
      state.loading = true; updateSubmit(); submit.innerHTML = '准备音频...'; setMessage('', '');
      try {
        var sourceAsset = sourceFile.files[0] ? await upload(sourceFile.files[0], 'cover_source', '原始歌曲') : null;
        submit.innerHTML = '提交任务...';
        var referenceAsset = referenceFile.files[0] ? await upload(referenceFile.files[0], 'cover_reference', '目标音色参考') : null;
        var payload = {
          title: title.value.trim() || '音乐翻唱',
          source_asset_id: sourceAsset ? Number(sourceAsset.id) : 0,
          source_audio: sourceAsset ? '' : sourceUrl.value.trim(),
          reference_asset_id: referenceAsset ? Number(referenceAsset.id) : 0,
          ref_audio: referenceAsset ? '' : referenceUrl.value.trim(),
          market_product_id: option.market_product_id,
          market_sku_id: option.market_sku_id,
          mode: 'async_query'
        };
        await request('app.aigc_music_cover.generate/index', { method: 'POST', body: JSON.stringify(payload) });
        setMessage('任务已提交，正在转换音色', 'success');
        await loadTasks(); startPolling();
      } catch (error) { setMessage(error.message || '提交失败，请稍后重试', 'error'); }
      finally { state.loading = false; submit.innerHTML = '开始生成 <span>↗</span>'; updateSubmit(); }
    });

    loadConfig();
    loadTasks();
  }

  function stopPolling() { if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null; } }
  function check() {
    if (!isPage()) { var old = document.getElementById(ROOT_ID); if (old) old.remove(); stopPolling(); mounted = false; return; }
    if (!mounted && document.body) render();
  }
  ['pushState', 'replaceState'].forEach(function (method) { var original = history[method]; history[method] = function () { var result = original.apply(this, arguments); window.setTimeout(check, 0); return result; }; });
  window.addEventListener('popstate', check);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check); else check();
})();
