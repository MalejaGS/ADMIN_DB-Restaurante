<?php

/*------------------------------------------------------------------*/
/**
 * @brief Establece una conexión con una base de datos PostgreSQL.
 * @param string $anfitrion Dirección del servidor de base de datos (host).
 * @param string $nombre_bd Nombre de la base de datos.
 * @param string $usuario Nombre del usuario de la base de datos.
 * @return resource Recurso de la conexión establecida.
 * @pre El servicio de PostgreSQL debe estar activo y accesible.
 * @post Si la conexión falla, el script termina su ejecución.
 */
function pg_conectar($host, $dbname, $user)
/*--------------------------------------------------------------------*/
{
    $conn = pg_connect("host=$host dbname=$dbname user=$user");
    if (!$conn)
        die("Error de conexión: ");//.pg_last_error());

    return $conn;
}

/*------------------------------------------------------------------*/
/**
 * @brief Formatea una variable para su visualización en depuración.
 * @param mixed $variable La variable, arreglo u objeto a inspeccionar.
 * @return string Cadena con la estructura de la variable dentro de etiquetas <pre>.
 * @pre Ninguna.
 * @post Devuelve el volcado de la variable como texto HTML.
 */
function Mostrar($variable)
/*--------------------------------------------------------------------*/
{
    $retorno = "<pre>".var_export($variable, true)."</pre>";
    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Ejecuta una sentencia SQL y devuelve los resultados en un objeto.
 * @param string $sentencia Consulta SQL a ejecutar.
 * @param resource $conexion Recurso de la conexión activa.
 * @return object Objeto que contiene la 'cantidad' de registros y los 'datos'.
 * @pre La conexión debe estar abierta y la sentencia ser válida.
 * @post Se libera la memoria de la consulta implícitamente al retornar.
 */
function procesar_query($sentencia, $conexion)
/*--------------------------------------------------------------------*/
{
    $retorno = array();
    $respuesta = pg_query($conexion, $sentencia);
    $Qregistros = pg_num_rows($respuesta);
    for ($i = 0; $i < $Qregistros; $i ++) {
        // $fila = pg_fetch_row($respuesta, $i);
        $retorno[] = pg_fetch_array($respuesta, $i, PGSQL_ASSOC);
    }
    return (object) array('cantidad' => $Qregistros, 'datos' => $retorno);
}
?>
