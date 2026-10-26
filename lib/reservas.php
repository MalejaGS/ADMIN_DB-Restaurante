<?php
function inicio_reserva($value)
{
    if (!is_string($value)) throw new DomainException('Fecha y hora inválidas.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
    if (!$d || $d->format('Y-m-d\TH:i')!==$value) throw new DomainException('Fecha y hora inválidas.');
    if ($d < new DateTimeImmutable('now')) throw new DomainException('La reserva debe comenzar en el futuro.');
    return $d->format('Y-m-d H:i:s');
}
function validar_reserva($conn,$id,$mesa,$cliente,$cantidad,$inicio)
{
    $m=fila($conn,'SELECT sillas FROM mesas WHERE id=$1 FOR SHARE',[$mesa]);
    if (!$m) throw new DomainException('Mesa inexistente.');
    if ($cantidad>(int)$m['sillas']) throw new DomainException('La cantidad de personas supera las sillas de la mesa.');
    cliente_valido($conn,$cliente);
    $conflicto=fila($conn,"SELECT h.id FROM horarios h JOIN reservaciones r ON r.id=h.reservacion_id WHERE r.estado<>4 AND r.id<>$1 AND (h.mesa_id=$2 OR r.cliente_id=$3) AND tsrange(h.inicio,h.inicio+greatest(h.duracion,interval '2 hours'),'[)') && tsrange($4::timestamp,$4::timestamp+interval '2 hours','[)') LIMIT 1",[$id,$mesa,$cliente,$inicio]);
    if ($conflicto) throw new DomainException('La mesa o el cliente ya tienen una reserva en ese horario (ocupación de dos horas).');
    // Evaluar cada comienzo dentro de la ventana: sumar todas las reservas que
    // intersectan la ventana sobreestimaría el cupo cuando no coinciden entre sí.
    $cupo=fila($conn,"WITH ocupaciones AS (
      SELECT h.inicio,h.inicio+greatest(h.duracion,interval '2 hours') AS fin,r.cantidad
      FROM horarios h JOIN reservaciones r ON r.id=h.reservacion_id
      WHERE r.estado<>4 AND r.id<>$1 AND h.inicio < $2::timestamp+interval '2 hours'
        AND h.inicio+greatest(h.duracion,interval '2 hours') > $2::timestamp
    ), puntos AS (SELECT $2::timestamp AS t UNION SELECT inicio FROM ocupaciones WHERE inicio >= $2::timestamp)
    SELECT COALESCE((SELECT max((SELECT COALESCE(sum(cantidad),0) FROM ocupaciones o WHERE o.inicio<=p.t AND o.fin>p.t)) FROM puntos p),0) AS pico,
           (SELECT COALESCE(sum(sillas),0) FROM mesas) AS cupo",[$id,$inicio]);
    if ((int)$cupo['pico']+$cantidad>(int)$cupo['cupo']) throw new DomainException('Se supera el cupo total del restaurante para ese horario.');
}
function accion_reserva_guardar($conn)
{
    exigir('Maitre','Administrador');
    // Un único lock de planificación protege creación, modificación y cancelación.
    db($conn,'SELECT pg_advisory_xact_lock(202603,2)');
    $id=($_POST['id']??'')!==''?entero($_POST['id'],'Reserva'):0;
    $mesa=entero($_POST['mesa_id']??null,'Mesa');
    $cliente=entero($_POST['cliente_id']??null,'Cliente');
    $cantidad=entero($_POST['personas']??null,'Personas');
    $inicio=inicio_reserva($_POST['inicio']??'');
    if ($id) {
        $r=fila($conn,'SELECT * FROM reservaciones WHERE id=$1 FOR UPDATE',[$id]);
        if (!$r || !in_array((int)$r['estado'],[1,2],true)) throw new DomainException('Solo se pueden modificar reservas pendientes o confirmadas.');
        $horarios=filas($conn,'SELECT id FROM horarios WHERE reservacion_id=$1 FOR UPDATE',[$id]);
        if (count($horarios)!==1) throw new DomainException('Esta reserva histórica necesita revisión: no tiene un único horario.');
        if (fila($conn,'SELECT id FROM pedidos WHERE reservacion_id=$1 LIMIT 1',[$id])) throw new DomainException('No se puede modificar una reserva con pedidos registrados.');
    }
    validar_reserva($conn,$id,$mesa,$cliente,$cantidad,$inicio);
    if ($id) {
        db($conn,'UPDATE reservaciones SET cliente_id=$1,cantidad=$2 WHERE id=$3',[$cliente,$cantidad,$id]);
        db($conn,"UPDATE horarios SET mesa_id=$1,inicio=$2,duracion=interval '2 hours' WHERE reservacion_id=$3",[$mesa,$inicio,$id]);
    } else {
        $r=fila($conn,'INSERT INTO reservaciones(cliente_id,cantidad,estado) VALUES($1,$2,2) RETURNING id',[$cliente,$cantidad]);
        $id=(int)$r['id'];
        db($conn,"INSERT INTO horarios(mesa_id,reservacion_id,inicio,duracion) VALUES($1,$2,$3,interval '2 hours')",[$mesa,$id,$inicio]);
    }
    return url('reservas',['fecha'=>substr($inicio,0,10)]);
}
function accion_reserva_cancelar($conn)
{
    exigir('Maitre','Administrador');
    db($conn,'SELECT pg_advisory_xact_lock(202603,2)');
    $id=entero($_POST['id']??null,'Reserva');
    $r=fila($conn,'SELECT estado FROM reservaciones WHERE id=$1 FOR UPDATE',[$id]);
    if (!$r || !in_array((int)$r['estado'],[1,2],true)) throw new DomainException('La reserva ya fue cancelada o completada.');
    if (fila($conn,'SELECT id FROM pedidos WHERE reservacion_id=$1 LIMIT 1',[$id])) throw new DomainException('No se puede cancelar una reserva con pedidos registrados.');
    db($conn,'UPDATE reservaciones SET estado=4 WHERE id=$1',[$id]);
    return url('reservas');
}
function fn_reservas($conn)
{
    exigir('Maitre','Administrador');
    $dia=fecha($_GET['fecha']??date('Y-m-d'));
    $s='<h1>Reservaciones</h1><form method="get">'.oculto('opcion','reservas').campo('fecha','Fecha',$dia,'date','required').'<button>Consultar</button></form>';
    $consulta=isset($_GET['inicio_consulta'])?inicio_reserva($_GET['inicio_consulta']):null;
    $personas=isset($_GET['personas_consulta'])?entero($_GET['personas_consulta'],'Personas'):1;
    $s.='<details'.($consulta?' open':'').'><summary>Consultar disponibilidad y franjas horarias</summary><form method="get">'.oculto('opcion','reservas').oculto('fecha',$dia).campo('inicio_consulta','Inicio',isset($_GET['inicio_consulta'])?$_GET['inicio_consulta']:(new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i'),'datetime-local','required').campo('personas_consulta','Personas',$personas,'number','min="1" required').'<button>Consultar mesas</button></form>';

    $cupoMesas=fila($conn,'SELECT COALESCE(sum(sillas),0) AS total_sillas FROM mesas');
    $aforoTotal=(int)$cupoMesas['total_sillas'];
    $slots=['12:00','14:00','16:00','18:00','20:00','22:00'];
    $dataSlots=[];
    foreach($slots as $slot) {
        $slotInicio=$dia.' '.$slot;
        $slotRes=fila($conn,"SELECT count(DISTINCT r.id) AS reservas, COALESCE(sum(r.cantidad), 0) AS comensales FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.estado<>4 AND tsrange(h.inicio, h.inicio+greatest(h.duracion, interval '2 hours'), '[)') && tsrange($1::timestamp, $1::timestamp+interval '2 hours', '[)')",[$slotInicio]);
        $ocupados=(int)$slotRes['comensales'];
        $libres=max(0,$aforoTotal-$ocupados);
        $porc=$aforoTotal>0?round(($ocupados/$aforoTotal)*100):0;
        $estadoSlot=$libres>0?'Disponible ('.$libres.' cupos)':'Completo';
        $accionSlot=enlace('Elegir franja '.$slot,'reservas',['fecha'=>$dia,'inicio_consulta'=>$dia.'T'.$slot,'personas_consulta'=>$personas]);
        $dataSlots[]=[h($slot.' a '.date('H:i',strtotime($slot.' +2 hours'))),h($slotRes['reservas']),h($ocupados.' / '.$aforoTotal.' ('.$porc.'%)'),h($estadoSlot),$accionSlot];
    }
    $s.='<h3>Disponibilidad por franja horaria (día '.$dia.')</h3>'.tabla(['Franja de 2 horas','Reservas','Ocupación','Estado','Acción rápida'],$dataSlots);

    if ($consulta) {
        // mesas es distribuida en la instalación real (Citus); consultar por
        // separado evita un join correlacionado entre datos locales y shards.
        $candidatas=filas($conn,'SELECT id,sillas FROM mesas WHERE sillas >= $1 ORDER BY sillas,id',[$personas]);
        $ocupadas=array_column(filas($conn,"SELECT DISTINCT h.mesa_id FROM horarios h JOIN reservaciones r ON r.id=h.reservacion_id WHERE r.estado<>4 AND tsrange(h.inicio,h.inicio+greatest(h.duracion,interval '2 hours'),'[)') && tsrange($1::timestamp,$1::timestamp+interval '2 hours','[)')",[$consulta]),'mesa_id');
        $disponibles=array_filter($candidatas,function($m)use($ocupadas){return !in_array($m['id'],$ocupadas,true);});
        $data=[];
        foreach($disponibles as $m) $data[]=[h($m['id']),h($m['sillas']),enlace('Seleccionar','reservas',['fecha'=>substr($consulta,0,10),'mesa_id'=>$m['id'],'inicio_consulta'=>$_GET['inicio_consulta'],'personas_consulta'=>$personas])];
        $s.=tabla(['Mesa disponible','Sillas','Acción'],$data).'<p>La disponibilidad se confirma nuevamente al guardar.</p>';
    }
    $s.='</details>';
    $r=null;
    if (isset($_GET['id'])) {
        $id=entero($_GET['id'],'Reserva');
        $rs=filas($conn,'SELECT r.*,h.mesa_id,h.inicio FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id WHERE r.id=$1',[$id]);
        if (count($rs)!==1) throw new DomainException('Reserva inexistente o con varios horarios históricos; requiere revisión.');
        $r=$rs[0];
        if (!in_array((int)$r['estado'],[1,2],true)) throw new DomainException('La reserva no es editable.');
    }
    $mesas=filas($conn,"SELECT id,'Mesa '||id||' ('||sillas||' sillas)' AS nombre FROM mesas ORDER BY id");
    $s.='<h2>'.($r?'Modificar reserva #'.(int)$r['id']:'Crear reserva').'</h2>'.formulario('reserva_guardar',oculto('id',$r['id']??'').selector_cliente($conn,$r['cliente_id']??null).'<label>Mesa <select name="mesa_id" required>'.opciones($mesas,'id','nombre',$r['mesa_id']??(isset($_GET['mesa_id'])?entero($_GET['mesa_id'],'Mesa'):null)).'</select></label>'.campo('inicio','Inicio',isset($r['inicio'])?date('Y-m-d\TH:i',strtotime($r['inicio'])):($consulta?date('Y-m-d\TH:i',strtotime($consulta)):$dia.'T12:00'),'datetime-local','required').campo('personas','Personas',$r['cantidad']??$personas,'number','min="1" required').'<p>Duración: dos horas. La disponibilidad se valida al guardar.</p>');
    $rows=filas($conn,'SELECT r.*,h.mesa_id,h.inicio,u.nombre FROM reservaciones r JOIN horarios h ON h.reservacion_id=r.id JOIN usuarios u ON u.id=r.cliente_id WHERE h.inicio >= $1::date AND h.inicio < $1::date+interval \'1 day\' ORDER BY h.inicio,r.id',[$dia]);
    $estados=[1=>'Pendiente',2=>'Confirmada',3=>'Completada',4=>'Cancelada'];$data=[];
    foreach($rows as $row) {
        $acciones='';
        if (in_array((int)$row['estado'],[1,2],true)) $acciones=enlace('Modificar','reservas',['id'=>$row['id'],'fecha'=>$dia]).formulario('reserva_cancelar',oculto('id',$row['id']),'Cancelar reserva');
        $data[]=[h($row['id']),h($row['nombre']),h($row['mesa_id']),h($row['inicio']),h($row['cantidad']),h($estados[$row['estado']]),$acciones];
    }
    $s.=tabla(['Reserva','Cliente','Mesa','Inicio','Personas','Estado','Acciones'],$data);
    $cupo=fila($conn,'SELECT COALESCE(sum(sillas),0) AS total FROM mesas');
    $conflictos=filas($conn,'SELECT c.* FROM restaurante_conflictos_reservas c JOIN horarios h ON h.id=c.horario_1 WHERE h.inicio >= CURRENT_DATE ORDER BY h.inicio LIMIT 50');
    $s.='<p>Cupo total: '.h($cupo['total']).' personas.</p>';
    if ($conflictos) {
        $s.='<h2>Conflictos existentes al estimar dos horas</h2><p>Revise estas reservas antes de reasignar mesas. No se han modificado automáticamente.</p>';
        $s.=tabla(['Mesa','Reserva 1','Reserva 2'],array_map(function($v){return [h($v['mesa_id']),h($v['reserva_1']),h($v['reserva_2'])];},$conflictos));
    }
    return $s;
}
function accion_cliente_guardar($conn)
{
    exigir('Administrador','Maitre');
    $nombre=texto($_POST['nombre']??'','Nombre',150);
    $rol=fila($conn,"SELECT id FROM roles WHERE nombre='Cliente'");
    if (!$rol) throw new DomainException('Falta el rol Cliente en la base de datos.');
    // El cliente tiene interacción indirecta: su clave aleatoria no se distribuye.
    $u=fila($conn,"INSERT INTO usuarios(nombre,clave,fecha_clave) VALUES($1,decode($2,'hex'),LOCALTIMESTAMP) RETURNING id",[$nombre,bin2hex(password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT))]);
    db($conn,'INSERT INTO actuaciones(rol_id,usuario_id) VALUES($1,$2)',[$rol['id'],$u['id']]);
    return url('clientes',['id'=>$u['id']]);
}
function fn_clientes($conn)
{
    exigir('Administrador','Maitre','Mesero');
    $q=isset($_GET['q'])&&is_string($_GET['q'])?trim($_GET['q']):'';
    if (mb_strlen($q)>150) throw new DomainException('Búsqueda demasiado larga.');
    $id=isset($_GET['id'])?entero($_GET['id'],'Cliente'):null;

    if ($id !== null) {
        $cliente=fila($conn,"SELECT u.id,u.nombre,u.activo FROM usuarios u WHERE u.id=$1 AND EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre='Cliente')",[$id]);
        if (!$cliente) throw new DomainException('Cliente no encontrado.');

        $reservas=filas($conn,"SELECT r.id,r.cantidad,r.estado,h.mesa_id,h.inicio FROM reservaciones r LEFT JOIN horarios h ON h.reservacion_id=r.id WHERE r.cliente_id=$1 ORDER BY h.inicio DESC NULLS LAST, r.id DESC",[$id]);
        $pedidos=filas($conn,"SELECT p.id,p.mesa_id,p.creado,
            (SELECT string_agg(o.cantidad || 'x ' || pl.nombre, ', ' ORDER BY pl.nombre) FROM ordenes o JOIN platos pl ON pl.id=o.plato_id WHERE o.pedido_id=p.id) AS platos_detalle,
            (SELECT COALESCE(sum(o.cantidad * COALESCE(o.precio_unitario, pl.precio)), 0) FROM ordenes o JOIN platos pl ON pl.id=o.plato_id WHERE o.pedido_id=p.id) AS total_calculado
            FROM pedidos p WHERE p.cliente_id=$1 ORDER BY p.id DESC",[$id]);

        $gastoTotal=0;
        foreach($pedidos as $ped) $gastoTotal += (float)$ped['total_calculado'];
        $estadosReserva=[1=>'Pendiente',2=>'Confirmada',3=>'Completada',4=>'Cancelada'];

        $s='<h1>Historial del Cliente #'.(int)$cliente['id'].' · '.h($cliente['nombre']).'</h1>';
        $s.='<p>'.enlace('← Volver a la búsqueda de clientes','clientes').'</p>';
        $s.='<div class="RESUMEN_CLIENTE">';
        $s.='<div class="RESUMEN_CARD"><span>Reservaciones</span><strong>'.count($reservas).'</strong></div>';
        $s.='<div class="RESUMEN_CARD"><span>Pedidos</span><strong>'.count($pedidos).'</strong></div>';
        $s.='<div class="RESUMEN_CARD"><span>Gasto total</span><strong>'.h(dinero($gastoTotal)).'</strong></div>';
        $s.='</div>';

        $s.='<h2>Historial de Reservaciones</h2>';
        $resData=[];
        foreach($reservas as $r) {
            $acciones=enlace('Ver en reservas','reservas',['id'=>$r['id']]);
            $resData[]=[h($r['id']),h($r['inicio']??'Sin horario'),h($r['mesa_id']??'Sin mesa'),h($r['cantidad']),h($estadosReserva[$r['estado']]??'Desconocido'),$acciones];
        }
        $s.=tabla(['Reserva #','Fecha y Hora','Mesa','Personas','Estado','Acción'],$resData);

        $s.='<h2>Historial de Pedidos</h2>';
        $pedData=[];
        foreach($pedidos as $p) {
            $estadoP=estado_pedido($conn,$p['id']);
            $pedData[]=[enlace('#'.$p['id'],'pedido',['id'=>$p['id']]),h($p['creado']??'Histórico sin fecha'),h($p['mesa_id']??'Sin mesa'),h($p['platos_detalle']??'Sin platos'),h(dinero($p['total_calculado'])),h($estadoP),enlace('Ver pedido','pedido',['id'=>$p['id']])];
        }
        $s.=tabla(['Pedido #','Fecha','Mesa','Ítems solicitados','Total estimado','Estado','Acción'],$pedData);

        return $s;
    }

    $rows=filas($conn,"SELECT u.id,u.nombre FROM usuarios u WHERE u.activo AND EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre='Cliente') AND ($1::text='' OR u.nombre ILIKE '%'||$1||'%') ORDER BY u.nombre,u.id LIMIT 100",[$q]);
    $tablaData=[];
    foreach($rows as $r) {
        $tablaData[]=[
            h($r['id']),
            h($r['nombre']),
            enlace('Ver historial completo','clientes',['id'=>$r['id']])
        ];
    }
    $s='<h1>Clientes e Historial</h1><form method="get">'.oculto('opcion','clientes').campo('q','Buscar por nombre',$q).'<button>Buscar</button></form>'.tabla(['ID','Nombre','Historial'],$tablaData).'<p>Hasta 100 resultados; refine la búsqueda si es necesario.</p>';
    if (puede('Administrador','Maitre')) $s.='<h2>Registrar cliente</h2>'.formulario('cliente_guardar',campo('nombre','Nombre','','text','maxlength="150" required'));
    return $s;
}
