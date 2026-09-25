<?php

/**
 * Arranque comun de todos los puntos de entrada.
 *
 * ============================================================================
 * QUE ES ESTE FICHERO Y POR QUE EXISTE
 * ============================================================================
 *
 * Todo script que arranca la aplicacion, ya sea index.php desde el navegador o
 * uno de los de la carpeta bin/ desde la consola, necesita lo mismo: cargar la
 * clase Aplicacion, que registre el autocargador, y arrancar.
 *
 * El problema es que hay un nudo de huevo y gallina:
 *
 *   - Para que PHP sepa qué hacer con una clase, necesita el autocargador.
 *   - El autocargador lo registra un metodo de la clase Aplicacion.
 *   - Para llamar a ese metodo, PHP necesita haber cargado la clase Aplicacion.
 *
 * La solucion es directa: el punto de entrada carga este fichero con un
 * require normal, y este carga a mano la clase Aplicacion, que es el unico
 * fichero que hace falta. Despues, el autocargador ya se encarga de todas las
 * demas, y arrancar() no tiene que hacer nada especial.
 *
 * Esta es la unica clase del proyecto que se carga sin pasar por el
 * autocargador. Se documenta aqui para que quede claro que es intencionado y no
 * un descuido.
 *
 * ============================================================================
 * LO QUE HACE, EN ORDEN
 * ============================================================================
 *
 *  1. Calcula la raiz del proyecto y la normaliza a barras.
 *  2. Carga a mano app\Core\Aplicacion.
 *  3. Registra el autocargador de clases.
 *  4. Llama a arrancar(), que carga la configuracion, fija la zona horaria,
 *     configura los errores y abre la sesion si la peticion es web.
 *
 * A partir de ese momento, cualquier clase de la aplicacion se puede usar
 * escribiendo su nombre completo, sin ningun require mas.
 *
 * ============================================================================
 * USO
 * ============================================================================
 *
 * Desde index.php, en la raiz del proyecto:
 *
 *     <?php
 *     require __DIR__ . '/app/inicio.php';
 *     \App\Core\Aplicacion::arrancar();
 *
 * Desde un script de la carpeta bin/, que esta un nivel mas abajo:
 *
 *     <?php
 *     require __DIR__ . '/../app/inicio.php';
 *     \App\Core\Aplicacion::arrancar();
 *
 * @see \App\Core\Aplicacion::registrarAutoloader()
 * @see \App\Core\Aplicacion::arrancar()
 */

declare(strict_types=1);

// El proyecto se despliega con las carpetas en minusculas y los espacios de
// nombres en mayusculas, que es la convencion PSR-4. Se pide el fichero de
// forma explicita y se comprueba que exista, para que un despliegue con otro
// nombre de carpeta falle aqui con un mensaje claro y no mas adelante con un
// «class not found».
$ficheroAplicacion = __DIR__ . '/Core/Aplicacion.php';

if (!is_file($ficheroAplicacion)) {
    throw new RuntimeException(
        'No se encuentra app/Core/Aplicacion.php. La carpeta app esta incompleta.'
    );
}

require_once $ficheroAplicacion;

// La raiz se deduce de la ubicacion de este propio fichero, que esta siempre en
// <raiz>/app. dirname(__DIR__) sube de app a la raiz del proyecto.
\App\Core\Aplicacion::registrarAutoloader(dirname(__DIR__));
