<?php

/**
 * Modelo de los tramos de participacion.
 *
 * ============================================================================
 * QUE ES UN TRAMO
 * ============================================================================
 *
 * Un tramo es un periodo de participacion: una fecha y un intervalo de horas, con
 * hora de fin posterior a la de inicio. El apartado 4.2 de la especificacion pide
 * poder poner varios el mismo dia, y el esquema lo permite sin ninguna
 * complicacion adicional, porque la clave unica es (promocion_id, fecha,
 * hora_inicio): dos tramos del mismo dia que empiecen a la misma hora no pueden
 * existir, y dos que empiecen a distinta hora si.
 *
 * ============================================================================
 * LO QUE EL ESQUEMA NO PUEDE COMPROBAR Y ESTE MODELO DEJA PREPARADO
 * ============================================================================
 *
 * La tabla solo lleva una restriccion CHECK, la de que la hora de fin sea
 * posterior a la de inicio. Todo lo demas necesita consultar otras filas, y por
 * eso el comentario del esquema remite a \App\Services\Tramos:
 *
 *   - Que dos tramos de la misma campana no se solapen. Un solape no lo detecta
 *     ningun indice, porque dos intervalos que se cruzan no tienen nada igual.
 *   - Que el tramo no cruce el cambio de hora de verano, que es una regla de
 *     calendario, no de datos.
 *
 * Aqui solo estan las consultas que hacen falta para comprobarlo: el metodo que
 * devuelve los tramos con los que se cruzaria uno nuevo. La regla en si, y el
 * mensaje que ve el administrador, viven en el servicio.
 *
 * ============================================================================
 * POR QUE UN TRAMO CON UNIDADES NO SE BORRA
 * ============================================================================
 *
 * El esquema declara fk_unidades_tramo con ON DELETE CASCADE, asi que un DELETE
 * sin mas borraria en cascada todas las unidades del tramo. Que el motor lo
 * permita no significa que sea una buena idea: un tramo es el periodo dentro del
 * cual se puede haber entregado un premio, y borrarlo se llevaria por delante el
 * historial de unidades y de participaciones que lo apuntan. Por eso borrar()
 * comprueba antes y devuelve un recuento de cero filas, y el panel lo cuenta como
 * «no se puede borrar». Un premio anulado se anula, que es lo que existe el estado
 * anulada para eso.
 *
 * @see \App\Services\Tramos
 * @see \App\Models\UnidadPremio
 * @see apartado 4.2 de la especificacion, dias y jornadas
 * @see decision D9 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;
use App\Core\NoEncontrado;

/**
 * Acceso a la tabla de tramos.
 */
class Tramo extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'tramos';

    /**
     * Devuelve los tramos de una campana con sus unidades y su plan.
     *
     * El orden es por fecha y hora de inicio, que es el orden en que se
     * muestran y en el que el generador los recorre. Los dos recuentos que se
     * traen son los que la pantalla necesita para cada fila: cuantas unidades
     * tiene el tramo ahora mismo, y cuantas tendria segun el plan de cantidades.
     *
     * Los dos recuentos vienen en la misma consulta y no en dos consultas por
     * tramo, porque el panel lista los tramos de una campana entera y con veinte
     * tramos eso serian cuarenta idas y venidas a la base de datos para pintar una
     * tabla.
     *
     * @param int $promocionId Campana cuyos tramos se quieren.
     *
     * @return array<int, array<string, mixed>> Tramos en orden cronologico.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarPorPromocion(int $promocionId): array
    {
        return $this->db->todos(
            'SELECT t.id,
                    t.fecha,
                    t.hora_inicio,
                    t.hora_fin,
                    (SELECT COUNT(*) FROM unidades_premio u
                      WHERE u.tramo_id = t.id) AS unidades,
                    (SELECT COUNT(*) FROM unidades_premio u
                      WHERE u.tramo_id = t.id AND u.estado = ?)
                        AS unidades_entregadas,
                    (SELECT COALESCE(SUM(a.cantidad), 0) FROM asignaciones_tramo a
                      WHERE a.tramo_id = t.id) AS plan
               FROM tramos t
              WHERE t.promocion_id = ?
              ORDER BY t.fecha ASC, t.hora_inicio ASC',
            [UnidadPremio::ESTADO_ENTREGADA, $promocionId]
        );
    }

    /**
     * Devuelve el tramo en el que esta la campana en un instante dado.
     *
     * ============================================================================
     * POR QUE NO SE GUARDA UN «TRAMO ACTUAL» EN LA BASE DE DATOS
     * ============================================================================
     *
     * Se podria resolver con una columna que dijera en que tramo va la campana, y
     * habria que actualizarla cada vez que avanzase el reloj. No se hace. Un tramo
     * guardado tendria que reescribirse en cada participacion para llevar la
     * cuenta de un dato que se deduce del reloj, y ademas podria quedarse
     * desactualizado: si alguien cambia el calendario o se para el reloj de la
     * tienda, la columna diria un tramo y la campana estaria en otro. Consultarlo
     * es mas barato que mantenerlo, y no puede mentir.
     *
     * La comparacion se hace en SQL y no con filtros de PHP por una razon concreta:
     * los tramos de una campana son pocos, pero la fecha y la hora son cadenas, y
     * comparar «A-n-j» con «A-n-j H:i:s» en PHP exigiria un monton de casos
     * especiales que en SQL son una comparacion de columnas. Se apoya en el indice
     * ix_tramos_busqueda, de modo que el coste es el mismo que el de leer un tramo.
     *
     * El criterio es el mismo que usa el motor para admitir una participacion: la
     * hora de inicio entra, la de fin no. Con «hora_fin >» un tramo termina a las
     * 14:00 y a las 14:00 ya no esta dentro, que es lo que espera cualquiera que
     * mire el panel.
     *
     * @param int    $promocionId Campana que se quiere consultar.
     * @param string $momento     Instante de referencia, en «A-n-j H:i:s».
     *
     * @return array<string, mixed>|null El tramo en curso, o null si en ese
     *                                   instante la campana esta fuera de horario.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function actualEn(int $promocionId, string $momento): ?array
    {
        return $this->db->uno(
            'SELECT id, fecha, hora_inicio, hora_fin
               FROM tramos
              WHERE promocion_id = ?
                AND fecha = DATE(?)
                AND hora_inicio <= TIME(?)
                AND hora_fin > TIME(?)
              ORDER BY hora_inicio ASC
              LIMIT 1',
            [$promocionId, $momento, $momento, $momento]
        );
    }

    /**
     * Devuelve un tramo en una linea que se pueda leer sin mirar las columnas.
     *
     * Los tramos no tienen columna «nombre»: se distinguen por el dia y la hora,
     * asi que la etiqueta sale de ahi. Se hace aqui y no en las vistas porque
     * el mismo texto sale en el listado, en la comparacion con el calendario y
     * en el desplegable de anadir tramo, y si cada uno compusiera la cadena por
     * su cuenta acabarian tres formatos distintos para el mismo tramo.
     *
     * @param array<string, mixed> $tramo Fila de un tramo, con «fecha»,
     *                                 «hora_inicio» y «hora_fin».
     *
     * @return string Etiqueta legible, por ejemplo «Sabado 13 de junio, 18:00
     *               a 20:00».
     */
    public static function etiqueta(array $tramo): string
    {
        $marca = strtotime((string) $tramo['fecha'] . ' ' . (string) $tramo['hora_inicio']);

        if ($marca === false) {
            return sprintf('%s, de %s a %s', (string) $tramo['fecha'], (string) $tramo['hora_inicio'], (string) $tramo['hora_fin']);
        }

        $dias = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes',
            'Wednesday' => 'Miercoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes',
            'Saturday' => 'Sabado'];
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $dia = $dias[date('l', $marca)] ?? '';
        $mes = $meses[(int) date('n', $marca)] ?? '';

        return sprintf(
            '%s %d de %s, de %s a %s',
            $dia,
            (int) date('j', $marca),
            $mes,
            (string) $tramo['hora_inicio'],
            (string) $tramo['hora_fin']
        );
    }

    /**
     * Devuelve las fechas en las que la campana tiene tramos.
     *
     * El panel las usa para el desplegable de «anadir tramo», que deberia ofrecer
     * las fechas ya usadas y permitir escribir una nueva, en vez de obligar a
     * teclear la fecha entera cada vez que se anade un segundo tramo el mismo dia.
     *
     * @param int $promocionId Campana cuyas fechas se quieren.
     *
     * @return array<int, string> Fechas en formato «A-n-j», ordenadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function fechasDe(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT DISTINCT fecha FROM tramos WHERE promocion_id = ? ORDER BY fecha ASC',
            [$promocionId]
        );

        $fechas = [];

        foreach ($filas as $fila) {
            $fechas[] = (string) $fila['fecha'];
        }

        return $fechas;
    }

    /**
     * Devuelve un tramo o lanza el error de pagina inexistente.
     *
     * @param int    $id      Identificador del tramo.
     * @param string $ruta    Ruta que se cita en el mensaje de error.
     * @param int    $promocionId Campana a la que tiene que pertenecer el tramo,
     *                            para que un tramo de otra campana no se pueda
     *                            editar desde la URL de esta.
     *
     * @return array<string, mixed> Fila completa del tramo.
     *
     * @throws \App\Core\NoEncontrado Si el tramo no existe o no es de esa campana.
     */
    public function exigirPorId(int $id, string $ruta, int $promocionId): array
    {
        $tramo = $this->buscarPorId($id);

        // La segunda comprobacion no es un adorno. Sin ella, quien conozca el
        // identificador de un tramo de otra campana podria editarlo escribiendo
        // la URL con el identificador de la campana propia: las rutas llevan los
        // dos identificadores porque las dos cosas tienen que cuadrar a la vez.
        if ($tramo === null || (int) $tramo['promocion_id'] !== $promocionId) {
            throw new NoEncontrado($ruta);
        }

        return $tramo;
    }

    /**
     * Guarda un tramo, creandolo si no existe.
     *
     * @param array<string, mixed> $datos       Fecha y horas ya validadas, en
     *                                          formato «A-n-j» y «H:i:s».
     * @param int                  $promocionId Campana a la que pertenece.
     * @param int|null             $id          Identificador si se edita, o null
     *                                          si es un tramo nuevo.
     *
     * @return int Identificador del tramo guardado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardar(array $datos, int $promocionId, ?int $id = null): int
    {
        $ahora = Aplicacion::ahora();

        if ($id === null) {
            return $this->db->insertar(
                'INSERT INTO tramos (promocion_id, fecha, hora_inicio, hora_fin, creado_en)
                 VALUES (?, ?, ?, ?, ?)',
                [$promocionId, $datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $ahora]
            );
        }

        $this->db->ejecutar(
            'UPDATE tramos SET fecha = ?, hora_inicio = ?, hora_fin = ? WHERE id = ?',
            [$datos['fecha'], $datos['hora_inicio'], $datos['hora_fin'], $id]
        );

        return $id;
    }

    /**
     * Devuelve los tramos de una campana con los que se cruzaria el indicado.
     *
     * El solape se comprueba en SQL y no comparando fechas en PHP porque hay que
     * hacerlo para todas las combinaciones, y porque el criterio exacto —dos
     * intervalos que se tocan en un extremo NO se solapan— es mas seguro de
     * escribir en la consulta que de implementar a mano. Un tramo de 10:00 a 14:00
     * y otro de 14:00 a 18:00 pueden convivir: la participacion que llega a las
     * 14:00 pertenece al primero, no a los dos.
     *
     * @param int    $promocionId Campana a la que se compara.
     * @param string $fecha       Fecha del tramo que se comprueba.
     * @param string $horaInicio  Hora de inicio, en formato «H:i:s».
     * @param string $horaFin     Hora de fin, en formato «H:i:s».
     * @param int|null $excluirId Identificador del tramo que se esta editando, si
     *                            lo hay, para no compararlo consigo mismo.
     *
     * @return array<int, array<string, mixed>> Tramos que se solapan con el dado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function solapadosCon(
        int $promocionId,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int $excluirId = null
    ): array {
        // La condicion es «el otro empieza antes de que este termine Y el otro
        // termina despues de que este empiece». Con dos intervalos ordenados, eso
        // es exactamente la interseccion, y deja fuera tanto los que quedan
        // enteros a la izquierda como los que quedan enteros a la derecha.
        $sql = 'SELECT id, fecha, hora_inicio, hora_fin
                  FROM tramos
                 WHERE promocion_id = ?
                   AND fecha = ?
                   AND hora_inicio < ?
                   AND hora_fin > ?';

        $parametros = [$promocionId, $fecha, $horaFin, $horaInicio];

        if ($excluirId !== null) {
            $sql .= ' AND id <> ?';
            $parametros[] = $excluirId;
        }

        $sql .= ' ORDER BY hora_inicio ASC';

        return $this->db->todos($sql, $parametros);
    }

    /**
     * Comprueba si un tramo tiene unidades de premio, y de que clase.
     *
     * @param int $tramoId Tramo que se comprueba.
     *
     * @return array<string, int> Recuento con las claves «total» y «entregadas».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarUnidades(int $tramoId): array
    {
        $fila = $this->db->uno(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(estado = ?), 0) AS entregadas
               FROM unidades_premio
              WHERE tramo_id = ?',
            [UnidadPremio::ESTADO_ENTREGADA, $tramoId]
        );

        return [
            'total'      => (int) ($fila['total'] ?? 0),
            'entregadas' => (int) ($fila['entregadas'] ?? 0),
        ];
    }

    /**
     * Borra un tramo solo si no tiene unidades colgadas.
     *
     * El borrado se hace con la condicion de que no exista ninguna unidad, en la
     * propia sentencia y no en una comprobacion previa. Asi el recuento de filas
     * afectadas dice la verdad: si entre la comprobacion y el borrado otra
     * peticion crea una unidad en ese tramo —que es justo lo que pasaria con dos
     * administradores abiertos a la vez— el DELETE no borra nada en lugar de
     * llevarsela por delante en cascada.
     *
     * @param int $tramoId Tramo que se quiere borrar.
     *
     * @return int 1 si se ha borrado, 0 si tenia unidades.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function borrarSiEstaLibre(int $tramoId): int
    {
        return $this->db->ejecutar(
            'DELETE t FROM tramos t
              WHERE t.id = ?
                AND NOT EXISTS (SELECT 1 FROM unidades_premio u WHERE u.tramo_id = t.id)
                AND NOT EXISTS (SELECT 1 FROM participaciones p WHERE p.tramo_id = t.id)',
            [$tramoId]
        );
    }

    /**
     * Devuelve el numero de tramos de una campana.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return int Numero de tramos.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contar(int $promocionId): int
    {
        return (int) $this->db->valor(
            'SELECT COUNT(*) FROM tramos WHERE promocion_id = ?',
            [$promocionId]
        );
    }
}
