<?php

/**
 * Modelo del catalogo de tipos de premio.
 *
 * ============================================================================
 * QUE ES UN TIPO DE PREMIO Y POR QUE NO ES UN PREMIO
 * ============================================================================
 *
 * Un tipo de premio es «microondas», «auriculares», «batidora». Un premio es
 * «el microondas de las 10:12 del lunes». La distincion no es academica: el
 * apartado 4.3 pide crear el catalogo primero y asignar cantidades despues, y
 * la tabla tipos_premio guarda lo primero mientras que las unidades de la cola
 * guardan lo segundo.
 *
 * ============================================================================
 * POR QUE SE DESACTIVA Y NO SE BORRA
 * ============================================================================
 *
 * El apartado 4.3 pide editar el catalogo sin alterar el historial de premios ya
 * adjudicados, y hay dos razones tecnicas que lo imponen:
 *
 *   - La columna fk_unidades_tipo es ON DELETE RESTRICT. Borrar un tipo del que
 *     hay unidades en la cola no se puede hacer, y no por un descuido del
 *     esquema: es exactamente el comportamiento que se quiere.
 *   - Una unidad entregada guarda su tipo_premio_id y el nombre se lee de aqui.
 *     Si al borrar el tipo se borrase el nombre, el historial de lo entregado
 *     perderia la informacion de QUE se entrego. Renombrar el tipo, en cambio,
 *     solo cambia como se muestra a partir de ahora, y el esquema lo permite
 *     porque el nombre no esta copiado en la unidad.
 *
 * Por eso hay un interruptor «activo» en lugar de un boton de borrar, y por eso
 * generar() solo ofrece los tipos activos. Un premio que se ha retirado de la
 * campana sigue siendo el que aparece en el historial de quien lo gano.
 *
 * ============================================================================
 * POR QUE EL NOMBRE ES UNICO DENTRO DE LA CAMPANA Y NO GLOBAL
 * ============================================================================
 *
 * El indice uq_tipos_premio_nombre es (promocion_id, nombre). Dos campanas
 * distintas —una de Navidad y otra de verano— pueden tener las dos «batidora» sin
 * problema, porque son cosas distintas. Lo que no puede pasar es que una misma
 * campana tenga dos premios con el mismo nombre, porque entonces el panel no
 * podria distinguirlos y el generaador de calendario repartiria unidades entre
 * dos filas indistinguibles.
 *
 * @see \App\Services\Calendario
 * @see \App\Models\UnidadPremio
 * @see apartado 4.3 de la especificacion, catalogo de tipos de premio
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;
use App\Core\NoEncontrado;

/**
 * Acceso a la tabla de tipos de premio.
 */
class TipoPremio extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'tipos_premio';

    /**
     * Devuelve el catalogo de una campana con sus recuentos.
     *
     * Se piden los tipos inactivos tambien, y se les marca como tales en la
     * consulta, porque el panel tiene que poder volver a activarlos. Un catalogo
     * que solo deja ver lo que esta activo obliga a recrear el premio desde cero
     * si alguien lo desactivo por error, con el historial de unidades que ya
     * apuntan a el.
     *
     * @param int  $promocionId Campana cuyo catalogo se quiere.
     * @param bool $soloActivos Si es true, se excluyen los desactivados. Lo usa
     *                          el generador del calendario, que solo reparte
     *                          premios que se pueden entregar.
     *
     * @return array<int, array<string, mixed>> Tipos de premio con las claves
     *                                    «id», «nombre», «descripcion»,
     *                                    «imagen_ruta», «activo», «unidades» y
     *                                    «entregadas».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarPorPromocion(int $promocionId, bool $soloActivos = false): array
    {
        // La condicion de soloActivos se decide en PHP y se anade al SQL como un
        // parametro, en vez de escribir «AND activo = 1» o «AND activo = ?» con
        // dos textos distintos. Un unico parametro con valor 1 o 0 aprovecha el
        // indice ix_tipos_premio_activos en los dos casos, y el motor no tiene
        // que volver a analizar la sentencia.
        $sql = 'SELECT tp.id,
                       tp.nombre,
                       tp.descripcion,
                       tp.imagen_ruta,
                       tp.activo,
                       (SELECT COUNT(*) FROM unidades_premio u
                         WHERE u.tipo_premio_id = tp.id) AS unidades,
                       (SELECT COUNT(*) FROM unidades_premio u
                         WHERE u.tipo_premio_id = tp.id AND u.estado = ?)
                           AS entregadas
                  FROM tipos_premio tp
                 WHERE tp.promocion_id = ?
                   AND tp.activo >= ?
                 ORDER BY tp.nombre ASC';

        return $this->db->todos(
            $sql,
            [UnidadPremio::ESTADO_ENTREGADA, $promocionId, $soloActivos ? 1 : 0]
        );
    }

    /**
     * Devuelve un tipo de premio o lanza el error de pagina inexistente.
     *
     * @param int    $id          Identificador del tipo de premio.
     * @param string $ruta        Ruta que se cita en el mensaje de error.
     * @param int    $promocionId Campana a la que tiene que pertenecer.
     *
     * @return array<string, mixed> Fila completa del tipo de premio.
     *
     * @throws \App\Core\NoEncontrado Si no existe o no es de esa campana.
     */
    public function exigirPorId(int $id, string $ruta, int $promocionId): array
    {
        $tipo = $this->buscarPorId($id);

        if ($tipo === null || (int) $tipo['promocion_id'] !== $promocionId) {
            throw new NoEncontrado($ruta);
        }

        return $tipo;
    }

    /**
     * Guarda un tipo de premio, creandolo si no existe.
     *
     * @param array<string, mixed> $datos       Nombre, descripcion e imagen ya
     *                                          validados. Las claves son
     *                                          obligatorias y son exactamente
     *                                          estas: «nombre», «descripcion»,
     *                                          «imagen_ruta» y «activo». Las tres
     *                                          primeras se pasan con la cadena
     *                                          vacia si no tienen valor, y
     *                                          «activo» como true o false.
     * @param int                  $promocionId Campana a la que pertenece.
     * @param int|null             $id          Identificador si se edita, o null
     *                                          si es un premio nuevo.
     *
     * @return int Identificador del tipo de premio guardado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardar(array $datos, int $promocionId, ?int $id = null): int
    {
        $ahora = Aplicacion::ahora();

        // El nombre se guarda con la primera letra en mayuscula y el resto en
        // minusculas, para que el panel no muestre «MICROONDAS» un dia y
        // «microondas» otro segun como se le haya escrito. Se hace aqui y no en
        // la pantalla porque el nombre tambien se escribe desde la generacion
        // del calendario y desde las pruebas, y normalizar en un solo sitio es la
        // unica manera de que la comparacion de nombres del indice unico siga
        // siendo fiable.
        $nombre = mb_convert_case(trim((string) $datos['nombre']), MB_CASE_TITLE, 'UTF-8');

        if ($id === null) {
            return $this->db->insertar(
                'INSERT INTO tipos_premio (
                     promocion_id, nombre, descripcion, imagen_ruta, activo, creado_en, actualizado_en
                 ) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $promocionId,
                    $nombre,
                    $datos['descripcion'] !== '' ? $datos['descripcion'] : null,
                    $datos['imagen_ruta'],
                    $datos['activo'] ? 1 : 0,
                    $ahora,
                    $ahora,
                ]
            );
        }

        // Al editar se respeta el interruptor de activo que llega en los datos, y
        // no se deduce de si el tipo tiene unidades: desactivar es una decision
        // del administrador, no un efecto secundario de que haya premios sueltos.
        $this->db->ejecutar(
            'UPDATE tipos_premio
                SET nombre = ?,
                    descripcion = ?,
                    imagen_ruta = ?,
                    activo = ?,
                    actualizado_en = ?
              WHERE id = ?',
            [
                $nombre,
                $datos['descripcion'] !== '' ? $datos['descripcion'] : null,
                $datos['imagen_ruta'],
                $datos['activo'] ? 1 : 0,
                $ahora,
                $id,
            ]
        );

        return $id;
    }

    /**
     * Activa o desactiva un tipo de premio.
     *
     * @param int  $id     Identificador del tipo de premio.
     * @param bool $activo True para activar, false para desactivar.
     *
     * @return int Numero de filas afectadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function cambiarActivo(int $id, bool $activo): int
    {
        return $this->db->ejecutar(
            'UPDATE tipos_premio SET activo = ?, actualizado_en = ? WHERE id = ?',
            [$activo ? 1 : 0, Aplicacion::ahora(), $id]
        );
    }

    /**
     * Comprueba si un tipo de premio tiene unidades colgadas.
     *
     * @param int $tipoPremioId Tipo de premio que se comprueba.
     *
     * @return int Numero de unidades de ese tipo en la cola.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarUnidades(int $tipoPremioId): int
    {
        return (int) $this->db->valor(
            'SELECT COUNT(*) FROM unidades_premio WHERE tipo_premio_id = ?',
            [$tipoPremioId]
        );
    }
}
