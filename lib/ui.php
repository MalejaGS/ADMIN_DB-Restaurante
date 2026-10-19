<?php
function icono($nombre)
{
    $paths=[
      'inicio'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
      'pedido'=>'<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M9 9h6M9 13h6M9 17h4"/>',
      'reserva'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-12 5 2 2 4-4"/>',
      'menu'=>'<path d="M4 3v7a3 3 0 0 0 6 0V3M7 3v18M20 3c-4 3-5 8 0 9v9M20 3v9"/>',
      'reporte'=>'<path d="M4 3v18h17M8 16v-4M13 16V8M18 16V5"/>',
      'cocina'=>'<path d="M7 14a5 5 0 0 1-1-10 6 6 0 0 1 12 0 5 5 0 0 1-1 10v7H7zM7 17h10"/>',
      'clientes'=>'<circle cx="9" cy="7" r="4"/><path d="M2 21v-3a7 7 0 0 1 14 0v3m1-18a4 4 0 0 1 0 8m2 4a6 6 0 0 1 3 5"/>',
      'mesa'=>'<rect x="5" y="7" width="14" height="10" rx="2"/><path d="M9 3h6M9 21h6M2 10v4m20-4v4"/>'
    ];
    return '<span class="ICONO" aria-hidden="true"><svg viewBox="0 0 24 24">'.($paths[$nombre]??$paths['menu']).'</svg></span>';
}
function modulos()
{
    return [
      ['','Inicio','inicio','Vista general de tu operación.',true],
      ['realizar_pedidos',puede('Mesero')?'Realizar Pedido':'Pedidos','pedido','Selecciona una mesa, registra platos y gestiona las entregas.',puede('Mesero','Administrador')],
      ['reservas','Reservas','reserva','Encuentra una mesa y organiza las próximas visitas.',puede('Administrador','Maitre')],
      ['gestion_menu','Actualizar Menú','menu','Gestiona platos, precios y cocineros autorizados.',puede('Administrador')],
      ['reportes','Reportes y Estadísticas','reporte','Consulta ventas, reservas y los platos más solicitados.',puede('Administrador')],
      ['cocina','Preparación','cocina','Toma los platos de tu especialidad y márcalos listos.',puede('Cocinero')],
      ['clientes','Clientes','clientes','Busca un cliente o registra sus datos para una visita.',puede('Administrador','Maitre','Mesero')],
      ['desplegar_menu','Carta del restaurante','menu','Consulta los platos disponibles, sus precios y categorías.',true],
    ];
}
function encabezado($titulo,$descripcion,$etiqueta='Operación diaria')
{
    return '<div class="ENCABEZADO"><div><span class="ETIQUETA">'.h($etiqueta).'</span><h1>'.h($titulo).'</h1><p>'.h($descripcion).'</p></div></div>';
}
function tarjeta($label,$valor,$nota='')
{
    return '<div class="TARJETA"><span class="ETIQUETA">'.h($label).'</span><strong>'.h($valor).'</strong><p>'.h($nota).'</p></div>';
}
function insignia($texto)
{
    $colores=['En preparación'=>'ambar','En preparación (sin asignar)'=>'ambar','Preparado'=>'verde','Entregado'=>'azul','Confirmada'=>'verde','Pendiente'=>'ambar','Completada'=>'azul','Cancelada'=>'rojo','Activo'=>'verde','Inactivo'=>'rojo'];
    return '<span class="ESTADO '.($colores[$texto]??'').'">'.h($texto).'</span>';
}
function marca()
{
    return '<a class="MARCA" href="index.php"><span class="MONOGRAMA">u</span><span>urbano<small>restaurante & cocina</small></span></a>';
}
function layout($contenido)
{
    if (!isset($_SESSION['usuario_id'])) return '<main id="contenido">'.$contenido.'</main>';
    $op=is_string($_GET['opcion']??'')?($_GET['opcion']??''):'';
    if ($op==='pedido') $op='realizar_pedidos';
    $titulo='Inicio';$links='';
    foreach(modulos() as [$ruta,$label,$icono,$desc,$allowed]) if($allowed) {
        if($op===$ruta) $titulo=$label;
        $links.='<a href="'.h(url($ruta)).'"'.($op===$ruta?' aria-current="page"':'').'>'.icono($icono).'<span>'.h($label).'</span></a>';
    }
    return '<div class="APP"><aside class="LATERAL">'.marca().'<div class="GRUPO_NAV"><span class="ETIQUETA">Tu espacio de trabajo</span><nav class="NAVEGACION" aria-label="Navegación principal">'.$links.'</nav></div><div class="PERFIL"><div><strong>'.h($_SESSION['nombre']).'</strong><small>'.h(implode(' · ',$_SESSION['roles'])).'</small></div>'.formulario('logout','','Cerrar sesión').'</div></aside><div class="CUERPO"><header class="CABECERA"><div class="RUTA"><span>Restaurante Urbano</span><span>/</span><strong>'.h($titulo).'</strong></div><span class="FECHA">'.h(date('d / m / Y')).' · Bogotá</span></header><main class="CONTENIDO" id="contenido">'.$contenido.'</main><footer class="PIE"><span>Urbano · Gestión del restaurante</span><span>Universidad Javeriana · Bases de datos · Ing. Ricardo Naranjo Faccini</span></footer></div></div>';
}
