<?php
date_default_timezone_set('America/Mexico_City');
setlocale(LC_MONETARY, 'es_MX.UTF-8');

//PARA CU
define('LUGAR', 'CU');

//PARA MED
//define('LUGAR', 'MED');

//Conexiones CU
$connect = mysqli_connect("localhost", "plk-cu", "PuntoDeVenta!2025", "plk-cu01") or die("Error de conexión");

//Conexion MED
//$connect = mysqli_connect("localhost", "plk-cu", "PuntoDeVenta!2025", "plk-cu01") or die("Error de conexión");

//Conexion Remota (DEV)
//$connect = mysqli_connect("plk.cu-caja.vpn", "danhell", "WildChild82", "plk-cu01") or die("Error de conexión");

// Cargar funciones
require_once __DIR__ . '/functions_rd.php';
?>