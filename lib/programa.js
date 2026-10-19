'use strict';
document.addEventListener('DOMContentLoaded', function () {
    const normalizar = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const moneda = value => new Intl.NumberFormat('es-CO', {style: 'currency', currency: 'COP'}).format(value);
    document.querySelectorAll('.VER_CLAVE').forEach(button => {
        button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input');
            const mostrar = input.type === 'password';
            input.type = mostrar ? 'text' : 'password';
            button.textContent = mostrar ? 'Ocultar' : 'Mostrar';
            button.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });
    document.querySelectorAll('.ITEMS_PEDIDO').forEach(box => {
        const inputs = Array.from(box.querySelectorAll('input[data-precio]'));
        const lista = box.querySelector('.RESUMEN_LISTA');
        function total() {
            let suma = 0, unidades = 0;
            lista.replaceChildren();
            inputs.forEach(input => {
                const cantidad = Math.max(0, Number(input.value) || 0);
                const plato = input.closest('.PLATO');
                plato.classList.toggle('ELEGIDO', cantidad > 0);
                unidades += cantidad;
                suma += cantidad * Number(input.dataset.precio);
                if (cantidad > 0) {
                    const li = document.createElement('li');
                    li.textContent = cantidad + ' × ' + plato.dataset.nombre;
                    lista.append(li);
                }
            });
            box.querySelector('.TOTAL_PEDIDO').textContent = moneda(suma);
            box.querySelector('.UNIDADES_PEDIDO').textContent = unidades + (unidades === 1 ? ' unidad seleccionada' : ' unidades seleccionadas');
        }
        box.addEventListener('click', event => {
            const button = event.target.closest('button[data-delta]');
            if (!button) return;
            const input = button.parentElement.querySelector('input');
            input.value = Math.min(1000, Math.max(0, (Number(input.value) || 0) + Number(button.dataset.delta)));
            total();
        });
        box.addEventListener('input', total);
        function filtrar() {
            const q = normalizar(box.querySelector('.BUSCAR_PLATO').value.trim());
            const tipo = box.querySelector('.FILTRAR_TIPO').value;
            let visibles = 0;
            box.querySelectorAll('.PLATO').forEach(plato => {
                plato.hidden = !normalizar(plato.dataset.nombre).includes(q) || (tipo !== '' && plato.dataset.tipo !== tipo);
                if (!plato.hidden) visibles++;
            });
            box.querySelector('.SIN_PLATOS').hidden = visibles !== 0;
        }
        box.querySelector('.BUSCAR_PLATO').addEventListener('input', filtrar);
        box.querySelector('.FILTRAR_TIPO').addEventListener('change', filtrar);
        total();
    });
    document.querySelectorAll('.CLIENTE_SELECTOR').forEach(box => {
        const input = box.querySelector('.CLIENTE_BUSQUEDA');
        const id = box.querySelector('[name=cliente_id]');
        const results = box.querySelector('.CLIENTE_RESULTADOS');
        const seleccionado = box.querySelector('.CLIENTE_SELECCIONADO');
        const manual = box.querySelector('details');
        let timer, abortar, secuencia = 0;
        // Sin JS el ID es obligatorio y visible; con JS la validación apunta al buscador.
        id.required = false;
        input.required = true;
        manual.open = false;
        input.setCustomValidity(id.value ? '' : 'Busca y selecciona un cliente o ingresa su ID manualmente.');
        id.addEventListener('input', () => {
            clearTimeout(timer);
            ++secuencia;
            if (abortar) abortar.abort();
            results.replaceChildren();
            input.value = id.value ? '#' + id.value : '';
            input.setCustomValidity(id.value ? '' : 'Selecciona un cliente.');
            seleccionado.textContent = id.value ? 'Cliente #' + id.value + ' · Se verificará al guardar.' : 'Selecciona un cliente.';
        });
        input.addEventListener('input', () => {
            clearTimeout(timer);
            if (abortar) abortar.abort();
            const actual = ++secuencia;
            id.value = '';
            input.setCustomValidity('Selecciona un cliente de los resultados.');
            results.replaceChildren();
            const q = input.value.trim();
            if (q.length < 2 && !/^\d+$/.test(q)) {
                seleccionado.textContent = 'Escribe al menos dos letras o el ID del cliente.';
                return;
            }
            seleccionado.textContent = 'Buscando clientes…';
            timer = setTimeout(async () => {
                abortar = new AbortController();
                try {
                    const endpoint = new URL('index.php', window.location.href);
                    endpoint.search = new URLSearchParams({opcion: 'buscar_clientes', q});
                    const response = await fetch(endpoint, {signal: abortar.signal, headers: {'Accept': 'application/json'}});
                    if (!response.ok) throw new Error('consulta');
                    const clientes = await response.json();
                    if (actual !== secuencia) return;
                    results.replaceChildren();
                    seleccionado.textContent = clientes.length ? 'Selecciona el cliente correcto.' : 'No encontramos ese cliente. Puedes registrarlo en Clientes.';
                    clientes.forEach(cliente => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = cliente.nombre + ' · #' + cliente.id;
                        button.addEventListener('click', () => {
                            id.value = cliente.id;
                            input.value = cliente.nombre;
                            input.setCustomValidity('');
                            seleccionado.textContent = 'Seleccionado: ' + cliente.nombre + ' · #' + cliente.id;
                            results.replaceChildren();
                            input.focus();
                        });
                        results.append(button);
                    });
                } catch (error) {
                    if (error.name === 'AbortError' || actual !== secuencia) return;
                    seleccionado.textContent = 'No se pudo buscar. Reintenta o ingresa el ID manualmente.';
                    manual.open = true;
                }
            }, 260);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); results.querySelector('button')?.focus(); }
            if (event.key === 'Escape') results.replaceChildren();
        });
        results.addEventListener('keydown', event => {
            const botones = Array.from(results.querySelectorAll('button'));
            const index = botones.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') { event.preventDefault(); botones[(index + 1) % botones.length]?.focus(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); if (index > 0) botones[index - 1].focus(); else input.focus(); }
            if (event.key === 'Escape') { results.replaceChildren(); input.focus(); }
        });
    });
    // Filtra solo la tabla que acompaña al buscador, sin alterar los datos guardados.
    document.querySelectorAll('.BUSCAR_TABLA').forEach(input => {
        const tabla = input.closest('.FILTRO_TABLA').nextElementSibling;
        if (!tabla || !tabla.classList.contains('TABLA')) return;
        input.addEventListener('input', () => {
            const q = normalizar(input.value);
            let n = 0;
            tabla.querySelectorAll('tbody tr').forEach(row => {
                row.hidden = !normalizar(row.textContent).includes(q);
                if (!row.hidden) n++;
            });
            input.parentElement.querySelector('span').textContent = n + ' resultados';
        });
    });
    // Errores junto al formulario: conserva selección, cantidades y datos escritos.
    document.querySelectorAll('form[method=post]').forEach(form => {
        form.addEventListener('input', () => form.querySelector('.ERROR_FORMULARIO')?.remove());
        form.addEventListener('submit', async event => {
            if (!window.fetch || !window.FormData) return;
            event.preventDefault();
            if (form.dataset.enviando) return;
            const button = form.querySelector('button[type=submit]');
            const original = button.textContent;
            form.querySelector('.ERROR_FORMULARIO')?.remove();
            form.dataset.enviando = 'true';
            button.disabled = true;
            button.textContent = 'Guardando…';
            try {
                const response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin'});
                if (response.redirected && response.ok) {
                    window.location.assign(response.url);
                    return;
                }
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                const alert = document.createElement('p');
                alert.className = 'ERROR_FORMULARIO';
                alert.setAttribute('role', 'alert');
                alert.tabIndex = -1;
                alert.textContent = doc.querySelector('[role=alert]')?.textContent || 'No se pudo guardar. Revisa los datos e inténtalo otra vez.';
                form.prepend(alert);
                alert.focus();
            } catch (error) {
                const alert = document.createElement('p');
                alert.className = 'ERROR_FORMULARIO';
                alert.setAttribute('role', 'alert');
                alert.tabIndex = -1;
                alert.textContent = 'No pudimos confirmar el envío. Revisa tu conexión y reintenta; el mismo formulario no duplica la operación.';
                form.prepend(alert);
                alert.focus();
            } finally {
                delete form.dataset.enviando;
                button.disabled = false;
                button.textContent = original;
            }
        });
    });
});
