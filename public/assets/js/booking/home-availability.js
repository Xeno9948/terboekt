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

    function paintRange(rootEl, checkin, checkout) {
        var inEl = rootEl.querySelector('[data-home-checkin]');
        var outEl = rootEl.querySelector('[data-home-checkout]');
        if (inEl) inEl.textContent = formatDate(checkin);
        if (outEl) outEl.textContent = formatDate(checkout);
    }

    function init(rootEl) {
        if (!rootEl || !root.TerboektCalendar || !root.TerboektApi) return;
        if (rootEl.dataset.ready === '1') return;
        rootEl.dataset.ready = '1';

        var calEl = rootEl.querySelector('[data-home-cal]');
        if (!calEl) return;

        var calendar = root.TerboektCalendar.mount(calEl, {
            onChange: function (range) {
                writeRange(range.checkin, range.checkout);
                paintRange(rootEl, range.checkin, range.checkout);
            }
        });

        var draft = readDraft();
        if (draft.checkin) {
            calendar.setRange(draft.checkin, draft.checkout || '');
        } else {
            paintRange(rootEl, '', '');
        }

        async function loadAvailability() {
            var from = root.TerboektCalendar.todayYmd();
            var to = root.TerboektCalendar.addDays(from, 400);
            var result = await root.TerboektApi.fetchAvailability(from, to);
            if (!result.ok) {
                calendar.markAdvisory();
                return;
            }
            calendar.setAvailability(result.data);
        }

        document.addEventListener('lang:changed', function () {
            calendar.refresh();
            var range = calendar.getRange();
            paintRange(rootEl, range.checkin, range.checkout);
        });

        loadAvailability();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var block = document.getElementById('availability');
        if (block) init(block);
    });
})(window);
