<?php

/**
 * Contrato del validador de reglas de participacion.
 *
 * ============================================================================
 * POR QUE EXISTE UNA INTERFAZ Y NO UNA CLASE
 * ============================================================================
 *
 * Porque el motor de adjudicacion NO sabe que reglas tiene una campana, y no
 * deberia saberlo. Las reglas de participacion son el hito 4, con su panel y sus
 * tres pantallas; el motor es el hito 2 y solo necesita saber una cosa: si este
 * intento es valido o no, y si no, por que.
 *
 * La frontera se cruza con una interfaz en lugar de con una comprobacion
 * suelta por dos razones concretas.
 *
 * La primera es la garantia del caso de aceptacion 5, «rechazar sin consumir una
 * unidad». Si la decision de rechazar estuviera en el controlador, esa garantia
 * dependeria de que el controlador llamase a la operacion correcta, y no habria
 * ninguna prueba que lo comprobara. Con el validador inyectado, el rechazo es
 * una salida del propio motor: cuando el validador dice que no, el motor escribe
 * en intentos_rechazados y sale sin haber tocado unidades_premio. No hay forma
 * de que un rechazo llegue a la cola, ni por descuido.
 *
 * La segunda es el orden de los hitos. Con esta interfaz, el hito 4 es
 * puramente aditivo: se escribe la clase que la implementa y no hay que tocar
 * el motor ni una linea. Y mientras tanto, las pruebas del hito 2 pueden
 * inyectar un validador que acepte o que rechace a voluntad, que es lo que
 * permite probar los dos caminos sin tener todavia las reglas de verdad.
 *
 * ============================================================================
 * QUE DEVUELVE EL VALIDADOR
 * ============================================================================
 *
 * Null si el intento es valido, y un array con el motivo si no lo es. Se
 * devuelve un motivo y no un simple false por dos razones: el panel de
 * seguimiento agrupa los rechazos por motivo, asi que hace falta un codigo; y la
 * pantalla de la azafata tiene que poder explicarle a la clienta por que no
 * puede participar, en un mensaje legible y sin datos personales de nadie mas.
 *
 * @see \App\Services\Adjudicador
 * @see \App\Models\IntentoRechazado
 * @see apartado 6 de la especificacion, la operacion de validar y adjudicar
 * @see caso de aceptacion 5
 */

declare(strict_types=1);

namespace App\Services;

/**
 * Decide si un intento de participacion es valido, y con que motivo no lo es.
 */
interface ValidadorReglas
{
    /**
     * Comprueba un intento de participacion.
     *
     * La implementacion recibe el intento tal cual lo ha escrito la pantalla de
     * la azafata, con la configuracion de la campana que necesite para decidir, y
     * devuelve el rechazo o null.
     *
     * Se llama DENTRO de la transaccion de adjudicacion, con el bloqueo de la
     * campana ya tomado. Una implementacion que consulte la base de datos esta,
     * por tanto, dentro de esa transaccion y ve el mismo estado que el motor.
     *
     * Una implementacion que lance una excepcion se considera un fallo del
     * sistema, no un rechazo: la transaccion se deshace y el intento no queda
     * registrado ni como rechazo ni como participacion. Devolver el rechazo es lo
     * unico que evita que un fallo de programacion se traduzca en «su participacion
     * no es valida» mostrado a una clienta en un mostrador.
     *
     * @param array<string, mixed> $intento Datos del intento. Siempre incluye
     *                                     «promocion_id», «tramo_id» (que puede
     *                                     ser null si no hay ningun tramo
     *                                     activo), «clave_idempotencia»,
     *                                     «momento», «datos» y
     *                                     «clave_unicidad». Puede incluir
     *                                     «usuario_azafata_id» y
     *                                     «simulacion».
     *
     * @return array{codigo: string, texto: string}|null Null si el intento es
     *                                               valido. Si no lo es, un
     *                                               array con un codigo corto
     *                                               y estable del motivo, para
     *                                               agrupar en el panel, y un
     *                                               texto para la pantalla. El
     *                                               texto no debe llevar datos
     *                                               personales de la persona
     *                                               que participa.
     */
    public function validar(array $intento): ?array;
}
