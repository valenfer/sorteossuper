<?php

/**
 * Excepcion de dato no valido.
 *
 * Se lanza cuando la aplicacion detecta que un valor no cumple las reglas que
 * ella misma ha definido: un tramo que se solapa con otro, un campo
 * obligatorio vacio, un fichero que no es una imagen, una participacion fuera
 * de horario.
 *
 * Se separa de ErrorAplicacion porque estos dos casos se tratan de forma muy
 * distinta:
 *
 *   - ErrorValidacion  -> HTTP 422. El usuario puede corregir lo que ha
 *                         escrito. Se le muestra su mensaje y se le devuelve a
 *                         la pantalla con los datos conservados.
 *   - ErrorAplicacion  -> HTTP 500. El problema es del sistema. Se registra y
 *                         se muestra un mensaje generico.
 *
 * En el caso de la participacion de una clienta, el mensaje de esta excepcion
 * es el que ve la azafata y, en su parte legible, la clienta. Por eso NUNCA
 * debe incluir datos de otra persona: el apartado 4.7 de la especificacion
 * prohibe expresamente que un rechazo revele informacion de otro participante.
 *
 * @see \App\Core\Validador
 * @see apartado 4.7 de la especificacion
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Error de validacion de datos, apto para mostrar a la persona que escribe.
 */
class ErrorValidacion extends ErrorAplicacion
{
    /**
     * Codigo HTTP de la peticion incorrecta. Es el que corresponde a una
     * peticion bien formada cuyo contenido no es valido.
     */
    protected int $codigoHttp = 422;

    /**
     * Lista de errores de validacion detallados, indexados por el nombre del
     * campo que ha fallado.
     *
     * Se usa en los formularios del panel para marcar cada input con su error
     * al lado, en lugar de mostrar un unico mensaje conjunto que obliga a
     * buscar el campo culpable.
     *
     * @var array<string, string>
     */
    private array $errores = [];

    /**
     * Construye la excepcion con un mensaje general y el detalle por campo.
     *
     * @param string               $mensaje Texto general que resume el fallo,
     *                                      del tipo «Revisa los campos marcados».
     * @param array<string, string> $errores Detalle por campo, con la clave
     *                                      siendo el nombre del campo y el
     *                                      valor el mensaje para la persona.
     */
    public function __construct(string $mensaje, array $errores = [])
    {
        parent::__construct($mensaje);
        $this->errores = $errores;
    }

    /**
     * Devuelve el detalle de errores por campo.
     *
     * @return array<string, string> Mensajes indexados por nombre de campo.
     */
    public function errores(): array
    {
        return $this->errores;
    }
}
