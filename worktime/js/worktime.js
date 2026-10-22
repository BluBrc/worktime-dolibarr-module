/* Modul Worktime – Stempeluhr (vanilla JS, fetch API, kein Seitenreload) */
(function () {
	'use strict';

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function escapeHtml(s) {
		return String(s === null || s === undefined ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }

	document.addEventListener('DOMContentLoaded', function () {
		var box = $('#wt-clock');
		if (!box) { return; }

		var cfg = {
			url: box.getAttribute('data-url'),
			token: box.getAttribute('data-token'),
			statusLabels: JSON.parse(box.getAttribute('data-status-labels') || '{}'),
			noEntries: box.getAttribute('data-no-entries') || '',
			errGeneric: box.getAttribute('data-err-generic') || 'Error'
		};
		var busy = false;

		// Live-Uhr
		var clockEl = $('#wt-now');
		function tick() {
			if (!clockEl) { return; }
			var d = new Date();
			clockEl.textContent = pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
		}
		tick();
		setInterval(tick, 1000);

		function showMessage(text, ok) {
			var el = $('#wt-message');
			if (!el) { return; }
			el.textContent = text || '';
			el.className = 'wt-message ' + (ok ? 'wt-ok' : 'wt-err');
		}

		function applyStatus(status, allowed) {
			var badge = $('#wt-status');
			if (badge) {
				badge.className = 'wt-status wt-status-' + status;
				badge.textContent = cfg.statusLabels[status] || status;
			}
			$all('.wt-btn').forEach(function (btn) {
				var act = btn.getAttribute('data-action');
				if (allowed.indexOf(act) >= 0) { btn.classList.remove('wt-hidden'); } else { btn.classList.add('wt-hidden'); }
			});
			box.setAttribute('data-status', status);
		}

		function setText(id, text, cls) {
			var el = document.getElementById(id);
			if (!el) { return; }
			el.textContent = text;
			if (cls !== undefined) {
				el.classList.remove('wt-positive', 'wt-negative', 'wt-neutral');
				el.classList.add(cls);
			}
		}

		function applyToday(t) {
			if (!t) { return; }
			setText('wt-today-work', t.work_formatted);
			setText('wt-today-break', t.break_formatted);
			setText('wt-week-balance', t.week_balance, t.week_balance_class);
			setText('wt-month-balance', t.month_balance, t.month_balance_class);
			setText('wt-week-actual', t.week_actual);
			setText('wt-month-actual', t.month_actual);
			var ul = $('#wt-timeline');
			if (ul) {
				if (!t.entries || !t.entries.length) {
					ul.innerHTML = '<li class="opacitymedium">' + escapeHtml(cfg.noEntries) + '</li>';
				} else {
					ul.innerHTML = t.entries.map(function (e) {
						return '<li class="wt-tl-' + escapeHtml(e.type) + '"><span class="wt-tl-time">' + escapeHtml(e.time) + '</span> ' +
							escapeHtml(e.label) + (e.note ? ' <span class="wt-tl-note">– ' + escapeHtml(e.note) + '</span>' : '') + '</li>';
					}).join('');
				}
			}
		}

		function post(action) {
			var fd = new FormData();
			fd.append('action', action);
			fd.append('token', cfg.token);
			var noteEl = $('#wt-note');
			if (noteEl && action !== 'status') { fd.append('note', noteEl.value || ''); }
			return fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
				.then(function (r) {
					return r.json().catch(function () { throw new Error(cfg.errGeneric + ' (HTTP ' + r.status + ')'); });
				});
		}

		$all('.wt-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (busy) { return; }
				busy = true;
				$all('.wt-btn').forEach(function (b) { b.disabled = true; });
				post(btn.getAttribute('data-action'))
					.then(function (data) {
						if (data.new_status && data.allowed) { applyStatus(data.new_status, data.allowed); }
						if (data.today) { applyToday(data.today); }
						if (data.success) {
							showMessage(data.message || data.time, true);
							var noteEl = $('#wt-note');
							if (noteEl) { noteEl.value = ''; }
						} else {
							showMessage(data.error || cfg.errGeneric, false);
						}
					})
					.catch(function (err) { showMessage(err.message || cfg.errGeneric, false); })
					.then(function () {
						busy = false;
						$all('.wt-btn').forEach(function (b) { b.disabled = false; });
					});
			});
		});

		// Laufende Zeiten jede Minute aktualisieren (nur wenn eingestempelt)
		setInterval(function () {
			var st = box.getAttribute('data-status');
			if (busy || (st !== 'clock_in' && st !== 'break')) { return; }
			post('status').then(function (data) {
				if (data.success) {
					applyStatus(data.new_status, data.allowed);
					applyToday(data.today);
				}
			}).catch(function () { /* still */ });
		}, 60000);
	});
})();
