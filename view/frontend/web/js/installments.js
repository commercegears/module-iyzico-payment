/**
 * CommerceGears_IyzicoPayment: fills the product page "Installment options" table (Luma and Hyvä, no dependencies).
 * Builds the table with textContent only.
 */
(function () {
    'use strict';

    function build(box, data) {
        var classes = JSON.parse(box.getAttribute('data-classes') || '{}'),
            labels = JSON.parse(box.getAttribute('data-labels') || '{}'),
            target = box.querySelector('[data-role="table"]'),
            table = document.createElement('table'),
            head = document.createElement('tr');

        function cell(tag, text, cls, extra) {
            var el = document.createElement(tag);
            el.textContent = text;
            if (cls) { el.className = cls; }
            if (extra) {
                var small = document.createElement('span');
                small.textContent = extra;
                if (classes.small) { small.className = classes.small; }
                el.appendChild(small);
            }
            return el;
        }

        table.className = classes.table || '';
        head.appendChild(cell('th', labels.card || 'Card', classes.th));
        data.counts.forEach(function (count) {
            head.appendChild(cell('th', (labels.count || '%1').replace('%1', count), classes.th));
        });
        table.appendChild(head);
        data.rows.forEach(function (row) {
            var tr = document.createElement('tr');
            tr.appendChild(cell('td', row.name, classes.td));
            data.counts.forEach(function (count) {
                var c = row.cells[count];
                tr.appendChild(c
                    ? cell('td', c.each + ' ' + data.currency, classes.td, (labels.total || 'Total') + ' ' + c.total)
                    : cell('td', '–', classes.td));
            });
            table.appendChild(tr);
        });
        target.appendChild(table);
        box.hidden = false;
    }

    function init() {
        document.querySelectorAll('.commercegears-iyzico-installments[data-url], [data-iyzico-installments][data-url]').forEach(function (box) {
            if (box.getAttribute('data-loaded')) { return; }
            box.setAttribute('data-loaded', '1');
            fetch(box.getAttribute('data-url'), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (data && data.rows && data.rows.length && data.counts && data.counts.length) {
                        build(box, data);
                    }
                })
                .catch(function () {});
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
