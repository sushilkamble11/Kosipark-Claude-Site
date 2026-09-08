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


  /* ---------- Designed placeholders ----------
   * A slot GuestPoint has no photo for used to render as a grey void with a
   * broken-image glyph — sixty of them down the page, which read as a broken
   * site rather than a site awaiting photography. These draw a panel in the
   * park's own colours with the caption saying what belongs there, so an empty
   * slot looks deliberate and doubles as the shot list.
   *
   * Deliberately not a stock photo: an invented picture of a cabin nobody has
   * stayed in is worse than an honest blank.
   */
  var TONES = [
    ["#1D3730", "#122720"],   // forest
    ["#3A4E3C", "#22322A"],
    ["#5B4630", "#3A2C1E"],   // bark
    ["#74836A", "#4C5C40"],   // sage
    ["#96592A", "#6B3E1C"]    // copper
  ];

  function hashOf(str) {
    var h = 0;
    for (var i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) | 0;
    return Math.abs(h);
  }

  function esc(s) {
    return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  // Wrap the caption by width rather than character count, so long captions
  // do not overflow the panel.
  function wrap(text, perLine, maxLines) {
    var words = String(text).split(/\s+/), lines = [], line = "";
    for (var i = 0; i < words.length; i++) {
      var next = line ? line + " " + words[i] : words[i];
      if (next.length > perLine && line) { lines.push(line); line = words[i]; }
      else line = next;
      if (lines.length === maxLines) break;
    }
    if (line && lines.length < maxLines) lines.push(line);
    return lines;
  }

  /* The panel is drawn in an 800-wide viewBox and then stretched to whatever
     the slot happens to be. On a card that is about right; on a full-bleed
     hero it scaled a 27px caption up to nearly fifty, so the home page led
     with two lines of grey placeholder text across the middle of the picture.
     Size the caption against the slot's real width so it lands at roughly the
     same reading size everywhere. */
  function captionSizeFor(width) {
    if (!width) return 27;
    var scale = 800 / width;               // viewBox units per rendered pixel
    return Math.max(11, Math.min(46, Math.round(17 * scale)));
  }

  function placeholderFor(caption, seed, width, suppressCaption) {
    var t = TONES[hashOf(seed || caption || "x") % TONES.length];
    var size = captionSizeFor(width);
    // Wrap by the width actually available at this size, not a fixed count.
    var perLine = Math.max(18, Math.round(680 / (size * 0.52)));
    var lines = suppressCaption ? [] : wrap(caption || "Photograph to come", perLine, 3);
    var lead = Math.round(size * 1.4);
    var startY = 300 - (lines.length - 1) * (lead / 2);
    var text = lines.map(function (l, i) {
      return '<text x="400" y="' + (startY + i * lead) + '" text-anchor="middle" ' +
             'font-family="Georgia, serif" font-size="' + size + '" fill="#EDE4D4">' + esc(l) + '</text>';
    }).join("");

    var svg =
      '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="560" viewBox="0 0 800 560">' +
        '<defs><linearGradient id="g" x1="0" y1="0" x2="0" y2="1">' +
          '<stop offset="0" stop-color="' + t[0] + '"/><stop offset="1" stop-color="' + t[1] + '"/>' +
        '</linearGradient></defs>' +
        '<rect width="800" height="560" fill="url(#g)"/>' +
        // A ridgeline, echoing the logo — quiet, not a placeholder icon.
        '<path d="M0 470 L150 372 L250 424 L400 300 L530 400 L650 340 L800 440 L800 560 L0 560 Z" ' +
          'fill="rgba(252,250,246,0.07)"/>' +
        '<path d="M0 505 L190 420 L330 470 L470 380 L620 450 L800 390 L800 560 L0 560 Z" ' +
          'fill="rgba(252,250,246,0.05)"/>' +
        text +
      '</svg>';
    return "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
  }

  function widthOf(el) {
    try { return Math.round(el.getBoundingClientRect().width) || 0; } catch (e) { return 0; }
  }

  var CONVENTION = /^(?:r|av)-(.+)-(\d+)$/;
  var loaded = null;          // promise for the one content fetch
  var content = null;         // { images: [...], rooms: { slug: [...] } }
  var resolved = false;       // has the content fetch finished, either way?
  var filled = new WeakSet(); // slots already resolved, so re-renders are cheap

  function wanted(el) {
    // Slots with no API mapping still get a designed placeholder, so return a
    // request that resolves to nothing rather than bailing out.
    var room = el.getAttribute("gp-room");
    if (room) return { room: room, index: parseInt(el.getAttribute("gp-index") || "0", 10) || 0 };
    if (el.hasAttribute("gp-property")) {
      return { room: null, index: parseInt(el.getAttribute("gp-index") || "0", 10) || 0 };
    }
    var m = CONVENTION.exec(el.id || "");
    if (m) return { room: m[1], index: Math.max(0, parseInt(m[2], 10) - 1) };
    return { room: null, index: -1 };   // no mapping — placeholder only
  }

  function pick(req) {
    if (!content || req.index < 0) return null;
    var list = req.room ? (content.rooms && content.rooms[req.room]) || []
                        : content.images || [];
    return list[req.index] || null;
  }

  function apply(el) {
    if (filled.has(el)) return;
    // Room detail slots receive their curated fallback src immediately after
    // the component renders. Do not let a faster GuestPoint/mock response
    // claim the empty slot first; the src mutation triggers another sweep,
    // where real GuestPoint photos can still replace the local fallback.
    if (el.hasAttribute("data-room-photo") && !el.getAttribute("src")) return;
    var req = wanted(el);
    if (!req) return;
    var img = pick(req);
    if (!img || !img.url) {
      // Wait for the fetch before giving up on this slot — otherwise the first
      // sweep claims every slot with a panel and the real photos, arriving a
      // moment later, find nothing left to fill.
      if (!resolved) return;
      // Curated photos from Kosipark's existing website are the production
      // fallback. Keep them visible when GuestPoint has no image for a slot;
      // a later API image still wins through the normal branch below.
      if (el.getAttribute("src")) {
        filled.add(el);
        if (!el.getAttribute("alt")) {
          el.setAttribute("alt", el.getAttribute("placeholder") || "Kosciuszko Tourist Park");
        }
        return;
      }
      // GuestPoint has nothing for this slot. Draw the designed panel from the
      // slot's own caption rather than leaving a grey hole.
      var cap = el.getAttribute("placeholder") || "";
      if (!cap) return;
      filled.add(el);
      el.setAttribute("alt", cap);
      // The hero already carries the page's main message. Repeating a photo
      // brief inside that full-bleed panel competes with the real headline.
      el.setAttribute("src", placeholderFor(cap, el.id || cap, widthOf(el), el.id === "h-hero"));
      el.setAttribute("data-kosipark-placeholder", "");
      return;
    }
    // Local development uses inline SVG scenery to prove the GuestPoint
    // image pipeline. When the page already has an authentic Kosipark photo,
    // keep that photo instead of letting the development fixture cover it.
    // Real GuestPoint CDN images still take priority in production.
    if (el.getAttribute("src") && /^data:image\/svg\+xml/i.test(img.url)) {
      filled.add(el);
      if (!el.getAttribute("alt")) {
        el.setAttribute("alt", el.getAttribute("placeholder") || "Kosciuszko Tourist Park");
      }
      return;
    }
    filled.add(el);
    // A caption is real alt text: it is what the property wrote about the photo.
    var caption = img.caption || el.getAttribute("placeholder") || "";
    if (img.caption) {
      el.setAttribute("alt", img.caption);
      if (!el.hasAttribute("data-keep-placeholder")) {
        el.setAttribute("placeholder", img.caption);
      }
    }

    // A URL that 404s left the slot showing its placeholder TEXT at the slot's
    // own size — which on the hero meant two lines of grey caption a hundred
    // pixels tall across the middle of the page. Check the photo actually
    // loads, and if it doesn't, draw the panel instead. Every photo fails this
    // way while the site is on sample data, so this is what most visitors see.
    var probe = new Image();
    probe.onload = function () { el.setAttribute("src", img.url); };
    probe.onerror = function () {
      el.setAttribute("alt", caption);
      el.setAttribute("src", placeholderFor(caption, el.id || caption, widthOf(el), el.id === "h-hero"));
      el.setAttribute("data-kosipark-placeholder", "");
    };
    probe.src = img.url;
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
        resolved = true;
        sweep(document);          // fills what we have, draws panels for the rest
        return content;
      })
      .catch(function (err) {
        // Deliberately quiet in the page: placeholders are a fine fallback.
        console.info("[gp-images] no photos from GuestPoint — drawing placeholders");
        content = null;
        resolved = true;
        sweep(document);
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
        sweep(document);
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
