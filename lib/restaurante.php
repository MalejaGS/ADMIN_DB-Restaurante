<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/pedidos.php';
require_once __DIR__.'/reservas.php';
require_once __DIR__.'/menu.php';
require_once __DIR__.'/reportes.php';
require_once __DIR__.'/ui.php';
function fn_boton_menu_principal() { return enlace('Volver al inicio'); }
function fn_menu_opciones($conn)
{
    $nombre=explode(' ',$_SESSION['nombre'])[0];
    $s=encabezado('Hola, '.$nombre.'. Todo listo para el servicio.','Elige una tarea para empezar. Aquí tienes lo que está pasando en el restaurante.','Opciones del programa');
    $s.='<div class="TARJETAS">';
    $mesas=fila($conn,'SELECT count(*) AS n,COALESCE(sum(sillas),0) AS cupo FROM mesas');
    $s.=tarjeta('Salón',$mesas['n'].((int)$mesas['n']===1?' mesa':' mesas'),$mesas['cupo'].' sillas en total');
    if (puede('Administrador','Maitre')) {
        $r=fila($conn,"SELECT count(DISTINCT r.id) AS n FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE h.inicio>=CURRENT_DATE AND h.inicio<CURRENT_DATE+interval '1 day' AND r.estado<>4");
        $s.=tarjeta('Reservas de hoy',$r['n'],'Sin incluir las canceladas');
    } elseif (puede('Mesero')) {
        $r=fila($conn,'SELECT count(*) AS n FROM pedidos p WHERE p.mesero_id=$1 AND EXISTS(SELECT 1 FROM ordenes o WHERE o.pedido_id=p.id AND o.estado<>3)',[$_SESSION['usuario_id']]);
        $s.=tarjeta('Tus pedidos abiertos',$r['n'],'En preparación o pendientes de entrega');
    } else {
        $r=fila($conn,'SELECT count(*) AS n FROM ordenes o JOIN especialidades e ON e.plato_id=o.plato_id AND e.cocinero_id=$1 LEFT JOIN preparaciones p ON p.orden_id=o.id WHERE o.estado=1 OR (o.estado=2 AND p.cocinero_id=$1)',[$_SESSION['usuario_id']]);
        $s.=tarjeta('En tu cocina',$r['n'],'Disponibles o en preparación contigo');
    }
    $p=fila($conn,'SELECT count(*) AS n FROM platos WHERE activo');
    $s.=tarjeta('Carta disponible',$p['n'].((int)$p['n']===1?' plato':' platos'),'Listos para nuevos pedidos').'</div><h2>¿Qué necesitas hacer?</h2><div class="ACCESOS">';
    foreach(modulos() as [$ruta,$label,$ic,$desc,$allowed]) if($allowed && $ruta!=='') $s.='<a class="ACCESO_RAPIDO" href="'.h(url($ruta)).'">'.icono($ic).'<span><strong>'.h($label).'</strong><small>'.h($desc).'</small></span><span class="FLECHA" aria-hidden="true">↗</span></a>';
    $s.='</div>';
    if(puede('Mesero','Administrador')) {
        $rows=filas($conn,'SELECT DISTINCT p.id,p.mesa_id FROM pedidos p JOIN ordenes o ON o.pedido_id=p.id WHERE p.mesero_id=$1 AND o.estado=4 ORDER BY p.id',[$_SESSION['usuario_id']]);
        if($rows) {
            $s.='<h2>Listos para llevar a la mesa</h2><div class="ACCESOS">';
            foreach($rows as $r) $s.='<a class="ACCESO_RAPIDO" href="'.h(url('pedido',['id'=>$r['id']])).'">'.icono('pedido').'<span><strong>Pedido #'.h($r['id']).'</strong><small>Mesa '.h($r['mesa_id']).' · Hay platos preparados</small></span></a>';
            $s.='</div>';
        }
    }
    return $s;
}
