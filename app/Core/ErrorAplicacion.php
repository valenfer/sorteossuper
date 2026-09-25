<?php

/**
 * Excepcion base de la aplicacion.
 *
 * Todas las excepciones propias heredan de esta clase. Al tener una unica raiz,
 * el nucleo puede distinguirlas de los errores internos de PHP y de las
 * excepciones nativas de PDO: las nuestras son controladas y se muestran al
 * usuario con un mensaje util; las de PHP son un fallo del sistema y se
 * muestran con un mensaje generico.
 *
 * Una excepcion de esta jerarquia puede mostrarse al usuario con su mensaje.
 * Una excepcion que NO herede de ella, jamas.
 *
 * @see \App\Core\ErrorBaseDeDatos
 * @see \App\Core\ErrorValidacion
 * @see \App\Core\ErrorConfiguracion
 */

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Excepcion propia de la aplicacion, apta para mostrar al usuario.
 */
class ErrorAplicacion extends RuntimeException
{
    /**
     * Codigo HTTP con el que debe responder la aplicacion cuando esta
     * excepcion llega al nucleo sin haber sido capturada antes.
     *
     * 500 por defecto, porque la mayoria de los errores internos de la aplicacion
     * son fallos del servidor y no culpa de quien esta usando la pantalla.
     *
     * @var int
     */
    protected int $codigoHttp = 500;

    /**
     * Devuelve el codigo HTTP con el que responder ante esta excepcion.
     *
     * @return int Codigo HTTP entre 400 y 599.
     */
    public function codigoHttp(): int
    {
        return $this->codigoHttp;
    }
}
