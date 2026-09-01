/**
 * QRIVO web client — session guard and shared chrome.
 *
 * `requireTeacher()` only checks that a token exists so the browser can redirect
 * to the login page instead of rendering an empty screen. It is a UX guard, NOT
 * a security control: every protected page immediately calls the API, and the
 * SERVER decides what the caller may see (SECURITY_RULES.md §1 — frontend
 * visibility is never a security boundary).
 */
(function () {
  'use strict';

  function redirectToLogin() {
    var target = window.location.pathname.split('/').pop() || 'dashboard.html';
    window.location.href = 'index.html?next=' + encodeURIComponent(target);
  }

  function requireSession() {
    if (!window.QrivoApi.isAuthenticated()) {
      redirectToLogin();
      return false;
    }
    return true;
  }

  /** Fill the navbar with the signed-in user and wire the logout button. */
  function mountChrome() {
    var user = window.QrivoApi.currentUser();
    var nameEl = document.getElementById('navUserName');
    if (nameEl) {
      nameEl.textContent = user
        ? ((user.first_name || '') + ' ' + (user.last_name || '')).trim() || user.email || ''
        : '';
    }

    var roleEl = document.getElementById('navUserRole');
    if (roleEl && user && Array.isArray(user.roles)) {
      roleEl.textContent = user.roles.join(', ');
    }

    var logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function (e) {
        e.preventDefault();
        window.QrivoApi.logout().then(function () {
          window.location.href = 'index.html';
        });
      });
    }

    var apiEl = document.getElementById('navApiBase');
    if (apiEl) apiEl.textContent = window.QRIVO_CONFIG.apiBase;
  }

  window.QrivoAuth = {
    requireSession: requireSession,
    mountChrome: mountChrome,
    redirectToLogin: redirectToLogin
  };
})();
