(function () {
  'use strict';

  var PATHS = ['/ai/tools/aigc_watermark_removal', '/ai/tools/aigc_watermark_removal/'];
  var overlayId = 'aigc-watermark-removal-page';
  var pollTimer = null;
  var mounted = false;

  // Route cleanup runs outside render(); keep the timer cleanup in this scope
  // so navigating away cannot raise a ReferenceError or leak polling.
  function stopPolling() { if (pollTimer) { window.clearInterval(pollTimer); pollTimer = null; } }

  function isPage() {
    return PATHS.indexOf(window.location.pathname) !== -1;
  }

  function loadCss() {
    if (document.querySelector('link[data-watermark-removal-css]')) return;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/watermark-removal-page.css?v=20260825-watermark-v1';
    link.dataset.watermarkRemovalCss = '1';
    document.head.appendChild(link);
  }

  function unwrap(payload) {
    if (payload && typeof payload === 'object' && payload.data !== undefined) return payload.data;
    return payload || {};
  }

  function storedToken() {
    try { return document.cookie.split(';').map(function (item) { return item.trim(); }).find(function (item) { return item.indexOf('token=') === 0; })?.slice(6) || window.localStorage.getItem('token') || ''; } catch (_) { return ''; }
  }

  async function request(path, options) {
    var input = options || {};
    var headers = Object.assign({ 'Content-Type': 'application/json' }, input.headers || {});
    var token = storedToken();
    if (token && !headers.token) headers.token = token;
    var init = Object.assign({ credentials: 'include', headers: headers }, input);
    init.headers = headers;
    var response = await fetch('/api/' + path, init);
    var payload = await response.json().catch(function () { return {}; });
    if (!response.ok || (payload && payload.code !== undefined && Number(payload.code) !== 1)) {
      throw new Error((payload && (payload.msg || payload.message)) || '请求失败');
    }
    return unwrap(payload);
  }

  function text(value) {
    return value === null || value === undefined ? '' : String(value);
  }

  function points(value) {
    var number = Number(value || 0);
    if (!isFinite(number)) return '0';
    return number.toFixed(number % 1 === 0 ? 0 : 2);
  }

  function statusLabel(status) {
    return { pending: '排队中', running: '处理中', success: '已完成', failed: '失败', canceled: '已取消' }[status] || '处理中';
  }

  function createVideo(result) {
    var item = document.createElement('article');
    item.className = 'watermark-result';
    var video = document.createElement('video');
    video.controls = true;
    video.preload = 'metadata';
    video.src = text(result.video_url || result.download_url);
    var footer = document.createElement('div');
    footer.className = 'watermark-result__footer';
    var label = document.createElement('span');
    label.textContent = '无水印视频';
    var download = document.createElement('a');
    download.className = 'watermark-download';
    download.href = text(result.download_url || result.video_url);
    download.target = '_blank';
    download.rel = 'noopener';
    download.download = 'watermark-removed.mp4';
    download.textContent = '下载';
    footer.append(label, download);
    item.append(video, footer);
    return item;
  }

  function render() {
    loadCss();
    var host = document.createElement('div');
    host.id = overlayId;
    host.className = 'watermark-page';
    host.innerHTML = '<div class="watermark-shell">' +
      '<header class="watermark-header"><a class="watermark-back" href="/ai/tools" aria-label="返回工具列表">←</a><div><p class="watermark-eyebrow">AI VIDEO TOOL</p><h1>短视频去水印</h1><p>粘贴分享链接，生成干净无水印视频</p></div><span class="watermark-secure">安全处理 · 仅保存任务结果</span></header>' +
      '<main class="watermark-grid"><section class="watermark-card watermark-form-card"><div class="watermark-card__head"><div><h2>去除视频水印</h2><p>支持抖音、快手、小红书等平台的分享链接</p></div><span class="watermark-step">01</span></div>' +
      '<label class="watermark-label" for="watermark-url">短视频分享链接</label><div class="watermark-input-row"><input id="watermark-url" type="url" autocomplete="off" placeholder="https://..."/><button id="watermark-paste" type="button">粘贴</button></div><p id="watermark-url-help" class="watermark-help">请使用平台的“复制链接”功能获取完整地址</p>' +
      '<label class="watermark-label" for="watermark-channel">处理通道</label><select id="watermark-channel"><option value="">加载中...</option></select><p id="watermark-channel-help" class="watermark-help"></p>' +
      '<div id="watermark-dependency" class="watermark-dependency" hidden></div><div class="watermark-quote"><span>预计消耗</span><strong id="watermark-points">--</strong><small>算力点</small></div><button id="watermark-submit" class="watermark-submit" type="button" disabled>开始去水印 <span>↗</span></button><p id="watermark-message" class="watermark-message" role="status"></p></section>' +
      '<section class="watermark-card watermark-tasks-card"><div class="watermark-card__head"><div><h2>处理记录</h2><p>结果会保存在当前租户的任务记录中</p></div><button id="watermark-refresh" class="watermark-icon-button" type="button" aria-label="刷新任务">↻</button></div><div id="watermark-tasks" class="watermark-tasks"><div class="watermark-empty">正在加载任务...</div></div></section></main>' +
      '</div>';
    document.body.appendChild(host);
    mounted = true;
    var urlInput = host.querySelector('#watermark-url');
    var channel = host.querySelector('#watermark-channel');
    var submit = host.querySelector('#watermark-submit');
    var pointsNode = host.querySelector('#watermark-points');
    var message = host.querySelector('#watermark-message');
    var help = host.querySelector('#watermark-url-help');
    var dependency = host.querySelector('#watermark-dependency');
    var tasksNode = host.querySelector('#watermark-tasks');
    var state = { options: [], config: null, loading: false };

    function selectedOption() {
      return state.options.find(function (option) { return String(option.id || option.value) === String(channel.value); }) || null;
    }

    function setMessage(value, kind) {
      message.textContent = text(value);
      message.className = 'watermark-message' + (kind ? ' is-' + kind : '');
    }

    function updateSubmit() {
      submit.disabled = state.loading || !urlInput.value.trim() || !selectedOption() || !state.config || Number(state.config.status) !== 1;
    }

    function renderChannels() {
      channel.innerHTML = '';
      if (!state.options.length) {
        channel.appendChild(new Option('暂无可用通道', ''));
        channel.disabled = true;
        dependency.hidden = false;
        dependency.textContent = '算力超市还没有上架 watermark_removal / remove 应用 API。请先在算力超市配置商品、SKU 和租户售价。';
        updateSubmit();
        return;
      }
      channel.disabled = false;
      state.options.forEach(function (option, index) {
        var label = text(option.name || '去水印通道');
        var price = Number(option.tenant_unit_price || option.platform_unit_cost || 0);
        var optionNode = new Option(label + ' · ' + points(price) + ' 点/次', text(option.id || option.value));
        channel.appendChild(optionNode);
        if (index === 0) optionNode.selected = true;
      });
      var current = selectedOption();
      dependency.hidden = true;
      host.querySelector('#watermark-channel-help').textContent = current && current.description ? text(current.description) : '每次处理一个视频，按算力超市租户售价计费';
      updateSubmit();
    }

    async function estimate() {
      var url = urlInput.value.trim();
      var option = selectedOption();
      if (!url || !option) { pointsNode.textContent = '--'; updateSubmit(); return; }
      try {
        var quote = await request('app.aigc_watermark_removal.generate/estimate', { method: 'POST', body: JSON.stringify({ url: url, market_product_id: option.market_product_id, market_sku_id: option.market_sku_id }) });
        pointsNode.textContent = points(quote.display_points !== undefined ? quote.display_points : quote.user_charge_points);
        help.textContent = '估价已更新，提交后只扣除实际成功任务的费用';
        help.className = 'watermark-help is-success';
      } catch (error) {
        pointsNode.textContent = '--';
        help.textContent = error.message || '链接暂时无法估价';
        help.className = 'watermark-help is-error';
      }
      updateSubmit();
    }

    function renderTasks(rows) {
      tasksNode.innerHTML = '';
      if (!rows.length) { tasksNode.innerHTML = '<div class="watermark-empty">还没有去水印记录</div>'; return; }
      rows.forEach(function (task) {
        var card = document.createElement('article');
        card.className = 'watermark-task';
        var head = document.createElement('div');
        head.className = 'watermark-task__head';
        var title = document.createElement('strong');
        title.textContent = '任务 #' + text(task.task_id || task.id);
        var badge = document.createElement('span');
        badge.className = 'watermark-badge is-' + text(task.status || 'running');
        badge.textContent = text(task.status_label || statusLabel(task.status));
        head.append(title, badge);
        var source = document.createElement('p');
        source.className = 'watermark-task__source';
        source.textContent = text(task.source_url || '短视频链接');
        card.append(head, source);
        var results = Array.isArray(task.results) ? task.results : [];
        if (results.length) {
          var resultList = document.createElement('div');
          resultList.className = 'watermark-results';
          results.forEach(function (result) { resultList.appendChild(createVideo(result)); });
          card.appendChild(resultList);
        } else if (task.error) {
          var error = document.createElement('p'); error.className = 'watermark-task__error'; error.textContent = text(task.error); card.appendChild(error);
        } else {
          var pending = document.createElement('div'); pending.className = 'watermark-pending'; pending.innerHTML = '<span class="watermark-spinner"></span>正在处理，完成后会自动出现下载按钮'; card.appendChild(pending);
        }
        var actions = document.createElement('div'); actions.className = 'watermark-task__actions';
        var del = document.createElement('button'); del.type = 'button'; del.className = 'watermark-delete'; del.textContent = '删除记录'; del.dataset.id = text(task.id || task.task_id); actions.appendChild(del); card.appendChild(actions);
        tasksNode.appendChild(card);
      });
      tasksNode.querySelectorAll('.watermark-delete').forEach(function (button) {
        button.addEventListener('click', async function () {
          button.disabled = true;
          try { await request('app.aigc_watermark_removal.task/delete', { method: 'POST', body: JSON.stringify({ id: Number(button.dataset.id) }) }); await loadTasks(); } catch (error) { setMessage(error.message, 'error'); button.disabled = false; }
        });
      });
    }

    async function loadTasks() {
      try {
        var data = await request('app.aigc_watermark_removal.task/lists?page_no=1&page_size=20', { method: 'GET', headers: {} });
        var rows = Array.isArray(data.lists) ? data.lists : [];
        renderTasks(rows);
        if (rows.some(function (task) { return ['pending', 'running'].indexOf(String(task.status)) !== -1; })) startPolling(); else stopPolling();
      } catch (error) { tasksNode.innerHTML = '<div class="watermark-empty is-error">任务加载失败，请刷新重试</div>'; }
    }

    function startPolling() { if (pollTimer) return; pollTimer = window.setInterval(loadTasks, 4500); }
    async function loadConfig() {
      try {
        state.config = await request('app.aigc_watermark_removal.config/detail', { method: 'GET', headers: {} });
        state.options = Array.isArray(state.config.options) ? state.config.options : [];
        renderChannels();
        if (state.options.length) estimate();
      } catch (error) {
        state.config = { status: 0 };
        dependency.hidden = false;
        dependency.textContent = error.message || '应用配置加载失败';
        renderChannels();
      }
    }

    urlInput.addEventListener('input', function () { updateSubmit(); window.clearTimeout(urlInput._estimateTimer); urlInput._estimateTimer = window.setTimeout(estimate, 450); });
    channel.addEventListener('change', estimate);
    host.querySelector('#watermark-paste').addEventListener('click', async function () { try { urlInput.value = await navigator.clipboard.readText(); urlInput.dispatchEvent(new Event('input')); } catch (_) { setMessage('浏览器未授权读取剪贴板，请手动粘贴链接', 'error'); } });
    host.querySelector('#watermark-refresh').addEventListener('click', loadTasks);
    submit.addEventListener('click', async function () {
      var option = selectedOption(); if (!option || !urlInput.value.trim()) return;
      state.loading = true; submit.disabled = true; submit.innerHTML = '提交中…'; setMessage('', '');
      try { await request('app.aigc_watermark_removal.generate/index', { method: 'POST', body: JSON.stringify({ url: urlInput.value.trim(), market_product_id: option.market_product_id, market_sku_id: option.market_sku_id }) }); setMessage('任务已提交，正在处理', 'success'); await loadTasks(); startPolling(); } catch (error) { setMessage(error.message || '提交失败，请稍后重试', 'error'); } finally { state.loading = false; submit.innerHTML = '开始去水印 <span>↗</span>'; updateSubmit(); }
    });
    loadConfig();
    loadTasks();
  }

  function check() {
    if (!isPage()) {
      var old = document.getElementById(overlayId);
      if (old) old.remove();
      stopPolling(); mounted = false;
      return;
    }
    if (!mounted && document.body) render();
  }

  ['pushState', 'replaceState'].forEach(function (method) { var original = history[method]; history[method] = function () { var result = original.apply(this, arguments); window.setTimeout(check, 0); return result; }; });
  window.addEventListener('popstate', check);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check); else check();
})();
