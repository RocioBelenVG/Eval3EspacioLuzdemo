<?php
/**
 * Compatibilidad: el motor DTE y los generadores de PDF del sistema original
 * cargaban sus librerías desde dte/vendor. En EspacioLuz Demo las dependencias
 * se instalan una sola vez con Composer en la raíz del proyecto (vendor/).
 */
return require dirname(__DIR__, 2) . '/vendor/autoload.php';
