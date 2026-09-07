/**
 * gp-images.js — fills the site's photo slots from GuestPoint.
 *
 * GuestPoint returns photos with the availability payload:
 *   PropertyAvailability.PropertyImages[]   — the park itself
 *   RoomTypeAvailability.RoomImages[]       — one set per room type
 * each an Image { URL, Captions: { lang: text }, Sequence }.
 *
 * Rather than replace the design's <image-slot> elements, this sets their `src`
 * attribute, which the element already supports. That matters: a slot with no
 * matching GuestPoint photo is left exactly as it was, so the site degrades to
 * its captioned placeholders instead of to holes. Nothing here throws into the
 * page — if the API is unreachable, every slot simply stays a placeholder.
 *
 * How a slot says which photo it wants, in priority order:
 *   1. gp-room="cedar-cabin" gp-index="0"   — nth photo of that room type
 *   2. gp-property gp-index="3"             — nth photo of the park
 *   3. id="r-cedar-cabin-1" / "av-cedar-cabin-2"  — by convention, 1-based
 * A slot with none of these is never touched.
 */
(function () {
  "use strict";

  var CONVENTION = /^(?:r|av)-(.+)-(\d+)$/;
  var loaded = null;          // promise for the one content fetch
  var content = null;         // { images: [...], rooms: { slug: [...] } }
  var filled = new WeakSet(); // slots already resolved, so re-renders are cheap

  function wanted(el) {
    var room = el.getAttribute("gp-room");
    if (room) return { room: room, index: parseInt(el.getAttribute("gp-index") || "0", 10) || 0 };
    if (el.hasAttribute("gp-property")) {
      return { room: null, index: parseInt(el.getAttribute("gp-index") || "0", 10) || 0 };
    }
    var m = CONVENTION.exec(el.id || "");
    if (m) return { room: m[1], index: Math.max(0, parseInt(m[2], 10) - 1) };
    return null;
  }

  function pick(req) {
    if (!content) return null;
    var list = req.room ? (content.rooms && content.rooms[req.room]) || []
                        : content.images || [];
    return list[req.index] || null;
  }

  function apply(el) {
    if (filled.has(el)) return;
    var req = wanted(el);
    if (!req) return;
    var img = pick(req);
    if (!img || !img.url) return;      // no photo for this one — placeholder stays
    filled.add(el);
    // A caption is real alt text: it is what the property wrote about the photo.
    if (img.caption) {
      el.setAttribute("alt", img.caption);
      if (!el.hasAttribute("data-keep-placeholder")) {
        el.setAttribute("placeholder", img.caption);
      }
    }
    el.setAttribute("src", img.url);
  }

  function sweep(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var slots = scope.querySelectorAll("image-slot");
    for (var i = 0; i < slots.length; i++) apply(slots[i]);
  }

  function load() {
    if (loaded) return loaded;
    loaded = import("/guestpoint.js")
      .then(function (gp) { return gp.getPropertyContent(); })
      .then(function (meta) {
        content = meta && !meta.failed ? meta : null;
        if (content) sweep(document);
        return content;
      })
      .catch(function (err) {
        // Deliberately quiet in the page: placeholders are a fine fallback.
        console.warn("[gp-images] photos unavailable, placeholders kept", err);
        content = null;
        return null;
      });
    return loaded;
  }

  // The pages render their components asynchronously, and re-render on state
  // changes, so slots keep appearing after first paint. Watch for them rather
  // than guessing at a delay.
  function watch() {
    if (!window.MutationObserver) return;
    var pending = null;
    new MutationObserver(function () {
      if (pending) return;
      pending = requestAnimationFrame(function () {
        pending = null;
        if (content) sweep(document);
      });
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  function start() { load(); watch(); }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }

  // Exposed for the console while wiring up: __gpImages.refresh() re-reads.
  window.__gpImages = {
    refresh: function () { loaded = null; filled = new WeakSet(); return load(); },
    content: function () { return content; }
  };
})();
