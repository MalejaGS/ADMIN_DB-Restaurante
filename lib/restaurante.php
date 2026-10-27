<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/pedidos.php';
require_once __DIR__.'/reservas.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/reportes.php';
require_once __DIR__.'/mesas.php';
require_once __DIR__.'/empleados.php';
function fn_boton_menu_principal() { return boton('Inicio',''); }
function fn_barra_navegacion()
{
    if (!isset($_SESSION['usuario_id'])) return '';
    $actual=$_GET['opcion']??'';
    $alias=['pedido'=>'realizar_pedidos','factura_pedido'=>'realizar_pedidos'];
    $actual=$alias[$actual]??$actual;
    // Operación diaria primero; administración al final.
    $items=[
        ['','Inicio',true],
        ['realizar_pedidos','Sala',puede('Mesero','Administrador')],
        ['cocina','Cocina',puede('Cocinero')],
        ['reservas','Reservas',puede('Maitre','Administrador')],
        ['clientes','Clientes',puede('Administrador','Maitre','Mesero')],
        ['desplegar_menu','Menú',true],
        ['gestion_menu','Carta',puede('Administrador')],
        ['gestion_mesas','Mesas',puede('Administrador')],
        ['gestion_empleados','Personal',puede('Administrador')],
        ['reportes','Reportes',puede('Administrador')],
    ];
    $links='';
    foreach($items as [$op,$label,$ok]) {
        if (!$ok) continue;
        $activo=$actual===$op;
        $links.='<a href="'.h(url($op)).'"'.($activo?' class="ACTIVO" aria-current="page"':'').'>'.h($label).'</a>';
    }
    return '<nav class="TOPNAV" aria-label="Navegación principal">'.$links.'</nav>'
        .'<div class="TOPUSER"><span class="TOPUSER_NOMBRE">'.h($_SESSION['nombre']).'<small>'.h(implode(' · ',$_SESSION['roles'])).'</small></span>'
        .formulario('logout','','Salir','BTN_LINK').'</div>';
}
function saludo()
{
    $h=(int)date('G');
    return $h<12?'Buenos días':($h<19?'Buenas tardes':'Buenas noches');
}
function fn_menu_opciones($conn)
{
    $nombre=explode(' ',trim($_SESSION['nombre']))[0];
    $acciones='';
    if (puede('Mesero')) $acciones.=boton('Abrir mesa','realizar_pedidos');
    if (puede('Maitre')) $acciones.=boton('Nueva reserva','reservas',['nueva'=>1]);
    if (puede('Cocinero')) $acciones.=boton('Ir a cocina','cocina');
    $s=encabezado(h(saludo().', '.$nombre),h(ucfirst(fecha_larga(date('Y-m-d')))).' · '.h(implode(', ',$_SESSION['roles'])),$acciones);

    if (puede('Mesero')) {
        $listos=filas($conn,'SELECT p.id,p.mesa_id,u.nombre AS cliente,count(o.id) AS n FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id JOIN usuarios u ON u.id=p.cliente_id WHERE p.mesero_id=$1 AND o.estado=4 GROUP BY p.id,p.mesa_id,u.nombre ORDER BY p.id',[$_SESSION['usuario_id']]);
        if ($listos) {
            $l='<ul class="LISTA_ACCION">';
            foreach($listos as $x) $l.='<li><div><strong>Mesa '.h($x['mesa_id']).'</strong><span>'.h($x['cliente']).' · '.(int)$x['n'].' '.((int)$x['n']===1?'plato listo':'platos listos').'</span></div>'.boton('Servir','pedido',['id'=>$x['id']],'BTN BTN_SM').'</li>';
            $s.=seccion('Listos para servir',$l.'</ul>');
        }
        $abiertos=filas($conn,"SELECT p.id,p.mesa_id,u.nombre AS cliente,p.creado,count(o.id) AS total,count(o.id) FILTER(WHERE o.estado=4) AS listos
            FROM pedidos p JOIN usuarios u ON u.id=p.cliente_id JOIN ordenes o ON o.pedido_id=p.id
            WHERE p.mesero_id=$1 GROUP BY p.id,p.mesa_id,u.nombre,p.creado HAVING count(o.id) > count(o.id) FILTER(WHERE o.estado=3)
            ORDER BY p.id DESC LIMIT 10",[$_SESSION['usuario_id']]);
        $data=[];
        foreach($abiertos as $a) $data[]=[enlace('Mesa '.($a['mesa_id']??'—'),'pedido',['id'=>$a['id']]),h($a['cliente']),num((int)$a['total']),h(hora_corta($a['creado'])),estado_pedido_html(estado_pedido($conn,$a['id']))];
        $s.=seccion('Mis mesas abiertas',tabla(['Mesa','Cliente','Platos','Abierta','Estado'],$data,'No tiene mesas abiertas.'));
    }

    if (puede('Maitre')) {
        $hoy=fila($conn,"SELECT count(DISTINCT r.id) AS n, COALESCE(sum(r.cantidad),0) AS personas FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.estado IN (1,2) AND h.inicio >= CURRENT_DATE AND h.inicio < CURRENT_DATE + interval '1 day'");
        $cupo=fila($conn,'SELECT COALESCE(sum(sillas),0) AS total FROM mesas');
        $s.=kpis([['Reservas hoy',(int)$hoy['n']],['Comensales esperados',(int)$hoy['personas']],['Puestos en sala',(int)$cupo['total']]]);
        $prox=filas($conn,"SELECT r.id,r.cliente_id,u.nombre,h.mesa_id,h.inicio,r.cantidad FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE r.estado IN (1,2) AND h.inicio >= LOCALTIMESTAMP AND h.inicio <= LOCALTIMESTAMP + interval '2 hours' ORDER BY h.inicio");
        $data=[];
        foreach($prox as $p) $data[]=[h(hora_corta($p['inicio'])),enlace($p['nombre'],'clientes',['id'=>$p['cliente_id']]),'Mesa '.h($p['mesa_id']),num((int)$p['cantidad']),enlace('Ver','reservas',['id'=>$p['id'],'fecha'=>substr($p['inicio'],0,10)])];
        $s.=seccion('Llegan en las próximas 2 horas',tabla(['Hora','Cliente','Mesa','Pers.',''],$data,'No hay llegadas en las próximas dos horas.'));
    }

    if (puede('Cocinero')) {
        $c=fila($conn,'SELECT count(*) FILTER(WHERE o.estado=1) AS cola, count(*) FILTER(WHERE o.estado=2 AND pr.cocinero_id=$1) AS mias FROM ordenes o JOIN especialidades e ON e.plato_id=o.plato_id AND e.cocinero_id=$1 LEFT JOIN preparaciones pr ON pr.orden_id=o.id WHERE o.estado IN (1,2)',[$_SESSION['usuario_id']]);
        $s.=kpis([['En cola',(int)$c['cola']],['Preparando',(int)$c['mias']]]);
    }

    if (puede('Administrador')) {
        $m=fila($conn,"SELECT
            (SELECT COALESCE(sum(o.cantidad*o.precio_unitario),0) FROM ordenes o WHERE o.estado=3 AND o.entregado >= CURRENT_DATE AND o.entregado < CURRENT_DATE + interval '1 day') AS ventas,
            (SELECT count(DISTINCT p.id) FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id WHERE o.estado IN (1,2,4)) AS abiertos,
            (SELECT count(*) FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.estado IN (1,2) AND h.inicio >= CURRENT_DATE AND h.inicio < CURRENT_DATE + interval '1 day') AS reservas,
            (SELECT count(*) FROM usuarios u WHERE u.activo AND EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre IN ('Administrador','Maitre','Mesero','Cocinero'))) AS personal");
        $s.=kpis([['Ventas de hoy',h(dinero($m['ventas']))],['Pedidos abiertos',(int)$m['abiertos']],['Reservas hoy',(int)$m['reservas']],['Personal activo',(int)$m['personal']]]);
    }
    return $s;
}
