(function (root) {
    const FALLBACK = {
        currency: 'EUR',
        maxGuests: 8,
        bedrooms: 4,
        propertyName: 'Home Terboekt',
        address: 'Terboekt 28, 3600 Genk',
        email: 'info@hometerboekt.be',
        checkinFrom: '16:00',
        checkoutBefore: '10:00',
        depositPercentage: 30,
        packages: [
            { id: 'weekend', nights: 2, lowCents: 80000, highCents: 90000, enabled: true },
            { id: 'extended', nights: 3, lowCents: 110000, highCents: 125000, enabled: true },
            { id: 'midweek', nights: 4, lowCents: 125000, highCents: 150000, enabled: true },
            { id: 'week', nights: 7, lowCents: 210000, highCents: 240000, enabled: true }
        ],
        fees: {
            sundayEveningExtraCents: 15000,
            cleaningCents: 10000,
            touristTaxPerPersonPerNightCents: 150,
            securityDepositCents: 50000
        },
        extraGuest: { enabled: false, threshold: null, cents: null }
    };

    function formatEuro(cents) {
        const negative = cents < 0;
        const abs = Math.abs(cents);
        const major = Math.floor(abs / 100);
        const minor = String(abs % 100).padStart(2, '0');
        const grouped = String(major).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (negative ? '-' : '') + '€ ' + grouped + (minor === '00' ? '' : ',' + minor);
    }

    function formatEuroPair(low, high) {
        return formatEuro(low) + ' / ' + formatEuro(high);
    }

    function siteVarsFromRates(data) {
        const fees = data.fees || {};
        const extra = data.extraGuest || {};
        return {
            cleaning: formatEuro(Number(fees.cleaningCents) || 0),
            tax: formatEuro(Number(fees.touristTaxPerPersonPerNightCents) || 0),
            deposit: formatEuro(Number(fees.securityDepositCents) || 0),
            sunday: formatEuro(Number(fees.sundayEveningExtraCents) || 0),
            extraGuest: formatEuro(Number(extra.cents) || 0),
            extraGuestThreshold: extra.threshold != null ? String(extra.threshold) : '',
            maxGuests: String(data.maxGuests != null ? data.maxGuests : 8),
            bedrooms: String(data.bedrooms != null ? data.bedrooms : 4),
            email: data.email || 'info@hometerboekt.be',
            address: data.address || 'Terboekt 28, 3600 Genk',
            propertyName: data.propertyName || 'Home Terboekt',
            checkinFrom: data.checkinFrom || '16:00',
            checkoutBefore: data.checkoutBefore || '10:00',
            depositPercent: String(data.depositPercentage != null ? data.depositPercentage : 30)
        };
    }

    root.TerboektSiteVars = root.TerboektSiteVars || siteVarsFromRates(FALLBACK);

    function applySiteChrome(data) {
        const vars = siteVarsFromRates(data);
        root.TerboektSiteVars = vars;

        document.querySelectorAll('[data-site="propertyName"]').forEach((el) => {
            el.textContent = vars.propertyName;
        });
        document.querySelectorAll('[data-site="address"]').forEach((el) => {
            el.textContent = vars.address;
        });
        document.querySelectorAll('[data-site="email"]').forEach((el) => {
            el.textContent = vars.email;
            if (el.tagName === 'A') {
                el.setAttribute('href', 'mailto:' + vars.email);
            }
        });

        const guestSelect = document.getElementById('guests');
        if (guestSelect && guestSelect.tagName === 'SELECT') {
            const max = Math.max(1, parseInt(vars.maxGuests, 10) || 8);
            const previous = parseInt(guestSelect.value, 10) || max;
            const chosen = Math.min(Math.max(previous, 1), max);
            guestSelect.innerHTML = '';
            for (let i = 1; i <= max; i += 1) {
                const option = document.createElement('option');
                option.value = String(i);
                option.textContent = String(i);
                option.selected = i === chosen;
                guestSelect.appendChild(option);
            }
            guestSelect.value = String(chosen);
        }

        document.querySelectorAll('[data-extra-guest]').forEach((el) => {
            el.hidden = !(data.extraGuest && data.extraGuest.enabled);
        });

        if (typeof root.applyTranslations === 'function') {
            root.applyTranslations(root.currentLang || 'nl');
        }
    }

    function applyRates(data) {
        const packages = data.packages || [];
        packages.forEach((pkg) => {
            const enabled = pkg.enabled !== false;
            document.querySelectorAll('[data-rate-package="' + pkg.id + '"]').forEach((el) => {
                el.hidden = !enabled;
            });
            if (!enabled) {
                return;
            }
            document.querySelectorAll('[data-rate="' + pkg.id + '"][data-season="low"]').forEach((el) => {
                el.textContent = formatEuro(pkg.lowCents);
            });
            document.querySelectorAll('[data-rate="' + pkg.id + '"][data-season="high"]').forEach((el) => {
                el.textContent = formatEuro(pkg.highCents);
            });
            document.querySelectorAll('[data-rate="' + pkg.id + '"][data-season="both"]').forEach((el) => {
                el.textContent = formatEuroPair(pkg.lowCents, pkg.highCents);
            });
        });

        const fees = data.fees || {};
        const feeMap = {
            cleaning: fees.cleaningCents,
            tax: fees.touristTaxPerPersonPerNightCents,
            deposit: fees.securityDepositCents,
            sunday: fees.sundayEveningExtraCents
        };
        Object.keys(feeMap).forEach((key) => {
            if (feeMap[key] == null) {
                return;
            }
            document.querySelectorAll('[data-fee="' + key + '"]').forEach((el) => {
                el.textContent = formatEuro(feeMap[key]);
            });
        });

        applySiteChrome(data);
    }

    async function loadRates() {
        try {
            const api = await fetch('api/rates', { headers: { Accept: 'application/json' } });
            if (api.ok) {
                const json = await api.json();
                if (json && json.rates) {
                    return json.rates;
                }
            }
        } catch (e) { /* API optional on static hosting */ }
        try {
            const res = await fetch('assets/data/rates.json', { headers: { Accept: 'application/json' } });
            if (res.ok) {
                return await res.json();
            }
        } catch (e) { /* keep fallback */ }
        return FALLBACK;
    }

    function quoteUrl(checkin, checkout, guests) {
        const params = new URLSearchParams({ checkin: checkin, checkout: checkout, guests: String(guests) });
        return 'api/quote?' + params.toString();
    }

    root.TerboektPricing = {
        formatEuro: formatEuro,
        quoteUrl: quoteUrl,
        availabilityUrl: function (from, to) {
            return 'api/availability?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
        }
    };

    let latestRates = FALLBACK;

    function boot() {
        loadRates().then((data) => {
            latestRates = data;
            applyRates(data);
        });
    }

    document.addEventListener('lang:changed', () => {
        applyRates(latestRates);
    });
    document.addEventListener('components:ready', () => {
        applySiteChrome(latestRates);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
