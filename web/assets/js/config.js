/**
 * QRIVO web client — runtime configuration.
 *
 * The panel is a set of STATIC files. It holds no secrets and makes no security
 * decisions; the only thing configured here is where the authoritative backend
 * lives (mirroring the mobile app's EndpointResolver).
 *
 * RESOLUTION ORDER — probed, not assumed.
 *
 *   1. same origin        the panel served from the API itself (/panel on :8000)
 *   2. hotspot            http://192.168.137.1:8000
 *   3. published tunnel   whatever endpoint.json currently advertises
 *   4. saved override     the "Sunucu adresi" field, LAST resort
 *   5. http://localhost:8000
 *
 * Each candidate is tried with a real request to /api/v1/health, and the first
 * one that answers wins.
 *
 * WHY THE SAVED OVERRIDE MOVED TO LAST.
 * It used to be checked SECOND, before anything was probed, and it is sticky in
 * localStorage. A tunnel address saved during testing therefore survived the
 * tunnel's death: the panel opened at http://127.0.0.1:8080 kept trying a dead
 * public hostname and showed "Sunucuya ulaşılamadı" while a perfectly healthy
 * API sat on the same machine. An address that DEMONSTRABLY answers must beat a
 * remembered one that does not. The override still works — it is simply the
 * fallback for when nothing is auto-discoverable, which is what an override is
 * for.
 */
(function () {
  'use strict';

  var DEFAULT_BASE = 'http://localhost:8000';
  var HOTSPOT_BASE = 'http://192.168.137.1:8000';
  var STORAGE_KEY = 'qrivo.apiBase';
  var CONFIG_URL = 'https://api.github.com/repos/HekimSefkan/QRIVO/contents/endpoint.json?ref=endpoint';
  var PROBE_TIMEOUT_MS = 2500;

  function normalise(url) {
    return String(url || '').replace(/\/+$/, '');
  }

  function stored() {
    try { return normalise(window.localStorage.getItem(STORAGE_KEY)); } catch (e) { return ''; }
  }

  /** Does this base actually serve the QRIVO API? */
  function probe(base) {
    if (!base) return Promise.resolve(false);
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, PROBE_TIMEOUT_MS);
    return fetch(base + '/api/v1/health', {
      method: 'GET',
      signal: controller.signal,
      headers: { Accept: 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (body) { return !!(body && body.data && body.data.api === 'ok'); })
      .catch(function () { return false; })
      .then(function (result) { clearTimeout(timer); return result; });
  }

  /** The tunnel address currently advertised, or '' when unavailable. */
  function publishedTunnel() {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, PROBE_TIMEOUT_MS);
    return fetch(CONFIG_URL + '&t=' + Date.now(), {
      signal: controller.signal,
      headers: { Accept: 'application/vnd.github.raw, application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (doc) { return doc && doc.api_base_url ? normalise(doc.api_base_url) : ''; })
      .catch(function () { return ''; })
      .then(function (result) { clearTimeout(timer); return result; });
  }

  // An explicit ?api= still wins outright: it is a deliberate, per-load
  // instruction rather than a remembered one.
  var fromQuery = normalise(new URLSearchParams(window.location.search).get('api') || '');
  if (fromQuery) {
    try { window.localStorage.setItem(STORAGE_KEY, fromQuery); } catch (e) { /* private mode */ }
  }

  // A synchronous best guess so the very first render has something sensible.
  // `ready` below replaces it with a probed answer.
  var initial = fromQuery ||
    (window.location.port === '8000' ? normalise(window.location.origin) : '') ||
    stored() || DEFAULT_BASE;

  function chooseBase() {
    if (fromQuery) return Promise.resolve(fromQuery);

    var sameOrigin = normalise(window.location.origin);
    var candidates = [sameOrigin, HOTSPOT_BASE];

    return candidates.reduce(function (chain, candidate) {
      return chain.then(function (found) {
        if (found) return found;
        return probe(candidate).then(function (ok) { return ok ? candidate : ''; });
      });
    }, Promise.resolve(''))
      .then(function (found) {
        if (found) return found;
        // Nothing local answered — ask what the tunnel currently is.
        return publishedTunnel().then(function (tunnel) {
          if (!tunnel) return '';
          return probe(tunnel).then(function (ok) { return ok ? tunnel : ''; });
        });
      })
      .then(function (found) {
        if (found) return found;
        // Last resort: whatever was saved by hand, then the dev default.
        var saved = stored();
        if (saved) {
          return probe(saved).then(function (ok) { return ok ? saved : DEFAULT_BASE; });
        }
        return DEFAULT_BASE;
      });
  }

  window.QRIVO_CONFIG = {
    apiBase: initial,
    apiPrefix: '/api/v1',
    /** AD-010: the spec allows 2–5 s; the API also reports its own interval. */
    pollIntervalMs: 3000,
    storageKey: STORAGE_KEY,

    /** Resolves once apiBase has been probed. Await before the first call. */
    ready: null,

    setApiBase: function (url) {
      var value = normalise(url);
      try { window.localStorage.setItem(STORAGE_KEY, value); } catch (e) { /* ignore */ }
      window.QRIVO_CONFIG.apiBase = value;
    }
  };

  window.QRIVO_CONFIG.ready = chooseBase().then(function (base) {
    window.QRIVO_CONFIG.apiBase = base;
    return base;
  });
})();
