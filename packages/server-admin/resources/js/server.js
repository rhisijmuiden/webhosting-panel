// Server management screens: follow running jobs, the reboot dialog and confirmations.
(function () {
  'use strict';

  function poll(el) {
    var url = el.getAttribute('data-run-poll');
    var log = el.querySelector('[data-run-log]');
    var summary = el.querySelector('[data-run-summary]');
    var failures = 0;
    var lastStatus = null;

    function tick() {
      fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
        .then(function (d) {
          failures = 0;
          if (log) {
            var atEnd = log.scrollTop + log.clientHeight >= log.scrollHeight - 8;
            log.textContent = d.log || '…';
            if (atEnd) { log.scrollTop = log.scrollHeight; }
          }
          if (summary && d.summary) { summary.textContent = d.summary; }
          var changed = lastStatus !== null && (d.status !== lastStatus);
          var phaseChanged = el.dataset.phase && d.phase && el.dataset.phase !== d.phase;
          lastStatus = d.status;
          if (d.phase) { el.dataset.phase = el.dataset.phase || d.phase; }
          if ((d.finished || changed || phaseChanged) && el.hasAttribute('data-reload-on-done')) {
            window.setTimeout(function () { window.location.reload(); }, 800);
            return;
          }
          if (!d.finished) { window.setTimeout(tick, 2500); }
        })
        .catch(function () {
          // The panel itself is away while the server reboots: keep trying.
          failures++;
          window.setTimeout(tick, Math.min(15000, 3000 + failures * 1000));
        });
    }
    tick();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-run-poll]').forEach(poll);

    document.querySelectorAll('[data-open-dialog]').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = document.getElementById(b.getAttribute('data-open-dialog'));
        if (d && d.showModal) { d.showModal(); }
      });
    });
    document.querySelectorAll('[data-open-on-load]').forEach(function (m) {
      var d = document.getElementById(m.getAttribute('data-open-on-load'));
      if (d && d.showModal) { d.showModal(); }
    });
    document.querySelectorAll('[data-close-dialog]').forEach(function (b) {
      b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) { d.close(); } });
    });

    document.querySelectorAll('[data-confirm-match]').forEach(function (input) {
      var form = input.closest('form');
      var submit = form && form.querySelector('[data-confirm-submit]');
      var want = (input.getAttribute('data-confirm-match') || '').toLowerCase();
      function check() { if (submit) { submit.disabled = input.value.trim().toLowerCase() !== want; } }
      input.addEventListener('input', check);
      check();
    });

    document.querySelectorAll('[data-confirm]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        if (!window.confirm(b.getAttribute('data-confirm'))) { e.preventDefault(); }
      });
    });
  });
})();
