/* =====================================================
   Module Comptabilité — JS (datatable vanilla + saisie pièces)
   ===================================================== */

/* ---------------------------------------------------------
   1) DataTable générique : recherche, tri, pagination
   Usage : <table class="datatable" data-per-page="20"> ...
   Colonnes : <th data-type="num|date|text">
   --------------------------------------------------------- */
(function () {
    'use strict';

    function parseCell(text, type) {
        var t = (text || '').trim();
        if (type === 'num') {
            var n = parseFloat(t.replace(/[\s\xA0\u00A0]/g, '').replace(',', '.').replace(/[^\d.\-]/g, ''));
            return isNaN(n) ? -Infinity : n;
        }
        if (type === 'date') {
            var m = t.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
            if (m) return m[3] + m[2] + m[1];
            return t;
        }
        return t.toLowerCase();
    }

    function initTable(table) {
        var perPage = parseInt(table.dataset.perPage || '15', 10);
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        var filtered = allRows.slice();
        var sortCol = -1;
        var sortDir = 1;
        var page = 0;
        var query = '';

        // Contrôles
        var wrap = document.createElement('div');
        wrap.className = 'dt-wrap';
        table.parentNode.insertBefore(wrap, table);
        wrap.appendChild(table);

        var controls = document.createElement('div');
        controls.className = 'dt-controls no-print';
        controls.innerHTML =
            '<input type="search" placeholder="Rechercher…" aria-label="Rechercher">' +
            '<span style="display:flex;gap:8px;align-items:center;">' +
            '<label class="dt-info">Afficher</label>' +
            '<select class="dt-size"><option>10</option><option selected>15</option><option>25</option><option>50</option><option>100</option><option value="0">Tous</option></select>' +
            '<span class="dt-info" data-info></span></span>';
        wrap.insertBefore(controls, table);

        var pager = document.createElement('div');
        pager.className = 'dt-pager no-print';
        wrap.appendChild(pager);

        var search = controls.querySelector('input[type="search"]');
        var sizeSel = controls.querySelector('.dt-size');
        var infoEl = controls.querySelector('[data-info]');

        search.addEventListener('input', function () {
            query = search.value.toLowerCase();
            applyFilter();
        });
        sizeSel.addEventListener('change', function () {
            perPage = parseInt(sizeSel.value, 10);
            page = 0;
            render();
        });

        // Tri par clic sur les en-têtes
        Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (th, idx) {
            th.classList.add('sortable');
            th.addEventListener('click', function () {
                if (sortCol === idx) { sortDir *= -1; } else { sortCol = idx; sortDir = 1; }
                Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (o) {
                    o.classList.remove('sort-asc', 'sort-desc');
                });
                th.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');
                var type = th.dataset.type || 'text';
                filtered.sort(function (a, b) {
                    var ca = parseCell(a.cells[idx] ? a.cells[idx].textContent : '', type);
                    var cb = parseCell(b.cells[idx] ? b.cells[idx].textContent : '', type);
                    return ca < cb ? -sortDir : ca > cb ? sortDir : 0;
                });
                render(true);
            });
        });

        function applyFilter() {
            filtered = allRows.filter(function (row) {
                return !query || row.textContent.toLowerCase().indexOf(query) !== -1;
            });
            page = 0;
            render(true);
        }

        function visibleRows() {
            if (!perPage || perPage < 1) return filtered;
            return filtered.slice(page * perPage, page * perPage + perPage);
        }

        function render(keepSort) {
            allRows.forEach(function (r) { r.style.display = 'none'; });
            visibleRows().forEach(function (r) { r.style.display = ''; });
            var total = filtered.length;
            infoEl.textContent = total + ' ligne' + (total > 1 ? 's' : '');
            renderPager();
        }

        function renderPager() {
            pager.innerHTML = '';
            var totalPages = perPage > 0 ? Math.max(1, Math.ceil(filtered.length / perPage)) : 1;
            if (totalPages <= 1 && filtered.length <= 10) return;

            var prev = document.createElement('button');
            prev.textContent = '‹';
            prev.disabled = page === 0;
            prev.addEventListener('click', function () { page--; render(); });
            pager.appendChild(prev);

            var pagesBox = document.createElement('span');
            pagesBox.className = 'pages';
            var maxButtons = 9;
            var start = Math.max(0, Math.min(page - Math.floor(maxButtons / 2), totalPages - maxButtons));
            var end = Math.min(totalPages, start + maxButtons);
            for (var p = start; p < end; p++) {
                (function (p) {
                    var b = document.createElement('button');
                    b.textContent = p + 1;
                    if (p === page) b.classList.add('current');
                    b.addEventListener('click', function () { page = p; render(); });
                    pagesBox.appendChild(b);
                })(p);
            }
            pager.appendChild(pagesBox);

            var next = document.createElement('button');
            next.textContent = '›';
            next.disabled = page >= totalPages - 1;
            next.addEventListener('click', function () { page++; render(); });
            pager.appendChild(next);
        }

        render();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table.datatable').forEach(initTable);
    });

    /* ---------------------------------------------------------
       2) Saisie d'une pièce : lignes dynamiques + équilibre live
       --------------------------------------------------------- */
    var form = document.getElementById('entry-form');
    if (form) initEntryForm(form);

    function initEntryForm(form) {
        var tbody = form.querySelector('#lines-tbody');
        var addBtn = form.querySelector('#add-line');
        var tpl = form.querySelector('#line-template');

        function money(v) {
            var n = parseFloat(v) || 0;
            return n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalc() {
            var debit = 0, credit = 0;
            tbody.querySelectorAll('tr.line-row').forEach(function (row) {
                var d = AccountingParse(row.querySelector('.in-debit').value);
                var c = AccountingParse(row.querySelector('.in-credit').value);
                debit += d; credit += c;
            });
            var diff = Math.round((debit - credit) * 100) / 100;
            document.getElementById('sum-debit').textContent = money(debit.toFixed(2));
            document.getElementById('sum-credit').textContent = money(credit.toFixed(2));
            document.getElementById('sum-diff').textContent = money(Math.abs(diff).toFixed(2));
            var box = document.getElementById('balance-state');
            var ok = Math.abs(diff) < 0.005 && debit > 0;
            box.textContent = ok ? '✔ Écriture équilibrée' : (debit === 0 && credit === 0 ? 'Saisissez les montants' : '✘ Déséquilibrée');
            box.className = ok ? 'balance-ok' : 'balance-bad';
            var submitBtn = form.querySelector('button[name="save_status"]');
            if (submitBtn) submitBtn.disabled = !ok;
        }

        function renumber() {
            tbody.querySelectorAll('tr.line-row').forEach(function (row, i) {
                row.querySelector('.line-no').textContent = i + 1;
            });
        }

        function wireRow(row) {
            row.querySelector('.in-debit').addEventListener('input', function () {
                if (this.value !== '') { row.querySelector('.in-credit').value = ''; }
                recalc();
            });
            row.querySelector('.in-credit').addEventListener('input', function () {
                if (this.value !== '') { row.querySelector('.in-debit').value = ''; }
                recalc();
            });
            row.querySelector('.account-filter').addEventListener('input', function () {
                filterAccountSelect(row.querySelector('.in-account'), this.value);
            });
            row.querySelector('.line-remove').addEventListener('click', function () {
                if (tbody.querySelectorAll('tr.line-row').length > 1) {
                    row.remove();
                    renumber();
                    recalc();
                }
            });
            row.querySelectorAll('.in-debit, .in-credit').forEach(function (inp) {
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
                });
            });
        }

        function filterAccountSelect(sel, q) {
            q = (q || '').toLowerCase();
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (!opt.value) return;
                opt.hidden = q !== '' &&
                    opt.textContent.toLowerCase().indexOf(q) === -1;
            });
            if (q !== '') {
                var firstVisible = Array.prototype.find.call(sel.options, function (o) { return !o.hidden && o.value; });
                if (firstVisible) { sel.value = firstVisible.value; }
            }
        }

        window.addEntryLine = function (data) {
            var node = tpl.content.cloneNode(true);
            var row = node.querySelector('tr');
            tbody.appendChild(node);
            if (data) {
                if (data.account_id) row.querySelector('.in-account').value = data.account_id;
                if (data.third_party_id) row.querySelector('.in-third').value = data.third_party_id;
                row.querySelector('.in-label').value = data.label || '';
                row.querySelector('.in-debit').value = parseFloat(data.debit || 0) > 0 ? data.debit : '';
                row.querySelector('.in-credit').value = parseFloat(data.credit || 0) > 0 ? data.credit : '';
                row.querySelector('.in-due').value = data.due_date || '';
            }
            wireRow(row);
            renumber();
            return row;
        };

        addBtn.addEventListener('click', function () {
            var row = window.addEntryLine();
            row.querySelector('.in-account').focus();
        });

        tbody.querySelectorAll('tr.line-row').forEach(wireRow);
        renumber();
        recalc();
    }

    function AccountingParse(v) {
        v = String(v || '').replace(/\s|\xA0|\u00A0/g, '').replace(',', '.');
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }
})();
