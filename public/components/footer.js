const footerHTML = `
<footer>
    <div class="container footer-content">
        <div class="footer-col">
            <h3 data-site="propertyName">Home Terboekt</h3>
            <p data-i18n="footer_desc">Uw luxe thuisbasis voor een onvergetelijke vakantie in Belgisch Limburg.</p>
        </div>
        <div class="footer-col">
            <h4 data-i18n="footer_links">Navigatie</h4>
            <ul>
                <li><a href="/" data-i18n="nav_home">Home</a></li>
                <li><a href="/genk" data-i18n="nav_genk">Bezoek Genk</a></li>
                <li><a href="/prijzen" data-i18n="nav_prices">Prijzen</a></li>
                <li><a href="/contact" data-i18n="nav_contact">Boeken</a></li>
                <li><a href="/voorwaarden" data-site="houseRulesUrl" data-i18n="footer_rules">Voorwaarden</a></li>
                <li><a href="/annulatie" data-site="cancellationUrl" data-i18n="footer_cancel">Annulatievoorwaarden</a></li>
                <li><a href="/vakantiehuis-genk" data-i18n="nav_vakantiehuis_genk">Vakantiehuis Genk</a></li>
                <li><a href="/vakantiehuis-zwembad-limburg" data-i18n="nav_vakantiehuis_zwembad">Vakantiehuis met zwembad</a></li>
                <li><a href="/nationaal-park-hoge-kempen" data-i18n="nav_hoge_kempen">Nationaal Park Hoge Kempen</a></li>
                <li><a href="/weekendje-weg-limburg" data-i18n="nav_weekendje">Weekendje weg Limburg</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4 data-i18n="footer_discover">Ontdek</h4>
            <ul>
                <li><a href="/vakantiehuis-genk" data-i18n="nav_vakantiehuis_genk">Vakantiehuis Genk</a></li>
                <li><a href="/vakantiehuis-zwembad-limburg" data-i18n="nav_vakantiehuis_zwembad">Vakantiehuis met zwembad</a></li>
                <li><a href="/nationaal-park-hoge-kempen" data-i18n="nav_hoge_kempen">Nationaal Park Hoge Kempen</a></li>
                <li><a href="/weekendje-weg-limburg" data-i18n="nav_weekendje">Weekendje weg Limburg</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4 data-i18n="footer_contact">Contact</h4>
            <ul class="contact-info">
                <li><i class="fa-solid fa-location-dot"></i> <span data-site="address">Terboekt 28, 3600 Genk</span></li>
                <li><i class="fa-solid fa-envelope"></i> <a data-site="email" href="mailto:info@hometerboekt.be">info@hometerboekt.be</a></li>
                <li><i class="fa-solid fa-building"></i> <span data-i18n="footer_vat">BTW</span> <span data-site="vat">BE1030279857</span></li>
            </ul>
        </div>
    </div>
    <div class="container footer-bottom">
        <p data-i18n="footer_copyright">&copy; 2026 Home Terboekt. Alle rechten voorbehouden.</p>
    </div>
</footer>
`;

function paintSiteFields() {
    const live = window.TerboektLiveConfig || {};
    const vars = window.TerboektSiteVars || {};
    const propertyName = live.property_name || vars.propertyName;
    const address = live.property_address || vars.address;
    const email = live.contact_email || vars.email;
    const vat = live.vat_number || vars.vat;
    const houseRulesUrl = live.house_rules_url || vars.houseRulesUrl;
    const cancellationUrl = live.cancellation_url || vars.cancellationUrl;

    if (propertyName) {
        document.querySelectorAll('[data-site="propertyName"]').forEach((el) => {
            el.textContent = propertyName;
        });
    }
    if (address) {
        document.querySelectorAll('[data-site="address"]').forEach((el) => {
            el.textContent = address;
        });
    }
    if (email) {
        document.querySelectorAll('[data-site="email"]').forEach((el) => {
            el.textContent = email;
            if (el.tagName === 'A') {
                el.setAttribute('href', 'mailto:' + email);
            }
        });
        window.TerboektSiteVars = Object.assign({}, vars, { email: email });
    }
    if (vat) {
        document.querySelectorAll('[data-site="vat"]').forEach((el) => {
            el.textContent = vat;
        });
        window.TerboektSiteVars = Object.assign({}, window.TerboektSiteVars || vars, { vat: vat });
    }
    if (houseRulesUrl) {
        document.querySelectorAll('[data-site="houseRulesUrl"]').forEach((el) => {
            if (el.tagName === 'A') {
                el.setAttribute('href', houseRulesUrl);
            }
        });
    }
    if (cancellationUrl) {
        document.querySelectorAll('[data-site="cancellationUrl"]').forEach((el) => {
            if (el.tagName === 'A') {
                el.setAttribute('href', cancellationUrl);
            }
        });
    }
}

function applyLiveConfig(json) {
    if (!json || json.ok === false) {
        return false;
    }
    const email = String(json.contact_email || '').trim();
    const name = String(json.property_name || '').trim();
    const address = String(json.property_address || '').trim();
    const vat = String(json.vat_number || '').trim();
    const houseRules = String(json.house_rules_url || '').trim();
    const cancellation = String(json.cancellation_url || '').trim();
    if (!email && !name && !address && !vat) {
        return false;
    }
    window.TerboektLiveConfig = {
        contact_email: email,
        property_name: name,
        property_address: address,
        vat_number: vat,
        house_rules_url: houseRules,
        cancellation_url: cancellation
    };
    window.TerboektSiteVars = Object.assign({}, window.TerboektSiteVars || {}, {
        email: email || (window.TerboektSiteVars && window.TerboektSiteVars.email),
        propertyName: name || (window.TerboektSiteVars && window.TerboektSiteVars.propertyName),
        address: address || (window.TerboektSiteVars && window.TerboektSiteVars.address),
        vat: vat || (window.TerboektSiteVars && window.TerboektSiteVars.vat) || 'BE1030279857',
        houseRulesUrl: houseRules || (window.TerboektSiteVars && window.TerboektSiteVars.houseRulesUrl),
        cancellationUrl: cancellation || (window.TerboektSiteVars && window.TerboektSiteVars.cancellationUrl)
    });
    paintSiteFields();
    return true;
}

function loadLiveConfig() {
    const paths = ['/api/config', 'api/config'];
    let chain = Promise.reject();
    paths.forEach((url) => {
        chain = chain.catch(() =>
            fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' }).then((res) => {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
        );
    });
    return chain.then((json) => {
        if (!applyLiveConfig(json)) {
            throw new Error('empty config');
        }
    }).catch(() => {
        return fetch('/api/rates', { headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then((res) => (res.ok ? res.json() : Promise.reject()))
            .then((json) => {
                const rates = json && json.rates ? json.rates : {};
                applyLiveConfig({
                    ok: true,
                    contact_email: rates.email || '',
                    property_name: rates.propertyName || '',
                    property_address: rates.address || '',
                    vat_number: rates.vatNumber || '',
                    house_rules_url: rates.houseRulesUrl || '',
                    cancellation_url: rates.cancellationUrl || ''
                });
            })
            .catch(() => undefined);
    });
}

function loadFooter() {
    const footerPlaceholder = document.getElementById('footer-placeholder');
    if (footerPlaceholder) {
        footerPlaceholder.innerHTML = footerHTML;
    }
    document.dispatchEvent(new Event('components:ready'));
    loadLiveConfig();
}

window.paintSiteFields = paintSiteFields;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadFooter);
} else {
    loadFooter();
}
