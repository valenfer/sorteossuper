<?php

/**
 * Excepcion de error de la base de datos.
 *
 * Envuelve las excepciones nativas de PDO para que el mensaje que llega al
 * usuario o al log nunca contenga informacion sensible.
 *
 * POR QUE EXISTE ESTA CLASE. Una excepcion de PDO incluye en su texto la
 * sentencia SQL completa, los valores insertados y, en algunos casos, la
 * cadena de conexion con el usuario y la contrasena de la base de datos. Un
 * error de MySQL mostrado por pantalla en el navegador de la tablet del
 * supermercado permitiria a cualquiera que pase por delante ver la estructura
 * de la base de datos y, si la conexion falla al arrancar, las credenciales.
 * Esta clase es la barrera que lo impide.
 *
 * QUE SE CONSERVA Y QUE SE TIRA. Se conserva la clase de error de MySQL, el
 * numero de codigo y el SQL con los valores sustituidos por marcadores, porque
 * sin eso no hay forma de diagnosticar un fallo. Se descartan la cadena de
 * conexion, el usuario, la contrasena y el volcado de la excepcion original.
 *
 * @see \App\Core\Db
 * @see seccion 13.2 del documento de especificacion, proteccion de datos
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Error-motor de base de datos con el mensaje saneado.
 */
class ErrorBaseDeDatos extends ErrorAplicacion
{
    /**
     * Volcado de la excepcion original de PDO, conservado solo para el log.
     *
     * Se declara aqui y no se inicializa en el constructor porque Exception ya
     * la define como protected. Guardarla permite que el manejador de errores
     * la registre completa en el fichero de log, donde si es util, sin que
     * llegue nunca a la pantalla del navegador.
     *
     * @var \PDOException|null
     */
    private $original = null;

    /**
     * Codigo de error de MySQL o MariaDB, por ejemplo 1062 para clave duplicada
     * o 42S02 para tabla inexistente. Permite reaccionar de forma rapida ante
     * los casos previstos sin tener que descifrar el mensaje.
     *
     * @var string|null
     */
    private ?string $codigoMotor = null;

    /**
     * Construye el error a partir de una excepcion nativa de PDO.
     *
     * @param \PDOException $original Excepcion original de PDO, de la que solo
     *                               se extraen el codigo de motor y el mensaje
     *                               ya saneado.
     */
    public function __construct(\PDOException $original)
    {
        // Se guardan solo el codigo y el mensaje propio de MySQL. El metodo
        // getMessage() de PDOException ya incluye el SQL con los valores, que
        // es justo lo que hace util el diagnostico, pero no la contrasena.
        $this->codigoMotor = (string) $original->getCode();

        $mensaje = 'Error de la base de datos';

        // Se comprueba si es un error conocido de MySQL para poder describirlo
        // en castellano. Se comparan por codigo y no por el texto original,
        // porque el texto puede venir en el idioma del servidor.
        $codigo = $original->getCode();
        if ($codigo === 23000 || $codigo === 1062) {
            $mensaje = 'Ya existe un registro con esos mismos datos. No se ha vuelto a crear.';
        } elseif ((string) $codigo === '42S02') {
            $mensaje = 'Falta alguna tabla de la base de datos. Ejecuta el instalador.';
        } elseif ((string) $codigo === '42S22') {
            $mensaje = 'Falta alguna columna en la base de datos. Revisa la version del esquema.';
        } elseif ($codigo === 2006) {
            $mensaje = 'No se puede contactar con el servidor de base de datos.';
        } elseif ($codigo === 'HY000' && (int) $original->errorInfo[1] === 1045) {
            $mensaje = 'Las credenciales de la base de datos no son correctas.';
        }

        // El mensaje de MySQL se anade al final, entre parentesis, para poder
        // diagnosticar sin exponer nada. Se limita a 300 caracteres porque
        // MySQL devuelve a veces SQL de varios cientos de lineas.
        $detalle = $original->getMessage();
        if (strlen($detalle) > 300) {
            $detalle = substr($detalle, 0, 300) . '...';
        }

        parent::__construct($mensaje . ' Detalle: ' . $detalle, 0);

        // Se conserva la excepcion original por si el log de PHP necesita la
        // traza completa. No se muestra nunca al usuario.
        $this->original = $original;
    }

    /**
     * Devuelve el codigo de error devuelto por el motor de base de datos.
     *
     * @return string Codigo de error de MySQL, o cadena vacia si no lo hay.
     */
    public function codigoMotor(): string
    {
        return (string) $this->codigoMotor;
    }
}
