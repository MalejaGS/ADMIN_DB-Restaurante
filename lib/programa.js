// El total es una ayuda visual; PostgreSQL fija el precio definitivo al guardar.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ITEMS_PEDIDO').forEach(function (box) {
        function total() {
            let suma = 0;
            box.querySelectorAll('input[data-precio]').forEach(function (input) {
                suma += Math.max(0, Number(input.value) || 0) * Number(input.dataset.precio);
            });
            box.querySelector('.TOTAL_PEDIDO').textContent = new Intl.NumberFormat('es-CO', {style: 'currency', currency: 'COP'}).format(suma);
        }
        box.addEventListener('input', total);
        total();
    });
});
