/* 机场图谱 — admin interactions */
(function () {
  "use strict";

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function nextIndex() {
    var max = -1;
    $$('[name^="plan_name["]').forEach(function (input) {
      var match = input.name.match(/plan_name\[(\d+)\]/);
      if (match) max = Math.max(max, parseInt(match[1], 10));
    });
    return max + 1;
  }

  function initPlanEditor() {
    var tbody = $("[data-plan-rows]");
    var template = $("#plan-row-template");
    var add = $("[data-plan-add]");
    if (!tbody || !template || !add) return;

    add.addEventListener("click", function () {
      var html = template.innerHTML.split("__IDX__").join(String(nextIndex()));
      var wrap = document.createElement("tbody");
      wrap.innerHTML = html.trim();
      var row = wrap.firstElementChild;
      if (row) {
        tbody.appendChild(row);
        var first = $('input[name^="plan_name"]', row);
        if (first) first.focus();
      }
    });

    tbody.addEventListener("click", function (event) {
      var remove = event.target.closest("[data-plan-remove]");
      if (!remove) return;
      var row = remove.closest("tr");
      if (row) row.remove();
    });

    tbody.addEventListener("change", function (event) {
      var select = event.target.closest("[data-period]");
      if (!select) return;
      var row = select.closest("tr");
      if (!row) return;
      var months = $('input[name^="plan_months"]', row);
      var option = select.options[select.selectedIndex];
      if (months && option) {
        months.value = option.getAttribute("data-months") || "";
      }
    });
  }

  function initDeleteForms() {
    $$("form[data-delete-form], form[data-confirm]").forEach(function (form) {
      form.addEventListener("submit", function (event) {
        var message = form.getAttribute("data-confirm") || "确认执行该操作？此操作不可恢复。";
        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    initPlanEditor();
    initDeleteForms();
  });
})();
