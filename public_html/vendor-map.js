/**
 * Self-hosts the runtime's third-party scripts — when the host lets us.
 *
 * support.js loads React and ReactDOM from unpkg.com on every page. For a
 * booking site that is a live dependency on someone else's CDN: if unpkg is
 * slow or down, the search bar and calendars never mount.
 *
 * support.js already supports self-hosting — it checks window.__resources for
 * a replacement path before falling back to the CDN — so nothing in it needs
 * editing. The copies in /assets/js are byte-identical to unpkg's: their
 * SHA-384 hashes match the integrity values support.js pins.
 *
 * The probe below exists because Hostinger's static-site deploy eats these two
 * files. Everything else in the archive lands; the minified React bundles do
 * not, wherever they are put (tried /vendor and /lib) — most likely the upload
 * malware scanner false-positiving on minified JS. Rather than ship a site that
 * breaks silently when that happens, this checks the local copy is actually
 * there and only then redirects to it. If it is missing, __resources is left
 * alone and support.js uses the CDN, exactly as it did before. Either way the
 * page works.
 *
 * The probe is a synchronous HEAD against a same-origin file, which is a few
 * milliseconds, and it has to be synchronous: support.js reads __resources on
 * the next line. This must run BEFORE support.js — a plain <script>, not
 * deferred.
 */
(function () {
  "use strict";

  var LOCAL = {
    "https://unpkg.com/react@18.3.1/umd/react.production.min.js": "/assets/js/react.js",
    "https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js": "/assets/js/react-dom.js"
  };

  function present(path) {
    try {
      var xhr = new XMLHttpRequest();
      xhr.open("HEAD", path, false);        // sync: __resources is read immediately
      xhr.send(null);
      return xhr.status >= 200 && xhr.status < 300;
    } catch (e) {
      return false;
    }
  }

  var urls = Object.keys(LOCAL);
  // One probe is enough — the two files deploy or vanish together.
  if (present(LOCAL[urls[0]])) {
    window.__resources = Object.assign(window.__resources || {}, LOCAL);
  } else if (window.console && console.info) {
    console.info("[kosipark] self-hosted React missing, falling back to the CDN");
  }
})();
