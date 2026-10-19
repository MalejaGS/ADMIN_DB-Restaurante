# Auditoría y correspondencia con los requisitos

Auditoría del 19 de octubre de 2026. Fuentes: PDF de requisitos, código original, `backup.sql`, semilla `restaurante_urbano.sql` y esquema de la base local `restaurante`.

## Estado encontrado

| Área | Defecto original | Resolución |
| --- | --- | --- |
| Conexión | Usuario `estudiante` inexistente | Configuración libpq por entorno; propietario local `admin_db` verificado |
| Pedidos | Solo imprime `$_REQUEST` | Registro transaccional, múltiples ítems, precio original, mesa, cliente, reserva y mesero |
| Mesas | LEFT JOIN filtrado por WHERE excluye mesas sin reserva y repite otras | Listado independiente de mesas y consulta separada de reservas vigentes |
| Reservas | No existe interfaz ni lógica; duración restringida a menos de dos horas | Crear, modificar y cancelar; ocupación de dos horas, bloqueos, capacidad y cupo |
| Carta | HTML desbalanceado; datos sin escapar | Agrupación correcta y salida escapada, manteniendo clases y aspecto |
| Actualizar menú | No implementado | Categorías, platos, disponibilidad, autorizaciones y bajas seguras |
| Cocina y entregas | No implementado; falta estado preparado | Toma exclusiva, especialidad y propietario comprobados; entrega de ítems preparados |
| Reportes | No implementado; no hay precios históricos ni fecha de entrega | Consultas por período y separación de importes comprobados y estimados |
| Seguridad | No hay sesión, permisos, CSRF ni control de errores | Sesiones, permisos por operación, consultas parametrizadas y formularios protegidos |

## Diferencias de la base real

La base contiene Citus (tabla `mesas` distribuida), reservas particionadas y una copia `reservaciones_old`; no corresponde exactamente al dump vacío. Se conservaron esa distribución, las particiones y las tablas antiguas.

Antes de migrar se guardó `/home/estudiante/restaurante-respaldo-20261019.dump`. Las migraciones se probaron sobre el DDL vacío y sobre una copia de los datos y particiones reales; luego se aplicaron a la base local.

La auditoría detectó:

- 27.779 reservas y 18.762 horarios: 9.017 reservas no tienen horario. Se conservan y se contabilizan como datos sin período asignable.
- 7.804 pares de horarios históricos se cruzan al considerar una ocupación de dos horas. La vista `restaurante_conflictos_reservas` los expone sin modificar los horarios originales; la pantalla de reservas muestra hasta 50 conflictos futuros.
- 15.035 pedidos históricos carecen de mesa, vínculo con reserva y fecha propia. No se reconstruyen asociaciones por coincidencias de cliente.
- 72.329 ítems carecen de precio original y fecha de entrega. Su estimación usa el precio actual y la fecha de solicitud, siempre en columnas separadas.
- La secuencia de reservas marcaba 18.762 aunque existían IDs hasta 45.000. Se adelantó conservando los IDs y se agregó una partición predeterminada para los nuevos registros.

Los nuevos pedidos conservan precio y fecha, y las nuevas entregas su instante real. Por eso sus ventas son verificables. Los datos históricos no se presentan como ventas exactas.

## Cobertura y comprobación

Los cuatro módulos prioritarios están implementados. Se incluye el acceso de empleados y el flujo mínimo de cocina necesario para preparar y entregar los pedidos. Los permisos se acumulan cuando un empleado tiene varios roles.

Las pruebas automatizadas cubren registro y ampliación de pedidos, transiciones, importes, CRUD, cruces, cupo, reenvíos, concurrencia, rutas, permisos, autenticación antigua y moderna, CSRF y escape HTML. También se comprobó el flujo completo sobre la base real dentro de una transacción con ROLLBACK, sin dejar registros de prueba.

La primera implementación conservó la plantilla. A petición posterior del usuario se renovó la UX: navegación permanente, colores y tipografía nuevos, diseño adaptable a celular, buscador de clientes dentro del formulario, catálogo filtrable y resumen del pedido. No se introdujo un framework ni se alteraron las reglas de negocio.

Quedan para la segunda etapa acordada: administración completa de mesas y empleados, historial independiente de clientes y notificaciones periódicas. El menú principal ya muestra ítems preparados del mesero conectado cuando se consulta.

La mejora de UX se verificó en Chromium con capturas de escritorio y móvil: login, buscador, filtros, cantidades, errores sin pérdida de datos, envío de pedidos y vistas de 390 y 320 píxeles sin desbordamiento horizontal. Las capturas de prueba utilizan datos ficticios.
