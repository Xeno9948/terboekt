(function () {
    var menuBtn = document.querySelector('[data-admin-menu]');
    var nav = document.getElementById('admin-nav');
    var backdrop = document.querySelector('[data-admin-backdrop]');

    function setOpen(open) {
        if (!nav) return;
        nav.classList.toggle('is-open', open);
        document.body.classList.toggle('admin-nav-open', open);
        if (menuBtn) menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (backdrop) {
            if (open) backdrop.removeAttribute('hidden');
            else backdrop.setAttribute('hidden', '');
        }
    }

    if (menuBtn && nav) {
        menuBtn.addEventListener('click', function () {
            setOpen(!nav.classList.contains('is-open'));
        });
    }
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setOpen(false);
        });
    }
    if (nav) {
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', function () {
                setOpen(false);
            });
        });
    }

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm') || 'Weet u het zeker?';
            if (!window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.getElementById(btn.getAttribute('data-copy') || '');
            if (!target) return;
            var value = target.value || target.textContent || '';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(function () {
                    btn.textContent = 'Gekopieerd';
                    setTimeout(function () { btn.textContent = 'Kopieer'; }, 2000);
                });
            } else {
                target.focus();
                target.select();
            }
        });
    });

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var buttons = form.querySelectorAll('button[type="submit"]');
            buttons.forEach(function (btn) {
                btn.setAttribute('data-busy', '1');
            });
        });
    });
})();
