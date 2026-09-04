(function () {
  'use strict';

  if (window.__shortDramaStyleSearchInstalled) {
    return;
  }
  window.__shortDramaStyleSearchInstalled = true;

  var panelSelector = '.drama-tool-panel--style, .plan-tool-panel--style';
  var inputClass = 'short-drama-style-search';
  var emptyClass = 'short-drama-style-search-empty';
  var panelStates = typeof WeakMap === 'function' ? new WeakMap() : null;
  var pollAttempts = 0;

  function normalize(value) {
    return String(value || '').trim().toLocaleLowerCase();
  }

  function filterPanel(panel) {
    var input = panel.querySelector('.' + inputClass);
    var grid = panel.querySelector('.drama-style-grid, .plan-style-grid');
    if (!input || !grid) {
      return;
    }

    var query = normalize(input.value);
    var buttons = Array.prototype.filter.call(grid.children, function (child) {
      return child.tagName === 'BUTTON';
    });
    var labels = buttons.map(function (button) {
      return button.textContent || '';
    }).join('\u0001');
    var signature = query + '\u0002' + labels;
    if (panelStates && panelStates.get(panel) === signature) {
      return;
    }
    if (panelStates) {
      panelStates.set(panel, signature);
    }
    var visibleCount = 0;

    buttons.forEach(function (button) {
      var label = button.querySelector('span');
      var matches = !query || normalize(label ? label.textContent : button.textContent).indexOf(query) !== -1;
      button.hidden = !matches;
      if (matches) {
        visibleCount += 1;
      }
    });

    var empty = grid.querySelector('.' + emptyClass);
    if (!visibleCount && query) {
      if (!empty) {
        empty = document.createElement('div');
        empty.className = emptyClass;
        empty.textContent = '没有匹配的风格';
        grid.appendChild(empty);
      }
    } else if (empty) {
      empty.remove();
    }
  }

  function addSearch(panel) {
    var head = panel.querySelector('.drama-picker-panel__head, .plan-picker-panel__head');
    if (!head || head.querySelector('.' + inputClass)) {
      return;
    }

    var wrapper = document.createElement('label');
    wrapper.className = 'short-drama-style-search-wrap';
    wrapper.setAttribute('aria-label', '搜索风格');

    var input = document.createElement('input');
    input.className = inputClass;
    input.type = 'search';
    input.placeholder = '搜索风格';
    input.setAttribute('aria-label', '搜索风格');
    input.setAttribute('autocomplete', 'off');
    input.addEventListener('input', function () {
      filterPanel(panel);
    });
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        input.value = '';
        filterPanel(panel);
        input.blur();
      }
    });

    wrapper.appendChild(input);
    head.appendChild(wrapper);
  }

  function refresh() {
    document.querySelectorAll(panelSelector).forEach(function (panel) {
      addSearch(panel);
      filterPanel(panel);
    });
  }

  function loadStyles() {
    if (document.querySelector('link[data-short-drama-style-search]')) {
      return;
    }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/_nuxt/short-drama-style-search.css?v=20260825-style-search-v5';
    link.setAttribute('data-short-drama-style-search', '');
    document.head.appendChild(link);
  }

  function start() {
    loadStyles();
    refresh();
    document.addEventListener('click', function (event) {
      var target = event.target;
      var element = target && target.closest ? target : target && target.parentElement;
      if (element && element.closest && element.closest('button[aria-label*="画风"]')) {
        window.setTimeout(refresh, 0);
      }
    }, true);
    function pollForPanel() {
      refresh();
      pollAttempts += 1;
      if (document.querySelector(panelSelector) || pollAttempts >= 20) {
        return;
      }
      window.setTimeout(pollForPanel, 250);
    }
    window.setTimeout(pollForPanel, 120);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
}());
