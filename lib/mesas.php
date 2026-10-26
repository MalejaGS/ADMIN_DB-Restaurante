<?php
function accion_mesa_guardar($conn)
{
    exigir('Administrador');
    $id = ($_POST['id'] ?? '') !== '' ? entero($_POST['id'], 'Mesa') : 0;
    $sillas = entero($_POST['sillas'] ?? null, 'Asientos', 1);
    if ($sillas > 100) throw new DomainException('El número de asientos no puede superar 100 por mesa.');

    if ($id > 0) {
        $m = fila($conn, 'SELECT id, sillas FROM mesas WHERE id=$1 FOR UPDATE', [$id]);
        if (!$m) throw new DomainException('Mesa inexistente.');

        // Validar que ninguna reserva futura confirmada o pendiente para esta mesa requiera más sillas que la nueva capacidad
        $excede = fila($conn, "SELECT r.id, r.cantidad FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE h.mesa_id=$1 AND r.estado IN (1,2) AND h.inicio >= LOCALTIMESTAMP AND r.cantidad > $2 LIMIT 1", [$id, $sillas]);
        if ($excede) {
            throw new DomainException('No se puede reducir la capacidad a ' . $sillas . ' asientos porque la reserva #' . $excede['id'] . ' requiere ' . $excede['cantidad'] . ' puestos.');
        }

        db($conn, 'UPDATE mesas SET sillas=$1 WHERE id=$2', [$sillas, $id]);
    } else {
        $numeroMesa = trim($_POST['numero_mesa'] ?? '');
        if ($numeroMesa !== '') {
            $nuevoId = entero($numeroMesa, 'Número de mesa', 1);
            if (fila($conn, 'SELECT id FROM mesas WHERE id=$1', [$nuevoId])) {
                throw new DomainException('Ya existe una mesa con el número ' . $nuevoId . '.');
            }
            db($conn, 'INSERT INTO mesas(id, sillas) VALUES($1, $2)', [$nuevoId, $sillas]);
            db($conn, "SELECT setval('mesas_id_seq', (SELECT GREATEST(max(id), nextval('mesas_id_seq')) FROM mesas))");
            $id = $nuevoId;
        } else {
            $r = fila($conn, 'INSERT INTO mesas(sillas) VALUES($1) RETURNING id', [$sillas]);
            $id = (int)$r['id'];
        }
    }
    return url('gestion_mesas', ['id' => $id]);
}

function accion_mesa_eliminar($conn)
{
    exigir('Administrador');
    $id = entero($_POST['id'] ?? null, 'Mesa');
    $m = fila($conn, 'SELECT id FROM mesas WHERE id=$1 FOR UPDATE', [$id]);
    if (!$m) throw new DomainException('Mesa inexistente.');

    // Verificar si tiene reservaciones vigentes o futuras
    $activa = fila($conn, "SELECT r.id FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE h.mesa_id=$1 AND r.estado IN (1,2) AND h.inicio >= LOCALTIMESTAMP - interval '2 hours' LIMIT 1", [$id]);
    if ($activa) throw new DomainException('No se puede eliminar la mesa: tiene reservaciones vigentes o próximas.');

    // Verificar si tiene pedidos en curso
    $pedidoActivo = fila($conn, "SELECT p.id FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id WHERE p.mesa_id=$1 AND o.estado IN (1,2,4) LIMIT 1", [$id]);
    if ($pedidoActivo) throw new DomainException('No se puede eliminar la mesa: tiene pedidos en preparación o listos.');

    // Verificar referencias históricas de clave foránea
    $hist = fila($conn, "SELECT (SELECT count(*) FROM horarios WHERE mesa_id=$1) AS h, (SELECT count(*) FROM pedidos WHERE mesa_id=$1) AS p", [$id]);
    if ((int)$hist['h'] > 0 || (int)$hist['p'] > 0) {
        throw new DomainException('No se puede eliminar la mesa porque tiene historial registrado de ' . (int)$hist['h'] . ' horarios y ' . (int)$hist['p'] . ' pedidos (requerido para integridad histórica).');
    }

    db($conn, 'DELETE FROM mesas WHERE id=$1', [$id]);
    return url('gestion_mesas');
}

function fn_gestion_mesas($conn)
{
    exigir('Administrador');
    $cupo = fila($conn, 'SELECT count(*) AS total_mesas, COALESCE(sum(sillas),0) AS total_sillas FROM mesas');

    $s = '<h1>Gestión de Mesas</h1>';
    $s .= '<p><strong>Cupo total del restaurante:</strong> ' . (int)$cupo['total_sillas'] . ' asientos distribuidos en ' . (int)$cupo['total_mesas'] . ' mesas.</p>';

    $m = null;
    if (isset($_GET['id'])) {
        $editId = entero($_GET['id'], 'Mesa');
        $m = fila($conn, 'SELECT * FROM mesas WHERE id=$1', [$editId]);
        if (!$m) throw new DomainException('Mesa inexistente.');
    }

    if ($m) {
        $s .= '<h2>Modificar Mesa #' . (int)$m['id'] . '</h2>';
        $formularioEdicion = oculto('id', $m['id'])
            . campo('sillas', 'Número de asientos (sillas)', $m['sillas'], 'number', 'min="1" max="100" required');
        $s .= formulario('mesa_guardar', $formularioEdicion, 'Guardar cambios');
        $s .= '<p>' . enlace('Cancelar edición y crear nueva mesa', 'gestion_mesas') . '</p>';
    } else {
        $s .= '<h2>Agregar Nueva Mesa</h2>';
        $formularioCreacion = campo('numero_mesa', 'Número de mesa (opcional, automático si está vacío)', '', 'number', 'min="1"')
            . campo('sillas', 'Número de asientos (sillas)', '4', 'number', 'min="1" max="100" required');
        $s .= formulario('mesa_guardar', $formularioCreacion, 'Crear mesa');
    }

    // Buscador y listado de mesas
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $s .= '<h2>Listado de Mesas</h2>';
    $s .= '<form method="get">' . oculto('opcion', 'gestion_mesas')
        . campo('q', 'Buscar por número de mesa o sillas', $q, 'text')
        . '<button type="submit">Buscar</button></form>';

    $filtroSql = 'TRUE';
    $params = [];
    if ($q !== '') {
        $filtroSql = '(m.id::text ILIKE $1 OR m.sillas::text ILIKE $1)';
        $params[] = '%' . $q . '%';
    }

    $mesas = filas($conn, "SELECT m.id, m.sillas FROM mesas m WHERE $filtroSql ORDER BY m.id LIMIT 100", $params);

    $reservasHoyMap = [];
    if (!empty($mesas)) {
        $mesaIds = array_column($mesas, 'id');
        $hoyRows = filas($conn, "SELECT h.mesa_id, count(*) AS n FROM horarios h JOIN reservaciones r ON r.id=h.reservacion_id WHERE h.mesa_id = ANY($1::int[]) AND r.estado IN (1,2) AND h.inicio >= CURRENT_DATE AND h.inicio < CURRENT_DATE + interval '1 day' GROUP BY h.mesa_id", ['{' . implode(',', $mesaIds) . '}']);
        foreach ($hoyRows as $hr) {
            $reservasHoyMap[(int)$hr['mesa_id']] = (int)$hr['n'];
        }
    }

    $tablaDatos = [];
    foreach ($mesas as $mesa) {
        $acciones = enlace('Modificar', 'gestion_mesas', ['id' => $mesa['id']]);
        $acciones .= formulario('mesa_eliminar', oculto('id', $mesa['id']), 'Eliminar');
        $tablaDatos[] = [
            h($mesa['id']),
            h($mesa['sillas']),
            h($reservasHoyMap[(int)$mesa['id']] ?? 0),
            $acciones
        ];
    }

    $s .= tabla(['Mesa #', 'Asientos', 'Reservas hoy', 'Acciones'], $tablaDatos);
    $s .= '<p>Se muestran hasta 100 mesas. Use el buscador para consultar mesas específicas.</p>';

    return $s;
}
