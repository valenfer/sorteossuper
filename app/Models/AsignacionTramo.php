<?php

/**
 * Modelo del plan de cantidades por tramo y tipo de premio.
 *
 * ============================================================================
 * QUE ES ESTA TABLA: EL PLAN, NO LA REALIDAD
 * ============================================================================
 *
 * El comentario del esquema lo dice con todas las letras —«esta tabla guarda
 * SIEMPRE el plan que se introdujo, y el calendario (unidades_premio) es la
 * realidad»— y esa distincion es la pieza central del hito 3.
 *
 * El plan es lo que el administrador ha decidido: en el tramo de la tarde hay dos
 * microondas. La realidad es lo que hay en la cola: en el tramo de la tarde hay
 * tres unidades de microondas, porque alguien ha movido una desde el tramo de la
 * manana. El apartado 4.6 pide comparar las dos cosas, mostrar las diferencias
 * exactas y pedir confirmacion antes de guardar.
 *
 * ============================================================================
 * UNA FILA POR TRAMO Y TIPO, Y POR QUE NO HAY HISTORICO DE PLANES
 * ============================================================================
 *
 * El indice unico uq_asignaciones_tramo_tipo es (tramo_id, tipo_premio_id), asi
 * que esta tabla tiene como mucho una fila por cada combinacion de tramo y tipo.
 * El comentario del esquema sugiere, en un passage, que «sincronizar» podria
 * consistir en anadir un plan nuevo sin borrar el anterior; con ese indice
 * unico eso es imposible, y no es un descuido sino la garantia de que el plan que
 * se muestra es siempre el ultimo guardado.
 *
 * La resolucion es la que menos contradicciones tiene, y además encaja con lo que
 * pide el apartado 4.6: las diferencias se calculan al vuelo, comparando el plan
 * con el calendario, y «sincronizar» es escribir en el plan lo que hay en el
 * calendario. Despues de sincronizar, las dos cosas coinciden y no hay nada que
 * mostrar. Un historico de planes solo tendria sentido para poder volver atras, y
 * el apartado 9 no lo pide: pide que las ediciones preserven las adjudicaciones y
 * queden auditadas, y las adjudicaciones viven en unidades_premio, no aqui.
 *
 * ============================================================================
 * POR QUE CANTIDAD NO PUEDE SER CERO
 * ============================================================================
 *
 * El CHECK ck_asignaciones_cantidad exige cantidad > 0. Una asignacion de cero
 * unidades no significa nada y solo genera ruido al comparar el plan con el
 * calendario: apareceria una fila con un cero que el panel tendria que esconder y
 * el generador tendria que ignorar. Por eso sustituirPorTramo() no inserta las
 * cantidades que valen cero, sino que directamente no las guarda.
 *
 * @see \App\Services\Calendario::compararConPlan()
 * @see \App\Models\UnidadPremio
 * @see apartado 4.4 de la especificacion, asignacion de cantidades
 * @see apartado 4.6 de la especificacion, revision del calendario
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;
use App\Models\UnidadPremio;

/**
 * Acceso a la tabla de asignaciones por tramo.
 */
class AsignacionTramo extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'asignaciones_tramo';

    /**
     * Devuelve el plan completo de una campana, con sus tramos y sus premios.
     *
     * @param int $promocionId Campana cuyo plan se quiere.
     *
     * @return array<int, array<string, mixed>> Filas con las claves «tramo_id»,
     *                                    «tipo_premio_id», «cantidad»,
     *                                    «nombre_tramo», «fecha», «hora_inicio»,
     *                                    «hora_fin» y «nombre_premio».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarPorPromocion(int $promocionId): array
    {
        // El recorrido va por tramo, fecha y hora porque es el orden en el que el
        // panel enseña la matriz de cantidades: primero los tramos en orden
        // cronologico y, dentro de cada uno, los premios. Con el indice
        // ix_tramos_busqueda el motor no tiene que ordenar nada.
        return $this->db->todos(
            'SELECT a.tramo_id,
                    a.tipo_premio_id,
                    a.cantidad,
                    t.fecha,
                    t.hora_inicio,
                    t.hora_fin,
                    tp.nombre AS nombre_premio
               FROM asignaciones_tramo a
               JOIN tramos t ON t.id = a.tramo_id
               JOIN tipos_premio tp ON tp.id = a.tipo_premio_id
              WHERE t.promocion_id = ?
              ORDER BY t.fecha ASC, t.hora_inicio ASC, tp.nombre ASC',
            [$promocionId]
        );
    }

    /**
     * Devuelve las cantidades de un tramo, indexadas por tipo de premio.
     *
     * La forma del valor de retorno es un mapa y no una lista porque quien lo
     * consume —la pantalla de cantidades y el generador— siempre busca «la
     * cantidad de este tipo en este tramo», y con un mapa esa busqueda es un
     * acceso directo en vez de un recorrido.
     *
     * @param int $tramoId Tramo cuyo plan se quiere.
     *
     * @return array<int, int> Tipo de premio como clave y cantidad como valor.
     *                        Los tipos sin cantidad asignada no aparecen.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function cantidadesPorTramo(int $tramoId): array
    {
        $filas = $this->db->todos(
            'SELECT tipo_premio_id, cantidad FROM asignaciones_tramo WHERE tramo_id = ?',
            [$tramoId]
        );

        $cantidades = [];

        foreach ($filas as $fila) {
            $cantidades[(int) $fila['tipo_premio_id']] = (int) $fila['cantidad'];
        }

        return $cantidades;
    }

    /**
     * Sustituye por completo el plan de un tramo.
     *
     * ============================================================================
     * POR QUE SE BORRA Y SE VUELVE A INSERTAR
     * ============================================================================
     *
     * La operacion es «este tramo tiene estas cantidades», no «anade esta
     * cantidad». Guardarla como altas y bajas sueltas obligaria a hacer tres
     * consultas por tipo de premio y a calcular en PHP que filas sobran, con el
     * riesgo de que un error a medias deje el plan a medio camino. Ademas el
     * CHECK de cantidad > 0 impide borrarponiendo a cero.
     *
     * El borrado y los INSERT van dentro de la transaccion que abre el
     * controlador, de modo que un fallo a mitad no deja el tramo sin plan: la
     * operacion es atomica porque la envuelve quien la pide, no porque aqui se
     * abra una transaccion. Un modelo que abre su propia transaccion puede
     * dejar las cosas a medias si el llamante espera hacer mas cosas dentro de
     * ella.
     *
     * @param int            $tramoId    Tramo cuyo plan se sustituye.
     * @param array<int, int> $cantidades Mapa de tipo de premio a cantidad. Las
     *                                    cantidades de cero o menores se
     *                                    ignoran, porque no se pueden guardar.
     *
     * @return int Numero de filas insertadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function sustituirPorTramo(int $tramoId, array $cantidades): int
    {
        $this->db->ejecutar('DELETE FROM asignaciones_tramo WHERE tramo_id = ?', [$tramoId]);

        $ahora = Aplicacion::ahora();
        $insertadas = 0;

        foreach ($cantidades as $tipoPremioId => $cantidad) {
            $cantidad = (int) $cantidad;

            if ($cantidad < 1) {
                continue;
            }

            $this->db->ejecutar(
                'INSERT INTO asignaciones_tramo (tramo_id, tipo_premio_id, cantidad, creado_en)
                 VALUES (?, ?, ?, ?)',
                [$tramoId, (int) $tipoPremioId, $cantidad, $ahora]
            );

            $insertadas++;
        }

        return $insertadas;
    }

    /**
     * Guarda una cantidad de un solo tipo de premio en un tramo.
     *
     * Es la operacion atomica que usa la sincronizacion del calendario: una
     * pareja (tramo, tipo) es la unidad logica del plan, porque es la celda de la
     * matriz que ve el administrador.
     *
     * @param int $tramoId       Tramo al que se asigna.
     * @param int $tipoPremioId  Tipo de premio que se asigna.
     * @param int $cantidad      Cantidad aprobada. Si es cero o menor, la
     *                           asignacion se borra en lugar de guardarse.
     *
     * @return int Numero de filas afectadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardarCantidad(int $tramoId, int $tipoPremioId, int $cantidad): int
    {
        if ($cantidad < 1) {
            return $this->db->ejecutar(
                'DELETE FROM asignaciones_tramo WHERE tramo_id = ? AND tipo_premio_id = ?',
                [$tramoId, $tipoPremioId]
            );
        }

        // INSERT ... ON DUPLICATE KEY UPDATE es la forma de que guardar dos veces
        // la misma celda no sea un error de clave duplicada. Se usa el indice
        // unico (tramo_id, tipo_premio_id) para decidir cual de las dos filas es
        // la misma, en vez de un SELECT previo que en dos peticiones
        // simultaneas dejaria pasar a las dos.
        $this->db->ejecutar(
            'INSERT INTO asignaciones_tramo (tramo_id, tipo_premio_id, cantidad, creado_en)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cantidad = VALUES(cantidad)',
            [$tramoId, $tipoPremioId, $cantidad, Aplicacion::ahora()]
        );

        return 1;
    }

    /**
     * Devuelve los totales del plan por tipo de premio.
     *
     * El apartado 4.4 pide «mostrar el total por tramo, por tipo y por
     * promocion», y este metodo es el «por tipo». El total por tramo esta en
     * \App\Models\Tramo::listarPorPromocion() y el total por promocion es la suma
     * de todos los tramos, que se calcula en el servicio de configuracion para
     * no tener que traer todas las filas para sumarlas en PHP.
     *
     * @param int $promocionId Campana cuyos totales se quieren.
     *
     * @return array<int, array<string, mixed>> Una fila por tipo de premio, con
     *                                    las claves «tipo_premio_id», «nombre» y
     *                                    «total», ordenada de mayor a menor.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function totalesPorTipo(int $promocionId): array
    {
        return $this->db->todos(
            'SELECT a.tipo_premio_id,
                    tp.nombre,
                    SUM(a.cantidad) AS total
               FROM asignaciones_tramo a
               JOIN tramos t ON t.id = a.tramo_id
               JOIN tipos_premio tp ON tp.id = a.tipo_premio_id
              WHERE t.promocion_id = ?
              GROUP BY a.tipo_premio_id, tp.nombre
              ORDER BY total DESC, tp.nombre ASC',
            [$promocionId]
        );
    }

    /**
     * Devuelve las diferencias entre el plan y el calendario.
     *
     * Esta es la consulta que respalda el aviso del apartado 4.6. Se hace
     * comparando el plan con el recuento real de unidades agrupado por tramo y
     * tipo, y devolviendo las cuatro categorias: lo que falta, lo que sobra, lo
     * que ya coincide y las combinaciones que solo existen en un lado. Las que
     * coinciden no salen en la pantalla, pero se consultan para poder decir «de 24
     * unidades, 19 estan donde deben».
     *
     * Se cuentan solo las unidades que no estan anuladas. Una unidad retirada
     * deja de formar parte del calendario definitivo, y si se contara como
     * presente el aviso no se activaria nunca despues de retirar un premio, que
     * es justo cuando el administrador necesita que se le pregunte si quiere
     * corregir el plan.
     *
     * El plan y el calendario se agregan por separado y luego se cruzan, en vez
     * de juntarlos con UNION ALL y sumar: juntarlos perderia la informacion de
     * cual de los dos lados venia cada fila, que es justo lo que hay que
     * mostrar. MySQL no tiene FULL OUTER JOIN, asi que las parejas de claves se
     * sacan de la union de los dos lados y de ahi se cuelgan los dos agregados
     * con LEFT JOIN, que si la tiene. La union es de claves, no de filas de
     * negocio, y por eso no duplica nada.
     *
     * @param int $promocionId Campana que se quiere comparar.
     *
     * @return array<int, array<string, mixed>> Una fila por combinacion de tramo
     *                                    y tipo que exista en cualquiera de los
     *                                    dos lados, con las claves «tramo_id»,
     *                                    «tipo_premio_id», «cantidad_plan»,
     *                                    «cantidad_calendario» y «diferencia»,
     *                                    ordenadas por tramo y tipo.
     *
     *                                    «diferencia» va con el signo
     *                                    calendario menos plan: si vale -3 es
     *                                    que faltan tres unidades. Se elige
     *                                    ese signo, y no plan menos calendario,
     *                                    porque lo que pregunta el panel es
     *                                    cuanto queda por crear, y con este
     *                                    orden la respuesta sale con el signo
     *                                    ya puesto. Una diferencia distinta de
     *                                    cero significa que el calendario ya no
     *                                    es el que se planeo, que es lo que la
     *                                    pantalla tiene que destacar.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function compararConCalendario(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT k.tramo_id,
                    k.tipo_premio_id,
                    COALESCE(p.cantidad_plan, 0) AS cantidad_plan,
                    COALESCE(c.cantidad_calendario, 0) AS cantidad_calendario
               FROM (
                     SELECT a.tramo_id, a.tipo_premio_id
                       FROM asignaciones_tramo a
                       JOIN tramos t ON t.id = a.tramo_id
                      WHERE t.promocion_id = ?
                     UNION
                     SELECT u.tramo_id, u.tipo_premio_id
                       FROM unidades_premio u
                      WHERE u.promocion_id = ?
                 ) AS k
               LEFT JOIN (
                     SELECT a.tramo_id, a.tipo_premio_id, SUM(a.cantidad) AS cantidad_plan
                       FROM asignaciones_tramo a
                       JOIN tramos t ON t.id = a.tramo_id
                      WHERE t.promocion_id = ?
                      GROUP BY a.tramo_id, a.tipo_premio_id
                 ) AS p ON p.tramo_id = k.tramo_id
                       AND p.tipo_premio_id = k.tipo_premio_id
               LEFT JOIN (
                     SELECT u.tramo_id, u.tipo_premio_id, COUNT(*) AS cantidad_calendario
                       FROM unidades_premio u
                      WHERE u.promocion_id = ?
                        AND u.estado <> ?
                      GROUP BY u.tramo_id, u.tipo_premio_id
                 ) AS c ON c.tramo_id = k.tramo_id
                       AND c.tipo_premio_id = k.tipo_premio_id
              ORDER BY k.tramo_id ASC, k.tipo_premio_id ASC',
            [
                $promocionId,
                $promocionId,
                $promocionId,
                $promocionId,
                UnidadPremio::ESTADO_ANULADA,
            ]
        );

        $comparacion = [];

        foreach ($filas as $fila) {
            $comparacion[] = [
                'tramo_id'            => (int) $fila['tramo_id'],
                'tipo_premio_id'      => (int) $fila['tipo_premio_id'],
                'cantidad_plan'       => (int) $fila['cantidad_plan'],
                'cantidad_calendario' => (int) $fila['cantidad_calendario'],
                'diferencia'          => (int) $fila['cantidad_calendario'] - (int) $fila['cantidad_plan'],
            ];
        }

        return $comparacion;
    }

    /**
     * Devuelve el total de unidades previstas en el plan de una campana.
     *
     * @param int $promocionId Campana cuyo total se quiere.
     *
     * @return int Suma de todas las cantidades del plan.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function totalPlan(int $promocionId): int
    {
        return (int) $this->db->valor(
            'SELECT COALESCE(SUM(a.cantidad), 0)
               FROM asignaciones_tramo a
               JOIN tramos t ON t.id = a.tramo_id
              WHERE t.promocion_id = ?',
            [$promocionId]
        );
    }
}
