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
                <li><a href="/voorwaarden" data-site="houseRulesUrl" data-i18n="footer_rules">Huisreglement</a></li>
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
            </ul>
        </div>
    </div>
    <div class="container footer-bottom">
        <p data-i18n="footer_copyright">&copy; 2026 Home Terboekt. Alle rechten voorbehouden.</p>
    </div>
</footer>
`;

function loadFooter() {
    const footerPlaceholder = document.getElementById('footer-placeholder');
    if (footerPlaceholder) {
        footerPlaceholder.innerHTML = footerHTML;
    }
    document.dispatchEvent(new Event('components:ready'));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadFooter);
} else {
    loadFooter();
}
