<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/pedidos.php';
require_once __DIR__.'/reservas.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/reportes.php';
require_once __DIR__.'/mesas.php';
require_once __DIR__.'/empleados.php';
function fn_boton_menu_principal() { return '<a href="index.php" class="BTN_SECUNDARIO">← Inicio</a>'; }
function fn_barra_navegacion()
{
    if (!isset($_SESSION['usuario_id'])) return '';
    $actual = $_GET['opcion'] ?? '';
    $items = [
        ['', 'Inicio', '🏠', true],
        ['desplegar_menu', 'Menú', '📋', true],
        ['realizar_pedidos', puede('Mesero') ? 'Sala & Pedidos' : 'Pedidos', '🍽️', puede('Mesero', 'Administrador')],
        ['reservas', 'Reservaciones', '📅', puede('Maitre', 'Administrador')],
        ['cocina', 'Cocina KDS', '👨‍🍳', puede('Cocinero')],
        ['gestion_mesas', 'Mesas', '🪑', puede('Administrador')],
        ['gestion_empleados', 'Personal', '👥', puede('Administrador')],
        ['gestion_menu', 'Carta', '📖', puede('Administrador')],
        ['reportes', 'Reportes', '📊', puede('Administrador')],
        ['clientes', 'Clientes', '👤', puede('Administrador', 'Maitre', 'Mesero')]
    ];
    
    $navLinks = '';
    foreach ($items as [$op, $label, $icon, $allowed]) {
        if (!$allowed) continue;
        $isActiva = ($actual === $op) || ($op === 'realizar_pedidos' && in_array($actual, ['pedido', 'factura_pedido'], true));
        $navLinks .= '<a href="'.h(url($op)).'" class="APP_NAV_LINK'.($isActiva ? ' NAV_ACTIVA' : '').'"><span class="NAV_ICON">'.$icon.'</span> <span class="NAV_TEXT">'.h($label).'</span></a>';
    }

    $rolBadge = '<span class="ROL_BADGE">'.h(implode(' · ', $_SESSION['roles'])).'</span>';
    $logoutBtn = formulario('logout', '', 'Cerrar sesión');

    return '
    <div class="SESION">
        <nav class="SESION_NAV" aria-label="Navegación principal">
            '.$navLinks.'
        </nav>
        <div class="SESION_USER">
            <span class="USER_INFO"><strong>'.h($_SESSION['nombre']).'</strong> '.$rolBadge.'</span>
            '.$logoutBtn.'
        </div>
    </div>';
}
function fn_menu_opciones($conn)
{
    $items=[
        ['desplegar_menu','Desplegar Menú',true],
        ['realizar_pedidos',puede('Mesero')?'Plano de Sala & Pedidos':'Pedidos',puede('Mesero','Administrador')],
        ['reservas','Reservaciones',puede('Maitre','Administrador')],
        ['gestion_mesas','Gestión de Mesas',puede('Administrador')],
        ['gestion_empleados','Gestión de Empleados',puede('Administrador')],
        ['gestion_menu','Actualizar Menú',puede('Administrador')],
        ['reportes','Reportes y Estadísticas',puede('Administrador')],
        ['cocina','Cocina KDS',puede('Cocinero')],
        ['clientes','Clientes e Historial',puede('Administrador','Maitre','Mesero')]
    ];
    $s='<h1>Panel Operativo · '.h(implode(', ',$_SESSION['roles'])).'</h1>';
    $s.='<div class="MENU_OPCIONES">';
    foreach($items as [$op,$label,$allowed]) if ($allowed) $s.='<button onclick="location.href=\''.h(url($op)).'\'">'.h($label).'</button>';
    $s.='</div>';

    // 1. Interfaz especializada para Mesero
    if (puede('Mesero')) {
        $listos=filas($conn,'SELECT DISTINCT p.id,p.mesa_id,u.nombre AS cliente FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id JOIN usuarios u ON u.id=p.cliente_id WHERE p.mesero_id=$1 AND o.estado=4 ORDER BY p.id',[$_SESSION['usuario_id']]);
        if ($listos) {
            $s.='<h2>🔔 Platos listos para entregar a la mesa</h2><div class="RESUMEN_CLIENTE">';
            foreach($listos as $l) {
                $s.='<div class="RESUMEN_CARD"><span>Mesa '.(int)$l['mesa_id'].' · '.h($l['cliente']).'</span><strong>'.enlace('Entregar Pedido #'.$l['id'],'pedido',['id'=>$l['id']]).'</strong></div>';
            }
            $s.='</div>';
        }
        $abiertos=filas($conn,"SELECT p.id,p.mesa_id,u.nombre AS cliente,p.creado,
            count(o.id) AS total_items,
            count(o.id) FILTER(WHERE o.estado=4) AS listos,
            count(o.id) FILTER(WHERE o.estado=3) AS entregados
            FROM pedidos p JOIN usuarios u ON u.id=p.cliente_id JOIN ordenes o ON o.pedido_id=p.id
            WHERE p.mesero_id=$1 GROUP BY p.id,p.mesa_id,u.nombre,p.creado HAVING count(o.id) > count(o.id) FILTER(WHERE o.estado=3)
            ORDER BY p.id DESC LIMIT 10",[$_SESSION['usuario_id']]);
        if ($abiertos) {
            $s.='<h2>Mis pedidos activos en sala</h2>';
            $dataMesero=[];
            foreach($abiertos as $ab) {
                $st = estado_pedido($conn, $ab['id']);
                $dataMesero[]=[enlace('#'.$ab['id'],'pedido',['id'=>$ab['id']]),h($ab['mesa_id']??'Sin mesa'),h($ab['cliente']),h($ab['total_items'].' ('.$ab['listos'].' listos)'),h($st),enlace('Atender / Agregar','pedido',['id'=>$ab['id']])];
            }
            $s.=tabla(['Pedido','Mesa','Cliente','Ítems','Estado','Acción'],$dataMesero);
        }
    }

    // 2. Interfaz especializada para Maitre
    if (puede('Maitre')) {
        $cupo=fila($conn,'SELECT count(*) AS mesas, COALESCE(sum(sillas),0) AS total_sillas FROM mesas');
        $resHoy=fila($conn,"SELECT count(DISTINCT r.id) AS total_hoy, COALESCE(sum(r.cantidad),0) AS personas_hoy FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.estado IN (1,2) AND h.inicio >= CURRENT_DATE AND h.inicio < CURRENT_DATE + interval '1 day'");
        $s.='<h2>Estado de la sala para hoy</h2><div class="RESUMEN_CLIENTE">';
        $s.='<div class="RESUMEN_CARD"><span>Cupo del restaurante</span><strong>'.(int)$cupo['total_sillas'].' puestos</strong></div>';
        $s.='<div class="RESUMEN_CARD"><span>Reservas activas hoy</span><strong>'.(int)$resHoy['total_hoy'].' ('.(int)$resHoy['personas_hoy'].' comensales)</strong></div>';
        $s.='</div>';

        $proximas=filas($conn,"SELECT r.id,r.cliente_id,u.nombre,h.mesa_id,h.inicio,r.cantidad FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE r.estado IN (1,2) AND h.inicio >= LOCALTIMESTAMP AND h.inicio <= LOCALTIMESTAMP + interval '2 hours' ORDER BY h.inicio");
        if ($proximas) {
            $s.='<h2>⏰ Reservaciones próximas a comenzar (en 2 horas o menos)</h2>';
            $pData=[];
            foreach($proximas as $pr) {
                $pData[]=[enlace('#'.$pr['id'],'reservas',['id'=>$pr['id'],'fecha'=>substr($pr['inicio'],0,10)]),h($pr['nombre']),'Mesa '.h($pr['mesa_id']),h($pr['cantidad']).' pers.',h($pr['inicio']),enlace('Ver / Modificar','reservas',['id'=>$pr['id']])];
            }
            $s.=tabla(['Reserva','Cliente','Mesa','Personas','Inicio','Acción'],$pData);
        }
    }

    // 3. Interfaz especializada para Cocinero
    if (puede('Cocinero')) {
        $cola=filas($conn,'SELECT o.*,p.nombre,p.tiempo,pe.mesa_id,pr.cocinero_id FROM ordenes o JOIN platos p ON p.id=o.plato_id JOIN pedidos pe ON pe.id=o.pedido_id JOIN especialidades e ON e.plato_id=o.plato_id AND e.cocinero_id=$1 LEFT JOIN preparaciones pr ON pr.orden_id=o.id WHERE o.estado=1 OR (o.estado=2 AND pr.cocinero_id=$1) ORDER BY p.tiempo DESC,o.solicitado,o.id LIMIT 10',[$_SESSION['usuario_id']]);
        $s.='<h2>Comandas en cocina (prioridad por tiempo de preparación)</h2>';
        if ($cola) {
            $dataCocina=[];
            foreach($cola as $c) {
                $dataCocina[]=[h($c['pedido_id']),h($c['mesa_id']??'Sin mesa'),h($c['nombre']),h($c['cantidad']),h($c['tiempo']),h($c['solicitado']),formulario('cocina',oculto('orden_id',$c['id']).oculto('paso',$c['estado']==1?'tomar':'preparar'),$c['estado']==1?'Tomar':'Marcar preparado')];
            }
            $s.=tabla(['Pedido','Mesa','Plato','Cantidad','Tiempo','Solicitado','Acción directa'],$dataCocina);
        } else {
            $s.='<p>No tienes comandas pendientes por preparar en este momento.</p>';
        }
    }

    // 4. Interfaz especializada para Administrador
    if (puede('Administrador')) {
        $metricas=fila($conn,"SELECT
            (SELECT COALESCE(sum(sillas),0) FROM mesas) AS cupo,
            (SELECT count(*) FROM usuarios u WHERE u.activo AND EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre IN ('Administrador','Maitre','Mesero','Cocinero'))) AS empleados,
            (SELECT count(*) FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.estado IN (1,2) AND h.inicio >= CURRENT_DATE AND h.inicio < CURRENT_DATE + interval '1 day') AS reservas_dia,
            (SELECT COALESCE(sum(o.cantidad * o.precio_unitario),0) FROM ordenes o WHERE o.estado=3 AND o.entregado >= CURRENT_DATE AND o.entregado < CURRENT_DATE + interval '1 day') AS ventas_hoy");
        $s.='<h2>Resumen Operativo del Restaurante</h2><div class="RESUMEN_CLIENTE">';
        $s.='<div class="RESUMEN_CARD"><span>Ventas entregadas hoy</span><strong>'.h(dinero($metricas['ventas_hoy'])).'</strong></div>';
        $s.='<div class="RESUMEN_CARD"><span>Reservas activas hoy</span><strong>'.(int)$metricas['reservas_dia'].'</strong></div>';
        $s.='<div class="RESUMEN_CARD"><span>Cupo total disponible</span><strong>'.(int)$metricas['cupo'].' asientos</strong></div>';
        $s.='<div class="RESUMEN_CARD"><span>Personal activo</span><strong>'.(int)$metricas['empleados'].' empleados</strong></div>';
        $s.='</div>';
    }

    return $s;
}

