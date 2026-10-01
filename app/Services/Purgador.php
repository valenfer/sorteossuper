<?php

/**
 * Servicio que purga los datos personales de las campanas que han cumplido su
 * plazo de retencion.
 *
 * ============================================================================
 * POR QUE ESTO NO ES UN SCRIPT CON SQL DENTRO
 * ============================================================================
 *
 * Porque la purga se decide con una regla que no cabe en el guion: una campana
 * se purga cuando lleva cerrada mas dias de los que dice su parametro de
 * retencion, y eso son tres condiciones sobre la misma campana. Si la
 * comprobacion viviera en el binario, el panel no podria ensear cuando le toca a
 * cada campana sin repetirla, y un segundo sitio que decide distinto es un sitio
 * que un dia borra de mas.
 *
 * El guion de consola y el panel llaman a este mismo servicio.
 *
 * ============================================================================
 * POR QUE VACIA Y NO BORRA
 * ============================================================================
 *
 * El apartado 7 pide limitar cuanto tiempo se guardan los datos, no perder el
 * historial del sorteo. Las participaciones y los correos conservan su fila
 * porque hay tres cosas que dependen de ella:
 *
 *   - la referencia inmutable entre la participacion ganadora y la unidad
 *     adjudicada que pide el apartado 6, sin la cual no se puede demostrar a
 *     quien se entrego cada premio;
 *   - los recuentos del panel de seguimiento, que con las filas borradas bajarian
 *     y con ellos los porcentajes que se ensenan a la direccion;
 *   - la auditoria de adjudicaciones, que cita el identificador de la
 *     participacion.
 *
 * Se vacian las columnas que identifican a una persona y se conservan las que
 * describen el sorteo. Es la diferencia entre «no saber quien gano» y «no saber
 * que se entrego un premio».
 *
 * ============================================================================
 * POR QUE TRES TABLAS Y POR QUE UNA SOLA AUDITORIA
 * ============================================================================
 *
 * El dato personal de una participacion esta en tres sitios, y purgar solo el
 * primero dejaria los otros intactos:
 *
 *   - `participaciones`: el formulario entero, sus formas normalizadas y la
 *     huella de unicidad.
 *   - `correos`: el destinatario, el cuerpo con el nombre y el codigo de
 *     reclamacion, y las variables con las que se monto el mensaje.
 *   - `intentos_rechazados`: nada de lo que se suele llamar dato personal, pero
 *     una huella HMAC de la identidad, que es un identificador estable de una
 *     persona. El esquema lo dice sin rodeos, asi que tampoco se vacia: mientras
 *     la campana «conozca» quien fue rechazado, decir que sus datos estan
 *     borrados no seria cierto.
 *
 * ============================================================================
 * POR QUE NO SE PURGAN LOS CORREOS QUE ESTAN PENDIENTES
 * ============================================================================
 *
 * Porque un mensaje en estado «pendiente» o «enviando» todavia no ha salido, y
 * vaciarlo dejaria al worker un mensaje sin texto y sin destinatario esperando a
 * ser recogido. La tienda recibiria un correo en blanco dirigido a nadie, y el
 * codigo de reclamacion del premio se habria perdido para siempre, porque el
 * unico sitio donde estaba era el cuerpo del mensaje.
 *
 * Es la parte de la purga que podria hacer dano de verdad, y por eso la condicion
 * esta en la consulta de \App\Models\Correo::purgar() y no aqui: asi no hay
 * ningun camino que la esquive.
 *
 * Los rechazos no llevan `purgada_en` porque vaciarlos es su propia marca: el
 * WHERE filtra por «clave_identidad IS NOT NULL» y una segunda pasada no
 * encuentra nada. Se paga con no poder decir que dia se purgaron, y se acepta a
 * cambio de no anadir una columna que solo serviria para eso.
 *
 * Una fila de auditoria por campana, no una por fila vaciada. Con veinte mil
 * participaciones, veinte mil asientos serian ilegibles y ademas no podrian
 * decir nada que no dijera el recuento. Lo que tiene que poder contestarse es
 * «¿se purgo esta campana y cuantas filas?to?», y eso cabe en tres numeros.
 *
 * ============================================================================
 * POR QUE NO SE PUEDE SEGUIR EN MEDIO
 * ============================================================================
 *
 * El vaciado de las tres tablas va en una transaccion. Si se hiciera tabla por
 * tabla sin ella, un fallo a la mitad dejaria participaciones sin datos
 * personales pero correos con el nombre y el codigo de reclamacion dentro: la
 * mitad de lo que se queria borrar seguiria escrito y no habria forma de
 * deshacerlo, porque la purga es irreversible.
 *
 * Por lo mismo el bloqueo de campana se toma antes, como en
 * \App\Services\Adjudicador: dos pasadas del guion a la vez no deben estar
 * vaciando las mismas filas y escribiendo dos auditorias de lo mismo.
 *
 * ============================================================================
 * POR QUE LA SIMULACION CUENTA Y NO ESTIMA
 * ============================================================================
 *
 * Porque una simulacion sirve para decidir, y para decidir hace falta el numero
 * exacto. La simulacion cuenta con los mismos filtros que usara el vaciado, de
 * modo que el numero que ensena es el que saldra. No puede decir «casi», porque
 * una simulacion que estima en vez de contar hace pensar que el numero es
 * exacto, y quien decide si purga o no debe ver el numero exacto de filas
 * afectadas.
 *
 * No hay forma de deshacer una purga, asi que el primer uso de este servicio en
 * una instalacion nueva tiene que ser en simulacion. Por eso existe, y por eso el
 * guion la pide por defecto.
 *
 * @see \App\Models\Promocion::campanasParaPurgar()
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\ErrorAplicacion;
use App\Models\Auditoria;
use App\Models\Correo;
use App\Models\IntentoRechazado;
use App\Models\Participacion;
use App\Models\Promocion;

/**
 * Servicio que purga los datos personales de las campanas que han cumplido su
 * plazo de retencion.
 *
 * @see El comentario de cabecera del fichero, que explica las decisiones de fondo.
 */
class Purgador
{
    /**
     * Crea el purgador con sus modelos.
     *
     * Los modelos se crean aqui y no se reciben como parametros, que es lo que
     * hacen el resto de servicios del proyecto. Ademas de que aqui no haria
     * falta, hay una razon tecnica: PHP 8.0, que es el motor de XAMPP, no admite
     * «new» en el valor por defecto de un parametro —eso es de PHP 8.1—, asi que
     * la inyeccion opcional solo se podria hacer con una cadena de comprobaciones
     * de tipo que no aportaria nada. Los modelos no tienen estado propio:
     * comparten la conexion, de modo que crearlos aqui o dentro no cambia nada.
     *
     * @return void
     */
    public function __construct()
    {
        $this->promociones = new Promocion();
        $this->participaciones = new Participacion();
        $this->correos = new Correo();
        $this->rechazos = new IntentoRechazado();
        $this->auditoria = new Auditoria();
    }

    /**
     * Purgador de datos personales de las campanas que ya pueden purgarse.
     *
     * ============================================================================
     * POR QUE SE PUEDE VOLVER A LLAMAR SIN QUE PASE NADA
     * ============================================================================
     *
     * Porque las tres consultas de vaciado filtran por su propia marca —«purgada_en
     * IS NULL» en las dos primeras, «clave_identidad IS NOT NULL» en la tercera— y
     * una fila ya purgada no vuelve a entrar. La segunda pasada no cambia nada, y
     * un trabajo de cron que se ejecuta cada noche no necesita acordarse de si
     * ayer ya paso.
     *
     * La campana, en cambio, sigue apareciendo en `campanasParaPurgar` para
     * siempre: su plazo ya vencio y no se queda sin plazo. Lo que evita el trabajo
     * de noches vacias es `yaAuditada`, que salta la campana si su asiento de purga
     * ya esta escrito. Sin eso, el historial se llenaria de una linea por noche
     * por campana, que es justo el ruido que la auditoria tiene que evitar.
     *
     * @param string $momento  Instante de la purga, en «A-n-j H:i:s».
     * @param int    $limite   Maximo de campanas en esta pasada.
     * @param bool   $simular  Si es true, se cuenta y no se escribe nada.
     *
     * @return array{purgadas:int,campanas:array<int, array<string, mixed>>}
     *         Cuantas campanas se han purgado y el detalle de cada una.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si alguna consulta falla.
     */
    public function purgar(string $momento, int $limite = 50, bool $simular = false): array
    {
        $campanas = $this->promociones->campanasParaPurgar($momento, $limite);
        $resultado = ['purgadas' => 0, 'campanas' => []];

        foreach ($campanas as $campana) {
            $detalle = $this->purgarCampana((int) $campana['id'], $momento, $simular);

            if ($detalle === null) {
                continue;
            }

            $resultado['campanas'][] = $detalle;

            if (!$simular) {
                $resultado['purgadas']++;
            }
        }

        return $resultado;
    }

    /**
     * Purgador de los datos personales de una campana.
     *
     * ============================================================================
     * POR QUE DEVUELVE NULL Y NO UN ERROR CUANDO YA ESTA PURGADA
     * ============================================================================
     *
     * Porque no es un error. Una campana cuyo plazo vencio hace semanas y cuyo
     * asiento de purga ya esta escrito esta en un estado correcto y deseado, y
     * `purgar()` la ha encontrado en la lista porque la lista no sabe eso. Lanzar
     * un error obligaria a distinguir ahi dos cosas que no son un fallo, y lo
     * unico que se quiere es que no aparezca en el recuento.
     *
     * ============================================================================
     * POR QUE EL BLOQUEO SE TOMA ANTES DE LEER LA CAMPANA
     * ============================================================================
     *
     * Al reves que en el cierre, y por el motivo opuesto. Alli la campana se
     * comprueba antes para avisar rapido de un estado que no va a cambiar; aqui la
     * lectura decide si se purga, y lo que decide tiene que ser lo que se vea
     * con el bloqueo puesto. Adjudicar una campana mientras se purga es justo el
     * cruce que no puede ocurrir: uno escribe `clave_unicidad` mientras el otro
     * la vacia.
     *
     * @param int    $promocionId Campana que se quiere purgar.
     * @param string $momento     Instante de la purga, en «A-n-j H:i:s».
     * @param bool   $simular     Si es true, se cuenta y no se escribe nada.
     *
     * @return array<string, mixed>|null Detalle de la campana purgada, o null si
     *         ya estaba purgada y no hay nada que hacer.
     *
     * @throws \App\Core\ErrorAplicacion   Si la campana no existe o no se puede
     *                                     purgar todavia.
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function purgarCampana(int $promocionId, string $momento, bool $simular = false): ?array
    {
        $db = Aplicacion::db();

        if ($simular) {
            return $this->simularCampana($promocionId, $momento);
        }

        if (!$db->bloquearPromocion($promocionId)) {
            throw new ErrorAplicacion(
                'No se puede purgar la campana ahora mismo porque esta ocupada. Inténtelo de nuevo en unos segundos.'
            );
        }

        // El bloqueo con nombre pertenece a la conexion y no se libera con el
        // COMMIT, igual que en \App\Services\Adjudicador.
        try {
            return $db->enTransaccion(function () use ($promocionId, $momento): ?array {
                $campana = $this->promociones->exigirPurgaPermitida($promocionId, $momento);

                if ($this->auditoria->existe(
                    $promocionId,
                    null,
                    'purgador',
                    Auditoria::ACCION_PURGA
                )) {
                    return null;
                }

                $recuento = [
                    'participaciones' => $this->participaciones->purgar($promocionId, $momento),
                    'correos' => $this->correos->purgar($promocionId, $momento),
                    'rechazos' => $this->rechazos->purgar($promocionId),
                ];

                $this->auditoria->registrar(
                    $promocionId,
                    null,
                    '',
                    'purgador',
                    (string) $promocionId,
                    Auditoria::ACCION_PURGA,
                    null,
                    $recuento,
                    null,
                    null,
                    ''
                );

                return [
                    'promocion_id' => $promocionId,
                    'nombre' => (string) $campana['nombre'],
                ] + $recuento;
            });
        } finally {
            $db->liberarBloqueoPromocion($promocionId);
        }
    }

    /**
     * Cuenta lo que una purga vaciaria, sin escribir nada.
     *
     * ============================================================================
     * POR QUE CUENTA Y NO ESTIMA
     * ============================================================================
     *
     * Porque una simulacion sirve para decidir, y para decidir hace falta el
     * numero exacto. Contar cuesta cuatro consultas, que es lo que costaria
     * purgar; y el resultado es el mismo numero que dara la pasada real, porque
     * las consultas de conteo llevan los mismos filtros que las de vaciado.
     *
     * Las de correos cuentan solo los estados «enviado» y «error». Los mensajes
     * pendientes no se purgan, asi que no pueden aparecer en la cuenta: si
     * contaran, el guion anunciaria un numero que despues no se cumplira, que es
     * la forma segura de que nadie se fíe de la simulacion.
     *
     * @param int    $promocionId Campana que se simula.
     * @param string $momento     Instante de referencia, en «A-n-j H:i:s».
     *
     * @return array<string, mixed> El mismo detalle que devuelve `purgarCampana`,
     *         con el recuento de lo que se vaciaria.
     *
     * @throws \App\Core\ErrorAplicacion   Si la campana no existe o no se puede
     *                                     purgar todavia.
     * @throws \App\Core\ErrorBaseDeDatos Si alguna consulta falla.
     */
    private function simularCampana(int $promocionId, string $momento): array
    {
        $campana = $this->promociones->exigirPurgaPermitida($promocionId, $momento);

        return [
            'promocion_id' => $promocionId,
            'nombre' => (string) $campana['nombre'],
            'participaciones' => $this->participaciones->contarPendientesDePurga($promocionId),
            'correos' => $this->correos->contarPendientesDePurga($promocionId),
            'rechazos' => $this->rechazos->contarPendientesDePurga($promocionId),
        ];
    }
}
