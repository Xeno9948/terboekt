(function (root) {
    function t(key) {
        return (root.TerboektApi && root.TerboektApi.t(key)) || key;
    }

    function euro(cents) {
        if (root.TerboektPricing && typeof root.TerboektPricing.formatEuro === 'function') {
            return root.TerboektPricing.formatEuro(Number(cents) || 0);
        }
        return '€ ' + (Number(cents || 0) / 100).toFixed(2);
    }

    function labelForItem(item) {
        var code = String((item && item.code) || '');
        if (code.indexOf('accommodation_') === 0 || code === 'accommodation') return t('book_quote_accommodation');
        if (code === 'cleaning') return t('book_quote_cleaning');
        if (code === 'tourist_tax') return t('book_quote_tax');
        if (code === 'sunday_evening') return t('book_quote_sunday');
        if (code.indexOf('discount') !== -1) return t('book_quote_discount');
        return (item && item.label) || code || t('book_quote_fees');
    }

    function row(label, amount, extraClass) {
        return '<div class="quote-row' + (extraClass ? ' ' + extraClass : '') + '">'
            + '<span>' + label + '</span>'
            + '<strong>' + amount + '</strong>'
            + '</div>';
    }

    function render(target, quote, state) {
        if (!target) return;
        var mode = (state && state.mode) || 'idle';
        if (mode === 'idle') {
            target.innerHTML = '<p class="quote-placeholder">' + t('book_quote_pick_dates') + '</p>';
            return;
        }
        if (mode === 'loading') {
            target.innerHTML = '<p class="quote-placeholder"><i class="fa-solid fa-spinner fa-spin"></i> ' + t('book_quote_loading') + '</p>';
            return;
        }
        if (mode === 'error') {
            var raw = String(state.message || '');
            var friendly = /package|nightly|arrangement|nachttarief|weekend|midweek/i.test(raw)
                ? t('book_quote_error')
                : (raw || t('book_quote_error'));
            target.innerHTML = '<p class="form-error show">' + friendly + '</p>';
            return;
        }
        if (!quote) {
            target.innerHTML = '<p class="quote-placeholder">' + t('book_quote_pick_dates') + '</p>';
            return;
        }

        var nights = Number(quote.nights) || 0;
        var html = '<div class="quote-head"><p class="section-kicker" data-i18n="book_quote_title">' + t('book_quote_title') + '</p>'
            + '<h3>' + nights + ' ' + t('book_nights') + (quote.package ? ' · ' + quote.package : '') + '</h3></div>';

        var items = Array.isArray(quote.line_items) ? quote.line_items : [];
        if (items.length) {
            items.forEach(function (item) {
                html += row(labelForItem(item), euro(item.amount_cents));
            });
        } else {
            html += row(t('book_quote_accommodation'), euro(quote.accommodation_cents));
            html += row(t('book_quote_cleaning'), euro(quote.cleaning_fee_cents));
            html += row(t('book_quote_tax'), euro(quote.tourist_tax_cents));
            if (quote.sunday_evening_cents) html += row(t('book_quote_sunday'), euro(quote.sunday_evening_cents));
            if (quote.extra_fees_cents) html += row(t('book_quote_fees'), euro(quote.extra_fees_cents));
            if (quote.discount_cents) html += row(t('book_quote_discount'), euro(-Math.abs(quote.discount_cents)));
        }

        html += row(t('book_quote_total'), euro(quote.total_cents), 'is-total');
        html += row(t('book_quote_deposit') + ' (' + (quote.deposit_percentage || 30) + '%)', euro(quote.deposit_cents), 'is-deposit');
        html += row(t('book_quote_remaining'), euro(quote.remaining_cents));
        html += '<p class="quote-note">' + t('book_quote_security') + ': ' + euro(quote.security_deposit_cents) + '</p>';
        target.innerHTML = html;
    }

    function summaryLines(quote) {
        if (!quote) return [];
        return [
            { label: t('book_quote_nights'), value: String(quote.nights || '') },
            { label: t('book_quote_total'), value: euro(quote.total_cents) },
            { label: t('book_quote_deposit'), value: euro(quote.deposit_cents) },
            { label: t('book_quote_remaining'), value: euro(quote.remaining_cents) }
        ];
    }

    root.TerboektQuote = {
        render: render,
        euro: euro,
        summaryLines: summaryLines
    };
})(window);
