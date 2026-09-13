(function (root) {
    const FALLBACK = {
        currency: 'EUR',
        maxGuests: 8,
        packages: [
            { id: 'weekend', nights: 2, lowCents: 80000, highCents: 90000 },
            { id: 'extended', nights: 3, lowCents: 110000, highCents: 125000 },
            { id: 'midweek', nights: 4, lowCents: 125000, highCents: 150000 },
            { id: 'week', nights: 7, lowCents: 210000, highCents: 240000 }
        ],
        fees: {
            sundayEveningExtraCents: 15000,
            cleaningCents: 10000,
            touristTaxPerPersonPerNightCents: 150,
            securityDepositCents: 50000
        }
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

    function applyRates(data) {
        const packages = data.packages || [];
        packages.forEach((pkg) => {
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

    function boot() {
        loadRates().then(applyRates);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
