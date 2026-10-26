<?php
function accion_empleado_guardar($conn)
{
    exigir('Administrador');
    $id = ($_POST['id'] ?? '') !== '' ? entero($_POST['id'], 'Empleado') : 0;
    $nombre = texto($_POST['nombre'] ?? '', 'Nombre', 150);

    $rolesValidos = ['Administrador', 'Maitre', 'Mesero', 'Cocinero'];
    $rolesSeleccionados = array_values(array_intersect($rolesValidos, (array)($_POST['roles'] ?? [])));
    if (empty($rolesSeleccionados)) {
        throw new DomainException('Debe asignar al menos un rol de empleado al usuario.');
    }

    $rolesDb = filas($conn, "SELECT id, nombre FROM roles WHERE nombre = ANY($1::text[])", ['{' . implode(',', $rolesValidos) . '}']);
    $mapaRoles = [];
    foreach ($rolesDb as $r) {
        $mapaRoles[$r['nombre']] = (int)$r['id'];
    }

    if ($id > 0) {
        $u = fila($conn, 'SELECT id, activo, nombre FROM usuarios WHERE id=$1 FOR UPDATE', [$id]);
        if (!$u) throw new DomainException('Empleado inexistente.');

        // Evitar que el administrador actual se quite sus propios privilegios o se desactive
        if ($id === (int)$_SESSION['usuario_id']) {
            if (!in_array('Administrador', $rolesSeleccionados, true)) {
                throw new DomainException('No puede retirarse a sí mismo el rol de Administrador.');
            }
            if (!isset($_POST['activo'])) {
                throw new DomainException('No puede desactivar su propia cuenta de usuario.');
            }
        }

        $activo = isset($_POST['activo']) ? 't' : 'f';
        $claveNueva = trim($_POST['clave'] ?? '');
        if ($claveNueva !== '') {
            if (strlen($claveNueva) < 6) throw new DomainException('La nueva contraseña debe tener al menos 6 caracteres.');
            $hash = bin2hex(password_hash($claveNueva, PASSWORD_DEFAULT));
            db($conn, "UPDATE usuarios SET nombre=$1, activo=$2, clave=decode($3,'hex'), fecha_clave=LOCALTIMESTAMP WHERE id=$4", [$nombre, $activo, $hash, $id]);
        } else {
            db($conn, "UPDATE usuarios SET nombre=$1, activo=$2 WHERE id=$3", [$nombre, $activo, $id]);
        }

        // Sincronizar roles en actuaciones
        $actuacionesActuales = array_column(filas($conn, 'SELECT r.nombre FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=$1', [$id]), 'nombre');
        foreach ($rolesValidos as $rolNombre) {
            $tieneRol = in_array($rolNombre, $rolesSeleccionados, true);
            $teniaRol = in_array($rolNombre, $actuacionesActuales, true);
            if ($tieneRol && !$teniaRol) {
                db($conn, 'INSERT INTO actuaciones(usuario_id, rol_id) VALUES($1, $2) ON CONFLICT DO NOTHING', [$id, $mapaRoles[$rolNombre]]);
            } elseif (!$tieneRol && $teniaRol) {
                db($conn, 'DELETE FROM actuaciones WHERE usuario_id=$1 AND rol_id=$2', [$id, $mapaRoles[$rolNombre]]);
            }
        }

        // Gestión de platos que sabe cocinar si es Cocinero
        if (in_array('Cocinero', $rolesSeleccionados, true)) {
            $platosInput = array_map('intval', (array)($_POST['platos'] ?? []));
            $platosValidos = [];
            if (!empty($platosInput)) {
                $platosDb = filas($conn, "SELECT id FROM platos WHERE id = ANY($1::int[])", ['{' . implode(',', $platosInput) . '}']);
                $platosValidos = array_map('intval', array_column($platosDb, 'id'));
            }

            // Validar que no se desautorice un plato que el cocinero tiene actualmente en preparación
            $enCurso = filas($conn, 'SELECT pr.orden_id, o.plato_id, p.nombre FROM preparaciones pr JOIN ordenes o ON o.id=pr.orden_id JOIN platos p ON p.id=o.plato_id WHERE pr.cocinero_id=$1 AND o.estado=2', [$id]);
            foreach ($enCurso as $ec) {
                if (!in_array((int)$ec['plato_id'], $platosValidos, true)) {
                    throw new DomainException('No puede retirar la autorización del plato "' . $ec['nombre'] . '" porque tiene órdenes en preparación en este momento.');
                }
            }

            // Sincronizar especialidades
            if (!empty($platosValidos)) {
                db($conn, "DELETE FROM especialidades WHERE cocinero_id=$1 AND NOT (plato_id = ANY($2::int[]))", [$id, '{' . implode(',', $platosValidos) . '}']);
                foreach ($platosValidos as $pid) {
                    db($conn, 'INSERT INTO especialidades(cocinero_id, plato_id) VALUES($1, $2) ON CONFLICT(cocinero_id, plato_id) DO NOTHING', [$id, $pid]);
                }
            } else {
                db($conn, 'DELETE FROM especialidades WHERE cocinero_id=$1', [$id]);
            }
        } else {
            // Ya no es cocinero: validar si tenía preparaciones en curso
            $enCurso = fila($conn, 'SELECT pr.orden_id FROM preparaciones pr JOIN ordenes o ON o.id=pr.orden_id WHERE pr.cocinero_id=$1 AND o.estado=2 LIMIT 1', [$id]);
            if ($enCurso) {
                throw new DomainException('No puede retirar el rol de Cocinero mientras el empleado tenga órdenes en preparación.');
            }
            db($conn, 'DELETE FROM especialidades WHERE cocinero_id=$1', [$id]);
        }
    } else {
        $clave = $_POST['clave'] ?? '';
        if (!is_string($clave) || strlen(trim($clave)) < 6) {
            throw new DomainException('La contraseña inicial es obligatoria y debe tener al menos 6 caracteres.');
        }
        $hash = bin2hex(password_hash(trim($clave), PASSWORD_DEFAULT));
        $u = fila($conn, "INSERT INTO usuarios(nombre, clave, fecha_clave, activo) VALUES($1, decode($2,'hex'), LOCALTIMESTAMP, true) RETURNING id", [$nombre, $hash]);
        $id = (int)$u['id'];

        foreach ($rolesSeleccionados as $rolNombre) {
            db($conn, 'INSERT INTO actuaciones(usuario_id, rol_id) VALUES($1, $2)', [$id, $mapaRoles[$rolNombre]]);
        }

        if (in_array('Cocinero', $rolesSeleccionados, true)) {
            $platosInput = array_map('intval', (array)($_POST['platos'] ?? []));
            if (!empty($platosInput)) {
                $platosDb = filas($conn, "SELECT id FROM platos WHERE id = ANY($1::int[])", ['{' . implode(',', $platosInput) . '}']);
                foreach ($platosDb as $pdb) {
                    db($conn, 'INSERT INTO especialidades(cocinero_id, plato_id) VALUES($1, $2) ON CONFLICT DO NOTHING', [$id, (int)$pdb['id']]);
                }
            }
        }
    }

    return url('gestion_empleados', ['id' => $id]);
}

function fn_gestion_empleados($conn)
{
    exigir('Administrador');

    $s = '<h1>Gestión de Empleados</h1>';

    $emp = null;
    $empRoles = [];
    $empPlatos = [];
    if (isset($_GET['id'])) {
        $empId = entero($_GET['id'], 'Empleado');
        $emp = fila($conn, 'SELECT * FROM usuarios WHERE id=$1', [$empId]);
        if (!$emp) throw new DomainException('Empleado inexistente.');
        $empRoles = array_column(filas($conn, 'SELECT r.nombre FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=$1', [$empId]), 'nombre');
        if (in_array('Cocinero', $empRoles, true)) {
            $empPlatos = array_map('intval', array_column(filas($conn, 'SELECT plato_id FROM especialidades WHERE cocinero_id=$1', [$empId]), 'plato_id'));
        }
    }

    $rolesDisponibles = ['Administrador', 'Maitre', 'Mesero', 'Cocinero'];

    if ($emp) {
        $s .= '<h2>Modificar Empleado #' . (int)$emp['id'] . '</h2>';
        $formulario = oculto('id', $emp['id'])
            . campo('nombre', 'Nombre completo', $emp['nombre'], 'text', 'maxlength="150" required')
            . campo('clave', 'Nueva contraseña (dejar en blanco para conservar la actual)', '', 'password', 'minlength="6" autocomplete="new-password"')
            . '<label><input type="checkbox" name="activo" value="1"' . ($emp['activo'] === 't' ? ' checked' : '') . '> Empleado activo</label>'
            . '<fieldset><legend>Roles asignados</legend>';
        foreach ($rolesDisponibles as $rNombre) {
            $checked = in_array($rNombre, $empRoles, true) ? ' checked' : '';
            $formulario .= '<label class="CHECKBOX_GRUPO"><input type="checkbox" name="roles[]" value="' . h($rNombre) . '"' . $checked . '> ' . h($rNombre) . '</label>';
        }
        $formulario .= '</fieldset>';

        // Si tiene rol Cocinero, mostrar selector de platos que sabe preparar
        $todosPlatos = filas($conn, 'SELECT p.id, p.nombre, p.tipo_id, t.nombre AS categoria FROM platos p JOIN tipos t ON t.id=p.tipo_id WHERE p.activo ORDER BY t.nombre, p.nombre');
        $formulario .= '<fieldset><legend>Platos que sabe preparar (solo aplica si tiene rol Cocinero)</legend>';
        $formulario .= '<p>Seleccione los platos del menú autorizados para este cocinero:</p>';
        $catActual = null;
        foreach ($todosPlatos as $plato) {
            if ($catActual !== $plato['categoria']) {
                if ($catActual !== null) $formulario .= '<br>';
                $catActual = $plato['categoria'];
                $formulario .= '<strong>' . h($catActual) . ':</strong><br>';
            }
            $chkPlato = in_array((int)$plato['id'], $empPlatos, true) ? ' checked' : '';
            $formulario .= '<label class="CHECKBOX_GRUPO"><input type="checkbox" name="platos[]" value="' . (int)$plato['id'] . '"' . $chkPlato . '> ' . h($plato['nombre']) . '</label>';
        }
        $formulario .= '</fieldset>';

        $s .= formulario('empleado_guardar', $formulario, 'Guardar cambios del empleado');
        $s .= '<p>' . enlace('Cancelar edición y registrar nuevo empleado', 'gestion_empleados') . '</p>';
    } else {
        $s .= '<h2>Registrar Nuevo Empleado</h2>';
        $formulario = campo('nombre', 'Nombre completo', '', 'text', 'maxlength="150" required')
            . campo('clave', 'Contraseña inicial', '', 'password', 'minlength="6" required autocomplete="new-password"')
            . '<fieldset><legend>Roles asignados</legend>';
        foreach ($rolesDisponibles as $rNombre) {
            $formulario .= '<label class="CHECKBOX_GRUPO"><input type="checkbox" name="roles[]" value="' . h($rNombre) . '"> ' . h($rNombre) . '</label>';
        }
        $formulario .= '</fieldset>';

        $todosPlatos = filas($conn, 'SELECT p.id, p.nombre, p.tipo_id, t.nombre AS categoria FROM platos p JOIN tipos t ON t.id=p.tipo_id WHERE p.activo ORDER BY t.nombre, p.nombre');
        $formulario .= '<fieldset><legend>Platos que sabe preparar (si se selecciona rol Cocinero)</legend>';
        $catActual = null;
        foreach ($todosPlatos as $plato) {
            if ($catActual !== $plato['categoria']) {
                if ($catActual !== null) $formulario .= '<br>';
                $catActual = $plato['categoria'];
                $formulario .= '<strong>' . h($catActual) . ':</strong><br>';
            }
            $formulario .= '<label class="CHECKBOX_GRUPO"><input type="checkbox" name="platos[]" value="' . (int)$plato['id'] . '"> ' . h($plato['nombre']) . '</label>';
        }
        $formulario .= '</fieldset>';

        $s .= formulario('empleado_guardar', $formulario, 'Registrar empleado');
    }

    // Buscador y listado de empleados
    $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
    $rolFiltro = isset($_GET['rol']) && in_array($_GET['rol'], $rolesDisponibles, true) ? $_GET['rol'] : '';

    $s .= '<h2>Personal del Restaurante</h2>';
    $filtroHtml = '<form method="get">' . oculto('opcion', 'gestion_empleados')
        . campo('q', 'Buscar por nombre o ID', $q, 'text')
        . '<label>Filtrar por rol <select name="rol">'
        . '<option value="">Todos los roles</option>';
    foreach ($rolesDisponibles as $r) {
        $filtroHtml .= '<option value="' . h($r) . '"' . ($rolFiltro === $r ? ' selected' : '') . '>' . h($r) . '</option>';
    }
    $filtroHtml .= '</select></label><button type="submit">Filtrar</button></form>';
    $s .= $filtroHtml;

    $where = ["EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre IN ('Administrador','Maitre','Mesero','Cocinero'))"];
    $params = [];
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where[] = "(u.nombre ILIKE $" . count($params) . " OR u.id::text ILIKE $" . count($params) . ")";
    }
    if ($rolFiltro !== '') {
        $params[] = $rolFiltro;
        $where[] = "EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre=$" . count($params) . ")";
    }

    $whereSql = implode(' AND ', $where);
    $empleados = filas($conn, "SELECT u.id, u.nombre, u.activo,
        (SELECT string_agg(r.nombre, ', ' ORDER BY r.nombre) FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id) AS roles,
        (SELECT count(*) FROM especialidades e WHERE e.cocinero_id=u.id) AS especialidades_count
        FROM usuarios u WHERE $whereSql ORDER BY u.id LIMIT 100", $params);

    $tablaDatos = [];
    foreach ($empleados as $e) {
        $rolesStr = $e['roles'] ?? 'Ninguno';
        $infoPlatos = strpos($rolesStr, 'Cocinero') !== false ? (int)$e['especialidades_count'] . ' platos' : 'N/A';
        $estado = $e['activo'] === 't' ? 'Activo' : 'Inactivo';
        $tablaDatos[] = [
            h($e['id']),
            h($e['nombre']),
            h($rolesStr),
            h($estado),
            h($infoPlatos),
            enlace('Editar / Autorizar platos', 'gestion_empleados', ['id' => $e['id']])
        ];
    }

    $s .= tabla(['ID', 'Nombre', 'Roles', 'Estado', 'Platos autorizados', 'Acciones'], $tablaDatos);
    $s .= '<p>Se muestran hasta 100 empleados. Use los filtros para localizar personal específico.</p>';

    return $s;
}
