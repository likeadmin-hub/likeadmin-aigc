(function () {
  'use strict';

  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  var technicalIdentifier = /^market_/i;
  var descriptions = new Map();
  var configReady = false;
  var configLoading = false;
  var pendingRows = new Set();
  var knownRows = new Set();
  var refreshTimer = null;

  function clean(value) {
    return String(value == null ? '' : value).trim();
  }

  function key(value) {
    return clean(value).toLowerCase();
  }

  function userDescription(option) {
    var values = [
      option.description,
      option.model_description,
      option.app_description,
      option.summary,
      option.introduction,
      option.model_intro,
    ];

    for (var i = 0; i < values.length; i += 1) {
      var value = clean(values[i]);
      if (value && !technicalIdentifier.test(value)) return value;
    }
    return '';
  }

  function registerOption(option) {
    if (!option || typeof option !== 'object') return;

    var description = userDescription(option);
    if (!description) return;

    [
      option.id,
      option.value,
      option.code,
      option.channel_code,
      option.model_code,
    ].forEach(function (value) {
      var normalized = key(value);
      if (normalized) descriptions.set(normalized, description);
    });
  }

  function collectOptions(section) {
    if (!section || typeof section !== 'object') return;
    var options = Array.isArray(section.options) ? section.options : [];
    var channels = Array.isArray(section.channels) ? section.channels : [];
    options.concat(channels).forEach(registerOption);
  }

  function applyConfig(payload) {
    var data = payload && payload.data ? payload.data : payload;
    var router = data && data.market_router ? data.market_router : {};
    collectOptions(router.image);
    collectOptions(router.video);
    collectOptions(router.music);
    configReady = true;
    knownRows.forEach(queueRow);
  }

  function rowDescription(row) {
    var text = row && row.children && row.children[1];
    var valueNode = text && text.querySelector('em');
    if (!valueNode) return;

    var current = clean(valueNode.textContent);
    var modelCode = technicalIdentifier.test(current) ? current : valueNode.getAttribute('data-market-model-code') || current;
    if (valueNode.getAttribute('data-market-model-code') !== modelCode) {
      valueNode.setAttribute('data-market-model-code', modelCode);
    }

    var description = descriptions.get(key(modelCode));
    if (description) {
      if (clean(valueNode.textContent) !== description) {
        valueNode.textContent = description;
      }
      valueNode.classList.add('canvas-model-description');
      valueNode.style.display = 'block';
      return;
    }

    if (valueNode.textContent !== '') valueNode.textContent = '';
    valueNode.classList.remove('canvas-model-description');
    valueNode.style.display = 'none';
  }

  function queueRow(row) {
    if (!row || !row.isConnected) return;
    // Current bundles render descriptions themselves; keep this for cached legacy bundles.
    if (row.querySelector('small')) return;
    knownRows.add(row);
    pendingRows.add(row);
    if (refreshTimer !== null) return;
    refreshTimer = window.setTimeout(function () {
      refreshTimer = null;
      if (!pendingRows.size) return;
      if (!configReady) { if (!configLoading) loadConfig(); return; }
      var rows = Array.from(pendingRows);
      pendingRows.clear();
      rows.forEach(function (item) {
        if (item.isConnected) rowDescription(item);
        else knownRows.delete(item);
      });
    }, 100);
  }

  function collectRows(node) {
    if (!node || node.nodeType !== 1) return;
    if (node.matches('.agent-model-row')) queueRow(node);
    node.querySelectorAll('.agent-model-row').forEach(queueRow);
  }

  function changedRows(records) {
    records.forEach(function (record) {
      var target = record.target.nodeType === 1 ? record.target : record.target.parentElement;
      var row = target && target.closest('.agent-model-row');
      if (row) queueRow(row);
      record.addedNodes.forEach(collectRows);
    });
    knownRows.forEach(function (row) { if (!row.isConnected) { knownRows.delete(row); pendingRows.delete(row); } });
  }

  function loadConfig() {
    configLoading = true;
    var headers = {
      Accept: 'application/json',
      terminal: '4',
    };
    var publicConfig = window.__NUXT__ && window.__NUXT__.config && window.__NUXT__.config.public;
    if (publicConfig && publicConfig.version) headers.version = String(publicConfig.version);
    var token = '', tenantId = '';
    try {
      token = clean(window.localStorage && window.localStorage.getItem('token'));
      tenantId = clean(window.localStorage && window.localStorage.getItem('tenant_id'));
    } catch (_) { /* storage can be disabled; cookie authentication remains available */ }
    var queryTenant = new URLSearchParams(window.location.search).get('tenant_id');
    var pathTenant = window.location.pathname.match(/^\/t\/(\d+)(?:\/|$)/);
    tenantId = clean(queryTenant || (pathTenant && pathTenant[1]) || tenantId);
    if (token) {
      headers.token = token;
      headers.Authorization = 'Bearer ' + token;
    }
    if (tenantId) headers['tenant-id'] = tenantId;

    fetch('/api/app.aigc_canvas.config/detail', {
      credentials: 'include',
      headers: headers,
    })
      .then(function (response) {
        return response.ok ? response.json() : null;
      })
      .then(applyConfig)
      .catch(function () {
        configReady = true;
        knownRows.forEach(queueRow);
      });
  }

  var style = document.createElement('style');
  style.textContent = '.agent-model-row > span:nth-child(2) > em { display: none !important; } .agent-model-row > span:nth-child(2) > em.canvas-model-description { color: #ffffff70; display: block !important; font-size: 11px; font-style: normal; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }';
  document.head.appendChild(style);

  function start() {
    if (!document.body) return;
    collectRows(document.body);
    new MutationObserver(changedRows).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
}());
