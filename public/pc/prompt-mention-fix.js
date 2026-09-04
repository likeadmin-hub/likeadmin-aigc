(function () {
  "use strict";

  var promptSelectors = [
    "#image-prompt",
    "#video-prompt",
    "textarea.prompt-card__textarea"
  ];
  var activeMenu = null;
  var activeTarget = null;
  var savedSelection = null;

  function isPromptTarget(target) {
    return target instanceof HTMLTextAreaElement && promptSelectors.some(function (selector) {
      return target.matches(selector);
    });
  }

  function isStoryboardEditor(target) {
    return target instanceof HTMLElement && target.matches(".composer-editor");
  }

  function rootFor(target) {
    if (target.id === "image-prompt" || target.id === "video-prompt") {
      return target.closest(".creator-panel") || document;
    }
    return target.closest(".ai-create-composer") || document;
  }

  function collectReferenceItems(target) {
    var root = rootFor(target);
    var nodes = root.querySelectorAll(".reference-item, .upload-panel__preview-frame");
    var items = [];
    var counters = { img: 0, video: 0, audio: 0, reference: 0 };

    Array.prototype.forEach.call(nodes, function (node) {
      var media = node.querySelector("img[alt], video, audio");
      var name = media && media.getAttribute("alt");
      var detail = String(name || "").trim();
      if (!detail) {
        var small = node.querySelector("small");
        detail = String((small && small.textContent) || "").trim();
      }
      var type = media ? media.tagName.toLowerCase() : "";
      if (!type && (node.matches(".is-audio") || node.querySelector(".upload-panel__audio"))) type = "audio";
      if (!type && node.matches(".is-video")) type = "video";
      if (!type) type = "reference";
      counters[type] += 1;
      var prefix = type === "img" ? "图片" : type === "video" ? "视频" : type === "audio" ? "音频" : "素材";
      var mentionName = prefix + counters[type];
      var url = media && (media.currentSrc || media.getAttribute("src"));
      items.push({ name: mentionName, detail: detail, url: url || "", type: type });
    });

    return items;
  }

  function closeMenu() {
    if (activeMenu && activeMenu.parentNode) activeMenu.parentNode.removeChild(activeMenu);
    activeMenu = null;
    activeTarget = null;
    savedSelection = null;
  }

  function positionMenu(menu, target) {
    var rect = target.getBoundingClientRect();
    var width = 320;
    var left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
    var top = rect.bottom + 6;
    if (top + 220 > window.innerHeight) top = Math.max(8, rect.top - 226);
    menu.style.left = left + "px";
    menu.style.top = top + "px";
  }

  function insertTextareaMention(target, name) {
    var value = String(target.value || "");
    var start = Number.isInteger(target.selectionStart) ? target.selectionStart : value.length;
    var end = Number.isInteger(target.selectionEnd) ? target.selectionEnd : start;
    var beforeCaret = value.slice(0, start);
    var atIndex = beforeCaret.lastIndexOf("@");
    var pendingAt = atIndex >= 0
      && (atIndex === 0 || /\s/.test(beforeCaret.charAt(atIndex - 1)))
      && !/\s/.test(beforeCaret.slice(atIndex + 1));
    var tokenStart = pendingAt ? atIndex : start;
    var before = value.slice(0, tokenStart).replace(/\s+$/, "");
    var after = value.slice(end);
    var token = "@" + name;
    var next = before + (before ? " " : "") + token + after;
    var caret = before.length + (before ? 1 : 0) + token.length;

    target.value = next;
    target.setSelectionRange(caret, caret);
    target.dispatchEvent(new Event("input", { bubbles: true }));
    target.dispatchEvent(new Event("change", { bubbles: true }));
    target.focus();
  }

  function insertStoryboardMention(target, name) {
    target.focus();
    var selection = window.getSelection && window.getSelection();
    if (selection && savedSelection) {
      selection.removeAllRanges();
      selection.addRange(savedSelection);
    }
    var token = "@" + name;
    if (document.execCommand) {
      document.execCommand("insertText", false, token);
    } else if (selection && selection.rangeCount) {
      selection.getRangeAt(0).insertNode(document.createTextNode(token));
    }
    target.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function openReferenceMenu(target, items) {
    closeMenu();
    activeTarget = target;
    var selection = window.getSelection && window.getSelection();
    savedSelection = selection && selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;

    var menu = document.createElement("div");
    menu.className = "prompt-mention-picker";
    menu.setAttribute("role", "listbox");
    menu.style.cssText = "position:fixed;width:320px;max-width:calc(100vw - 16px);max-height:240px;overflow:auto;padding:6px;background:#202124;border:1px solid #4a4d52;border-radius:8px;box-shadow:0 10px 28px rgba(0,0,0,.35);z-index:2147483647;font:13px/1.4 -apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;color:#f5f6f7";

    items.forEach(function (item) {
      var button = document.createElement("button");
      button.type = "button";
      button.className = "prompt-mention-picker__item";
      button.setAttribute("role", "option");
      button.title = "@" + item.name;
      button.style.cssText = "display:flex;align-items:center;width:100%;gap:9px;padding:7px 8px;border:0;border-radius:5px;background:transparent;color:inherit;text-align:left;cursor:pointer";
      button.addEventListener("mouseenter", function () { button.style.background = "#34373d"; });
      button.addEventListener("mouseleave", function () { button.style.background = "transparent"; });
      if (item.url && item.type === "img") {
        var image = document.createElement("img");
        image.src = item.url;
        image.alt = "";
        image.style.cssText = "width:32px;height:32px;object-fit:cover;border-radius:4px;background:#303238;flex:none";
        button.appendChild(image);
      } else {
        var marker = document.createElement("span");
        marker.textContent = item.type === "video" ? "VID" : "REF";
        marker.style.cssText = "display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:4px;background:#34373d;color:#b9c0ca;font-size:10px;flex:none";
        button.appendChild(marker);
      }
      var label = document.createElement("span");
      label.textContent = "@" + item.name;
      label.style.cssText = "display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap";
      button.appendChild(label);
      if (item.detail && item.detail !== item.name) {
        var detail = document.createElement("small");
        detail.textContent = item.detail;
        detail.style.cssText = "display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#9da3ad";
        label.appendChild(detail);
      }
      button.addEventListener("click", function (event) {
        event.preventDefault();
        event.stopPropagation();
        if (activeTarget instanceof HTMLTextAreaElement) insertTextareaMention(activeTarget, item.name);
        else if (isStoryboardEditor(activeTarget)) insertStoryboardMention(activeTarget, item.name);
        closeMenu();
      });
      menu.appendChild(button);
    });

    document.body.appendChild(menu);
    activeMenu = menu;
    positionMenu(menu, target);
  }

  function openStoryboardMentionMenu(target) {
    var context = target.closest(".composer-shell, .composer, .storyboard-page");
    var button = context ? context.querySelector(".mention-btn") : null;
    if (!button) button = document.querySelector(".mention-btn");
    if (!button) return false;
    button.click();
    return true;
  }

  function isAtShortcut(event) {
    return (event.key === "@" || (event.code === "Digit2" && event.shiftKey))
      && !event.ctrlKey && !event.metaKey && !event.altKey;
  }

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      if (activeMenu) {
        event.preventDefault();
        event.stopImmediatePropagation();
        closeMenu();
      }
      return;
    }
    if (!isAtShortcut(event)) return;
    if (isStoryboardEditor(event.target)) {
      if (openStoryboardMentionMenu(event.target)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
      return;
    }
    if (!isPromptTarget(event.target) || event.target.closest(".ai-create-composer")) return;
    var items = collectReferenceItems(event.target);
    if (!items.length) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openReferenceMenu(event.target, items);
  }, true);

  document.addEventListener("beforeinput", function (event) {
    if (event.inputType !== "insertText" || event.data !== "@") return;
    if (isStoryboardEditor(event.target)) {
      if (openStoryboardMentionMenu(event.target)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
      return;
    }
    if (!isPromptTarget(event.target) || event.target.closest(".ai-create-composer")) return;
    var items = collectReferenceItems(event.target);
    if (!items.length) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    openReferenceMenu(event.target, items);
  }, true);

  document.addEventListener("pointerdown", function (event) {
    if (activeMenu && !activeMenu.contains(event.target) && event.target !== activeTarget) closeMenu();
  }, true);
  window.addEventListener("resize", function () {
    if (activeMenu && activeTarget) positionMenu(activeMenu, activeTarget);
  });
  window.addEventListener("scroll", function () {
    if (activeMenu && activeTarget) positionMenu(activeMenu, activeTarget);
  }, true);
})();
