<?php
function datos_reportes($conn,$desde,$hasta,$grupo)
{
    fecha($desde);fecha($hasta);
    if ($desde>$hasta) throw new DomainException('La fecha inicial debe ser anterior o igual a la final.');
    if (!in_array($grupo,['day','week','month'],true)) throw new DomainException('Agrupación inválida.');
    $params=[$desde,$hasta,$grupo];
    $reservas=filas($conn,"SELECT date_trunc($3::text,h.inicio)::date AS periodo,r.estado,count(DISTINCT r.id) AS reservas,sum(r.cantidad) AS personas
      FROM reservaciones r JOIN (SELECT reservacion_id,min(inicio) AS inicio FROM horarios GROUP BY reservacion_id) h ON h.reservacion_id=r.id
      WHERE h.inicio >= $1::date AND h.inicio < $2::date+interval '1 day' GROUP BY 1,r.estado ORDER BY 1,r.estado",$params);
    // Ventas actuales por fecha de entrega. En el legado solo se conoce solicitado:
    // se presenta como estimación aparte, nunca como venta de fecha comprobada.
    $ventas=filas($conn,"SELECT date_trunc($3::text,COALESCE(o.entregado,o.solicitado) AT TIME ZONE 'America/Bogota')::date AS periodo,
      COALESCE(sum(o.cantidad*o.precio_unitario) FILTER(WHERE o.entregado IS NOT NULL AND o.precio_unitario IS NOT NULL),0) AS verificadas,
      COALESCE(sum(o.cantidad*COALESCE(o.precio_unitario,p.precio)) FILTER(WHERE o.entregado IS NULL OR o.precio_unitario IS NULL),0) AS estimadas
      FROM ordenes o JOIN platos p ON p.id=o.plato_id WHERE o.estado=3
      AND COALESCE(o.entregado,o.solicitado) >= $1::date AND COALESCE(o.entregado,o.solicitado) < $2::date+interval '1 day'
      GROUP BY 1 ORDER BY 1",$params);
    $platos=filas($conn,"SELECT p.id,p.nombre,sum(o.cantidad) AS unidades,
      COALESCE(sum(o.cantidad*o.precio_unitario) FILTER(WHERE o.precio_unitario IS NOT NULL),0) AS importe,
      COALESCE(sum(o.cantidad*p.precio) FILTER(WHERE o.precio_unitario IS NULL),0) AS estimado
      FROM ordenes o JOIN platos p ON p.id=o.plato_id WHERE o.solicitado >= $1::date AND o.solicitado < $2::date+interval '1 day'
      GROUP BY p.id,p.nombre ORDER BY unidades DESC,p.nombre",[$desde,$hasta]);
    return ['reservas'=>$reservas,'ventas'=>$ventas,'platos'=>$platos];
}
function fn_reportes($conn)
{
    exigir('Administrador');
    $desde=fecha($_GET['desde']??date('Y-m-01'));$hasta=fecha($_GET['hasta']??date('Y-m-d'));$grupo=$_GET['grupo']??'day';
    $d=datos_reportes($conn,$desde,$hasta,$grupo);
    $ventas=array_sum(array_column($d['ventas'],'verificadas'));
    $estimadas=array_sum(array_column($d['ventas'],'estimadas'));
    $reservas=array_sum(array_column($d['reservas'],'reservas'));
    $s=encabezado('Reportes y estadísticas','Mira cómo va el restaurante. Elige un período para consultar ventas, visitas y platos favoritos.','Resultados del restaurante');
    $s.='<form method="get">'.oculto('opcion','reportes').campo('desde','Desde',$desde,'date','required').campo('hasta','Hasta',$hasta,'date','required').'<label>Agrupar por<select name="grupo">'.opciones([['id'=>'day','nombre'=>'Día'],['id'=>'week','nombre'=>'Semana (lunes)'],['id'=>'month','nombre'=>'Mes']],'id','nombre',$grupo,false).'</select></label><button>Aplicar filtros</button></form>';
    $s.='<div class="TARJETAS">'.tarjeta('Ventas verificadas',dinero($ventas),'Ítems entregados con precio original').tarjeta('Estimación histórica',dinero($estimadas),'Se mantiene separada de las ventas verificadas').tarjeta('Reservas del período',$reservas,'Incluye todos los estados').'</div>';
    if ($d['ventas']) {
        $max=max(1,max(array_column($d['ventas'],'verificadas')),max(array_column($d['ventas'],'estimadas')));
        $s.='<h2>Ventas a lo largo del período</h2><div class="GRAFICO" aria-label="Comparación de ventas verificadas y estimaciones históricas"><p class="AYUDA">Verde: ventas verificadas · Terracota: estimación histórica. Valores separados.</p>';
        foreach($d['ventas'] as $r) foreach(['verificadas'=>'Verificadas','estimadas'=>'Estimadas'] as $key=>$label) {
            $width=number_format(max(0,min(100,(float)$r[$key]/$max*100)),3,'.','');
            $s.='<div class="BARRA_FILA"><span>'.h($r['periodo']).'<small> · '.h($label).'</small></span><div class="BARRA_FONDO" aria-hidden="true"><div class="BARRA_VALOR'.($key==='estimadas'?' ESTIMADA':'').'" style="width:'.$width.'%"></div></div><strong>'.h(dinero($r[$key])).'</strong></div>';
        }
        $s.='</div>';
    }
    $estados=[1=>'Pendiente',2=>'Confirmada',3=>'Completada',4=>'Cancelada'];$rows=[];
    foreach($d['reservas'] as $r) $rows[]=[h($r['periodo']),insignia($estados[$r['estado']]),h($r['reservas']),h($r['personas'])];
    $s.='<h2>Reservas por estado</h2>'.tabla(['Período','Estado','Reservas','Personas'],$rows);
    $rows=[];
    foreach($d['ventas'] as $r) $rows[]=[h($r['periodo']),h(dinero($r['verificadas'])),h(dinero($r['estimadas']))];
    $s.='<h2>Detalle de ventas</h2>'.tabla(['Período','Ventas verificadas','Estimación histórica'],$rows).'<p class="AYUDA">Las ventas verificadas usan la fecha de entrega y el precio registrado. Los históricos usan la fecha de solicitud y, cuando falta el precio original, el precio actual.</p>';
    $rows=[];$unidades=0;$importe=0;$estimado=0;
    foreach($d['platos'] as $r) { $rows[]=[h($r['nombre']),h($r['unidades']),h(dinero($r['importe'])),h(dinero($r['estimado']))];$unidades+=(int)$r['unidades'];$importe+=(float)$r['importe'];$estimado+=(float)$r['estimado']; }
    $s.='<h2>Los más pedidos</h2><p class="AYUDA">Ordenados por unidades solicitadas. Incluye todos los estados; el importe solicitado no equivale a una venta.</p>'.tabla(['Plato','Unidades','Importe registrado','Estimación sin precio original'],$rows).'<p class="AYUDA">Total: '.$unidades.' unidades · Importe registrado: '.h(dinero($importe)).' · Estimado: '.h(dinero($estimado)).'</p>';
    $calidad=fila($conn,"SELECT (SELECT count(*) FROM reservaciones r WHERE NOT EXISTS(SELECT 1 FROM horarios h WHERE h.reservacion_id=r.id)) AS sin_horario,(SELECT count(*) FROM pedidos WHERE mesa_id IS NULL OR creado IS NULL) AS incompletos,(SELECT count(*) FROM ordenes WHERE precio_unitario IS NULL) AS sin_precio");
    return $s.'<details><summary>Acerca de los datos históricos</summary><p>Reservas sin horario: '.h($calidad['sin_horario']).' (no se pueden asignar a un período). Pedidos sin mesa o fecha: '.h($calidad['incompletos']).'. Ítems sin precio original: '.h($calidad['sin_precio']).'.</p></details>';
}
