/**
 * QRIVO web client — teacher reports (PROJECT_SPECIFICATION.md §6.16).
 *
 * Calls GET /teacher/reports/{course|class|student}/{id}. The API scopes every
 * report to the teacher's own courses/classes and returns 403 otherwise — this
 * screen only renders what comes back. The course/class pickers are populated
 * from /teacher/schedule, so a teacher can only ever pick something they are
 * already assigned to; the server re-checks regardless.
 */
(function () {
  'use strict';

  var el = window.QrivoUi.el;

  if (!window.QrivoAuth.requireSession()) return;
  window.QrivoAuth.mountChrome();

  var nodes = {
    type: document.getElementById('reportType'),
    target: document.getElementById('targetSelect'),
    targetLabel: document.getElementById('targetLabel'),
    targetHelp: document.getElementById('targetHelp'),
    studentWrap: document.getElementById('studentNumberWrap'),
    student: document.getElementById('studentSelect'),
    from: document.getElementById('fromDate'),
    to: document.getElementById('toDate'),
    run: document.getElementById('runBtn'),
    error: document.getElementById('reportError'),
    result: document.getElementById('reportResult'),
    summaryGrid: document.getElementById('summaryGrid'),
    summaryLine: document.getElementById('summaryLine'),
    detailTitle: document.getElementById('detailTitle'),
    detailHead: document.getElementById('detailHead'),
    detailBody: document.getElementById('detailBody'),
    pagerWrap: document.getElementById('pagerWrap'),
    pagerInfo: document.getElementById('pagerInfo'),
    prev: document.getElementById('prevPage'),
    next: document.getElementById('nextPage')
  };

  var state = { schedule: [], page: 1, perPage: 25, lastMeta: null };

  // ─── pickers ──────────────────────────────────────────────────────────────

  function loadSchedule() {
    return window.QrivoApi.get('/teacher/schedule').then(function (res) {
      state.schedule = res.data.schedule || [];
      populateTargets();
    }).catch(function (error) {
      if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
      showError(error.message);
    });
  }

  function uniqueBy(rows, keyFn) {
    var seen = {};
    return rows.filter(function (row) {
      var key = keyFn(row);
      if (seen[key]) return false;
      seen[key] = true;
      return true;
    });
  }

  function populateTargets() {
    var type = nodes.type.value;
    nodes.target.replaceChildren();

    var options;
    if (type === 'class') {
      nodes.targetLabel.textContent = 'Sınıf';
      options = uniqueBy(state.schedule, function (s) { return s.class_id; })
        .map(function (s) { return { value: s.class_id, label: s.class_name }; });
    } else {
      nodes.targetLabel.textContent = type === 'student' ? 'Ders (öğrenci listesi için)' : 'Ders';
      options = uniqueBy(state.schedule, function (s) { return s.course_id; })
        .map(function (s) { return { value: s.course_id, label: s.course_code + ' — ' + s.course_name }; });
    }

    if (options.length === 0) {
      nodes.target.appendChild(el('option', { value: '', text: 'Atanmış kayıt yok' }));
      nodes.run.disabled = true;
      return;
    }

    nodes.run.disabled = false;
    options.forEach(function (option) {
      nodes.target.appendChild(el('option', { value: option.value, text: option.label }));
    });

    nodes.studentWrap.classList.toggle('d-none', type !== 'student');
    if (type === 'student') loadStudentsForCourse();
  }

  /** Students come from the roster of the course's most recent session. */
  function loadStudentsForCourse() {
    nodes.student.replaceChildren(el('option', { value: '', text: 'Yükleniyor…' }));

    window.QrivoApi.get('/teacher/reports/course/' + nodes.target.value, { per_page: 1 })
      .then(function (res) {
        var sessions = (res.data && res.data.sessions) || [];
        if (sessions.length === 0) {
          nodes.student.replaceChildren(el('option', { value: '', text: 'Bu derste yoklama yok' }));
          return;
        }
        return window.QrivoApi.get('/teacher/attendance/' + sessions[0].session_id + '/live/students')
          .then(function (r) {
            nodes.student.replaceChildren();
            (r.data.students || []).forEach(function (s) {
              nodes.student.appendChild(el('option', {
                value: s.student_id,
                text: s.student_number + ' — ' + s.first_name + ' ' + s.last_name
              }));
            });
            if (!nodes.student.children.length) {
              nodes.student.appendChild(el('option', { value: '', text: 'Öğrenci bulunamadı' }));
            }
          });
      })
      .catch(function (error) {
        nodes.student.replaceChildren(el('option', { value: '', text: error.message }));
      });
  }

  nodes.type.addEventListener('change', function () { state.page = 1; populateTargets(); });
  nodes.target.addEventListener('change', function () {
    if (nodes.type.value === 'student') loadStudentsForCourse();
  });

  // ─── run ──────────────────────────────────────────────────────────────────

  function showError(message) {
    nodes.error.textContent = message;
    nodes.error.classList.remove('d-none');
    nodes.result.classList.add('d-none');
  }

  function run() {
    nodes.error.classList.add('d-none');

    var type = nodes.type.value;
    var id = type === 'student' ? nodes.student.value : nodes.target.value;
    if (!id) { showError('Lütfen bir seçim yapın.'); return; }

    var path = type === 'course' ? '/teacher/reports/course/'
      : type === 'class' ? '/teacher/reports/class/'
        : '/teacher/reports/student/';

    var query = { page: state.page, per_page: state.perPage };
    if (nodes.from.value) query.from = nodes.from.value;
    if (nodes.to.value) query.to = nodes.to.value;
    if (type === 'student') query.course_id = nodes.target.value;

    window.QrivoUi.setBusy(nodes.run, true, '…');

    window.QrivoApi.get(path + id, query).then(function (res) {
      render(type, res.data || {});
    }).catch(function (error) {
      if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
      // The API's own message — e.g. a 403 scope refusal — shown verbatim.
      showError(error.message);
    }).then(function () {
      window.QrivoUi.setBusy(nodes.run, false);
    });
  }

  nodes.run.addEventListener('click', function () { state.page = 1; run(); });
  nodes.prev.addEventListener('click', function () {
    if (state.page > 1) { state.page -= 1; run(); }
  });
  nodes.next.addEventListener('click', function () {
    if (state.lastMeta && state.page < state.lastMeta.total_pages) { state.page += 1; run(); }
  });

  // ─── render ───────────────────────────────────────────────────────────────

  function render(type, data) {
    nodes.result.classList.remove('d-none');
    renderSummary(data.summary || {});

    if (type === 'course') renderSessions(data.sessions || []);
    else if (type === 'class') renderStudents(data.students || []);
    else renderRecords(data.records || []);

    var meta = data.meta || null;
    state.lastMeta = meta;
    nodes.pagerWrap.hidden = !meta;

    if (meta) {
      nodes.pagerInfo.textContent = 'Sayfa ' + meta.page + ' / ' + (meta.total_pages || 1)
        + ' · toplam ' + meta.total + ' kayıt';
      nodes.prev.disabled = meta.page <= 1;
      nodes.next.disabled = meta.page >= (meta.total_pages || 1);
    }
  }

  function renderSummary(summary) {
    var counts = summary.counts || {};
    var tiles = [
      { value: summary.total_records || 0, label: 'KAYIT', cls: 'is-total' },
      { value: counts.present || 0, label: 'VAR', cls: 'is-present' },
      { value: counts.absent || 0, label: 'YOK', cls: 'is-absent' },
      { value: counts.late || 0, label: 'GEÇ', cls: 'is-late' },
      { value: counts.excused || 0, label: 'MAZERETLİ', cls: 'is-excused' },
      { value: counts.waiting || 0, label: 'BEKLİYOR', cls: 'is-waiting' }
    ];

    nodes.summaryGrid.replaceChildren();
    tiles.forEach(function (tile) {
      nodes.summaryGrid.appendChild(
        el('div', { class: 'counter-tile ' + tile.cls }, [
          el('div', { class: 'counter-value', text: String(tile.value) }),
          el('div', { class: 'counter-label', text: tile.label })
        ])
      );
    });

    var rate = Math.round((summary.present_rate || 0) * 100);
    nodes.summaryLine.textContent = (summary.sessions || 0) + ' oturum · '
      + (summary.marked_records || 0) + ' işaretlenmiş kayıt · katılım oranı %' + rate;
  }

  function head(labels) {
    nodes.detailHead.replaceChildren();
    labels.forEach(function (label) {
      nodes.detailHead.appendChild(el('th', { scope: 'col', text: label }));
    });
  }

  function empty(colspan) {
    nodes.detailBody.replaceChildren(
      el('tr', {}, [el('td', {
        colspan: String(colspan), class: 'text-center text-body-secondary py-4', text: 'Kayıt bulunamadı.'
      })])
    );
  }

  function renderSessions(rows) {
    nodes.detailTitle.textContent = 'Yoklama Oturumları';
    head(['Tarih', 'Sınıf', 'Durum', 'VAR', 'YOK', 'GEÇ', 'MAZ', 'Oran']);
    nodes.detailBody.replaceChildren();
    if (!rows.length) return empty(8);

    rows.forEach(function (r) {
      var c = r.counts || {};
      nodes.detailBody.appendChild(el('tr', {}, [
        el('td', { text: window.QrivoUi.dateTime(r.start_time) }),
        el('td', { text: r.class_name || ('#' + r.class_id) }),
        el('td', {}, [window.QrivoUi.sessionBadge(r.session_status)]),
        el('td', { text: String(c.present || 0) }),
        el('td', { text: String(c.absent || 0) }),
        el('td', { text: String(c.late || 0) }),
        el('td', { text: String(c.excused || 0) }),
        el('td', { text: '%' + Math.round((r.present_rate || 0) * 100) })
      ]));
    });
  }

  function renderStudents(rows) {
    nodes.detailTitle.textContent = 'Öğrenci Bazlı Katılım';
    head(['Numara', 'Öğrenci', 'VAR', 'YOK', 'GEÇ', 'MAZ', 'Oran']);
    nodes.detailBody.replaceChildren();
    if (!rows.length) return empty(7);

    rows.forEach(function (r) {
      var c = r.counts || {};
      nodes.detailBody.appendChild(el('tr', {}, [
        el('td', {}, [el('span', { class: 'student-number', text: r.student_number })]),
        el('td', { class: 'student-name', text: (r.first_name || '') + ' ' + (r.last_name || '') }),
        el('td', { text: String(c.present || 0) }),
        el('td', { text: String(c.absent || 0) }),
        el('td', { text: String(c.late || 0) }),
        el('td', { text: String(c.excused || 0) }),
        el('td', { text: '%' + Math.round((r.present_rate || 0) * 100) })
      ]));
    });
  }

  function renderRecords(rows) {
    nodes.detailTitle.textContent = 'Yoklama Geçmişi';
    head(['Tarih', 'Ders', 'Sınıf', 'Durum', 'Kaynak', 'İşaretlenme']);
    nodes.detailBody.replaceChildren();
    if (!rows.length) return empty(6);

    rows.forEach(function (r) {
      nodes.detailBody.appendChild(el('tr', {}, [
        el('td', { text: window.QrivoUi.dateTime(r.start_time) }),
        el('td', { text: (r.course_code || '') + ' ' + (r.course_name || '') }),
        el('td', { text: r.class_name || '' }),
        el('td', {}, [window.QrivoUi.statusBadge(r.status)]),
        el('td', {}, [window.QrivoUi.sourceBadge(r.source)]),
        el('td', { text: window.QrivoUi.dateTime(r.marked_at) })
      ]));
    });
  }

  loadSchedule();
})();
