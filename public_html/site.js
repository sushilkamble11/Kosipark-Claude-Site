/**
 * site.js — small runtime shims the deployed site needs that the design
 * canvas did not. Loaded on every page, after support.js.
 *
 * Why it exists: the pages carry <base href="/"> so that component imports
 * (./SiteNav.dc.html) and assets resolve from the site root at any URL depth
 * — /accommodation/cedar-cabin included. A <base> also re-points bare
 * fragment links, so href="#book" would jump to the home page instead of
 * scrolling. These two handlers put that behaviour back.
 */
(function () {
  "use strict";

  function scrollToId(id, smooth) {
    var target = document.getElementById(id);
    if (!target) return false;
    target.scrollIntoView({ behavior: smooth ? "smooth" : "auto", block: "start" });
    return true;
  }

  // 1. In-page fragment links. Delegated, so it covers markup the components
  //    render after load.
  document.addEventListener("click", function (e) {
    var a = e.target && e.target.closest && e.target.closest('a[href^="#"]');
    if (!a) return;
    var raw = a.getAttribute("href") || "";
    var id = raw.slice(1);
    if (!id) return;
    if (!scrollToId(id, true)) return;   // unknown id — let the browser have it
    e.preventDefault();
    try {
      history.replaceState(null, "", location.pathname + location.search + "#" + id);
    } catch (err) { /* history blocked — the scroll already happened */ }
  }, false);

  // 2. Arriving with a fragment (e.g. /accommodation#book). Components mount
  //    asynchronously, so the browser's own scroll fires before the target
  //    exists. Retry on a short budget, then give up quietly.
  var wanted = (location.hash || "").slice(1);
  if (wanted) {
    var started = Date.now();
    (function retry() {
      if (scrollToId(wanted, false)) return;
      if (Date.now() - started > 4000) return;
      setTimeout(retry, 120);
    })();
  }
})();
