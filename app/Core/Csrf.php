<?php

/**
 * Proteccion contra CSRF.
 *
 * ============================================================================
 * QUE ES Y POR QUE HACE FALTA
 * ============================================================================
 *
 * Un CSRF (falsificacion de peticion entre sitios) ocurre cuando alguien hace
 * que el navegador de la azafata envie un formulario sin que ella lo sepa. Por
 * ejemplo, con una pagina web oculta cargada en otra pestana que hace un POST
 * a la pantalla de participacion de la tienda.
 *
 * Sin proteccion, ese POST valido: lleva la cookie de sesion de la azafata, el
 * navegador la envia automaticamente y el servidor no tiene forma de saber que
 * la peticion no la ha escrito una persona. El resultado seria que alguien
 * desde fuera podria registrar participaciones ajenas, consumir premios o
 * borrar el calendario de la campana.
 *
 * La proteccion es un token: un valor aleatorio que el servidor genera, guarda
 * en la sesion e incluye en TODOS los formularios. Cuando llega un POST, el
 * servidor comprueba que el token del formulario sea igual al de la sesion. Un
 * atacante de otro sitio no puede conocerlo, porque solo puede ver lo que se
 * sirve a la propia victima, y por tanto su peticion falsa sera rechazada.
 *
 * ============================================================================
 * POR QUE NO ES SUFICIENTE CON QUE EL TOKEN NO SEA LEGIBLE POR JAVASCRIPT
 * ============================================================================
 *
 * Se podria pensar que basta con poner el token en un campo oculto, porque el
 * JavaScript de la pagina no lo ve. Es un error: un atacante que consiga
 * inyectar JavaScript en la pagina (un XSS) si podria leerlo, y entonces la
 * proteccion no serviria de nada. Por eso la aplicacion:
 *
 *   - No usa JavaScript para enviar nada importante, salvo la participacion,
 *     que exige ademas un token de un solo uso (ver mas abajo).
 *   - Escapa toda salida HTML (apartado 9 de la especificacion).
 *
 * ============================================================================
 * TOKEN DE UN SOLO USO EN LA PARTICIPACION
 * ============================================================================
 *
 * La pantalla de participacion es un caso especial, y el mas delicado. Se envia
 * desde JavaScript, y el caso de aceptacion 7 exige que un doble clic o una
 * recarga devuelvan el mismo resultado sin crear una segunda participacion ni
 * consumir otro premio. Eso se resuelve con el token de idempotencia (D1), que
 * es un identificador que genera el navegador por cada intento y que se guarda
 * con un indice unico en la base de datos. Es un mecanismo distinto del token
 * CSRF y complementario: el CSRF demuestra que la peticion la ha hecho esta
 * sesion, y el de idempotencia demuestra que es el mismo intento repetido.
 *
 * @see \App\Core\Controlador::exigirCsrf()
 * @see \App\Services\Participacion
 * @see apartado 9 de la especificacion, proteccion contra CSRF
 * @see caso de aceptacion 7
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Emision y comprobacion de tokens CSRF.
 */
class Csrf
{
    /**
     * Nombre del campo que lleva el token en los formularios.
     *
     * @var string
     */
    public const CAMPO = 'csrf_token';

    /**
     * Longitud en bytes del token generado.
     *
     * 32 bytes dan 64 caracteres hexadecimales, que es el mismo tamaño que
     * recomienda la especificacion de OWASP para este tipo de token. Es
     * suficiente para que no se pueda adivinar.
     */
    private const BYTES = 32;

    /**
     * Devuelve el token CSRF de la sesion en curso, creandolo si hace falta.
     *
     * Se reutiliza el mismo token durante toda la sesion. Generar uno nuevo en
     * cada carga de pagina no aporta seguridad: la amenaza no es que alguien
     * adivine el token por ser corto, sino que lo copie desde otra pagina, y
     * eso lo impide la sesion igual en los dos casos.
     *
     * @return string Token hexadecimal de 64 caracteres.
     */
    public static function token(): string
    {
        // En consola no hay sesion, y generar un token que luego no se podra
        // comprobar no tiene sentido. Se devuelve cadena vacia.
        if (!Aplicacion::esPeticionWeb() || session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }

        if (empty($_SESSION[self::CAMPO]) || !is_string($_SESSION[self::CAMPO])) {
            // random_bytes lanza una excepcion si el sistema no puede dar
            // aleatoriedad criptografica. Es preferible que la pantalla falle
            // a que se genere un token predecible con mt_rand o uniqid.
            $_SESSION[self::CAMPO] = bin2hex(random_bytes(self::BYTES));
        }

        return $_SESSION[self::CAMPO];
    }

    /**
     * Devuelve el campo oculto listo para insertar en un formulario.
     *
     * Se ofrece como metodo y no como helper de plantilla para que ninguna
     * vista tenga que construir el HTML a mano, y para que el nombre del campo
     * no tenga que repetirse en cada formulario, donde un error tipografico
     * haria que la proteccion fallara en silencio.
     *
     * @return string Etiqueta input type hidden con el token dentro.
     */
    public static function campo(): string
    {
        return '<input type="hidden" name="' . self::CAMPO . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * Comprueba que la peticion lleva un token valido y corta si no.
     *
     * La comparacion usa hash_equals y no ==, porque hash_equals tarda el mismo
     * tiempo independientemente de quantos caracteres coincidan. Con ==, un
     * atacante podria deducir el token correcto caracter a caracter midiendo el
     * tiempo de respuesta, comparando un byte cada vez.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si el token falta o no coincide.
     */
    public static function exigirValido(): void
    {
        $esperado = $_SESSION[self::CAMPO] ?? null;
        $recibido = $_POST[self::CAMPO] ?? null;

        // Si no hay token en la sesion, es que la sesion no existe o ha caducado.
        // Se devuelve el mismo mensaje en todos los casos, sin distinguir cual
        // ha sido el fallo, para no darle informacion a quien esta probando.
        if (!is_string($esperado) || !is_string($recibido) || $esperado === '') {
            throw new ErrorValidacion(
                'La sesion ha caducado. Vuelve a cargar la pantalla e intentalo de nuevo.'
            );
        }

        if (!hash_equals($esperado, $recibido)) {
            throw new ErrorValidacion(
                'La sesion no es valida. Vuelve a cargar la pantalla e intentalo de nuevo.'
            );
        }
    }
}
