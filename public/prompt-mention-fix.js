(function () {
  "use strict";

  var promptSelectors = [
    "#image-prompt",
    "#video-prompt",
    "textarea.prompt-card__textarea"
  ];

  function isPromptTarget(target) {
    return target instanceof HTMLTextAreaElement && promptSelectors.some(function (selector) {
      return target.matches(selector);
    });
  }

  function referenceNames(target) {
    var names = [];
    var root = target.id === "image-prompt" || target.id === "video-prompt"
      ? target.closest(".creator-panel") || document
      : target.closest(".ai-create-composer") || document;

    root.querySelectorAll(".reference-item img[alt], .upload-panel__preview-card img[alt]").forEach(function (image) {
      var name = String(image.getAttribute("alt") || "").trim();
      if (name && names.indexOf(name) < 0) names.push(name);
    });

    return names;
  }

  function insertReferenceMention(target) {
    var names = referenceNames(target);
    if (!names.length) return false;

    var value = String(target.value || "");
    var start = Number.isInteger(target.selectionStart) ? target.selectionStart : value.length;
    var end = Number.isInteger(target.selectionEnd) ? target.selectionEnd : start;
    var used = names.filter(function (name) {
      return value.indexOf("@" + name) >= 0;
    });
    var name = names.find(function (candidate) {
      return used.indexOf(candidate) < 0;
    }) || names[0];
    var token = "@" + name;
    var before = value.slice(0, start).replace(/\s+$/, "");
    var after = value.slice(end);
    var next = before + (before ? " " : "") + token + after;
    var caret = before.length + (before ? 1 : 0) + token.length;

    target.value = next;
    target.setSelectionRange(caret, caret);
    target.dispatchEvent(new Event("input", { bubbles: true }));
    target.dispatchEvent(new Event("change", { bubbles: true }));
    return true;
  }

  function openStoryboardMentionMenu(target) {
    if (!(target instanceof HTMLElement) || !target.matches(".composer-editor")) return false;
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
    if (!isAtShortcut(event)) return;
    if (openStoryboardMentionMenu(event.target)) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (!isPromptTarget(event.target)) return;
    if (insertReferenceMention(event.target)) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);

  document.addEventListener("beforeinput", function (event) {
    if (event.inputType !== "insertText" || event.data !== "@") return;
    if (openStoryboardMentionMenu(event.target)) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    if (isPromptTarget(event.target) && insertReferenceMention(event.target)) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);
})();
