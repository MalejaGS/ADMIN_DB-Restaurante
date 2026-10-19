\set ON_ERROR_STOP on
BEGIN;
SET LOCAL TIME ZONE 'America/Bogota';
SELECT pg_advisory_xact_lock(202603, 1);
CREATE TABLE IF NOT EXISTS public.restaurante_migraciones (version integer PRIMARY KEY, aplicada timestamptz NOT NULL DEFAULT now());
-- La migración se ejecuta una sola vez; la segunda ejecución no cambia datos.
SELECT NOT EXISTS (SELECT 1 FROM public.restaurante_migraciones WHERE version=1) AS aplicar \gset
\if :aplicar
-- En instalaciones con reservas particionadas, cubrir IDs futuros sin mover filas.
DO $$
BEGIN
 IF EXISTS (SELECT 1 FROM pg_partitioned_table WHERE partrelid='public.reservaciones'::regclass)
 AND NOT EXISTS (SELECT 1 FROM pg_inherits i JOIN pg_class c ON c.oid=i.inhrelid WHERE i.inhparent='public.reservaciones'::regclass AND pg_get_expr(c.relpartbound,c.oid)='DEFAULT') THEN
  EXECUTE 'CREATE TABLE public.reservaciones_futuras PARTITION OF public.reservaciones DEFAULT';
 END IF;
END $$;
ALTER TABLE public.usuarios ADD COLUMN activo boolean NOT NULL DEFAULT true;
ALTER TABLE public.platos ADD COLUMN activo boolean NOT NULL DEFAULT true;
ALTER TABLE public.pedidos
 ADD COLUMN mesa_id integer REFERENCES public.mesas(id),
 ADD COLUMN reservacion_id integer REFERENCES public.reservaciones(id),
 ADD COLUMN creado timestamptz,
 ADD COLUMN token text UNIQUE;
ALTER TABLE public.ordenes
 ADD COLUMN precio_unitario numeric CHECK (precio_unitario >= 0),
 ADD COLUMN preparado timestamptz,
 ADD COLUMN entregado timestamptz;
ALTER TABLE public.ordenes DROP CONSTRAINT ck_ordenes_estado;
ALTER TABLE public.ordenes ADD CONSTRAINT ck_ordenes_estado CHECK (estado IN (1,2,3,4));
ALTER TABLE public.horarios DROP CONSTRAINT ck_horarios_duracion;
ALTER TABLE public.horarios ADD CONSTRAINT ck_horarios_duracion CHECK (duracion > interval '0' AND duracion <= interval '2 hours');
-- No se inventan mesa, reserva, fecha de entrega ni precio de los pedidos antiguos.
-- Los horarios originales se conservan. La aplicación reserva como mínimo 2 horas.
CREATE TABLE public.restaurante_operaciones (
 token text PRIMARY KEY,
 usuario_id integer NOT NULL REFERENCES public.usuarios(id),
 accion text NOT NULL,
 destino text NOT NULL,
 creada timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX horarios_mesa_inicio_idx ON public.horarios(mesa_id, inicio);
CREATE INDEX horarios_reserva_idx ON public.horarios(reservacion_id);
CREATE INDEX pedidos_mesero_idx ON public.pedidos(mesero_id, id);
CREATE INDEX ordenes_pedido_idx ON public.ordenes(pedido_id);
CREATE INDEX ordenes_solicitado_idx ON public.ordenes(solicitado);
CREATE INDEX ordenes_cocina_idx ON public.ordenes(estado, plato_id);
CREATE INDEX reservaciones_cliente_idx ON public.reservaciones(cliente_id);
CREATE VIEW public.restaurante_conflictos_reservas AS
 SELECT h1.id AS horario_1, h2.id AS horario_2, h1.mesa_id,
        h1.reservacion_id AS reserva_1, h2.reservacion_id AS reserva_2
 FROM public.horarios h1
 JOIN public.reservaciones r1 ON r1.id=h1.reservacion_id AND r1.estado<>4
 JOIN public.horarios h2 ON h1.mesa_id=h2.mesa_id AND h1.id<h2.id
 JOIN public.reservaciones r2 ON r2.id=h2.reservacion_id AND r2.estado<>4
 WHERE tsrange(h1.inicio,h1.inicio+greatest(h1.duracion,interval '2 hours'),'[)')
    && tsrange(h2.inicio,h2.inicio+greatest(h2.duracion,interval '2 hours'),'[)');
INSERT INTO public.restaurante_migraciones(version) VALUES (1);
\endif
COMMIT;
-- Auditoría informativa: estos conflictos NO se modifican automáticamente.
SELECT * FROM public.restaurante_conflictos_reservas ORDER BY mesa_id, horario_1;
SELECT count(*) AS pedidos_sin_mesa_o_fecha FROM public.pedidos WHERE mesa_id IS NULL OR creado IS NULL;
SELECT count(*) AS items_sin_precio_original FROM public.ordenes WHERE precio_unitario IS NULL;
