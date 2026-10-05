<?php
//------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once("etc/parametros.php");
require_once("lib/libreria.php");
require_once("lib/restaurante.php");

//------------------------------------------------------------
$conn = pg_conectar($host, $dbname, $user);
$contenido = "";

$opcion = "";
if (isset($_REQUEST['opcion']))
    $opcion = $_REQUEST['opcion'];

if ($opcion != "") {
    $funcion = "fn_".$opcion;
    if (function_exists($funcion))
        $contenido = $funcion($conn);
    else
        $contenido = fn_menu_opciones($conn);
} else
    $contenido = fn_menu_opciones($conn);

//------------------------------------------------------------
$esqueleto = file_get_contents("esqueleto.html");
$html = sprintf($esqueleto, $contenido);
print $html;

//------------------------------------------------------------
?>
