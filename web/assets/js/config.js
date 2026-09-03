/**
 * QRIVO web client — runtime configuration.
 *
 * The web client is a set of STATIC files. It holds no secrets and makes no
 * security decisions; the only thing configured here is where the authoritative
 * backend lives (mirroring the mobile app's AppConfig).
 *
 * Resolution order:
 *   1. ?api=http://host:port     — query override, handy for a quick test
 *   2. localStorage qrivo.apiBase — sticky override, set from the login screen
 *   3. same origin when served over HTTPS from a public host (tunnel/hosting)
 *   4. same origin when the page is served by the API itself
 *   5. http://localhost:8000     — the default from backend/README.md
 */
(function () {
  'use strict';

  var DEFAULT_BASE = 'http://localhost:8000';
  var STORAGE_KEY = 'qrivo.apiBase';

  function normalise(url) {
    return String(url || '').replace(/\/+$/, '');
  }

  function resolve() {
    var fromQuery = new URLSearchParams(window.location.search).get('api');
    if (fromQuery) {
      try { window.localStorage.setItem(STORAGE_KEY, normalise(fromQuery)); } catch (e) { /* private mode */ }
      return normalise(fromQuery);
    }

    try {
      var stored = window.localStorage.getItem(STORAGE_KEY);
      if (stored) return normalise(stored);
    } catch (e) { /* private mode */ }

    // Published on a public host over HTTPS (Tailscale Funnel, or any real
    // deployment): the API is served from the SAME origin as this page, so the
    // panel works on any device with nothing typed in. Deliberately excludes
    // localhost so local development keeps using DEFAULT_BASE below.
    var host = window.location.hostname;
    var isLocal = host === 'localhost' || host === '127.0.0.1' || host === '::1' || host === '[::1]';
    if (window.location.protocol === 'https:' && !isLocal) {
      return normalise(window.location.origin);
    }

    // Served by the API itself (rare, but supported).
    if (window.location.port === '8000') return normalise(window.location.origin);

    return DEFAULT_BASE;
  }

  window.QRIVO_CONFIG = {
    apiBase: resolve(),
    apiPrefix: '/api/v1',
    /** AD-010: the spec allows 2–5 s; the API also reports its own interval. */
    pollIntervalMs: 3000,
    storageKey: STORAGE_KEY,

    setApiBase: function (url) {
      var value = normalise(url);
      try { window.localStorage.setItem(STORAGE_KEY, value); } catch (e) { /* ignore */ }
      window.QRIVO_CONFIG.apiBase = value;
    }
  };
})();
