// Header Component
const headerHTML = `
<header>
    <div class="header-container">
        <div class="logo">
            <a href="index.html">Home Terboekt</a>
        </div>
        <nav class="main-nav">
            <ul>
                <li><a href="index.html" data-i18n="nav_home">Home</a></li>
                <li><a href="genk.html" data-i18n="nav_genk">Ontdek Genk</a></li>
                <li><a href="contact.html" data-i18n="nav_contact">Boeken & Contact</a></li>
            </ul>
        </nav>
        <div class="lang-switcher">
            <a href="#" data-lang="nl" class="active">NL</a>
            <a href="#" data-lang="en">EN</a>
            <a href="#" data-lang="fr">FR</a>
            <a href="#" data-lang="de">DE</a>
        </div>
        <div class="mobile-toggle">
            <i class="fa-solid fa-bars"></i>
        </div>
    </div>
</header>
`;

// Function to load header
function loadHeader() {
    const headerPlaceholder = document.getElementById('header-placeholder');
    if (headerPlaceholder) {
        headerPlaceholder.innerHTML = headerHTML;

        // Set active navigation link based on current page
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        const navLinks = document.querySelectorAll('.main-nav a');
        navLinks.forEach(link => {
            link.classList.remove('active');
            if (link.getAttribute('href') === currentPage) {
                link.classList.add('active');
            }
        });
    }
}

// Load header when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadHeader);
} else {
    loadHeader();
}
