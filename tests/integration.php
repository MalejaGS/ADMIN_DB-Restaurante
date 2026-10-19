<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// SOLO una base temporal llamada restaurante_test. Nunca ejecutar sobre datos reales.
require_once __DIR__.'/../etc/parametros.php';
require_once __DIR__.'/../lib/libreria.php';
require_once __DIR__.'/../lib/restaurante.php';
date_default_timezone_set('America/Bogota');
$c=pg_conectar($host,$dbname,$user,$port);
if (fila($c,'SELECT current_database() AS nombre')['nombre']!=='restaurante_test') die("Use exclusivamente PGDATABASE=restaurante_test.\n");
function identidad($id,$roles) { $_SESSION=['usuario_id'=>$id,'roles'=>$roles,'csrf'=>str_repeat('a',64),'nombre'=>'Prueba']; }
function ejecutar($c,$accion,$datos,$token=null) { $_POST=$datos+['token'=>$token??bin2hex(random_bytes(24))];return operacion($c,$accion,function()use($c,$accion){$f='accion_'.$accion;return $f($c);}); }
function comprobar($ok,$mensaje) { if (!$ok) throw new RuntimeException('FALLO: '.$mensaje);echo "OK: $mensaje\n"; }
function rechaza($fn,$mensaje) { try { $fn(); } catch(DomainException $e) { comprobar(true,$mensaje);return; }throw new RuntimeException('No se rechazó: '.$mensaje); }
if (($argv[1]??'')==='worker') {
    $data=json_decode(base64_decode($argv[2]),true);
    identidad($data['usuario'],$data['roles']);
    try { ejecutar($c,$data['accion'],$data['post'],$data['token']??null);echo 'ACEPTADO'; } catch(DomainException $e) { echo 'RECHAZADO'; }
    exit;
}
if (getenv('RESTAURANTE_TEST_RESET')!=='1') die("Defina RESTAURANTE_TEST_RESET=1 para cargar las fixtures temporales.\n");
db($c,'TRUNCATE restaurante_operaciones,preparaciones,ordenes,pedidos,horarios,reservaciones,especialidades,actuaciones,platos,mesas,tipos,usuarios,roles RESTART IDENTITY CASCADE');
db($c,"INSERT INTO roles(nombre) VALUES('Administrador'),('Maitre'),('Mesero'),('Cocinero'),('Cliente')");
$names=['Admin','Maitre','Mesero','Cocinero','Otro cocinero','Cliente <script>alert(1)</script>','Cliente dos','Otro mesero','No autorizado'];
foreach($names as $name) db($c,"INSERT INTO usuarios(nombre,clave,fecha_clave) VALUES($1,sha256(convert_to('Restaurante#42','UTF8')),LOCALTIMESTAMP)",[$name]);
foreach([[1,1],[2,2],[3,3],[4,4],[5,4],[6,5],[7,5],[8,3],[9,4]] as [$u,$r]) db($c,'INSERT INTO actuaciones(usuario_id,rol_id) VALUES($1,$2)',[$u,$r]);
foreach([
 "INSERT INTO mesas(sillas) VALUES(4),(4),(2)",
 "INSERT INTO tipos(nombre) VALUES('Fuerte'),('Bebida')",
 "INSERT INTO platos(tipo_id,nombre,descripcion,precio,tiempo) VALUES(1,'Principal','Descripción',10000,interval '20 minutes'),(2,'Bebida','Descripción',5000,interval '2 minutes')",
 "INSERT INTO especialidades(cocinero_id,plato_id) VALUES(4,1),(5,1),(4,2)"
] as $sql) db($c,$sql);
identidad(3,['Mesero']);
$token=bin2hex(random_bytes(24));
$post=['mesa_id'=>1,'cliente_id'=>6,'cantidad'=>[1=>2,2=>1]];
$dest=ejecutar($c,'pedido_guardar',$post,$token);
parse_str(parse_url($dest,PHP_URL_QUERY),$query);$pedido=(int)$query['id'];
comprobar(fila($c,'SELECT count(*) AS n FROM ordenes WHERE pedido_id=$1',[$pedido])['n']==2,'pedido con múltiples ítems');
comprobar(estado_pedido($c,$pedido)==='En preparación','estado inicial');
ejecutar($c,'pedido_guardar',$post,$token);
comprobar(fila($c,'SELECT count(*) AS n FROM pedidos')['n']==1,'reenvío no duplica pedido');
rechaza(function()use($c){ejecutar($c,'pedido_guardar',['mesa_id'=>1,'cliente_id'=>6,'cantidad'=>[1=>-1]]);},'cantidades negativas');
rechaza(function()use($c){ejecutar($c,'pedido_guardar',['mesa_id'=>1,'cliente_id'=>6,'cantidad'=>[1=>1,999=>1]]);},'rollback completo si un plato no existe');
comprobar(fila($c,'SELECT count(*) AS n FROM pedidos')['n']==1,'sin cabecera huérfana tras error');
$orders=filas($c,'SELECT id FROM ordenes WHERE pedido_id=$1 ORDER BY id',[$pedido]);$orden=(int)$orders[0]['id'];
rechaza(function()use($c,$pedido,$orden){ejecutar($c,'entregar',['pedido_id'=>$pedido,'orden_id'=>$orden]);},'entrega antes de preparar');
identidad(8,['Mesero']);
rechaza(function()use($c,$pedido){pedido_acceso($c,$pedido);},'mesero no accede a pedido ajeno');
identidad(9,['Cocinero']);
rechaza(function()use($c,$orden){ejecutar($c,'cocina',['orden_id'=>$orden,'paso'=>'tomar']);},'cocinero sin especialidad');
identidad(4,['Cocinero']);
ejecutar($c,'cocina',['orden_id'=>$orden,'paso'=>'tomar']);
identidad(5,['Cocinero']);
rechaza(function()use($c,$orden){ejecutar($c,'cocina',['orden_id'=>$orden,'paso'=>'preparar']);},'cocinero no finaliza ítem ajeno');
identidad(4,['Cocinero']);
ejecutar($c,'cocina',['orden_id'=>$orden,'paso'=>'preparar']);
$orden2=(int)$orders[1]['id'];ejecutar($c,'cocina',['orden_id'=>$orden2,'paso'=>'tomar']);ejecutar($c,'cocina',['orden_id'=>$orden2,'paso'=>'preparar']);
comprobar(estado_pedido($c,$pedido)==='Preparado','pedido preparado al completar todos los ítems');
identidad(3,['Mesero']);
ejecutar($c,'entregar',['pedido_id'=>$pedido,'orden_id'=>$orden]);ejecutar($c,'entregar',['pedido_id'=>$pedido,'orden_id'=>$orden2]);
comprobar(estado_pedido($c,$pedido)==='Entregado','pedido entregado');
rechaza(function()use($c,$pedido){ejecutar($c,'pedido_agregar',['pedido_id'=>$pedido,'cantidad'=>[1=>1]]);},'pedido entregado no admite nuevos ítems');
// Precio original y ventas verificadas.
identidad(1,['Administrador']);
ejecutar($c,'plato_guardar',['id'=>1,'nombre'=>'Principal actualizado','descripcion'=>'Nueva','tipo_id'=>1,'precio'=>'15000','minutos'=>25,'activo'=>1,'cocineros'=>[4,5]]);
comprobar((float)fila($c,'SELECT precio_unitario FROM ordenes WHERE id=$1',[$orden])['precio_unitario']===10000.0,'cambio de menú preserva precio histórico');
$d=datos_reportes($c,date('Y-m-d'),date('Y-m-d'),'day');
comprobar((float)$d['ventas'][0]['verificadas']===25000.0,'ventas exactas de ítems entregados');
foreach(['week','month'] as $group) comprobar(count(datos_reportes($c,date('Y-m-d'),date('Y-m-d'),$group)['ventas'])===1,'agrupación '.$group);
// Legado sin precio ni fecha de entrega, estimación en columna separada.
$p=fila($c,'INSERT INTO pedidos(cliente_id,mesero_id) VALUES(6,3) RETURNING id');
db($c,'INSERT INTO ordenes(plato_id,pedido_id,estado,cantidad) VALUES(1,$1,3,1)',[$p['id']]);
$d=datos_reportes($c,date('Y-m-d'),date('Y-m-d'),'day');
comprobar((float)$d['ventas'][0]['verificadas']===25000.0 && (float)$d['ventas'][0]['estimadas']===15000.0,'histórico estimado separado');
rechaza(function()use($c){datos_reportes($c,'2026-02-30','2026-03-01','day');},'fechas inválidas');
rechaza(function()use($c){datos_reportes($c,'2026-03-02','2026-03-01','day');},'rango invertido');
comprobar(datos_reportes($c,'2000-01-01','2000-01-01','day')['ventas']===[],'reporte vacío');
// Gestión de categorías y platos, borrado seguro y permisos.
rechaza(function()use($c){ejecutar($c,'categoria_eliminar',['id'=>1]);},'categoría con platos no se elimina');
ejecutar($c,'categoria_guardar',['nombre'=>'Nueva']);
$t=fila($c,"SELECT id FROM tipos WHERE nombre='Nueva'")['id'];
ejecutar($c,'categoria_guardar',['id'=>$t,'nombre'=>'Renombrada']);
ejecutar($c,'categoria_eliminar',['id'=>$t]);
comprobar(!fila($c,'SELECT id FROM tipos WHERE id=$1',[$t]),'CRUD de categoría');
ejecutar($c,'plato_guardar',['nombre'=>'Temporal','descripcion'=>'Temporal','tipo_id'=>1,'precio'=>'100.50','minutos'=>1,'activo'=>1,'cocineros'=>[4]]);
$tmp=fila($c,"SELECT id FROM platos WHERE nombre='Temporal'")['id'];
ejecutar($c,'plato_eliminar',['id'=>$tmp]);
comprobar(!fila($c,'SELECT id FROM platos WHERE id=$1',[$tmp]),'plato sin historial se elimina');
ejecutar($c,'plato_eliminar',['id'=>2]);
comprobar(fila($c,'SELECT activo FROM platos WHERE id=2')['activo']==='f','plato con historial se desactiva');
identidad(3,['Mesero']);
rechaza(function()use($c){ejecutar($c,'pedido_guardar',['mesa_id'=>1,'cliente_id'=>6,'cantidad'=>[2=>1]]);},'no pedir plato inactivo');
rechaza(function()use($c){ejecutar($c,'categoria_guardar',['nombre'=>'Ataque']);},'mesero no administra menú');
// Reservas: creación, cruces, límites, modificaciones y cancelación.
identidad(2,['Maitre']);
$dia=(new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$res=['mesa_id'=>1,'cliente_id'=>6,'personas'=>4,'inicio'=>$dia.'T12:00'];
ejecutar($c,'reserva_guardar',$res);
$r=fila($c,'SELECT max(id) AS id FROM reservaciones')['id'];
comprobar(fila($c,"SELECT duracion=interval '2 hours' AS ok FROM horarios WHERE reservacion_id=$1",[$r])['ok']==='t','reserva dura dos horas');
rechaza(function()use($c,$res){$res['inicio']=substr($res['inicio'],0,11).'13:00';ejecutar($c,'reserva_guardar',$res);},'reserva cruzada');
$resContigua=$res;$resContigua['inicio']=$dia.'T14:00';ejecutar($c,'reserva_guardar',$resContigua);
comprobar(fila($c,'SELECT count(*) AS n FROM reservaciones')['n']==2,'reservas contiguas permitidas');
rechaza(function()use($c,$res){$res['mesa_id']=3;$res['personas']=3;ejecutar($c,'reserva_guardar',$res);},'capacidad de mesa');
rechaza(function()use($c,$res){$res['mesa_id']=2;ejecutar($c,'reserva_guardar',$res);},'cliente no puede reservar dos mesas simultáneas');
$edit=$res;$edit['id']=$r;$edit['inicio']=$dia.'T10:00';ejecutar($c,'reserva_guardar',$edit);
comprobar(strpos(fila($c,'SELECT inicio FROM horarios WHERE reservacion_id=$1',[$r])['inicio'],'10:00')!==false,'modificar reserva');
ejecutar($c,'reserva_cancelar',['id'=>$r]);
$res['inicio']=$dia.'T10:00';ejecutar($c,'reserva_guardar',$res);
comprobar(true,'cancelada libera disponibilidad');
// Un legado inconsistente puede exceder el cupo aunque la mesa nueva esté libre.
$basura=[];
for($i=0;$i<3;$i++) {
 $tmp=fila($c,'INSERT INTO reservaciones(cliente_id,cantidad,estado) VALUES(6,4,2) RETURNING id')['id'];$basura[]=$tmp;
 db($c,"INSERT INTO horarios(mesa_id,reservacion_id,inicio,duracion) VALUES(1,$1,$2,interval '80 minutes')",[$tmp,$dia.' 22:00']);
}
rechaza(function()use($c,$dia){ejecutar($c,'reserva_guardar',['mesa_id'=>2,'cliente_id'=>7,'personas'=>1,'inicio'=>$dia.'T22:00']);},'cupo global ante conflictos históricos');
comprobar((int)fila($c,'SELECT count(*) AS n FROM restaurante_conflictos_reservas')['n']>0,'auditoría detecta cruces históricos sin modificarlos');
foreach($basura as $tmp) { db($c,'DELETE FROM horarios WHERE reservacion_id=$1',[$tmp]);db($c,'DELETE FROM reservaciones WHERE id=$1',[$tmp]); }
// Agregar ítems a un pedido abierto, con el precio vigente del menú.
identidad(3,['Mesero']);
$dest=ejecutar($c,'pedido_guardar',['mesa_id'=>2,'cliente_id'=>7,'cantidad'=>[1=>1]]);
parse_str(parse_url($dest,PHP_URL_QUERY),$q);$abierto=$q['id'];
ejecutar($c,'pedido_agregar',['pedido_id'=>$abierto,'cantidad'=>[1=>2]]);
comprobar(fila($c,'SELECT sum(cantidad) AS n FROM ordenes WHERE pedido_id=$1',[$abierto])['n']==3,'agregar ítems a pedido abierto');
// Listados sin duplicados y escapado de nombres de clientes.
identidad(3,['Mesero']);$_GET=[];$html=fn_realizar_pedidos($c);
comprobar(substr_count($html,'>Mesa ')===3,'listado de todas las mesas sin duplicados');
comprobar(strpos($html,'<script>alert(1)</script>')===false && strpos($html,'&lt;script&gt;')!==false,'escape HTML de datos');
$_GET=['q'=>"' OR 1=1 --"];
comprobar(strpos(fn_clientes($c),'No hay resultados')!==false,'búsqueda parametrizada');
// Concurrencia real: dos procesos, dos conexiones PostgreSQL.
function paralelo($data1,$data2) {
    $procesos=[];
    foreach([$data1,$data2] as $data) {
        $pipes=[];$p=proc_open([PHP_BINARY,__FILE__,'worker',base64_encode(json_encode($data))],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);$procesos[]=[$p,$pipes];
    }
    $out=[];
    foreach($procesos as [$p,$pipes]) { $out[]=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0)throw new RuntimeException($err); }
    return $out;
}
$concurrente=['usuario'=>2,'roles'=>['Maitre'],'accion'=>'reserva_guardar','post'=>['mesa_id'=>2,'cliente_id'=>7,'personas'=>2,'inicio'=>$dia.'T18:00']];
$out=paralelo($concurrente,$concurrente);
comprobar(count(array_filter($out,function($s){return $s==='ACEPTADO';}))===1 && in_array('RECHAZADO',$out,true),'reservas concurrentes: solo una se acepta');
$concurrente=['usuario'=>3,'roles'=>['Mesero'],'accion'=>'pedido_guardar','token'=>bin2hex(random_bytes(24)),'post'=>['mesa_id'=>2,'cliente_id'=>7,'cantidad'=>[1=>1]]];
$n=(int)fila($c,'SELECT count(*) AS n FROM pedidos')['n'];paralelo($concurrente,$concurrente);
comprobar((int)fila($c,'SELECT count(*) AS n FROM pedidos')['n']===$n+1,'reenvío concurrente de pedido idempotente');
$ultima=fila($c,'SELECT max(id) AS id FROM ordenes')['id'];
$concurrente=['usuario'=>4,'roles'=>['Cocinero'],'accion'=>'cocina','post'=>['orden_id'=>$ultima,'paso'=>'tomar']];
$otra=$concurrente;$otra['usuario']=5;
$out=paralelo($concurrente,$otra);
comprobar(count(array_filter($out,function($s){return $s==='ACEPTADO';}))===1 && in_array('RECHAZADO',$out,true),'solo un cocinero puede tomar el mismo ítem');
echo "PRUEBAS DE INTEGRACIÓN COMPLETADAS\n";
