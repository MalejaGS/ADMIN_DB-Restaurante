<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/pedidos.php';
require_once __DIR__.'/reservas.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/reportes.php';
function fn_boton_menu_principal() { return '<button onclick="location.href=\'index.php\'">Menu del programa</button>'; }
function fn_menu_opciones($conn)
{
    $items=[['desplegar_menu','Desplegar Menú',true],['realizar_pedidos',puede('Mesero')?'Realizar Pedido':'Pedidos',puede('Mesero','Administrador')],['reservas','Reservaciones',puede('Maitre','Administrador')],['gestion_menu','Actualizar Menú',puede('Administrador')],['reportes','Reportes y Estadísticas',puede('Administrador')],['cocina','Preparación',puede('Cocinero')],['clientes','Clientes',puede('Administrador','Maitre','Mesero')]];
    $s='<h1>Opciones del programa</h1><div class="MENU_OPCIONES">';
    foreach($items as [$op,$label,$allowed]) if ($allowed) $s.='<button onclick="location.href=\''.h(url($op)).'\'">'.h($label).'</button>';
    $s.='</div>';
    if (puede('Mesero','Administrador')) {
        $rows=filas($conn,'SELECT DISTINCT p.id,p.mesa_id FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id WHERE p.mesero_id=$1 AND o.estado=4 ORDER BY p.id',[$_SESSION['usuario_id']]);
        if ($rows) { $s.='<h2>Ítems listos para entregar</h2>';foreach($rows as $r) $s.=enlace('Pedido #'.$r['id'].' · Mesa '.$r['mesa_id'],'pedido',['id'=>$r['id']]); }
    }
    return $s;
}
