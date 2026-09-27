<?php

/**
 * Interfaz de envio de correo.
 *
 * ============================================================================
 * POR QUE EXISTE, Y POR QUE NO ES UN LLAMADO A mail()
 * ============================================================================
 *
 * La razon inmediata es que la instalacion de XAMPP de este entorno no tiene la
 * extension mail() (D1), y la que de verdad importa es que separa el mensaje de
 * su envio. El hilo 5 solo tiene que encolar un texto ya sustituido; como esta
 * detras de una interfaz, el mismo processor sirve para escribir el mensaje en el
 * log de una instalacion de pruebas y para entregarselo a un servidor SMTP de
 * verdad, sin cambiar ni una linea de lo que encola.
 *
 * La segunda razon es que la prueba tiene que poder fallar. El caso de
 * aceptacion 9 pide simular un fallo de envio conservando la adjudicacion, y eso
 * no se puede provocar de otra manera sin romper algo de verdad: si el
 * procesador llamara a mail() directamente, la prueba dependeria de como este
 * configurado el correo de la maquina y el fallo que se quiere comprobar seria el
 * fallo que de verdad tenga el servidor, no el que la prueba ha pedido. Con la
 * interfaz, la prueba pasa un doble que falla cuando ella quiere y comprueba que
 * el mensaje queda en estado de error con su motivo escrito.
 *
 * Y hay una tercera, que es la que se nota al leer las pruebas: con una interfaz
 * se puede comprobar que un mensaje sale con el asunto y el cuerpo que ha escrito
 * el administrador, sin mandarselo a nadie. Con el transporte «log» de esta
 * instalacion, eso es gratis.
 *
 * ============================================================================
 * QUE IMPORTA DE LOS DOS TRANSPORTES
 * ============================================================================
 *
 * Los dos tienen que cumplir lo mismo: devolver true si el mensaje ha salido y
 * false si no, sin lanzar excepciones. El motivo es que «no se ha podido enviar»
 * es un resultado normal de la cola, no un fallo del programa. Un fallo de envio
 * no se propaga porque tiene que quedar escrito en la fila del mensaje y la
 * adjudicacion tiene que seguir valida aunque el correo no llegue nunca (apartado
 * 5.4 de la especificacion). Si el transporte lanzara, un buzon de correo caido
 * pararia el processor entero y los mensajes siguientes no se intentarian.
 *
 * ============================================================================
 * @see \App\Services\MailerLog
 * @see \App\Services\MailerSmtp
 * @see \App\Services\ProcesadorCorreo
 */

declare(strict_types=1);

namespace App\Services;

/**
 * Envia un mensaje de correo con uno de los transportes de la aplicacion.
 */
interface Mailer
{
    /**
     * Envia un mensaje.
     *
     * @param string                $destinatario Direccion de correo.
     * @param string                $asunto       Asunto ya sustituido.
     * @param string                $cuerpo       Cuerpo ya sustituido.
     * @param array<string, string> $cabeceras    Cabeceras adicionales, como
     *                                             «Content-Type».
     *
     * @return bool True si el mensaje ha salido, false si no. No lanza.
     */
    public function enviar(
        string $destinatario,
        string $asunto,
        string $cuerpo,
        array $cabeceras = []
    ): bool;

    /**
     * Nombre del transporte, tal como se guarda en la columna «transporte».
     *
     * Se guarda en cada mensaje enviado para que dentro de seis meses se pueda
     * decir si ese correo salio de verdad o solo se escribio en el log, que son
     * dos cosas que desde fuera se ven igual.
     *
     * @return string Nombre corto del transporte, «log» o «smtp».
     */
    public function nombre(): string;

    /**
     * Motivo del ultimo envio fallido, para escribirlo en la fila del mensaje.
     *
     * Existe por esto: la columna «ultimo_error» guarda un texto resumido que se
     * le enseña al administrador, y un texto generico del tipo «no se ha podido
     * enviar» no le dice nada. En cambio «el servidor no acepta EHLO» o «235
     * credenciales rechazadas» sí dice qué mirar. El transporte lo sabe porque es
     * quien ha tenido la conversacion SMTP; obligarle a que la devuelva es mejor
     * que adivinarlo en el processor.
     *
     * @return string Motivo del fallo, o cadena vacia si el ultimo envio fue
     *              bien o si este transporte no puede fallar.
     */
    public function ultimoError(): string;
}
