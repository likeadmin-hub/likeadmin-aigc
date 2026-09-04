(function () {
  "use strict";

  function editorFromTarget(target) {
    if (!(target instanceof Element)) return null;
    var editor = target.closest(".agent-composer-editor");
    if (!editor || editor.getAttribute("contenteditable") === "false") return null;
    return editor;
  }

  function insertAt(editor) {
    editor.focus();
    var selection = window.getSelection();
    var range = selection && selection.rangeCount && editor.contains(selection.anchorNode)
      ? selection.getRangeAt(0)
      : document.createRange();

    if (!selection || !selection.rangeCount || !editor.contains(selection.anchorNode)) {
      range.selectNodeContents(editor);
      range.collapse(false);
    }

    range.deleteContents();
    var text = document.createTextNode("@");
    range.insertNode(text);
    range.setStartAfter(text);
    range.collapse(true);

    if (selection) {
      selection.removeAllRanges();
      selection.addRange(range);
    }

    editor.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function notifyMentionShortcut(editor) {
    editor.dispatchEvent(new KeyboardEvent("keydown", {
      bubbles: true,
      cancelable: true,
      key: "@",
      code: "Digit2",
      shiftKey: true
    }));
  }

  document.addEventListener("beforeinput", function (event) {
    if (event.inputType !== "insertText" || event.data !== "@") return;
    var editor = editorFromTarget(event.target);
    if (!editor) return;

    // Some browsers and older cached bundles cancel @ before it reaches the editor.
    event.preventDefault();
    event.stopImmediatePropagation();
    insertAt(editor);
    notifyMentionShortcut(editor);
  }, true);
})();
