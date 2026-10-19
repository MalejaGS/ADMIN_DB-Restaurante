<?php
function estado_pedido($conn,$id)
{
    $r=fila($conn,'SELECT count(*) AS n,count(*) FILTER(WHERE estado=3) AS entregadas,count(*) FILTER(WHERE estado IN(3,4)) AS listas FROM ordenes WHERE pedido_id=$1',[$id]);
    if ($r['n']>0 && $r['n']===$r['entregadas']) return 'Entregado';
    if ($r['n']>0 && $r['n']===$r['listas']) return 'Preparado';
    return 'En preparación';
}
function pedido_acceso($conn,$id,$lock=false)
{
    exigir('Administrador','Mesero');
    $p=fila($conn,'SELECT * FROM pedidos WHERE id=$1'.($lock?' FOR UPDATE':''),[$id]);
    if (!$p || (!puede('Administrador') && (int)$p['mesero_id']!==$_SESSION['usuario_id'])) throw new DomainException('Pedido no disponible para este usuario.');
    return $p;
}
function items_entrada()
{
    $items=$_POST['cantidad']??[];
    if (!is_array($items) || count($items)>500) throw new DomainException('Ítems inválidos.');
    $result=[];
    foreach($items as $id=>$cantidad) {
        if ($cantidad==='' || $cantidad==='0') continue;
        $result[entero($id,'Plato')]=entero($cantidad,'Cantidad');
        if ($result[$id]>1000) throw new DomainException('Máximo 1000 unidades por ítem.');
    }
    if (!$result) throw new DomainException('Agregue al menos un plato.');
    ksort($result);
    return $result;
}
function guardar_items($conn,$pedido,$items)
{
    foreach($items as $id=>$cantidad) {
        $p=fila($conn,'SELECT precio FROM platos WHERE id=$1 AND activo FOR SHARE',[$id]);
        if (!$p) throw new DomainException('Uno de los platos ya no está disponible.');
        if (!fila($conn,'SELECT e.id FROM especialidades e JOIN usuarios u ON u.id=e.cocinero_id WHERE e.plato_id=$1 AND u.activo LIMIT 1',[$id])) throw new DomainException('El plato '.$id.' no tiene un cocinero autorizado.');
        db($conn,'INSERT INTO ordenes(plato_id,pedido_id,estado,cantidad,precio_unitario) VALUES($1,$2,1,$3,$4)',[$id,$pedido,$cantidad,$p['precio']]);
    }
}
function accion_pedido_guardar($conn)
{
    exigir('Mesero');
    db($conn,'SELECT pg_advisory_xact_lock(202603,2)');
    $items=items_entrada();
    $mesa=entero($_POST['mesa_id']??null,'Mesa');
    if (!fila($conn,'SELECT id FROM mesas WHERE id=$1 FOR SHARE',[$mesa])) throw new DomainException('Mesa inexistente.');
    $cliente=entero($_POST['cliente_id']??null,'Cliente');
    cliente_valido($conn,$cliente);
    $reserva=($_POST['reservacion_id']??'')!==''?entero($_POST['reservacion_id'],'Reserva'):null;
    if ($reserva) {
        $r=fila($conn,"SELECT r.cliente_id FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.id=$1 AND h.mesa_id=$2 AND r.estado IN(1,2) AND LOCALTIMESTAMP >= h.inicio AND LOCALTIMESTAMP < h.inicio+greatest(h.duracion,interval '2 hours') FOR SHARE OF r,h",[$reserva,$mesa]);
        if (!$r || (int)$r['cliente_id']!==$cliente) throw new DomainException('La reserva no está vigente o no corresponde a esta mesa y cliente.');
    } else {
        $r=fila($conn,"SELECT r.cliente_id FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE h.mesa_id=$1 AND r.estado<>4 AND LOCALTIMESTAMP >= h.inicio AND LOCALTIMESTAMP < h.inicio+greatest(h.duracion,interval '2 hours') AND r.cliente_id<>$2 LIMIT 1",[$mesa,$cliente]);
        if ($r) throw new DomainException('La mesa está reservada para otro cliente en este horario.');
    }
    $p=fila($conn,'INSERT INTO pedidos(cliente_id,mesero_id,mesa_id,reservacion_id,creado,token) VALUES($1,$2,$3,$4,CURRENT_TIMESTAMP,$5) RETURNING id',[$cliente,$_SESSION['usuario_id'],$mesa,$reserva,$_POST['token']]);
    guardar_items($conn,$p['id'],$items);
    return url('pedido',['id'=>$p['id']]);
}
function accion_pedido_agregar($conn)
{
    $id=entero($_POST['pedido_id']??null,'Pedido');
    pedido_acceso($conn,$id,true);
    if (estado_pedido($conn,$id)==='Entregado') throw new DomainException('El pedido ya está entregado.');
    guardar_items($conn,$id,items_entrada());
    return url('pedido',['id'=>$id]);
}
function accion_entregar($conn)
{
    $id=entero($_POST['pedido_id']??null,'Pedido');
    pedido_acceso($conn,$id,true);
    $orden=entero($_POST['orden_id']??null,'Ítem');
    $r=db($conn,'UPDATE ordenes SET estado=3,entregado=CURRENT_TIMESTAMP WHERE id=$1 AND pedido_id=$2 AND estado=4',[$orden,$id]);
    if (pg_affected_rows($r)!==1) throw new DomainException('Solo se pueden entregar ítems preparados.');
    return url('pedido',['id'=>$id]);
}
function formulario_items($conn)
{
    $rows=filas($conn,'SELECT p.id,p.nombre,p.precio,t.nombre AS tipo FROM platos p JOIN tipos t ON t.id=p.tipo_id WHERE p.activo ORDER BY t.nombre,p.nombre');
    if (!$rows) return '<div class="VACIO"><strong>La carta está vacía</strong><p>Agrega platos disponibles antes de registrar un pedido.</p></div>';
    $tipos=array_values(array_unique(array_column($rows,'tipo')));
    $s='<div class="ITEMS_PEDIDO"><h3>Elige los platos</h3><p class="AYUDA">Busca en la carta y ajusta las cantidades. Tu selección permanece al cambiar de categoría.</p><div class="CATALOGO_FILTROS"><input type="search" class="BUSCAR_PLATO" aria-label="Buscar plato" placeholder="Buscar un plato…"><select class="FILTRAR_TIPO" aria-label="Categoría"><option value="">Todas las categorías</option>';
    foreach($tipos as $tipo) $s.='<option value="'.h($tipo).'">'.h($tipo).'</option>';
    $s.='</select></div><div class="CATALOGO">';
    foreach($rows as $p) $s.='<div class="PLATO" data-tipo="'.h($p['tipo']).'" data-nombre="'.h($p['nombre']).'"><span class="NOMBRE">'.h($p['nombre']).'</span><span class="DESCRIPCION">'.h($p['tipo']).'</span><span class="PRECIO">'.h(dinero($p['precio'])).'</span><label>Cantidad <span class="CANTIDAD_CONTROL"><button type="button" data-delta="-1" aria-label="Quitar una unidad de '.h($p['nombre']).'">−</button><input aria-label="Cantidad de '.h($p['nombre']).'" type="number" name="cantidad['.(int)$p['id'].']" min="0" max="1000" value="0" data-precio="'.h($p['precio']).'"><button type="button" data-delta="1" aria-label="Agregar una unidad de '.h($p['nombre']).'">+</button></span></label></div>';
    return $s.'</div><p class="SIN_PLATOS AYUDA" hidden>No encontramos platos con esos filtros.</p><ul class="RESUMEN_LISTA" aria-label="Platos seleccionados"></ul><div class="RESUMEN_PEDIDO"><div><strong class="UNIDADES_PEDIDO">0 unidades</strong><span>Total de nuevos ítems</span></div><output class="TOTAL_PEDIDO" aria-live="polite">$0,00</output></div></div>';
}
function selector_cliente($conn,$seleccionado=null)
{
    $u=$seleccionado?fila($conn,'SELECT nombre FROM usuarios WHERE id=$1',[$seleccionado]):null;
    return '<div class="CLIENTE_SELECTOR"><label>Cliente<input type="search" class="CLIENTE_BUSQUEDA" placeholder="Busca por nombre o ID…" autocomplete="off" aria-label="Buscar cliente por nombre o ID" value="'.h($u['nombre']??'').'"></label><div class="CLIENTE_RESULTADOS" aria-label="Resultados de clientes"></div><p class="CLIENTE_SELECCIONADO" role="status">'.($u?'Seleccionado: '.h($u['nombre']).' · #'.h($seleccionado):'Busca y selecciona el cliente de esta visita.').'</p><details'.($seleccionado?'':' open').'><summary>Ingresar ID del cliente manualmente</summary>'.campo('cliente_id','ID del cliente',$seleccionado??'','number','min="1" required').'</details><noscript><p>Usa el ID del cliente. Puedes consultarlo en '.enlace('Clientes','clientes').'.</p></noscript></div>';
}
function buscar_clientes($conn)
{
    exigir('Administrador','Maitre','Mesero');
    $q=is_string($_GET['q']??null)?trim($_GET['q']):'';
    if (mb_strlen($q)>150) throw new DomainException('Búsqueda demasiado larga.');
    if (mb_strlen($q)<2 && !ctype_digit($q)) return [];
    return filas($conn,"SELECT u.id,u.nombre FROM usuarios u WHERE u.activo AND EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre='Cliente') AND (u.nombre ILIKE '%'||$1||'%' OR u.id::text=$1) ORDER BY u.nombre,u.id LIMIT 12",[$q]);
}
function fn_realizar_pedidos($conn)
{
    exigir('Administrador','Mesero');
    $s=encabezado(puede('Mesero')?'Realizar pedido':'Pedidos','Selecciona una mesa para comenzar o consulta el estado de los pedidos registrados.');
    $mesas=filas($conn,'SELECT id,sillas FROM mesas ORDER BY id');
    if (!isset($_GET['mesa_id'])) {
        $s.='<div class="MESAS">';
        if (puede('Mesero')) foreach($mesas as $m) $s.='<a class="MESA" href="'.h(url('realizar_pedidos',['mesa_id'=>$m['id']])).'">'.icono('mesa').'<strong>Mesa '.(int)$m['id'].'</strong><small>'.(int)$m['sillas'].' sillas · Tomar pedido</small></a>';
        $s.='</div><h2>Pedidos recientes</h2>';
        $where=puede('Administrador')?'TRUE':'p.mesero_id=$1';
        $params=puede('Administrador')?[]:[$_SESSION['usuario_id']];
        $rows=filas($conn,"SELECT p.*,u.nombre FROM pedidos p JOIN usuarios u ON u.id=p.cliente_id WHERE $where ORDER BY p.id DESC LIMIT 100",$params);
        $data=[];
        foreach($rows as $p) $data[]=[enlace('#'.$p['id'],'pedido',['id'=>$p['id']]),h($p['mesa_id']??'Histórica sin mesa'),h($p['nombre']),h($p['creado']??'Fecha histórica desconocida'),insignia(estado_pedido($conn,$p['id']))];
        return $s.tabla(['Pedido','Mesa','Cliente','Fecha','Estado'],$data).'<p>Se muestran hasta 100 pedidos recientes.</p>';
    }
    exigir('Mesero');
    $mesa=entero($_GET['mesa_id'],'Mesa');
    if (!fila($conn,'SELECT id FROM mesas WHERE id=$1',[$mesa])) throw new DomainException('Mesa inexistente.');
    $reservas=filas($conn,"SELECT r.id,r.cliente_id,u.nombre,h.inicio FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE h.mesa_id=$1 AND r.estado IN(1,2) AND LOCALTIMESTAMP>=h.inicio AND LOCALTIMESTAMP<h.inicio+greatest(h.duracion,interval '2 hours') ORDER BY h.inicio",[$mesa]);
    $reserva=null;
    if (isset($_GET['reservacion_id'])) {
        foreach($reservas as $r) if ((string)$r['id']===(string)$_GET['reservacion_id']) $reserva=$r;
        if (!$reserva) throw new DomainException('Reserva no vigente para esta mesa.');
    }
    foreach($reservas as $r) $s.=enlace('Usar reserva #'.$r['id'].' — '.$r['nombre'],'realizar_pedidos',['mesa_id'=>$mesa,'reservacion_id'=>$r['id']]);
    return $s.'<div class="PASOS"><span>1 · Mesa '.$mesa.'</span><b>→</b><span>2 · Cliente</span><b>→</b><span>3 · Platos y cantidades</span></div>'.enlace('← Elegir otra mesa','realizar_pedidos').formulario('pedido_guardar',oculto('mesa_id',$mesa).oculto('reservacion_id',$reserva['id']??'').selector_cliente($conn,$reserva['cliente_id']??null).formulario_items($conn),'Registrar pedido');
}
function fn_pedido($conn)
{
    $id=entero($_GET['id']??null,'Pedido');
    $p=pedido_acceso($conn,$id);
    $u=fila($conn,'SELECT nombre FROM usuarios WHERE id=$1',[$p['cliente_id']]);
    $s=encabezado('Pedido #'.$id,'Mesa '.($p['mesa_id']??'sin dato histórico').' · '.$u['nombre']).insignia(estado_pedido($conn,$id));
    $rows=filas($conn,'SELECT o.*,p.nombre,p.precio AS precio_actual FROM ordenes o JOIN platos p ON p.id=o.plato_id WHERE o.pedido_id=$1 ORDER BY o.id',[$id]);
    $estados=[1=>'En preparación (sin asignar)',2=>'En preparación',3=>'Entregado',4=>'Preparado'];
    $data=[];$total=0;$estimado=0;
    foreach($rows as $r) {
        $historico=$r['precio_unitario']===null;
        $valor=($historico?$r['precio_actual']:$r['precio_unitario'])*$r['cantidad'];
        if ($historico) $estimado+=$valor; else $total+=$valor;
        $accion=$r['estado']==4?formulario('entregar',oculto('pedido_id',$id).oculto('orden_id',$r['id']),'Entregar'):'';
        $data[]=[h($r['nombre']),h($r['cantidad']),insignia($estados[$r['estado']]),h(dinero($valor)).($historico?' (estimado)':''),$accion];
    }
    $s.=tabla(['Plato','Cantidad','Estado','Subtotal','Entrega'],$data).'<p>Total con precio registrado: '.h(dinero($total)).'. Estimación histórica adicional: '.h(dinero($estimado)).'.</p>';
    if (estado_pedido($conn,$id)!=='Entregado') $s.='<h2>Agregar ítems</h2>'.formulario('pedido_agregar',oculto('pedido_id',$id).formulario_items($conn),'Agregar al pedido');
    return $s;
}
function accion_cocina($conn)
{
    exigir('Cocinero');
    $id=entero($_POST['orden_id']??null,'Ítem');
    // Orden uniforme de bloqueos: pedido, orden, especialidad.
    $base=fila($conn,'SELECT pedido_id FROM ordenes WHERE id=$1',[$id]);
    if (!$base) throw new DomainException('Ítem inexistente.');
    db($conn,'SELECT id FROM pedidos WHERE id=$1 FOR UPDATE',[$base['pedido_id']]);
    $o=fila($conn,'SELECT * FROM ordenes WHERE id=$1 FOR UPDATE',[$id]);
    $especialidad=fila($conn,'SELECT id FROM especialidades WHERE cocinero_id=$1 AND plato_id=$2 FOR SHARE',[$_SESSION['usuario_id'],$o['plato_id']]);
    if (!$especialidad) throw new DomainException('No está autorizado para preparar este plato.');
    $paso=$_POST['paso']??'';
    if ($paso==='tomar') {
        if ((int)$o['estado']!==1) throw new DomainException('Otro cocinero ya tomó este ítem.');
        db($conn,'INSERT INTO preparaciones(cocinero_id,orden_id) VALUES($1,$2)',[$_SESSION['usuario_id'],$id]);
        db($conn,'UPDATE ordenes SET estado=2 WHERE id=$1',[$id]);
    } elseif ($paso==='preparar') {
        if ((int)$o['estado']!==2 || !fila($conn,'SELECT id FROM preparaciones WHERE orden_id=$1 AND cocinero_id=$2',[$id,$_SESSION['usuario_id']])) throw new DomainException('Solo puede finalizar sus propios ítems en preparación.');
        db($conn,'UPDATE ordenes SET estado=4,preparado=CURRENT_TIMESTAMP WHERE id=$1',[$id]);
    } else throw new DomainException('Transición inválida.');
    return url('cocina');
}
function fn_cocina($conn)
{
    exigir('Cocinero');
    $rows=filas($conn,'SELECT o.*,p.nombre,p.tiempo,pe.mesa_id,pr.cocinero_id FROM ordenes o JOIN platos p ON p.id=o.plato_id JOIN pedidos pe ON pe.id=o.pedido_id JOIN especialidades e ON e.plato_id=o.plato_id AND e.cocinero_id=$1 LEFT JOIN preparaciones pr ON pr.orden_id=o.id WHERE o.estado=1 OR (o.estado=2 AND pr.cocinero_id=$1) ORDER BY p.tiempo DESC,o.solicitado,o.id',[$_SESSION['usuario_id']]);
    $data=[];
    foreach($rows as $r) $data[]=[h($r['pedido_id']),h($r['mesa_id']??'Desconocida'),h($r['nombre']),h($r['cantidad']),h($r['tiempo']),h($r['solicitado']),formulario('cocina',oculto('orden_id',$r['id']).oculto('paso',$r['estado']==1?'tomar':'preparar'),$r['estado']==1?'Tomar':'Marcar preparado')];
    return encabezado('Preparación de platos','Toma una preparación de tu especialidad. Los platos de mayor tiempo aparecen primero.').tabla(['Pedido','Mesa','Plato','Cantidad','Tiempo','Solicitado','Acción'],$data);
}
