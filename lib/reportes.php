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
    $meseros=filas($conn,"SELECT u.id, u.nombre,
      count(DISTINCT p.id) AS total_pedidos,
      count(o.id) FILTER(WHERE o.estado=3) AS items_entregados,
      COALESCE(sum(o.cantidad * COALESCE(o.precio_unitario, pl.precio)) FILTER(WHERE o.estado=3), 0) AS total_ventas
      FROM usuarios u
      JOIN actuaciones a ON a.usuario_id=u.id
      JOIN roles ro ON ro.id=a.rol_id AND ro.nombre='Mesero'
      LEFT JOIN pedidos p ON p.mesero_id=u.id AND p.creado >= $1::date AND p.creado < $2::date + interval '1 day'
      LEFT JOIN ordenes o ON o.pedido_id=p.id
      LEFT JOIN platos pl ON pl.id=o.plato_id
      GROUP BY u.id, u.nombre
      ORDER BY total_ventas DESC, total_pedidos DESC, u.nombre",[$desde,$hasta]);
    $cocineros=filas($conn,"SELECT u.id, u.nombre,
      count(DISTINCT pr.orden_id) AS items_tomados,
      count(DISTINCT pr.orden_id) FILTER(WHERE o.estado IN (3,4)) AS items_preparados
      FROM usuarios u
      JOIN actuaciones a ON a.usuario_id=u.id
      JOIN roles ro ON ro.id=a.rol_id AND ro.nombre='Cocinero'
      LEFT JOIN preparaciones pr ON pr.cocinero_id=u.id
      LEFT JOIN ordenes o ON o.id=pr.orden_id AND o.solicitado >= $1::date AND o.solicitado < $2::date + interval '1 day'
      GROUP BY u.id, u.nombre
      ORDER BY items_preparados DESC, items_tomados DESC, u.nombre",[$desde,$hasta]);
    $categorias=filas($conn,"SELECT t.id, t.nombre AS categoria,
      COALESCE(sum(o.cantidad), 0) AS unidades,
      COALESCE(sum(o.cantidad * COALESCE(o.precio_unitario, pl.precio)), 0) AS importe
      FROM tipos t
      LEFT JOIN platos pl ON pl.tipo_id=t.id
      LEFT JOIN ordenes o ON o.plato_id=pl.id AND o.solicitado >= $1::date AND o.solicitado < $2::date + interval '1 day'
      GROUP BY t.id, t.nombre
      ORDER BY unidades DESC, importe DESC, t.nombre",[$desde,$hasta]);
    return ['reservas'=>$reservas,'ventas'=>$ventas,'platos'=>$platos,'meseros'=>$meseros,'cocineros'=>$cocineros,'categorias'=>$categorias];
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

    $rowsMeseros=[];
    foreach($d['meseros'] as $m) $rowsMeseros[]=[h($m['id']),h($m['nombre']),h($m['total_pedidos']),h($m['items_entregados']),h(dinero($m['total_ventas']))];
    $s.='<h2>Desempeño de Meseros</h2>'.tabla(['ID','Mesero','Pedidos atendidos','Ítems servidos','Ventas registradas'],$rowsMeseros);

    $rowsCocina=[];
    foreach($d['cocineros'] as $c) $rowsCocina[]=[h($c['id']),h($c['nombre']),h($c['items_tomados']),h($c['items_preparados'])];
    $s.='<h2>Eficiencia de Cocineros</h2>'.tabla(['ID','Cocinero','Comandas tomadas','Platos preparados'],$rowsCocina);

    $rowsCat=[];
    foreach($d['categorias'] as $cat) $rowsCat[]=[h($cat['categoria']),h($cat['unidades']),h(dinero($cat['importe']))];
    $s.='<h2>Ventas por Categoría de Menú</h2>'.tabla(['Categoría','Unidades solicitadas','Importe estimado'],$rowsCat);

    $rows=[];$unidades=0;$importe=0;$estimado=0;
    foreach($d['platos'] as $r) { $rows[]=[h($r['nombre']),h($r['unidades']),h(dinero($r['importe'])),h(dinero($r['estimado']))];$unidades+=(int)$r['unidades'];$importe+=(float)$r['importe'];$estimado+=(float)$r['estimado']; }
    $calidad=fila($conn,"SELECT (SELECT count(*) FROM reservaciones r WHERE NOT EXISTS(SELECT 1 FROM horarios h WHERE h.reservacion_id=r.id)) AS sin_horario,(SELECT count(*) FROM pedidos WHERE mesa_id IS NULL OR creado IS NULL) AS incompletos,(SELECT count(*) FROM ordenes WHERE precio_unitario IS NULL) AS sin_precio");
    $s.='<details><summary>Calidad de los datos históricos</summary><p>Reservas sin horario: '.h($calidad['sin_horario']).' (no se pueden asignar a un período). Pedidos sin mesa o fecha: '.h($calidad['incompletos']).'. Ítems sin precio original: '.h($calidad['sin_precio']).'.</p></details>';
    return $s.'<h2>Platos más solicitados</h2><p>Incluye todos los estados, según la fecha de solicitud; los importes solicitados no equivalen a ventas.</p>'.tabla(['Plato','Unidades','Importe registrado','Estimación sin precio original'],$rows).'<p>Total: '.$unidades.' unidades; importe registrado '.h(dinero($importe)).'; estimado '.h(dinero($estimado)).'.</p>';
}
