/**
 * QRIVO web client — REST API client.
 *
 * Responsibilities (presentation only — NO security decisions):
 *   - keep the token pair in memory, mirrored to sessionStorage so a page
 *     navigation inside the tab keeps the session
 *   - attach `Authorization: Bearer <token>`
 *   - unwrap the `{ success, message, data, meta }` envelope
 *   - on 401, attempt ONE refresh via /auth/refresh (single-flight) and retry
 *   - surface the API's own message; never invent or expose a security reason
 *
 * The backend is authoritative. Nothing here decides what a user may do.
 */
(function () {
  'use strict';

  var SESSION_KEY = 'qrivo.session';

  /** @type {{accessToken:string, refreshToken:string, user:object}|null} */
  var session = null;
  var refreshInFlight = null;

  // ─── session storage ──────────────────────────────────────────────────────

  function loadSession() {
    if (session) return session;
    try {
      var raw = window.sessionStorage.getItem(SESSION_KEY);
      session = raw ? JSON.parse(raw) : null;
    } catch (e) {
      session = null;
    }
    return session;
  }

  function saveSession(next) {
    session = next;
    try {
      if (next) window.sessionStorage.setItem(SESSION_KEY, JSON.stringify(next));
      else window.sessionStorage.removeItem(SESSION_KEY);
    } catch (e) { /* private mode — memory only */ }
  }

  // ─── errors ───────────────────────────────────────────────────────────────

  /**
   * An API failure. `message` is ALWAYS the server's own text (or a neutral
   * fallback) — the client never derives a security reason of its own.
   */
  function ApiError(message, status, errors) {
    this.name = 'ApiError';
    this.message = message || 'Beklenmeyen bir hata oluştu.';
    this.status = status || 0;
    this.errors = errors || null;
  }
  ApiError.prototype = Object.create(Error.prototype);

  function networkError() {
    return new ApiError('Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.', 0, null);
  }

  // ─── request pipeline ─────────────────────────────────────────────────────

  function url(path, query) {
    var full = window.QRIVO_CONFIG.apiBase + window.QRIVO_CONFIG.apiPrefix +
      (path.charAt(0) === '/' ? path : '/' + path);
    if (query) {
      var params = new URLSearchParams();
      Object.keys(query).forEach(function (k) {
        var v = query[k];
        if (v !== null && v !== undefined && v !== '') params.append(k, v);
      });
      var qs = params.toString();
      if (qs) full += '?' + qs;
    }
    return full;
  }

  function send(method, path, options) {
    options = options || {};
    var headers = { Accept: 'application/json' };
    var current = loadSession();

    if (current && current.accessToken && !options.anonymous) {
      headers.Authorization = 'Bearer ' + current.accessToken;
    }

    var init = { method: method, headers: headers };
    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(options.body);
    }

    return fetch(url(path, options.query), init).then(function (response) {
      return response.text().then(function (raw) {
        var decoded = null;
        try { decoded = raw ? JSON.parse(raw) : null; } catch (e) { decoded = null; }

        if (response.status === 401 && !options.isRetry && !options.anonymous) {
          return refresh().then(function (ok) {
            if (!ok) {
              clear();
              throw new ApiError((decoded && decoded.message) || 'Oturumunuz sona erdi.', 401, null);
            }
            var retry = Object.assign({}, options, { isRetry: true });
            return send(method, path, retry);
          });
        }

        if (response.status >= 200 && response.status < 300) {
          return {
            data: decoded ? decoded.data : null,
            meta: decoded ? decoded.meta : null,
            message: decoded ? decoded.message : null
          };
        }

        throw new ApiError(
          decoded && decoded.message ? decoded.message : 'İşlem tamamlanamadı (HTTP ' + response.status + ').',
          response.status,
          decoded ? decoded.errors : null
        );
      });
    }, function () {
      throw networkError();
    });
  }

  // ─── auth ─────────────────────────────────────────────────────────────────

  function login(email, password) {
    return send('POST', '/auth/login', {
      anonymous: true,
      body: { email: email, password: password }
    }).then(function (res) {
      saveSession({
        accessToken: res.data.access_token,
        refreshToken: res.data.refresh_token,
        user: res.data.user || null
      });
      return res.data;
    });
  }

  /** Single-flight refresh so parallel polls cannot stampede /auth/refresh. */
  function refresh() {
    var current = loadSession();
    if (!current || !current.refreshToken) return Promise.resolve(false);
    if (refreshInFlight) return refreshInFlight;

    refreshInFlight = send('POST', '/auth/refresh', {
      anonymous: true,
      body: { refresh_token: current.refreshToken }
    }).then(function (res) {
      saveSession({
        accessToken: res.data.access_token,
        refreshToken: res.data.refresh_token,
        user: res.data.user || current.user
      });
      return true;
    }).catch(function () {
      return false;
    }).then(function (ok) {
      refreshInFlight = null;
      return ok;
    });

    return refreshInFlight;
  }

  function logout() {
    var current = loadSession();
    if (!current) return Promise.resolve();
    return send('POST', '/auth/logout', {}).catch(function () { /* log out locally regardless */ })
      .then(function () { clear(); });
  }

  function clear() { saveSession(null); }

  function isAuthenticated() {
    var current = loadSession();
    return !!(current && current.accessToken);
  }

  function currentUser() {
    var current = loadSession();
    return current ? current.user : null;
  }

  window.QrivoApi = {
    ApiError: ApiError,
    get: function (path, query) { return send('GET', path, { query: query }); },
    post: function (path, body) { return send('POST', path, { body: body || {} }); },
    patch: function (path, body) { return send('PATCH', path, { body: body || {} }); },
    login: login,
    logout: logout,
    refresh: refresh,
    clear: clear,
    isAuthenticated: isAuthenticated,
    currentUser: currentUser
  };
})();
