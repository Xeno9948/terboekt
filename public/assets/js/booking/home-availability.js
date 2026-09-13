(function (root) {
    var DRAFT_KEY = 'terboekt_booking_draft';

    function t(key) {
        return (root.TerboektApi && root.TerboektApi.t(key)) || key;
    }

    function formatDate(ymd) {
        if (!ymd) return '—';
        try {
            var date = root.TerboektCalendar.parseYmd(ymd);
            return new Intl.DateTimeFormat(root.currentLang || 'nl', { dateStyle: 'medium' }).format(date);
        } catch (err) {
            return ymd;
        }
    }

    function readDraft() {
        try {
            return JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null') || {};
        } catch (err) {
            return {};
        }
    }

    function writeRange(checkin, checkout) {
        var draft = readDraft();
        draft.checkin = checkin || '';
        draft.checkout = checkout || '';
        try {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        } catch (err) { /* private mode */ }
    }

    function init(rootEl) {
        if (!rootEl || !root.TerboektCalendar || !root.TerboektApi) return;
        if (rootEl.dataset.ready === '1') return;
        rootEl.dataset.ready = '1';

        var calEl = rootEl.querySelector('[data-home-cal]');
        var rangeEl = rootEl.querySelector('[data-home-range]');
        var cta = rootEl.querySelector('[data-home-cta]');
        var note = rootEl.querySelector('[data-home-note]');
        if (!calEl) return;

        var calendar = root.TerboektCalendar.mount(calEl, {
            onChange: function (range) {
                writeRange(range.checkin, range.checkout);
                if (rangeEl) {
                    if (range.checkin && range.checkout) {
                        rangeEl.textContent = formatDate(range.checkin) + ' → ' + formatDate(range.checkout);
                        rangeEl.classList.remove('is-empty');
                    } else if (range.checkin) {
                        rangeEl.textContent = formatDate(range.checkin) + ' → …';
                        rangeEl.classList.remove('is-empty');
                    } else {
                        rangeEl.textContent = t('book_select_dates');
                        rangeEl.classList.add('is-empty');
                    }
                }
                if (cta) {
                    var ready = !!(range.checkin && range.checkout);
                    cta.classList.toggle('is-ready', ready);
                    if (ready) cta.textContent = t('home_avail_cta');
                    else cta.textContent = t('cta_book');
                }
            }
        });

        var draft = readDraft();
        if (draft.checkin) {
            calendar.setRange(draft.checkin, draft.checkout || '');
        } else if (rangeEl) {
            rangeEl.textContent = t('book_select_dates');
        }

        async function loadAvailability() {
            var from = root.TerboektCalendar.todayYmd();
            var to = root.TerboektCalendar.addDays(from, 400);
            var result = await root.TerboektApi.fetchAvailability(from, to);
            if (!result.ok) {
                calendar.markAdvisory();
                if (note) {
                    note.hidden = false;
                    note.textContent = t('book_availability_advisory');
                }
                return;
            }
            calendar.setAvailability(result.data);
            if (note) {
                var stale = result.data && result.data.calendar_health && result.data.calendar_health.stale;
                note.hidden = false;
                note.textContent = stale ? t('book_availability_stale') : t('book_availability_advisory');
            }
        }

        document.addEventListener('lang:changed', function () {
            if (typeof root.applyTranslations === 'function') {
                root.applyTranslations(root.currentLang || 'nl');
            }
            calendar.refresh();
            var range = calendar.getRange();
            if (rangeEl) {
                if (range.checkin && range.checkout) {
                    rangeEl.textContent = formatDate(range.checkin) + ' → ' + formatDate(range.checkout);
                    rangeEl.classList.remove('is-empty');
                } else if (range.checkin) {
                    rangeEl.textContent = formatDate(range.checkin) + ' → …';
                    rangeEl.classList.remove('is-empty');
                } else {
                    rangeEl.textContent = t('book_select_dates');
                    rangeEl.classList.add('is-empty');
                }
            }
            if (cta) {
                cta.textContent = (range.checkin && range.checkout) ? t('home_avail_cta') : t('cta_book');
            }
        });

        loadAvailability();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var block = document.getElementById('availability');
        if (block) init(block);
    });
})(window);
