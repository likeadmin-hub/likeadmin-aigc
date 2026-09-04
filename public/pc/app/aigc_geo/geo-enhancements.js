(function () {
  'use strict';

  var path = window.location.pathname || '';
  if (!/\/app\/aigc_geo(?:\/|$)/.test(path)) return;

  document.body.classList.add('geo-page-body');

  var landing = /\/app\/aigc_geo\/?$/.test(path);
  var marketText = 'GEO营销优化系统已接入算力市场能力：按量结算、租户隔离、可替换模型 SKU。';

  function toast(message) {
    var current = document.querySelector('.geo-enhancement-toast');
    if (current) current.remove();
    var node = document.createElement('div');
    node.className = 'geo-enhancement-toast';
    node.textContent = message;
    document.body.appendChild(node);
    window.setTimeout(function () {
      node.remove();
    }, 2600);
  }

  function createNode(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  }

  function showMarketPanel() {
    var old = document.querySelector('.geo-market-backdrop');
    if (old) {
      old.remove();
      return;
    }

    var backdrop = createNode('div', 'geo-market-backdrop');
    var panel = createNode('section', 'geo-market-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', '算力市场能力');

    var header = createNode('div', 'geo-market-panel__header');
    var heading = createNode('div');
    var status = createNode('span', 'geo-market-status', '上架准备就绪');
    var title = createNode('h2', '', '算力市场能力');
    var description = createNode('p', '', '统一承载问题生成、品牌检测与内容生产，后续可直接绑定平台 SKU。');
    heading.appendChild(status);
    heading.appendChild(title);
    heading.appendChild(description);
    var close = createNode('button', 'geo-market-panel__close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', '关闭算力市场能力');
    close.addEventListener('click', function () { backdrop.remove(); });
    header.appendChild(heading);
    header.appendChild(close);

    var grid = createNode('div', 'geo-market-panel__grid');
    [
      ['文本模型路由', '问题聚类、文章生成、优化建议', '按次结算'],
      ['实时检测', '豆包、DeepSeek、元宝、千问等平台', '多平台可扩展'],
      ['内容分发', '官网、媒体、自媒体与海外渠道', '结果可追踪'],
    ].forEach(function (item) {
      var card = createNode('article');
      card.appendChild(createNode('strong', '', item[0]));
      card.appendChild(createNode('span', '', item[1]));
      card.appendChild(createNode('em', '', item[2]));
      grid.appendChild(card);
    });

    var footer = createNode('div', 'geo-market-panel__footer');
    footer.appendChild(createNode('span', '', '资源类型：app_api · 计费单位：次 · 状态：ready'));
    var copy = createNode('button', '', '复制接入说明');
    copy.type = 'button';
    copy.addEventListener('click', function () {
      var done = function () { toast('接入说明已复制'); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(marketText).then(done).catch(done);
      } else {
        done();
      }
    });
    footer.appendChild(copy);

    panel.appendChild(header);
    panel.appendChild(grid);
    panel.appendChild(footer);
    backdrop.appendChild(panel);
    backdrop.addEventListener('click', function (event) {
      if (event.target === backdrop) backdrop.remove();
    });
    document.body.appendChild(backdrop);
  }

  function enhanceLanding() {
    var heroCopy = document.querySelector('.hero-copy');
    if (heroCopy && !heroCopy.querySelector('.geo-market-kicker')) {
      heroCopy.insertBefore(createNode('span', 'geo-market-kicker', '算力超市 · 上架准备就绪'), heroCopy.firstChild);
    }

    var searchConsole = document.querySelector('.search-console');
    if (searchConsole && !document.querySelector('.geo-market-strip')) {
      var strip = createNode('div', 'geo-market-strip');
      [
        ['多模型路由', '按 AI 平台拆解答案来源'],
        ['按量结算', '检测与生成共用算力账户'],
        ['可观测结果', '任务、引用、收录统一追踪'],
      ].forEach(function (item) {
        var card = createNode('article');
        card.appendChild(createNode('strong', '', item[0]));
        card.appendChild(createNode('span', '', item[1]));
        strip.appendChild(card);
      });
      searchConsole.parentNode.insertBefore(strip, searchConsole.nextSibling);
    }
  }

  function enhanceConsole() {
    var actions = document.querySelector('.geo-topbar__actions');
    if (actions && !actions.querySelector('.geo-market-button')) {
      var button = createNode('button', 'el-button geo-market-button', '算力市场');
      button.type = 'button';
      button.addEventListener('click', showMarketPanel);
      actions.insertBefore(button, actions.firstChild);
    }

    var menuScroll = document.querySelector('.geo-console__menu-scroll');
    if (menuScroll && !document.querySelector('.geo-market-sidebar')) {
      var sideButton = createNode('button', 'geo-market-sidebar', '算力市场');
      sideButton.type = 'button';
      sideButton.addEventListener('click', showMarketPanel);
      menuScroll.parentNode.insertBefore(sideButton, document.querySelector('.geo-console__user-card'));
    }

    var topbar = document.querySelector('.geo-console__topbar');
    if (topbar && !document.querySelector('.geo-market-banner')) {
      var banner = createNode('div', 'geo-market-banner');
      banner.appendChild(createNode('strong', '', '算力市场接入层已就绪'));
      banner.appendChild(createNode('span', '', '文本、检测、分发能力统一按量结算，后续绑定 SKU 即可上架。'));
      topbar.parentNode.insertBefore(banner, topbar.nextSibling);
    }
  }

  function enhance() {
    if (landing) enhanceLanding();
    else enhanceConsole();
  }

  var observer = new MutationObserver(enhance);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', enhance);
  } else {
    enhance();
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      var panel = document.querySelector('.geo-market-backdrop');
      if (panel) panel.remove();
    }
  });
})();
