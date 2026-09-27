<?php

/**
 * Procesa la cola de correos salientes desde la linea de comandos.
 *
 * ============================================================================
 * POR QUE HAY QUE LLAMARLO A MANO Y NO HAY UN DESPERTADOR DENTRO
 * ============================================================================
 *
 * Porque el hito 5 no incluye un planificador, y porque mandarlo desde la propia
 * peticion seria justo el error que D1 prohibe. Si la aplicacion enviara el
 * correo al final de cada participacion, la azafata pagaria la lentitud del
 * servidor de correo en cada pantalla, y un SMTP caido dejaria el sitio parado.
 * Aqui solo se encolan mensajes, y quien envia es un proceso aparte.
 *
 * Como montarlo de forma permanente depende del sistema y es cosa de quien
 * instala, no del codigo:
 *
 *   - Linux, cada cinco minutos, con cron:
 *         * * * * * /ruta/al/proyecto/php bin/enviar_correos.php
 *     Lo normal es usar flock dentro del trabajo de cron para que dos pasadas
 *     no se pisen. El UPDATE condicionado de la cola ya evita el correo doble,
 *     pero no evita que dos procesos hablen con el servidor de SMTP a la vez.
 *
 *   - Windows, con el programador de tareas: el mismo binario cada cinco minutos.
 *
 * En los dos casos, dejar que se solapen es un error de configuracion, no del
 * programa, y por eso el guion avisa al terminar si hacia mucho que no corria.
 *
 * ============================================================================
 * QUE IMPRIME
 * ============================================================================
 *
 * Un recuento y nada mas. El detalle de cada mensaje esta en la tabla «correos»,
 * con su estado y su motivo, que es donde lo mira el administrador. Este guion no
 * es un panel: si imprimiera el cuerpo de cada correo, acabaria en el registro del
 * sistema, con los datos de las clientas, y ahi no puede quedar.
 *
 * El codigo de salida es lo que de verdad importa para un planificador: 0 si todo
 * ha ido bien, 1 si algo ha fallado. Con eso, el planificador puede avisar. Sin
 * el, un fallo de SMTP seria silencioso, que es como se descubre a los tres dias
 * que nadie ha recibido los correos de la campaña del lunes.
 *
 * ============================================================================
 * @see \App\Services\ProcesadorCorreo
 */

declare(strict_types=1);

use App\Services\ProcesadorCorreo;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

require_once __DIR__ . '/../app/inicio.php';

/**
 * Lee una opcion de los argumentos de la linea de comandos.
 *
 * Se admiten tanto «--limite=50» como «--limite 50», porque la primera forma es
 * la que se usa al escribir el trabajo de cron y la segunda al probarlo a mano, y
 * no tiene sentido obligar a recordar cual de las dos va.
 *
 * @param array<int, string> $argumentos Argumentos de $argv.
 * @param string              $nombre     Nombre de la opcion, sin los guiones.
 * @param int|null            $porDefecto Valor si no se escribe la opcion.
 *
 * @return string|int|null Valor de la opcion, o el valor por defecto.
 */
function opcion(array $argumentos, string $nombre, ?int $porDefecto = null)
{
    $conIgual = '--' . $nombre . '=';

    foreach ($argumentos as $posicion => $argumento) {
        if (str_starts_with($argumento, $conIgual)) {
            return substr($argumento, strlen($conIgual));
        }

        if ($argumento === '--' . $nombre) {
            $siguiente = $argumentos[$posicion + 1] ?? null;

            return $siguiente === null || str_starts_with($siguiente, '-')
                ? '1'
                : $siguiente;
        }
    }

    return $porDefecto;
}

$argumentos = array_slice($argv, 1);
$limite = (int) opcion($argumentos, 'limite', 50);
$transporte = ProcesadorCorreo::transporteConfigurado();

echo 'Procesador de correo: transporte ' . $transporte . ', hasta ' . $limite . ' mensajes.' . PHP_EOL;

if ($transporte === 'smtp') {
    // Aviso, no error. Mandar por SMTP es lo correcto en produccion, y que el
    // guion lo recuerde en pantalla cada vez que se ejecuta es la unica parte
    // del trabajo de cron que alguien va a leer de verdad.
    echo 'Aviso: los mensajes salen por SMTP, a las direcciones reales de la cola.' . PHP_EOL;
}

$procesador = new ProcesadorCorreo();
$recuento = $procesador->procesar($limite);

echo PHP_EOL;
echo '  Vistos:    ' . $recuento['vistos'] . PHP_EOL;
echo '  Enviados:  ' . $recuento['enviados'] . PHP_EOL;
echo '  Fallidos:  ' . $recuento['fallidos'] . PHP_EOL;

// Los omitidos no son un fallo: son mensajes que otro proceso tenia ya
// reservados. Se cuentan aparte, y con una nota, porque si se ven muchos de
// seguida significa que hay dos planificadores escribiendo a la vez y conviene
// mirar la configuracion.
if ($recuento['omitidos'] > 0) {
    echo '  Omitidos:  ' . $recuento['omitidos']
        . ' (otro proceso ya los tenía reservados)' . PHP_EOL;
}

if ($recuento['vistos'] === 0) {
    echo PHP_EOL . 'No hay nada pendiente. Nada que hacer.' . PHP_EOL;
    exit(0);
}

if ($recuento['fallidos'] > 0) {
    echo PHP_EOL . 'Ha habido fallos. Cada uno lleva su motivo en la tabla correos,'
        . ' en la columna ultimo_error.' . PHP_EOL;
    echo 'Las participaciones NO se han visto afectadas: un fallo de correo no'
        . ' altera el resultado ya adjudicado.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'Cola vaciada. ' . $recuento['enviados'] . ' mensajes enviados.' . PHP_EOL;
exit(0);
