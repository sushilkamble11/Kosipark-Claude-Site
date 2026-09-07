/**
 * Self-hosts the runtime's third-party scripts.
 *
 * support.js loads React and ReactDOM from unpkg.com on every page. For a
 * booking site that is a live dependency on someone else's CDN: if unpkg is
 * slow or down, the search bar, calendars and checkout never mount. It is also
 * a third-party request on every visit.
 *
 * support.js already supports self-hosting — it checks window.__resources for
 * a replacement path before falling back to the CDN — so nothing in it needs
 * editing. The files in /vendor are byte-identical to unpkg's: their SHA-384
 * hashes match the integrity values support.js pins.
 *
* This must run BEFORE support.js, so it is a plain <script>, not deferred.
 */
window.__resources = Object.assign(window.__resources || {}, {
  "https://unpkg.com/react@18.3.1/umd/react.production.min.js":
    "/lib/react-18.3.1.production.min.js",
  "https://unpkg.com/react-dom@18.3.1/umd/react-dom.production.min.js":
    "/lib/react-dom-18.3.1.production.min.js"
});
