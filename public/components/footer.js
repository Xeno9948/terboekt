// Footer Component
const footerHTML = `
<footer>
    <div class="container footer-content">
        <div class="footer-col">
            <h3>Home Terboekt</h3>
            <p data-i18n="footer_desc">Uw luxe thuisbasis voor een onvergetelijke vakantie in Belgisch Limburg.</p>
            <div class="socials">
                <a href="#"><i class="fa-brands fa-instagram"></i></a>
                <a href="#"><i class="fa-brands fa-facebook"></i></a>
            </div>
        </div>
        <div class="footer-col">
            <h4 data-i18n="links">Snelle Links</h4>
            <ul>
                <li><a href="index.html" data-i18n="nav_home">Home</a></li>
                <li><a href="genk.html" data-i18n="nav_genk">Genk & Omgeving</a></li>
                <li><a href="contact.html" data-i18n="nav_contact">Contact & Boeken</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4 data-i18n="contact">Contact</h4>
            <ul class="contact-info">
                <li><i class="fa-solid fa-location-dot"></i> Terboekt 28, 3600 Genk</li>
                <li><i class="fa-solid fa-envelope"></i> <a href="mailto:info@hometerboekt.be">info@hometerboekt.be</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; 2026 Home Terboekt. Alle rechten voorbehouden.</p>
    </div>
</footer>
`;

// Function to load footer
function loadFooter() {
    const footerPlaceholder = document.getElementById('footer-placeholder');
    if (footerPlaceholder) {
        footerPlaceholder.innerHTML = footerHTML;
    }
}

// Load footer when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadFooter);
} else {
    loadFooter();
}
