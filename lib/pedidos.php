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
function accion_pedido_reasignar_mesa($conn)
{
    $id=entero($_POST['pedido_id']??null,'Pedido');
    $p=pedido_acceso($conn,$id,true);
    if (estado_pedido($conn,$id)==='Entregado') throw new DomainException('El pedido ya está entregado.');
    $nuevaMesa=entero($_POST['nueva_mesa_id']??null,'Mesa');
    if (!fila($conn,'SELECT id FROM mesas WHERE id=$1',[$nuevaMesa])) throw new DomainException('Mesa destino inexistente.');
    $r=fila($conn,"SELECT r.cliente_id FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE h.mesa_id=$1 AND r.estado<>4 AND LOCALTIMESTAMP >= h.inicio AND LOCALTIMESTAMP < h.inicio+greatest(h.duracion,interval '2 hours') AND r.cliente_id<>$2 LIMIT 1",[$nuevaMesa,$p['cliente_id']]);
    if ($r) throw new DomainException('La mesa destino está reservada para otro cliente en este horario.');
    db($conn,'UPDATE pedidos SET mesa_id=$1 WHERE id=$2',[$nuevaMesa,$id]);
    return url('pedido',['id'=>$id]);
}
function accion_orden_cancelar($conn)
{
    $id=entero($_POST['pedido_id']??null,'Pedido');
    pedido_acceso($conn,$id,true);
    $orden=entero($_POST['orden_id']??null,'Ítem');
    $o=fila($conn,'SELECT * FROM ordenes WHERE id=$1 AND pedido_id=$2 FOR UPDATE',[$orden,$id]);
    if (!$o) throw new DomainException('Ítem inexistente.');
    if ((int)$o['estado']!==1) throw new DomainException('No se puede cancelar este plato porque ya fue tomado por cocina o está preparado.');
    db($conn,'DELETE FROM ordenes WHERE id=$1',[$orden]);
    return url('pedido',['id'=>$id]);
}
function estado_pedido_html($st)
{
    $tonos=['Entregado'=>['Cerrado','neutro'],'Preparado'=>['Listo para servir','ok'],'En preparación'=>['En cocina','alerta']];
    [$t,$tono]=$tonos[$st]??[$st,'neutro'];
    return estado($t,$tono);
}
function estado_orden_html($e)
{
    $m=[1=>['En cola','alerta'],2=>['Preparando','info'],4=>['Listo','ok'],3=>['Servido','neutro']];
    [$t,$tono]=$m[(int)$e]??['—','neutro'];
    return estado($t,$tono);
}
function formulario_items($conn)
{
    $rows=filas($conn,'SELECT p.id,p.nombre,p.precio,t.nombre AS tipo FROM platos p JOIN tipos t ON t.id=p.tipo_id WHERE p.activo ORDER BY t.nombre,p.nombre');
    if (!$rows) return '<p class="VACIO">No hay platos disponibles.</p>';
    $tipos=array_values(array_unique(array_column($rows,'tipo')));
    $s='<div class="CARTA_FILTROS"><input type="search" class="BUSCADOR_PLATOS" placeholder="Buscar plato" aria-label="Buscar plato">';
    $s.='<div class="PILLS_CATEGORIAS" role="group" aria-label="Categorías"><button type="button" class="PILL_ACTIVA" data-categoria="">Todo</button>';
    foreach($tipos as $t) $s.='<button type="button" data-categoria="'.h($t).'">'.h($t).'</button>';
    $s.='</div></div>';
    $tipo=null;
    foreach($rows as $p) {
        if ($tipo!==$p['tipo']) {
            if ($tipo!==null) $s.='</div>';
            $tipo=$p['tipo'];
            $s.='<div class="CARTA_GRUPO" data-tipo="'.h($tipo).'"><h3>'.h($tipo).'</h3>';
        }
        $s.='<div class="PLATO_FILA" data-nombre="'.h(mb_strtolower($p['nombre'])).'">'
            .'<span class="PLATO_NOMBRE">'.h($p['nombre']).'</span>'
            .'<span class="PLATO_PRECIO">'.h(dinero($p['precio'])).'</span>'
            .'<span class="STEPPER"><button type="button" class="BTN_STEP BTN_MENOS" aria-label="Quitar uno">−</button>'
            .'<input type="number" name="cantidad['.(int)$p['id'].']" min="0" max="1000" value="0" inputmode="numeric" data-precio="'.h($p['precio']).'" aria-label="Cantidad de '.h($p['nombre']).'">'
            .'<button type="button" class="BTN_STEP BTN_MAS" aria-label="Agregar uno">+</button></span></div>';
    }
    return $s.'</div>';
}
// Carta a la izquierda, resumen fijo a la derecha con el único botón de envío.
function comanda($conn,$extraResumen,$etiquetaBoton)
{
    return '<div class="COMANDA ITEMS_PEDIDO"><div class="COMANDA_CARTA">'.formulario_items($conn).'</div>'
        .'<aside class="COMANDA_RESUMEN">'.$extraResumen
        .'<h3>Platos</h3><ol class="RESUMEN_LISTA"><li class="RESUMEN_VACIO">Aún no ha agregado platos.</li></ol>'
        .'<div class="RESUMEN_TOTAL"><span>Total</span><output class="TOTAL_PEDIDO">$0</output></div>'
        .'<button type="submit" class="BTN BTN_BLOQUE BTN_COMANDA">'.h($etiquetaBoton).'</button>'
        .'<p class="NOTA">El precio final se confirma al guardar.</p></aside></div>';
}
function selector_cliente($conn,$seleccionado=null)
{
    $clientes=filas($conn,"SELECT u.id,u.nombre FROM usuarios u JOIN actuaciones a ON a.usuario_id=u.id JOIN roles r ON r.id=a.rol_id WHERE u.activo AND r.nombre='Cliente' ORDER BY u.nombre");
    $s='<label>Cliente <select name="cliente_id" required><option value="">Seleccione un cliente</option>';
    foreach($clientes as $c) $s.='<option value="'.(int)$c['id'].'"'.((string)$seleccionado===(string)$c['id']?' selected':'').'>'.h($c['nombre']).' (#'.(int)$c['id'].')</option>';
    $s.='</select>';
    // Solo Maitre y Administrador pueden registrar clientes.
    if (puede('Administrador','Maitre')) $s.='<span class="NOTA">'.enlace('Registrar cliente nuevo','clientes',['nuevo'=>1]).'</span>';
    return $s.'</label>';
}
function fn_realizar_pedidos($conn)
{
    exigir('Administrador','Mesero');
    if (isset($_GET['mesa_id'])) return fn_tomar_pedido($conn);
    $mesero=puede('Mesero');
    $mesas=filas($conn,'SELECT id,sillas FROM mesas ORDER BY id');
    $abiertos=filas($conn,"SELECT p.id,p.mesa_id,u.nombre AS cliente,count(o.id) AS total,count(o.id) FILTER(WHERE o.estado=4) AS listos FROM pedidos p JOIN usuarios u ON u.id=p.cliente_id JOIN ordenes o ON o.pedido_id=p.id GROUP BY p.id,p.mesa_id,u.nombre HAVING count(o.id) > count(o.id) FILTER(WHERE o.estado=3)");
    $porMesa=[];
    foreach($abiertos as $a) $porMesa[(int)$a['mesa_id']]=$a;
    $reservas=filas($conn,"SELECT h.mesa_id,r.id,u.nombre AS cliente,h.inicio FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE r.estado IN (1,2) AND LOCALTIMESTAMP >= h.inicio AND LOCALTIMESTAMP < h.inicio+greatest(h.duracion,interval '2 hours')");
    $resMesa=[];
    foreach($reservas as $r) $resMesa[(int)$r['mesa_id']]=$r;

    $ocupadas=0;$reservadas=0;$tarjetas='';
    foreach($mesas as $m) {
        $id=(int)$m['id'];$href=null;$detalle='';
        if (isset($porMesa[$id])) {
            $p=$porMesa[$id];$ocupadas++;
            $clase='MESA_OCUPADA';$estado='Ocupada';
            $detalle=h($p['cliente']).'<br>'.(int)$p['total'].' platos'.((int)$p['listos']>0?' · <b class="MESA_LISTOS">'.(int)$p['listos'].' listos</b>':'');
            $href=url('pedido',['id'=>$p['id']]);
        } elseif (isset($resMesa[$id])) {
            $r=$resMesa[$id];$reservadas++;
            $clase='MESA_RESERVADA';$estado='Reservada '.hora_corta($r['inicio']);
            $detalle=h($r['cliente']);
            if ($mesero) $href=url('realizar_pedidos',['mesa_id'=>$id,'reservacion_id'=>$r['id']]);
        } else {
            $clase='MESA_LIBRE';$estado='Libre';
            if ($mesero) $href=url('realizar_pedidos',['mesa_id'=>$id]);
        }
        $tag=$href?'a':'div';
        $tarjetas.='<'.$tag.' class="MESA '.$clase.'"'.($href?' href="'.h($href).'"':'').'>'
            .'<span class="MESA_NUM"><small>Mesa</small>'.$id.'</span><span class="MESA_SILLAS">'.(int)$m['sillas'].' puestos</span>'
            .'<span class="MESA_ESTADO">'.h($estado).'</span><span class="MESA_DETALLE">'.$detalle.'</span></'.$tag.'>';
    }
    $meta=count($mesas).' mesas · '.$ocupadas.' ocupadas · '.$reservadas.' con reserva ahora';
    $s=encabezado('Sala',h($meta));
    $s.='<div class="PLANO_SALA">'.$tarjetas.'</div>';
    if ($mesero) $s.='<p class="NOTA">Toque una mesa libre para abrir un pedido, o una ocupada para atenderla.</p>';

    $where=puede('Administrador')?'TRUE':'p.mesero_id=$1';
    $params=puede('Administrador')?[]:[$_SESSION['usuario_id']];
    $rows=filas($conn,"SELECT p.*,u.nombre FROM pedidos p JOIN usuarios u ON u.id=p.cliente_id WHERE $where ORDER BY p.id DESC LIMIT 25",$params);
    $data=[];
    foreach($rows as $p) $data[]=[enlace('#'.$p['id'],'pedido',['id'=>$p['id']]),h($p['mesa_id']??'—'),h($p['nombre']),h(fecha_corta($p['creado'])),estado_pedido_html(estado_pedido($conn,$p['id']))];
    return $s.seccion($mesero?'Mis últimos pedidos':'Últimos pedidos',tabla(['Pedido','Mesa','Cliente','Fecha','Estado'],$data,'Aún no hay pedidos.'));
}
function fn_tomar_pedido($conn)
{
    exigir('Mesero');
    $mesa=entero($_GET['mesa_id'],'Mesa');
    if (!fila($conn,'SELECT id FROM mesas WHERE id=$1',[$mesa])) throw new DomainException('Mesa inexistente.');
    $reservas=filas($conn,"SELECT r.id,r.cliente_id,u.nombre,h.inicio FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE h.mesa_id=$1 AND r.estado IN(1,2) AND LOCALTIMESTAMP>=h.inicio AND LOCALTIMESTAMP<h.inicio+greatest(h.duracion,interval '2 hours') ORDER BY h.inicio",[$mesa]);
    $reserva=null;
    if (isset($_GET['reservacion_id'])) {
        foreach($reservas as $r) if ((string)$r['id']===(string)$_GET['reservacion_id']) $reserva=$r;
        if (!$reserva) throw new DomainException('Reserva no vigente para esta mesa.');
    }
    $meta=$reserva?'Reserva de '.h($reserva['nombre']).' · '.h(hora_corta($reserva['inicio'])):'Sin reserva';
    $s=encabezado('Mesa '.$mesa.' · nuevo pedido',$meta,boton('Volver a la sala','realizar_pedidos',[],'BTN BTN_SEC'));
    if (!$reserva) foreach($reservas as $r)
        $s.='<div class="AVISO"><span>Esta mesa está reservada para <strong>'.h($r['nombre']).'</strong> a las '.h(hora_corta($r['inicio'])).'.</span>'.boton('Usar la reserva','realizar_pedidos',['mesa_id'=>$mesa,'reservacion_id'=>$r['id']],'BTN BTN_SEC BTN_SM').'</div>';
    return $s.formulario('pedido_guardar',oculto('mesa_id',$mesa).oculto('reservacion_id',$reserva['id']??'').comanda($conn,selector_cliente($conn,$reserva['cliente_id']??null),'Enviar a cocina'),null);
}
function fn_pedido($conn)
{
    $id=entero($_GET['id']??null,'Pedido');
    $p=pedido_acceso($conn,$id);
    $u=fila($conn,'SELECT nombre FROM usuarios WHERE id=$1',[$p['cliente_id']]);
    $st=estado_pedido($conn,$id);
    $meta='Mesa '.h($p['mesa_id']??'—').' · '.h($u['nombre']).($p['creado']?' · '.h(fecha_corta($p['creado'])):'');
    $s=encabezado('Pedido #'.$id.' '.estado_pedido_html($st),$meta,boton('Precuenta','factura_pedido',['id'=>$id],'BTN BTN_SEC'));
    $rows=filas($conn,'SELECT o.*,p.nombre,p.precio AS precio_actual FROM ordenes o JOIN platos p ON p.id=o.plato_id WHERE o.pedido_id=$1 ORDER BY o.id',[$id]);
    $data=[];$total=0;$estimado=0;$listos=0;
    foreach($rows as $r) {
        $historico=$r['precio_unitario']===null;
        $valor=($historico?$r['precio_actual']:$r['precio_unitario'])*$r['cantidad'];
        if ($historico) $estimado+=$valor; else $total+=$valor;
        $accion='';
        if ($r['estado']==4) { $listos++; $accion=formulario('entregar',oculto('pedido_id',$id).oculto('orden_id',$r['id']),'Servir','BTN BTN_SM'); }
        elseif ($r['estado']==1) $accion=formulario('orden_cancelar',oculto('pedido_id',$id).oculto('orden_id',$r['id']),'Quitar','BTN_LINK BTN_PELIGRO');
        $data[]=[h($r['nombre']),num((int)$r['cantidad']),estado_orden_html($r['estado']),num(h(dinero($valor)).($historico?'*':'')),$accion];
    }
    if ($listos) $s.='<div class="AVISO AVISO_OK">'.$listos.' '.($listos===1?'plato listo':'platos listos').' para servir.</div>';
    $s.=tabla(['Plato','Cant.','Estado','Subtotal',''],$data,'Este pedido no tiene platos.');
    $s.='<div class="TOTAL_LINEA"><span>Total</span><strong>'.h(dinero($total+$estimado)).'</strong></div>';
    if ($estimado>0) $s.='<p class="NOTA">* Registro histórico sin precio original; se usa el precio actual.</p>';
    if ($st!=='Entregado') {
        $s.=plegable('Agregar platos',formulario('pedido_agregar',oculto('pedido_id',$id).comanda($conn,'','Agregar al pedido'),null),!$rows);
        $mesas=filas($conn,"SELECT id,'Mesa '||id||' · '||sillas||' puestos' AS nombre FROM mesas WHERE id IS DISTINCT FROM $1 ORDER BY id",[$p['mesa_id']]);
        $s.=plegable('Cambiar de mesa',formulario('pedido_reasignar_mesa',oculto('pedido_id',$id).'<label>Mesa destino <select name="nueva_mesa_id" required>'.opciones($mesas,'id','nombre').'</select></label>','Mover pedido','BTN BTN_SEC'));
    }
    return $s;
}
function fn_factura_pedido($conn)
{
    $id=entero($_GET['id']??null,'Pedido');
    $p=pedido_acceso($conn,$id);
    $cliente=fila($conn,'SELECT nombre FROM usuarios WHERE id=$1',[$p['cliente_id']]);
    $mesero=fila($conn,'SELECT nombre FROM usuarios WHERE id=$1',[$p['mesero_id']]);
    $items=filas($conn,'SELECT o.*,pl.nombre,pl.precio AS precio_vigente FROM ordenes o JOIN platos pl ON pl.id=o.plato_id WHERE o.pedido_id=$1 ORDER BY o.id',[$id]);
    $subtotal=0;$lineas='';
    foreach($items as $it) {
        $precio=$it['precio_unitario']!==null?(float)$it['precio_unitario']:(float)$it['precio_vigente'];
        $valor=$precio*(int)$it['cantidad'];
        $subtotal+=$valor;
        $lineas.='<tr><td>'.(int)$it['cantidad'].'</td><td>'.h($it['nombre']).'</td><td class="NUM">'.h(dinero($valor)).'</td></tr>';
    }
    $servicio=$subtotal*0.10;
    $s=encabezado('Precuenta · pedido #'.$id,'',boton('Volver al pedido','pedido',['id'=>$id],'BTN BTN_SEC').'<button type="button" class="BTN" onclick="window.print()">Imprimir</button>');
    $s.='<div class="TICKET">';
    $s.='<div class="TICKET_HEAD"><strong>URBANO</strong><span>Fine dining · Bogotá</span><span>NIT 900.824.112-5</span></div>';
    $s.='<dl class="TICKET_INFO"><dt>Mesa</dt><dd>'.h($p['mesa_id']??'—').'</dd><dt>Fecha</dt><dd>'.h(fecha_corta($p['creado']??date('Y-m-d H:i'))).'</dd><dt>Cliente</dt><dd>'.h($cliente['nombre']??'—').'</dd><dt>Mesero</dt><dd>'.h($mesero['nombre']??'—').'</dd></dl>';
    $s.='<table class="TICKET_ITEMS"><tbody>'.$lineas.'</tbody></table>';
    $s.='<dl class="TICKET_TOTALES"><dt>Subtotal</dt><dd>'.h(dinero($subtotal)).'</dd><dt>Servicio sugerido (10 %, voluntario)</dt><dd>'.h(dinero($servicio)).'</dd><dt class="TICKET_TOTAL">Total</dt><dd class="TICKET_TOTAL">'.h(dinero($subtotal+$servicio)).'</dd></dl>';
    $s.='<p class="TICKET_PIE">Gracias por su visita. Documento informativo, no es factura electrónica.</p></div>';
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
function tarjeta_cocina($r,$paso,$etiqueta)
{
    return '<div class="TICKET_COCINA"><div class="TC_META"><strong>Mesa '.h($r['mesa_id']??'—').'</strong><span>#'.(int)$r['pedido_id'].' · '.h(hora_corta($r['solicitado'])).'</span></div>'
        .'<div class="TC_PLATO"><span class="TC_CANT">'.(int)$r['cantidad'].'×</span> '.h($r['nombre']).'</div>'
        .'<div class="TC_PIE"><span>'.(int)ceil((float)$r['minutos']).' min</span>'.formulario('cocina',oculto('orden_id',$r['id']).oculto('paso',$paso),$etiqueta,'BTN BTN_SM').'</div></div>';
}
function fn_cocina($conn)
{
    exigir('Cocinero');
    $rows=filas($conn,'SELECT o.*,p.nombre,extract(epoch FROM p.tiempo)/60 AS minutos,pe.mesa_id,pr.cocinero_id FROM ordenes o JOIN platos p ON p.id=o.plato_id JOIN pedidos pe ON pe.id=o.pedido_id JOIN especialidades e ON e.plato_id=o.plato_id AND e.cocinero_id=$1 LEFT JOIN preparaciones pr ON pr.orden_id=o.id WHERE o.estado=1 OR (o.estado=2 AND pr.cocinero_id=$1) ORDER BY p.tiempo DESC,o.solicitado,o.id',[$_SESSION['usuario_id']]);
    $cola='';$prep='';$nc=0;$np=0;
    foreach($rows as $r) {
        if ($r['estado']==1) { $nc++; $cola.=tarjeta_cocina($r,'tomar','Tomar'); }
        else { $np++; $prep.=tarjeta_cocina($r,'preparar','Listo'); }
    }
    $s=encabezado('Cocina',$nc.' en cola · '.$np.' preparando · los platos más largos primero');
    $s.='<div class="COCINA">';
    $s.='<section class="COCINA_COL"><h2>En cola <span>'.$nc.'</span></h2>'.($cola?:'<p class="VACIO">Nada en cola.</p>').'</section>';
    $s.='<section class="COCINA_COL"><h2>Preparando <span>'.$np.'</span></h2>'.($prep?:'<p class="VACIO">No está preparando nada.</p>').'</section>';
    return $s.'</div>';
}
