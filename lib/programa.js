// El total es una ayuda visual; PostgreSQL fija el precio definitivo al guardar.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ITEMS_PEDIDO').forEach(function (box) {
        function total() {
            let suma = 0;
            box.querySelectorAll('input[data-precio]').forEach(function (input) {
                suma += Math.max(0, Number(input.value) || 0) * Number(input.dataset.precio);
            });
            let totalEl = box.querySelector('.TOTAL_PEDIDO');
            if (totalEl) {
                totalEl.textContent = new Intl.NumberFormat('es-CO', {style: 'currency', currency: 'COP'}).format(suma);
            }
        }

        let searchInput = box.querySelector('.BUSCADOR_PLATOS');
        let pills = box.querySelectorAll('.PILLS_CATEGORIAS button');
        let currentCat = '';

        function filtrar() {
            let q = (searchInput ? searchInput.value : '').trim().toLowerCase();
            box.querySelectorAll('.PLATO').forEach(function (plato) {
                let nombre = plato.dataset.nombre || '';
                let tipo = plato.dataset.tipo || '';
                let matchCat = !currentCat || tipo === currentCat;
                let matchText = !q || nombre.indexOf(q) !== -1;
                plato.style.display = (matchCat && matchText) ? '' : 'none';
            });
        }

        if (searchInput) {
            searchInput.addEventListener('input', filtrar);
        }

        pills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                pills.forEach(function (p) { p.classList.remove('PILL_ACTIVA'); });
                pill.classList.add('PILL_ACTIVA');
                currentCat = pill.dataset.categoria || '';
                filtrar();
            });
        });

        box.addEventListener('input', total);
        total();
    });

    // Polling en segundo plano para refrescar alertas en el panel principal
    if (document.querySelector('.SESION') && (!window.location.search || window.location.search === '?opcion=')) {
        setInterval(function () {
            fetch(window.location.href)
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    let parser = new DOMParser();
                    let doc = parser.parseFromString(html, 'text/html');
                    let nuevo = doc.querySelector('#contenido');
                    let actual = document.querySelector('#contenido');
                    if (nuevo && actual && nuevo.innerHTML !== actual.innerHTML) {
                        actual.innerHTML = nuevo.innerHTML;
                    }
                })
                .catch(function () {});
        }, 30000);
    }
});

