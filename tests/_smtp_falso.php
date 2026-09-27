<?php

/**
 * Servidor de correo falso, para probar el transporte SMTP de verdad.
 *
 * ============================================================================
 * POR QUE HAY QUE LEVANTAR UN SERVIDOR SMTP DE VERDAD
 * ============================================================================
 *
 * Porque el cliente SMTP de \App\Services\MailerSmtp no tiene una sola cosa
 * dificil: montar el mensaje. Lo dificil es la conversacion, y esa solo se
 * comprueba hablando con alguien. Un unit test que comprobara que
 * MailerSmtp::componer() devuelve una cadena con las cabeceras correctas pasaria
 * con el EHLO mal escrito, con un MAIL FROM al reves o con un salto de linea
 * donde no toca, porque ninguno de esos fallos se ve en el texto: se ven cuando
 * el otro lado contesta otra cosa.
 *
 * Asi que esto se levanta como un proceso hijo que hace de servidor SMTP minimo
 * en un puerto libre, habla con el cliente de verdad, y anota todo lo que ha
 * recibido en un fichero. El proceso padre, en la prueba, comprueba ese fichero.
 * Es la unica forma de comprobar, de verdad, que el mensaje llega entero y que
 * el cliente no se queda colgado esperando una linea que no lee.
 *
 * ============================================================================
 * POR QUE ES UN SCRIPT SUELTO Y NO UNA CLASE
 * ============================================================================
 *
 * Porque se ejecuta en otro proceso, con otros argumentos, y no necesita nada
 * de la aplicacion: ni base de datos, ni configuracion, ni autocargador. Por eso
 * no requiere app/inicio.php, y por eso no se le pide documentacion PHPDoc de
 * metodos: no hay metodos, es un guion.
 *
 * ============================================================================
 * LO QUE RESPONDE
 * ============================================================================
 *
 * Se comporta como un servidor educado: 220 al conectar, 250 al EHLO, 250 al
 * MAIL FROM, 250 al RCPT TO, 354 al DATA y 250 al punto final. En el EHLO anuncia
 * STARTTLS aunque no sepa hacerlo, porque asi se comprueba que el cliente, al no
 * estar en modo 'tls', no lo intenta: si lo intentara, se quedaria esperando el
 * 220 que este servidor no da, y la prueba fallaria con un error de tiempo, que
 * es justo el fallo que se quiere cazar.
 *
 * Ademas, y esto es lo importante, comprueba lo que el cliente deberia haber
 * hecho y lo que no deberia: que mande EHLO y no HELO, que mande MAIL FROM antes
 * que RCPT TO, y que termine el cuerpo con un punto solo.
 *
 * ============================================================================
 * USO
 * ============================================================================
 *
 *     php tests/_smtp_falso.php <fichero> <puerto>
 *
 * Escribe en <fichero> un volcado de la conversacion y sale. Si no recibe nada en
 * un rato, sale tambien, dejando el fichero a medias: una prueba que se queda
 * colgada esperando un fichero que no se va a escribir no informa de nada.
 *
 * @see \App\Services\MailerSmtp
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ficheroSalida = $argv[1] ?? '';
$puerto = (int) ($argv[2] ?? 0);

// El puerto 0 es valido y es el que se usa siempre: significa «elige uno libre».
// Por eso la comprobacion es menor que cero y no menor o igual que cero, que
// rechazaria justo el valor que se le pide.
if ($ficheroSalida === '' || $puerto < 0) {
    fwrite(STDERR, "Uso: php _smtp_falso.php <fichero> <puerto>\n");
    exit(1);
}

$anotar = static function (string $linea) use ($ficheroSalida): void {
    file_put_contents($ficheroSalida, $linea, FILE_APPEND);
};

// El puerto 0 deja que el sistema elija uno libre, que es mejor que probar uno
// fijo y encontrarse con que ya lo tiene otro proceso. Se escribe cual es para
// que el padre lo sepa antes de poder conectarse a el.
$escucha = @stream_socket_server('tcp://127.0.0.1:' . $puerto, $codigo, $mensaje);

if ($escucha === false) {
    $anotar('ERROR no se pudo escuchar: ' . $mensaje . PHP_EOL);
    exit(1);
}

$local = (string) stream_socket_get_name($escucha, false);
$puertoReal = (int) substr($local, strrpos($local, ':') + 1);
$anotar('PUERTO ' . $puertoReal . PHP_EOL);

// Si el padre no se conecta en quince segundos, se sale. Es una salvaguarda
// para que un fallo del padre no deje este proceso colgado para siempre.
stream_set_timeout($escucha, 15);

$conexion = @stream_socket_accept($escucha, 15);

if ($conexion === false) {
    $anotar('ERROR nadie se ha conectado' . PHP_EOL);
    fclose($escucha);
    exit(1);
}

$anotar('ACEPTADA' . PHP_EOL);
fwrite($conexion, "220 falso.local ESMTP listo" . "\r\n");

/**
 * Lee una linea del cliente y la anota.
 *
 * Las respuestas van con los codigos de exito que llevaria un servidor de
 * verdad, para que el cliente avance como avanzaria en produccion.
 */
$leer = static function ($conexion, string $enRespuestaA) use ($anotar): string {
    $linea = fgets($conexion, 1024);

    if ($linea === false) {
        $anotar('FIN sin linea' . PHP_EOL);

        return '';
    }

    $anotar($linea);
    $orden = strtoupper(trim(substr($linea, 0, 4)));

    switch ($orden) {
        case 'EHLO':
            // Se anuncia STARTTLS a proposito, para comprobar que el cliente no
            // lo usa cuando no le toca. La respuesta va con guion en las lineas
            // intermedias, que es como se ve de verdad una multilinea.
            fwrite($conexion, "250-falso.local dice hola" . "\r\n");
            fwrite($conexion, "250-STARTTLS" . "\r\n");
            fwrite($conexion, "250 8BITMIME" . "\r\n");
            break;

        case 'HELO':
            // Un servidor real tambien lo acepta. Se anota aparte para que la
            // prueba pueda decir que el cliente uso HELO, que es un error, sin
            // tener que mirar el fichero entero.
            $anotar('AVISO el cliente ha usado HELO en vez de EHLO' . PHP_EOL);
            fwrite($conexion, "250 falso.local" . "\r\n");
            break;

        case 'MAIL':
            $anotar('OK MAIL FROM en ' . $enRespuestaA . PHP_EOL);
            fwrite($conexion, "250 2.1.0 emisor ok" . "\r\n");
            break;

        case 'RCPT':
            $anotar('OK RCPT TO en ' . $enRespuestaA . PHP_EOL);
            fwrite($conexion, "250 2.1.5 destinatario ok" . "\r\n");
            break;

        case 'DATA':
            $anotar('OK DATA en ' . $enRespuestaA . PHP_EOL);
            fwrite($conexion, "354 sigue con el cuerpo y acaba en punto" . "\r\n");
            break;

        case 'QUIT':
            fwrite($conexion, "221 2.0.0 hasta luego" . "\r\n");
            break;

        default:
            $anotar('ORDEN NO CONOCIDA: ' . trim($linea) . PHP_EOL);
            fwrite($conexion, "502 orden no reconocida" . "\r\n");
    }

    return $linea;
};

// El cuerpo del mensaje se lee linea a linea hasta una que sea solo un punto. Se
// anota entero, con las cabeceras, para que la prueba pueda comprobar que ha
// llegado el asunto y el cuerpo que habia escrito la campana.
$ultima = '';
$cuerpo = 0;
$pasada = 0;

while (true) {
    $pasada++;
    $linea = $leer($conexion, (string) $cuerpo);

    if ($linea === '' || $pasada > 200) {
        break;
    }

    $ultima = $linea;

    if ($cuerpo === 0 && strtoupper(substr(trim($linea), 0, 4)) === 'DATA') {
        $cuerpo = 1;
        $continuo = 0;

        while (true) {
            $deDatos = fgets($conexion, 1024);

            if ($deDatos === false) {
                break 2;
            }

            $anotar('CUERPO ' . rtrim($deDatos, "\r\n") . PHP_EOL);

            if (trim($deDatos) === '.') {
                fwrite($conexion, "250 2.0.0 mensaje guardado" . "\r\n");
                break;
            }

            $continuo++;

            if ($continuo > 500) {
                break 2;
            }
        }
    }

    if (strtoupper(substr(trim($ultima), 0, 4)) === 'QUIT') {
        break;
    }
}

$anotar('FIN' . PHP_EOL);
fclose($conexion);
fclose($escucha);
exit(0);
