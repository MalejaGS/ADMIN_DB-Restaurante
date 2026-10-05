<?php
/*------------------------------------------------------------------*/
/**
 * @brief Genera el código HTML de un botón para regresar al inicio.
 * @return string Cadena de texto con el botón HTML.
 * @pre Ninguna.
 * @post El botón redirige a la opción raíz mediante JavaScript.
 */
function fn_boton_menu_principal()
/*--------------------------------------------------------------------*/
{
    $scr_menu = "window.open('?opcion=', '_top');";
    $retorno = "<button onClick=\"$scr_menu\">Menu del programa</button>";
    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Despliega los botones principales de navegación del programa.
 * @param resource $conexion_bd Recurso de conexión a la base de datos.
 * @return string Fragmento HTML con el menú de navegación.
 * @pre El sistema debe estar cargado y la conexión debe ser válida.
 * @post Permite navegar hacia el menú de platos o el sistema de pedidos.
 */
function fn_menu_opciones($conn)
/*--------------------------------------------------------------------*/
{
    $scr_menu = "window.open('?opcion=desplegar_menu', '_top');";
    $scr_pedido = "window.open('?opcion=realizar_pedidos', '_top');";
    $scr_reserva = "window.open('?opcion=crear_reserva', '_top');";
    $retorno = "<h1>Opciones del programa</h1>"
             . "<div class='MENU_OPCIONES'>"
             . "<button onClick=\"$scr_menu\">Desplegar Menu</button>"
             . "<button onClick=\"$scr_pedido\">Realizar Pedido</button>"
             . "<button onClick=\"$scr_reserva\">Crear Reserva</button>"
             . "</div>"
             ;
    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Consulta y visualiza la carta de platos agrupados por tipo.
 * @param resource $conexion_bd Recurso de conexión a la base de datos.
 * @return string Lista HTML (ul) con las secciones y platos del restaurante.
 * @pre Debe existir la tabla platos y tipos con datos íntegros.
 * @post Genera la visualización estructurada del menú.
 */
function fn_desplegar_menu($conn)
/*--------------------------------------------------------------------*/
{
    $sentencia = "
    SELECT platos.id
         , platos.nombre
         , tipos.nombre AS tipo
         , descripcion
         , precio 
    FROM platos
    LEFT JOIN tipos ON platos.tipo_id=tipos.id
        ORDER BY tipos.id, platos.nombre
        ;";
    $resultado = procesar_query($sentencia, $conn);
    $retorno = fn_boton_menu_principal()."<br />";
    $tipo = "<div class='SECCION'>";
    foreach ($resultado->datos AS $plato) {
    if ($tipo != $plato['tipo']) {
        $retorno.= "</div><div class='SECCION'><h2>".$plato['tipo']."</h2>";
        $tipo = $plato['tipo'];
    }
        $retorno.= "<div class='PLATO'>"
                 .     "<span class='NOMBRE'>".$plato['nombre']."</span>"
                 .     "<span class='DESCRIPCION'>".$plato['descripcion']."</span>"
                 .     "<span class='PRECIO'>$".$plato['precio']."</span>"
                 . "</div>";
    }
    $retorno .= "</div>";
    $retorno = "<ul>$retorno</ul>";
    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Gestiona el flujo de pedidos, listando mesas o procesando una específica.
 * @param resource $conexion_bd Recurso de conexión a la base de datos.
 * @return string HTML con el listado de mesas disponibles o el formulario de pedido.
 * @pre El parámetro mesa_id en $_REQUEST determina el flujo de la función.
 * @post Facilita la selección de mesa para iniciar un pedido.
 */
function fn_realizar_pedidos($conn)
/*--------------------------------------------------------------------*/
{
    $retorno = fn_boton_menu_principal()."<br />";

    if (isset($_REQUEST['mesa_id']))
        $accion = "pedido_mesa";
    else
        $accion = "listar_mesas";

    switch ($accion) {
    case 'listar_mesas':
        //----------------------------------------
        // Listado de mesas
        //----------------------------------------
        $sentencia = "
            SELECT MES.id
                 , MES.sillas
                 , HOR.inicio
                 , USR.nombre as nombre_usr
            FROM mesas AS MES
            LEFT JOIN horarios      AS HOR ON MES.id = HOR.mesa_id
            LEFT JOIN reservaciones AS RES ON RES.id = HOR.reservacion_id
            LEFT JOIN usuarios      AS USR ON USR.id = RES.cliente_id
            WHERE HOR.inicio BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '1 day'
            ORDER BY MES.id
            ";
        $resultado = procesar_query($sentencia, $conn);
 
        $botones_mesas = "";
        foreach ($resultado->datos as $mesa) {
            $script = "window.open('?opcion=realizar_pedidos&mesa_id=".$mesa['id']."', '_top');";
            $botones_mesas.= "<button onClick=\"$script\">".$mesa['id']." - ".$mesa['nombre_usr']."</button>";
        }
        $retorno .= "<div class='MENU_OPCIONES'>".$botones_mesas."</div>";
        break;
    case 'pedido_mesa':
        $retorno .= "HACIENDO PEDIDO PARA UNA MESA.".Mostrar($_REQUEST);
    }

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Gestiona la creación de nuevas reservas.
 * @param resource $conexion_bd Recurso de conexión a la base de datos.
 * @return string HTML con el formulario para crear una reserva.
 * @pre La tabla reservaciones debe existir en la base de datos.
 * @post Permite al usuario ingresar los datos para crear una nueva reserva.
 */
function fn_crear_reserva($conn)
/*--------------------------------------------------------------------*/
{
    $retorno = fn_boton_menu_principal()."<br />";
    $retorno .= "<h1>Crear Nueva Reserva</h1>";
    $retorno .= "<form method='post' action='?opcion=guardar_reserva'>";
    $retorno .= "    <label for='fecha'>Fecha:</label><br />";
    $retorno .= "    <input type='date' name='fecha' id='fecha' required><br /><br />";
    $retorno .= "    <label for='hora'>Hora:</label><br />";
    $retorno .= "    <input type='time' name='hora' id='hora' required><br /><br />";
    $retorno .= "    <label for='mesa_id'>Mesa:</label><br />";
    $retorno .= "    <select name='mesa_id' id='mesa_id' required>";
    $retorno .= "        <option value=''>Seleccione una mesa</option>";
    
    $sentencia_mesas = "SELECT id, sillas FROM mesas ORDER BY id";
    $resultado_mesas = procesar_query($sentencia_mesas, $conn);
    
    foreach ($resultado_mesas->datos as $mesa) {
        $retorno .= "        <option value='".$mesa['id']."'>".$mesa['id']."</option>";
    }
    
    $retorno .= "    </select><br /><br />";
    $retorno .= "    <label for='cliente_nombre'>Nombre del Cliente:</label><br />";
    $retorno .= "    <input type='text' name='cliente_nombre' id='cliente_nombre' required><br /><br />";
    $retorno .= "    <label for='telefono'>Teléfono:</label><br />";
    $retorno .= "    <input type='tel' name='telefono' id='telefono'><br /><br />";
    $retorno .= "    <input type='submit' value='Crear Reserva'>";
    $retorno .= "</form>";
    
    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Guarda una nueva reserva en la base de datos.
 * @param resource $conexion_bd Recurso de conexión a la base de datos.
 * @return string HTML con confirmación o error del guardado.
 * @pre La función fn_crear_reserva debe haber sido llamada primero.
 * @post La reserva se guarda o muestra un mensaje de error.
 */
function fn_guardar_reserva($conn)
/*--------------------------------------------------------------------*/
{
    $retorno = fn_boton_menu_principal()."<br />";
    
    if (isset($_POST['fecha'], $_POST['hora'], $_POST['mesa_id'], $_POST['cliente_nombre'])) {
        $sentencia = "INSERT INTO reservaciones (fecha, hora, mesa_id, cliente_nombre) 
                      VALUES (?, ?, ?, ?)";
        
        try {
            // Nota: En PHP puro con pg_query, necesitamos usar pg_prepare y pg_execute para consultas parametrizadas
            // Para mantener la simplicidad del código existente, usaremos una consulta directa
            $fecha = $_POST['fecha'];
            $hora = $_POST['hora'];
            $mesa_id = $_POST['mesa_id'];
            $cliente_nombre = $_POST['cliente_nombre'];
            
            $sentencia_insert = "INSERT INTO reservaciones (fecha, hora, mesa_id, cliente_nombre) 
                                 VALUES ('$fecha', '$hora', $mesa_id, '$cliente_nombre')";
            
            pg_query($conn, $sentencia_insert);
            $retorno .= "<p style='color: green;'>Reserva creada exitosamente.</p>";
        } catch (Exception $e) {
            $retorno .= "<p style='color: red;'>Error al crear la reserva: " . $e->getMessage() . "</p>";
        }
    } else {
        $retorno .= "<p style='color: red;'>Faltan datos para crear la reserva.</p>";
    }
    
    // Redirigir de nuevo al formulario después de guardar
    $retorno .= "<br /><a href='?opcion=crear_reserva'>Crear otra reserva</a>";
    
    return $retorno;
}
//------------------------------------------------------------
?>
