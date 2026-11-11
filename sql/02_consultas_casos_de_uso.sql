/*
===============================================================================
  PROYECTO RESTAURANTE - PONTIFICIA UNIVERSIDAD JAVERIANA
  Asignatura: Bases de Datos: Administración y Aplicaciones
  Docente: Ing. Ricardo Naranjo Faccini, MSc.
  
  FICHERO: 02_consultas_casos_de_uso.sql
  OBJETIVO:
    Catálogo completo de consultas SQL comentadas y optimizadas para dar
    solución y soporte a cada uno de los Casos de Uso y Reportes descritos
    en el documento de especificación:
      - CU-01: Gestión de Mesas (cupo total, capacidad y disponibilidad)
      - CU-02: Gestión de Reservaciones (Maitre: disponibilidad, alertas <=2h)
      - CU-03: Gestión del Menú (Admin: categorías, tiempos, especialidades)
      - CU-04: Registro de Pedidos (Mesero: múltiples ítems, total liquidado)
      - CU-05: Preparación de Platos (Cocinero: orden desc por tiempo, especialidad)
      - CU-06: Gestión de Entregas (Mesero: alertas de listos, entrega a mesa)
      - CU-07: Reportes y Estadísticas (Ventas, reservas y ranking de platos)
      - Funcionalidades Adicionales: Historial de Clientes y Gestión de Empleados
===============================================================================
*/

-- ============================================================================
-- CU-01: GESTIÓN DE MESAS (Administrador)
-- ============================================================================

-- 1.1. Calcular el cupo total del restaurante sumando los asientos de todas las mesas
SELECT
    count(id)       AS total_mesas,
    sum(sillas)     AS cupo_total_restaurante,
    min(sillas)     AS capacidad_minima_mesa,
    max(sillas)     AS capacidad_maxima_mesa,
    round(avg(sillas), 2) AS promedio_sillas_por_mesa
FROM public.mesas;

-- 1.2. Listado detallado de mesas con su estado actual y reservas en curso
SELECT
    m.id AS numero_mesa,
    m.sillas AS capacidad_asientos,
    CASE
        WHEN EXISTS (
            SELECT 1
            FROM public.horarios h
            JOIN public.reservaciones r ON r.id = h.reservacion_id
            WHERE h.mesa_id = m.id
              AND r.estado IN (1, 2)
              AND LOCALTIMESTAMP >= h.inicio
              AND LOCALTIMESTAMP < h.inicio + GREATEST(h.duracion, interval '2 hours')
        ) THEN 'Ocupada / Reservada'
        ELSE 'Disponible'
    END AS estado_actual
FROM public.mesas m
ORDER BY m.id;


-- ============================================================================
-- CU-02: GESTIÓN DE RESERVACIONES (Maitre)
-- ============================================================================

-- 2.1. Consultar mesas disponibles para una fecha/hora dada y número de personas
--      (Valida capacidad suficiente, sin colisión de 2 horas y sin cancelar)
-- Parámetros de ejemplo: :p_inicio = '2026-11-15 19:30:00', :p_personas = 4
SELECT
    m.id AS mesa_id,
    m.sillas AS capacidad_mesa
FROM public.mesas m
WHERE m.sillas >= 4 -- Filtro por capacidad requerida
  AND NOT EXISTS (
      SELECT 1
      FROM public.horarios h
      JOIN public.reservaciones r ON r.id = h.reservacion_id
      WHERE h.mesa_id = m.id
        AND r.estado <> 4 -- No cancelada
        AND tsrange(h.inicio, h.inicio + GREATEST(h.duracion, interval '2 hours'), '[)')
         && tsrange('2026-11-15 19:30:00'::timestamp, '2026-11-15 19:30:00'::timestamp + interval '2 hours', '[)')
  )
ORDER BY m.sillas ASC, m.id ASC;

-- 2.2. Alerta para el Maitre: Reservaciones próximas a comenzar (ventana <= 2 horas)
SELECT
    r.id AS reservacion_id,
    u.id AS cliente_id,
    u.nombre AS nombre_cliente,
    h.mesa_id,
    h.inicio AS horario_inicio,
    (h.inicio - LOCALTIMESTAMP) AS tiempo_restante,
    r.cantidad AS personas,
    CASE r.estado
        WHEN 1 THEN 'Pendiente'
        WHEN 2 THEN 'Confirmada'
    END AS estado
FROM public.reservaciones r
JOIN public.usuarios u ON u.id = r.cliente_id
JOIN public.horarios h ON h.reservacion_id = r.id
WHERE r.estado IN (1, 2)
  AND h.inicio >= LOCALTIMESTAMP
  AND h.inicio <= LOCALTIMESTAMP + interval '2 hours'
ORDER BY h.inicio ASC;


-- ============================================================================
-- CU-03: GESTIÓN DEL MENÚ (Administrador)
-- ============================================================================

-- 3.1. Consultar el menú completo organizado por categorías, precios y tiempos
SELECT
    t.nombre AS categoria,
    p.id AS plato_id,
    p.nombre AS nombre_plato,
    p.descripcion,
    p.precio,
    p.tiempo AS tiempo_preparacion,
    p.activo
FROM public.platos p
JOIN public.tipos t ON t.id = p.tipo_id
ORDER BY t.id, p.precio DESC, p.nombre;

-- 3.2. Platos y especialidades autorizadas por cocinero
SELECT
    u.id AS cocinero_id,
    u.nombre AS nombre_cocinero,
    string_agg(p.nombre, ', ' ORDER BY p.nombre) AS platos_autorizados,
    count(e.plato_id) AS total_especialidades
FROM public.usuarios u
JOIN public.actuaciones a ON a.usuario_id = u.id
JOIN public.roles r ON r.id = a.rol_id AND r.nombre = 'Cocinero'
LEFT JOIN public.especialidades e ON e.cocinero_id = u.id
LEFT JOIN public.platos p ON p.id = e.plato_id
GROUP BY u.id, u.nombre
ORDER BY total_especialidades DESC, u.nombre;


-- ============================================================================
-- CU-04: REGISTRO DE PEDIDOS (Mesero)
-- ============================================================================

-- 4.1. Consulta de detalle de pedido con ítems, subtotales y estado general
-- Parámetro de ejemplo: :p_pedido_id = 100
SELECT
    p.id AS pedido_id,
    p.mesa_id,
    cli.nombre AS cliente,
    mes.nombre AS mesero,
    p.creado AS fecha_pedido,
    o.id AS orden_id,
    pl.nombre AS plato,
    o.cantidad,
    o.precio_unitario,
    (o.cantidad * o.precio_unitario) AS subtotal,
    CASE o.estado
        WHEN 1 THEN 'Solicitada'
        WHEN 2 THEN 'En preparación'
        WHEN 4 THEN 'Preparada / Lista'
        WHEN 3 THEN 'Entregada'
    END AS estado_item
FROM public.pedidos p
JOIN public.usuarios cli ON cli.id = p.cliente_id
JOIN public.usuarios mes ON mes.id = p.mesero_id
JOIN public.ordenes o ON o.pedido_id = p.id
JOIN public.platos pl ON pl.id = o.plato_id
WHERE p.id = 100
ORDER BY o.id;

-- 4.2. Liquidación total de la cuenta del pedido
SELECT
    p.id AS pedido_id,
    p.mesa_id,
    u.nombre AS cliente,
    sum(o.cantidad) AS total_items,
    sum(o.cantidad * o.precio_unitario) AS valor_total_a_pagar,
    CASE
        WHEN count(*) = count(*) FILTER (WHERE o.estado = 3) THEN 'Entregado'
        WHEN count(*) = count(*) FILTER (WHERE o.estado IN (3, 4)) THEN 'Listo para entrega'
        ELSE 'En preparación'
    END AS estado_general_pedido
FROM public.pedidos p
JOIN public.usuarios u ON u.id = p.cliente_id
JOIN public.ordenes o ON o.pedido_id = p.id
WHERE p.id = 100
GROUP BY p.id, p.mesa_id, u.nombre;


-- ============================================================================
-- CU-05: PREPARACIÓN DE PLATOS (Cocinero)
-- ============================================================================

-- 5.1. Cola de pedidos pendientes para un cocinero:
--      Solo platos que sabe preparar (especialidades), ordenados por tiempo
--      de preparación DESCENDENTE (los más demorados de primero) y antigüedad
-- Parámetro de ejemplo: :p_cocinero_id = 4
SELECT
    o.id AS orden_id,
    o.pedido_id,
    ped.mesa_id,
    p.id AS plato_id,
    p.nombre AS plato,
    p.tiempo AS tiempo_preparacion,
    o.cantidad,
    o.solicitado AS fecha_hora_solicitud,
    CASE o.estado
        WHEN 1 THEN 'Disponible para tomar'
        WHEN 2 THEN 'En preparación por usted'
    END AS estado_cola
FROM public.ordenes o
JOIN public.platos p ON p.id = o.plato_id
JOIN public.pedidos ped ON ped.id = o.pedido_id
JOIN public.especialidades esp ON esp.plato_id = p.id AND esp.cocinero_id = 4
LEFT JOIN public.preparaciones pr ON pr.orden_id = o.id
WHERE (o.estado = 1 OR (o.estado = 2 AND pr.cocinero_id = 4))
ORDER BY p.tiempo DESC, o.solicitado ASC;


-- ============================================================================
-- CU-06: GESTIÓN DE ENTREGAS (Mesero)
-- ============================================================================

-- 6.1. Notificación al mesero: Ítems y pedidos listos (preparados) para entregar a mesa
-- Parámetro de ejemplo: :p_mesero_id = 3
SELECT
    ped.id AS pedido_id,
    ped.mesa_id,
    cli.nombre AS cliente,
    o.id AS orden_id,
    p.nombre AS plato,
    o.cantidad,
    o.preparado AS fecha_hora_preparado,
    (CURRENT_TIMESTAMP - o.preparado) AS tiempo_esperando_entrega
FROM public.pedidos ped
JOIN public.usuarios cli ON cli.id = ped.cliente_id
JOIN public.ordenes o ON o.pedido_id = ped.id
JOIN public.platos p ON p.id = o.plato_id
WHERE o.estado = 4 -- Estado 'Preparado'
  AND ped.mesero_id = 3
ORDER BY ped.mesa_id, o.preparado ASC;


-- ============================================================================
-- CU-07: REPORTES Y ESTADÍSTICAS (Administrador)
-- ============================================================================

-- 7.1. Reporte de Reservaciones agrupadas por Período (Día / Semana / Mes)
-- Parámetros: :p_agrupacion = 'month', :p_desde = '2026-08-01', :p_hasta = '2026-11-30'
SELECT
    date_trunc('month', h.inicio)::date AS periodo,
    count(DISTINCT r.id) AS total_reservas,
    count(DISTINCT r.id) FILTER (WHERE r.estado = 2) AS confirmadas,
    count(DISTINCT r.id) FILTER (WHERE r.estado = 3) AS completadas,
    count(DISTINCT r.id) FILTER (WHERE r.estado = 4) AS canceladas,
    sum(r.cantidad) AS total_comensales_esperados
FROM public.reservaciones r
JOIN public.horarios h ON h.reservacion_id = r.id
WHERE h.inicio >= '2026-08-01'::date AND h.inicio < '2026-11-30'::date + interval '1 day'
GROUP BY 1
ORDER BY 1;

-- 7.2. Reporte de Platos más solicitados (Ranking de demanda e ingresos)
SELECT
    p.id AS plato_id,
    p.nombre AS plato,
    t.nombre AS categoria,
    sum(o.cantidad) AS unidades_vendidas,
    sum(o.cantidad * COALESCE(o.precio_unitario, p.precio)) AS facturacion_total
FROM public.ordenes o
JOIN public.platos p ON p.id = o.plato_id
JOIN public.tipos t ON t.id = p.tipo_id
WHERE o.solicitado >= '2026-08-01'::date AND o.solicitado < '2026-11-30'::date + interval '1 day'
GROUP BY p.id, p.nombre, t.nombre
ORDER BY unidades_vendidas DESC, facturacion_total DESC
LIMIT 10;

-- 7.3. Reporte de Ventas Totales por Período (Día / Semana / Mes)
SELECT
    date_trunc('month', COALESCE(o.entregado, o.solicitado) AT TIME ZONE 'America/Bogota')::date AS periodo,
    count(DISTINCT o.pedido_id) AS total_pedidos_atendidos,
    sum(o.cantidad) AS total_platos_servidos,
    sum(o.cantidad * o.precio_unitario) FILTER (WHERE o.entregado IS NOT NULL AND o.precio_unitario IS NOT NULL) AS ventas_verificadas,
    sum(o.cantidad * COALESCE(o.precio_unitario, p.precio)) AS facturacion_global
FROM public.ordenes o
JOIN public.platos p ON p.id = o.plato_id
WHERE o.estado = 3 -- Entregadas / Servidas
  AND COALESCE(o.entregado, o.solicitado) >= '2026-08-01'::date
  AND COALESCE(o.entregado, o.solicitado) < '2026-11-30'::date + interval '1 day'
GROUP BY 1
ORDER BY 1;


-- ============================================================================
-- FUNCIONALIDADES ADICIONALES
-- ============================================================================

-- A. Historial Completo del Cliente (Reservas, pedidos y gasto total acumulado)
-- Parámetro de ejemplo: :p_cliente_id = 33
SELECT
    u.id AS cliente_id,
    u.nombre AS nombre_cliente,
    (SELECT count(*) FROM public.reservaciones WHERE cliente_id = u.id) AS total_reservas,
    (SELECT count(*) FROM public.pedidos WHERE cliente_id = u.id) AS total_pedidos,
    COALESCE(
        (SELECT sum(o.cantidad * COALESCE(o.precio_unitario, pl.precio))
         FROM public.pedidos pe
         JOIN public.ordenes o ON o.pedido_id = pe.id
         JOIN public.platos pl ON pl.id = o.plato_id
         WHERE pe.cliente_id = u.id), 0
    ) AS gasto_acumulado_historico
FROM public.usuarios u
WHERE u.id = 33;

-- B. Gestión de Empleados y Roles Activos
SELECT
    u.id AS empleado_id,
    u.nombre,
    u.activo,
    string_agg(r.nombre, ', ' ORDER BY r.nombre) AS roles_asignados,
    u.fecha_clave AS ultima_actualizacion_credencial
FROM public.usuarios u
JOIN public.actuaciones a ON a.usuario_id = u.id
JOIN public.roles r ON r.id = a.rol_id
GROUP BY u.id, u.nombre, u.activo, u.fecha_clave
ORDER BY u.id;

