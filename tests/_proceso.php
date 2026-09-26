<?php

/**
 * Proceso auxiliar que participa una vez, para la prueba de concurrencia.
 *
 * ============================================================================
 * POR QUE HACE FALTA UN SEGUNDO PROCESO DE VERDAD
 * ============================================================================
 *
 * El caso de aceptacion 6 pide «simular dos participaciones simultaneas con una
 * sola unidad disponible: solo una recibe el premio». La palabra ultima es «solo
 * una», y para comprobarlo hacen falta dos conexiones distintas.
 *
 * En un solo proceso no se puede. Todo el objeto de GET_LOCK y de SELECT ... FOR
 * UPDATE es que dos conexiones competing por la misma fila se estorben; con una
 * sola conexion no hay competencia, el bloqueo se concede siempre a la primera
 * llamada sin esperar, y una prueba escrita asi pasaria siempre y no comprobaria
 * nada. Seria el peor tipo de prueba: verde, y falsa.
 *
 * Por eso este fichero se lanza DOS VECES como proceso independiente, con dos
 * conexiones propias, que intentan participar a la vez.
 *
 * ============================================================================
 * POR QUE EL RESULTADO SE ESCRIBE EN UN FICHERO Y NO SE MUESTRA POR PANTALLA
 * ============================================================================
 *
 * Porque dos procesos escribiendo a la vez en la misma salida se mezclan. Cada
 * uno escribe en su propio fichero y el padre los lee despues, que es la unica
 * forma de saber cual de los dos gano sin depender de un orden que no existe.
 *
 * ============================================================================
 * LA GUARDA DE LA BASE DE DATOS
 * ============================================================================
 *
 * Este script se ejecuta FUERA de tests/run.php, asi que no ha pasado por la
 * comprobacion de que la base de pruebas no se llama igual que la de la campana.
 * Como el padre le pasa el nombre por la linea de comandos, aqui se repite la
 * comprobacion, y si coinciden el proceso se niega a arrancar.
 *
 * Sin esa guarda, un error de tecleo en el padre haria que estos dos procesos
 * escribieran participaciones de prueba en la campana de un supermercado real.
 * Es el unico punto del proyecto donde una prueba podria tocar datos de verdad, y
 * por eso esta cerradura se repite aqui aunque parezca redundante.
 *
 * ============================================================================
 * POR QUE LA CAMPANA VIENE POR IDENTIFICADOR Y NO POR NOMBRE
 * ============================================================================
 *
 * Por nada, en principio: porque por nombre habria que conformarse con un
 * SELECT ... LIMIT 1 sin orden. Si dos campanas se llamaran igual (una
 * ejecucion anterior que se quedo a medias, otro caso que comparta el helper
 * de escenarios) el proceso participaria en la que le tocara, y la prueba
 * mediria otra cosa sin enterarse. El padre le pasa el id que acaba de crear.
 *
 * @see tests/run.php, caso 7
 * @see \App\Services\Adjudicador
 * @see caso de aceptacion 6
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/inicio.php';
require_once __DIR__ . '/_escenario.php';

use App\Core\Aplicacion;

/**
 * Escribe el resultado en el fichero indicado y termina.
 *
 * Se hace con file_put_contents y no con un return, porque este script no tiene
 * un cuerpo que pueda devolver nada: lo que importa es lo que se queda escrito en
 * disco, que es lo que leera el proceso padre.
 *
 * @param array<string, mixed> $resultado Resultado a escribir.
 * @param string               $fichero   Ruta del fichero de salida.
 *
 * @return void
 */
function escribirResultado(array $resultado, string $fichero): void
{
    file_put_contents(
        $fichero,
        json_encode($resultado, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

$base = $argv[1] ?? '';
$ficheroSalida = $argv[2] ?? '';
$claveIdempotencia = $argv[3] ?? '';
$momento = $argv[4] ?? '';
$tramoId = isset($argv[5]) ? (int) $argv[5] : 0;
$promocionId = isset($argv[6]) ? (int) $argv[6] : 0;

if ($base === '' || $ficheroSalida === '' || $claveIdempotencia === '' || $momento === '' || $promocionId <= 0) {
    escribirResultado(
        ['ok' => false, 'error' => 'Faltan argumentos.'],
        $ficheroSalida !== '' ? $ficheroSalida : 'php://stderr'
    );
    exit(1);
}

try {
    Aplicacion::arrancar(dirname(__DIR__));

    // La guarda. Se compara el nombre recibido con el de la campana ANTES de
    // cambiar nada, y se sale con un error claro si coinciden.
    $nombreCampana = (string) Aplicacion::config()['bd']['nombre'];

    if ($base === $nombreCampana) {
        escribirResultado(
            ['ok' => false, 'error' => 'El proceso hijo se niega a usar la base de la campana.'],
            $ficheroSalida
        );
        exit(1);
    }

    Aplicacion::usarBaseDePruebas($base);

    $motor = new \App\Services\Adjudicador(new ValidadorQueAcepta());

    // Cada proceso espera un poco antes de participar, y un poco distinto. Sin
    // esto los dos arranque tan a la vez que uno termina antes de que el otro
    // haya llegado al bloqueo, y la prueba dejaria de medir lo que dice medir.
    // Con la espera, es probable que el segundo este esperando al primero cuando
    // llegue, que es exactamente la situacion que D8 describe.
    usleep(random_int(0, 250) * 1000);

    // La campana se recibe ya por identificador y NO se busca por nombre. Con
    // el nombre habria que conformarse con un SELECT ... LIMIT 1 sin orden, y
    // si por lo que sea quedara otra campana con el mismo nombre (una
    // ejecucion anterior que se quedo a medias, otro caso que comparta el
    // helper de escenarios) este proceso participaria en la campana
    // equivocada sin decir nada. Es el peor fallo posible en una prueba de
    // concurrencia: uno que no falla donde deberia, o que falla con un error
    // que no tiene nada que ver. El identificador no admite Confusion.
    $resultado = $motor->registrar(
        $promocionId,
        $claveIdempotencia,
        $tramoId > 0 ? $tramoId : null,
        ['nombre' => 'Cliente de la prueba de concurrencia'],
        null,
        null,
        $momento
    );

    escribirResultado(['ok' => true, 'resultado' => $resultado], $ficheroSalida);
    exit(0);
} catch (Throwable $e) {
    escribirResultado(
        ['ok' => false, 'error' => get_class($e) . ': ' . $e->getMessage()],
        $ficheroSalida
    );
    exit(1);
}
