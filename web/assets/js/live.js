/**
 * QRIVO web client — teacher live attendance (PROJECT_SPECIFICATION.md §12–14).
 *
 * Left panel  : the dynamic QR (rendered locally from the payload the API
 *               returns) plus course/class/room/teacher/start/remaining.
 * Right panel : the live roster and the counters, refreshed by AJAX polling
 *               (AD-010 — the spec's own fallback; interval from the API,
 *               default 3 s).
 *
 * Everything security-relevant is the server's:
 *   - the QR string is GENERATED and SIGNED by the backend; this file only
 *     draws it. It never constructs, parses or validates a payload.
 *   - a status change is a PATCH; the API decides whether it is allowed.
 *   - close/cancel are POSTs; the API enforces ownership and state.
 * The client renders the API's own message on failure and never explains why.
 */
(function () {
  'use strict';

  var el = window.QrivoUi.el;

  if (!window.QrivoAuth.requireSession()) return;
  window.QrivoAuth.mountChrome();

  var sessionId = new URLSearchParams(window.location.search).get('session');
  if (!sessionId || !/^\d+$/.test(sessionId)) {
    document.getElementById('loadError').textContent = 'Geçersiz oturum numarası.';
    document.getElementById('loadError').classList.remove('d-none');
    return;
  }

  var STATUS_OPTIONS = ['WAITING', 'PRESENT', 'ABSENT', 'LATE', 'EXCUSED'];

  var state = {
    session: null,
    counters: {},
    students: [],
    studentsVersion: null,
    pollMs: window.QRIVO_CONFIG.pollIntervalMs,
    qrRefreshMs: 30000,
    remaining: null,
    filters: { search: '', number: '', status: '' },
    pollTimer: null,
    qrTimer: null,
    tickTimer: null,
    lastStatuses: {},
    stopped: false
  };

  var nodes = {
    qrCanvas: document.getElementById('qrCanvas'),
    qrRefresh: document.getElementById('qrRefresh'),
    remaining: document.getElementById('remaining'),
    counters: document.getElementById('counters'),
    studentBody: document.getElementById('studentBody'),
    rosterCount: document.getElementById('rosterCount'),
    sessionBadge: document.getElementById('sessionStatusBadge'),
    closeBtn: document.getElementById('closeBtn'),
    cancelBtn: document.getElementById('cancelBtn'),
    pollDot: document.getElementById('pollDot'),
    pollStatus: document.getElementById('pollStatus'),
    loadError: document.getElementById('loadError'),
    bulkBar: document.getElementById('bulkBar')
  };

  // ─── filters ──────────────────────────────────────────────────────────────

  function debounce(fn, ms) {
    var t = null;
    return function () {
      var args = arguments, self = this;
      window.clearTimeout(t);
      t = window.setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  var applyFilters = debounce(function () {
    state.filters.search = document.getElementById('searchInput').value.trim();
    state.filters.number = document.getElementById('numberInput').value.trim();
    state.filters.status = document.getElementById('statusFilter').value;
    refreshStudents();
  }, 250);

  document.getElementById('searchInput').addEventListener('input', applyFilters);
  document.getElementById('numberInput').addEventListener('input', applyFilters);
  document.getElementById('statusFilter').addEventListener('change', applyFilters);

  // ─── initial snapshot ─────────────────────────────────────────────────────

  function loadSnapshot() {
    return window.QrivoApi.get('/teacher/attendance/' + sessionId + '/live', {
      search: state.filters.search || null,
      status: state.filters.status || null
    }).then(function (res) {
      var data = res.data || {};
      state.session = data.session || null;
      state.counters = data.counters || {};
      state.students = data.students || [];
      state.studentsVersion = data.students_version || null;
      state.remaining = data.session ? data.session.remaining_seconds : null;
      if (data.poll_interval_ms) state.pollMs = data.poll_interval_ms;

      renderSessionInfo();
      renderCounters();
      renderStudents();
      renderQr(data.qr);
      markPoll(true);
      return data;
    });
  }

  // ─── QR ───────────────────────────────────────────────────────────────────

  /**
   * Draw the QR locally with the vendored qrcode-generator (MIT). The payload
   * comes from the API and is NEVER sent anywhere else — no third-party service.
   */
  function renderQr(qr) {
    if (!qr || !qr.qr_string) {
      nodes.qrCanvas.replaceChildren(
        el('div', { class: 'qr-inactive' }, [
          el('div', { class: 'fs-1 mb-2', text: '⏸' }),
          el('div', { text: 'Oturum aktif değil — QR gösterilmiyor.' })
        ])
      );
      nodes.qrRefresh.textContent = '—';
      return;
    }

    try {
      var code = qrcode(0, 'M');           // 0 = auto version, M = ~15% recovery
      code.addData(qr.qr_string);
      code.make();

      var wrapper = el('div', { class: 'w-100' });
      // createSvgTag returns markup produced by the library from the payload we
      // pass; it contains no user-controlled HTML.
      wrapper.innerHTML = code.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
      wrapper.setAttribute('role', 'img');
      wrapper.setAttribute('aria-label', 'Yoklama QR kodu');
      nodes.qrCanvas.replaceChildren(wrapper);

      if (qr.refresh_seconds) {
        state.qrRefreshMs = Math.max(5, parseInt(qr.refresh_seconds, 10)) * 1000;
        nodes.qrRefresh.textContent = String(qr.refresh_seconds);
      }
    } catch (e) {
      nodes.qrCanvas.replaceChildren(
        el('div', { class: 'qr-inactive', text: 'QR görüntülenemedi.' })
      );
    }
  }

  function refreshQr() {
    if (state.stopped || !state.session || state.session.status !== 'ACTIVE') return;

    window.QrivoApi.get('/teacher/attendance/' + sessionId + '/qr')
      .then(function (res) { renderQr(res.data); })
      .catch(function (error) {
        if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
        // A non-ACTIVE session simply stops producing QRs; the poll will notice.
        renderQr(null);
      });
  }

  // ─── session info ─────────────────────────────────────────────────────────

  function renderSessionInfo() {
    var s = state.session;
    if (!s) return;

    var user = window.QrivoApi.currentUser() || {};
    document.getElementById('infoCourse').textContent = state.courseLabel || ('#' + s.course_id);
    document.getElementById('infoClass').textContent = state.classLabel || ('#' + s.class_id);
    document.getElementById('infoRoom').textContent = state.roomLabel || ('#' + s.room_id);
    document.getElementById('infoTeacher').textContent =
      ((user.first_name || '') + ' ' + (user.last_name || '')).trim() || user.email || '—';
    document.getElementById('infoStart').textContent = window.QrivoUi.dateTime(s.start_time);

    nodes.sessionBadge.replaceChildren(window.QrivoUi.sessionBadge(s.status));

    var active = s.status === 'ACTIVE';
    nodes.closeBtn.disabled = !active;
    nodes.cancelBtn.disabled = !active;
    nodes.bulkBar.classList.toggle('d-none', !active);
  }

  /** Course/class/room names are not in the live payload — take them from the
   *  dashboard so the panel reads properly. Falls back to ids on failure. */
  function loadLabels() {
    return window.QrivoApi.get('/teacher/dashboard').then(function (res) {
      var all = [].concat(res.data.active_sessions || [], res.data.recent_sessions || []);
      var match = all.filter(function (s) { return String(s.id) === String(sessionId); })[0];
      if (match) {
        state.courseLabel = match.course_code + ' — ' + match.course_name;
        state.classLabel = match.class_name;
        state.roomLabel = match.room_name;
        renderSessionInfo();
      }
    }).catch(function () { /* labels are cosmetic */ });
  }

  // ─── counters ─────────────────────────────────────────────────────────────

  function renderCounters() {
    var c = state.counters || {};
    var tiles = [
      { key: 'TOTAL', label: 'TOPLAM', cls: 'is-total' },
      { key: 'PRESENT', label: 'VAR', cls: 'is-present' },
      { key: 'ABSENT', label: 'YOK', cls: 'is-absent' },
      { key: 'LATE', label: 'GEÇ', cls: 'is-late' },
      { key: 'EXCUSED', label: 'MAZERETLİ', cls: 'is-excused' },
      { key: 'WAITING', label: 'BEKLİYOR', cls: 'is-waiting' }
    ];

    nodes.counters.replaceChildren();
    tiles.forEach(function (tile) {
      nodes.counters.appendChild(
        el('div', { class: 'counter-tile ' + tile.cls }, [
          el('div', { class: 'counter-value', text: String(c[tile.key] || 0) }),
          el('div', { class: 'counter-label', text: tile.label })
        ])
      );
    });
  }

  // ─── roster ───────────────────────────────────────────────────────────────

  function renderStudents() {
    var rows = state.students;

    // The student-number box is an extra client-side narrowing on top of the
    // API's own `search` filter. It is display convenience only.
    if (state.filters.number) {
      var needle = state.filters.number.toLocaleLowerCase('tr');
      rows = rows.filter(function (r) {
        return String(r.student_number).toLocaleLowerCase('tr').indexOf(needle) !== -1;
      });
    }

    nodes.rosterCount.textContent = rows.length + ' öğrenci';
    nodes.studentBody.replaceChildren();

    if (rows.length === 0) {
      nodes.studentBody.appendChild(
        el('tr', {}, [el('td', { colspan: '6', class: 'text-center text-body-secondary py-4', text: 'Kayıt bulunamadı.' })])
      );
      return;
    }

    rows.forEach(function (student) {
      var changed = state.lastStatuses[student.student_id] !== undefined
        && state.lastStatuses[student.student_id] !== student.status;

      var select = el('select', {
        class: 'form-select form-select-sm',
        'aria-label': student.first_name + ' ' + student.last_name + ' durumunu değiştir'
      });

      STATUS_OPTIONS.forEach(function (option) {
        var opt = el('option', { value: option, text: window.QrivoUi.statusLabel(option) });
        if (option === student.status) opt.selected = true;
        select.appendChild(opt);
      });

      select.disabled = !state.session || state.session.status !== 'ACTIVE'
        ? state.session && state.session.status === 'CANCELLED'
        : false;
      // A CLOSED session still accepts manual corrections (PENDING_REVIEW
      // resolution); only CANCELLED is refused by the API.
      if (state.session && state.session.status === 'CANCELLED') select.disabled = true;

      select.addEventListener('change', function () {
        changeStatus(student, select.value, select);
      });

      nodes.studentBody.appendChild(
        el('tr', { class: changed ? 'row-changed' : '' }, [
          el('td', {}, [
            el('div', { class: 'student-name', text: student.first_name + ' ' + student.last_name })
          ]),
          el('td', {}, [el('span', { class: 'student-number', text: student.student_number })]),
          el('td', {}, [window.QrivoUi.statusBadge(student.status)]),
          el('td', {}, [window.QrivoUi.sourceBadge(student.source)]),
          el('td', {}, [el('span', { class: 'small', text: window.QrivoUi.timeOnly(student.marked_at) })]),
          el('td', {}, [select])
        ])
      );

      state.lastStatuses[student.student_id] = student.status;
    });
  }

  function refreshStudents() {
    return window.QrivoApi.get('/teacher/attendance/' + sessionId + '/live/students', {
      search: state.filters.search || null,
      status: state.filters.status || null
    }).then(function (res) {
      state.students = res.data.students || [];
      state.studentsVersion = res.data.students_version || state.studentsVersion;
      renderStudents();
    }).catch(function (error) {
      if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
      window.QrivoUi.showError(error);
    });
  }

  // ─── manual status change (PATCH) ─────────────────────────────────────────

  function changeStatus(student, nextStatus, select) {
    var previous = student.status;
    if (nextStatus === previous) return;

    window.QrivoUi.confirmDialog({
      title: 'Yoklama Durumunu Değiştir',
      body: student.first_name + ' ' + student.last_name + ' (' + student.student_number + '): '
        + window.QrivoUi.statusLabel(previous) + ' → ' + window.QrivoUi.statusLabel(nextStatus),
      confirmText: 'Değiştir',
      variant: 'primary',
      reason: true
    }).then(function (result) {
      if (!result) { select.value = previous; return; }

      select.disabled = true;
      var body = { status: nextStatus };
      if (result.reason) body.reason = result.reason;

      window.QrivoApi.patch(
        '/teacher/attendance/' + sessionId + '/student/' + student.student_id,
        body
      ).then(function (res) {
        window.QrivoUi.toast(
          student.student_number + ' → ' + window.QrivoUi.statusLabel(res.data.status),
          'success'
        );
        return poll(true);
      }).catch(function (error) {
        // Show the API's message verbatim and roll the control back.
        window.QrivoUi.showError(error);
        select.value = previous;
      }).then(function () {
        select.disabled = false;
      });
    });
  }

  // ─── bulk actions (confirmation required) ─────────────────────────────────

  nodes.bulkBar.addEventListener('click', function (e) {
    var button = e.target.closest('[data-bulk]');
    if (!button) return;

    var target = button.dataset.bulk;
    var waiting = state.students.filter(function (s) { return s.status === 'WAITING'; });

    if (waiting.length === 0) {
      window.QrivoUi.toast('Bekleyen öğrenci yok.', 'info');
      return;
    }

    window.QrivoUi.confirmDialog({
      title: 'Toplu İşlem',
      body: waiting.length + ' bekleyen öğrenci ' + window.QrivoUi.statusLabel(target)
        + ' olarak işaretlenecek. Bu işlem her öğrenci için denetim kaydı oluşturur.',
      confirmText: window.QrivoUi.statusLabel(target) + ' yap',
      variant: target === 'ABSENT' ? 'danger' : 'success',
      reason: true
    }).then(function (result) {
      if (!result) return;

      window.QrivoUi.setBusy(button, true, 'İşleniyor…');

      // Sequential: each PATCH is an independent, individually-audited call.
      var ok = 0, failed = 0, lastError = null;
      var chain = Promise.resolve();

      waiting.forEach(function (student) {
        chain = chain.then(function () {
          var body = { status: target };
          if (result.reason) body.reason = result.reason;
          return window.QrivoApi.patch(
            '/teacher/attendance/' + sessionId + '/student/' + student.student_id, body
          ).then(function () { ok++; }, function (error) { failed++; lastError = error; });
        });
      });

      chain.then(function () {
        window.QrivoUi.setBusy(button, false);
        if (failed === 0) {
          window.QrivoUi.toast(ok + ' öğrenci güncellendi.', 'success');
        } else {
          window.QrivoUi.toast(
            ok + ' güncellendi, ' + failed + ' başarısız. ' + (lastError ? lastError.message : ''),
            'warning'
          );
        }
        poll(true);
      });
    });
  });

  // ─── close / cancel ───────────────────────────────────────────────────────

  nodes.closeBtn.addEventListener('click', function () {
    window.QrivoUi.confirmDialog({
      title: 'Yoklamayı Kapat',
      body: 'Yoklama kapatılacak. Bekleyen öğrenciler sistem ayarına göre YOK veya İNCELEMEDE olarak işaretlenir ve yeni QR gönderimi kabul edilmez.',
      confirmText: 'YOKLAMAYI KAPAT',
      variant: 'danger'
    }).then(function (ok) {
      if (!ok) return;
      window.QrivoUi.setBusy(nodes.closeBtn, true, 'Kapatılıyor…');
      window.QrivoApi.post('/teacher/attendance/' + sessionId + '/close')
        .then(function () {
          window.QrivoUi.toast('Yoklama kapatıldı.', 'success');
          stopTimers();
          return loadSnapshot();
        })
        .catch(function (error) { window.QrivoUi.showError(error); })
        .then(function () {
          // setBusy() re-enables unconditionally, so re-apply the state-derived
          // disabled flags afterwards — a CLOSED session must not offer close again.
          window.QrivoUi.setBusy(nodes.closeBtn, false);
          renderSessionInfo();
        });
    });
  });

  nodes.cancelBtn.addEventListener('click', function () {
    window.QrivoUi.confirmDialog({
      title: 'Yoklamayı İptal Et',
      body: 'Yoklama iptal edilecek. Yoklama kayıtları değiştirilmez ancak oturum geçersiz sayılır. Bu işlem geri alınamaz.',
      confirmText: 'YOKLAMAYI İPTAL ET',
      variant: 'danger',
      reason: true
    }).then(function (result) {
      if (!result) return;
      window.QrivoUi.setBusy(nodes.cancelBtn, true, 'İptal ediliyor…');
      window.QrivoApi.post('/teacher/attendance/' + sessionId + '/cancel',
        result.reason ? { reason: result.reason } : {})
        .then(function () {
          window.QrivoUi.toast('Yoklama iptal edildi.', 'success');
          stopTimers();
          return loadSnapshot();
        })
        .catch(function (error) { window.QrivoUi.showError(error); })
        .then(function () {
          window.QrivoUi.setBusy(nodes.cancelBtn, false);
          renderSessionInfo();
        });
    });
  });

  // ─── polling (AD-010) ─────────────────────────────────────────────────────

  function markPoll(ok) {
    nodes.pollDot.classList.toggle('is-stale', !ok);
    nodes.pollStatus.textContent = ok
      ? 'Canlı · ' + Math.round(state.pollMs / 1000) + ' sn'
      : 'Bağlantı sorunu';
  }

  /** Counters every tick; the roster only when `students_version` changes. */
  function poll(force) {
    if (state.stopped) return Promise.resolve();

    return window.QrivoApi.get('/teacher/attendance/' + sessionId + '/live/counters')
      .then(function (res) {
        var data = res.data || {};
        state.counters = data.counters || state.counters;
        state.remaining = data.remaining_seconds;

        if (state.session && data.session_status && state.session.status !== data.session_status) {
          state.session.status = data.session_status;
          renderSessionInfo();
          if (data.session_status !== 'ACTIVE') { renderQr(null); stopTimers(); }
        }

        renderCounters();
        markPoll(true);

        if (force || data.students_version !== state.studentsVersion) {
          state.studentsVersion = data.students_version;
          return refreshStudents();
        }
      })
      .catch(function (error) {
        if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
        markPoll(false);
      });
  }

  function tickRemaining() {
    if (state.remaining === null || state.remaining === undefined) {
      nodes.remaining.textContent = '—';
      return;
    }
    if (state.session && state.session.status === 'ACTIVE' && state.remaining > 0) state.remaining -= 1;
    nodes.remaining.textContent = window.QrivoUi.seconds(state.remaining);
  }

  function startTimers() {
    stopTimers();
    state.stopped = false;
    state.pollTimer = window.setInterval(poll, state.pollMs);
    state.qrTimer = window.setInterval(refreshQr, state.qrRefreshMs);
    state.tickTimer = window.setInterval(tickRemaining, 1000);
  }

  function stopTimers() {
    state.stopped = true;
    [state.pollTimer, state.qrTimer, state.tickTimer].forEach(window.clearInterval);
    state.pollTimer = state.qrTimer = state.tickTimer = null;
    nodes.pollStatus.textContent = 'Durduruldu';
    nodes.pollDot.classList.add('is-stale');
  }

  // Pause polling while the tab is hidden — no needless load (spec §13).
  document.addEventListener('visibilitychange', function () {
    if (!state.session || state.session.status !== 'ACTIVE') return;
    if (document.hidden) stopTimers();
    else { startTimers(); poll(true); }
  });

  window.addEventListener('beforeunload', stopTimers);

  // ─── boot ─────────────────────────────────────────────────────────────────

  loadSnapshot().then(function (data) {
    loadLabels();
    if (data.session && data.session.status === 'ACTIVE') startTimers();
    else { markPoll(true); nodes.pollStatus.textContent = 'Oturum kapalı'; tickRemaining(); }
  }).catch(function (error) {
    if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
    nodes.loadError.textContent = error.message;
    nodes.loadError.classList.remove('d-none');
    nodes.studentBody.replaceChildren(
      el('tr', {}, [el('td', { colspan: '6', class: 'text-center text-danger py-4', text: error.message })])
    );
  });
})();
