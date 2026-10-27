// El total es una ayuda visual; PostgreSQL fija el precio definitivo al guardar.
document.addEventListener('DOMContentLoaded', function () {
    // 1. Manejo interactivo de ítems de comanda / pedido
    document.querySelectorAll('.ITEMS_PEDIDO').forEach(function (box) {
        let resumenLista = box.querySelector('.RESUMEN_LISTA');
        let totalEl = box.querySelector('.TOTAL_PEDIDO');
        let submitBtn = box.querySelector('.BTN_COMANDA');

        function total() {
            let suma = 0;
            let itemsHtml = [];
            box.querySelectorAll('input[data-precio]').forEach(function (input) {
                let val = Math.max(0, Number(input.value) || 0);
                let precio = Number(input.dataset.precio) || 0;
                let fila = input.closest('.PLATO_FILA') || input.closest('.PLATO_CARD') || input.closest('.PLATO');
                if (fila) {
                    if (val > 0) fila.classList.add('CARD_SELECCIONADA');
                    else fila.classList.remove('CARD_SELECCIONADA');
                }
                if (val > 0) {
                    let sub = val * precio;
                    suma += sub;
                    let nombreEl = fila ? (fila.querySelector('.PLATO_NOMBRE') || fila.querySelector('strong')) : null;
                    let nombre = nombreEl ? nombreEl.textContent.trim() : (fila ? (fila.dataset.nombre || 'Plato') : 'Plato');
                    let subFmt = new Intl.NumberFormat('es-CO', {style: 'currency', currency: 'COP', maximumFractionDigits: 0}).format(sub);
                    itemsHtml.push('<li><span><strong>' + val + '×</strong> ' + nombre + '</span><span>' + subFmt + '</span></li>');
                }
            });

            if (totalEl) {
                totalEl.textContent = new Intl.NumberFormat('es-CO', {style: 'currency', currency: 'COP', maximumFractionDigits: 0}).format(suma);
            }

            if (resumenLista) {
                if (itemsHtml.length === 0) {
                    resumenLista.innerHTML = '<li class="RESUMEN_VACIO">Aún no ha agregado platos.</li>';
                } else {
                    resumenLista.innerHTML = itemsHtml.join('');
                }
            }

            if (submitBtn) {
                if (suma > 0) submitBtn.classList.add('BTN_RESALTADO');
                else submitBtn.classList.remove('BTN_RESALTADO');
            }
        }

        let searchInput = box.querySelector('.BUSCADOR_PLATOS');
        let pills = box.querySelectorAll('.PILLS_CATEGORIAS button');
        let currentCat = '';

        function filtrar() {
            let q = (searchInput ? searchInput.value : '').trim().toLowerCase();
            // Filtrado por grupos de carta
            box.querySelectorAll('.CARTA_GRUPO').forEach(function (grupo) {
                let tipo = grupo.dataset.tipo || '';
                let matchCat = !currentCat || tipo === currentCat;
                let visibles = 0;
                grupo.querySelectorAll('.PLATO_FILA').forEach(function (fila) {
                    let nombre = (fila.dataset.nombre || fila.textContent || '').toLowerCase();
                    let matchText = !q || nombre.indexOf(q) !== -1;
                    if (matchCat && matchText) {
                        fila.style.display = '';
                        visibles++;
                    } else {
                        fila.style.display = 'none';
                    }
                });
                grupo.style.display = (matchCat && visibles > 0) ? '' : 'none';
            });

            // Retrocompatibilidad con formato de grilla sin grupos
            box.querySelectorAll('.PLATO:not(.PLATO_FILA), .PLATO_CARD').forEach(function (plato) {
                let nombre = (plato.dataset.nombre || '').toLowerCase();
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
                let container = btn.closest('.STEPPER') || btn.closest('.STEPPER_CANTIDAD');
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
    if ((document.querySelector('.TOPUSER') || document.querySelector('.SESION')) && (!window.location.search || window.location.search === '?opcion=')) {
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

