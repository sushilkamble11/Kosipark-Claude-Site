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

  /* ---------- Sample-data notice ----------
   * Until GuestPoint credentials are in place the site serves fixtures so it
   * can be reviewed. Rates, availability and photos are then all invented, and
   * an invented rate on a page with a Book button is the kind of thing that
   * ends up in a complaint. So say it, once, plainly, wherever it applies.
   *
   * guestpoint.js fires this event the first time it serves a fixture; it never
   * fires once real credentials answer.
   */
  function showSampleBanner() {
    if (document.getElementById("kosipark-sample-note")) return;
    var bar = document.createElement("div");
    bar.id = "kosipark-sample-note";
    bar.setAttribute("role", "status");
    bar.style.cssText = [
      "position:fixed", "left:0", "right:0", "bottom:0", "z-index:9999",
      "background:#96592A", "color:#FCFAF6",
      "font:600 12.5px/1.45 Figtree, system-ui, sans-serif",
      "letter-spacing:.02em", "text-align:center",
      "padding:11px 44px 11px 16px",
      "box-shadow:0 -8px 24px -18px rgba(18,39,32,0.9)"
    ].join(";");
    bar.textContent =
      "Preview — rates, availability and photos on this page are sample data, " +
      "not live. Call 02 6456 2224 to book.";

    var close = document.createElement("button");
    close.type = "button";
    close.setAttribute("aria-label", "Dismiss");
    close.textContent = "\u00d7";
    close.style.cssText = [
      "position:absolute", "top:50%", "right:12px", "transform:translateY(-50%)",
      "background:none", "border:0", "color:#FCFAF6", "font-size:20px",
      "line-height:1", "cursor:pointer", "padding:4px 8px"
    ].join(";");
    close.addEventListener("click", function () { bar.remove(); });
    bar.appendChild(close);

    (document.body || document.documentElement).appendChild(bar);
  }

  window.addEventListener("kosipark:sample-data", showSampleBanner);
})();
