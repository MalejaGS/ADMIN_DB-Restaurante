--
-- PostgreSQL database dump
--

\restrict B0TSV9KRajCj3NuHFU8giE5LpgJiMNtZkxC4bZp2DLJEGXcbAq3uCfRKgUHshlV

-- Dumped from database version 18.4
-- Dumped by pg_dump version 18.4

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: actuaciones; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.actuaciones (
    id integer NOT NULL,
    rol_id integer NOT NULL,
    usuario_id integer NOT NULL
);


ALTER TABLE public.actuaciones OWNER TO admin_db;

--
-- Name: actuaciones_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.actuaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.actuaciones_id_seq OWNER TO admin_db;

--
-- Name: actuaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.actuaciones_id_seq OWNED BY public.actuaciones.id;


--
-- Name: especialidades; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.especialidades (
    id integer NOT NULL,
    cocinero_id integer NOT NULL,
    plato_id integer NOT NULL
);


ALTER TABLE public.especialidades OWNER TO admin_db;

--
-- Name: especialidades_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.especialidades_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.especialidades_id_seq OWNER TO admin_db;

--
-- Name: especialidades_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.especialidades_id_seq OWNED BY public.especialidades.id;


--
-- Name: horarios; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.horarios (
    id integer NOT NULL,
    mesa_id integer NOT NULL,
    reservacion_id integer NOT NULL,
    inicio timestamp without time zone NOT NULL,
    duracion interval NOT NULL,
    CONSTRAINT ck_horarios_duracion CHECK (((duracion > '00:00:00'::interval) AND (duracion < '02:00:00'::interval)))
);


ALTER TABLE public.horarios OWNER TO admin_db;

--
-- Name: horarios_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.horarios_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.horarios_id_seq OWNER TO admin_db;

--
-- Name: horarios_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.horarios_id_seq OWNED BY public.horarios.id;


--
-- Name: mesas; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.mesas (
    id integer NOT NULL,
    sillas integer NOT NULL,
    CONSTRAINT ck_mesas_sillas_positivas CHECK ((sillas > 0))
);


ALTER TABLE public.mesas OWNER TO admin_db;

--
-- Name: mesas_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.mesas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.mesas_id_seq OWNER TO admin_db;

--
-- Name: mesas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.mesas_id_seq OWNED BY public.mesas.id;


--
-- Name: ordenes; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.ordenes (
    id integer NOT NULL,
    plato_id integer NOT NULL,
    pedido_id integer NOT NULL,
    estado integer DEFAULT 1 NOT NULL,
    cantidad integer NOT NULL,
    solicitado timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ck_ordenes_cantidad_positiva CHECK ((cantidad > 0)),
    CONSTRAINT ck_ordenes_estado CHECK ((estado = ANY (ARRAY[1, 2, 3])))
);


ALTER TABLE public.ordenes OWNER TO admin_db;

--
-- Name: ordenes_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.ordenes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ordenes_id_seq OWNER TO admin_db;

--
-- Name: ordenes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.ordenes_id_seq OWNED BY public.ordenes.id;


--
-- Name: pedidos; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.pedidos (
    id integer NOT NULL,
    cliente_id integer NOT NULL,
    mesero_id integer NOT NULL
);


ALTER TABLE public.pedidos OWNER TO admin_db;

--
-- Name: pedidos_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.pedidos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pedidos_id_seq OWNER TO admin_db;

--
-- Name: pedidos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.pedidos_id_seq OWNED BY public.pedidos.id;


--
-- Name: platos; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.platos (
    id integer NOT NULL,
    tipo_id integer NOT NULL,
    nombre text NOT NULL,
    descripcion text NOT NULL,
    tiempo interval NOT NULL,
    precio numeric NOT NULL,
    CONSTRAINT ck_platos_precio_no_negativo CHECK ((precio >= (0)::numeric)),
    CONSTRAINT ck_platos_tiempo_no_negativo CHECK ((tiempo >= '00:00:00'::interval))
);


ALTER TABLE public.platos OWNER TO admin_db;

--
-- Name: platos_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.platos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.platos_id_seq OWNER TO admin_db;

--
-- Name: platos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.platos_id_seq OWNED BY public.platos.id;


--
-- Name: preparaciones; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.preparaciones (
    id integer NOT NULL,
    cocinero_id integer NOT NULL,
    orden_id integer NOT NULL
);


ALTER TABLE public.preparaciones OWNER TO admin_db;

--
-- Name: preparaciones_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.preparaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.preparaciones_id_seq OWNER TO admin_db;

--
-- Name: preparaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.preparaciones_id_seq OWNED BY public.preparaciones.id;


--
-- Name: reservaciones; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.reservaciones (
    id integer NOT NULL,
    cliente_id integer NOT NULL,
    cantidad integer NOT NULL,
    estado integer NOT NULL,
    CONSTRAINT ck_reservaciones_cantidad_positiva CHECK ((cantidad > 0)),
    CONSTRAINT ck_reservaciones_estado CHECK ((estado = ANY (ARRAY[1, 2, 3, 4])))
);


ALTER TABLE public.reservaciones OWNER TO admin_db;

--
-- Name: reservaciones_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.reservaciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.reservaciones_id_seq OWNER TO admin_db;

--
-- Name: reservaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.reservaciones_id_seq OWNED BY public.reservaciones.id;


--
-- Name: roles; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.roles (
    id integer NOT NULL,
    nombre text NOT NULL
);


ALTER TABLE public.roles OWNER TO admin_db;

--
-- Name: roles_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.roles_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.roles_id_seq OWNER TO admin_db;

--
-- Name: roles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.roles_id_seq OWNED BY public.roles.id;


--
-- Name: tipos; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.tipos (
    id integer NOT NULL,
    nombre text NOT NULL
);


ALTER TABLE public.tipos OWNER TO admin_db;

--
-- Name: tipos_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.tipos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.tipos_id_seq OWNER TO admin_db;

--
-- Name: tipos_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.tipos_id_seq OWNED BY public.tipos.id;


--
-- Name: usuarios; Type: TABLE; Schema: public; Owner: admin_db
--

CREATE TABLE public.usuarios (
    id integer NOT NULL,
    nombre text NOT NULL,
    clave bytea NOT NULL,
    fecha_clave timestamp without time zone NOT NULL
);


ALTER TABLE public.usuarios OWNER TO admin_db;

--
-- Name: usuarios_id_seq; Type: SEQUENCE; Schema: public; Owner: admin_db
--

CREATE SEQUENCE public.usuarios_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.usuarios_id_seq OWNER TO admin_db;

--
-- Name: usuarios_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: admin_db
--

ALTER SEQUENCE public.usuarios_id_seq OWNED BY public.usuarios.id;


--
-- Name: actuaciones id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.actuaciones ALTER COLUMN id SET DEFAULT nextval('public.actuaciones_id_seq'::regclass);


--
-- Name: especialidades id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.especialidades ALTER COLUMN id SET DEFAULT nextval('public.especialidades_id_seq'::regclass);


--
-- Name: horarios id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.horarios ALTER COLUMN id SET DEFAULT nextval('public.horarios_id_seq'::regclass);


--
-- Name: mesas id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.mesas ALTER COLUMN id SET DEFAULT nextval('public.mesas_id_seq'::regclass);


--
-- Name: ordenes id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.ordenes ALTER COLUMN id SET DEFAULT nextval('public.ordenes_id_seq'::regclass);


--
-- Name: pedidos id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.pedidos ALTER COLUMN id SET DEFAULT nextval('public.pedidos_id_seq'::regclass);


--
-- Name: platos id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.platos ALTER COLUMN id SET DEFAULT nextval('public.platos_id_seq'::regclass);


--
-- Name: preparaciones id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.preparaciones ALTER COLUMN id SET DEFAULT nextval('public.preparaciones_id_seq'::regclass);


--
-- Name: reservaciones id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.reservaciones ALTER COLUMN id SET DEFAULT nextval('public.reservaciones_id_seq'::regclass);


--
-- Name: roles id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.roles ALTER COLUMN id SET DEFAULT nextval('public.roles_id_seq'::regclass);


--
-- Name: tipos id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.tipos ALTER COLUMN id SET DEFAULT nextval('public.tipos_id_seq'::regclass);


--
-- Name: usuarios id; Type: DEFAULT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.usuarios ALTER COLUMN id SET DEFAULT nextval('public.usuarios_id_seq'::regclass);


--
-- Data for Name: actuaciones; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.actuaciones (id, rol_id, usuario_id) FROM stdin;
\.


--
-- Data for Name: especialidades; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.especialidades (id, cocinero_id, plato_id) FROM stdin;
\.


--
-- Data for Name: horarios; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.horarios (id, mesa_id, reservacion_id, inicio, duracion) FROM stdin;
\.


--
-- Data for Name: mesas; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.mesas (id, sillas) FROM stdin;
\.


--
-- Data for Name: ordenes; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.ordenes (id, plato_id, pedido_id, estado, cantidad, solicitado) FROM stdin;
\.


--
-- Data for Name: pedidos; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.pedidos (id, cliente_id, mesero_id) FROM stdin;
\.


--
-- Data for Name: platos; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.platos (id, tipo_id, nombre, descripcion, tiempo, precio) FROM stdin;
\.


--
-- Data for Name: preparaciones; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.preparaciones (id, cocinero_id, orden_id) FROM stdin;
\.


--
-- Data for Name: reservaciones; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.reservaciones (id, cliente_id, cantidad, estado) FROM stdin;
\.


--
-- Data for Name: roles; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.roles (id, nombre) FROM stdin;
\.


--
-- Data for Name: tipos; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.tipos (id, nombre) FROM stdin;
\.


--
-- Data for Name: usuarios; Type: TABLE DATA; Schema: public; Owner: admin_db
--

COPY public.usuarios (id, nombre, clave, fecha_clave) FROM stdin;
\.


--
-- Name: actuaciones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.actuaciones_id_seq', 1, false);


--
-- Name: especialidades_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.especialidades_id_seq', 1, false);


--
-- Name: horarios_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.horarios_id_seq', 1, false);


--
-- Name: mesas_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.mesas_id_seq', 1, false);


--
-- Name: ordenes_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.ordenes_id_seq', 1, false);


--
-- Name: pedidos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.pedidos_id_seq', 1, false);


--
-- Name: platos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.platos_id_seq', 1, false);


--
-- Name: preparaciones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.preparaciones_id_seq', 1, false);


--
-- Name: reservaciones_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.reservaciones_id_seq', 1, false);


--
-- Name: roles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.roles_id_seq', 1, false);


--
-- Name: tipos_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.tipos_id_seq', 1, false);


--
-- Name: usuarios_id_seq; Type: SEQUENCE SET; Schema: public; Owner: admin_db
--

SELECT pg_catalog.setval('public.usuarios_id_seq', 1, false);


--
-- Name: actuaciones actuaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.actuaciones
    ADD CONSTRAINT actuaciones_pkey PRIMARY KEY (id);


--
-- Name: especialidades especialidades_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.especialidades
    ADD CONSTRAINT especialidades_pkey PRIMARY KEY (id);


--
-- Name: horarios horarios_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.horarios
    ADD CONSTRAINT horarios_pkey PRIMARY KEY (id);


--
-- Name: mesas mesas_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.mesas
    ADD CONSTRAINT mesas_pkey PRIMARY KEY (id);


--
-- Name: ordenes ordenes_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.ordenes
    ADD CONSTRAINT ordenes_pkey PRIMARY KEY (id);


--
-- Name: pedidos pedidos_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.pedidos
    ADD CONSTRAINT pedidos_pkey PRIMARY KEY (id);


--
-- Name: platos platos_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.platos
    ADD CONSTRAINT platos_pkey PRIMARY KEY (id);


--
-- Name: preparaciones preparaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.preparaciones
    ADD CONSTRAINT preparaciones_pkey PRIMARY KEY (id);


--
-- Name: reservaciones reservaciones_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.reservaciones
    ADD CONSTRAINT reservaciones_pkey PRIMARY KEY (id);


--
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (id);


--
-- Name: tipos tipos_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.tipos
    ADD CONSTRAINT tipos_pkey PRIMARY KEY (id);


--
-- Name: actuaciones uq_actuaciones_rol_usuario; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.actuaciones
    ADD CONSTRAINT uq_actuaciones_rol_usuario UNIQUE (rol_id, usuario_id);


--
-- Name: especialidades uq_especialidades_cocinero_plato; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.especialidades
    ADD CONSTRAINT uq_especialidades_cocinero_plato UNIQUE (cocinero_id, plato_id);


--
-- Name: platos uq_platos_nombre; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.platos
    ADD CONSTRAINT uq_platos_nombre UNIQUE (nombre);


--
-- Name: preparaciones uq_preparaciones_orden; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.preparaciones
    ADD CONSTRAINT uq_preparaciones_orden UNIQUE (orden_id);


--
-- Name: roles uq_roles_nombre; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.roles
    ADD CONSTRAINT uq_roles_nombre UNIQUE (nombre);


--
-- Name: tipos uq_tipos_nombre; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.tipos
    ADD CONSTRAINT uq_tipos_nombre UNIQUE (nombre);


--
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (id);


--
-- Name: actuaciones fk_actuaciones_rol; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.actuaciones
    ADD CONSTRAINT fk_actuaciones_rol FOREIGN KEY (rol_id) REFERENCES public.roles(id);


--
-- Name: actuaciones fk_actuaciones_usuario; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.actuaciones
    ADD CONSTRAINT fk_actuaciones_usuario FOREIGN KEY (usuario_id) REFERENCES public.usuarios(id);


--
-- Name: especialidades fk_especialidades_cocinero; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.especialidades
    ADD CONSTRAINT fk_especialidades_cocinero FOREIGN KEY (cocinero_id) REFERENCES public.usuarios(id);


--
-- Name: especialidades fk_especialidades_plato; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.especialidades
    ADD CONSTRAINT fk_especialidades_plato FOREIGN KEY (plato_id) REFERENCES public.platos(id);


--
-- Name: horarios fk_horarios_mesa; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.horarios
    ADD CONSTRAINT fk_horarios_mesa FOREIGN KEY (mesa_id) REFERENCES public.mesas(id);


--
-- Name: horarios fk_horarios_reservacion; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.horarios
    ADD CONSTRAINT fk_horarios_reservacion FOREIGN KEY (reservacion_id) REFERENCES public.reservaciones(id);


--
-- Name: ordenes fk_ordenes_pedido; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.ordenes
    ADD CONSTRAINT fk_ordenes_pedido FOREIGN KEY (pedido_id) REFERENCES public.pedidos(id);


--
-- Name: ordenes fk_ordenes_plato; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.ordenes
    ADD CONSTRAINT fk_ordenes_plato FOREIGN KEY (plato_id) REFERENCES public.platos(id);


--
-- Name: pedidos fk_pedidos_cliente; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.pedidos
    ADD CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id) REFERENCES public.usuarios(id);


--
-- Name: pedidos fk_pedidos_mesero; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.pedidos
    ADD CONSTRAINT fk_pedidos_mesero FOREIGN KEY (mesero_id) REFERENCES public.usuarios(id);


--
-- Name: platos fk_platos_tipo; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.platos
    ADD CONSTRAINT fk_platos_tipo FOREIGN KEY (tipo_id) REFERENCES public.tipos(id);


--
-- Name: preparaciones fk_preparaciones_cocinero; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.preparaciones
    ADD CONSTRAINT fk_preparaciones_cocinero FOREIGN KEY (cocinero_id) REFERENCES public.usuarios(id);


--
-- Name: preparaciones fk_preparaciones_orden; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.preparaciones
    ADD CONSTRAINT fk_preparaciones_orden FOREIGN KEY (orden_id) REFERENCES public.ordenes(id);


--
-- Name: reservaciones fk_reservaciones_cliente; Type: FK CONSTRAINT; Schema: public; Owner: admin_db
--

ALTER TABLE ONLY public.reservaciones
    ADD CONSTRAINT fk_reservaciones_cliente FOREIGN KEY (cliente_id) REFERENCES public.usuarios(id);


--
-- PostgreSQL database dump complete
--

\unrestrict B0TSV9KRajCj3NuHFU8giE5LpgJiMNtZkxC4bZp2DLJEGXcbAq3uCfRKgUHshlV

