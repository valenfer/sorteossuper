<?php

/**
 * Transporte «log»: deja el mensaje escrito en el log en vez de mandarlo.
 *
 * ============================================================================
 * POR QUE ESTE ES EL TRANSPORTE POR DEFECTO
 * ============================================================================
 *
 * Porque en una instalacion de trabajo casi siempre es el que se quiere, y el
 * fallo por defecto es el que duele.
 *
 * Si el transporte por defecto fuera el de verdad, bastaria con abrir el
 * formulario de participacion y probarlo en local para que se le mandara un
 * correo de premio a una direccion de verdad, con un codigo de reclamacion
 * verdadero, cada vez que alguien probara la aplicacion. Eso no es un detalle
 * menor: le manda correos a la gente y ademas consume una unidad de cada premio
 * probando. Con un servidor SMTP sin configurar, ademas, cada intento tardaria
 * diez segundos en connectando para nada, y el processor se pasaria el rato
 * esperando.
 *
 * Escribiendo en el log, en cambio, se ve el mensaje entero, con sus
 * sustituciones hechas, y no le llega a nadie. Cuando la instalacion este lista y
 * haya un buzon de salida de verdad, se cambia «correo.transporte» a 'smtp' en
 * la configuracion y se comprueba primero contra un buzon propio.
 *
 * ============================================================================
 * POR QUE CUENTA COMO ENVIADO Y NO COMO FALLIDO
 * ============================================================================
 *
 * El mensaje se marca como enviado. Un transporte que escribe en el log ha hecho
 * lo que se le pedia, que es dejar constancia del mensaje, y si se marcara como
 * error todos los mensajes se acumularian reintentando sin parar y la cola no se
 * vaciaria nunca.
 *
 * ============================================================================
 * @see \App\Services\Mailer
 * @see \App\Services\MailerSmtp
 */

declare(strict_types=1);

namespace App\Services;

/**
 * Deja el mensaje escrito en el log del servidor en lugar de enviarlo.
 */
final class MailerLog implements Mailer
{
    /**
     * Escribe el mensaje en el log.
     *
     * Va todo en un solo error_log() y no en varios, para que el mensaje
     * aparezca entero y en orden. Si se escribieran el asunto y el cuerpo por
     * separado, un cuerpo de varias lineas quedaria repartido entre lineas de log
     * que no se leen juntas, que es justo cuando hace falta leerlo.
     *
     * @param string                $destinatario Direccion de correo.
     * @param string                $asunto       Asunto ya sustituido.
     * @param string                $cuerpo       Cuerpo ya sustituido.
     * @param array<string, string> $cabeceras    Cabeceras adicionales.
     *
     * @return bool Siempre true: escribir en el log no falla nunca.
     */
    public function enviar(
        string $destinatario,
        string $asunto,
        string $cuerpo,
        array $cabeceras = []
    ): bool {
        error_log(sprintf(
            "[correo:log] para=%s asunto=%s cabeceras=%s cuerpo=%s",
            $destinatario,
            $asunto,
            $cabeceras === [] ? '(ninguna)' : (string) json_encode($cabeceras, JSON_UNESCAPED_UNICODE),
            $cuerpo
        ));

        return true;
    }

    /**
     * @return string Siempre «log».
     */
    public function nombre(): string
    {
        return 'log';
    }

    /**
     * Este transporte no puede fallar, asi que no hay motivo que dar.
     *
     * @return string Siempre cadena vacia.
     */
    public function ultimoError(): string
    {
        return '';
    }
}
