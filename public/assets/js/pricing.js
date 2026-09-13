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

    function packageById(data, id) {
        const packages = data.packages || [];
        for (let i = 0; i < packages.length; i += 1) {
            if (packages[i] && packages[i].id === id) {
                return packages[i];
            }
        }
        return null;
    }

    function siteVarsFromRates(data) {
        const fees = data.fees || {};
        const extra = data.extraGuest || {};
        const weekend = packageById(data, 'weekend') || {};
        const extended = packageById(data, 'extended') || {};
        const midweek = packageById(data, 'midweek') || {};
        const week = packageById(data, 'week') || {};
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
            depositPercent: String(data.depositPercentage != null ? data.depositPercentage : 30),
            weekendLow: formatEuro(Number(weekend.lowCents) || 0),
            weekendHigh: formatEuro(Number(weekend.highCents) || 0),
            extendedLow: formatEuro(Number(extended.lowCents) || 0),
            extendedHigh: formatEuro(Number(extended.highCents) || 0),
            midweekLow: formatEuro(Number(midweek.lowCents) || 0),
            midweekHigh: formatEuro(Number(midweek.highCents) || 0),
            weekLow: formatEuro(Number(week.lowCents) || 0),
            weekHigh: formatEuro(Number(week.highCents) || 0)
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
        document.querySelectorAll('[data-site="maxGuests"]').forEach((el) => {
            el.textContent = vars.maxGuests;
        });
        document.querySelectorAll('[data-site="bedrooms"]').forEach((el) => {
            el.textContent = vars.bedrooms;
        });
        document.querySelectorAll('[data-site="houseRulesUrl"]').forEach((el) => {
            if (el.tagName === 'A' && data.houseRulesUrl) {
                el.setAttribute('href', data.houseRulesUrl);
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
        applyJsonLd(data);
    }

    function schemaPrice(cents) {
        return (Math.max(0, Number(cents) || 0) / 100).toFixed(2);
    }

    function applyJsonLd(data) {
        const script = document.querySelector('script[type="application/ld+json"]');
        if (!script) {
            return;
        }
        let graph;
        try {
            graph = JSON.parse(script.textContent);
        } catch (e) {
            return;
        }
        const nodes = graph['@graph'] || (Array.isArray(graph) ? graph : [graph]);
        const packages = {
            weekend: packageById(data, 'weekend'),
            midweek: packageById(data, 'midweek'),
            week: packageById(data, 'week')
        };
        const fees = data.fees || {};
        const offerPrices = {
            'https://hometerboekt.be/prijzen#offer-weekend-low': packages.weekend ? packages.weekend.lowCents : null,
            'https://hometerboekt.be/prijzen#offer-weekend-high': packages.weekend ? packages.weekend.highCents : null,
            'https://hometerboekt.be/prijzen#offer-midweek-low': packages.midweek ? packages.midweek.lowCents : null,
            'https://hometerboekt.be/prijzen#offer-midweek-high': packages.midweek ? packages.midweek.highCents : null,
            'https://hometerboekt.be/prijzen#offer-week-low': packages.week ? packages.week.lowCents : null,
            'https://hometerboekt.be/prijzen#offer-week-high': packages.week ? packages.week.highCents : null,
            'https://hometerboekt.be/prijzen#offer-cleaning': fees.cleaningCents,
            'https://hometerboekt.be/prijzen#offer-tax': fees.touristTaxPerPersonPerNightCents,
            'https://hometerboekt.be/prijzen#offer-deposit': fees.securityDepositCents,
            'https://hometerboekt.be/prijzen#offer-sunday': fees.sundayEveningExtraCents
        };
        const interpolate = root.interpolateTranslation;
        const dict = (root.translations && (root.translations[root.currentLang || 'nl'] || root.translations.nl)) || {};
        nodes.forEach((node) => {
            if (!node) {
                return;
            }
            if (node['@type'] === 'Offer' && node['@id'] && offerPrices[node['@id']] != null) {
                node.price = schemaPrice(offerPrices[node['@id']]);
            }
            if (node['@type'] === 'FAQPage' && Array.isArray(node.mainEntity) && typeof interpolate === 'function') {
                node.mainEntity.forEach((item, index) => {
                    const key = 'prices_faq_' + (index + 1) + '_a';
                    if (item && item.acceptedAnswer && dict[key]) {
                        item.acceptedAnswer.text = interpolate(dict[key]);
                    }
                });
            }
        });
        script.textContent = JSON.stringify(graph);
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
        });
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        return res.json();
    }

    async function loadRates() {
        const apiPaths = ['/api/rates', 'api/rates'];
        for (let i = 0; i < apiPaths.length; i += 1) {
            try {
                const json = await fetchJson(apiPaths[i]);
                if (json && json.rates) {
                    return json.rates;
                }
            } catch (e) { /* try next source */ }
        }
        try {
            return await fetchJson('assets/data/rates.json');
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
