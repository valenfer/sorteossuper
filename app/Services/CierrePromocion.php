<?php

/**
 * Cierre de una campana.
 *
 * ============================================================================
 * QUE ES CERRAR UNA CAMPANA
 * ============================================================================
 *
 * Cerrar no es una etiqueta que se pone. Son tres cosas que tienen que ocurrir
 * juntas o no ocurrir:
 *
 *   1. Las unidades de premio que seguian programadas pasan a no_entregada.
 *   2. La campana pasa a finalizada y se le anota la hora del cierre.
 *   3. Queda escrito quien lo ha cerrado, cuando y cuantas unidades se han
 *      quedado sin reclamar.
 *
 * Las tres van en una sola transaccion. Si la campana quedara finalizada y sus
 * unidades siguieran programadas, el motor dejaria de entregar —porque ya no
 * acepta una campana finalizada— y las unidades se quedarian colgadas para
 * siempre, sin que nadie supiera por que. Si las unidades cambiaran y la campana
 * no, la campana seguiria admitiendo participaciones que ya no tienen premio que
 * dar, que es el peor fallo posible: alguien participaria, el azar le diria que
 * no ha ganado porque no habia premios, y no habria forma de demostrar despues
 * que el prize pool existia.
 *
 * ============================================================================
 * POR QUE EL CIERRE TOMA EL MISMO BLOQUEO QUE EL SORTEO
 * ============================================================================
 *
 * Por una carrera concreta y facil de que pase. El motor de adjudicacion bloquea
 * la campana mientras busca la siguiente unidad pendiente y la entrega; si el
 * administrador pulsa «Cerrar» en ese preciso instante, sin bloqueo se puede dar
 * este resultado: el motor ha leido la unidad 42 como programada, el cierre la
 * ha marcado como no_entregada, y el motor la entrega igualmente. La campana
 * queda cerrada con un premio entregado y, si el cierre escribio la auditoria
 * despues, con un recuento que ya no cuadra con lo que hay en la tabla.
 *
 * Con el bloqueo, uno de los dos espera. No hay ningun caso en que el cierre
 * necesite mas de unos milisegundos, porque son tres sentencias, asi que el
 * administrador no nota la espera y la azafata como mucho recibe un «intente de
 * nuevo».
 *
 * ============================================================================
 * POR QUE NO HAY MANCHA DE «POR QUE NO SE ENTREGARON»
 * ============================================================================
 *
 * Porque el esquema no tiene donde ponerla y porque no hace falta. El motivo de
 * una unidad que se cierra sin entregar es siempre el mismo, y el propio estado
 * lo dice: la campana se cerro antes de que nadie la reclamara. Anadir un motivo
 * distinto del de una retirada manual —que si lo guarda, en anulada_motivo—
 * haria que el calendario no se pudiera leer. Lo que si se guarda es el recuento
 * en la fila de auditoria, que es donde se busca «cuantos premios se quedaron
 * sin entregar en esta campana».
 *
 * @see \App\Models\UnidadPremio::noEntregarProgramadas()
 * @see \App\Models\Promocion::finalizar()
 * @see \App\Models\Auditoria
 * @see \App\Core\Db::bloquearPromocion()
 * @see apartado 8 de la especificacion, panel de seguimiento
 * @see decisiones D4, D8, D10 y D18
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\Db;
use App\Core\ErrorAplicacion;
use App\Models\Auditoria;
use App\Models\Promocion;
use App\Models\UnidadPremio;

/**
 * Servicio que cierra una campana y deja constancia de ello.
 */
class CierrePromocion
{
    /**
     * Cierra una campana y registra el cierre.
     *
     * Es idempotente en cuanto al efecto: llamarlo dos veces no cambia el
     * resultado, porque la segunda vez la campana ya no esta activa y la
     * actualizacion no afecta a ninguna fila. La segunda llamada devuelve
     * unidades_afectadas = 0 y no escribe una segunda fila de auditoria, de modo
     * que un doble clic en el boton no llena el historial de cierres repetidos.
     *
     * La campana se relee dentro de la transaccion y ya con el bloqueo puesto, no
     * antes. Releerla antes dejaria una ventana en la que el estado comprobado
     * no es el que se va a escribir, que es la forma habitual de que estas dos
     * piezas se desincronicen.
     *
     * @param int         $promocionId Campana que se cierra.
     * @param string|null $momento     Instante del cierre, o null para tomar el
     *                                 actual. Se puede forzar en las pruebas.
     * @param int|null    $usuarioId   Usuario que cierra, o null si no hay
     *                                 sesion.
     *
     * @return array{promocion_id: int, estado: string, cerrada_en: string, unidades_no_entregadas: int, unidades_pendientes: int}
     *         Resumen del cierre, con la forma que documentan panel y pruebas.
     *
     * @throws ErrorAplicacion Si la campana no existe o no esta activa.
     */
    public function cerrar(int $promocionId, ?string $momento = null, ?int $usuarioId = null): array
    {
        $momento = $momento ?? Aplicacion::ahora();
        $db = Aplicacion::db();

        // Se comprueba la campana antes de nada, para que una campana que ya no
        // este activa avise sin esperar a que se tome el bloqueo. El mensaje es
        // el mismo que el de la comprobacion de dentro, porque para quien esta en
        // la pantalla da igual: hay que abrirla de nuevo.
        $this->exigirActiva($promocionId);

        if (!$db->bloquearPromocion($promocionId)) {
            throw new ErrorAplicacion(
                'Hay demasiadas participaciones simultaneas en esta campana. Vuelva a intentarlo.'
            );
        }

        // El bloqueo con nombre pertenece a la conexion y no se libera con el
        // COMMIT, igual que en \App\Services\Adjudicador. Si una excepcion lo
        // dejara puesto, las participaciones de la campana quedarian colgadas.
        try {
            return $db->enTransaccion(function () use ($promocionId, $momento, $usuarioId): array {
                $promocion = (new Promocion())->exigirPorId($promocionId);

                if ($promocion['estado'] !== Promocion::ESTADO_ACTIVA) {
                    throw new ErrorAplicacion(
                        'Esta campana ya no esta activa, asi que no se puede cerrar. Recargue la ficha.'
                    );
                }

                $unidades = new UnidadPremio();

                // El recuento de las que quedaban ANTES del cierre. Se guarda
                // porque es el dato que responde a «¿habia premios sin
                // entregar cuando se cerro?», y no se puede reconstruir despues:
                // en cuanto las cuatrocientas unidades pasan a no_entregada, las
                // cuatrocientas se cuentan igual que las de siempre.
                $pendientes = $unidades->contarEntregables($promocionId);

                $afectadas = $unidades->noEntregarProgramadas($promocionId, $momento);

                $cierre = (new Promocion())->finalizar($promocionId, $momento);

                if ($cierre !== 1) {
                    // Esto no deberia ocurrir: la campana se ha releido como
                    // activa con el bloqueo puesto y no hay nadie mas que pueda
                    // cambiarla. Si occurriera, significa que el estado se ha
                    // tocado por fuera de la aplicacion, y se deshace todo antes
                    // de tocar la auditoria en lugar de dejar una campana a
                    // medias con un historial que diga que se ha cerrado.
                    throw new ErrorAplicacion(
                        'La campana ha cambiado mientras se cerraba. No se ha hecho ningun cambio.'
                    );
                }

                (new Auditoria())->registrar(
                    $promocionId,
                    $usuarioId,
                    $this->nombreUsuario($usuarioId),
                    'promocion',
                    (string) $promocionId,
                    Auditoria::ACCION_CIERRE,
                    [
                        'estado' => Promocion::ESTADO_ACTIVA,
                        'cerrada_en' => null,
                        'unidades_programadas' => $pendientes,
                    ],
                    [
                        'estado' => Promocion::ESTADO_FINALIZADA,
                        'cerrada_en' => $momento,
                        'unidades_no_entregadas' => $afectadas,
                    ],
                    null,
                    null,
                    Aplicacion::ipDeLaPeticion()
                );

                return [
                    'promocion_id' => $promocionId,
                    'estado' => Promocion::ESTADO_FINALIZADA,
                    'cerrada_en' => $momento,
                    'unidades_no_entregadas' => $afectadas,
                    'unidades_pendientes' => $pendientes,
                ];
            });
        } finally {
            $db->liberarBloqueoPromocion($promocionId);
        }
    }

    /**
     * Indica si una campana se puede cerrar ahora mismo.
     *
     * Lo consulta la ficha para pintar el boton o esconderlo, y no sustituye a la
     * comprobacion que hace cerrar(): entre que se pinta la pantalla y se pulsa
     * el boton, la campana puede haber cambiado. Esta es la version que puede
     * mentir; la de dentro de la transaccion no.
     *
     * @param int $promocionId Campana que se quiere comprobar.
     *
     * @return bool True si esta activa y se puede cerrar.
     */
    public function puedeCerrar(int $promocionId): bool
    {
        try {
            return (new Promocion())->exigirPorId($promocionId)['estado']
                === Promocion::ESTADO_ACTIVA;
        } catch (\Throwable $e) {
            // Una campana que no se puede ni leer no se puede cerrar. Que la
            // consulta falle es un problema del servidor, no de quien esta
            // mirando la ficha, y no tiene por que abadearle la pantalla.
            return false;
        }
    }

    /**
     * Comprueba que la campana existe y esta activa, fuera de la transaccion.
     *
     * @param int $promocionId Campana que se quiere comprobar.
     *
     * @return void
     *
     * @throws ErrorAplicacion Si la campana no existe o no esta activa.
     */
    private function exigirActiva(int $promocionId): void
    {
        $estado = (new Promocion())->exigirPorId($promocionId)['estado'];

        if ($estado !== Promocion::ESTADO_ACTIVA) {
            throw new ErrorAplicacion(
                'Esta campana ya no esta activa, asi que no se puede cerrar. Recargue la ficha.'
            );
        }
    }

    /**
     * Devuelve el nombre del usuario para la fila de auditoria.
     *
     * Se copia el nombre en el momento del cambio, no en el de la lectura, porque
     * el esquema guarda una copia justamente para que la fila siga diciendo quien
     * fue aunque la cuenta se renombre o se borre despues. Como la sesion solo
     * guarda el identificador —ver el comentario de
     * \App\Core\Autorizacion::usuario()— hace falta una consulta, y solo una.
     *
     * @param int|null $usuarioId Usuario de la sesion, o null.
     *
     * @return string Nombre legible, o cadena vacia si no hay usuario.
     */
    private function nombreUsuario(?int $usuarioId): string
    {
        return (new \App\Models\User())->nombreDe($usuarioId);
    }
}
