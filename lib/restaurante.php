<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/pedidos.php';
require_once __DIR__.'/reservas.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/reportes.php';
require_once __DIR__.'/mesas.php';
require_once __DIR__.'/empleados.php';
function fn_boton_menu_principal() { return '<button onclick="location.href=\'index.php\'">Menu del programa</button>'; }
function fn_menu_opciones($conn)
{
    $items=[
        ['desplegar_menu','Desplegar Menú',true],
        ['realizar_pedidos',puede('Mesero')?'Realizar Pedido':'Pedidos',puede('Mesero','Administrador')],
        ['reservas','Reservaciones',puede('Maitre','Administrador')],
        ['gestion_mesas','Gestión de Mesas',puede('Administrador')],
        ['gestion_empleados','Gestión de Empleados',puede('Administrador')],
        ['gestion_menu','Actualizar Menú',puede('Administrador')],
        ['reportes','Reportes y Estadísticas',puede('Administrador')],
        ['cocina','Preparación',puede('Cocinero')],
        ['clientes','Clientes e Historial',puede('Administrador','Maitre','Mesero')]
    ];
    $s='<h1>Opciones del programa</h1><div class="MENU_OPCIONES">';
    foreach($items as [$op,$label,$allowed]) if ($allowed) $s.='<button onclick="location.href=\''.h(url($op)).'\'">'.h($label).'</button>';
    $s.='</div>';
    if (puede('Maitre','Administrador')) {
        $proximas=filas($conn,"SELECT r.id,r.cliente_id,u.nombre,h.mesa_id,h.inicio FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE r.estado IN (1,2) AND h.inicio >= LOCALTIMESTAMP AND h.inicio <= LOCALTIMESTAMP + interval '2 hours' ORDER BY h.inicio");
        if ($proximas) {
            $s.='<h2>Reservaciones próximas a comenzar (en 2 horas o menos)</h2>';
            $pData=[];
            foreach($proximas as $pr) {
                $pData[]=[enlace('#'.$pr['id'],'reservas',['id'=>$pr['id'],'fecha'=>substr($pr['inicio'],0,10)]),h($pr['nombre']),h($pr['mesa_id']),h($pr['inicio'])];
            }
            $s.=tabla(['Reserva','Cliente','Mesa','Inicio'],$pData);
        }
    }
    if (puede('Mesero','Administrador')) {
        $rows=filas($conn,'SELECT DISTINCT p.id,p.mesa_id FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id WHERE p.mesero_id=$1 AND o.estado=4 ORDER BY p.id',[$_SESSION['usuario_id']]);
        if ($rows) { $s.='<h2>Ítems listos para entregar</h2>';foreach($rows as $r) $s.=enlace('Pedido #'.$r['id'].' · Mesa '.$r['mesa_id'],'pedido',['id'=>$r['id']]); }
    }
    return $s;
}

