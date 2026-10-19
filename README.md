# Restaurante: instalación y uso

Aplicación PHP procedural sobre la plantilla entregada. Requiere PHP 8 con `pgsql`, `mbstring` y sesiones; PostgreSQL 18; y JavaScript para calcular el total visual. El servidor valida nuevamente precios y cantidades.

## Base de datos

En este entorno la base local `restaurante` ya fue migrada. El usuario de conexión predeterminado es `admin_db`, verificado contra PostgreSQL; también puede configurarse por entorno sin editar archivos:

```bash
export PGHOST=localhost PGPORT=5432 PGDATABASE=restaurante PGUSER=admin_db
# Si PostgreSQL exige contraseña, use PGPASSWORD o un archivo ~/.pgpass protegido.
php -S 127.0.0.1:8080 -t /home/estudiante/restaurante
```

Para otra instalación, restaure primero el DDL de `../backup.sql` en una base vacía. El dump usa el propietario `admin_db`. `../restaurante_urbano.sql` es una semilla opcional para una base sin datos: no es el DDL y no debe ejecutarse sobre una base poblada.

Guarde un respaldo y aplique, en orden:

```bash
psql -X -v ON_ERROR_STOP=1 -f migrations/001_funcionalidades.sql
psql -X -v ON_ERROR_STOP=1 -f migrations/002_secuencias.sql
```

Ambas migraciones admiten reejecución. La primera informa conflictos históricos sin modificarlos. La segunda adelanta secuencias desfasadas sin reducirlas. No hay migración inversa automática porque eliminar columnas haría perder nuevos datos; el respaldo permite recuperar el estado previo en otra base.

## Acceso y recorrido

Se inicia sesión con ID de usuario y contraseña, usando los usuarios existentes. La semilla suministrada asigna `Restaurante#42` como contraseña de prueba; al primer acceso se sustituye el SHA-256 por `password_hash`. No se modifica la clave de usuarios que no inicien sesión.

Para consultar IDs de empleados:

```sql
SELECT u.id, u.nombre, string_agg(r.nombre, ', ' ORDER BY r.nombre) AS roles
FROM usuarios u
JOIN actuaciones a ON a.usuario_id=u.id
JOIN roles r ON r.id=a.rol_id
WHERE r.nombre IN ('Administrador','Maitre','Mesero','Cocinero')
GROUP BY u.id,u.nombre ORDER BY u.id;
```

- **Mesero:** Realizar Pedido → mesa → cliente o reserva vigente → cantidades → Registrar pedido. En el detalle puede agregar ítems mientras no esté totalmente entregado. El total visual refleja los ítems nuevos.
- **Maitre:** Reservaciones → cliente, mesa, inicio y personas. Consultar disponibilidad permite elegir fecha, hora y personas antes de seleccionar mesa. Se guardan dos horas y se rechazan cruces. La lista por fecha permite modificar y cancelar. Clientes permite buscar IDs y registrar clientes nuevos.
- **Cocinero:** Preparación → Tomar → Marcar preparado. Solo aparecen platos autorizados, ordenados por tiempo de preparación descendente y antigüedad. El mesero entrega cada ítem preparado desde el detalle del pedido.
- **Administrador:** Actualizar Menú permite agregar o editar categorías, platos y especialidades. Los platos activos necesitan cocinero autorizado. Los platos con historial se desactivan al pedir su eliminación. Reportes permite elegir fechas y agrupación diaria, semanal o mensual.

Estados internos de ítems: `1` en preparación sin asignar, `2` en preparación asignada, `4` preparado, `3` entregado. Se conservó el significado histórico del valor `3`. El estado general del pedido se calcula sobre todos sus ítems.

Las reservas canceladas no ocupan cupo. Se admiten intervalos contiguos y se valida que un cliente no tenga reservas simultáneas. Una reserva con pedidos ya registrados no puede modificarse ni cancelarse.

Los reportes de ventas usan fecha de entrega; los platos más solicitados usan fecha de solicitud. La semana empieza el lunes. Los extremos del filtro incluyen ambos días en `America/Bogota`. Los importes históricos estimados se muestran separados y las reservas sin horario se identifican como datos sin fecha.

## Pruebas reproducibles

Utilice exclusivamente una base temporal llamada `restaurante_test`, con el DDL y ambas migraciones. La prueba de integración rechaza otros nombres y requiere confirmar el reinicio de sus fixtures con `RESTAURANTE_TEST_RESET=1`. **Borra los datos de esa base temporal** antes de probar; nunca apunte las pruebas a la base real.

```bash
export PGHOST=/tmp PGPORT=55432 PGUSER=restaurante_test PGDATABASE=restaurante_test
RESTAURANTE_TEST_RESET=1 php tests/integration.php
php -S 127.0.0.1:58080 -t .
# En otra terminal:
python3 tests/integration_http.py
```

La prueba HTTP presupone las fixtures anteriores y el servidor temporal. Usa solo direcciones locales y admite `RESTAURANTE_TEST_URL` para cambiar el puerto.

Para revisar sintaxis:

```bash
find . -name '*.php' -exec php -l {} \;
```

Consulte `AUDITORIA.md` para los defectos originales, las particularidades de la base real y los límites de los datos históricos.

## Mejora de UX

La interfaz utiliza navegación permanente por rol, tarjetas de resumen y diseño adaptable a celular. Los pedidos incluyen búsqueda de clientes por nombre o ID, filtros de platos, controles de cantidad y resumen de selección. El ID manual sigue disponible si no hay JavaScript. Los errores se muestran junto al formulario y conservan sus datos. Los reportes incluyen indicadores y barras de ventas verificadas y estimaciones separadas.

Los assets incluyen una versión en su URL para actualizar la caché del navegador. Para publicar esta versión en Apache ejecute `sudo bash /home/estudiante/restaurante/publicar-apache.sh`; el script incluye el nuevo módulo `lib/ui.php` y guarda un respaldo antes de copiar. No requiere nuevas migraciones.

La prueba opcional `tests/ux_browser.py` usa Playwright/Chromium sobre las fixtures y el servidor temporal del puerto 58080. Comprueba búsqueda de clientes, selección y filtros de platos, conservación del formulario al fallar, envío y ausencia de desbordamiento horizontal en móvil. Sus capturas se guardan en `/tmp/restaurante-ux-capturas`.
