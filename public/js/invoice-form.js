/**
 * Live calculation on the EasyAdmin invoice form:
 *  - each line's Amount = Qty × Unit price
 *  - a running totals box with NHIL, GETFund, COVID-19 levy, VAT and Total
 * The server recalculates everything on save; this is only a preview.
 */
(function () {
    'use strict';

    function num(v) {
        var n = parseFloat(String(v == null ? '' : v).replace(/,/g, ''));
        return isNaN(n) ? 0 : n;
    }
    function cents(v) { return Math.round(v * 100); }
    function fmt(c) {
        return (c / 100).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function field(form, name) { return form.querySelector('[name="Invoice[' + name + ']"]'); }
    function value(form, name, fallback) {
        var el = field(form, name);
        return el ? el.value : fallback;
    }

    var lastHtml = null;

    function getForm() {
        return document.querySelector('form[name="Invoice"]');
    }

    function ensureTotalsBox(form) {
        var box = form.querySelector('.invoice-live-totals');
        if (box) { return box; }
        box = document.createElement('div');
        box.className = 'invoice-live-totals';

        var anchor = form.querySelector('.js-item-qty');
        var container = anchor ? anchor.closest('.field-collection') : null;
        if (!container) {
            var itemsWidget = form.querySelector('[data-ea-collection-field], [id="Invoice_items"]');
            container = itemsWidget ? (itemsWidget.closest('.field-collection') || itemsWidget.parentNode) : null;
        }
        if (container && container.parentNode) {
            container.parentNode.insertBefore(box, container.nextSibling);
        } else {
            form.appendChild(box);
        }
        return box;
    }

    function recalc() {
        var form = getForm();
        if (!form) { return; }

        var sub = 0;
        form.querySelectorAll('input[name$="[quantity]"]').forEach(function (qtyInput) {
            var prefix = qtyInput.name.slice(0, -'[quantity]'.length);
            var priceInput = form.querySelector('[name="' + prefix + '[unitPrice]"]');
            var amountInput = form.querySelector('[id="' + qtyInput.id.replace(/_quantity$/, '_amount') + '"]');
            var line = Math.round(cents(num(qtyInput.value)) * cents(num(priceInput ? priceInput.value : 0)) / 100);
            sub += line;
            if (amountInput) { amountInput.value = (line / 100).toFixed(2); }
        });

        var taxableEl = field(form, 'taxable');
        var taxable = taxableEl ? taxableEl.checked : true;
        var mode = value(form, 'taxMode', 'simple');
        var currency = value(form, 'currency', 'GHS') || 'GHS';
        var rates = {
            nhil: num(value(form, 'nhilRate', 2.5)),
            getfund: num(value(form, 'getfundRate', 2.5)),
            covid: num(value(form, 'covidRate', 0)),
            vat: num(value(form, 'vatRate', 15))
        };

        var nhil = 0, getfund = 0, covid = 0, vat = 0;
        if (taxable) {
            nhil = Math.round(sub * rates.nhil / 100);
            getfund = Math.round(sub * rates.getfund / 100);
            covid = Math.round(sub * rates.covid / 100);
            var base = mode === 'compound' ? sub + nhil + getfund + covid : sub;
            vat = Math.round(base * rates.vat / 100);
        }
        var total = sub + nhil + getfund + covid + vat;

        var rows = [['Subtotal', sub]];
        if (taxable) {
            rows.push(['NHIL (' + rates.nhil + '%)', nhil]);
            rows.push(['GETFund levy (' + rates.getfund + '%)', getfund]);
            if (rates.covid > 0) { rows.push(['COVID-19 levy (' + rates.covid + '%)', covid]); }
            if (mode === 'compound') { rows.push(['Total before VAT', sub + nhil + getfund + covid]); }
            rows.push(['VAT (' + rates.vat + '%)', vat]);
        }

        var html = '<table>';
        rows.forEach(function (r) { html += '<tr><td>' + r[0] + '</td><td>' + fmt(r[1]) + '</td></tr>'; });
        html += '<tr class="grand"><td>Total (' + currency.replace(/[^A-Za-z]/g, '') + ')</td><td>' + fmt(total) + '</td></tr></table>';

        if (html !== lastHtml || !form.querySelector('.invoice-live-totals')) {
            lastHtml = html;
            ensureTotalsBox(form).innerHTML = html;
        }
    }

    var scheduled = false;
    function schedule() {
        if (scheduled) { return; }
        scheduled = true;
        window.requestAnimationFrame(function () { scheduled = false; recalc(); });
    }

    document.addEventListener('input', schedule);
    document.addEventListener('change', schedule);
    document.addEventListener('ea.collection.item-added', schedule);
    document.addEventListener('ea.collection.item-removed', schedule);

    document.addEventListener('DOMContentLoaded', function () {
        var form = getForm();
        if (!form) { return; }
        recalc();
        // Catch rows added/removed by EasyAdmin's collection widget.
        new MutationObserver(schedule).observe(form, { childList: true, subtree: true });
    });
})();
