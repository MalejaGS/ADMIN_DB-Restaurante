/*
===============================================================================
  LIMPIEZA DE DATOS - BASE DE DATOS DEL RESTAURANTE
  PostgreSQL 18+

  ADVERTENCIA:
    Este script elimina permanentemente todos los REGISTROS de las 12 tablas.
    Conserva las tablas, columnas, constraints, indices y relaciones.
    Tambien reinicia en 1 las secuencias de las claves primarias.

  Ejecucion:
    psql -v ON_ERROR_STOP=1 -d nombre_base -f truncate_restaurante.sql

  Despues de ejecutarlo puede cargarse nuevamente:
    psql -v ON_ERROR_STOP=1 -d nombre_base -f seed_restaurante_urbano.sql
===============================================================================
*/

\set ON_ERROR_STOP on

BEGIN;

SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '0';

-- Evita limpiar la base al mismo tiempo que se ejecuta el seed.
SELECT pg_advisory_xact_lock(hashtext('seed_restaurante_urbano_v1'));

/*
  Todas las tablas relacionadas se incluyen en una sola instruccion TRUNCATE.
  Esto permite respetar las claves foraneas sin desactivarlas ni usar CASCADE.

  RESTART IDENTITY reinicia las secuencias OWNED BY asociadas a cada tabla.
*/
TRUNCATE TABLE
    public.preparaciones,
    public.especialidades,
    public.horarios,
    public.ordenes,
    public.pedidos,
    public.reservaciones,
    public.actuaciones,
    public.platos,
    public.mesas,
    public.tipos,
    public.roles,
    public.usuarios
RESTART IDENTITY;

-- Comprueba que ninguna tabla conserve registros antes de confirmar el cambio.
DO $verificar_limpieza$
DECLARE
    tabla text;
    tiene_datos boolean;
BEGIN
    FOREACH tabla IN ARRAY ARRAY[
        'actuaciones',
        'especialidades',
        'horarios',
        'mesas',
        'ordenes',
        'pedidos',
        'platos',
        'preparaciones',
        'reservaciones',
        'roles',
        'tipos',
        'usuarios'
    ]
    LOOP
        EXECUTE format(
            'SELECT EXISTS (SELECT 1 FROM public.%I)',
            tabla
        )
        INTO tiene_datos;

        IF tiene_datos THEN
            RAISE EXCEPTION 'La tabla public.% aun contiene datos.', tabla;
        END IF;
    END LOOP;
END
$verificar_limpieza$;

COMMIT;

\echo 'Limpieza completada: las 12 tablas estan vacias y sus IDs reiniciados.'
