// El total es una ayuda visual; PostgreSQL fija el precio definitivo al guardar.
document.addEventListener('DOMContentLoaded', function () {
    // 1. Manejo interactivo de ítems de comanda / pedido
    document.querySelectorAll('.ITEMS_PEDIDO').forEach(function (box) {
        function total() {
            let suma = 0;
            box.querySelectorAll('input[data-precio]').forEach(function (input) {
                let val = Math.max(0, Number(input.value) || 0);
                suma += val * Number(input.dataset.precio);
                let card = input.closest('.PLATO_CARD') || input.closest('.PLATO');
                if (card) {
                    if (val > 0) card.classList.add('CARD_SELECCIONADA');
                    else card.classList.remove('CARD_SELECCIONADA');
                }
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
            box.querySelectorAll('.PLATO, .PLATO_CARD').forEach(function (plato) {
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

        // Controles táctiles de incremento y decremento (+ / -)
        box.querySelectorAll('.BTN_STEP').forEach(function (btn) {
            btn.addEventListener('click', function () {
                let container = btn.closest('.STEPPER_CANTIDAD');
                if (!container) return;
                let input = container.querySelector('input[type="number"]');
                if (!input) return;
                let actual = Math.max(0, Number(input.value) || 0);
                if (btn.classList.contains('BTN_MAS')) {
                    input.value = Math.min(1000, actual + 1);
                } else if (btn.classList.contains('BTN_MENOS')) {
                    input.value = Math.max(0, actual - 1);
                }
                input.dispatchEvent(new Event('input', { bubbles: true }));
                total();
            });
        });

        box.addEventListener('input', total);
        total();
    });

    // 2. Autocompletado de credenciales de prueba en pantalla de login
    document.querySelectorAll('.BTN_DEMO').forEach(function (btn) {
        btn.addEventListener('click', function () {
            let uInput = document.getElementById('login_usuario_id');
            let pInput = document.getElementById('login_clave');
            if (uInput && pInput) {
                uInput.value = btn.dataset.id || '';
                pInput.value = btn.dataset.pass || '';
                let form = uInput.closest('form');
                if (form) {
                    let submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.classList.add('BTN_RESALTADO');
                        submitBtn.focus();
                    }
                }
            }
        });
    });

    // 3. Polling en segundo plano para refrescar alertas en el panel principal
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

