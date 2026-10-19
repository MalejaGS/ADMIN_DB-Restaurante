\set ON_ERROR_STOP on
BEGIN;
SELECT pg_advisory_xact_lock(202603,1);
SELECT pg_advisory_xact_lock(202603,2);
-- No crear nuevas filas en paralelo mientras se comprueban los contadores.
LOCK TABLE public.reservaciones, public.horarios, public.pedidos, public.ordenes,
 public.usuarios, public.actuaciones, public.roles, public.tipos, public.platos,
 public.especialidades, public.preparaciones IN SHARE ROW EXCLUSIVE MODE;
DO $$
DECLARE
 tabla text;
 secuencia text;
 maximo bigint;
 ultimo bigint;
 llamada boolean;
BEGIN
 IF NOT EXISTS (SELECT 1 FROM public.restaurante_migraciones WHERE version=1) THEN
  RAISE EXCEPTION 'Aplique primero 001_funcionalidades.sql';
 END IF;
 FOREACH tabla IN ARRAY ARRAY['reservaciones','horarios','pedidos','ordenes','usuarios','actuaciones','roles','tipos','platos','especialidades','preparaciones'] LOOP
  secuencia := pg_get_serial_sequence('public.'||tabla,'id');
  EXECUTE format('SELECT max(id) FROM public.%I',tabla) INTO maximo;
  IF secuencia IS NOT NULL AND maximo IS NOT NULL THEN
   EXECUTE format('SELECT last_value,is_called FROM %s',secuencia::regclass) INTO ultimo,llamada;
   -- setval no se revierte con ROLLBACK: solo se avanza y nunca se reutilizan IDs.
   IF maximo > ultimo OR (maximo=ultimo AND NOT llamada) THEN
    PERFORM setval(secuencia::regclass,maximo,true);
    RAISE NOTICE 'Secuencia % adelantada de % a %',secuencia,ultimo,maximo;
   END IF;
  END IF;
 END LOOP;
END $$;
INSERT INTO public.restaurante_migraciones(version) VALUES(2) ON CONFLICT(version) DO NOTHING;
COMMIT;
