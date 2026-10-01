<?php

/**
 * Purgador de datos personales de campanas que ya han cumplido su plazo de
 * retencion, desde la linea de comandos.
 *
 * ============================================================================
 * POR QUE ESTE TRABAJO NO SE HACE SOLO
 * ============================================================================
 *
 * Por la misma razon que el procesador de correo: si la aplicacion purgara al
 * final de cada peticion, bastaria una visita al panel un dia de plenty para
 * vaciar las tres tablas de una campana entera, y ese trabajo no cabe en el
 * tiempo de una respuesta web. Ademas, una purga es irreversible: no es un
 * cambio de estado que se pueda repetir desde la interfaz, y las acciones que no
 * se pueden deshacer no se lanzan por el simple hecho de que alguien haya
 * cargado una pagina.
 *
 * Como montarlo de forma permanente es cosa de quien instala, no del codigo:
 *
 *   - Linux, una vez al dia, con cron:
 *         30 3 * * * /ruta/al/proyecto/php bin/purgar_datos.php
 *   - Windows, con el programador de tareas: el mismo binario a diario.
 *
 * La hora importa menos que el dia. Lo que no puede ser es cada cinco minutos: la
 * purga es idempotente y el guion no haria nada nuevo, pero habria una pasada
 * inutil cada cinco minutos durante años.
 *
 * ============================================================================
 * POR QUE SIMULA POR DEFECTO Y POR QUE HAY QUE ESCRIBIR A MANO PARA PURGAR
 * ============================================================================
 *
 * Porque «--simular» es el valor por defecto y hay que escribir «--real» para
 * cambiarlo. Al reves de lo normal, y es deliberado.
 *
 * Una purga no tiene vuelta atras. No hay un «deshacer» en el que apoyarse, ni
 * una copia de lo vaciado en ningun sitio, ni un segundo intento que sirva.
 * Cuando alguien instala esto, el primer trabajo de las tres de la mañana tiene
 * que enseñar lo que habria pasado, y el que de verdad borra tiene que haber
 * escrito la palabra que lo pide.
 *
 * Un argumento con valor por defecto copiado y pegado es el accidente mas
 * probable de toda esta parte del sistema. El binario de la instalacion, que si
 * que tiene uno, se llama instalar; este no.
 *
 * ============================================================================
 * POR QUE ESTE GUION NO IMPRIME NINGUN DATO PERSONAL
 * ============================================================================
 *
 * Porque imprime recuentos y nombres de campana, que no identifican a nadie. Los
 * nombres, el destino de los correos y los codigos de reclamacion se quedan en
 * la base de datos, que es donde el apartado 7 quiere que esten y donde un
 * registro del sistema no debe ser una segunda copia. El guion no puede saber si
 * su salida acaba en un archivo, en un correo de aviso o en la pantalla de un
 *ordinates compartido, y lo que no debe hacer es ampliar el numero de sitios donde
 * hay un dato personal.
 *
 * ============================================================================
 * QUE CODIGO DE SALIDA DEVUELVE
 * ============================================================================
 *
 * 0 si todo ha ido bien, tambien si no habia nada que hacer —una noche que no
 * ocurre con ninguna campana pendiente es un noche correcta—, y 1 si algo ha
 * fallado. Es lo que un planificador necesita para avisar, y es la misma
 * convencion que \App\Services\ProcesadorCorreo.
 *
 * @see \App\Services\Purgador
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Services\Purgador;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

require_once __DIR__ . '/../app/inicio.php';

/**
 * Lee una opcion de los argumentos de la linea de comandos.
 *
 * Se admiten tanto «--campana=7» como «--campana 7», porque la primera forma es la
 * que se usa al escribir el trabajo de cron y la segunda al probarlo a mano, y no
 * tiene sentido obligar a recordar cual de las dos va.
 *
 * @param array<int, string> $argumentos Argumentos de $argv.
 * @param string              $nombre     Nombre de la opcion, sin los guiones.
 * @param string|null         $porDefecto Valor si no se escribe la opcion.
 *
 * @return string|null Valor de la opcion, o el valor por defecto.
 */
function opcion(array $argumentos, string $nombre, ?string $porDefecto = null): ?string
{
    $conIgual = '--' . $nombre . '=';

    foreach ($argumentos as $posicion => $argumento) {
        if (str_starts_with($argumento, $conIgual)) {
            return substr($argumento, strlen($conIgual));
        }

        if ($argumento === '--' . $nombre) {
            $siguiente = $argumentos[$posicion + 1] ?? null;

            return $siguiente === null || str_starts_with($siguiente, '-') ? '1' : $siguiente;
        }
    }

    return $porDefecto;
}

/**
 * Dice si un argumento es una opcion sin valor.
 *
 * @param array<int, string> $argumentos Argumentos de $argv.
 * @param string              $nombre     Nombre de la opcion, sin los guiones.
 *
 * @return bool True si la opcion aparece sin valor.
 */
function presente(array $argumentos, string $nombre): bool
{
    return in_array('--' . $nombre, $argumentos, true);
}

$argumentos = array_slice($argv, 1);

// Las dos cosas que se pueden cambiar de una pasada a otra: cuantas campanas y si
// se escribe o no. El limite tiene un tope alto a proposito, porque una campana
// grande es una de las que mas datos tiene y dejarla fuera de la pasada es peor
// que tardar mas.
$limite = (int) opcion($argumentos, 'limite', '50');
$simular = !presente($argumentos, 'real');
$campana = opcion($argumentos, 'campana');

echo 'Purgador de datos personales.' . PHP_EOL;
echo '  Modo:     ' . ($simular ? 'SIMULACION (no se escribe nada)' : 'REAL') . PHP_EOL;
echo '  Momento:  ' . Aplicacion::ahora() . PHP_EOL;

if ($campana !== null) {
    // El identificador se comprueba aqui y no se deja que llegue al servicio.
    // La comprobacion del servicio es la que protege los datos, y esta es la que
    // da un mensaje util: «--campana=abc» no es una campana que no se pueda
    // purgar, es un argumento mal escrito.
    if (!ctype_digit($campana) || (int) $campana < 1) {
        echo PHP_EOL . 'El valor de --campana tiene que ser un identificador numerico.'
            . PHP_EOL;
        exit(1);
    }

    echo '  Campana:  ' . $campana . ' (solo esta)' . PHP_EOL;
} else {
    echo '  Campanas: hasta ' . $limite . ' por pasada, las mas antiguas primero.'
        . PHP_EOL;
}

$purgador = new Purgador();

try {
    if ($campana !== null) {
        $detalles = [$purgador->purgarCampana((int) $campana, Aplicacion::ahora(), $simular)];
    } else {
        $recuento = $purgador->purgar(Aplicacion::ahora(), $limite, $simular);
        $detalles = $recuento['campanas'];
    }
} catch (Throwable $e) {
    // El mensaje se imprime sin la traza porque quien lo ve es quien programo el
    // trabajo de cron, que necesita saber que paso pero no necesita una traza de
    // PHP en su registro. Si el fallo es de codigo, la traza sale con
    // --depurar.
    echo PHP_EOL . 'No se ha podido purgar: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL;

if ($detalles === []) {
    echo 'No hay ninguna campana con datos pendientes de purgar.' . PHP_EOL;
    exit(0);
}

$totales = ['participaciones' => 0, 'correos' => 0, 'rechazos' => 0];

foreach ($detalles as $detalle) {
    $verbos = $simular ? 'se vaciaria' : 'vaciada';

    echo 'Campana ' . $detalle['promocion_id'] . ': ' . $detalle['nombre'] . PHP_EOL;
    echo '  Participaciones ' . $verbos . ': ' . $detalle['participaciones'] . PHP_EOL;
    echo '  Correos ' . $verbos . ':          ' . $detalle['correos'] . PHP_EOL;
    echo '  Huellas de rechazo vaciadas:    ' . $detalle['rechazos'] . PHP_EOL;
    echo PHP_EOL;

    foreach ($totales as $clave => $total) {
        $totales[$clave] = $total + $detalle[$clave];
    }
}

if ($simular) {
    echo 'SIMULACION. No se ha escrito nada en la base de datos.' . PHP_EOL;
    echo 'Para hacer la purga de verdad hay que ejecutar este mismo comando' . PHP_EOL;
    echo 'con la opcion --real.' . PHP_EOL;
    exit(0);
}

echo 'Purga terminada.' . PHP_EOL;
echo '  Participaciones vaciadas: ' . $totales['participaciones'] . PHP_EOL;
echo '  Correos vaciados:          ' . $totales['correos'] . PHP_EOL;
echo '  Huellas de rechazo:        ' . $totales['rechazos'] . PHP_EOL;
exit(0);
