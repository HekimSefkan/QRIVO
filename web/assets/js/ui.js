/**
 * QRIVO web client — shared UI helpers.
 *
 * Security note: the API deliberately stores user-supplied text verbatim (it is
 * data, not markup — see FINAL_AUDIT §2 row 19). This client therefore NEVER
 * interpolates API data into innerHTML. Every value goes through `text()` /
 * `el()`, which use textContent.
 */
(function () {
  'use strict';

  /** Attendance status → Turkish label + Bootstrap colour + short code. */
  var STATUS = {
    PRESENT:        { label: 'VAR',          cls: 'bg-success',            short: 'VAR' },
    ABSENT:         { label: 'YOK',          cls: 'bg-danger',             short: 'YOK' },
    LATE:           { label: 'GEÇ',          cls: 'bg-warning text-dark',  short: 'GEÇ' },
    EXCUSED:        { label: 'MAZERETLİ',    cls: 'bg-info text-dark',     short: 'MAZ' },
    WAITING:        { label: 'BEKLİYOR',     cls: 'bg-secondary',          short: 'BEK' },
    PENDING_REVIEW: { label: 'İNCELEMEDE',   cls: 'bg-dark',               short: 'İNC' }
  };

  var SESSION_STATUS = {
    ACTIVE:    { label: 'AKTİF',        cls: 'bg-success' },
    CLOSED:    { label: 'KAPATILDI',    cls: 'bg-secondary' },
    CANCELLED: { label: 'İPTAL EDİLDİ', cls: 'bg-danger' }
  };

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        if (key === 'class') node.className = attrs[key];
        else if (key === 'text') node.textContent = attrs[key];   // safe by construction
        else if (key === 'dataset') Object.assign(node.dataset, attrs[key]);
        else if (key.indexOf('on') === 0 && typeof attrs[key] === 'function') {
          node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
        } else if (attrs[key] !== null && attrs[key] !== undefined) {
          node.setAttribute(key, attrs[key]);
        }
      });
    }
    (children || []).forEach(function (child) {
      if (child === null || child === undefined) return;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  /**
   * A status badge. Accessibility: the status is carried by the TEXT, not only
   * by colour, and the badge exposes an aria-label.
   */
  function statusBadge(status) {
    var def = STATUS[status] || { label: status || '—', cls: 'bg-secondary' };
    return el('span', {
      class: 'badge ' + def.cls,
      text: def.label,
      'aria-label': 'Durum: ' + def.label
    });
  }

  function sessionBadge(status) {
    var def = SESSION_STATUS[status] || { label: status || '—', cls: 'bg-secondary' };
    return el('span', { class: 'badge ' + def.cls, text: def.label, 'aria-label': 'Oturum durumu: ' + def.label });
  }

  /** QR / MANUEL / SISTEM source pill. */
  function sourceBadge(source) {
    var map = { QR: 'QR', MANUAL: 'MANUEL', SYSTEM: 'SİSTEM' };
    return el('span', {
      class: 'badge border text-body-secondary bg-body-tertiary',
      text: map[source] || source || '—'
    });
  }

  function statusLabel(status) {
    return (STATUS[status] || {}).label || status || '—';
  }

  /** `2026-09-01 09:15:44` / ISO → `09:15:44`; null → '—'. */
  function timeOnly(value) {
    if (!value) return '—';
    var m = String(value).match(/(\d{2}:\d{2}:\d{2})/);
    return m ? m[1] : String(value);
  }

  function dateTime(value) {
    if (!value) return '—';
    return String(value).replace('T', ' ').replace(/(\+|\-)\d{2}:\d{2}$/, '').slice(0, 19);
  }

  function seconds(total) {
    if (total === null || total === undefined) return '—';
    var s = Math.max(0, parseInt(total, 10) || 0);
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    return (h > 0 ? String(h).padStart(2, '0') + ':' : '') +
      String(m).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
  }

  /** Non-blocking toast. `variant`: success | danger | warning | info. */
  function toast(message, variant) {
    var host = document.getElementById('toastHost');
    if (!host) { window.alert(message); return; }

    var node = el('div', {
      class: 'toast align-items-center text-bg-' + (variant || 'secondary') + ' border-0 show',
      role: 'alert',
      'aria-live': 'assertive',
      'aria-atomic': 'true'
    }, [
      el('div', { class: 'd-flex' }, [
        el('div', { class: 'toast-body', text: message }),
        el('button', {
          type: 'button',
          class: 'btn-close btn-close-white me-2 m-auto',
          'aria-label': 'Kapat',
          onclick: function () { node.remove(); }
        })
      ])
    ]);

    host.appendChild(node);
    window.setTimeout(function () { node.remove(); }, 6000);
  }

  /** Promise-based confirmation dialog (bulk actions and destructive actions). */
  function confirmDialog(opts) {
    return new Promise(function (resolve) {
      var modalEl = document.getElementById('confirmModal');
      if (!modalEl) { resolve(window.confirm(opts.body)); return; }

      modalEl.querySelector('[data-confirm-title]').textContent = opts.title || 'Onay';
      modalEl.querySelector('[data-confirm-body]').textContent = opts.body || '';

      var okBtn = modalEl.querySelector('[data-confirm-ok]');
      okBtn.textContent = opts.confirmText || 'Onayla';
      okBtn.className = 'btn ' + (opts.variant ? 'btn-' + opts.variant : 'btn-primary');

      var reasonWrap = modalEl.querySelector('[data-confirm-reason-wrap]');
      var reasonInput = modalEl.querySelector('[data-confirm-reason]');
      reasonWrap.classList.toggle('d-none', !opts.reason);
      reasonInput.value = '';

      var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      var settled = false;

      function done(result) {
        if (settled) return;
        settled = true;
        okBtn.removeEventListener('click', onOk);
        modalEl.removeEventListener('hidden.bs.modal', onHide);
        resolve(result);
      }
      function onOk() {
        var value = opts.reason ? reasonInput.value.trim() : true;
        modal.hide();
        done(opts.reason ? { reason: value } : true);
      }
      function onHide() { done(false); }

      okBtn.addEventListener('click', onOk);
      modalEl.addEventListener('hidden.bs.modal', onHide);
      modal.show();
    });
  }

  /** Render the API's message. Never adds interpretation of its own. */
  function showError(error) {
    var message = (error && error.message) ? error.message : 'Beklenmeyen bir hata oluştu.';
    toast(message, error && error.status === 401 ? 'warning' : 'danger');
  }

  function setBusy(button, busy, busyText) {
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.dataset.originalText || button.textContent;
      button.disabled = true;
      button.textContent = busyText || 'Lütfen bekleyin…';
    } else {
      button.disabled = false;
      if (button.dataset.originalText) button.textContent = button.dataset.originalText;
    }
  }

  window.QrivoUi = {
    el: el,
    STATUS: STATUS,
    statusBadge: statusBadge,
    sessionBadge: sessionBadge,
    sourceBadge: sourceBadge,
    statusLabel: statusLabel,
    timeOnly: timeOnly,
    dateTime: dateTime,
    seconds: seconds,
    toast: toast,
    confirmDialog: confirmDialog,
    showError: showError,
    setBusy: setBusy
  };
})();
