/**
 * QRIVO web client — teacher dashboard (PROJECT_SPECIFICATION.md §12).
 *
 * Renders the four blocks the specification names: bugünkü dersler, aktif
 * yoklama, son yoklamalar, toplam katılım — all from GET /teacher/dashboard.
 *
 * The "YOKLAMA BAŞLAT" button is gated by GET /teacher/attendance/eligibility:
 * the SERVER decides whether the teacher may open a session. The client only
 * renders that answer; it never evaluates schedules or assignments itself.
 */
(function () {
  'use strict';

  var el = window.QrivoUi.el;

  if (!window.QrivoAuth.requireSession()) return;

  var todayList = document.getElementById('todayList');
  var activeSection = document.getElementById('activeSection');
  var activeList = document.getElementById('activeList');
  var recentBody = document.getElementById('recentBody');
  var totalsGrid = document.getElementById('totalsGrid');
  var totalsSummary = document.getElementById('totalsSummary');

  document.getElementById('refreshBtn').addEventListener('click', load);

  function load() {
    window.QrivoApi.get('/teacher/dashboard').then(function (res) {
      var data = res.data || {};
      window.QrivoAuth.mountChrome();
      renderActive(data.active_sessions || []);
      renderToday(data.today_schedule || []);
      renderRecent(data.recent_sessions || []);
      renderTotals(data.totals || {});
    }).catch(function (error) {
      if (error.status === 401) { window.QrivoAuth.redirectToLogin(); return; }
      window.QrivoUi.showError(error);
      todayList.replaceChildren(
        el('div', { class: 'col-12' }, [
          el('div', { class: 'alert alert-danger mb-0', text: error.message })
        ])
      );
    });
  }

  // ─── aktif yoklama ────────────────────────────────────────────────────────

  function renderActive(sessions) {
    activeSection.hidden = sessions.length === 0;
    activeList.replaceChildren();

    sessions.forEach(function (s) {
      activeList.appendChild(
        el('div', { class: 'col-12 col-md-6 col-xl-4' }, [
          el('div', { class: 'card border-success shadow-sm h-100' }, [
            el('div', { class: 'card-body' }, [
              el('div', { class: 'd-flex justify-content-between align-items-start mb-2' }, [
                el('h3', { class: 'h6 mb-0', text: s.course_code + ' — ' + s.course_name }),
                window.QrivoUi.sessionBadge(s.status)
              ]),
              el('p', { class: 'text-body-secondary small mb-2' }, [
                s.class_name + ' · ' + s.room_name + ' · ' + window.QrivoUi.timeOnly(s.start_time)
              ]),
              el('p', { class: 'mb-3 small' }, [
                el('strong', { text: String(s.counts.PRESENT) }),
                ' / ' + s.counts.TOTAL + ' katıldı'
              ]),
              el('a', {
                class: 'btn btn-success w-100',
                href: 'live.html?session=' + encodeURIComponent(s.id),
                text: 'CANLI YOKLAMAYA GİT'
              })
            ])
          ])
        ])
      );
    });
  }

  // ─── bugünkü dersler ──────────────────────────────────────────────────────

  function renderToday(slots) {
    todayList.replaceChildren();

    if (slots.length === 0) {
      todayList.appendChild(
        el('div', { class: 'col-12' }, [
          el('div', { class: 'alert alert-light border mb-0', text: 'Bugün planlanmış ders bulunmuyor.' })
        ])
      );
      return;
    }

    slots.forEach(function (slot) {
      var startBtn = el('button', {
        class: 'btn btn-primary w-100',
        type: 'button',
        text: 'YOKLAMA BAŞLAT',
        disabled: 'disabled'
      });

      var statusNote = el('div', { class: 'form-text mb-2', text: 'Yetki kontrol ediliyor…' });

      var card = el('div', { class: 'col-12 col-md-6 col-xl-4' }, [
        el('div', { class: 'card shadow-sm h-100' }, [
          el('div', { class: 'card-body d-flex flex-column' }, [
            el('h3', { class: 'h6', text: slot.course_code + ' — ' + slot.course_name }),
            el('dl', { class: 'row small mb-3' }, [
              el('dt', { class: 'col-5 text-body-secondary', text: 'Sınıf' }),
              el('dd', { class: 'col-7 mb-1', text: slot.class_name }),
              el('dt', { class: 'col-5 text-body-secondary', text: 'Derslik' }),
              el('dd', { class: 'col-7 mb-1', text: slot.room_name }),
              el('dt', { class: 'col-5 text-body-secondary', text: 'Saat' }),
              el('dd', { class: 'col-7 mb-0', text: slot.start_time + ' – ' + slot.end_time })
            ]),
            el('div', { class: 'mt-auto' }, [statusNote, startBtn])
          ])
        ])
      ]);

      todayList.appendChild(card);

      // Server-side gate. The button stays disabled until the API says yes.
      window.QrivoApi.get('/teacher/attendance/eligibility', {
        class_id: slot.class_id,
        course_id: slot.course_id,
        academic_term_id: slot.academic_term_id
      }).then(function (res) {
        var result = res.data || {};
        if (result.authorized) {
          startBtn.disabled = false;
          statusNote.textContent = 'Yoklama başlatılabilir.';
          statusNote.className = 'form-text text-success mb-2';
          startBtn.addEventListener('click', function () { startSession(slot, startBtn); });
        } else {
          // Show the API's own reason text — no client-side interpretation.
          statusNote.textContent = result.message || 'Şu anda yoklama başlatılamaz.';
          statusNote.className = 'form-text text-body-secondary mb-2';
        }
      }).catch(function (error) {
        statusNote.textContent = error.message;
        statusNote.className = 'form-text text-danger mb-2';
      });
    });
  }

  function startSession(slot, button) {
    window.QrivoUi.confirmDialog({
      title: 'Yoklama Başlat',
      body: slot.course_code + ' — ' + slot.class_name + ' için yoklama oturumu başlatılsın mı?',
      confirmText: 'YOKLAMA BAŞLAT',
      variant: 'primary'
    }).then(function (ok) {
      if (!ok) return;

      window.QrivoUi.setBusy(button, true, 'Başlatılıyor…');
      window.QrivoApi.post('/teacher/attendance/start', {
        class_id: slot.class_id,
        course_id: slot.course_id
      }).then(function (res) {
        window.location.href = 'live.html?session=' + encodeURIComponent(res.data.session.id);
      }).catch(function (error) {
        window.QrivoUi.showError(error);
        window.QrivoUi.setBusy(button, false);
      });
    });
  }

  // ─── son yoklamalar ───────────────────────────────────────────────────────

  function renderRecent(sessions) {
    recentBody.replaceChildren();

    if (sessions.length === 0) {
      recentBody.appendChild(
        el('tr', {}, [el('td', { colspan: '6', class: 'text-center text-body-secondary py-4', text: 'Henüz kapatılmış yoklama yok.' })])
      );
      return;
    }

    sessions.forEach(function (s) {
      recentBody.appendChild(
        el('tr', {}, [
          el('td', {}, [
            el('div', { class: 'fw-semibold', text: s.course_code }),
            el('div', { class: 'small text-body-secondary', text: s.course_name })
          ]),
          el('td', { text: s.class_name }),
          el('td', { text: window.QrivoUi.dateTime(s.start_time) }),
          el('td', {}, [window.QrivoUi.sessionBadge(s.status)]),
          el('td', { class: 'text-end' }, [
            el('span', { class: 'fw-semibold', text: String(s.counts.PRESENT) }),
            ' / ' + s.counts.TOTAL
          ]),
          el('td', { class: 'text-end' }, [
            el('a', {
              class: 'btn btn-sm btn-outline-secondary',
              href: 'live.html?session=' + encodeURIComponent(s.id),
              text: 'Görüntüle'
            })
          ])
        ])
      );
    });
  }

  // ─── toplam katılım ───────────────────────────────────────────────────────

  function renderTotals(totals) {
    var tiles = [
      { key: 'total', label: 'KAYIT', cls: 'is-total' },
      { key: 'present', label: 'VAR', cls: 'is-present' },
      { key: 'absent', label: 'YOK', cls: 'is-absent' },
      { key: 'late', label: 'GEÇ', cls: 'is-late' },
      { key: 'excused', label: 'MAZERETLİ', cls: 'is-excused' },
      { key: 'waiting', label: 'BEKLİYOR', cls: 'is-waiting' }
    ];

    totalsGrid.replaceChildren();
    tiles.forEach(function (tile) {
      totalsGrid.appendChild(
        el('div', { class: 'counter-tile ' + tile.cls }, [
          el('div', { class: 'counter-value', text: String(totals[tile.key] || 0) }),
          el('div', { class: 'counter-label', text: tile.label })
        ])
      );
    });

    var marked = (totals.total || 0) - (totals.waiting || 0);
    var rate = marked > 0 ? Math.round(((totals.present || 0) / marked) * 100) : 0;
    totalsSummary.textContent = (totals.sessions || 0) + ' oturum · katılım oranı %' + rate;
  }

  load();
})();
