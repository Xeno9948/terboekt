(function (root) {
    const STORAGE_KEY = 'terboekt_cookie_consent';
    const VERSION = 1;

    function policyUrl() {
        const live = root.TerboektLiveConfig || {};
        const vars = root.TerboektSiteVars || {};
        return live.privacy_url || vars.privacyUrl || '/cookies';
    }

    function analyticsId() {
        const live = root.TerboektLiveConfig || {};
        return String(live.analytics_id || '').trim();
    }

    function readConsent() {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return null;
            const data = JSON.parse(raw);
            if (!data || data.v !== VERSION) return null;
            return {
                v: VERSION,
                necessary: true,
                analytics: !!data.analytics,
                ts: data.ts || ''
            };
        } catch (err) {
            return null;
        }
    }

    function writeConsent(analytics) {
        const data = {
            v: VERSION,
            necessary: true,
            analytics: !!analytics,
            ts: new Date().toISOString()
        };
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (err) { /* ignore quota */ }
        applyConsent(data);
        hideBanner();
        return data;
    }

    function loadAnalytics() {
        const id = analyticsId();
        if (!id || root.__terboektAnalyticsLoaded) return;
        root.__terboektAnalyticsLoaded = true;

        if (/^GTM-/i.test(id)) {
            root.dataLayer = root.dataLayer || [];
            root.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
            const s = document.createElement('script');
            s.async = true;
            s.src = 'https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(id);
            document.head.appendChild(s);
            return;
        }

        root.dataLayer = root.dataLayer || [];
        root.gtag = root.gtag || function () { root.dataLayer.push(arguments); };
        root.gtag('js', new Date());
        root.gtag('config', id, { anonymize_ip: true });
        const s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
        document.head.appendChild(s);
    }

    function applyConsent(consent) {
        if (consent && consent.analytics) {
            loadAnalytics();
        }
    }

    function t(key, fallback) {
        const lang = root.currentLang || 'nl';
        const dict = (root.translations && (root.translations[lang] || root.translations.nl)) || {};
        const str = dict[key] || fallback || '';
        if (typeof root.interpolateTranslation === 'function') {
            return root.interpolateTranslation(str);
        }
        return str;
    }

    function paintBanner() {
        const rootEl = document.getElementById('cookie-banner');
        if (!rootEl) return;
        rootEl.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.getAttribute('data-i18n');
            const text = t(key, el.textContent);
            if (text) el.textContent = text;
        });
        const policy = rootEl.querySelector('[data-cookie-policy]');
        if (policy) policy.setAttribute('href', policyUrl());
    }

    function bannerHTML() {
        return (
            '<div id="cookie-banner" class="cookie-banner" hidden role="dialog" aria-modal="false" aria-labelledby="cookie-banner-title">' +
                '<h2 id="cookie-banner-title" data-i18n="cookie_banner_title">Cookies</h2>' +
                '<p data-i18n="cookie_banner_text">We gebruiken noodzakelijke cookies voor de website en uw taal. Optionele statistiekcookies alleen met uw akkoord.</p>' +
                '<p><a data-cookie-policy href="/cookies" data-i18n="cookie_banner_policy">Lees het cookiebeleid</a></p>' +
                '<div class="cookie-prefs" id="cookie-prefs" hidden>' +
                    '<label class="cookie-pref">' +
                        '<input type="checkbox" checked disabled>' +
                        '<span><strong data-i18n="cookie_necessary">Noodzakelijk</strong><br>' +
                        '<span data-i18n="cookie_necessary_hint">Taal, cookiekeuze en de reservatieklad. Altijd aan.</span></span>' +
                    '</label>' +
                    '<label class="cookie-pref">' +
                        '<input type="checkbox" id="cookie-analytics-toggle">' +
                        '<span><strong data-i18n="cookie_analytics">Statistiek</strong><br>' +
                        '<span data-i18n="cookie_analytics_hint">Bezoekcijfers, alleen als u dit aanzet.</span></span>' +
                    '</label>' +
                    '<button type="button" class="btn btn-primary" data-cookie-save data-i18n="cookie_save">Bewaar keuze</button>' +
                '</div>' +
                '<div class="cookie-banner-actions">' +
                    '<button type="button" class="btn btn-outline" data-cookie-reject data-i18n="cookie_reject">Alleen noodzakelijk</button>' +
                    '<button type="button" class="btn btn-outline" data-cookie-customize data-i18n="cookie_customize">Voorkeuren</button>' +
                    '<button type="button" class="btn btn-primary" data-cookie-accept data-i18n="cookie_accept">Alles aanvaarden</button>' +
                '</div>' +
            '</div>'
        );
    }

    function hideBanner() {
        const el = document.getElementById('cookie-banner');
        if (el) el.hidden = true;
        document.body.classList.remove('has-cookie-banner');
    }

    function showBanner(openPrefs) {
        const el = document.getElementById('cookie-banner');
        if (!el) return;
        const consent = readConsent();
        const toggle = document.getElementById('cookie-analytics-toggle');
        if (toggle) toggle.checked = !!(consent && consent.analytics);
        const prefs = document.getElementById('cookie-prefs');
        if (prefs) prefs.hidden = !openPrefs;
        el.hidden = false;
        document.body.classList.add('has-cookie-banner');
        paintBanner();
    }

    function openSettings() {
        showBanner(true);
        const el = document.getElementById('cookie-banner');
        if (el && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ block: 'end', behavior: 'smooth' });
        }
    }

    function bindBanner(el) {
        el.querySelector('[data-cookie-accept]').addEventListener('click', function () {
            writeConsent(true);
        });
        el.querySelector('[data-cookie-reject]').addEventListener('click', function () {
            writeConsent(false);
        });
        el.querySelector('[data-cookie-customize]').addEventListener('click', function () {
            const prefs = document.getElementById('cookie-prefs');
            if (prefs) prefs.hidden = !prefs.hidden;
        });
        el.querySelector('[data-cookie-save]').addEventListener('click', function () {
            const toggle = document.getElementById('cookie-analytics-toggle');
            writeConsent(!!(toggle && toggle.checked));
        });
    }

    function bindOpeners(scope) {
        (scope || document).querySelectorAll('[data-cookie-open]').forEach((btn) => {
            if (btn.dataset.cookieBound === '1') return;
            btn.dataset.cookieBound = '1';
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                openSettings();
            });
        });
    }

    function mount() {
        if (!document.getElementById('cookie-banner')) {
            document.body.insertAdjacentHTML('beforeend', bannerHTML());
            bindBanner(document.getElementById('cookie-banner'));
        }
        paintBanner();
        bindOpeners(document);
        const consent = readConsent();
        if (consent) {
            applyConsent(consent);
            hideBanner();
        } else {
            showBanner(false);
        }
    }

    function onConfig() {
        paintBanner();
        const consent = readConsent();
        if (consent) applyConsent(consent);
        document.querySelectorAll('[data-site="privacyUrl"]').forEach((el) => {
            if (el.tagName === 'A') el.setAttribute('href', policyUrl());
        });
    }

    root.TerboektCookies = {
        open: openSettings,
        consent: readConsent,
        policyUrl: policyUrl
    };

    document.addEventListener('lang:changed', paintBanner);
    document.addEventListener('config:ready', onConfig);
    document.addEventListener('components:ready', function () {
        bindOpeners(document);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})(window);
