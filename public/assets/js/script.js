function initMobileNav() {
    const toggle = document.querySelector('.mobile-toggle');
    const nav = document.querySelector('.main-nav');
    if (!toggle || !nav) return;

    const header = document.querySelector('header');
    toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('active');
        toggle.setAttribute('aria-expanded', String(open));
        header?.classList.toggle('nav-open', open);
        if (!open) header?.classList.remove('nav-open');
        const icon = toggle.querySelector('i');
        if (icon) icon.className = open ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
    });

    nav.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            nav.classList.remove('active');
            toggle.setAttribute('aria-expanded', 'false');
            const icon = toggle.querySelector('i');
            if (icon) icon.className = 'fa-solid fa-bars';
            header?.classList.remove('nav-open');
        });
    });
}

function initHeaderScroll() {
    const header = document.querySelector('header');
    if (!header) return;
    const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 40);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
}

function initScrollHint() {
    const indicator = document.querySelector('.scroll-indicator');
    const next = document.querySelector('#about');
    if (!indicator || !next) return;
    indicator.addEventListener('click', () => {
        next.scrollIntoView({ behavior: 'smooth' });
    });
}

function initPhotoSwitch() {
    document.querySelectorAll('[data-photo-switch]').forEach((root) => {
        const slides = Array.from(root.querySelectorAll('.photo-switch-stage img'));
        const dots = Array.from(root.querySelectorAll('.photo-switch-dots button'));
        const stage = root.querySelector('.photo-switch-stage');
        if (slides.length < 2) return;
        let index = 0;

        const show = (next) => {
            index = (next + slides.length) % slides.length;
            slides.forEach((img, i) => img.classList.toggle('is-current', i === index));
            dots.forEach((dot, i) => dot.classList.toggle('is-active', i === index));
        };

        root.querySelector('.photo-switch-btn.next')?.addEventListener('click', (e) => {
            e.stopPropagation();
            show(index + 1);
        });
        root.querySelector('.photo-switch-btn.prev')?.addEventListener('click', (e) => {
            e.stopPropagation();
            show(index - 1);
        });
        dots.forEach((dot, i) => dot.addEventListener('click', (e) => {
            e.stopPropagation();
            show(i);
        }));
        stage?.addEventListener('click', () => show(index + 1));
    });
}

function initLazyMap() {
    const button = document.getElementById('show-map');
    const frame = document.getElementById('map-frame');
    if (!button || !frame) return;
    button.addEventListener('click', () => {
        if (frame.querySelector('iframe')) return;
        const iframe = document.createElement('iframe');
        iframe.title = 'Home Terboekt op Google Maps';
        iframe.src = 'https://maps.google.com/maps?q=Terboekt%2028%2C%203600%20Genk%2C%20Belgium&z=15&output=embed';
        iframe.loading = 'lazy';
        iframe.referrerPolicy = 'no-referrer-when-downgrade';
        frame.appendChild(iframe);
        frame.hidden = false;
        button.hidden = true;
    });
}

function initDateEmptyState() {
    document.querySelectorAll('input[type="date"]').forEach((el) => {
        const sync = () => el.classList.toggle('is-empty', !el.value);
        sync();
        el.addEventListener('input', sync);
        el.addEventListener('change', sync);
        el.form?.addEventListener('reset', () => requestAnimationFrame(sync));
    });
}

function initBookingForm() {
    const form = document.getElementById('booking-form');
    initDateEmptyState();
    if (!form) return;
    if (window.TerboektCheckout && typeof window.TerboektCheckout.init === 'function') {
        window.TerboektCheckout.init(form);
        return;
    }
    form.addEventListener('submit', (e) => e.preventDefault());
}

let booted = false;

function boot() {
    if (booted || !document.querySelector('header')) return;
    booted = true;
    if (document.querySelector('.hero, .page-hero')) {
        document.body.classList.add('has-hero');
    }
    initMobileNav();
    initHeaderScroll();
    initScrollHint();
    initPhotoSwitch();
    initLazyMap();
    initBookingForm();
}

document.addEventListener('components:ready', boot);
document.addEventListener('DOMContentLoaded', boot);
if (document.querySelector('header')) boot();
