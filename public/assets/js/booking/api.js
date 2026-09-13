(function (root) {
    function t(key) {
        var lang = root.currentLang || 'nl';
        var dict = (root.translations && (root.translations[lang] || root.translations.nl)) || {};
        if (dict[key]) return dict[key];
        if (root.translations && root.translations.nl && root.translations.nl[key]) {
            return root.translations.nl[key];
        }
        return key;
    }

    function apiPath(path) {
        return String(path || '').replace(/^\//, '');
    }

    function withQuery(path, params) {
        var url = 'api/' + apiPath(path);
        if (!params) return url;
        var search = new URLSearchParams();
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value === undefined || value === null || value === '') return;
            search.set(key, String(value));
        });
        var qs = search.toString();
        return qs ? url + '?' + qs : url;
    }

    async function request(method, path, body) {
        var opts = {
            method: method,
            headers: { Accept: 'application/json' }
        };
        if (body !== undefined && body !== null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        try {
            var res = await fetch(path, opts);
            var text = await res.text();
            var data = {};
            if (text) {
                try {
                    data = JSON.parse(text);
                } catch (err) {
                    data = { message: text };
                }
            }
            return { ok: res.ok, status: res.status, data: data };
        } catch (err) {
            return {
                ok: false,
                status: 0,
                data: { error: 'network', message: t('book_api_offline') }
            };
        }
    }

    function errorText(result) {
        var data = (result && result.data) || {};
        if (Array.isArray(data.errors) && data.errors.length) {
            return data.errors.join(' · ');
        }
        if (data.message) return data.message;
        if (data.error) return String(data.error);
        return t('book_api_offline');
    }

    function extractBank(source) {
        if (!source || typeof source !== 'object') return null;
        var bag = source.settings || source.bank || source;
        var holder = String(bag.bank_account_holder || bag.account_holder || '').trim();
        var iban = String(bag.bank_iban || bag.iban || '').trim();
        var bic = String(bag.bank_bic || bag.bic || '').trim();
        var bankName = String(bag.bank_name || bag.bank || '').trim();
        if (!holder && !iban && !bic && !bankName) return null;
        return {
            bank_account_holder: holder,
            bank_iban: iban,
            bank_bic: bic,
            bank_name: bankName
        };
    }

    async function fetchAvailability(from, to) {
        var path = root.TerboektPricing && root.TerboektPricing.availabilityUrl
            ? root.TerboektPricing.availabilityUrl(from, to)
            : withQuery('availability', { from: from, to: to, start: from, end: to });
        return request('GET', path);
    }

    async function fetchQuote(checkin, checkout, guests, sundayEvening) {
        var params = {
            checkin: checkin,
            checkout: checkout,
            check_in: checkin,
            check_out: checkout,
            guests: guests,
            guest_count: guests
        };
        if (sundayEvening) params.sunday_evening = '1';
        var path = root.TerboektPricing && root.TerboektPricing.quoteUrl
            ? root.TerboektPricing.quoteUrl(checkin, checkout, guests) + (sundayEvening ? '&sunday_evening=1' : '')
            : withQuery('quote', params);
        return request('GET', path);
    }

    async function createBooking(payload) {
        return request('POST', 'api/bookings', payload);
    }

    async function confirmTransferIntent(idOrRef, extra) {
        var id = encodeURIComponent(String(idOrRef || ''));
        if (!id) {
            return { ok: false, status: 0, data: { error: 'missing_id' } };
        }
        var body = Object.assign({ language: root.currentLang || 'nl' }, extra || {});
        return request('POST', 'api/bookings/' + id + '/confirm-transfer-intent', body);
    }

    async function fetchBankDetails(hints) {
        var found = extractBank(hints);
        if (found) return { ok: true, configured: true, bank: found, source: 'payload' };
        var paths = ['api/settings', 'api/property-settings', 'api/bank', 'api/rates'];
        for (var i = 0; i < paths.length; i++) {
            var result = await request('GET', paths[i]);
            var bank = extractBank(result.data);
            if (result.ok && bank) {
                return { ok: true, configured: true, bank: bank, source: paths[i] };
            }
        }
        return { ok: true, configured: false, bank: null, source: null };
    }

    root.TerboektApi = {
        t: t,
        request: request,
        errorText: errorText,
        extractBank: extractBank,
        fetchAvailability: fetchAvailability,
        fetchQuote: fetchQuote,
        createBooking: createBooking,
        confirmTransferIntent: confirmTransferIntent,
        fetchBankDetails: fetchBankDetails
    };
})(window);
