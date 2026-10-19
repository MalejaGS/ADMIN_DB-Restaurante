<?php
function fn_desplegar_menu($conn)
{
    $rows=filas($conn,'SELECT p.*,t.nombre AS tipo FROM platos p JOIN tipos t ON t.id=p.tipo_id WHERE p.activo ORDER BY t.id,p.nombre');
    $s='<h1>Menú</h1>';$tipo=null;
    foreach($rows as $p) {
        if ($tipo!==$p['tipo_id']) {
            if ($tipo!==null) $s.='</div>';
            $tipo=$p['tipo_id'];$s.='<div class="SECCION"><h2>'.h($p['tipo']).'</h2>';
        }
        $s.='<div class="PLATO"><span class="NOMBRE">'.h($p['nombre']).'</span><span class="DESCRIPCION">'.h($p['descripcion']).'</span><span class="PRECIO">'.h(dinero($p['precio'])).'</span></div>';
    }
    return $s.($tipo!==null?'</div>':'<p>No hay platos disponibles.</p>');
}
function precio_valido($value)
{
    if (!is_string($value) || !preg_match('/^\d{1,10}(\.\d{1,2})?$/D',$value)) throw new DomainException('Precio inválido: use un número positivo con hasta dos decimales.');
    return $value;
}
function accion_plato_guardar($conn)
{
    exigir('Administrador');
    $id=($_POST['id']??'')!==''?entero($_POST['id'],'Plato'):0;
    $nombre=texto($_POST['nombre']??'','Nombre',150);
    $descripcion=texto($_POST['descripcion']??'','Descripción',2000);
    $tipo=entero($_POST['tipo_id']??null,'Categoría');
    $precio=precio_valido($_POST['precio']??'');
    $minutos=entero($_POST['minutos']??null,'Tiempo de preparación',0);
    if ($minutos>1440) throw new DomainException('El tiempo no puede superar 1440 minutos.');
    $activo=isset($_POST['activo'])?'t':'f';
    if (!fila($conn,'SELECT id FROM tipos WHERE id=$1 FOR SHARE',[$tipo])) throw new DomainException('Categoría inexistente.');
    if (fila($conn,'SELECT id FROM platos WHERE nombre=$1 AND id<>$2',[$nombre,$id])) throw new DomainException('Ya existe un plato con este nombre.');
    $cocineros=$_POST['cocineros']??[];
    if (!is_array($cocineros) || count($cocineros)>200) throw new DomainException('Cocineros inválidos.');
    $ids=[];
    foreach($cocineros as $c) {
        $c=entero($c,'Cocinero');
        if (!in_array('Cocinero',roles_usuario($conn,$c),true)) throw new DomainException('Seleccione cocineros activos.');
        $ids[$c]=$c;
    }
    if ($activo==='t' && !$ids) throw new DomainException('Un plato activo necesita al menos un cocinero autorizado.');
    if ($id) {
        if (!fila($conn,'SELECT id FROM platos WHERE id=$1 FOR UPDATE',[$id])) throw new DomainException('Plato inexistente.');
        db($conn,'SELECT id FROM especialidades WHERE plato_id=$1 ORDER BY id FOR UPDATE',[$id]);
        $enCurso=filas($conn,'SELECT pr.cocinero_id FROM preparaciones pr JOIN ordenes o ON o.id=pr.orden_id WHERE o.plato_id=$1 AND o.estado=2',[$id]);
        foreach($enCurso as $c) if (!isset($ids[(int)$c['cocinero_id']])) throw new DomainException('No puede retirar la autorización a un cocinero con este plato en preparación.');
        db($conn,"UPDATE platos SET nombre=$1,descripcion=$2,tipo_id=$3,precio=$4,tiempo=$5::integer*interval '1 minute',activo=$6 WHERE id=$7",[$nombre,$descripcion,$tipo,$precio,$minutos,$activo,$id]);
    } else {
        $p=fila($conn,"INSERT INTO platos(nombre,descripcion,tipo_id,precio,tiempo,activo) VALUES($1,$2,$3,$4,$5::integer*interval '1 minute',$6) RETURNING id",[$nombre,$descripcion,$tipo,$precio,$minutos,$activo]);
        $id=(int)$p['id'];
    }
    // Conservar las autorizaciones presentes evita borrar una fila bloqueada por cocina.
    $actuales=filas($conn,'SELECT id,cocinero_id FROM especialidades WHERE plato_id=$1 ORDER BY id FOR UPDATE',[$id]);
    foreach($actuales as $e) if (!isset($ids[(int)$e['cocinero_id']])) db($conn,'DELETE FROM especialidades WHERE id=$1',[$e['id']]);
    foreach($ids as $c) db($conn,'INSERT INTO especialidades(plato_id,cocinero_id) VALUES($1,$2) ON CONFLICT(cocinero_id,plato_id) DO NOTHING',[$id,$c]);
    return url('gestion_menu',['id'=>$id]);
}
function accion_plato_eliminar($conn)
{
    exigir('Administrador');
    $id=entero($_POST['id']??null,'Plato');
    if (!fila($conn,'SELECT id FROM platos WHERE id=$1 FOR UPDATE',[$id])) throw new DomainException('Plato inexistente.');
    if (fila($conn,'SELECT id FROM ordenes WHERE plato_id=$1 LIMIT 1',[$id])) {
        db($conn,'UPDATE platos SET activo=false WHERE id=$1',[$id]);
    } else {
        db($conn,'DELETE FROM especialidades WHERE plato_id=$1',[$id]);
        db($conn,'DELETE FROM platos WHERE id=$1',[$id]);
    }
    return url('gestion_menu');
}
function accion_categoria_guardar($conn)
{
    exigir('Administrador');
    $id=($_POST['id']??'')!==''?entero($_POST['id'],'Categoría'):0;
    $nombre=texto($_POST['nombre']??'','Nombre',100);
    if (fila($conn,'SELECT id FROM tipos WHERE nombre=$1 AND id<>$2',[$nombre,$id])) throw new DomainException('Ya existe una categoría con ese nombre.');
    if ($id) {
        if (pg_affected_rows(db($conn,'UPDATE tipos SET nombre=$1 WHERE id=$2',[$nombre,$id]))!==1) throw new DomainException('Categoría inexistente.');
    } else db($conn,'INSERT INTO tipos(nombre) VALUES($1)',[$nombre]);
    return url('gestion_menu');
}
function accion_categoria_eliminar($conn)
{
    exigir('Administrador');
    $id=entero($_POST['id']??null,'Categoría');
    if (!fila($conn,'SELECT id FROM tipos WHERE id=$1 FOR UPDATE',[$id])) throw new DomainException('Categoría inexistente.');
    if (fila($conn,'SELECT id FROM platos WHERE tipo_id=$1 LIMIT 1',[$id])) throw new DomainException('La categoría tiene platos. Reasígnelos antes de eliminarla.');
    db($conn,'DELETE FROM tipos WHERE id=$1',[$id]);
    return url('gestion_menu');
}
function fn_gestion_menu($conn)
{
    exigir('Administrador');
    $p=null;
    if (isset($_GET['id'])) {
        $p=fila($conn,'SELECT *,extract(epoch FROM tiempo)/60 AS minutos FROM platos WHERE id=$1',[entero($_GET['id'],'Plato')]);
        if (!$p) throw new DomainException('Plato inexistente.');
    }
    $tipos=filas($conn,'SELECT * FROM tipos ORDER BY nombre');
    $cocineros=filas($conn,"SELECT u.id,u.nombre FROM usuarios u WHERE u.activo AND EXISTS(SELECT 1 FROM actuaciones a JOIN roles r ON r.id=a.rol_id WHERE a.usuario_id=u.id AND r.nombre='Cocinero') ORDER BY u.nombre");
    $autorizados=$p?array_column(filas($conn,'SELECT cocinero_id FROM especialidades WHERE plato_id=$1',[$p['id']]),'cocinero_id'):[];
    $c=oculto('id',$p['id']??'').campo('nombre','Nombre',$p['nombre']??'','text','required maxlength="150"').'<label>Descripción <textarea name="descripcion" required maxlength="2000">'.h($p['descripcion']??'').'</textarea></label><label>Categoría <select name="tipo_id" required>'.opciones($tipos,'id','nombre',$p['tipo_id']??null).'</select></label>'.campo('precio','Precio',$p['precio']??'0','number','required min="0" step="0.01"').campo('minutos','Preparación (minutos)',isset($p['minutos'])?(int)ceil($p['minutos']):0,'number','required min="0" max="1440"').'<label><input type="checkbox" name="activo" value="1"'.(!$p||$p['activo']==='t'?' checked':'').'> Disponible en el menú</label><fieldset><legend>Cocineros autorizados</legend>';
    foreach($cocineros as $co) $c.='<label><input type="checkbox" name="cocineros[]" value="'.(int)$co['id'].'"'.(in_array($co['id'],$autorizados,true)?' checked':'').'>'.h($co['nombre']).'</label>';
    $s='<h1>Gestión del menú</h1><h2>'.($p?'Actualizar plato':'Agregar plato').'</h2>'.formulario('plato_guardar',$c.'</fieldset>');
    if ($p) $s.=enlace('Agregar otro plato','gestion_menu');
    $rows=filas($conn,'SELECT p.*,t.nombre AS tipo FROM platos p JOIN tipos t ON t.id=p.tipo_id ORDER BY t.nombre,p.nombre');
    $data=[];
    foreach($rows as $r) $data[]=[h($r['nombre']),h($r['tipo']),h(dinero($r['precio'])),h($r['tiempo']),$r['activo']==='t'?'Activo':'Inactivo',enlace('Editar','gestion_menu',['id'=>$r['id']]).formulario('plato_eliminar',oculto('id',$r['id']),'Eliminar / desactivar')];
    $s.=tabla(['Plato','Categoría','Precio','Tiempo','Disponibilidad','Acciones'],$data).'<h2>Categorías</h2>';
    $data=[];
    foreach($tipos as $t) $data[]=[h($t['nombre']),formulario('categoria_guardar',oculto('id',$t['id']).campo('nombre','Nombre',$t['nombre'],'text','required maxlength="100"'),'Actualizar'),formulario('categoria_eliminar',oculto('id',$t['id']),'Eliminar')];
    return $s.tabla(['Categoría','Modificar','Eliminar'],$data).'<h3>Nueva categoría</h3>'.formulario('categoria_guardar',campo('nombre','Nombre','','text','required maxlength="100"'));
}
