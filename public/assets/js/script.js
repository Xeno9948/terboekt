function initMobileNav() {
    const toggle = document.querySelector('.mobile-toggle');
    const nav = document.querySelector('.main-nav');
    if (!toggle || !nav) return;

    toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('active');
        toggle.setAttribute('aria-expanded', String(open));
        const icon = toggle.querySelector('i');
        if (icon) icon.className = open ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
    });

    nav.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            nav.classList.remove('active');
            toggle.setAttribute('aria-expanded', 'false');
            const icon = toggle.querySelector('i');
            if (icon) icon.className = 'fa-solid fa-bars';
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

function initBookingForm() {
    const form = document.getElementById('booking-form');
    if (!form) return;

    const error = form.querySelector('.form-error');
    const success = form.querySelector('.form-success');
    const today = new Date().toISOString().split('T')[0];
    const checkin = form.querySelector('#checkin');
    const checkout = form.querySelector('#checkout');
    if (checkin) checkin.min = today;
    if (checkout) checkout.min = today;

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        error?.classList.remove('show');
        success?.classList.remove('show');

        if (!form.checkValidity()) {
            form.reportValidity();
            error?.classList.add('show');
            error?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        const data = new FormData(form);
        const name = data.get('name');
        const email = data.get('email');
        const phone = data.get('phone') || '—';
        const checkin = data.get('checkin');
        const checkout = data.get('checkout');
        const guests = data.get('guests');
        const message = data.get('message') || '—';

        if (checkin && checkout && checkout <= checkin) {
            error?.classList.add('show');
            error?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }

        const body = [
            `Naam: ${name}`,
            `E-mail: ${email}`,
            `Telefoon: ${phone}`,
            `Aankomst: ${checkin}`,
            `Vertrek: ${checkout}`,
            `Personen: ${guests}`,
            '',
            message
        ].join('\n');

        const mailto = `mailto:info@hometerboekt.be?subject=${encodeURIComponent('Reservatieaanvraag Home Terboekt')}&body=${encodeURIComponent(body)}`;
        window.location.href = mailto;
        success?.classList.add('show');
        form.reset();
    });
}

let booted = false;

function boot() {
    if (booted || !document.querySelector('header')) return;
    booted = true;
    initMobileNav();
    initHeaderScroll();
    initScrollHint();
    initBookingForm();
}

document.addEventListener('components:ready', boot);
document.addEventListener('DOMContentLoaded', boot);
if (document.querySelector('header')) boot();
