<?php
function pg_conectar($host, $dbname, $user, $port = '5432')
{
    $quote = function ($s) { return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $s)."'"; };
    $conn = @pg_connect('host='.$quote($host).' dbname='.$quote($dbname).' user='.$quote($user).' port='.$quote($port).' connect_timeout=5');
    if (!$conn) throw new RuntimeException('No se pudo conectar con PostgreSQL. Revise PGHOST, PGPORT, PGDATABASE, PGUSER y PGPASSWORD.');
    db($conn, "SET TIME ZONE 'America/Bogota'");
    return $conn;
}
function db($conn, $sql, $params = [])
{
    $r = @pg_query_params($conn, $sql, $params);
    if ($r === false) {
        error_log('Restaurante SQL: '.pg_last_error($conn));
        throw new RuntimeException('No se pudo completar la operación en la base de datos.');
    }
    return $r;
}
function filas($conn, $sql, $params = []) { return pg_fetch_all(db($conn, $sql, $params)) ?: []; }
function fila($conn, $sql, $params = []) { return pg_fetch_assoc(db($conn, $sql, $params)) ?: null; }
function procesar_query($sql, $conn, $params = []) { $d=filas($conn,$sql,$params); return (object)['cantidad'=>count($d),'datos'=>$d]; }
function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function Mostrar($value) { return '<pre>'.h(var_export($value,true)).'</pre>'; }
function entero($value, $nombre = 'Identificador', $min = 1)
{
    if (!is_scalar($value) || filter_var($value,FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > 2147483647)
        throw new DomainException($nombre.' inválido.');
    return (int)$value;
}
function texto($value, $nombre, $max = 500)
{
    if (!is_string($value) || trim($value)==='' || mb_strlen(trim($value))>$max) throw new DomainException($nombre.' es obligatorio (máximo '.$max.' caracteres).');
    return trim($value);
}
function fecha($value)
{
    if (!is_string($value)) throw new DomainException('Fecha inválida.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$d || $d->format('Y-m-d')!==$value) throw new DomainException('Fecha inválida.');
    return $value;
}
function dinero($value) { return '$'.number_format((float)$value,2,',','.'); }
function url($opcion = '', $params = []) { return '?'.http_build_query(['opcion'=>$opcion]+$params); }
function enlace($label,$opcion='',$params=[]) { return '<a href="'.h(url($opcion,$params)).'">'.h($label).'</a>'; }
function campo($name,$label,$value='',$type='text',$extra='') { return '<label>'.h($label).' <input type="'.h($type).'" name="'.h($name).'" value="'.h($value).'" '.$extra.'></label>'; }
function oculto($name,$value) { return '<input type="hidden" name="'.h($name).'" value="'.h($value).'">'; }
// $label=null omite el botón (el contenido ya trae su propio submit).
function formulario($accion,$contenido,$label='Guardar',$clase='')
{
    $boton=$label===null?'':'<button type="submit"'.($clase!==''?' class="'.h($clase).'"':'').'>'.h($label).'</button>';
    return '<form method="post" action="index.php">'.oculto('accion',$accion).oculto('csrf',$_SESSION['csrf']).oculto('token',bin2hex(random_bytes(24))).$contenido.$boton.'</form>';
}
function opciones($rows,$value,$label,$selected=null,$empty=true)
{
    $s=$empty?'<option value="">Seleccione</option>':'';
    foreach($rows as $r) $s.='<option value="'.h($r[$value]).'"'.((string)$selected===(string)$r[$value]?' selected':'').'>'.h($r[$label]).'</option>';
    return $s;
}
function tabla($headers,$rows,$vacio='No hay resultados para esta consulta.')
{
    if (!$rows) return '<p class="VACIO">'.h($vacio).'</p>';
    $s='<div class="TABLA"><table><thead><tr>';
    foreach($headers as $v) $s.='<th>'.h($v).'</th>';
    $s.='</tr></thead><tbody>';
    foreach($rows as $r) { $s.='<tr>'; foreach($r as $v) $s.='<td>'.$v.'</td>'; $s.='</tr>'; }
    return $s.'</tbody></table></div>';
}
// ---- Componentes de diagramación compartidos ----
function encabezado($tituloHtml,$metaHtml='',$accionesHtml='')
{
    return '<div class="PAGE_HEAD"><div class="PAGE_TITULO"><h1>'.$tituloHtml.'</h1>'.($metaHtml!==''?'<p class="PAGE_META">'.$metaHtml.'</p>':'').'</div>'.($accionesHtml!==''?'<div class="PAGE_ACTIONS">'.$accionesHtml.'</div>':'').'</div>';
}
function seccion($titulo,$contenido,$extraHtml='')
{
    return '<section class="BLOQUE"><div class="BLOQUE_HEAD"><h2>'.h($titulo).'</h2>'.$extraHtml.'</div>'.$contenido.'</section>';
}
function kpis($items)
{
    $s='<dl class="KPIS">';
    foreach($items as [$label,$valorHtml]) $s.='<div><dt>'.h($label).'</dt><dd>'.$valorHtml.'</dd></div>';
    return $s.'</dl>';
}
function estado($texto,$tono='neutro') { return '<span class="ESTADO ESTADO_'.strtoupper($tono).'">'.h($texto).'</span>'; }
function boton($label,$opcion,$params=[],$clase='BTN') { return '<a class="'.h($clase).'" href="'.h(url($opcion,$params)).'">'.h($label).'</a>'; }
function plegable($resumen,$contenido,$abierto=false,$id='')
{
    return '<details class="PANEL"'.($id!==''?' id="'.h($id).'"':'').($abierto?' open':'').'><summary>'.h($resumen).'</summary><div class="PANEL_BODY">'.$contenido.'</div></details>';
}
function hora_corta($ts) { return $ts?date('H:i',strtotime($ts)):'—'; }
function fecha_corta($ts) { return $ts?date('d/m/Y H:i',strtotime($ts)):'—'; }
function fecha_larga($ymd)
{
    $dias=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
    $meses=['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $t=strtotime($ymd);
    return $dias[(int)date('w',$t)].' '.(int)date('j',$t).' de '.$meses[(int)date('n',$t)];
}
function num($valorHtml) { return '<span class="NUM">'.$valorHtml.'</span>'; }
function redirigir($destino) { header('Location: '.$destino, true, 303); exit; }
function roles_usuario($conn,$id)
{
    return array_column(filas($conn,'SELECT r.nombre FROM actuaciones a JOIN roles r ON r.id=a.rol_id JOIN usuarios u ON u.id=a.usuario_id WHERE a.usuario_id=$1 AND u.activo',[$id]),'nombre');
}
function puede(...$roles) { return (bool)array_intersect($roles,$_SESSION['roles']??[]); }
function exigir(...$roles) { if (!puede(...$roles)) { http_response_code(403); throw new DomainException('No tiene permiso para esta operación.'); } }
function cliente_valido($conn,$id)
{
    if (!fila($conn,"SELECT u.id FROM usuarios u JOIN actuaciones a ON a.usuario_id=u.id JOIN roles r ON r.id=a.rol_id WHERE u.id=$1 AND u.activo AND r.nombre='Cliente'",[$id])) throw new DomainException('Seleccione un cliente activo.');
}
// Idempotencia persistente: cada formulario de escritura se consume una sola vez.
// El bloqueo transaccional serializa dos envíos simultáneos del mismo token.
function operacion($conn,$accion,$callback)
{
    $token=$_POST['token']??'';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/D',$token)) throw new DomainException('Formulario inválido. Recargue la página.');
    db($conn,'BEGIN');
    try {
        db($conn,'SELECT pg_advisory_xact_lock(hashtextextended($1,0))',[$token]);
        $prev=fila($conn,'SELECT * FROM restaurante_operaciones WHERE token=$1',[$token]);
        if ($prev) {
            if ((int)$prev['usuario_id']!==$_SESSION['usuario_id'] || $prev['accion']!==$accion) throw new DomainException('Formulario inválido.');
            $destino=$prev['destino'];
        } else {
            $destino=$callback();
            db($conn,'INSERT INTO restaurante_operaciones(token,usuario_id,accion,destino) VALUES($1,$2,$3,$4)',[$token,$_SESSION['usuario_id'],$accion,$destino]);
        }
        db($conn,'COMMIT');
        return $destino;
    } catch (Throwable $e) { @pg_query($conn,'ROLLBACK'); throw $e; }
}
