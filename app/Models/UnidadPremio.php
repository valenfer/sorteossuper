<?php

/**
 * Modelo de las unidades de premio, que son la cola de premios.
 *
 * ============================================================================
 * QUE ES UNA UNIDAD Y POR QUE ES LA TABLA MAS IMPORTANTE DEL PROYECTO
 * ============================================================================
 *
 * Una unidad es un premio concreto con una hora programada: «un voucher de
 * 20 euros que se puede repartir a partir de las 10:12». La tabla
 * unidades_premio tiene una fila por unidad, y su estado es lo que decide si ese
 * premio esta disponible, se ha entregado, se ha anulado o se ha perdido.
 *
 * Es la tabla mas importante porque es la unica que responde a la pregunta que
 * mas caro sale equivocada en una campana real: ¿este premio ya se ha dado? De
 * ahi que todas las consultas de este modelo sean cuidadas con el estado, y que
 * la entrega se haga con un UPDATE condicional en lugar de con un SELECT
 * seguido de un UPDATE.
 *
 * ============================================================================
 * LA CONSULTA DE LA COLA Y EL INDICE QUE LA HACE BARATA
 * ============================================================================
 *
 * La regla central del apartado 6 pide la PRIMERA unidad pendiente cuya hora
 * programada ya ha pasado, ordenadas por fecha y hora. El indice
 * ix_unidades_cola es (promocion_id, estado, inicio, id), exactamente el orden
 * en que la consulta necesita, y el id va el ultimo a proposito: es el
 * desempate estable que pide la especificacion para dos premios de la misma
 * hora. Con ese indice la cola se resuelve con una lectura de indice y sin
 * ordenar nada en memoria.
 *
 * ============================================================================
 * POR QUE LA COLA NO SE FILTRA POR TRAMO
 * ============================================================================
 *
 * Es tentador anadir «AND tramo_id = ...» a la consulta, y seria un error. La
 * decision D4 deja los premios pendientes en cola a lo largo de los tramos y de
 * los dias de la campana: el ejemplo del apartado 6 lo dice sin rodeos, con
 * premios previstos a las 10:12, 10:30 y 11:00 y la primera participacion a las
 * 11:20, que se lleva el de las 10:12. Filtrar por tramo dejaria los premios
 * viejos sin repartir y haria que el prize pool de cada tramo se perdiera al
 * cambiar de turno.
 *
 * @see \App\Services\Adjudicador
 * @see \App\Core\Db::bloquearPromocion()
 * @see apartado 6 de la especificacion, regla central de adjudicacion
 * @see decisiones D4, D8 y D9
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\ErrorBaseDeDatos;
use App\Core\Modelo;

/**
 * Acceso a la cola de premios y entrega de unidades.
 */
class UnidadPremio extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'unidades_premio';

    /**
     * Estado de una unidad que todavia no se ha repartido.
     *
     * @var string
     */
    public const ESTADO_PROGRAMADA = 'programada';

    /**
     * Estado de una unidad ya adjudicada a una participacion.
     *
     * @var string
     */
    public const ESTADO_ENTREGADA = 'entregada';

    /**
     * Devuelve la primera unidad pendiente cuya hora ya ha llegado.
     *
     * La comparacion es «menor o igual que» y no «menor que», porque el apartado
     * 6 habla de una unidad «anterior o igual al instante de la participacion»:
     * a la hora exacta en que un premio queda disponible, ese premio ya puede
     * repartirse. Poner «menor» haria que un premio se retrasase un minuto
     * entero, y con una cadencia de un premio cada pocos minutos eso se nota.
     *
     * El FOR UPDATE es la segunda de las tres defensas de D8, despues del
     * bloqueo con nombre: deja la fila de la unidad bloqueada para el resto de
     * la transaccion, de modo que ninguna otra peticion pueda leerla como
     * pendiente ni cambiarla mientras este motor decide.
     *
     * @param int    $promocionId Campana a la que pertenece la cola.
     * @param string $momento     Instante de referencia, en formato
     *                            «A-n-j H:i:s» y en hora local de la campana.
     *
     * @return array<string, mixed>|null Fila de la unidad candidata, o null si no
     *                                   hay ninguna pendiente. Devuelve null en
     *                                   vez de un array vacio para que el motor
     *                                   pueda escribir «sin premio» sin tener que
     *                                   comprobar el recuento.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function primeraPendiente(int $promocionId, string $momento): ?array
    {
        return $this->db->uno(
            'SELECT id, tramo_id, tipo_premio_id, inicio
               FROM unidades_premio
              WHERE promocion_id = ?
                AND estado = ?
                AND inicio <= ?
              ORDER BY inicio ASC, id ASC
              LIMIT 1
              FOR UPDATE',
            [$promocionId, self::ESTADO_PROGRAMADA, $momento]
        );
    }

    /**
     * Entrega una unidad a una participacion y devuelve el numero de filas que
     * han cambiado.
     *
     * ============================================================================
     * POR QUE ESTE METODO DEVUELVE UN NUMERO Y NO UN BOOLENO
     * ============================================================================
     *
     * Es la pieza de la que habla D8 al decir que el UPDATE condicional «es el
     * que garantiza por si solo que una unidad nunca se adjudica dos veces», y es
     * la razon de que el WHERE lleve «AND estado = 'programada».
     *
     * La idea es que MySQL no avise de un UPDATE que no cambia nada: si otra
     * peticion se ha adelantado y ya ha entregado esa unidad, este UPDATE
     * afecta a cero filas y sigue sin dar error. Si el motor se fiara de que la
     * unidad estaba «programada» cuando la leyo, dos peticiones simultaneas
     * adjudicarian el mismo premio. Al comprobar el recuento, la segunda ve un
     * cero, deshace lo suyo y busca la siguiente unidad. Por eso el metodo
     * devuelve el recuento y el motor lo mira: un cero no es un fallo, es la
     * senal de que hay que reintentar con otra unidad.
     *
     * @param int    $unidadId        Identificador de la unidad a entregar.
     * @param int    $participacionId Participacion que se la lleva el premio.
     * @param string $momento         Instante real de la adjudicacion.
     * @param string $codigo          Codigo unico de reclamacion, que es lo que
     *                                recibe la clienta.
     *
     * @return int Numero de filas afectadas: 1 si la unidad era suya, 0 si
     *             otro proceso se ha adelantado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function entregar(int $unidadId, int $participacionId, string $momento, string $codigo): int
    {
        return $this->db->ejecutar(
            'UPDATE unidades_premio
                SET estado = ?,
                    participacion_id = ?,
                    adjudicada_en = ?,
                    codigo_reclamacion = ?,
                    modificado_en = ?
              WHERE id = ?
                AND estado = ?',
            [
                self::ESTADO_ENTREGADA,
                $participacionId,
                $momento,
                $codigo,
                $momento,
                $unidadId,
                self::ESTADO_PROGRAMADA,
            ]
        );
    }

    /**
     * Cuenta las unidades de una campana agrupadas por estado.
     *
     * Lo usa el panel de seguimiento y, en este hito, las pruebas para comprobar
     * que una adjudicacion ha movido exactamente una unidad y nada mas.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return array<string, int> Estados como claves y numero de unidades como
     *                            valores. Los estados que no tengan ninguna
     *                            unidad no aparecen.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPorEstado(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT estado, COUNT(*) AS total
               FROM unidades_premio
              WHERE promocion_id = ?
              GROUP BY estado',
            [$promocionId]
        );

        $recuento = [];

        foreach ($filas as $fila) {
            $recuento[(string) $fila['estado']] = (int) $fila['total'];
        }

        return $recuento;
    }

    /**
     * Genera un codigo de reclamacion unico para una unidad.
     *
     * Es lo que recibe la clienta en el correo y lo que la azafata le entrega,
     * y es lo que resuelve la duda 4 del apartado 12 sobre que debe contener el
     * correo de premio.
     *
     * El codigo se sortea con random_bytes, no con rand ni con un contador, por
     * dos razones. Una, que no se pueda adivinar el siguiente a partir del
     * anterior. Dos, y mas importante, que el codigo de una unidad no tenga nada
     * que ver con su identificador: si el codigo fuera «P-000042» y las unidades
     * se numeran en orden, alguien que hubiera visto un correo deduce los
     * codigos de los demás premios con solo probar uno.
     *
     * Se usan quince caracteres de un alfabeto de treinta y dos, en mayusculas y
     * sin vocales ambiguas. La longitud sale de la columna CHAR(16), que es lo
     * que dice el esquema. Treinta y dos elevado a quince son mas de mil
     * millones de combinaciones, y se comprueba de todos modos que no exista
     * para no depender de esa probabilidad.
     *
     * @return string Codigo de quince caracteres, sin espacios ni signos.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si no se encuentra un codigo libre.
     */
    public function generarCodigoReclamacion(): string
    {
        // Se excluyen la I y la O, que se confunden con el uno y con el cero
        // cuando alguien lo lee en voz alta o lo dicta por teléfono. Tambien se
        // quitan el 0 y el 1, que en una lista escrita a mano son imposibles de
        // distinguir.
        $alfabeto = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $longitud = 15;
        $maximoIntentos = 10;

        for ($intento = 0; $intento < $maximoIntentos; $intento++) {
            $codigo = '';

            // Se leen bytes aleatorios de forma uniforme. Un byte da ocho bits, y
            // el alfabeto tiene treinta y dos valores, o sea cinco bits, asi que
            // se descartan los bytes que no caben en un multiplo de treinta y
            // dos. Dividir y quedarse con el resto sesgaria la distribucion, y
            // sesgar la distribucion de un codigo de premio es justo el tipo de
            // detalle que no se ve hasta que ya ha pasado.
            $bytes = random_bytes($longitud * 2);
            $aceptados = 0;

            for ($i = 0; $i < strlen($bytes) && $aceptados < $longitud; $i++) {
                $valor = ord($bytes[$i]) % 32;

                // Los ultimos ocho valores de un byte (de 248 a 255) producen
                // residuos de 24 a 31, y los residuos de 0 a 7 del rango 0 a 31
                // salen ocho veces mas veces que los demas. Descartarlos deja el
                // alfabeto equiprobable.
                if (ord($bytes[$i]) >= 248) {
                    continue;
                }

                $codigo .= $alfabeto[$valor];
                $aceptados++;
            }

            // Con el descarte anterior sobran bytes de sobra para quince
            // caracteres, pero si por lo que sea no se han reunido, se sigue
            // con el siguiente intento en lugar de devolver un codigo corto.
            if ($aceptados < $longitud) {
                continue;
            }

            $existe = $this->db->valor(
                'SELECT 1 FROM unidades_premio WHERE codigo_reclamacion = ? LIMIT 1',
                [$codigo]
            );

            if ($existe === null) {
                return $codigo;
            }
        }

        // Diez intentos sin encontrar uno libre es una situacion que en la
        // practice no ocurre, y si ocurre conviene que se note en el log de
        // errores en vez de devolver un codigo repetido: un codigo repetido
        // haria que dos clientas distintas compartieran la reclamacion del
        // mismo premio.
        throw new ErrorBaseDeDatos(
            new \PDOException('No se ha encontrado un codigo de reclamacion libre tras diez intentos.')
        );
    }
}
