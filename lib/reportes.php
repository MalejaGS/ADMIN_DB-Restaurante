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
    $s='<h1>Reportes y estadísticas</h1><form method="get">'.oculto('opcion','reportes').campo('desde','Desde',$desde,'date','required').campo('hasta','Hasta',$hasta,'date','required').'<label>Agrupar <select name="grupo">'.opciones([['id'=>'day','nombre'=>'Día'],['id'=>'week','nombre'=>'Semana (lunes)'],['id'=>'month','nombre'=>'Mes']],'id','nombre',$grupo,false).'</select></label><button>Consultar</button></form>';
    $estados=[1=>'Pendiente',2=>'Confirmada',3=>'Completada',4=>'Cancelada'];$rows=[];$total=0;
    foreach($d['reservas'] as $r) { $rows[]=[h($r['periodo']),h($estados[$r['estado']]),h($r['reservas']),h($r['personas'])];$total+=(int)$r['reservas']; }
    $s.='<h2>Reservas</h2>'.tabla(['Período','Estado','Reservas','Personas'],$rows).'<p>Total de reservas: '.$total.'.</p>';
    $rows=[];$total=0;$estimado=0;
    foreach($d['ventas'] as $r) { $rows[]=[h($r['periodo']),h(dinero($r['verificadas'])),h(dinero($r['estimadas']))];$total+=(float)$r['verificadas'];$estimado+=(float)$r['estimadas']; }
    $s.='<h2>Ventas de ítems entregados</h2>'.tabla(['Período','Ventas verificadas','Estimación histórica'],$rows).'<p>Total verificado: '.h(dinero($total)).'. Estimación histórica adicional: '.h(dinero($estimado)).'.</p><p>Las ventas verificadas usan la fecha de entrega y el precio registrado. Los históricos sin esos datos usan la fecha de solicitud y, si falta el precio original, el precio actual; se muestran separados.</p>';
    $rows=[];$unidades=0;$importe=0;$estimado=0;
    foreach($d['platos'] as $r) { $rows[]=[h($r['nombre']),h($r['unidades']),h(dinero($r['importe'])),h(dinero($r['estimado']))];$unidades+=(int)$r['unidades'];$importe+=(float)$r['importe'];$estimado+=(float)$r['estimado']; }
    $calidad=fila($conn,"SELECT (SELECT count(*) FROM reservaciones r WHERE NOT EXISTS(SELECT 1 FROM horarios h WHERE h.reservacion_id=r.id)) AS sin_horario,(SELECT count(*) FROM pedidos WHERE mesa_id IS NULL OR creado IS NULL) AS incompletos,(SELECT count(*) FROM ordenes WHERE precio_unitario IS NULL) AS sin_precio");
    $s.='<details><summary>Calidad de los datos históricos</summary><p>Reservas sin horario: '.h($calidad['sin_horario']).' (no se pueden asignar a un período). Pedidos sin mesa o fecha: '.h($calidad['incompletos']).'. Ítems sin precio original: '.h($calidad['sin_precio']).'.</p></details>';
    return $s.'<h2>Platos más solicitados</h2><p>Incluye todos los estados, según la fecha de solicitud; los importes solicitados no equivalen a ventas.</p>'.tabla(['Plato','Unidades','Importe registrado','Estimación sin precio original'],$rows).'<p>Total: '.$unidades.' unidades; importe registrado '.h(dinero($importe)).'; estimado '.h(dinero($estimado)).'.</p>';
}
