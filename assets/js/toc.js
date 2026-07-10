/*!
 * Kratos+ 文章目录：sticky 偏移、折叠、滚动高亮
 */
(function () {
  "use strict";

  var toc = document.querySelector(".k-main .sidebar .w-toc");
  if (!toc) return;

  var contentSel = toc.getAttribute("data-toc-target") || ".k-main .article .content";
  var content = document.querySelector(contentSel);
  var title = toc.querySelector(".title");
  var item = toc.querySelector(".item");

  // 前端构建目录：从正文标题中扫描 h1–h6，避免服务端缓存把 A 文章的目录带到 B 文章上。
  // 复用 the_content 过滤器已插入的 <a name="toc-N"> 锚点（每篇文章正文各自缓存，安全）。
  (function buildTree() {
    if (!content || !item) return;
    var headings = content.querySelectorAll("h1, h2, h3, h4, h5, h6");
    if (!headings.length) return;

    var entries = [];
    headings.forEach(function (h) {
      var anchor = h.querySelector('a[name^="toc-"]');
      if (!anchor) return;
      entries.push({
        name: anchor.getAttribute("name"),
        depth: parseInt(h.tagName.substring(1), 10),
        text: (h.textContent || "").trim(),
      });
    });
    if (!entries.length) return;

    var min = Infinity;
    entries.forEach(function (e) { if (e.depth < min) min = e.depth; });

    var html = "";
    var depth = min - 1;
    var liOpen = false;
    entries.forEach(function (e) {
      var d = e.depth;
      if (d > depth) {
        for (var i = depth; i < d; i++) html += '<ul class="toc-list">';
      } else {
        if (liOpen) { html += "</li>"; liOpen = false; }
        for (var j = depth; j > d; j--) html += "</ul></li>";
      }
      var level = d - min + 1;
      html += '<li class="toc-item toc-h' + d + ' toc-l' + level + '">'
        + '<a href="#' + e.name + '">'
        + e.text.replace(/[&<>"']/g, function (c) {
          return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c];
        })
        + '</a>';
      liOpen = true;
      depth = d;
    });
    if (liOpen) html += "</li>";
    for (var k = depth; k > min - 1; k--) {
      html += k > min ? "</ul></li>" : "</ul>";
    }

    item.innerHTML = html;
    toc.classList.remove("is-empty");
  })();

  // 空壳未被填充则彻底移除，避免出现空目录框
  if (!item || !item.firstChild) { toc.parentNode && toc.parentNode.removeChild(toc); return; }

  // 三角图标（飞书风格）：展开态 ▼、折叠态 ▶
  var SVG_CARET =
    '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2.5l4 3.5-4 3.5z" fill="currentColor"/></svg>';

  // 1) sticky 偏移随导航吸顶更新
  function updateOffset() {
    var nav = document.querySelector(".k-nav");
    var offset = 8;
    if (nav && nav.classList.contains("nav-sticky")) {
      offset = nav.offsetHeight + 8;
    }
    toc.style.setProperty("--k-toc-offset", offset + "px");
  }
  updateOffset();
  window.addEventListener("scroll", updateOffset, { passive: true });
  window.addEventListener("resize", updateOffset);

  // 2) 整体折叠（移动端才使用；桌面端的"默认折叠"含义在下面的子级里处理）
  if (title) {
    if (window.matchMedia("(max-width: 991.98px)").matches) {
      toc.classList.add("is-collapsed");
    }
    title.setAttribute("role", "button");
    title.setAttribute("tabindex", "0");
    var toggleAll = function () { toc.classList.toggle("is-collapsed"); };
    title.addEventListener("click", toggleAll);
    title.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggleAll(); }
    });
  }

  // 3) 子级折叠按钮
  function setToggle(btn, collapsed) {
    btn.setAttribute("aria-expanded", collapsed ? "false" : "true");
  }
  // 默认折叠 = 仅保留顶级，其余子级 li 初始折起
  var defaultCollapsed = toc.getAttribute("data-toc-collapsed") === "1";
  if (item) {
    item.querySelectorAll(".toc-item").forEach(function (li) {
      if (!li.querySelector(":scope > .toc-list")) return;
      li.classList.add("has-children");
      if (defaultCollapsed) li.classList.add("is-collapsed");
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "toc-toggle";
      btn.setAttribute("aria-label", "折叠 / 展开子目录");
      btn.innerHTML = SVG_CARET;
      setToggle(btn, defaultCollapsed);
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        setToggle(btn, li.classList.toggle("is-collapsed"));
      });
      li.insertBefore(btn, li.firstChild);
    });
  }

  // 4) 滚动高亮
  if (!content || !item || !("IntersectionObserver" in window)) return;

  var anchors = Array.prototype.slice.call(
    content.querySelectorAll('a[name^="toc-"]')
  );
  if (!anchors.length) return;

  var linkMap = {};
  item.querySelectorAll('a[href^="#toc-"]').forEach(function (a) {
    linkMap[a.getAttribute("href").slice(1)] = a;
  });

  function clearActive() {
    item.querySelectorAll("a.active").forEach(function (a) {
      a.classList.remove("active");
    });
    item.querySelectorAll(".is-active-parent").forEach(function (li) {
      li.classList.remove("is-active-parent");
    });
  }

  function activate(name) {
    var a = linkMap[name];
    if (!a) return;
    clearActive();
    a.classList.add("active");
    var li = a.closest(".toc-item");
    while (li) {
      if (li.classList.contains("is-collapsed")) {
        li.classList.remove("is-collapsed");
        var tg = li.querySelector(":scope > .toc-toggle");
        if (tg) setToggle(tg, false);
      }
      li.classList.add("is-active-parent");
      var parent = li.parentElement;
      li = parent ? parent.closest(".toc-item") : null;
    }
    var ir = item.getBoundingClientRect();
    var ar = a.getBoundingClientRect();
    if (ar.top < ir.top || ar.bottom > ir.bottom) {
      item.scrollTop += ar.top - ir.top - 40;
    }
  }

  var visible = {};
  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (e) {
        var name = e.target.getAttribute("name");
        if (e.isIntersecting) visible[name] = e.boundingClientRect.top;
        else delete visible[name];
      });
      var current = null;
      var minTop = Infinity;
      Object.keys(visible).forEach(function (n) {
        if (visible[n] < minTop) { minTop = visible[n]; current = n; }
      });
      if (!current) {
        var maxTop = -Infinity;
        anchors.forEach(function (el) {
          var top = el.getBoundingClientRect().top;
          if (top < 0 && top > maxTop) {
            maxTop = top;
            current = el.getAttribute("name");
          }
        });
      }
      if (current) activate(current);
    },
    { rootMargin: "-80px 0px -70% 0px", threshold: 0 }
  );
  anchors.forEach(function (a) { observer.observe(a); });
})();
