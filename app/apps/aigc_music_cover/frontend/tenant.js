(function () {
  'use strict';

  var ROUTE_PATTERN = /(?:^|\/)(?:app\/)?(?:aigc_music_cover|aigc-music-cover)(?:\/(?:config|task))?\/?$/;
  var ROOT_ID = 'aigc-music-cover-admin';
  var mounted = false;
  var timer = null;

  function routePath() {
    var hashPath = window.location.hash.replace(/^#/, '').split('?')[0];
    return hashPath.charAt(0) === '/' ? hashPath : window.location.pathname;
  }
  function isPage() { return ROUTE_PATTERN.test(routePath()); }
  function unwrap(payload) { return payload && payload.data !== undefined ? payload.data : (payload || {}); }
  function text(value) { return value === null || value === undefined ? '' : String(value); }
  function points(value) { var number = Number(value || 0); return isFinite(number) ? number.toFixed(number % 1 ? 2 : 0) : '0'; }
  function node(tag, className, value) { var element = document.createElement(tag); if (className) element.className = className; if (value !== undefined) element.textContent = value; return element; }

  function loadCss() {
    if (document.querySelector('link[data-music-cover-admin-css]')) return;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/music-cover-admin.css?v=20260826-route-v2';
    link.dataset.musicCoverAdminCss = '1';
    document.head.appendChild(link);
  }

  function stored(key) {
    try {
      var raw = window.localStorage.getItem('__like_admin_saas_tenant__' + key) || window.localStorage.getItem(key) || '';
      if (!raw) return '';
      var parsed = JSON.parse(raw);
      return parsed && parsed.value !== undefined ? parsed.value : raw;
    } catch (_) { return ''; }
  }

  async function api(path, options) {
    var input = options || {};
    var headers = Object.assign({ 'Content-Type': 'application/json' }, input.headers || {});
    var token = stored('token');
    var tenantId = stored('tenant_id');
    if (token && !headers.token) headers.token = token;
    if (tenantId && !headers['tenant-id']) headers['tenant-id'] = tenantId;
    var init = Object.assign({ credentials: 'include', headers: headers }, input);
    init.headers = headers;
    var response = await fetch('/tenantapi/' + path, init);
    var payload = await response.json().catch(function () { return {}; });
    if (!response.ok || (payload.code !== undefined && Number(payload.code) !== 1)) throw new Error(payload.msg || payload.message || '请求失败');
    return unwrap(payload);
  }

  function render() {
    loadCss();
    var root = document.createElement('section');
    root.id = ROOT_ID;
    root.className = 'music-cover-admin';
    root.innerHTML = '<div class="music-cover-admin__shell"><header><div><span class="music-cover-admin__eyebrow">APPLICATION CONTROL</span><h1>音乐翻唱</h1><p>租户应用配置、算力通道检查与任务管理</p></div><span id="music-cover-admin-status" class="music-cover-admin__status">读取中</span></header><nav class="music-cover-admin__tabs"><button type="button" data-tab="config" class="is-active">基础配置</button><button type="button" data-tab="tasks">任务记录</button></nav><main id="music-cover-admin-content"></main></div>';
    document.body.appendChild(root);
    mounted = true;

    var content = root.querySelector('#music-cover-admin-content');
    var status = root.querySelector('#music-cover-admin-status');
    var config = null;

    function setStatus(value, kind) { status.textContent = text(value); status.className = 'music-cover-admin__status' + (kind ? ' is-' + kind : ''); }

    function renderConfig() {
      content.innerHTML = '<section class="music-cover-admin__panel"><div class="music-cover-admin__panel-head"><div><h2>应用状态</h2><p>关闭后用户端不能估价或提交新的音乐翻唱任务</p></div><label class="music-cover-admin-switch"><input id="music-cover-admin-enabled" type="checkbox"><span></span></label></div><div id="music-cover-admin-dependency" class="music-cover-admin__dependency"></div></section><section class="music-cover-admin__panel"><div class="music-cover-admin__panel-head"><div><h2>算力超市通道</h2><p>读取 seedsvc / submit 商品、SKU 及当前租户售价</p></div><button id="music-cover-admin-refresh-config" class="music-cover-admin__ghost" type="button">刷新</button></div><div id="music-cover-admin-options" class="music-cover-admin__options"></div></section>';
      var enabled = content.querySelector('#music-cover-admin-enabled');
      enabled.checked = Number(config && config.status) === 1;
      enabled.addEventListener('change', async function () {
        try {
          await api('app.aigc_music_cover.config/setup', { method: 'POST', body: JSON.stringify({ status: enabled.checked ? 1 : 0, config_json: config.config_json || {} }) });
          config.status = enabled.checked ? 1 : 0;
          setStatus(enabled.checked ? '运行中' : '已停用', enabled.checked ? 'success' : 'warning');
        } catch (error) { enabled.checked = !enabled.checked; setStatus(error.message, 'error'); }
      });
      content.querySelector('#music-cover-admin-refresh-config').addEventListener('click', loadConfig);
      var options = Array.isArray(config && config.options) ? config.options : [];
      var dependency = content.querySelector('#music-cover-admin-dependency');
      dependency.textContent = options.length ? '通道已就绪，用户提交时按所选 SKU 的租户售价估价和结算。' : '尚未发现可用通道：请先在算力超市上架 upstream_app_code=seedsvc、upstream_api_code=submit 的应用 API，并启用至少一个 SKU。';
      dependency.className = 'music-cover-admin__dependency' + (options.length ? ' is-ready' : ' is-warning');
      var list = content.querySelector('#music-cover-admin-options');
      if (!options.length) list.appendChild(node('div', 'music-cover-admin__empty', '暂无可用通道'));
      options.forEach(function (option) {
        var item = node('article', 'music-cover-admin__option');
        var body = node('div', ''); body.append(node('strong', '', option.name || '音乐翻唱通道'), node('p', '', option.description || '音色修改与 AI 翻唱'));
        var price = node('b', '', points(option.tenant_unit_price || option.platform_unit_cost) + ' 点/次');
        item.append(body, price); list.appendChild(item);
      });
    }

    async function loadConfig() {
      setStatus('读取中');
      try {
        config = await api('app.aigc_music_cover.config/detail', { method: 'GET', headers: {} });
        renderConfig();
        setStatus(Number(config.status) === 1 ? '运行中' : '已停用', Number(config.status) === 1 ? 'success' : 'warning');
      } catch (error) { content.innerHTML = ''; content.appendChild(node('div', 'music-cover-admin__panel music-cover-admin__error', error.message || '配置读取失败')); setStatus('读取失败', 'error'); }
    }

    function resultAudio(result) {
      var wrap = node('div', 'music-cover-admin__result');
      var audio = document.createElement('audio'); audio.controls = true; audio.preload = 'metadata'; audio.src = text(result.audio_url || result.download_url);
      var download = node('a', 'music-cover-admin__ghost', '下载'); download.href = text(result.download_url || result.audio_url); download.target = '_blank'; download.rel = 'noopener'; download.download = 'music-cover-result.mp3';
      wrap.append(audio, download); return wrap;
    }

    function renderTasks(rows) {
      content.innerHTML = '<section class="music-cover-admin__panel"><div class="music-cover-admin__panel-head"><div><h2>任务记录</h2><p>显示租户内全部音乐翻唱任务，支持试听、下载、失败重试和删除</p></div><button id="music-cover-admin-refresh-tasks" class="music-cover-admin__ghost" type="button">刷新</button></div><div id="music-cover-admin-task-list" class="music-cover-admin__task-list"></div></section>';
      var list = content.querySelector('#music-cover-admin-task-list');
      if (!rows.length) list.appendChild(node('div', 'music-cover-admin__empty', '暂无任务记录'));
      rows.forEach(function (task) {
        var item = node('article', 'music-cover-admin__task');
        var top = node('div', 'music-cover-admin__task-top');
        var name = node('strong', '', task.title || ('任务 #' + (task.task_id || task.id)));
        var badge = node('span', 'music-cover-admin__badge is-' + (task.status || 'running'), task.status_label || task.status);
        top.append(name, badge);
        var meta = node('p', 'music-cover-admin__task-meta', '#' + (task.task_id || task.id) + ' · ' + points(task.user_charge_points) + ' 算力点');
        item.append(top, meta);
        var results = Array.isArray(task.results) ? task.results : [];
        results.forEach(function (result) { item.appendChild(resultAudio(result)); });
        if (task.error) item.appendChild(node('p', 'music-cover-admin__task-error', task.error));
        var actions = node('div', 'music-cover-admin__task-actions');
        if (String(task.status) === 'failed') {
          var retry = node('button', 'music-cover-admin__ghost', '重试'); retry.type = 'button';
          retry.addEventListener('click', async function () { retry.disabled = true; try { await api('app.aigc_music_cover.task/retry', { method: 'POST', body: JSON.stringify({ id: Number(task.id || task.task_id) }) }); setStatus('重试任务已提交', 'success'); await loadTasks(); } catch (error) { setStatus(error.message, 'error'); retry.disabled = false; } });
          actions.appendChild(retry);
        }
        var remove = node('button', 'music-cover-admin__danger', '删除'); remove.type = 'button';
        remove.addEventListener('click', async function () { remove.disabled = true; try { await api('app.aigc_music_cover.task/delete', { method: 'POST', body: JSON.stringify({ id: Number(task.id || task.task_id) }) }); await loadTasks(); } catch (error) { setStatus(error.message, 'error'); remove.disabled = false; } });
        actions.appendChild(remove); item.appendChild(actions); list.appendChild(item);
      });
      content.querySelector('#music-cover-admin-refresh-tasks').addEventListener('click', loadTasks);
    }

    async function loadTasks() {
      try {
        var data = await api('app.aigc_music_cover.task/lists?page_no=1&page_size=100', { method: 'GET', headers: {} });
        var rows = Array.isArray(data.lists) ? data.lists : [];
        renderTasks(rows);
        if (rows.some(function (task) { return ['pending', 'running'].indexOf(String(task.status)) !== -1; })) { if (!timer) timer = window.setInterval(loadTasks, 5000); }
        else if (timer) { window.clearInterval(timer); timer = null; }
      } catch (error) { content.innerHTML = ''; content.appendChild(node('div', 'music-cover-admin__panel music-cover-admin__error', error.message || '任务读取失败')); }
    }

    root.querySelectorAll('[data-tab]').forEach(function (button) {
      button.addEventListener('click', function () {
        root.querySelectorAll('[data-tab]').forEach(function (item) { item.classList.toggle('is-active', item === button); });
        if (button.dataset.tab === 'config') { if (timer) { clearInterval(timer); timer = null; } loadConfig(); } else loadTasks();
      });
    });
    if (routePath().indexOf('/task') !== -1) root.querySelector('[data-tab="tasks"]').click(); else loadConfig();
  }

  function check() {
    if (!isPage()) { var old = document.getElementById(ROOT_ID); if (old) old.remove(); if (timer) { clearInterval(timer); timer = null; } mounted = false; return; }
    if (!mounted && document.body) render();
  }
  ['pushState', 'replaceState'].forEach(function (method) { var original = history[method]; history[method] = function () { var result = original.apply(this, arguments); setTimeout(check, 0); return result; }; });
  window.addEventListener('popstate', check);
  window.addEventListener('hashchange', check);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', check); else check();
})();
