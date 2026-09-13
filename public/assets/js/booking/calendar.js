(function (root) {
    function t(key) {
        return (root.TerboektApi && root.TerboektApi.t(key)) || key;
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function toYmd(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }

    function parseYmd(ymd) {
        var parts = String(ymd || '').split('-');
        return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    }

    function addDays(ymd, days) {
        var date = parseYmd(ymd);
        date.setDate(date.getDate() + days);
        return toYmd(date);
    }

    function todayYmd() {
        var now = new Date();
        return toYmd(new Date(now.getFullYear(), now.getMonth(), now.getDate()));
    }

    function monthStart(date) {
        return new Date(date.getFullYear(), date.getMonth(), 1);
    }

    function daysInRange(start, end) {
        var out = [];
        if (!start || !end || end <= start) return out;
        var cursor = start;
        while (cursor < end) {
            out.push(cursor);
            cursor = addDays(cursor, 1);
        }
        return out;
    }

    function mount(rootEl, options) {
        var opts = options || {};
        var view = monthStart(new Date());
        var checkin = opts.checkin || '';
        var checkout = opts.checkout || '';
        var days = {};
        var ranges = [];
        var advisory = false;
        var stale = false;

        function isPast(ymd) {
            return ymd < todayYmd();
        }

        function nightAvailable(ymd) {
            if (Object.prototype.hasOwnProperty.call(days, ymd)) {
                return !!days[ymd];
            }
            for (var i = 0; i < ranges.length; i++) {
                var block = ranges[i];
                if (ymd >= block.start && ymd < block.end) return false;
            }
            return true;
        }

        function canCheckIn(ymd) {
            return !isPast(ymd) && nightAvailable(ymd);
        }

        function rangeNightsOk(start, end) {
            var nights = daysInRange(start, end);
            if (!nights.length) return false;
            for (var i = 0; i < nights.length; i++) {
                if (!nightAvailable(nights[i])) return false;
            }
            return true;
        }

        function emit() {
            if (typeof opts.onChange === 'function') {
                opts.onChange({
                    checkin: checkin,
                    checkout: checkout,
                    complete: !!(checkin && checkout && checkout > checkin),
                    advisory: advisory,
                    stale: stale
                });
            }
        }

        function setRange(nextIn, nextOut, silent) {
            checkin = nextIn || '';
            checkout = nextOut || '';
            render();
            if (!silent) emit();
        }

        function onDayClick(ymd) {
            if (isPast(ymd)) return;

            if (!checkin || (checkin && checkout)) {
                if (!canCheckIn(ymd)) return;
                setRange(ymd, '');
                return;
            }

            if (ymd === checkin) {
                setRange('', '');
                return;
            }

            if (ymd < checkin) {
                if (!canCheckIn(ymd)) return;
                setRange(ymd, '');
                return;
            }

            if (!rangeNightsOk(checkin, ymd)) {
                if (typeof opts.onInvalid === 'function') opts.onInvalid(ymd);
                return;
            }
            setRange(checkin, ymd);
        }

        function weekdayLabels() {
            return [t('book_dow_1'), t('book_dow_2'), t('book_dow_3'), t('book_dow_4'), t('book_dow_5'), t('book_dow_6'), t('book_dow_7')];
        }

        function dayClass(ymd, inMonth) {
            var classes = ['cal-day'];
            if (!inMonth) classes.push('is-outside');
            if (ymd === todayYmd()) classes.push('is-today');
            if (isPast(ymd)) classes.push('is-past');
            if (!nightAvailable(ymd) && !isPast(ymd)) {
                var checkoutCandidate = !!(checkin && !checkout && ymd > checkin && rangeNightsOk(checkin, ymd));
                if (!checkoutCandidate) classes.push('is-unavailable');
            }
            if (checkin && ymd === checkin) classes.push('is-start');
            if (checkout && ymd === checkout) classes.push('is-end');
            if (checkin && checkout && ymd > checkin && ymd < checkout) classes.push('is-in-range');
            if (checkin && !checkout && ymd === checkin) classes.push('is-in-range');
            return classes.join(' ');
        }

        function render() {
            var lang = root.currentLang || 'nl';
            var title = new Intl.DateTimeFormat(lang, { month: 'long', year: 'numeric' }).format(view);
            var labels = weekdayLabels();
            var first = monthStart(view);
            var startWeekday = (first.getDay() + 6) % 7;
            var gridStart = new Date(first);
            gridStart.setDate(first.getDate() - startWeekday);
            var cells = '';
            for (var i = 0; i < 42; i++) {
                var date = new Date(gridStart);
                date.setDate(gridStart.getDate() + i);
                var ymd = toYmd(date);
                var inMonth = date.getMonth() === view.getMonth();
                var canPickCheckout = !!(checkin && !checkout && ymd > checkin && rangeNightsOk(checkin, ymd));
                var isDisabled;
                if (isPast(ymd)) {
                    isDisabled = true;
                } else if (checkin && !checkout) {
                    if (ymd === checkin) isDisabled = false;
                    else if (ymd > checkin) isDisabled = !canPickCheckout;
                    else isDisabled = !canCheckIn(ymd);
                } else {
                    isDisabled = !canCheckIn(ymd);
                }
                cells += '<button type="button" class="' + dayClass(ymd, inMonth) + '"'
                    + ' data-ymd="' + ymd + '"'
                    + (isDisabled ? ' disabled aria-disabled="true"' : '')
                    + ' aria-label="' + ymd + '">'
                    + date.getDate()
                    + '</button>';
            }

            rootEl.innerHTML = ''
                + '<div class="cal-nav">'
                + '<button type="button" class="cal-nav-btn" data-cal-nav="-1" aria-label="' + t('book_month_prev') + '"><i class="fa-solid fa-chevron-left"></i></button>'
                + '<p class="cal-month">' + title + '</p>'
                + '<button type="button" class="cal-nav-btn" data-cal-nav="1" aria-label="' + t('book_month_next') + '"><i class="fa-solid fa-chevron-right"></i></button>'
                + '</div>'
                + '<div class="cal-weekdays">' + labels.map(function (label) {
                    return '<span>' + label + '</span>';
                }).join('') + '</div>'
                + '<div class="cal-grid" role="grid">' + cells + '</div>';

            rootEl.querySelectorAll('[data-cal-nav]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var delta = Number(btn.getAttribute('data-cal-nav') || '0');
                    view = new Date(view.getFullYear(), view.getMonth() + delta, 1);
                    render();
                    if (typeof opts.onMonthChange === 'function') {
                        opts.onMonthChange(toYmd(monthStart(view)), addDays(toYmd(new Date(view.getFullYear(), view.getMonth() + 1, 1)), 0));
                    }
                });
            });
            rootEl.querySelectorAll('.cal-day').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    onDayClick(btn.getAttribute('data-ymd'));
                });
            });
        }

        function applyAvailability(payload) {
            days = (payload && payload.days) || {};
            ranges = Array.isArray(payload && payload.unavailable) ? payload.unavailable : [];
            stale = !!(payload && payload.calendar_health && payload.calendar_health.stale);
            advisory = false;
            if (checkin && !canCheckIn(checkin)) setRange('', '');
            else if (checkin && checkout && !rangeNightsOk(checkin, checkout)) setRange(checkin, '');
            else render();
        }

        function markAdvisory() {
            advisory = true;
            days = {};
            ranges = [];
            render();
        }

        render();

        return {
            setAvailability: applyAvailability,
            markAdvisory: markAdvisory,
            setRange: setRange,
            getRange: function () { return { checkin: checkin, checkout: checkout }; },
            refresh: render,
            viewBounds: function () {
                var start = toYmd(monthStart(view));
                var end = toYmd(new Date(view.getFullYear(), view.getMonth() + 1, 1));
                return { from: start, to: end };
            }
        };
    }

    root.TerboektCalendar = {
        mount: mount,
        toYmd: toYmd,
        parseYmd: parseYmd,
        addDays: addDays,
        todayYmd: todayYmd,
        daysInRange: daysInRange
    };
})(window);
