<?php

/**
 * Excepcion de redireccion.
 *
 * Se lanza cuando hay que dejar la peticion actual y enviar al navegador a
 * otra pagina, sin que haya ocurrido un error que haya que enseñar.
 *
 * ============================================================================
 * POR QUE ES UNA EXCEPCION Y NO UN header() SEGUIDO DE exit
 * ============================================================================
 *
 * Lo natural seria escribir «header(...)» y «exit». En una aplicacion normal
 * con Apache y PHP seria correcta, y ademas es lo que hace la mayoria del
 * codigo de la web. Aqui no, por dos razones:
 *
 *   - «exit» a mitad de una peticion deja el resto del codigo sin ejecutar. Si
 *     la redireccion se lanza desde el nucleo, a mitad de la comprobacion de un
 *     rol, pueden quedar sin hacer cosas que si son necesarias: anotar el
 *     intento, cerrar la sesion, terminar la transaccion de la base de datos.
 *     Un fallo de disco en ese momento se traduciria en una pantalla en blanco
 *     en lugar de en un error, que es justo lo que mas cuesta diagnosticar.
 *
 *   - Una excepcion se puede capturar. El nucleo de la aplicacion la captura y
 *     produce la respuesta completa, con su codigo de estado y sus cabeceras, en
 *     un solo sitio. Asi que la lista de rutas que redirigen y la lista de
 *     errores que se traducen a pantalla estan juntas, y anadir una redireccion
 *     nueva no obliga a acordarse de terminar la peticion a mano en un archivo.
 *
 * El verificador de documentacion prohibe precisamente el «exit» dentro de la
 * aplicacion web, por este mismo motivo. Esta clase es la forma correcta de
 * cortar la peticion.
 *
 * @see \App\Core\Controlador::redirigir()
 * @see index.php
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Peticion que debe responderse con una redireccion a otra pagina.
 */
class Redirigir extends ErrorAplicacion
{
    /**
     * Ruta interna de destino, sin la parte inicial.
     *
     * @var string
     */
    private string $ruta;

    /**
     * Codigo HTTP de la redireccion.
     *
     * @var int
     */
    private int $codigo;

    /**
     * Construye la redireccion.
     *
     * @param string $ruta   Ruta interna de destino, por ejemplo 'login'.
     * @param int    $codigo Codigo HTTP; 303 por defecto, que es el que
     *                       corresponde a la respuesta a un envio de
     *                       formulario, porque obliga al navegador a repetir
     *                       con GET en lugar de con POST.
     */
    public function __construct(string $ruta, int $codigo = 303)
    {
        parent::__construct("Redireccion a la pagina «{$ruta}» con codigo {$codigo}.");

        $this->ruta = $ruta;
        $this->codigo = $codigo;
    }

    /**
     * Devuelve la ruta interna de destino.
     *
     * @return string
     */
    public function ruta(): string
    {
        return $this->ruta;
    }

    /**
     * Devuelve el codigo HTTP de la redireccion.
     *
     * @return int
     */
    public function codigo(): int
    {
        return $this->codigo;
    }
}
