/*
===============================================================================
  PROYECTO RESTAURANTE - PONTIFICIA UNIVERSIDAD JAVERIANA
  Asignatura: Bases de Datos: Administración y Aplicaciones
  Docente: Ing. Ricardo Naranjo Faccini, MSc.
  
  FICHERO: 01_integridad_y_triggers.sql
  OBJETIVO:
    Implementar y garantizar la integridad de datos, restricciones CHECK,
    claves foráneas y disparadores (triggers) de lógica de negocio conforme a
    los requerimientos del proyecto:
      1. No solapamiento de reservaciones en la misma mesa (bloque mínimo 2h).
      2. Validación estricta de cupo total del restaurante.
      3. Verificación de rol 'Mesero' en la creación de pedidos.
      4. Verificación de rol 'Cocinero' y especialidad autorizada en preparaciones.
      5. Snapshot de precio unitario y timestamp de solicitud en órdenes.
===============================================================================
*/

\set ON_ERROR_STOP on
BEGIN;

SET LOCAL TIME ZONE 'America/Bogota';

-- ----------------------------------------------------------------------------
-- 1. RESTRICCIONES CHECK COMPLEMENTARIAS
-- ----------------------------------------------------------------------------

-- Asegurar estados válidos en reservaciones: 1=pendiente, 2=confirmada, 3=completada, 4=cancelada
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_reservaciones_estado_valido') THEN
        ALTER TABLE public.reservaciones
            ADD CONSTRAINT ck_reservaciones_estado_valido
            CHECK (estado IN (1, 2, 3, 4));
    END IF;
    
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_reservaciones_cantidad_positiva') THEN
        ALTER TABLE public.reservaciones
            ADD CONSTRAINT ck_reservaciones_cantidad_positiva
            CHECK (cantidad > 0);
    END IF;
END $$;

-- Duración de reservaciones: máximo 2 horas y positiva
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_horarios_duracion') THEN
        ALTER TABLE public.horarios DROP CONSTRAINT ck_horarios_duracion;
    END IF;
    ALTER TABLE public.horarios
        ADD CONSTRAINT ck_horarios_duracion
        CHECK (duracion > interval '0' AND duracion <= interval '2 hours');
END $$;

-- Estados válidos en órdenes: 1=solicitada, 2=en preparación tomada, 4=preparada, 3=entregada
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_ordenes_estado') THEN
        ALTER TABLE public.ordenes DROP CONSTRAINT ck_ordenes_estado;
    END IF;
    ALTER TABLE public.ordenes
        ADD CONSTRAINT ck_ordenes_estado
        CHECK (estado IN (1, 2, 3, 4));
        
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_ordenes_cantidad_positiva') THEN
        ALTER TABLE public.ordenes
            ADD CONSTRAINT ck_ordenes_cantidad_positiva
            CHECK (cantidad > 0);
    END IF;
END $$;

-- Precios no negativos
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_platos_precio_no_negativo') THEN
        ALTER TABLE public.platos
            ADD CONSTRAINT ck_platos_precio_no_negativo
            CHECK (precio >= 0);
    END IF;
END $$;

-- Capacidad de mesas positiva
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_mesas_sillas_positivas') THEN
        ALTER TABLE public.mesas
            ADD CONSTRAINT ck_mesas_sillas_positivas
            CHECK (sillas > 0 AND sillas <= 100);
    END IF;
END $$;


-- ----------------------------------------------------------------------------
-- 2. DISPARADOR: VALIDACIÓN DE NO COLISIÓN DE RESERVACIONES EN LA MISMA MESA
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_trg_validar_cruce_reserva()
RETURNS TRIGGER AS $$
DECLARE
    v_capacidad_mesa integer;
    v_personas_reserva integer;
    v_estado_reserva integer;
    v_conflicto_id integer;
    v_duracion interval;
BEGIN
    -- Obtener datos de la reservación asociada
    SELECT cantidad, estado INTO v_personas_reserva, v_estado_reserva
    FROM public.reservaciones
    WHERE id = NEW.reservacion_id;

    -- Si la reservación está cancelada (estado 4), no ocupa mesa ni bloquea cupo
    IF v_estado_reserva = 4 THEN
        RETURN NEW;
    END IF;

    -- Validar que la mesa tenga capacidad suficiente
    SELECT sillas INTO v_capacidad_mesa
    FROM public.mesas
    WHERE id = NEW.mesa_id;

    IF v_capacidad_mesa IS NULL THEN
        RAISE EXCEPTION 'La mesa con ID % no existe.', NEW.mesa_id;
    END IF;

    IF v_personas_reserva > v_capacidad_mesa THEN
        RAISE EXCEPTION 'Capacidad excedida: La reserva #% solicita % personas, pero la mesa #% solo tiene % asientos.',
            NEW.reservacion_id, v_personas_reserva, NEW.mesa_id, v_capacidad_mesa;
    END IF;

    -- Duración efectiva a validar: mínimo 2 horas según especificación
    v_duracion := GREATEST(COALESCE(NEW.duracion, interval '2 hours'), interval '2 hours');

    -- Comprobar si existe solapamiento temporal en la misma mesa con otra reserva no cancelada
    SELECT h.id INTO v_conflicto_id
    FROM public.horarios h
    JOIN public.reservaciones r ON r.id = h.reservacion_id
    WHERE h.mesa_id = NEW.mesa_id
      AND r.estado <> 4
      AND h.id <> COALESCE(NEW.id, -1)
      AND h.reservacion_id <> NEW.reservacion_id
      AND tsrange(h.inicio, h.inicio + GREATEST(COALESCE(h.duracion, interval '2 hours'), interval '2 hours'), '[)')
       && tsrange(NEW.inicio, NEW.inicio + v_duracion, '[)')
    LIMIT 1;

    IF v_conflicto_id IS NOT NULL THEN
        RAISE EXCEPTION 'Cruce de horario detectado: La mesa #% ya está reservada en ese horario (Conflicto con horario #%, duración estimada 2h).',
            NEW.mesa_id, v_conflicto_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_cruce_reserva ON public.horarios;
CREATE TRIGGER trg_validar_cruce_reserva
BEFORE INSERT OR UPDATE OF mesa_id, inicio, duracion ON public.horarios
FOR EACH ROW
EXECUTE FUNCTION public.fn_trg_validar_cruce_reserva();


-- ----------------------------------------------------------------------------
-- 3. DISPARADOR: VALIDACIÓN DE CUPO MÁXIMO TOTAL DEL RESTAURANTE
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_trg_validar_cupo_restaurante()
RETURNS TRIGGER AS $$
DECLARE
    v_cupo_total integer;
    v_ocupacion_pico integer;
    v_personas_nuevas integer;
    v_estado_reserva integer;
BEGIN
    SELECT cantidad, estado INTO v_personas_nuevas, v_estado_reserva
    FROM public.reservaciones
    WHERE id = NEW.reservacion_id;

    IF v_estado_reserva = 4 THEN
        RETURN NEW;
    END IF;

    -- Cupo total sumando todas las mesas físicas del restaurante
    SELECT COALESCE(SUM(sillas), 0) INTO v_cupo_total FROM public.mesas;

    -- Calcular el pico de personas concurrentes en la ventana de 2 horas
    WITH ocupaciones AS (
        SELECT h.inicio,
               h.inicio + GREATEST(COALESCE(h.duracion, interval '2 hours'), interval '2 hours') AS fin,
               r.cantidad
        FROM public.horarios h
        JOIN public.reservaciones r ON r.id = h.reservacion_id
        WHERE r.estado <> 4
          AND h.reservacion_id <> NEW.reservacion_id
          AND h.inicio < NEW.inicio + interval '2 hours'
          AND h.inicio + GREATEST(COALESCE(h.duracion, interval '2 hours'), interval '2 hours') > NEW.inicio
    ),
    puntos AS (
        SELECT NEW.inicio AS t
        UNION
        SELECT inicio FROM ocupaciones WHERE inicio >= NEW.inicio
    )
    SELECT COALESCE(MAX(
        (SELECT COALESCE(SUM(cantidad), 0) FROM ocupaciones o WHERE o.inicio <= p.t AND o.fin > p.t)
    ), 0) INTO v_ocupacion_pico
    FROM puntos p;

    IF (v_ocupacion_pico + v_personas_nuevas) > v_cupo_total THEN
        RAISE EXCEPTION 'Cupo total del restaurante superado para el horario %: Capacidad total = % comensales, ocupación concurrente solicitada = %.',
            NEW.inicio, v_cupo_total, (v_ocupacion_pico + v_personas_nuevas);
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_cupo_restaurante ON public.horarios;
CREATE TRIGGER trg_validar_cupo_restaurante
BEFORE INSERT OR UPDATE OF inicio, duracion ON public.horarios
FOR EACH ROW
EXECUTE FUNCTION public.fn_trg_validar_cupo_restaurante();


-- ----------------------------------------------------------------------------
-- 4. DISPARADOR: VALIDACIÓN DE ROL MESERO EN PEDIDOS
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_trg_validar_rol_mesero()
RETURNS TRIGGER AS $$
DECLARE
    v_es_mesero boolean;
BEGIN
    SELECT EXISTS (
        SELECT 1
        FROM public.actuaciones a
        JOIN public.roles r ON r.id = a.rol_id
        WHERE a.usuario_id = NEW.mesero_id
          AND r.nombre = 'Mesero'
    ) INTO v_es_mesero;

    IF NOT v_es_mesero THEN
        RAISE EXCEPTION 'El usuario asignado con ID % no tiene el rol de Mesero.', NEW.mesero_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_rol_mesero ON public.pedidos;
CREATE TRIGGER trg_validar_rol_mesero
BEFORE INSERT OR UPDATE OF mesero_id ON public.pedidos
FOR EACH ROW
EXECUTE FUNCTION public.fn_trg_validar_rol_mesero();


-- ----------------------------------------------------------------------------
-- 5. DISPARADOR: VALIDACIÓN DE ROL COCINERO Y ESPECIALIDAD AUTORIZADA
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_trg_validar_preparacion_cocinero()
RETURNS TRIGGER AS $$
DECLARE
    v_es_cocinero boolean;
    v_tiene_especialidad boolean;
    v_plato_id integer;
    v_nombre_plato text;
BEGIN
    -- 1. Validar que el usuario tenga rol Cocinero
    SELECT EXISTS (
        SELECT 1
        FROM public.actuaciones a
        JOIN public.roles r ON r.id = a.rol_id
        WHERE a.usuario_id = NEW.cocinero_id
          AND r.nombre = 'Cocinero'
    ) INTO v_es_cocinero;

    IF NOT v_es_cocinero THEN
        RAISE EXCEPTION 'El usuario con ID % no tiene el rol de Cocinero.', NEW.cocinero_id;
    END IF;

    -- 2. Obtener el plato de la orden
    SELECT o.plato_id, p.nombre INTO v_plato_id, v_nombre_plato
    FROM public.ordenes o
    JOIN public.platos p ON p.id = o.plato_id
    WHERE o.id = NEW.orden_id;

    IF v_plato_id IS NULL THEN
        RAISE EXCEPTION 'La orden con ID % no existe o no tiene un plato asociado.', NEW.orden_id;
    END IF;

    -- 3. Validar que el cocinero tenga la especialidad registrada
    SELECT EXISTS (
        SELECT 1
        FROM public.especialidades
        WHERE cocinero_id = NEW.cocinero_id
          AND plato_id = v_plato_id
    ) INTO v_tiene_especialidad;

    IF NOT v_tiene_especialidad THEN
        RAISE EXCEPTION 'Especialidad no autorizada: El cocinero #% no tiene autorizada la preparación del plato "%" (ID %).',
            NEW.cocinero_id, v_nombre_plato, v_plato_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_preparacion_cocinero ON public.preparaciones;
CREATE TRIGGER trg_validar_preparacion_cocinero
BEFORE INSERT OR UPDATE OF cocinero_id, orden_id ON public.preparaciones
FOR EACH ROW
EXECUTE FUNCTION public.fn_trg_validar_preparacion_cocinero();


-- ----------------------------------------------------------------------------
-- 6. DISPARADOR: VALIDACIÓN DE ROL AL ASIGNAR ESPECIALIDAD
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_trg_validar_rol_al_asignar_especialidad()
RETURNS TRIGGER AS $$
DECLARE
    v_es_cocinero boolean;
BEGIN
    SELECT EXISTS (
        SELECT 1
        FROM public.actuaciones a
        JOIN public.roles r ON r.id = a.rol_id
        WHERE a.usuario_id = NEW.cocinero_id
          AND r.nombre = 'Cocinero'
    ) INTO v_es_cocinero;

    IF NOT v_es_cocinero THEN
        RAISE EXCEPTION 'No se puede asignar especialidad al usuario #%: no posee el rol de Cocinero.', NEW.cocinero_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_rol_al_asignar_especialidad ON public.especialidades;
CREATE TRIGGER trg_validar_rol_al_asignar_especialidad
BEFORE INSERT OR UPDATE OF cocinero_id ON public.especialidades
FOR EACH ROW
EXECUTE FUNCTION public.fn_trg_validar_rol_al_asignar_especialidad();


-- ----------------------------------------------------------------------------
-- 7. DISPARADOR: PRECIO UNITARIO Y TIMESTAMPS AUTOMÁTICOS EN ÓRDENES
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION public.fn_trg_ordenes_precio_y_tiempos()
RETURNS TRIGGER AS $$
BEGIN
    -- Asignar timestamp de solicitud si no se especifica
    IF NEW.solicitado IS NULL THEN
        NEW.solicitado := CURRENT_TIMESTAMP;
    END IF;

    -- Snapshot del precio unitario del plato al momento de registrar la orden
    IF NEW.precio_unitario IS NULL THEN
        SELECT precio INTO NEW.precio_unitario
        FROM public.platos
        WHERE id = NEW.plato_id;
    END IF;

    -- Si el estado pasa a preparado (4) y no hay fecha de preparación
    IF NEW.estado = 4 AND NEW.preparado IS NULL THEN
        NEW.preparado := CURRENT_TIMESTAMP;
    END IF;

    -- Si el estado pasa a entregado (3) y no hay fecha de entrega
    IF NEW.estado = 3 AND NEW.entregado IS NULL THEN
        IF NEW.preparado IS NULL THEN
            NEW.preparado := CURRENT_TIMESTAMP;
        END IF;
        NEW.entregado := CURRENT_TIMESTAMP;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_ordenes_precio_y_tiempos ON public.ordenes;
CREATE TRIGGER trg_ordenes_precio_y_tiempos
BEFORE INSERT OR UPDATE OF estado, plato_id, precio_unitario ON public.ordenes
FOR EACH ROW
EXECUTE FUNCTION public.fn_trg_ordenes_precio_y_tiempos();

COMMIT;

