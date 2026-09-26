<?php

/**
 * Modelo de los campos del formulario de participacion.
 *
 * ============================================================================
 * QUE GUARDA ESTA TABLA
 * ============================================================================
 *
 * El apartado 4.8 de la especificacion deja la eleccion de los campos al
 * administrador, con un minimo —nombre, DNI, telefono, direccion, codigo postal,
 * numero de ticket, codigo de participacion y correo— y la posibilidad de anadir
 * los que hagan falta. Cada fila es un campo: su clave interna, la etiqueta que
 * ve la clienta, el tipo de dato que se acepta, si es obligatorio y sus limites.
 *
 * ============================================================================
 * LA CLAVE Y LA ETIQUETA NO SON LO MISMO, Y POR QUE
 * ============================================================================
 *
 * «clave» es un identificador interno estable, en minusculas y sin espacios, del
 * tipo «nombre» o «dni». «etiqueta» es lo que aparece en pantalla. Separarlas
 * permite cambiar el texto que ve la clienta —de «DNI» a «Documento de
 * identidad», o a «Tu número de identidad», que es como lo llama la gente— sin
 * que se rompa nada, porque el programa siempre llama a la clave. Si las dos
 * cosas fueran una, cada cambio de rotulo seria un cambio de programa y un
 *ligera riesgo de perder los datos ya guardados.
 *
 * El indice unico es (promocion_id, clave): dos campos de la misma campana no
 * pueden llamarse igual, porque entonces el formulario tendria dos controles con
 * el mismo nombre y solo llegaria el ultimo al guardar.
 *
 * ============================================================================
 * POR QUE NO HAY UN TIPO QUE ADMITA HTML
 * ============================================================================
 *
 * El tipo limita lo que se acepta, y la lista no incluye ningun tipo que
 * permita marcado. No es una decision que afecte a los datos guardados, que son
 * valores, sino a como se pinta la etiqueta: un campo anadido por el
 * administrador no debe poder inyectar una etiqueta en la pagina de la azafata.
 * Los valores se escapan al imprimirlos de todas formas, pero no se depende de
 * que el que escribe el valor este avisado.
 *
 * @see \App\Services\ConfiguracionPromocion
 * @see \App\Core\Validador
 * @see apartado 4.8 de la especificacion, campos del formulario
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;

/**
 * Acceso a la tabla de campos del formulario.
 */
class CampoFormulario extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'campos_formulario';

    /**
     * Los tipos de dato que admite un campo.
     *
     * La lista es cerrada y no se amplia: cada tipo nuevo es un caso mas que
     * hay que validar en la pantalla, en el almacenamiento y en la comparacion
     * de valores, y el beneficio de un tipo mas no compensa el riesgo de
     * acceptar algo que luego no se sabe tratar.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['texto', 'email', 'telefono', 'entero', 'fecha', 'area'];

    /**
     * El campo que identifica a la persona cuando la campana no pide DNI.
     *
     * D3 deja este campo configurable porque «persona» no es un dato: puede ser
     * el correo, el telefono o el codigo de participacion. El valor de esta
     * constante es el que se propone por defecto cuando hay varios campos
     * compatibles.
     *
     * @var string
     */
    public const CLAVE_IDENTIDAD_POR_DEFECTO = 'correo';

    /**
     * Devuelve los campos de una campana en el orden en que se pintan.
     *
     * @param int  $promocionId Campana cuyos campos se quieren.
     * @param bool $soloVisibles Si es true, se excluyen los ocultos. Lo usa la
     *                           pantalla de la azafata, que solo enseña lo que
     *                           esta marcado como visible.
     *
     * @return array<int, array<string, mixed>> Campos con «orden» ascendente y,
     *                                    dentro de el, «clave» ascendente, que
     *                                    es lo que hace que el orden sea estable
     *                                    aunque dos campos compartan numero.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarPorPromocion(int $promocionId, bool $soloVisibles = false): array
    {
        return $this->db->todos(
            'SELECT id, clave, etiqueta, tipo, obligatorio, visible, orden,
                    valor_por_defecto, min_largo, max_largo
               FROM campos_formulario
              WHERE promocion_id = ?
                AND visible >= ?
              ORDER BY orden ASC, clave ASC',
            [$promocionId, $soloVisibles ? 1 : 0]
        );
    }

    /**
     * Sustituye por completo la lista de campos de una campana.
     *
     * Se borra y se vuelve a insertar, por la misma razon que en el plan de
     * cantidades: la operacion que quiere el administrador es «estos son los
     * campos», no «anade este campo». Con un alta y una baja por cada cambio, un
     * fallo a media lista dejaria el formulario a medias, que es peor que no
     * haber guardado nada.
     *
     * Los campos que ya tienen participaciones guardadas no se borran del
     * historial: la participacion guarda una copia de los datos en la columna
     * «datos» y una copia de las reglas en «reglas_snapshot», de modo que quitar
     * un campo del formulario no puede alterar lo que ya se recogio.
     *
     * @param int   $promocionId Campana cuyos campos se sustituyen.
     * @param array<int, array<string, mixed>> $campos Campos ya validados, cada
     *                                     uno con las claves «clave», «etiqueta»,
     *                                     «tipo», «obligatorio», «visible»,
     *                                     «orden», «valor_por_defecto»,
     *                                     «min_largo» y «max_largo». Las cuatro
     *                                     primeras banderas se pasan como true o
     *                                     false, y los dos largos como enteros:
     *                                     0 en «min_largo» significa que el campo
     *                                     no pone limite inferior, porque la
     *                                     columna es NOT NULL y no admite null.
     *
     * @return int Numero de campos guardados.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function sustituirTodos(int $promocionId, array $campos): int
    {
        $this->db->ejecutar('DELETE FROM campos_formulario WHERE promocion_id = ?', [$promocionId]);

        $ahora = Aplicacion::ahora();
        $guardados = 0;

        foreach ($campos as $campo) {
            $this->db->ejecutar(
                'INSERT INTO campos_formulario (
                     promocion_id, clave, etiqueta, tipo, obligatorio, visible, orden,
                     valor_por_defecto, min_largo, max_largo, creado_en
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $promocionId,
                    $campo['clave'],
                    $campo['etiqueta'],
                    $campo['tipo'],
                    $campo['obligatorio'] ? 1 : 0,
                    $campo['visible'] ? 1 : 0,
                    $campo['orden'],
                    $campo['valor_por_defecto'],
                    $campo['min_largo'],
                    $campo['max_largo'],
                    $ahora,
                ]
            );

            $guardados++;
        }

        return $guardados;
    }

    /**
     * Devuelve las claves de campo que ya usa una campana.
     *
     * Lo usa la pantalla de reglas para ofrecer como «campo de identidad» solo
     * los que existen de verdad, en lugar de una lista fija que incluiria campos
     * que la campana no pide.
     *
     * @param int $promocionId Campana cuyas claves se quieren.
     *
     * @return array<int, string> Claves ordenadas alfabeticamente.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function claves(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT clave FROM campos_formulario WHERE promocion_id = ? ORDER BY clave ASC',
            [$promocionId]
        );

        $claves = [];

        foreach ($filas as $fila) {
            $claves[] = (string) $fila['clave'];
        }

        return $claves;
    }
}
