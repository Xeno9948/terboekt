(function (root) {
    var STEPS = ['stay', 'details', 'review', 'result'];
    var DRAFT_KEY = 'terboekt_booking_draft';
    var RESULT_KEY = 'terboekt_booking_result';

    function t(key) {
        return (root.TerboektApi && root.TerboektApi.t(key)) || key;
    }

    function $(sel, parent) {
        return (parent || document).querySelector(sel);
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

    function addDaysFromToday(days) {
        return root.TerboektCalendar.addDays(root.TerboektCalendar.todayYmd(), Number(days) || 7);
    }

    function countryLabel(code) {
        return t('book_country_' + code) || code;
    }

    function showError(form, message) {
        var error = form.querySelector('.form-error');
        if (!error) return;
        error.textContent = message || t('form_error');
        error.classList.add('show');
        error.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideError(form) {
        var error = form.querySelector('.form-error');
        if (error) error.classList.remove('show');
        if (error && error.getAttribute('data-i18n')) {
            error.textContent = t(error.getAttribute('data-i18n'));
        }
    }

    function applyI18n() {
        if (typeof root.applyTranslations === 'function') {
            root.applyTranslations(root.currentLang || 'nl');
        }
    }

    function readDraft() {
        try {
            return JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
        } catch (err) {
            return null;
        }
    }

    function writeDraft(data) {
        try {
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(data));
        } catch (err) { /* private mode */ }
    }

    function clearDraft() {
        try { sessionStorage.removeItem(DRAFT_KEY); } catch (err) { /* ignore */ }
    }

    function init(form) {
        if (!form || form.dataset.bookingReady === '1') return;
        form.dataset.bookingReady = '1';

        var calendarRoot = $('#booking-calendar', form);
        var quoteRoot = $('#booking-quote', form);
        var stepEls = form.querySelectorAll('[data-step]');
        var progressBtns = document.querySelectorAll('.booking-progress [data-go-step]');
        var rangeLabel = $('#booking-range-label', form);
        var availabilityNote = $('#booking-availability-note', form);
        var gapNote = $('#booking-api-gap', form);
        var bankBox = $('#booking-bank', form);
        var statusBox = $('#booking-status', form);
        var submitBtn = $('#booking-submit', form);
        var transferBtn = $('#booking-transfer-btn', form);
        var languageInput = $('#booking-language', form);
        var nameInput = $('#name', form);
        var checkinInput = $('#checkin', form);
        var checkoutInput = $('#checkout', form);

        var state = {
            step: 'stay',
            checkin: '',
            checkout: '',
            quote: null,
            quoteOk: false,
            advisory: false,
            booking: null,
            bank: null,
            bankConfigured: false,
            transferNote: '',
            busy: false
        };

        var calendar = root.TerboektCalendar.mount(calendarRoot, {
            onChange: function (range) {
                state.checkin = range.checkin;
                state.checkout = range.checkout;
                state.advisory = range.advisory;
                if (checkinInput) checkinInput.value = range.checkin || '';
                if (checkoutInput) checkoutInput.value = range.checkout || '';
                updateRangeLabel();
                persist();
                refreshQuote();
            },
            onInvalid: function () {
                showError(form, t('book_blocked_range'));
            }
        });

        function persist() {
            writeDraft({
                checkin: state.checkin,
                checkout: state.checkout,
                guests: form.guests.value,
                sunday_evening: !!(form.sunday_evening && form.sunday_evening.checked),
                first_name: (form.first_name && form.first_name.value) || '',
                last_name: (form.last_name && form.last_name.value) || '',
                email: form.email.value,
                phone: form.phone.value,
                address: (form.address && form.address.value) || '',
                city: (form.city && form.city.value) || '',
                postal: (form.postal && form.postal.value) || '',
                country: (form.country && form.country.value) || 'BE',
                message: form.message.value
            });
        }

        function restoreDraft() {
            var draft = readDraft();
            if (!draft) return;
            if (draft.guests) form.guests.value = draft.guests;
            if (form.sunday_evening) form.sunday_evening.checked = !!draft.sunday_evening;
            ['first_name', 'last_name', 'email', 'phone', 'address', 'city', 'postal', 'country', 'message'].forEach(function (key) {
                if (form[key] && draft[key]) form[key].value = draft[key];
            });
            if (draft.checkin) {
                calendar.setRange(draft.checkin, draft.checkout || '', true);
                state.checkin = draft.checkin;
                state.checkout = draft.checkout || '';
                if (checkinInput) checkinInput.value = state.checkin;
                if (checkoutInput) checkoutInput.value = state.checkout;
            }
        }

        function setStep(next) {
            state.step = next;
            stepEls.forEach(function (el) {
                var active = el.getAttribute('data-step') === next;
                el.hidden = !active;
                el.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
            progressBtns.forEach(function (btn) {
                var step = btn.getAttribute('data-go-step');
                var idx = STEPS.indexOf(step);
                var current = STEPS.indexOf(next);
                btn.classList.toggle('is-active', step === next);
                btn.classList.toggle('is-done', idx < current);
                btn.disabled = idx > current || next === 'result';
            });
            document.querySelectorAll('.booking-progress').forEach(function (ol) {
                ol.classList.toggle('is-complete', next === 'result');
            });
            document.querySelectorAll('.booking-progress-static').forEach(function (el) {
                el.classList.toggle('is-active', next === 'result');
            });
            if (next === 'review') renderReview();
            if (next === 'result') renderResult();
            applyI18n();
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function updateRangeLabel() {
            if (!rangeLabel) return;
            rangeLabel.classList.toggle('is-empty', !(state.checkin || state.checkout));
            if (state.checkin && state.checkout) {
                rangeLabel.textContent = formatDate(state.checkin) + ' → ' + formatDate(state.checkout);
            } else if (state.checkin) {
                rangeLabel.textContent = formatDate(state.checkin) + ' → …';
            } else {
                rangeLabel.textContent = t('book_select_dates');
            }
        }

        function setAvailabilityNote(kind, message) {
            if (!availabilityNote) return;
            availabilityNote.hidden = !kind;
            availabilityNote.className = 'form-help booking-note' + (kind === 'warn' ? ' is-warn' : '');
            if (message) availabilityNote.textContent = message;
        }

        async function loadAvailability() {
            var from = root.TerboektCalendar.todayYmd();
            var to = root.TerboektCalendar.addDays(from, 400);
            var result = await root.TerboektApi.fetchAvailability(from, to);
            if (!result.ok) {
                calendar.markAdvisory();
                state.advisory = true;
                setAvailabilityNote('warn', t('book_availability_advisory'));
                return;
            }
            calendar.setAvailability(result.data);
            if (result.data && result.data.calendar_health && result.data.calendar_health.stale) {
                setAvailabilityNote('warn', t('book_availability_stale'));
            } else {
                setAvailabilityNote('info', t('book_availability_advisory'));
            }
        }

        var quoteTimer = null;
        function refreshQuote() {
            hideError(form);
            state.quote = null;
            state.quoteOk = false;
            updateNextStay();
            if (!state.checkin || !state.checkout) {
                root.TerboektQuote.render(quoteRoot, null, { mode: 'idle' });
                return;
            }
            root.TerboektQuote.render(quoteRoot, null, { mode: 'loading' });
            clearTimeout(quoteTimer);
            quoteTimer = setTimeout(fetchQuoteNow, 180);
        }

        async function fetchQuoteNow() {
            if (!state.checkin || !state.checkout) return;
            var guests = Number(form.guests.value || 0);
            var sunday = !!(form.sunday_evening && form.sunday_evening.checked);
            var result = await root.TerboektApi.fetchQuote(state.checkin, state.checkout, guests, sunday);
            if (!result.ok) {
                state.quoteOk = false;
                root.TerboektQuote.render(quoteRoot, null, {
                    mode: 'error',
                    message: root.TerboektApi.errorText(result)
                });
                updateNextStay();
                return;
            }
            state.quote = result.data.quote || result.data;
            state.quoteOk = true;
            root.TerboektQuote.render(quoteRoot, state.quote, { mode: 'ok' });
            updateNextStay();
        }

        function updateNextStay() {
            var next = $('#booking-next-stay', form);
            if (next) next.disabled = !(state.checkin && state.checkout && state.quoteOk) || state.busy;
        }

        function syncName() {
            var first = ((form.first_name && form.first_name.value) || '').trim();
            var last = ((form.last_name && form.last_name.value) || '').trim();
            if (nameInput) nameInput.value = [first, last].filter(Boolean).join(' ');
            if (languageInput) languageInput.value = root.currentLang || 'nl';
        }

        function detailsValid() {
            syncName();
            var required = ['first_name', 'last_name', 'email', 'address', 'city', 'postal', 'country'];
            for (var i = 0; i < required.length; i++) {
                var field = form[required[i]];
                if (field && !String(field.value || '').trim()) return false;
            }
            if (!form.email.checkValidity()) return false;
            if (!form.rules.checked) return false;
            return !!(nameInput && nameInput.value);
        }

        function composeMessage() {
            var user = String(form.message.value || '').trim();
            var lines = [];
            if (user) lines.push(user, '');
            lines.push('Address: ' + String(form.address.value || '').trim());
            lines.push('City: ' + String(form.city.value || '').trim());
            lines.push('Postal: ' + String(form.postal.value || '').trim());
            lines.push('Country: ' + countryLabel(form.country.value || 'BE'));
            return lines.join('\n');
        }

        function payload() {
            syncName();
            return {
                name: nameInput.value,
                first_name: (form.first_name.value || '').trim(),
                last_name: (form.last_name.value || '').trim(),
                email: form.email.value.trim(),
                phone: (form.phone.value || '').trim(),
                address: (form.address.value || '').trim(),
                city: (form.city.value || '').trim(),
                postal: (form.postal.value || '').trim(),
                country: form.country.value,
                checkin: state.checkin,
                checkout: state.checkout,
                check_in: state.checkin,
                check_out: state.checkout,
                guests: Number(form.guests.value),
                guest_count: Number(form.guests.value),
                message: composeMessage(),
                special_requests: String(form.message.value || '').trim(),
                rules: !!form.rules.checked,
                language: root.currentLang || 'nl',
                sunday_evening: !!(form.sunday_evening && form.sunday_evening.checked)
            };
        }

        function dlRow(label, value) {
            return '<div class="review-row"><span>' + label + '</span><strong>' + value + '</strong></div>';
        }

        function renderReview() {
            var box = $('#booking-review', form);
            if (!box) return;
            var quote = state.quote || {};
            var html = '';
            html += dlRow(t('form_checkin'), formatDate(state.checkin));
            html += dlRow(t('form_checkout'), formatDate(state.checkout));
            html += dlRow(t('form_guests'), form.guests.value);
            html += dlRow(t('form_name'), nameInput.value);
            html += dlRow(t('form_email'), form.email.value);
            if (form.phone.value) html += dlRow(t('form_phone'), form.phone.value);
            html += dlRow(t('form_address'), form.address.value + ', ' + form.postal.value + ' ' + form.city.value);
            html += dlRow(t('form_country'), countryLabel(form.country.value));
            if (form.message.value) html += dlRow(t('form_message'), form.message.value);
            html += dlRow(t('book_quote_nights'), String(quote.nights || '—'));
            html += dlRow(t('book_quote_total'), root.TerboektQuote.euro(quote.total_cents));
            html += dlRow(t('book_quote_deposit'), root.TerboektQuote.euro(quote.deposit_cents));
            html += dlRow(t('book_quote_remaining'), root.TerboektQuote.euro(quote.remaining_cents));
            box.innerHTML = html;
        }

        function statusCopy(status) {
            if (status === 'AWAITING_DEPOSIT') {
                return {
                    title: t('book_awaiting_title'),
                    badge: t('book_status_awaiting'),
                    tone: 'awaiting'
                };
            }
            return {
                title: t('book_request_received_title'),
                badge: t('book_status_requested'),
                tone: 'requested'
            };
        }

        function renderBank(bank, configured) {
            if (!bankBox) return;
            if (!configured || !bank) {
                bankBox.innerHTML = '<p class="form-help show">' + t('book_bank_missing') + '</p>';
                return;
            }
            var html = '<dl class="bank-dl">';
            if (bank.bank_account_holder) html += dlRow(t('book_bank_holder'), bank.bank_account_holder);
            if (bank.bank_iban) html += dlRow(t('book_bank_iban'), bank.bank_iban);
            if (bank.bank_bic) html += dlRow(t('book_bank_bic'), bank.bank_bic);
            if (bank.bank_name) html += dlRow(t('book_bank_name'), bank.bank_name);
            html += '</dl>';
            if (!bank.bank_iban) {
                html += '<p class="form-help show">' + t('book_bank_missing') + '</p>';
            }
            bankBox.innerHTML = html;
        }

        function renderResult() {
            var booking = state.booking || {};
            var status = String(booking.status || 'REQUESTED').toUpperCase();
            var copy = statusCopy(status);
            var quote = state.quote || {};
            var deposit = booking.deposit_cents != null ? booking.deposit_cents : quote.deposit_cents;
            var deadlineDays = quote.deposit_deadline_days || 7;
            var due = booking.payment_due_at || booking.deposit_due_at || addDaysFromToday(deadlineDays);
            var dueLabel = String(due).indexOf('T') > -1 || String(due).indexOf(' ') > -1
                ? formatDate(String(due).slice(0, 10))
                : formatDate(due);

            if (statusBox) {
                statusBox.className = 'booking-status is-' + copy.tone;
                statusBox.innerHTML = ''
                    + '<p class="section-kicker">' + copy.badge + '</p>'
                    + '<h3>' + copy.title + '</h3>'
                    + '<p>' + t('book_not_confirmed') + '</p>'
                    + dlRow(t('book_reference'), booking.reference || '—')
                    + dlRow(t('book_deposit_amount'), root.TerboektQuote.euro(deposit))
                    + dlRow(t('book_deadline'), dueLabel + ' (' + deadlineDays + ' ' + t('book_deadline_days') + ')');
            }
            renderBank(state.bank, state.bankConfigured);
            if (gapNote) {
                gapNote.hidden = !state.transferNote;
                gapNote.textContent = state.transferNote || '';
            }
            if (transferBtn) {
                var ack = form.transfer_ack && form.transfer_ack.checked;
                transferBtn.disabled = status === 'AWAITING_DEPOSIT' || state.busy || !ack;
                transferBtn.hidden = false;
            }
        }

        async function submitRequest() {
            if (state.busy) return;
            hideError(form);
            if (!(state.checkin && state.checkout && state.quoteOk)) {
                setStep('stay');
                showError(form, t('book_quote_pick_dates'));
                return;
            }
            if (!detailsValid()) {
                setStep('details');
                form.reportValidity();
                showError(form, t('form_error'));
                return;
            }
            state.busy = true;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('form_submit');
            }
            var result = await root.TerboektApi.createBooking(payload());
            state.busy = false;
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = t('form_submit');
            }
            if (!result.ok) {
                showError(form, root.TerboektApi.errorText(result));
                return;
            }
            state.booking = result.data;
            if (!state.booking.status) state.booking.status = 'REQUESTED';
            var bankResult = await root.TerboektApi.fetchBankDetails(result.data);
            state.bank = bankResult.bank;
            state.bankConfigured = !!bankResult.configured;
            clearDraft();
            try { sessionStorage.setItem(RESULT_KEY, JSON.stringify({ booking: state.booking, quote: state.quote, bank: state.bank, bankConfigured: state.bankConfigured })); } catch (err) { /* ignore */ }
            setStep('result');
        }

        async function confirmTransfer() {
            if (state.busy || !state.booking) return;
            if (!(form.transfer_ack && form.transfer_ack.checked)) {
                showError(form, t('book_transfer_understand'));
                return;
            }
            var id = state.booking.id || state.booking.reference;
            var email = ((form.email && form.email.value) || '').trim();
            state.busy = true;
            if (transferBtn) {
                transferBtn.disabled = true;
                transferBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('book_transfer_confirm');
            }
            var result = await root.TerboektApi.confirmTransferIntent(id, {
                email: email,
                guest_email: email,
                language: root.currentLang || 'nl'
            });
            state.busy = false;
            if (transferBtn) transferBtn.textContent = t('book_transfer_confirm');
            if (result.ok && result.data) {
                var returned = result.data.booking || result.data;
                state.booking = Object.assign({}, state.booking, returned);
                if (returned.status) state.booking.status = returned.status;
                var bank = root.TerboektApi.extractBank(result.data);
                if (bank) {
                    state.bank = bank;
                    state.bankConfigured = true;
                }
                state.transferNote = '';
                if (String(state.booking.status || '').toUpperCase() !== 'AWAITING_DEPOSIT') {
                    state.transferNote = t('book_transfer_gap');
                }
            } else if (result.status === 404) {
                state.transferNote = t('book_transfer_gap');
            } else {
                state.transferNote = root.TerboektApi.errorText(result);
            }
            renderResult();
            applyI18n();
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (state.step !== 'review') return;
            submitRequest();
        });

        form.addEventListener('change', persist);
        form.addEventListener('input', persist);
        form.guests.addEventListener('change', refreshQuote);
        if (form.sunday_evening) form.sunday_evening.addEventListener('change', refreshQuote);

        form.querySelectorAll('[data-go-step]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var next = btn.getAttribute('data-go-step');
                if (next === 'details') {
                    if (!(state.checkin && state.checkout && state.quoteOk)) {
                        showError(form, t('book_quote_pick_dates'));
                        return;
                    }
                }
                if (next === 'review') {
                    if (!detailsValid()) {
                        form.reportValidity();
                        showError(form, t('form_error'));
                        return;
                    }
                    syncName();
                }
                hideError(form);
                persist();
                setStep(next);
            });
        });

        if (transferBtn) transferBtn.addEventListener('click', confirmTransfer);
        if (form.transfer_ack) {
            form.transfer_ack.addEventListener('change', function () {
                if (state.step === 'result') renderResult();
            });
        }
        var clearBtn = $('#booking-clear-dates', form);
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                calendar.setRange('', '');
            });
        }

        document.addEventListener('lang:changed', function () {
            if (languageInput) languageInput.value = root.currentLang || 'nl';
            calendar.refresh();
            updateRangeLabel();
            if (state.quoteOk) root.TerboektQuote.render(quoteRoot, state.quote, { mode: 'ok' });
            if (state.step === 'review') renderReview();
            if (state.step === 'result') renderResult();
            applyI18n();
        });

        restoreDraft();
        updateRangeLabel();
        root.TerboektQuote.render(quoteRoot, null, { mode: 'idle' });
        updateNextStay();
        loadAvailability();
        if (state.checkin && state.checkout) refreshQuote();
        applyI18n();
    }

    root.TerboektCheckout = { init: init };
})(window);
