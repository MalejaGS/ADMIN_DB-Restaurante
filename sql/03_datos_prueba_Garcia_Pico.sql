/*
===============================================================================
  DATOS DE PRUEBA - RESTAURANTE URBANO DE COMIDAS RAPIDAS
  PostgreSQL 18+

  Ventana temporal (relativa a CURRENT_DATE):
    - Tres meses calendario completos anteriores.
    - El mes calendario actual.
    - El mes calendario siguiente completo.

  Semilla determinista:
    - data_seed = 42. La misma fecha de ejecucion produce el mismo conjunto.
    - Cada decision usa una etiqueta de dominio distinta para evitar que, por
      ejemplo, el tamano del grupo quede correlacionado con su estado.

  Suposicion documentada para los estados, ya que el dump solo define enteros:
    reservaciones.estado: 1 = pendiente, 2 = confirmada,
                          3 = completada, 4 = cancelada.
    ordenes.estado:       1 = solicitada, 2 = en preparacion, 3 = entregada.

  Seguridad:
    - Ejecutar despues de restaurar el dump vacio entregado.
    - La carga es atomica: cualquier error revierte todo.
    - No borra datos. Si detecta datos previos en las tablas, se detiene.
    - Todos los usuarios usan la clave de prueba Restaurante#42.
    - Se almacena su SHA-256 en bytea. Esta politica es solo para pruebas
      academicas; una aplicacion real debe usar Argon2id o bcrypt con salt.

  Ejecucion:
    psql -v ON_ERROR_STOP=1 -d nombre_base -f seed_restaurante_urbano.sql
===============================================================================
*/

\set ON_ERROR_STOP on
\set data_seed 42
\set test_password 'Restaurante#42'

BEGIN;

SET LOCAL TIME ZONE 'America/Bogota';
SET LOCAL lock_timeout = '10s';
SET LOCAL statement_timeout = '0';

-- Evita dos cargas simultaneas de este mismo conjunto de datos.
SELECT pg_advisory_xact_lock(hashtext('seed_restaurante_urbano_v1'));

-- El esquema suministrado no ofrece claves naturales suficientes para hacer una
-- carga idempotente sin riesgo. Por ello se exige una base vacia y nunca se hace
-- TRUNCATE ni DELETE de informacion ajena.
DO $validar_base_vacia$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.actuaciones
        UNION ALL SELECT 1 FROM public.especialidades
        UNION ALL SELECT 1 FROM public.horarios
        UNION ALL SELECT 1 FROM public.mesas
        UNION ALL SELECT 1 FROM public.ordenes
        UNION ALL SELECT 1 FROM public.pedidos
        UNION ALL SELECT 1 FROM public.platos
        UNION ALL SELECT 1 FROM public.preparaciones
        UNION ALL SELECT 1 FROM public.reservaciones
        UNION ALL SELECT 1 FROM public.roles
        UNION ALL SELECT 1 FROM public.tipos
        UNION ALL SELECT 1 FROM public.usuarios
    ) THEN
        RAISE EXCEPTION
            'Carga cancelada: la base ya contiene datos. Restaure primero el dump vacio.';
    END IF;
END
$validar_base_vacia$;

/* ---------------------------------------------------------------------------
   1. ROLES, USUARIOS Y ACTUACIONES
--------------------------------------------------------------------------- */

INSERT INTO public.roles (nombre)
VALUES ('Administrador'), ('Cliente'), ('Cocinero'), ('Maitre'), ('Mesero');

CREATE TEMP TABLE seed_usuarios_stg (
    seed_key    text PRIMARY KEY,
    usuario_id  integer NOT NULL UNIQUE,
    nombre      text NOT NULL,
    rol_nombre  text NOT NULL,
    clave       bytea NOT NULL,
    fecha_clave timestamp without time zone NOT NULL
) ON COMMIT DROP;

-- Cuatro maitres permiten mantener dos responsables de sala por turno y rotar
-- descansos/dias libres sin dejar descubierta una operacion de 32 mesas.
WITH personal(seed_key, nombre, rol_nombre) AS (
    VALUES
        ('STAFF-ADM-01', 'Maria Fernanda Bohorquez', 'Administrador'),
        ('STAFF-ADM-02', 'Nicolas Eduardo Quintero', 'Administrador'),
        ('STAFF-MAI-01', 'Andrea Carolina Montalvo', 'Maitre'),
        ('STAFF-MAI-02', 'Rafael Ignacio Montoya', 'Maitre'),
        ('STAFF-MAI-03', 'Helena Victoria Restrepo', 'Maitre'),
        ('STAFF-MAI-04', 'Mauricio Felipe Londono', 'Maitre'),
        ('STAFF-MES-01', 'Laura Ximena Cardenas', 'Mesero'),
        ('STAFF-MES-02', 'Samuel Esteban Cifuentes', 'Mesero'),
        ('STAFF-MES-03', 'Daniela Alejandra Buitrago', 'Mesero'),
        ('STAFF-MES-04', 'Tomas Alejandro Beltran', 'Mesero'),
        ('STAFF-MES-05', 'Sara Juliana Villamil', 'Mesero'),
        ('STAFF-MES-06', 'Felipe Andres Mondragon', 'Mesero'),
        ('STAFF-MES-07', 'Manuela Sofia Barragan', 'Mesero'),
        ('STAFF-MES-08', 'Juan Camilo Zuluaga', 'Mesero'),
        ('STAFF-MES-09', 'Andres Felipe Pinzon', 'Mesero'),
        ('STAFF-MES-10', 'Maria Paula Naranjo', 'Mesero'),
        ('STAFF-MES-11', 'Jose Miguel Forero', 'Mesero'),
        ('STAFF-MES-12', 'Carolina Andrea Leal', 'Mesero'),
        ('STAFF-MES-13', 'David Santiago Ospina', 'Mesero'),
        ('STAFF-MES-14', 'Luciana Herrera', 'Mesero'),
        ('STAFF-COC-01', 'Camilo Andres Santamaria', 'Cocinero'),
        ('STAFF-COC-02', 'Paula Andrea Fajardo', 'Cocinero'),
        ('STAFF-COC-03', 'Esteban Mauricio Galindo', 'Cocinero'),
        ('STAFF-COC-04', 'Juliana Marcela Poveda', 'Cocinero'),
        ('STAFF-COC-05', 'Sergio Ivan Salamanca', 'Cocinero'),
        ('STAFF-COC-06', 'Natalia Andrea Cepeda', 'Cocinero'),
        ('STAFF-COC-07', 'Mateo Nicolas Avendano', 'Cocinero'),
        ('STAFF-COC-08', 'Alejandro Jose Rendon', 'Cocinero'),
        ('STAFF-COC-09', 'Luisa Fernanda Pardo', 'Cocinero'),
        ('STAFF-COC-10', 'Cristian David Guevara', 'Cocinero'),
        ('STAFF-COC-11', 'Angela Maria Prieto', 'Cocinero'),
        ('STAFF-COC-12', 'Miguel Angel Tovar', 'Cocinero')
)
INSERT INTO seed_usuarios_stg
    (seed_key, usuario_id, nombre, rol_nombre, clave, fecha_clave)
SELECT
    p.seed_key,
    nextval('public.usuarios_id_seq')::integer,
    p.nombre,
    p.rol_nombre,
    sha256(convert_to(:'test_password', 'UTF8')),
    (CURRENT_DATE - make_interval(
        days => mod(abs(hashtextextended(
            'FECHA_CLAVE_PERSONAL|' || p.seed_key,
            :data_seed
        )::numeric), 90)::integer
    ))::timestamp
FROM personal AS p;

-- 10.000 clientes: 4.000 colombianos y 6.000 internacionales/regionales.
-- Con unas 18.600 reservas esperadas, el promedio queda cerca de 1,9 reservas
-- por titular en cinco meses, en lugar de reutilizar artificialmente las mismas
-- cuentas mas de quince veces.
-- El esquema no posee pais, telefono ni correo; la diversidad se representa
-- mediante nombres plausibles de cinco grupos geograficos.
WITH pools(region, nombres, apellidos, cantidad) AS (
    VALUES
    (
        'CO',
        ARRAY[
            'Sofia','Valentina','Isabella','Mariana','Gabriela','Sara','Laura','Camila',
            'Santiago','Sebastian','Mateo','Nicolas','Samuel','Daniel','Martin','Emiliano',
            'Ana Maria','Maria Jose','Juan Pablo','Juan David'
        ]::text[],
        ARRAY[
            'Rodriguez','Gomez','Gonzalez','Martinez','Garcia','Lopez','Hernandez','Sanchez',
            'Ramirez','Diaz','Torres','Vargas','Moreno','Rojas','Castro','Ortiz','Ruiz','Suarez',
            'Mendoza','Navarro'
        ]::text[],
        4000
    ),
    (
        'LATAM',
        ARRAY[
            'Renata','Antonella','Luciana','Martina','Josefina','Agustina','Catalina','Florencia',
            'Thiago','Benjamin','Bautista','Lautaro','Joaquin','Franco','Facundo','Maximiliano',
            'Maria Paz','Luis Felipe','Jose Manuel','Ana Lucia'
        ]::text[],
        ARRAY[
            'Alvarez','Benitez','Cardenas','Delgado','Espinoza','Fernandez','Ibarra','Jimenez',
            'Luna','Molina','Paredes','Quiroga','Romero','Salazar','Silva','Soto','Valdez','Vega',
            'Zamora','Acosta'
        ]::text[],
        1500
    ),
    (
        'ANGLO',
        ARRAY[
            'Olivia','Emma','Amelia','Charlotte','Ava','Mia','Sophia','Evelyn','Liam','Noah',
            'Oliver','James','Elijah','William','Henry','Lucas','Theodore','Jack','Leo','Alexander'
        ]::text[],
        ARRAY[
            'Smith','Johnson','Williams','Brown','Jones','Miller','Davis','Wilson','Moore','Taylor',
            'Anderson','Thomas','Jackson','White','Harris','Martin','Thompson','Walker','Young','King'
        ]::text[],
        1500
    ),
    (
        'EUROPA',
        ARRAY[
            'Chloe','Elise','Lea','Manon','Giulia','Chiara','Elena','Lucia','Hugo','Louis',
            'Arthur','Jules','Luca','Matteo','Leonardo','Marco','Felix','Jonas','Oskar','Milan'
        ]::text[],
        ARRAY[
            'Dubois','Bernard','Moreau','Laurent','Rossi','Romano','Ferrari','Ricci','Schmidt','Weber',
            'Wagner','Becker','Muller','Fischer','Jansen','De Vries','Bianchi','Conti','Marino','Greco'
        ]::text[],
        1500
    ),
    (
        'GLOBAL',
        ARRAY[
            'Yuki','Aiko','Hana','Mei','Sakura','Haruto','Ren','Sota','Minjun','Jihoon',
            'Seo-yeon','Ji-woo','Aarav','Vihaan','Anaya','Isha','Omar','Layla','Zain','Nour'
        ]::text[],
        ARRAY[
            'Sato','Suzuki','Takahashi','Tanaka','Watanabe','Kim','Lee','Park','Choi','Jung',
            'Sharma','Patel','Singh','Gupta','Khan','Haddad','Nasser','Rahman','Farah','Saleh'
        ]::text[],
        1500
    )
),
clientes_indices AS (
    SELECT
        p.*,
        g.n,
        1 + ((g.n - 1) % cardinality(p.nombres)) AS idx_nombre,
        1 + (((g.n - 1) / cardinality(p.nombres)) % cardinality(p.apellidos))
            AS idx_apellido_1,
        1 + mod(
            ((g.n - 1) / cardinality(p.nombres)) % cardinality(p.apellidos)
                + ((g.n - 1) /
                    (cardinality(p.nombres) * cardinality(p.apellidos)))
                + 1,
            cardinality(p.apellidos)
        ) AS idx_apellido_2
    FROM pools AS p
    CROSS JOIN LATERAL generate_series(1, p.cantidad) AS g(n)
),
clientes AS (
    SELECT
        c.region || '-' || to_char(c.n, 'FM00000') AS seed_key,
        c.nombres[c.idx_nombre] || ' ' ||
        c.apellidos[c.idx_apellido_1] || ' ' ||
        c.apellidos[c.idx_apellido_2] AS nombre
    FROM clientes_indices AS c
)
INSERT INTO seed_usuarios_stg
    (seed_key, usuario_id, nombre, rol_nombre, clave, fecha_clave)
SELECT
    c.seed_key,
    nextval('public.usuarios_id_seq')::integer,
    c.nombre,
    'Cliente',
    sha256(convert_to(:'test_password', 'UTF8')),
    (CURRENT_DATE - make_interval(
        days => mod(abs(hashtextextended(
            'FECHA_CLAVE_CLIENTE|' || c.seed_key,
            :data_seed
        )::numeric), 180)::integer
    ))::timestamp
FROM clientes AS c;

INSERT INTO public.usuarios (id, nombre, clave, fecha_clave)
SELECT usuario_id, nombre, clave, fecha_clave
FROM seed_usuarios_stg
ORDER BY usuario_id;

INSERT INTO public.actuaciones (rol_id, usuario_id)
SELECT r.id, u.usuario_id
FROM seed_usuarios_stg AS u
JOIN public.roles AS r
  ON r.nombre = u.rol_nombre;

/* ---------------------------------------------------------------------------
   2. TIPOS, PLATOS Y ESPECIALIDADES
--------------------------------------------------------------------------- */

INSERT INTO public.tipos (nombre)
VALUES
    ('Entrada'), ('Hamburguesa'), ('Perro caliente'), ('Sandwich'), ('Pollo'),
    ('Acompanamiento'), ('Postre'), ('Bebida'), ('Coctel');

CREATE TEMP TABLE seed_platos_stg (
    plato_id    integer PRIMARY KEY,
    tipo_nombre text NOT NULL,
    nombre      text NOT NULL,
    descripcion text NOT NULL,
    tiempo      interval NOT NULL,
    precio      numeric(12,2) NOT NULL
) ON COMMIT DROP;

-- Precios expresados en COP y ajustados a una propuesta premium (3x frente
-- al valor inicialmente planteado).
WITH menu(tipo_nombre, nombre, descripcion, tiempo, precio) AS (
    VALUES
        ('Entrada', 'Croquetas de yuca trufada',
         'Yuca cremosa, queso costeno, mayonesa de ajo negro y cebollin.',
         interval '12 minutes', 66000),
        ('Entrada', 'Nachos Distrito',
         'Totopos, chili de res, queso fundido, pico de gallo y crema agria.',
         interval '14 minutes', 87000),
        ('Entrada', 'Coliflor buffalo',
         'Coliflor crocante, salsa buffalo de la casa y dip de queso azul.',
         interval '13 minutes', 72000),

        ('Hamburguesa', 'Smash Clasica 85',
         'Doble carne smash, cheddar, pepinillos, cebolla y salsa de la casa.',
         interval '15 minutes', 108000),
        ('Hamburguesa', 'Bogota 2600',
         'Carne madurada, queso paipa, tocineta, cebolla caramelizada y alioli.',
         interval '18 minutes', 132000),
        ('Hamburguesa', 'Andina Ahumada',
         'Doble carne, queso ahumado, portobello, rugula y BBQ de panela.',
         interval '18 minutes', 129000),
        ('Hamburguesa', 'Caribe Crispy',
         'Pollo crocante, coleslaw de mango, pepinillos y mayonesa de limon.',
         interval '17 minutes', 117000),
        ('Hamburguesa', 'Veggie Urbana',
         'Medallon de frijol y quinua, aguacate, tomate, rugula y tahini.',
         interval '16 minutes', 111000),

        ('Perro caliente', 'Perro Calle 85',
         'Salchicha premium, cheddar, tocineta, cebolla crispy y relish.',
         interval '13 minutes', 93000),
        ('Perro caliente', 'Perro Costeno',
         'Salchicha artesanal, suero, queso costeno, platano maduro y hogao.',
         interval '14 minutes', 99000),
        ('Perro caliente', 'Seoul Dog',
         'Salchicha de res, kimchi suave, gochujang, ajonjoli y cebollin.',
         interval '14 minutes', 102000),

        ('Sandwich', 'Philly Chapinero',
         'Res laminada, pimenton, cebolla, provolone y pan artesanal.',
         interval '17 minutes', 123000),
        ('Sandwich', 'Cubano de la Casa',
         'Cerdo, jamon, queso suizo, pepinillos y mostaza en pan prensado.',
         interval '16 minutes', 114000),

        ('Pollo', 'Tenders Miel Picante',
         'Tiras de pollo crocante, miel picante y dip ranch de cilantro.',
         interval '15 minutes', 105000),
        ('Pollo', 'Alitas Tamarindo BBQ',
         'Alitas glaseadas con tamarindo y BBQ; acompana encurtido fresco.',
         interval '20 minutes', 117000),

        ('Acompanamiento', 'Papas Trufa y Parmesano',
         'Papas francesas, aceite de trufa, parmesano y perejil.',
         interval '10 minutes', 51000),
        ('Acompanamiento', 'Yucas Crocantes',
         'Yuca frita con sal de limon y mayonesa de cilantro.',
         interval '11 minutes', 45000),
        ('Acompanamiento', 'Aros de Cebolla',
         'Aros apanados con cerveza y salsa BBQ ahumada.',
         interval '11 minutes', 48000),
        ('Acompanamiento', 'Ensalada Urbana',
         'Mezcla verde, tomate, pepino, aguacate y vinagreta de panela.',
         interval '8 minutes', 48000),

        ('Postre', 'Churros de Arequipe',
         'Churros tibios, azucar de canela, arequipe y chocolate oscuro.',
         interval '12 minutes', 54000),
        ('Postre', 'Brownie Sal Marina',
         'Brownie tibio, helado de vainilla, caramelo y sal marina.',
         interval '10 minutes', 63000),
        ('Postre', 'Cheesecake de Maracuya',
         'Cheesecake cremoso, curd de maracuya y crocante de coco.',
         interval '6 minutes', 66000),

        ('Bebida', 'Limonada de Panela y Hierbabuena',
         'Limonada natural con panela organica y hierbabuena.',
         interval '5 minutes', 36000),
        ('Bebida', 'Soda de Lulo',
         'Soda artesanal de lulo, limon y romero.',
         interval '5 minutes', 42000),
        ('Bebida', 'Cold Brew Naranja',
         'Cafe filtrado en frio, tonica y piel de naranja.',
         interval '6 minutes', 45000),
        ('Bebida', 'Agua Mineral',
         'Agua mineral con o sin gas, botella de 330 ml.',
         interval '2 minutes', 27000),

        ('Coctel', 'Gin Distrito',
         'Gin, tonica, cordial de limon mandarino y romero.',
         interval '7 minutes', 108000),
        ('Coctel', 'Mule de Lulo',
         'Vodka, lulo, ginger beer y limon.',
         interval '7 minutes', 105000),
        ('Coctel', 'Spritz de Guayaba',
         'Aperitivo, espumante, guayaba rosada y soda.',
         interval '7 minutes', 111000)
)
INSERT INTO seed_platos_stg
    (plato_id, tipo_nombre, nombre, descripcion, tiempo, precio)
SELECT
    nextval('public.platos_id_seq')::integer,
    m.tipo_nombre,
    m.nombre,
    m.descripcion,
    m.tiempo,
    m.precio
FROM menu AS m;

INSERT INTO public.platos (id, tipo_id, nombre, descripcion, tiempo, precio)
SELECT p.plato_id, t.id, p.nombre, p.descripcion, p.tiempo, p.precio
FROM seed_platos_stg AS p
JOIN public.tipos AS t
  ON t.nombre = p.tipo_nombre;

-- Cada plato queda cubierto por seis de los doce cocineros. Esta capacitacion
-- cruzada garantiza que siempre haya especialistas disponibles al rotar ocho
-- cocineros por turno y evita concentrar un plato popular en solo dos personas.
WITH cocineros AS (
    SELECT
        usuario_id,
        row_number() OVER (ORDER BY seed_key) AS rn,
        count(*) OVER () AS total
    FROM seed_usuarios_stg
    WHERE rol_nombre = 'Cocinero'
),
platos AS (
    SELECT
        plato_id,
        row_number() OVER (ORDER BY plato_id) AS rn
    FROM seed_platos_stg
)
INSERT INTO public.especialidades (cocinero_id, plato_id)
SELECT c.usuario_id, p.plato_id
FROM platos AS p
JOIN cocineros AS c
  ON c.rn = 1 + mod(p.rn - 1, c.total)
  OR c.rn = 1 + mod(p.rn,     c.total)
  OR c.rn = 1 + mod(p.rn + 1, c.total)
  OR c.rn = 1 + mod(p.rn + 2, c.total)
  OR c.rn = 1 + mod(p.rn + 3, c.total)
  OR c.rn = 1 + mod(p.rn + 4, c.total);

/* ---------------------------------------------------------------------------
   3. MESAS
--------------------------------------------------------------------------- */

CREATE TEMP TABLE seed_mesas_stg (
    mesa_key text PRIMARY KEY,
    mesa_id  integer NOT NULL UNIQUE,
    sillas   integer NOT NULL
) ON COMMIT DROP;

INSERT INTO seed_mesas_stg (mesa_key, mesa_id, sillas)
SELECT
    'M' || to_char(g.n, 'FM000'),
    nextval('public.mesas_id_seq')::integer,
    CASE
        WHEN g.n <= 8  THEN 2
        WHEN g.n <= 24 THEN 4
        WHEN g.n <= 30 THEN 6
        ELSE 8
    END
FROM generate_series(1, 32) AS g(n);

INSERT INTO public.mesas (id, sillas)
SELECT mesa_id, sillas
FROM seed_mesas_stg
ORDER BY mesa_id;

/* ---------------------------------------------------------------------------
   4. RESERVACIONES Y HORARIOS

   Los turnos de cada mesa estan separados por 90 minutos y duran 80 minutos,
   por lo que no se superponen. Las mesas reciben un desfase fijo de 0, 5, 10 o
   15 minutos para evitar que todo el salon llegue y ordene al mismo instante.
   La ocupacion aumenta en noches y fines de semana. Las reservas futuras se
   reducen gradualmente porque aun no se ha cerrado su demanda.

   Las cancelaciones conservan su horario como trazabilidad historica, pero no
   representan ocupacion activa. Cualquier consulta de disponibilidad debe
   excluir reservaciones.estado = 4.
--------------------------------------------------------------------------- */

CREATE TEMP TABLE seed_reservas_stg (
    seed_no        bigint PRIMARY KEY,
    reservacion_id integer NOT NULL UNIQUE,
    cliente_id     integer NOT NULL,
    mesa_id        integer NOT NULL,
    inicio         timestamp without time zone NOT NULL,
    duracion       interval NOT NULL,
    cantidad       integer NOT NULL,
    estado         integer NOT NULL
) ON COMMIT DROP;

WITH parametros AS (
    SELECT
        (date_trunc('month', CURRENT_DATE) - interval '3 months')::date AS fecha_inicio,
        (date_trunc('month', CURRENT_DATE) + interval '1 month')::date  AS proximo_mes,
        (date_trunc('month', CURRENT_DATE) + interval '2 months')::date AS fecha_fin_exclusiva
),
dias AS (
    SELECT g.fecha::date AS fecha
    FROM parametros AS p
    CROSS JOIN LATERAL generate_series(
        p.fecha_inicio,
        p.fecha_fin_exclusiva - 1,
        interval '1 day'
    ) AS g(fecha)
),
config_slots(hora, dias_semana) AS (
    VALUES
        (time '12:00', ARRAY[1,2,3,4,5,6,7]),
        (time '13:30', ARRAY[1,2,3,4,5,6,7]),
        (time '15:00', ARRAY[5,6,7]),
        (time '18:30', ARRAY[1,2,3,4,5,6,7]),
        (time '20:00', ARRAY[1,2,3,4,5,6,7]),
        (time '21:30', ARRAY[4,5,6]),
        (time '23:00', ARRAY[5,6])
),
candidatos AS (
    SELECT
        d.fecha,
        c.hora,
        m.mesa_key,
        m.mesa_id,
        m.sillas,
        extract(isodow FROM d.fecha)::integer AS dia_semana,
        p.proximo_mes,
        CASE
            WHEN extract(isodow FROM d.fecha)::integer IN (5,6)
                 AND c.hora >= time '18:00' THEN 94
            WHEN extract(isodow FROM d.fecha)::integer IN (5,6) THEN 86
            WHEN extract(isodow FROM d.fecha)::integer = 7 THEN 80
            WHEN c.hora >= time '18:00' THEN 78
            ELSE 68
        END AS ocupacion_base
    FROM dias AS d
    CROSS JOIN parametros AS p
    CROSS JOIN config_slots AS c
    CROSS JOIN seed_mesas_stg AS m
    WHERE extract(isodow FROM d.fecha)::integer = ANY (c.dias_semana)
),
ponderados AS (
    SELECT
        c.*,
        CASE
            WHEN c.fecha < CURRENT_DATE THEN c.ocupacion_base
            WHEN c.fecha = CURRENT_DATE THEN round(c.ocupacion_base * 0.90)::integer
            WHEN c.fecha < c.proximo_mes THEN round(c.ocupacion_base * 0.75)::integer
            ELSE round(c.ocupacion_base * 0.60)::integer
        END AS umbral_ocupacion,
        mod(abs(hashtextextended(
            'OCUPACION|' || c.fecha::text || '|' || c.hora::text || '|' || c.mesa_key,
            :data_seed
        )::numeric), 100)::integer AS dado_ocupacion
    FROM candidatos AS c
),
seleccionados AS (
    SELECT
        p.*,
        row_number() OVER (ORDER BY p.fecha, p.hora, p.mesa_id) AS seed_no
    FROM ponderados AS p
    WHERE p.dado_ocupacion < p.umbral_ocupacion
),
clientes AS (
    SELECT
        usuario_id,
        row_number() OVER (ORDER BY seed_key) AS rn,
        count(*) OVER () AS total
    FROM seed_usuarios_stg
    WHERE rol_nombre = 'Cliente'
),
reservas_calculadas AS (
    SELECT
        s.seed_no,
        c.usuario_id AS cliente_id,
        s.mesa_id,
        (s.fecha + s.hora)::timestamp
            + make_interval(mins => 5 * mod(
                abs(hashtextextended(
                    'DESFASE_MESA|' || s.mesa_key,
                    :data_seed
                )::numeric),
                4
              )::integer) AS inicio,
        interval '80 minutes' AS duracion,
        CASE s.sillas
            WHEN 2 THEN 1 + mod(abs(hashtextextended(
                'CANTIDAD_MESA_2|' || s.seed_no::text, :data_seed
            )::numeric), 2)::integer
            WHEN 4 THEN 2 + mod(abs(hashtextextended(
                'CANTIDAD_MESA_4|' || s.seed_no::text, :data_seed
            )::numeric), 3)::integer
            WHEN 6 THEN 4 + mod(abs(hashtextextended(
                'CANTIDAD_MESA_6|' || s.seed_no::text, :data_seed
            )::numeric), 3)::integer
            ELSE        6 + mod(abs(hashtextextended(
                'CANTIDAD_MESA_8|' || s.seed_no::text, :data_seed
            )::numeric), 3)::integer
        END AS cantidad,
        mod(abs(hashtextextended(
            'ESTADO_RESERVA|' || s.seed_no::text,
            :data_seed
        )::numeric), 100)::integer AS dado_estado,
        s.proximo_mes
    FROM seleccionados AS s
    JOIN clientes AS c
      ON c.rn = 1 + mod(s.seed_no - 1, c.total)
)
INSERT INTO seed_reservas_stg
    (seed_no, reservacion_id, cliente_id, mesa_id, inicio, duracion, cantidad, estado)
SELECT
    r.seed_no,
    nextval('public.reservaciones_id_seq')::integer,
    r.cliente_id,
    r.mesa_id,
    r.inicio,
    r.duracion,
    r.cantidad,
    CASE
        WHEN r.inicio < LOCALTIMESTAMP THEN
            CASE WHEN r.dado_estado < 8 THEN 4 ELSE 3 END
        WHEN r.inicio::date < r.proximo_mes THEN
            CASE
                WHEN r.dado_estado < 4  THEN 4
                WHEN r.dado_estado < 20 THEN 1
                ELSE 2
            END
        ELSE
            CASE
                WHEN r.dado_estado < 4  THEN 4
                WHEN r.dado_estado < 45 THEN 1
                ELSE 2
            END
    END AS estado
FROM reservas_calculadas AS r;

INSERT INTO public.reservaciones (id, cliente_id, cantidad, estado)
SELECT reservacion_id, cliente_id, cantidad, estado
FROM seed_reservas_stg
ORDER BY reservacion_id;

INSERT INTO public.horarios (mesa_id, reservacion_id, inicio, duracion)
SELECT mesa_id, reservacion_id, inicio, duracion
FROM seed_reservas_stg
ORDER BY inicio, mesa_id;

/* ---------------------------------------------------------------------------
   5. PEDIDOS, ORDENES Y PREPARACIONES

   El dump no incluye reservacion_id en pedidos. Para conservar coherencia, se
   crea exactamente un pedido por reserva completada y la hora se conserva en
   ordenes.solicitado. Una futura mejora del modelo deberia agregar esa FK.
--------------------------------------------------------------------------- */

CREATE TEMP TABLE seed_pedidos_stg (
    pedido_id     integer PRIMARY KEY,
    reservacion_id integer NOT NULL UNIQUE,
    cliente_id    integer NOT NULL,
    mesero_id     integer NOT NULL,
    inicio        timestamp without time zone NOT NULL,
    comensales    integer NOT NULL
) ON COMMIT DROP;

-- La plantilla contiene 14 meseros, pero cada turno usa un subconjunto rotativo
-- de ocho. Asi se representan descansos y dias libres sin sobrecargar a quienes
-- se encuentran activos.
WITH meseros AS (
    SELECT
        usuario_id,
        row_number() OVER (ORDER BY seed_key) AS rn,
        count(*) OVER () AS total
    FROM seed_usuarios_stg
    WHERE rol_nombre = 'Mesero'
)
INSERT INTO seed_pedidos_stg
    (pedido_id, reservacion_id, cliente_id, mesero_id, inicio, comensales)
SELECT
    nextval('public.pedidos_id_seq')::integer,
    r.reservacion_id,
    r.cliente_id,
    m.usuario_id,
    r.inicio,
    r.cantidad
FROM seed_reservas_stg AS r
JOIN meseros AS m
  ON m.rn = 1 + mod(
      mod(
          abs(hashtextextended(
              'TURNO_MESEROS|' || r.inicio::date::text || '|' ||
              CASE
                  WHEN r.inicio::time < time '17:00' THEN 'ALMUERZO'
                  ELSE 'CENA'
              END,
              :data_seed
          )::numeric),
          m.total
      )
      + mod(r.seed_no - 1, least(m.total, 8)),
      m.total
  )
WHERE r.estado = 3
ORDER BY r.reservacion_id;

INSERT INTO public.pedidos (id, cliente_id, mesero_id)
SELECT pedido_id, cliente_id, mesero_id
FROM seed_pedidos_stg
ORDER BY pedido_id;

CREATE TEMP TABLE seed_ordenes_stg (
    orden_id  integer PRIMARY KEY,
    plato_id  integer NOT NULL,
    pedido_id integer NOT NULL,
    estado    integer NOT NULL,
    cantidad  integer NOT NULL,
    solicitado timestamp with time zone NOT NULL
) ON COMMIT DROP;

WITH catalogo_base AS (
    SELECT
        p.plato_id,
        CASE
            WHEN p.tipo_nombre = 'Entrada' THEN 'ENTRADA'
            WHEN p.tipo_nombre IN ('Hamburguesa','Perro caliente','Sandwich','Pollo')
                THEN 'PRINCIPAL'
            WHEN p.tipo_nombre = 'Acompanamiento' THEN 'ACOMPANAMIENTO'
            WHEN p.tipo_nombre = 'Bebida'         THEN 'BEBIDA'
            WHEN p.tipo_nombre = 'Postre'         THEN 'POSTRE'
            WHEN p.tipo_nombre = 'Coctel'         THEN 'COCTEL'
        END AS categoria
    FROM seed_platos_stg AS p
),
catalogo AS (
    SELECT
        plato_id,
        categoria,
        row_number() OVER (PARTITION BY categoria ORDER BY plato_id) AS rn,
        count(*) OVER (PARTITION BY categoria) AS total
    FROM catalogo_base
),
lineas AS (
    SELECT
        p.pedido_id,
        p.inicio,
        l.categoria,
        l.cantidad,
        l.linea_no
    FROM seed_pedidos_stg AS p
    CROSS JOIN LATERAL (
        VALUES
            ('PRINCIPAL'::text,       (p.comensales+1)/2,            1, true),
            ('PRINCIPAL'::text,       p.comensales/2,                2, p.comensales > 1),
            ('BEBIDA'::text,          p.comensales,                  3, true),
            ('ACOMPANAMIENTO'::text,  greatest(1,(p.comensales+1)/2),4, true),
            ('ENTRADA'::text,         greatest(1,(p.comensales+2)/3),5,
                mod(abs(hashtextextended(
                    'INCLUIR_ENTRADA|' || p.pedido_id::text, :data_seed
                )::numeric),100) < 28),
            ('POSTRE'::text,          greatest(1,(p.comensales+1)/2),6,
                mod(abs(hashtextextended(
                    'INCLUIR_POSTRE|' || p.pedido_id::text, :data_seed
                )::numeric),100) < 48),
            ('COCTEL'::text,          1,                             7,
                mod(abs(hashtextextended(
                    'INCLUIR_COCTEL|' || p.pedido_id::text, :data_seed
                )::numeric),100) < 18)
    ) AS l(categoria, cantidad, linea_no, incluir)
    WHERE l.incluir
),
lineas_con_plato AS (
    SELECT
        l.*,
        c.plato_id
    FROM lineas AS l
    JOIN catalogo AS c
      ON c.categoria = l.categoria
     AND c.rn = 1 + mod(
         abs(hashtextextended(
             'SELECCION_PLATO|' || l.pedido_id::text || '|' ||
                 l.categoria || '|' || l.linea_no::text,
             :data_seed
         )::numeric),
         c.total
     )
)
INSERT INTO seed_ordenes_stg
    (orden_id, plato_id, pedido_id, estado, cantidad, solicitado)
SELECT
    nextval('public.ordenes_id_seq')::integer,
    l.plato_id,
    l.pedido_id,
    3,
    l.cantidad,
    (l.inicio AT TIME ZONE 'America/Bogota')
        + make_interval(mins => 5 + mod(
            abs(hashtextextended(
                'DEMORA_ORDEN|' || l.pedido_id::text || '|' || l.linea_no::text,
                :data_seed
            )::numeric),
            13
          )::integer)
FROM lineas_con_plato AS l
ORDER BY l.pedido_id, l.linea_no;

INSERT INTO public.ordenes
    (id, plato_id, pedido_id, estado, cantidad, solicitado)
SELECT orden_id, plato_id, pedido_id, estado, cantidad, solicitado
FROM seed_ordenes_stg
ORDER BY orden_id;

-- Cada turno rota ocho de los doce cocineros. La interseccion entre seis
-- especialistas por plato y ocho cocineros activos siempre deja al menos dos
-- personas habilitadas para ejecutar cada orden.
WITH cocineros AS (
    SELECT
        usuario_id,
        row_number() OVER (ORDER BY seed_key) AS rn,
        count(*) OVER () AS total
    FROM seed_usuarios_stg
    WHERE rol_nombre = 'Cocinero'
),
especialistas AS (
    SELECT
        e.plato_id,
        e.cocinero_id,
        c.rn,
        c.total
    FROM public.especialidades AS e
    JOIN cocineros AS c
      ON c.usuario_id = e.cocinero_id
),
candidatos AS (
    SELECT
        o.orden_id,
        e.cocinero_id,
        row_number() OVER (
            PARTITION BY o.orden_id
            ORDER BY abs(hashtextextended(
                'PREFERENCIA_COCINERO|' || o.orden_id::text || '|' ||
                    e.cocinero_id::text,
                :data_seed
            )::numeric)
        ) AS preferencia
    FROM seed_ordenes_stg AS o
    JOIN especialistas AS e
      ON e.plato_id = o.plato_id
    WHERE mod(
        (e.rn - 1)::numeric
            - mod(abs(hashtextextended(
                'TURNO_COCINEROS|' ||
                (o.solicitado AT TIME ZONE 'America/Bogota')::date::text || '|' ||
                CASE
                    WHEN (o.solicitado AT TIME ZONE 'America/Bogota')::time < time '17:00'
                        THEN 'ALMUERZO'
                    ELSE 'CENA'
                END,
                :data_seed
              )::numeric), e.total)
            + e.total,
        e.total
    ) < least(e.total, 8)
),
asignacion AS (
    SELECT orden_id, cocinero_id
    FROM candidatos
    WHERE preferencia = 1
)
INSERT INTO public.preparaciones (cocinero_id, orden_id)
SELECT cocinero_id, orden_id
FROM asignacion
ORDER BY orden_id;

/* ---------------------------------------------------------------------------
   6. VALIDACIONES DE NEGOCIO Y DE INTEGRIDAD
--------------------------------------------------------------------------- */

DO $validar_carga$
BEGIN
    IF (SELECT count(*) FROM seed_usuarios_stg WHERE rol_nombre = 'Cliente') <> 10000
       OR (SELECT count(*) FROM seed_usuarios_stg WHERE rol_nombre = 'Mesero') <> 14
       OR (SELECT count(*) FROM seed_usuarios_stg WHERE rol_nombre = 'Cocinero') <> 12
       OR (SELECT count(*) FROM seed_usuarios_stg WHERE rol_nombre = 'Maitre') <> 4 THEN
        RAISE EXCEPTION 'Validacion fallida: la proporcion de clientes o personal no coincide con la definida.';
    END IF;

    IF EXISTS (
        SELECT nombre
        FROM seed_usuarios_stg
        WHERE rol_nombre = 'Cliente'
        GROUP BY nombre
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: se generaron nombres de clientes duplicados.';
    END IF;

    IF EXISTS (
        SELECT p.id
        FROM public.platos AS p
        LEFT JOIN public.especialidades AS e ON e.plato_id = p.id
        GROUP BY p.id
        HAVING count(e.id) <> 6
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: un plato no tiene exactamente seis cocineros habilitados.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.reservaciones AS r
        LEFT JOIN public.horarios AS h ON h.reservacion_id = r.id
        GROUP BY r.id
        HAVING count(h.id) <> 1
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: una reservacion no tiene exactamente un horario.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.reservaciones AS r
        JOIN public.horarios AS h ON h.reservacion_id = r.id
        JOIN public.mesas AS m ON m.id = h.mesa_id
        WHERE r.cantidad > m.sillas
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: hay reservaciones que exceden la capacidad de la mesa.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.horarios AS h1
        JOIN public.reservaciones AS r1 ON r1.id = h1.reservacion_id
        JOIN public.horarios AS h2
          ON h1.mesa_id = h2.mesa_id
         AND h1.id < h2.id
         AND tsrange(h1.inicio, h1.inicio + h1.duracion, '[)')
             && tsrange(h2.inicio, h2.inicio + h2.duracion, '[)')
        JOIN public.reservaciones AS r2 ON r2.id = h2.reservacion_id
        WHERE r1.estado <> 4
          AND r2.estado <> 4
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: existen ocupaciones activas superpuestas para una misma mesa.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.reservaciones AS r1
        JOIN public.horarios AS h1 ON h1.reservacion_id = r1.id
        JOIN public.reservaciones AS r2
          ON r2.cliente_id = r1.cliente_id
         AND r2.id > r1.id
         AND r2.estado <> 4
        JOIN public.horarios AS h2
          ON h2.reservacion_id = r2.id
         AND tsrange(h1.inicio, h1.inicio + h1.duracion, '[)')
             && tsrange(h2.inicio, h2.inicio + h2.duracion, '[)')
        WHERE r1.estado <> 4
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: un cliente tiene reservaciones activas simultaneas.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.reservaciones AS r
        JOIN public.horarios AS h ON h.reservacion_id = r.id
        WHERE r.estado = 3 AND h.inicio >= LOCALTIMESTAMP
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: hay reservas futuras marcadas como completadas.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.pedidos AS p
        JOIN seed_pedidos_stg AS s ON s.pedido_id = p.id
        JOIN public.reservaciones AS r ON r.id = s.reservacion_id
        WHERE r.estado <> 3
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: se genero un pedido para una reserva no completada.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.pedidos AS p
        WHERE NOT EXISTS (
            SELECT 1
            FROM public.actuaciones AS a
            JOIN public.roles AS ro ON ro.id = a.rol_id
            WHERE a.usuario_id = p.mesero_id
              AND ro.nombre = 'Mesero'
        )
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: un pedido fue asignado a un usuario que no es mesero.';
    END IF;

    IF EXISTS (
        SELECT
            s.inicio::date,
            CASE WHEN s.inicio::time < time '17:00' THEN 'ALMUERZO' ELSE 'CENA' END
        FROM seed_pedidos_stg AS s
        GROUP BY
            s.inicio::date,
            CASE WHEN s.inicio::time < time '17:00' THEN 'ALMUERZO' ELSE 'CENA' END
        HAVING count(DISTINCT s.mesero_id) > 8
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: un turno utiliza mas de ocho meseros.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.preparaciones AS pr
        JOIN public.ordenes AS o ON o.id = pr.orden_id
        LEFT JOIN public.especialidades AS e
          ON e.cocinero_id = pr.cocinero_id
         AND e.plato_id = o.plato_id
        WHERE e.id IS NULL
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: un cocinero preparo un plato fuera de su especialidad.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.preparaciones AS pr
        WHERE NOT EXISTS (
            SELECT 1
            FROM public.actuaciones AS a
            JOIN public.roles AS ro ON ro.id = a.rol_id
            WHERE a.usuario_id = pr.cocinero_id
              AND ro.nombre = 'Cocinero'
        )
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: una preparacion fue asignada a un usuario que no es cocinero.';
    END IF;

    IF EXISTS (
        SELECT
            (o.solicitado AT TIME ZONE 'America/Bogota')::date,
            CASE
                WHEN (o.solicitado AT TIME ZONE 'America/Bogota')::time < time '17:00'
                    THEN 'ALMUERZO'
                ELSE 'CENA'
            END
        FROM public.preparaciones AS pr
        JOIN public.ordenes AS o ON o.id = pr.orden_id
        GROUP BY
            (o.solicitado AT TIME ZONE 'America/Bogota')::date,
            CASE
                WHEN (o.solicitado AT TIME ZONE 'America/Bogota')::time < time '17:00'
                    THEN 'ALMUERZO'
                ELSE 'CENA'
            END
        HAVING count(DISTINCT pr.cocinero_id) > 8
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: un turno utiliza mas de ocho cocineros.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.ordenes AS o
        LEFT JOIN public.preparaciones AS pr ON pr.orden_id = o.id
        GROUP BY o.id
        HAVING count(pr.id) <> 1
    ) THEN
        RAISE EXCEPTION 'Validacion fallida: una orden no tiene exactamente una preparacion.';
    END IF;
END
$validar_carga$;

-- Resumen que psql mostrara al finalizar correctamente.
SELECT
    (SELECT count(*) FROM public.usuarios)       AS usuarios,
    (SELECT count(*) FROM public.mesas)          AS mesas,
    (SELECT count(*) FROM public.platos)         AS platos,
    (SELECT count(*) FROM public.reservaciones)  AS reservaciones,
    (SELECT count(*) FROM public.pedidos)        AS pedidos,
    (SELECT count(*) FROM public.ordenes)        AS ordenes,
    (SELECT min(inicio)::date FROM public.horarios) AS primera_reserva,
    (SELECT max(inicio)::date FROM public.horarios) AS ultima_reserva;

SELECT
    r.estado,
    CASE r.estado
        WHEN 1 THEN 'pendiente'
        WHEN 2 THEN 'confirmada'
        WHEN 3 THEN 'completada'
        WHEN 4 THEN 'cancelada'
    END AS descripcion,
    count(*) AS cantidad
FROM public.reservaciones AS r
GROUP BY r.estado
ORDER BY r.estado;

SELECT
    ro.nombre AS rol,
    count(*) AS usuarios
FROM public.actuaciones AS a
JOIN public.roles AS ro ON ro.id = a.rol_id
GROUP BY ro.nombre
ORDER BY ro.nombre;

-- Credenciales de demostracion. Cualquier usuario puede autenticarse con la
-- misma clave siempre que la aplicacion compare el SHA-256 recibido con clave.
SELECT
    count(*) AS usuarios_totales,
    count(*) FILTER (
        WHERE clave = sha256(convert_to(:'test_password', 'UTF8'))
    ) AS usuarios_con_clave_valida,
    :'test_password' AS clave_comun_de_prueba
FROM public.usuarios;

SELECT DISTINCT ON (ro.nombre)
    ro.nombre AS rol,
    u.id AS usuario_id,
    u.nombre AS usuario_ejemplo,
    :'test_password' AS clave_prueba
FROM public.usuarios AS u
JOIN public.actuaciones AS a ON a.usuario_id = u.id
JOIN public.roles AS ro ON ro.id = a.rol_id
ORDER BY ro.nombre, u.id;

COMMIT;

ANALYZE public.usuarios;
ANALYZE public.reservaciones;
ANALYZE public.horarios;
ANALYZE public.pedidos;
ANALYZE public.ordenes;
