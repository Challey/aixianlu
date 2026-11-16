/* 机场图谱 — lightweight interactions, no dependencies */
(function () {
  "use strict";

  var COMPARE_KEY = "atlas.compare.v1";
  var COMPARE_MAX = 4;
  var USD_CNY = 7.2;

  function $(sel, root) {
    return (root || document).querySelector(sel);
  }

  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function on(el, event, fn) {
    if (el) el.addEventListener(event, fn);
  }

  function readCompare() {
    try {
      var raw = JSON.parse(localStorage.getItem(COMPARE_KEY) || "[]");
      return Array.isArray(raw) ? raw.filter(function (s) { return typeof s === "string"; }).slice(0, COMPARE_MAX) : [];
    } catch (err) {
      return [];
    }
  }

  function writeCompare(list) {
    var unique = [];
    list.forEach(function (slug) {
      if (slug && unique.indexOf(slug) === -1 && unique.length < COMPARE_MAX) unique.push(slug);
    });
    try {
      localStorage.setItem(COMPARE_KEY, JSON.stringify(unique));
    } catch (err) {
      /* storage may be unavailable */
    }
    syncCompareUI(unique);
    document.dispatchEvent(new CustomEvent("compare:change", { detail: unique }));
    return unique;
  }

  function syncCompareUI(list) {
    $$("[data-compare-count]").forEach(function (el) {
      el.textContent = String(list.length);
    });
    $$("input.js-compare").forEach(function (input) {
      input.checked = list.indexOf(input.value) !== -1;
    });
    $$("[data-compare-toggle]").forEach(function (btn) {
      var slug = btn.getAttribute("data-compare-toggle");
      var active = list.indexOf(slug) !== -1;
      btn.classList.toggle("is-selected", active);
      btn.setAttribute("aria-pressed", active ? "true" : "false");
      btn.textContent = active ? "已加入对比" : "加入对比";
    });
    renderTray(list);
  }

  var trayEl = null;
  function renderTray(list) {
    trayEl = trayEl || $("[data-compare-tray]");
    if (!trayEl) return;
    var isComparePage = document.body.classList.contains("page-compare");
    trayEl.hidden = list.length === 0 || isComparePage;
    document.body.classList.toggle("has-compare-tray", !trayEl.hidden);
    var items = $("[data-compare-items]", trayEl);
    if (!items) return;
    items.innerHTML = "";
    list.forEach(function (slug) {
      var chip = document.createElement("span");
      chip.className = "compare-tray__item";
      var label = document.createElement("span");
      label.textContent = slug;
      chip.appendChild(label);
      var remove = document.createElement("button");
      remove.type = "button";
      remove.setAttribute("aria-label", "移除 " + slug);
      remove.textContent = "×";
      on(remove, "click", function () {
        writeCompare(readCompare().filter(function (s) { return s !== slug; }));
      });
      chip.appendChild(remove);
      items.appendChild(chip);
    });
    var go = $("[data-compare-go]", trayEl);
    if (go) go.href = "/compare/?p=" + encodeURIComponent(list.join(","));
    var clear = $("[data-compare-clear]", trayEl);
    if (clear) {
      clear.onclick = function () {
        writeCompare([]);
      };
    }
  }

  function toast(message) {
    var el = $(".toast");
    if (!el) {
      el = document.createElement("div");
      el.className = "toast";
      el.setAttribute("role", "status");
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.classList.add("is-visible");
    clearTimeout(el._timer);
    el._timer = setTimeout(function () {
      el.classList.remove("is-visible");
    }, 2200);
  }

  function initNav() {
    var toggle = $("[data-nav-toggle]");
    on(toggle, "click", function () {
      var open = document.body.classList.toggle("is-nav-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    $$(".site-nav a").forEach(function (a) {
      on(a, "click", function () {
        document.body.classList.remove("is-nav-open");
        if (toggle) toggle.setAttribute("aria-expanded", "false");
      });
    });
  }

  function initCompareInputs() {
    $$("[data-compare-toggle]").forEach(function (btn) {
      on(btn, "click", function () {
        var slug = btn.getAttribute("data-compare-toggle");
        var list = readCompare();
        if (list.indexOf(slug) !== -1) {
          writeCompare(list.filter(function (s) { return s !== slug; }));
          return;
        }
        if (list.length >= COMPARE_MAX) {
          toast("最多同时对比 " + COMPARE_MAX + " 家");
          return;
        }
        list.push(slug);
        writeCompare(list);
        toast("已加入对比");
      });
    });
    $$("input.js-compare").forEach(function (input) {
      on(input, "change", function () {
        var list = readCompare();
        if (input.checked) {
          if (list.length >= COMPARE_MAX) {
            input.checked = false;
            toast("最多同时对比 " + COMPARE_MAX + " 家");
            return;
          }
          if (list.indexOf(input.value) === -1) list.push(input.value);
        } else {
          list = list.filter(function (s) { return s !== input.value; });
        }
        writeCompare(list);
      });
    });
  }

  function initCopy() {
    $$("[data-copy]").forEach(function (el) {
      on(el, "click", function () {
        var text = el.getAttribute("data-copy");
        function done() {
          toast("已复制优惠码 " + text);
          var hint = $("span", el);
          if (hint) hint.textContent = "已复制";
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(done, done);
        } else {
          var area = document.createElement("textarea");
          area.value = text;
          document.body.appendChild(area);
          area.select();
          try { document.execCommand("copy"); } catch (err) { /* ignore */ }
          document.body.removeChild(area);
          done();
        }
      });
    });
  }

  /* ---------------------------------------------------------------------
     Providers list
     --------------------------------------------------------------------- */

  function initProviderList() {
    var table = $("[data-provider-table]");
    if (!table) return;
    var tbody = $("tbody", table);
    var rows = $$("tr", tbody);
    var search = $("[data-filter-search]");
    var line = $("[data-filter-line]");
    var protocol = $("[data-filter-protocol]");
    var region = $("[data-filter-region]");
    var price = $("[data-filter-price]");
    var sort = $("[data-filter-sort]");
    var count = $("[data-filter-count]");
    var empty = $("[data-filter-empty]");
    var toggle = $("[data-filter-toggle]");
    var panel = $("[data-filter-panel]");
    var badge = $("[data-filter-badge]");
    var reset = $("[data-filter-reset]");
    var featureTags = $$("[data-filter-feature]");
    var activeFeatures = [];

    var params = new URLSearchParams(window.location.search);
    if (params.get("q") && search) search.value = params.get("q");
    if (params.get("line") && line) line.value = params.get("line");
    if (params.get("protocol") && protocol) protocol.value = params.get("protocol");
    if (params.get("region") && region) region.value = params.get("region");
    if (params.get("feature") && featureTags.length) {
      activeFeatures = params.get("feature").split(",");
    }

    function activeFilterCount() {
      var n = 0;
      [line, protocol, region, price].forEach(function (el) {
        if (el && el.value) n++;
      });
      return n + activeFeatures.length;
    }

    function syncFilterUI() {
      var n = activeFilterCount();
      if (badge) {
        badge.hidden = n === 0;
        badge.textContent = String(n);
      }
      if (toggle) toggle.classList.toggle("is-active", n > 0);
    }

    if (toggle && panel) {
      on(toggle, "click", function () {
        var open = panel.hidden;
        panel.hidden = !open;
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
      });
      if (["line", "protocol", "region", "feature"].some(function (key) { return params.get(key); })) {
        panel.hidden = false;
        toggle.setAttribute("aria-expanded", "true");
      }
    }

    if (reset) {
      on(reset, "click", function () {
        [line, protocol, region, price].forEach(function (el) { if (el) el.value = ""; });
        activeFeatures = [];
        featureTags.forEach(function (tag) { tag.classList.remove("is-active"); });
        apply();
      });
    }

    featureTags.forEach(function (tag) {
      var value = tag.getAttribute("data-filter-feature");
      if (activeFeatures.indexOf(value) !== -1) tag.classList.add("is-active");
      on(tag, "click", function () {
        var idx = activeFeatures.indexOf(value);
        if (idx === -1) activeFeatures.push(value);
        else activeFeatures.splice(idx, 1);
        tag.classList.toggle("is-active", idx === -1);
        apply();
      });
    });

    function numeric(row, attr, fallback) {
      var v = parseFloat(row.getAttribute(attr));
      return isNaN(v) ? fallback : v;
    }

    function matches(row) {
      if (search) {
        var q = search.value.trim().toLowerCase();
        if (q) {
          var haystack = (row.getAttribute("data-search") || "").toLowerCase();
          if (haystack.indexOf(q) === -1) return false;
        }
      }
      if (line && line.value && row.getAttribute("data-line") !== line.value) return false;
      if (protocol && protocol.value) {
        var protocols = (row.getAttribute("data-protocols") || "").split(" ");
        if (protocols.indexOf(protocol.value) === -1) return false;
      }
      if (region && region.value) {
        var regions = (row.getAttribute("data-regions") || "").split(" ");
        if (regions.indexOf(region.value) === -1) return false;
      }
      if (price && price.value) {
        var max = parseFloat(price.value);
        var monthly = numeric(row, "data-monthly", 999999);
        if (monthly > max) return false;
      }
      if (activeFeatures.length) {
        var features = (row.getAttribute("data-features") || "").split("|");
        for (var i = 0; i < activeFeatures.length; i++) {
          if (features.indexOf(activeFeatures[i]) === -1) return false;
        }
      }
      return true;
    }

    function applySort() {
      if (!sort) return;
      var mode = sort.value;
      if (mode === "default") {
        var recommended = rows.slice().sort(function (a, b) {
          var diff = numeric(b, "data-weight", 0) - numeric(a, "data-weight", 0);
          if (diff) return diff;
          return numeric(a, "data-order", 9999) - numeric(b, "data-order", 9999);
        });
        recommended.forEach(function (row) { tbody.appendChild(row); });
        return;
      }
      var sorted = rows.slice().sort(function (a, b) {
        switch (mode) {
          case "price-asc":
            return numeric(a, "data-monthly", 999999) - numeric(b, "data-monthly", 999999);
          case "price-desc":
            return numeric(b, "data-monthly", 0) - numeric(a, "data-monthly", 0);
          case "unit-asc":
            return numeric(a, "data-unit", 999999) - numeric(b, "data-unit", 999999);
          case "regions-desc":
            return numeric(b, "data-regions-count", 0) - numeric(a, "data-regions-count", 0);
          case "nodes-desc":
            return numeric(b, "data-nodes", 0) - numeric(a, "data-nodes", 0);
          case "traffic-desc":
            return numeric(b, "data-traffic", 0) - numeric(a, "data-traffic", 0);
          case "founded-asc":
            return numeric(a, "data-founded", 9999) - numeric(b, "data-founded", 9999);
          default:
            return 0;
        }
      });
      sorted.forEach(function (row) { tbody.appendChild(row); });
    }

    function apply() {
      applySort();
      var visible = 0;
      rows.forEach(function (row) {
        var ok = matches(row);
        row.hidden = !ok;
        if (ok) visible++;
      });
      if (count) count.textContent = visible + " / " + rows.length + " 家";
      if (empty) empty.hidden = visible !== 0;
      table.hidden = visible === 0;
      var url = new URL(window.location.href);
      ["q", "line", "protocol", "region", "feature"].forEach(function (key) {
        url.searchParams.delete(key);
      });
      if (search && search.value.trim()) url.searchParams.set("q", search.value.trim());
      if (line && line.value) url.searchParams.set("line", line.value);
      if (protocol && protocol.value) url.searchParams.set("protocol", protocol.value);
      if (region && region.value) url.searchParams.set("region", region.value);
      if (activeFeatures.length) url.searchParams.set("feature", activeFeatures.join(","));
      window.history.replaceState(null, "", url.toString());
      syncFilterUI();
    }

    var debounce = null;
    on(search, "input", function () {
      clearTimeout(debounce);
      debounce = setTimeout(apply, 120);
    });
    [line, protocol, region, price, sort].forEach(function (el) {
      on(el, "change", apply);
    });
    apply();
  }

  /* ---------------------------------------------------------------------
     Compare page
     --------------------------------------------------------------------- */

  var indexPromise = null;
  function loadIndex() {
    if (indexPromise) return indexPromise;
    indexPromise = fetch("/data/providers.json", { credentials: "same-origin" })
      .then(function (res) {
        if (!res.ok) throw new Error("index unavailable");
        return res.json();
      })
      .catch(function () { return []; });
    return indexPromise;
  }

  function money(value, currency) {
    if (value === null || value === undefined || value === "") return "—";
    var symbol = currency === "USD" ? "$" : "￥";
    var rounded = Math.round(value * 100) / 100;
    return symbol + rounded.toLocaleString("zh-CN", { maximumFractionDigits: 2 });
  }

  function cnyMonthly(p) {
    var value = p.monthly_price_from;
    if (value === null || value === undefined) {
      if (p.price_from_period === "月付") value = p.price_from;
      else if (p.price_from && p.price_from_period === "按量") value = null;
      else value = p.price_from;
    }
    if (value === null || value === undefined) return null;
    return p.price_currency === "USD" ? value * USD_CNY : value;
  }

  function entryLabel(p) {
    if (p.price_from === null || p.price_from === undefined) return "待补录";
    var period = p.price_from_period || "";
    var short = { "月付": "/月", "季付": "/季", "半年付": "/半年", "年付": "/年", "按量": " 按量" }[period] || "";
    return money(p.price_from, p.price_currency) + short;
  }

  function monthlyLabel(p) {
    var value = cnyMonthly(p);
    if (value === null) return "—";
    return "￥" + (Math.round(value * 100) / 100).toLocaleString("zh-CN", { maximumFractionDigits: 2 }) + "/月";
  }

  function trafficLabel(p) {
    if (!p.traffic_from) return "—";
    var gb = p.traffic_from;
    return (gb >= 1024 ? (Math.round((gb / 1024) * 100) / 100) + " TB" : Math.round(gb) + " GB") + "/月";
  }

  function unitLabel(p) {
    if (!p.unit_price_from) return "—";
    var value = p.unit_price_from;
    var symbol = p.price_currency === "USD" ? "$" : "￥";
    return symbol + (Math.round(value * 1000) / 1000) + "/GB";
  }

  function listText(items, empty) {
    if (!items || !items.length) return empty || "未标注";
    return items.join("、");
  }

  function initComparePage() {
    var root = $("[data-compare-page]");
    if (!root) return;
    var wrap = $("[data-compare-table-wrap]", root);
    var empty = $("[data-compare-empty]", root);
    var addSelect = $("[data-compare-add]");
    var params = new URLSearchParams(window.location.search);
    var fromUrl = params.get("p");
    var list = fromUrl ? fromUrl.split(",").filter(Boolean) : readCompare();
    list = list.slice(0, COMPARE_MAX);

    function render(index) {
      var bySlug = {};
      index.forEach(function (p) { bySlug[p.slug] = p; });
      list = list.filter(function (slug) { return bySlug[slug]; });
      writeCompare(list);

      if (addSelect) {
        addSelect.innerHTML = '<option value="">＋ 添加一家机场</option>';
        index.forEach(function (p) {
          if (list.indexOf(p.slug) !== -1) return;
          var opt = document.createElement("option");
          opt.value = p.slug;
          opt.textContent = p.name;
          addSelect.appendChild(opt);
        });
      }

      if (!list.length) {
        if (wrap) wrap.hidden = true;
        if (empty) empty.hidden = false;
        return;
      }
      if (wrap) wrap.hidden = false;
      if (empty) empty.hidden = true;

      var selected = list.map(function (slug) { return bySlug[slug]; });
      var best = {
        monthly: null,
        traffic: null,
        unit: null,
        regions: null,
        nodes: null
      };
      selected.forEach(function (p) {
        var m = cnyMonthly(p);
        if (m !== null && (best.monthly === null || m < best.monthly)) best.monthly = m;
        if (p.traffic_from && (best.traffic === null || p.traffic_from > best.traffic)) best.traffic = p.traffic_from;
        if (p.unit_price_from && (best.unit === null || p.unit_price_from < best.unit)) best.unit = p.unit_price_from;
        if (best.regions === null || p.region_count > best.regions) best.regions = p.region_count;
        if (best.nodes === null || (p.node_count || 0) > best.nodes) best.nodes = p.node_count || 0;
      });

      function cell(value, cls) {
        return '<td class="' + (cls || "") + '">' + value + "</td>";
      }
      function bestClass(active) {
        return active ? " is-best" : "";
      }

      var html = '<table class="compare-table"><thead><tr><th></th>';
      selected.forEach(function (p) {
        html += '<th class="cmp-provider">' +
          '<div class="cmp-provider__name"><a href="/providers/' + p.slug + '/">' + escapeHtml(p.name) + "</a></div>" +
          '<span class="cmp-provider__sub">' + escapeHtml(p.line_type) + " · " + p.region_count + " 个地区 · " + p.plan_count + " 个套餐</span>" +
          '<button type="button" class="cmp-remove" data-remove="' + p.slug + '">移除</button>' +
          "</th>";
      });
      html += "</tr></thead><tbody>";

      var rows = [
        ["入门价", function (p) { return entryLabel(p); }],
        ["月均价格", function (p, i) {
          return '<span class="' + bestClass(cnyMonthly(p) !== null && cnyMonthly(p) === best.monthly) + '">' + monthlyLabel(p) + "</span>";
        }],
        ["月流量", function (p) {
          return '<span class="' + bestClass(p.traffic_from && p.traffic_from === best.traffic) + '">' + trafficLabel(p) + "</span>";
        }],
        ["单位流量价", function (p) {
          return '<span class="' + bestClass(p.unit_price_from && p.unit_price_from === best.unit) + '">' + unitLabel(p) + "</span>";
        }],
        ["线路类型", function (p) { return escapeHtml(p.line_type); }],
        ["协议", function (p) { return escapeHtml(listText(p.protocols, "未标注")); }],
        ["地区数", function (p) {
          return '<span class="' + bestClass(p.region_count === best.regions) + '">' + p.region_count + " 个</span>";
        }],
        ["节点数", function (p) {
          var label = p.node_count_label || ((p.node_count || 0) + "+");
          var title = p.node_count_note || (p.node_count_disclosed ? "来源明确披露" : "按服务器地区数计下限");
          return '<span class="' + bestClass((p.node_count || 0) === best.nodes) + '" title="' + escapeHtml(title) + '">' + escapeHtml(label) + "</span>";
        }],
        ["主要地区", function (p) { return escapeHtml(listText((p.regions || []).slice(0, 8), "未标注")); }],
        ["成立年份", function (p) { return p.founded ? p.founded + " 年" : "未标注"; }],
        ["流媒体解锁", function (p) { return escapeHtml(listText(p.media_unlock, "未提及")); }],
        ["AI 解锁", function (p) { return escapeHtml(listText(p.ai_unlock, "未提及")); }],
        ["免费试用", function (p) { return (p.features || []).indexOf("免费试用") !== -1 ? "支持" : "未提及"; }],
        ["定制客户端", function (p) { return (p.features || []).indexOf("定制客户端") !== -1 ? "支持" : "未提及"; }],
        ["优惠", function (p) {
          if (p.discount_code) return '<span class="badge badge--blue">' + escapeHtml(p.discount_code) + "</span>";
          return escapeHtml(p.discount_label || "—");
        }],
        ["套餐数", function (p) { return p.plan_count + " 个"; }]
      ];
      rows.forEach(function (row) {
        html += '<tr><th scope="row">' + row[0] + "</th>";
        selected.forEach(function (p, i) {
          html += cell(row[1](p, i), "num");
        });
        html += "</tr>";
      });
      html += '<tr><th scope="row">详情</th>';
      selected.forEach(function (p) {
        html += '<td><a class="text-link" href="/providers/' + p.slug + '/">查看详情</a></td>';
      });
      html += "</tr></tbody></table>";
      if (wrap) wrap.innerHTML = html;

      $$("[data-remove]", wrap).forEach(function (btn) {
        on(btn, "click", function () {
          list = list.filter(function (slug) { return slug !== btn.getAttribute("data-remove"); });
          render(index);
        });
      });
    }

    on(addSelect, "change", function () {
      if (!addSelect.value) return;
      if (list.length >= COMPARE_MAX) {
        toast("最多同时对比 " + COMPARE_MAX + " 家");
        addSelect.value = "";
        return;
      }
      list.push(addSelect.value);
      addSelect.value = "";
      loadIndex().then(render);
    });

    loadIndex().then(render);
  }

  function escapeHtml(value) {
    return String(value === null || value === undefined ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  /* ---------------------------------------------------------------------
     Match quiz
     --------------------------------------------------------------------- */

  function initQuiz() {
    var quiz = $("[data-quiz]");
    if (!quiz) return;
    var steps = $$("[data-quiz-step]", quiz);
    var results = $("[data-quiz-results]", quiz);
    var back = $("[data-quiz-back]", quiz);
    var current = 0;
    var answers = {};

    function show(index) {
      current = Math.max(0, Math.min(index, steps.length - 1));
      steps.forEach(function (step, i) {
        step.classList.toggle("is-active", i === current);
      });
      $$("[data-quiz-progress] span", quiz).forEach(function (bar, i) {
        bar.classList.toggle("is-done", i <= current);
      });
      if (results) results.classList.remove("is-active");
      quiz.classList.remove("is-finished");
      if (back) back.hidden = current === 0;
    }

    $$("[data-quiz-option]", quiz).forEach(function (btn) {
      on(btn, "click", function () {
        var step = btn.closest("[data-quiz-step]");
        var key = step.getAttribute("data-key");
        answers[key] = btn.getAttribute("data-value");
        $$("[data-quiz-option]", step).forEach(function (b) { b.classList.remove("is-selected"); });
        btn.classList.add("is-selected");
        setTimeout(function () {
          if (current < steps.length - 1) show(current + 1);
          else finish();
        }, 160);
      });
    });

    on(back, "click", function () {
      if (results && results.classList.contains("is-active")) {
        show(steps.length - 1);
        return;
      }
      show(current - 1);
    });

    function finish() {
      loadIndex().then(function (index) {
        steps.forEach(function (step) { step.classList.remove("is-active"); });
        quiz.classList.add("is-finished");
        if (results) results.classList.add("is-active");
        renderResults(index, answers, results);
      });
    }

    function renderResults(index, a, container) {
      if (!container) return;
      var budget = parseFloat(a.budget || "50");
      var traffic = parseFloat(a.traffic || "100");
      var priority = a.priority || "balanced";

      var scored = index.map(function (p) {
        var score = 40;
        var reasons = [];
        var monthly = cnyMonthly(p);
        if (monthly !== null) {
          if (monthly <= budget) {
            score += 24;
            reasons.push("月均 " + monthlyLabel(p) + " 在预算内");
          } else if (monthly <= budget * 1.25) {
            score += 4;
          } else {
            score -= 26;
          }
        }
        if (p.traffic_from) {
          if (p.traffic_from >= traffic) {
            score += 20;
            reasons.push(trafficLabel(p) + " 流量够用");
          } else if (p.traffic_from >= traffic * 0.6) {
            score += 6;
          } else {
            score -= 12;
          }
        }
        if (priority === "stable" && p.line_type.indexOf("专线") !== -1) {
          score += 26;
          reasons.push("专线线路，稳定性优先");
        }
        if (priority === "value") {
          if (p.unit_price_from) {
            score += Math.max(0, 22 - p.unit_price_from * 110);
            if (p.unit_price_from <= 0.16) reasons.push("单位流量价低");
          }
        }
        if (priority === "unlock") {
          if ((p.media_unlock || []).length) {
            score += 12;
            reasons.push("支持 " + p.media_unlock.slice(0, 3).join("、") + " 解锁");
          }
          if ((p.ai_unlock || []).length) {
            score += 8;
          }
        }
        if (priority === "regions") {
          score += Math.min(24, p.region_count * 0.7);
          if (p.region_count >= 12) reasons.push(p.region_count + " 个地区节点");
        }
        if ((p.features || []).indexOf("免费试用") !== -1) score += 3;
        if ((p.features || []).indexOf("定制客户端") !== -1) score += 2;
        return { p: p, score: score, reasons: reasons };
      });

      scored.sort(function (a, b) { return b.score - a.score; });
      var top = scored.slice(0, 4);
      container.innerHTML = '<h2 style="font-size:1.4rem;margin-bottom:.4rem">为你筛出的 4 家</h2>' +
        '<p style="color:var(--muted);font-size:.86rem;margin-bottom:1.1rem">按预算、流量和线路偏好评分。价格与可用性以服务商官网为准。</p>';
      top.forEach(function (item, i) {
        var p = item.p;
        var reasons = item.reasons.slice(0, 2).join("；") || (p.summary || "").slice(0, 48);
        container.innerHTML +=
          '<div class="quiz-result">' +
            '<div class="quiz-result__rank">' + (i + 1) + "</div>" +
            '<div><div class="quiz-result__name"><a href="/providers/' + p.slug + '/">' + escapeHtml(p.name) + '</a></div>' +
            '<div class="quiz-result__reason">' + escapeHtml(reasons) + "</div></div>" +
            '<div class="quiz-result__price"><b>' + monthlyLabel(p) + "</b><span>" + entryLabel(p) + " 起</span></div>" +
          "</div>";
      });
      container.innerHTML += '<div class="compare-actions"><a class="btn btn--primary" href="/providers/">浏览全部机场</a>' +
        '<button class="btn btn--ghost" type="button" data-quiz-restart>重新选择</button></div>';
      on($("[data-quiz-restart]", container), "click", function () {
        answers = {};
        container.classList.remove("is-active");
        show(0);
      });
    }

    show(0);
  }

  /* ---------------------------------------------------------------------
     Home search
     --------------------------------------------------------------------- */

  /* ---------------------------------------------------------------------
     Agent application form
     --------------------------------------------------------------------- */

  function initAgentForm() {
    var form = $("[data-agent-form]");
    if (!form) return;
    var status = $("[data-form-status]", form);
    var button = $("button[type=submit]", form);
    var params = new URLSearchParams(window.location.search);
    if (params.get("type") === "supply") {
      var channel = $("#agent-channel", form);
      if (channel) channel.value = "资源供给";
    }

    on(form, "submit", function (ev) {
      ev.preventDefault();
      if (!status) return;
      status.hidden = true;
      status.classList.remove("is-error");
      if (button) {
        button.disabled = true;
        button.textContent = "提交中…";
      }
      fetch(form.action, {
        method: "POST",
        body: new FormData(form),
        headers: { Accept: "application/json" },
        credentials: "same-origin"
      })
        .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
        .then(function (result) {
          status.hidden = false;
          if (result.ok && result.data && result.data.ok) {
            status.textContent = result.data.message || "已收到申请，我们会尽快联系你。";
            form.reset();
          } else {
            status.classList.add("is-error");
            status.textContent = (result.data && result.data.message) || "提交失败，请稍后重试或直接邮件联系。";
          }
        })
        .catch(function () {
          status.hidden = false;
          status.classList.add("is-error");
          status.textContent = "网络异常，请稍后重试或直接邮件联系。";
        })
        .finally(function () {
          if (button) {
            button.disabled = false;
            button.textContent = "提交申请";
          }
        });
    });
  }

  function initNewsFilter() {
    var bar = $("[data-news-filter-bar]");
    if (!bar) return;
    var buttons = $$("[data-news-filter]", bar);
    var items = $$(".news-item");
    buttons.forEach(function (btn) {
      on(btn, "click", function () {
        var category = btn.getAttribute("data-news-filter");
        buttons.forEach(function (candidate) {
          candidate.classList.toggle("is-active", candidate === btn);
        });
        items.forEach(function (item) {
          var visible = category === "all" || item.getAttribute("data-category") === category;
          item.classList.toggle("is-hidden", !visible);
        });
      });
    });
  }

  function initHomeSearch() {
    var form = $("[data-home-search]");
    if (!form) return;
    on(form, "submit", function (ev) {
      var input = $("input[name=q]", form);
      if (!input || !input.value.trim()) {
        ev.preventDefault();
        window.location.href = "/providers/";
      }
    });
  }

  /* ------------------------------------------------------------------
     Local footprint: clicks + purchased/expiry, browser-only, no account
     ------------------------------------------------------------------ */
  var FP_KEY = "atlas.footprint.v1";
  var FP_DISMISS_KEY = "atlas.footprint.dismiss";
  var FP_DUE_DAYS = 7;

  function fpRead() {
    try {
      var raw = JSON.parse(localStorage.getItem(FP_KEY) || "[]");
      return Array.isArray(raw) ? raw.filter(function (r) { return r && typeof r.slug === "string"; }) : [];
    } catch (err) {
      return [];
    }
  }

  function fpWrite(list) {
    try {
      localStorage.setItem(FP_KEY, JSON.stringify(list));
    } catch (err) {
      /* storage unavailable */
    }
  }

  function fpFind(list, slug) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].slug === slug) return list[i];
    }
    return null;
  }

  function fpISO(d) {
    return d.getFullYear() + "-" + ("0" + (d.getMonth() + 1)).slice(-2) + "-" + ("0" + d.getDate()).slice(-2);
  }

  function fpToday() {
    return fpISO(new Date());
  }

  function fpAddDays(iso, days) {
    var d = new Date(iso + "T00:00:00");
    d.setDate(d.getDate() + days);
    return fpISO(d);
  }

  function fpDaysLeft(iso) {
    if (!iso) return null;
    var a = new Date(fpToday() + "T00:00:00");
    var b = new Date(iso + "T00:00:00");
    if (isNaN(b.getTime())) return null;
    return Math.round((b - a) / 86400000);
  }

  function fpStatus(rec) {
    if (!rec.purchased) return "clicked";
    var left = fpDaysLeft(rec.expiresOn);
    if (left === null) return "purchased";
    if (left < 0) return "expired";
    if (left <= FP_DUE_DAYS) return "due";
    return "active";
  }

  function fpStatusLabel(status) {
    return { clicked: "未标记购买", purchased: "已购", active: "使用中", due: "即将到期", expired: "已过期" }[status] || "已购";
  }

  function fpRecordClick(slug, name) {
    var list = fpRead();
    var rec = fpFind(list, slug);
    if (!rec) {
      rec = { slug: slug, name: name || slug, clicks: 0, firstClickAt: fpToday() };
      list.push(rec);
    }
    rec.clicks = (rec.clicks || 0) + 1;
    rec.lastClickAt = fpToday();
    if (name) rec.name = name;
    fpWrite(list);
    fpSyncBadges();
  }

  function fpMark(slug, name, period, expiresOn, note) {
    var list = fpRead();
    var rec = fpFind(list, slug);
    if (!rec) {
      rec = { slug: slug, name: name || slug, clicks: 0, firstClickAt: fpToday() };
      list.push(rec);
    }
    rec.purchased = true;
    rec.purchasedAt = fpToday();
    rec.period = period;
    rec.expiresOn = expiresOn;
    rec.note = note || "";
    if (name) rec.name = name;
    fpWrite(list);
  }

  function fpDueRecords(list) {
    return list.filter(function (r) {
      var s = fpStatus(r);
      return s === "due" || s === "expired";
    });
  }

  function fpSyncBadges() {
    var due = fpDueRecords(fpRead()).length;
    $$("[data-fp-due]").forEach(function (el) {
      el.hidden = due === 0;
      el.textContent = String(due);
    });
  }

  function fpEsc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  function fpBanner() {
    try {
      if (sessionStorage.getItem(FP_DISMISS_KEY) === fpToday()) return;
    } catch (err) { /* ignore */ }
    var due = fpDueRecords(fpRead());
    if (!due.length) return;
    var main = $("#main");
    if (!main || $(".fp-banner")) return;
    var banner = document.createElement("div");
    banner.className = "fp-banner";
    banner.setAttribute("role", "status");
    banner.innerHTML =
      '<div class="shell shell--wide fp-banner__inner">' +
      '<span>你有 <b>' + due.length + '</b> 个订阅即将到期或已过期，记得续费或更换。</span>' +
      '<span class="fp-banner__actions"><a href="/my/">查看我的足迹</a>' +
      '<button type="button" class="fp-banner__close" aria-label="关闭提醒">×</button></span></div>';
    main.insertBefore(banner, main.firstChild);
    on($(".fp-banner__close", banner), "click", function () {
      try {
        sessionStorage.setItem(FP_DISMISS_KEY, fpToday());
      } catch (err) { /* ignore */ }
      banner.remove();
    });
  }

  function fpOpenDialog(slug, name) {
    var dlg = $("#fp-dialog");
    if (!dlg || typeof dlg.showModal !== "function") return;
    var rec = fpFind(fpRead(), slug);
    $("#fp-slug").value = slug;
    $("[data-fp-dialog-name]").textContent = name || (rec && rec.name) || slug;
    var period = $("#fp-period");
    var expires = $("#fp-expires");
    period.value = rec && rec.period ? rec.period : "30";
    if (period.value !== "custom" && !(rec && rec.expiresOn)) {
      expires.value = fpAddDays(fpToday(), parseInt(period.value, 10) || 30);
    } else {
      expires.value = (rec && rec.expiresOn) || fpAddDays(fpToday(), 30);
    }
    $("#fp-note").value = (rec && rec.note) || "";
    dlg.showModal();
  }

  function fpInitDialog() {
    var dlg = $("#fp-dialog");
    if (!dlg) return;
    var form = $("#fp-form");
    var period = $("#fp-period");
    var expires = $("#fp-expires");
    on(period, "change", function () {
      if (period.value !== "custom") {
        expires.value = fpAddDays(fpToday(), parseInt(period.value, 10) || 30);
      }
    });
    on($("[data-fp-cancel]", dlg), "click", function () {
      dlg.close();
    });
    on(form, "submit", function (ev) {
      ev.preventDefault();
      var slug = $("#fp-slug").value;
      if (!slug || !expires.value) {
        expires.focus();
        return;
      }
      fpMark(slug, $("[data-fp-dialog-name]").textContent, period.value, expires.value, $("#fp-note").value);
      dlg.close();
      fpSyncBadges();
      fpRenderMy();
    });
  }

  function fpRowHTML(rec) {
    var status = fpStatus(rec);
    var left = fpDaysLeft(rec.expiresOn);
    var expiryText = "";
    if (rec.purchased && rec.expiresOn) {
      expiryText = rec.expiresOn + (left === null ? "" : left < 0 ? "（已过期 " + (-left) + " 天）" : "（剩 " + left + " 天）");
    }
    var meta = [];
    if (rec.clicks) meta.push("点击 " + rec.clicks + " 次");
    if (rec.lastClickAt) meta.push("最近 " + rec.lastClickAt);
    if (expiryText) meta.push("到期 " + expiryText);
    return (
      '<div class="fp-row" data-slug="' + fpEsc(rec.slug) + '">' +
      '<div class="fp-row__main">' +
      '<div class="fp-row__top"><a class="fp-row__name" href="/providers/' + fpEsc(rec.slug) + '/">' + fpEsc(rec.name || rec.slug) + '</a>' +
      '<span class="badge fp-status fp-status--' + status + '">' + fpStatusLabel(status) + "</span></div>" +
      '<p class="fp-row__meta">' + fpEsc(meta.join(" · ")) + "</p>" +
      (rec.note ? '<p class="fp-row__note">' + fpEsc(rec.note) + "</p>" : "") +
      "</div>" +
      '<div class="fp-row__actions">' +
      '<a class="btn btn--primary btn--small" href="/go/' + fpEsc(rec.slug) + '" rel="nofollow sponsored noopener" target="_blank" data-fp-slug="' + fpEsc(rec.slug) + '" data-fp-name="' + fpEsc(rec.name || rec.slug) + '">续费 / 官网</a>' +
      '<button class="btn btn--ghost btn--small" type="button" data-fp-edit="' + fpEsc(rec.slug) + '">改到期</button>' +
      '<button class="btn btn--ghost btn--small" type="button" data-fp-del="' + fpEsc(rec.slug) + '">删除</button>' +
      "</div></div>"
    );
  }

  function fpRenderMy() {
    var listEl = $("[data-fp-list]");
    if (!listEl) return;
    var empty = $("[data-fp-empty]");
    var summary = $("[data-fp-summary]");
    var records = fpRead();
    var order = { expired: 0, due: 1, active: 2, purchased: 3, clicked: 4 };
    records.sort(function (a, b) {
      var d = (order[fpStatus(a)] || 9) - (order[fpStatus(b)] || 9);
      if (d !== 0) return d;
      return String(b.lastClickAt || "").localeCompare(String(a.lastClickAt || ""));
    });
    if (!records.length) {
      listEl.innerHTML = "";
      if (empty) empty.hidden = false;
      if (summary) summary.hidden = true;
      return;
    }
    if (empty) empty.hidden = true;
    if (summary) {
      var due = fpDueRecords(records).length;
      var bought = records.filter(function (r) { return r.purchased; }).length;
      summary.hidden = false;
      summary.innerHTML =
        '<span class="chip">共 ' + records.length + " 家</span>" +
        '<span class="chip">已购 ' + bought + "</span>" +
        (due ? '<span class="chip chip--warn">待续费 ' + due + "</span>" : "");
    }
    listEl.innerHTML = records.map(fpRowHTML).join("");
  }

  function fpInitMy() {
    var listEl = $("[data-fp-list]");
    if (!listEl) return;
    fpRenderMy();
    on($("[data-fp-clear]"), "click", function () {
      if (window.confirm("清空本机所有足迹与到期记录？此操作不可恢复。")) {
        fpWrite([]);
        fpRenderMy();
        fpSyncBadges();
      }
    });
    on(listEl, "click", function (ev) {
      var edit = ev.target.closest("[data-fp-edit]");
      var del = ev.target.closest("[data-fp-del]");
      if (edit) {
        var slug = edit.getAttribute("data-fp-edit");
        var rec = fpFind(fpRead(), slug);
        fpOpenDialog(slug, rec && rec.name);
      } else if (del) {
        var s = del.getAttribute("data-fp-del");
        fpWrite(fpRead().filter(function (r) { return r.slug !== s; }));
        fpRenderMy();
        fpSyncBadges();
      }
    });
  }

  function fpInitGlobal() {
    document.addEventListener("click", function (ev) {
      var a = ev.target.closest ? ev.target.closest('a[href^="/go/"]') : null;
      if (!a) return;
      var m = (a.getAttribute("href") || "").match(/^\/go\/([a-z0-9-]+)/);
      var slug = a.getAttribute("data-fp-slug") || (m ? m[1] : "");
      if (slug) fpRecordClick(slug, a.getAttribute("data-fp-name") || "");
    }, true);
    $$("[data-fp-mark]").forEach(function (btn) {
      on(btn, "click", function () {
        fpOpenDialog(btn.getAttribute("data-fp-mark"), btn.getAttribute("data-fp-name"));
      });
    });
    fpInitDialog();
    fpSyncBadges();
    fpBanner();
  }

  document.addEventListener("DOMContentLoaded", function () {
    initNav();
    initCompareInputs();
    initCopy();
    initProviderList();
    initComparePage();
    initQuiz();
    initAgentForm();
    initNewsFilter();
    initHomeSearch();
    fpInitGlobal();
    fpInitMy();
    syncCompareUI(readCompare());
  });

  window.addEventListener("pageshow", function () {
    syncCompareUI(readCompare());
    fpSyncBadges();
  });
})();
