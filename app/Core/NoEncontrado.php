<?php

/**
 * Excepcion de peticion no encontrada.
 *
 * Se lanza cuando ninguna ruta del enrutador coincide con la URL pedida. Se
 * distingue de ErrorAplicacion porque la respuesta correcta es un 404, no un
 * 500: la peticion no es un fallo del sistema, simplemente no existe.
 *
 * Tambien se usa para las rutas restringidas por rol. Una azafata que intenta
 * abrir el panel de administracion recibe un 404 y no un 403, porque un 403
 * confirmaria que la ruta existe y permitiria enumerar las pantallas de la
 * aplicacion probando rutas.
 *
 * @see \App\Core\Router
 * @see apartado 3 de la especificacion, control de acceso por rol
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Peticion no encontrada, o acceso denegado por rol, con respuesta 404.
 */
class NoEncontrado extends ErrorAplicacion
{
    /**
     * Codigo HTTP de la peticion incorrecta.
     */
    protected int $codigoHttp = 404;

    /**
     * Construye el error a partir de la ruta que no ha coincidido.
     *
     * La ruta se incluye en el mensaje porque el mensaje va al log y a la
     * pantalla de depuracion del administrador, no a una clienta.
     *
     * @param string $ruta Ruta pedida que no coincide con ninguna registrada.
     */
    public function __construct(string $ruta)
    {
        parent::__construct("No existe la pagina solicitada: «{$ruta}».");
    }
}
