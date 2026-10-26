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

Se encuentran implementados los módulos prioritarios y las tres funcionalidades críticas restantes de la especificación:
1. **Gestión de Mesas:** Creación, edición de capacidad en asientos, cálculo dinámico del cupo total del restaurante y eliminación segura con verificación de integridad histórica y reservaciones vigentes (compatible con tablas distribuidas Citus).
2. **Gestión de Empleados:** Registro de personal con contraseñas seguras, administración de roles múltiples (Administrador, Maitre, Mesero, Cocinero), control de estado activo y asignación directa de platos autorizados a cocineros (especialidades).
3. **Historial de Clientes:** Consulta integral con desglose y métricas de reservaciones, pedidos realizados, importes acumulados y estado de cada transacción.
4. **Notificaciones y alertas:** Alertas de reservaciones próximas a comenzar (en 2 horas o menos) para el Maitre y visualización de pedidos preparados listos para entregar para el Mesero.

Las pruebas automatizadas cubren registro y ampliación de pedidos, transiciones, importes, CRUD, cruces, cupo, reenvíos, concurrencia, rutas, permisos, autenticación antigua y moderna, CSRF y escape HTML. También se comprobó el flujo completo sobre la base real dentro de una transacción con ROLLBACK, sin dejar registros de prueba.

La cabecera, pie, colores, clases de carta y estructura de la plantilla académica de la Pontificia Universidad Javeriana se mantienen íntegramente. Se añadieron controles de formulario y tablas; no se introdujo un framework ajeno.

