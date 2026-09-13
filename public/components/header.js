const headerHTML = `
<header>
    <div class="header-container">
        <div class="logo">
            <a href="index.html" data-site="propertyName">Home Terboekt</a>
        </div>
        <nav class="main-nav" id="main-nav" aria-label="Hoofdnavigatie">
            <ul>
                <li><a href="index.html" data-i18n="nav_home">Home</a></li>
                <li><a href="genk.html" data-i18n="nav_genk">Bezoek Genk</a></li>
                <li><a href="contact.html" data-i18n="nav_contact">Boeken</a></li>
            </ul>
        </nav>
        <div class="lang-switcher" role="navigation" aria-label="Language">
            <a href="#" data-lang="nl" class="active">NL</a>
            <a href="#" data-lang="en">EN</a>
            <a href="#" data-lang="fr">FR</a>
            <a href="#" data-lang="de">DE</a>
        </div>
        <a href="contact.html" class="btn-nav" data-i18n="cta_book">Reserveer nu</a>
        <button class="mobile-toggle" type="button" aria-expanded="false" aria-controls="main-nav" aria-label="Menu">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
</header>
`;

function loadHeader() {
    const headerPlaceholder = document.getElementById('header-placeholder');
    if (!headerPlaceholder) return;
    headerPlaceholder.innerHTML = headerHTML;

    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    document.querySelectorAll('.main-nav a').forEach((link) => {
        const href = link.getAttribute('href');
        const isActive = href === currentPage || (currentPage === '' && href === 'index.html');
        link.classList.toggle('active', isActive);
        if (isActive) link.setAttribute('aria-current', 'page');
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadHeader);
} else {
    loadHeader();
}
