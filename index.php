<?php
ini_set('display_errors','0');
error_reporting(E_ALL);
date_default_timezone_set('America/Bogota');
require_once __DIR__.'/etc/parametros.php';
require_once __DIR__.'/lib/libreria.php';
require_once __DIR__.'/lib/restaurante.php';
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','use_strict_mode'=>true]);
if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
$conn=null;$contenido='';
try {
    $conn=pg_conectar($host,$dbname,$user,$port);
    $m=fila($conn,"SELECT to_regclass('public.restaurante_migraciones') AS tabla");
    if (!$m['tabla'] || !fila($conn,'SELECT version FROM restaurante_migraciones WHERE version=2')) throw new RuntimeException('Falta aplicar las migraciones 001 y 002 antes de utilizar la aplicación.');
    if (isset($_SESSION['usuario_id'])) {
        $_SESSION['roles']=roles_usuario($conn,$_SESSION['usuario_id']);
        if (!array_intersect($_SESSION['roles'],['Administrador','Maitre','Mesero','Cocinero'])) unset($_SESSION['usuario_id'],$_SESSION['roles'],$_SESSION['nombre']);
    }
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        $csrf=$_POST['csrf']??'';
        if (!is_string($csrf)||!hash_equals($_SESSION['csrf'],$csrf)) { http_response_code(403);throw new DomainException('El formulario expiró. Recargue la página.'); }
        $accion=$_POST['accion']??'';
        if (!is_string($accion)) throw new DomainException('Acción inválida.');
        if ($accion==='login') login($conn);
        if (!isset($_SESSION['usuario_id'])) { http_response_code(401);throw new DomainException('Inicie sesión para continuar.'); }
        if ($accion==='logout') { $_SESSION=[];session_regenerate_id(true);redirigir(url()); }
        $acciones=['pedido_guardar'=>'accion_pedido_guardar','pedido_agregar'=>'accion_pedido_agregar','entregar'=>'accion_entregar','cocina'=>'accion_cocina','reserva_guardar'=>'accion_reserva_guardar','reserva_cancelar'=>'accion_reserva_cancelar','cliente_guardar'=>'accion_cliente_guardar','plato_guardar'=>'accion_plato_guardar','plato_eliminar'=>'accion_plato_eliminar','categoria_guardar'=>'accion_categoria_guardar','categoria_eliminar'=>'accion_categoria_eliminar','mesa_guardar'=>'accion_mesa_guardar','mesa_eliminar'=>'accion_mesa_eliminar','empleado_guardar'=>'accion_empleado_guardar'];
        $permisos=['pedido_guardar'=>['Mesero'],'pedido_agregar'=>['Administrador','Mesero'],'entregar'=>['Administrador','Mesero'],'cocina'=>['Cocinero'],'reserva_guardar'=>['Administrador','Maitre'],'reserva_cancelar'=>['Administrador','Maitre'],'cliente_guardar'=>['Administrador','Maitre'],'plato_guardar'=>['Administrador'],'plato_eliminar'=>['Administrador'],'categoria_guardar'=>['Administrador'],'categoria_eliminar'=>['Administrador'],'mesa_guardar'=>['Administrador'],'mesa_eliminar'=>['Administrador'],'empleado_guardar'=>['Administrador']];
        if (!isset($acciones[$accion])) { http_response_code(404);throw new DomainException('Acción desconocida.'); }
        exigir(...$permisos[$accion]);
        $fn=$acciones[$accion];
        $destino=operacion($conn,$accion,function()use($conn,$fn){return $fn($conn);});
        $_SESSION['aviso']='Operación guardada correctamente.';
        redirigir($destino);
    }
    if (!isset($_SESSION['usuario_id'])) $contenido=fn_login($conn);
    else {
        $rutas=[''=>'fn_menu_opciones','desplegar_menu'=>'fn_desplegar_menu','realizar_pedidos'=>'fn_realizar_pedidos','pedido'=>'fn_pedido','reservas'=>'fn_reservas','clientes'=>'fn_clientes','gestion_menu'=>'fn_gestion_menu','reportes'=>'fn_reportes','cocina'=>'fn_cocina','gestion_mesas'=>'fn_gestion_mesas','gestion_empleados'=>'fn_gestion_empleados'];
        $opcion=$_GET['opcion']??'';
        if (!is_string($opcion)||!isset($rutas[$opcion])) { http_response_code(404);throw new DomainException('Página no encontrada.'); }
        $contenido=$rutas[$opcion]($conn);
    }
} catch (DomainException $e) {
    if (http_response_code()<400) http_response_code(422);
    $contenido='<p role="alert">'.h($e->getMessage()).'</p><p>Los cambios de este formulario no se guardaron. Regrese con el navegador para corregirlos.</p>';
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Restaurante: '.$e->getMessage());
    $mensaje=$conn===null?$e->getMessage():'No se pudo completar la operación. Revise la conexión y que la migración esté aplicada.';
    $contenido='<p role="alert">'.h($mensaje).'</p>';
}
$barra='';
if (isset($_SESSION['usuario_id'])) $barra='<div class="SESION"><p>'.h($_SESSION['nombre']).' · '.h(implode(', ',$_SESSION['roles'])).'</p>'.fn_boton_menu_principal().formulario('logout','','Cerrar sesión').'</div>';
if (isset($_SESSION['aviso'])) { $barra.='<p role="status">'.h($_SESSION['aviso']).'</p>';unset($_SESSION['aviso']); }
print sprintf(file_get_contents(__DIR__.'/esqueleto.html'),$barra.$contenido);
