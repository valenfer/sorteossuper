<?php

/**
 * Implementacion real de las reglas de participacion.
 *
 * ============================================================================
 * QUE ES ESTA CLASE Y POR QUE NO VIVE DENTRO DEL MOTOR
 * ============================================================================
 *
 * El motor de adjudicacion (\App\Services\Adjudicador) no sabe que reglas tiene
 * una campana, y no deberia saberlo: el motor es el hito 2 y solo necesita saber
 * una cosa, si un intento es valido o no. Esta clase es la que si lo sabe, y es
 * la que se le inyecta al motor por el constructor.
 *
 * La frontera se cruza con la interfaz \App\Services\ValidadorReglas y no con
 * una comprobacion suelta, y la razon es la garantia del caso de aceptacion 5:
 * «rechazar sin consumir una unidad». Con el validador inyectado, el rechazo es
 * una salida del propio motor, que escribe en intentos_rechazados y sale sin
 * haber tocado unidades_premio. No hay forma de que un rechazo llegue a la cola,
 * ni por descuido.
 *
 * ============================================================================
 * EL ORDEN EN QUE SE COMPRUEBAN LAS REGLAS
 * ============================================================================
 *
 * El orden importa, y no es un detalle de estilo: es el que evita que un mensaje
 * indique un motivo equivocado. En este orden, el que se comprueba primero es el
 * que no depende de nada mas:
 *
 *   1. Codigo de participacion, si la campana exige uno. Es lo que la clienta
 *      tiene delante en la mano, y si falla, todo lo demas da igual.
 *   2. Consentimiento, si la campana lo exige. Es una casilla que la propia
 *      campana ha pedido marcar, y su ausencia invalida el intento entero.
 *   3. Lista de tickets, si la campana declara que los verifica.
 *   4. Duplicados, que es lo ultimo porque es la comprobacion mas cara.
 *
 * Duplicados va el ultimo a proposito: las tres primeras son consultas a tablas
 * pequenas, mientras que la comprobacion de duplicados acaba tocando el indice
 * unico de participaciones, que es el indice mas caliente de la base de datos
 * cuando la cola de premios esta funcionando. Un intento que va a fallar por el
 * codigo, que es el caso mas frecuente de rechazo, no llega a tocar ese indice.
 *
 * ============================================================================
 * POR QUE LOS MOTIVOS TIENEN CODIGO Y TEXTO
 * ============================================================================
 *
 * El codigo es corto y estable, y es lo que permite agrupar los rechazos en el
 * panel de seguimiento. El texto es lo que lee la clienta en el mostrador, y por
 * eso sale de las reglas configuradas y no del codigo: una campana puede querer
 * «Ya has participado esta semana, gracias por participates» y otra
 * «Este ticket ya se ha usado». El texto no lleva datos personales de nadie mas,
 * porque el apartado 4.7 prohibe expresamente revelarlos: un texto por defecto
 * que dijera «ese DNI ya participa» confirmaria a la segunda persona que la
 * primera existe.
 *
 * @see \App\Services\ValidadorReglas
 * @see \App\Services\Adjudicador
 * @see \App\Models\ReglaParticipacion
 * @see \App\Models\CodigoValido
 * @see \App\Services\Huella
 * @see apartado 4.7 de la especificacion, requisitos de participacion
 * @see caso de aceptacion 5
 */

declare(strict_types=1);

namespace App\Services;

use App\Models\CodigoValido;
use App\Models\Participacion;
use App\Models\ReglaParticipacion;

/**
 * Comprueba las reglas de participacion configuradas en una campana.
 */
class ReglasCampana implements ValidadorReglas
{
    /**
     * Codigo del motivo de rechazo por codigo no valido.
     *
     * @var string
     */
    public const CODIGO_INVALIDO = 'codigo_invalido';

    /**
     * Codigo del motivo de rechazo por falta de consentimiento.
     *
     * @var string
     */
    public const CODIGO_SIN_CONSENTIMIENTO = 'sin_consentimiento';

    /**
     * Codigo del motivo de rechazo por ticket que no esta en la lista.
     *
     * @var string
     */
    public const CODIGO_TICKET_NO_VERIFICADO = 'ticket_no_verificado';

    /**
     * Codigo del motivo de rechazo por participacion duplicada.
     *
     * @var string
     */
    public const CODIGO_DUPLICADO = 'duplicado';

    /**
     * Codigo del motivo de rechazo por intento fuera de horario.
     *
     * @var string
     */
    public const CODIGO_FUERA_DE_HORARIO = 'fuera_de_horario';

    /**
     * Reglas de la campana, cacheadas por campana durante la peticion.
     *
     * El motor puede llamar a validar() mas de una vez con la misma campana, y
     * releer las reglas en cada llamada es una consulta que no aporta nada: las
     * reglas no cambian a mitad de una adjudicacion. La cache es por peticion y
     * no persistente, asi que un cambio del administrador se ve en la peticion
     * siguiente, que es justo cuando tiene que verse.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $reglasPorCampana = [];

    /**
     * Resolucion del campo de identidad y del ambito de la huella.
     *
     * Se inyecta y no se construye dentro porque la pantalla de participacion
     * necesita exactamente el mismo calculo, y la forma de garantizar que los
     * dos digan lo mismo es que usen el mismo objeto y no dos copias de la misma
     * logica. Ver \App\Services\IdentidadCampana.
     *
     * @var IdentidadCampana
     */
    private IdentidadCampana $identidad;

    /**
     * @param IdentidadCampana|null $identidad Resolucion de identidad. Por
     *                                        defecto se construye una, que es lo
     *                                        normal.
     */
    public function __construct(?IdentidadCampana $identidad = null)
    {
        $this->identidad = $identidad ?? new IdentidadCampana();
    }

    /**
     * Comprueba un intento de participacion contra las reglas de su campana.
     *
     * @param array<string, mixed> $intento Datos del intento, con la misma forma
     *                                     que describe la interfaz.
     *
     * @return array{codigo: string, texto: string}|null Null si el intento es
     *                                               valido.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si alguna consulta falla. Una excepcion
     *                                    se considera un fallo del sistema, no
     *                                    un rechazo, de modo que la transaccion
     *                                    se deshace y el intento no queda
     *                                    registrado ni como participacion ni como
     *                                    rechazo.
     */
    public function validar(array $intento): ?array
    {
        $promocionId = (int) ($intento['promocion_id'] ?? 0);

        if ($promocionId <= 0) {
            // Una campana que no existe no es un rechazo: es una peticion que
            // no tiene sentido. Se devuelve el mismo motivo generico que si
            // faltara el codigo, porque la pantalla no puede explicar un id que
            // nadie ha escrito.
            return [
                'codigo' => self::CODIGO_INVALIDO,
                'texto'  => 'Su participacion no es valida.',
            ];
        }

        $reglas = $this->reglas($promocionId);
        $datos = (array) ($intento['datos'] ?? []);

        // ---- 1. El codigo de participacion, si la campana exige uno ----------
        if ($reglas['exigir_codigo']) {
            $codigo = trim((string) ($datos['codigo_participacion'] ?? ''));

            if ($codigo === '') {
                return $this->rechazo(self::CODIGO_INVALIDO, $reglas['texto_rechazo_codigo']);
            }

            // La lista de codigos es opcional, y por eso se mira primero si hay
            // alguna. Si la campana no ha cargado ninguna, exigir que el codigo
            // este en ella dejaria fuera a todas las campanas que usan la regla
            // solo como «no me dejes participar en blanco», que es un uso
            // legitimo: el apartado 4.7 pide el control de duplicados, y la lista
            // es la parte opcional.
            //
            // Si la lista tiene algo, en cambio, el codigo tiene que estar en
            // ella y sin gastar. En cuanto hay una lista cargada, esta es la
            // comprobacion que la hace util, y saltarsela por tener un codigo
            // cualquiera seria justo el fallo que la lista existe para evitar.
            if ($this->hayLista($promocionId, CodigoValido::TIPO_CODIGO)
                && !(new CodigoValido())->estaLibre($promocionId, CodigoValido::TIPO_CODIGO, $codigo)) {
                return $this->rechazo(self::CODIGO_INVALIDO, $reglas['texto_rechazo_codigo']);
            }
        }

        // ---- 2. El consentimiento, si la campana lo exige ---------------------
        if ($reglas['exigir_consentimiento']) {
            $aceptado = $datos['consentimiento'] ?? $datos['acepto_aviso'] ?? null;

            // Se acepta lo que venga de un formulario HTML de verdad: la casilla
            // llega como «on» cuando esta marcada, y como null o ausente cuando
            // no. El booleano se acepta tambien porque el simulador de
            // participaciones lo pasa ya convertido.
            $marcado = $aceptado === true
                || $aceptado === 1
                || $aceptado === '1'
                || $aceptado === 'on'
                || $aceptado === 'si'
                || $aceptado === 'true';

            if (!$marcado) {
                return $this->rechazo(
                    self::CODIGO_SIN_CONSENTIMIENTO,
                    $reglas['texto_rechazo_consentimiento']
                );
            }
        }

        // ---- 3. La lista de tickets, si la campana declara verificarlos -------
        if ($reglas['verificar_ticket']) {
            $ticket = trim((string) ($datos['num_ticket'] ?? ''));

            if ($ticket === '') {
                return $this->rechazo(
                    self::CODIGO_TICKET_NO_VERIFICADO,
                    $reglas['texto_rechazo_codigo']
                );
            }

            if ($this->hayLista($promocionId, CodigoValido::TIPO_TICKET)
                && !(new CodigoValido())->estaLibre($promocionId, CodigoValido::TIPO_TICKET, $ticket)) {
                return $this->rechazo(
                    self::CODIGO_TICKET_NO_VERIFICADO,
                    $reglas['texto_rechazo_codigo']
                );
            }
        }

        // ---- 4. Los duplicados, que es lo ultimo -----------------------------
        $duplicado = $this->esDuplicado($promocionId, $reglas, $intento, $datos);

        if ($duplicado !== null) {
            return $duplicado;
        }

        return null;
    }

    /**
     * Devuelve las reglas de una campana, de la cache si ya estan leidas.
     *
     * @param int $promocionId Campana cuyas reglas se quieren.
     *
     * @return array<string, mixed> Reglas, con las casillas ya convertidas a
     *                            booleano y los textos a cadena.
     */
    private function reglas(int $promocionId): array
    {
        if (isset($this->reglasPorCampana[$promocionId])) {
            return $this->reglasPorCampana[$promocionId];
        }

        $leidas = (new ReglaParticipacion())->leer($promocionId);

        // MySQL devuelve un TINYINT por casilla, y Convertir eso a booleano
        // aqui, una sola vez, evita que el resto del metodo tenga que acordarse
        // de que «1» es truthy pero «0» tambien lo es en PHP.
        $reglas = [
            'exigir_codigo'         => (bool) ($leidas['exigir_codigo'] ?? false),
            'exigir_consentimiento' => (bool) ($leidas['exigir_consentimiento'] ?? false),
            'verificar_ticket'      => (bool) ($leidas['verificar_ticket'] ?? false),
            'una_por_campana'       => (bool) ($leidas['una_por_campana'] ?? false),
            'una_por_dia'           => (bool) ($leidas['una_por_dia'] ?? false),
            'una_por_ticket'        => (bool) ($leidas['una_por_ticket'] ?? false),
            'una_por_dni'           => (bool) ($leidas['una_por_dni'] ?? false),
            'campo_identidad'       => (string) ($leidas['campo_identidad'] ?? ''),
            'texto_rechazo_codigo'          => (string) ($leidas['texto_rechazo_codigo'] ?? ''),
            'texto_rechazo_consentimiento'  => (string) ($leidas['texto_rechazo_consentimiento'] ?? ''),
            'texto_rechazo_horario'         => (string) ($leidas['texto_rechazo_horario'] ?? ''),
            'texto_rechazo_duplicado'       => (string) ($leidas['texto_rechazo_duplicado'] ?? ''),
        ];

        // Un texto vacio en una regla activa haria que la pantalla mostrase un
        // rechazo sin explicar nada, que es justo lo que el apartado 4.7 pide
        // evitar. Se rellena aqui con el texto por defecto, para que el rechazo
        // sea siempre explicable aunque el administrador no haya escrito nada.
        $reglas['texto_rechazo_codigo'] = $reglas['texto_rechazo_codigo'] !== ''
            ? $reglas['texto_rechazo_codigo']
            : 'El codigo introducido no es valido.';

        $reglas['texto_rechazo_consentimiento'] = $reglas['texto_rechazo_consentimiento'] !== ''
            ? $reglas['texto_rechazo_consentimiento']
            : 'Es necesario aceptar el aviso de privacidad.';

        $reglas['texto_rechazo_horario'] = $reglas['texto_rechazo_horario'] !== ''
            ? $reglas['texto_rechazo_horario']
            : 'Ahora mismo no estamos en horario de participacion.';

        $reglas['texto_rechazo_duplicado'] = $reglas['texto_rechazo_duplicado'] !== ''
            ? $reglas['texto_rechazo_duplicado']
            : 'Ya has participado en esta promocion.';

        $this->reglasPorCampana[$promocionId] = $reglas;

        return $reglas;
    }

    /**
     * Comprueba si el intento es un duplicado de una participacion anterior.
     *
     * ============================================================================
     * POR QUE CADA REGLA SE COMPRUEBA POR SEPARADO
     * ============================================================================
     *
     * El apartado 4.7 pide que «cuando hay varias reglas activas se deben
     * cumplir todas», y eso no se puede resolver con una sola comparacion. Cada
     * regla necesita su ambito: una por campana es «campana:7», una por dia es
     * «campana:7|dia:2026-03-15» y una por ticket es «ticket:abc123». Como
     * participaciones solo tiene una columna clave_unicidad, el ambito que se
     * guarda en ella es el de la regla mas restrictiva que este puesta, y no
     * siempre el de campana. Lo elige IdentidadCampana, que es el mismo sitio que
     * lo elige la pantalla de participacion, y el indice unico
     * (promocion_id, clave_unicidad) es el que hace cumplir esa.
     *
     * Que el ambito siga a la regla mas restrictiva no es un detalle. Si la
     * huella se guardara siempre con ambito de campana, una campana con «una por
     * dia» rechazaria a la misma persona para siempre, y no solo el primer dia: el
     * dia siguiente la huella seria identica y el indice la rechazaria otra vez.
     * El rechazo apareceria un dia tarde, cuando ya nadie relaciona el segundo dia
     * con la primera participacion, y no habria dado error en ningun momento.
     *
     * Las demas reglas se comprueban aqui una a una sobre el valor de identidad,
     * que es lo que permite que la combinacion se cumpla entera sin anadir
     * columnas ni indices nuevos. Es el reparto que se decidio para este hito: la
     * huella canonica cubre la regla mas restrictiva y el resto se comprueba de
     * forma explicita, en lugar de migrar la tabla.
     *
     * El orden de las comprobaciones es de mas restrictiva a menos restrictiva,
     * para que el motivo que ve la clienta sea el que mas le afecta. Si alguien
     * ya participa en la campana y ademas repite ticket ese mismo dia, el
     * mensaje que se le ensena es el de una por campana, no el del ticket, porque
     * el primero es el que no va a cambiar con el paso del tiempo.
     *
     * @param int                  $promocionId Campana del intento.
     * @param array<string, mixed> $reglas      Reglas ya leidas.
     * @param array<string, mixed> $intento     Intento completo.
     * @param array<string, mixed> $datos       Datos del intento.
     *
     * @return array{codigo: string, texto: string}|null Rechazo o null.
     */
    private function esDuplicado(
        int $promocionId,
        array $reglas,
        array $intento,
        array $datos
    ): ?array {
        $texto = $reglas['texto_rechazo_duplicado'];
        $participaciones = new Participacion();

        // ---- Una por campana, o la que vigile la huella ---------------------
        // Se usa la huella que el motor ya ha calculado y recibido en el intento,
        // y no una nueva, precisamente para que la comparacion sea
        // identicamente la que va a hacer el indice al insertar. Si aqui se
        // calculara de otra manera, la comprobacion podria decir que no hay
        // duplicado y el indice decir lo contrario, y el rechazo llegaria como un
        // error de base de datos en vez de como un motivo de rechazo.
        //
        // Que el bloque valga para varias reglas es lo que hace
        // \App\Services\IdentidadCampana: la huella se guarda con el ambito de la
        // regla mas restrictiva que este activa, y ese ambito se decide una sola
        // vez para las dos mitades. Si la huella guardada fuera de dia y aqui se
        // buscara la de campana, el rechazo se veria un dia tarde.
        if ($reglas['una_por_campana']) {
            if ($this->esDuplicadoDeHuella($participaciones, $promocionId, $intento)) {
                return $this->rechazo(self::CODIGO_DUPLICADO, $texto);
            }
        }

        // ---- Una por DNI -----------------------------------------------------
        // Va siempre por aqui y nunca por la huella, y el motivo es concreto: la
        // regla habla del DNI, pero el campo de identidad de la campana puede
        // ser el correo o el ticket. Si compartiera la huella con el resto, esa
        // huella se calcularia sobre el campo de identidad y dos personas con DNI
        // distintos pero el mismo correo no chocarian nunca, con lo que la regla
        // no se estaria cumpliendo. Se comprueba sobre el campo `dni`, que es lo
        // que la regla quiere decir.
        if ($reglas['una_por_dni']) {
            $dni = $this->identidad->valorDe('dni', $datos);

            if ($dni !== ''
                && $participaciones->existeIdentidadEn($promocionId, 'dni', $dni)) {
                return $this->rechazo(self::CODIGO_DUPLICADO, $texto);
            }
        }

        // ---- Una por dia ----------------------------------------------------
        if ($reglas['una_por_dia']) {
            $campo = $this->identidad->campoDe($reglas, $datos);
            $valor = $this->identidad->valorDe($campo, $datos);
            $fecha = substr((string) ($intento['momento'] ?? ''), 0, 10);

            if ($campo !== '' && $valor !== '' && $fecha !== ''
                && $participaciones->existeIdentidadEn($promocionId, $campo, $valor, $fecha)) {
                return $this->rechazo(self::CODIGO_DUPLICADO, $texto);
            }
        }

        // ---- Una por ticket -------------------------------------------------
        if ($reglas['una_por_ticket']) {
            $ticket = trim((string) ($datos['num_ticket'] ?? ''));

            if ($ticket !== ''
                && $participaciones->existeIdentidadEn($promocionId, 'num_ticket', $ticket)) {
                return $this->rechazo(self::CODIGO_DUPLICADO, $texto);
            }
        }

        return null;
    }

    /**
     * Comprueba si la huella guardada en el intento ya esta en la campana.
     *
     * Se separa del resto de comprobaciones porque tiene un caso raro que no
     * tienen las demas: la huella puede no existir. Pasa cuando la campana declara
     * una regla de duplicado pero la persona no ha rellenado ningun campo del que
     * se pueda sacar una identidad, o cuando la huella no se ha podido calcular
     * por lo que sea. En ese caso no se puede comprobar nada, y la eleccion es
     * entre dejar pasar y rechazar.
     *
     * Se rechaza. Dejarlo pasar dejaria sin comprobar la unica regla que la
     * campana habia declarado, y el error se veria semanas despues, cuando el
     * administrador se preguntaria por que le salen participaciones repetidas
     * siendo que la configuracion dice que no puede haberlas. Rechazar y explicar
     * molesta solo a quien se dejo un campo en blanco; dejar pasar es un fallo que
     * no ve nadie hasta que ya no tiene arreglo.
     *
     * @param Participacion        $participaciones Modelo de participaciones.
     * @param int                  $promocionId     Campana del intento.
     * @param array<string, mixed> $intento         Intento completo.
     *
     * @return bool True si hay duplicado, o si la huella falta y por tanto no se
     *              puede garantizar que no lo haya.
     */
    private function esDuplicadoDeHuella(
        Participacion $participaciones,
        int $promocionId,
        array $intento
    ): bool {
        $claveUnicidad = (string) ($intento['clave_unicidad'] ?? '');

        if ($claveUnicidad === '') {
            return true;
        }

        return $participaciones->existeClaveUnicidad($promocionId, $claveUnicidad);
    }

    /**
     * Indica si la campana tiene alguma codigo cargado de un tipo.
     *
     * La lista de tickets y la de codigos son opcionales por decision D3, y por
     * eso el validador no puede preguntar simplemente «esta el codigo en la
     * lista»: con la lista vacia, esa pregunta siempre dice que no, y una
     * campana que solo quiere «no me dejes participar en blanco» rechazaria a
     * todo el mundo. Preguntar antes si hay algo cargado separa los dos casos
     * sin cambiar el esquema.
     *
     * @param int    $promocionId Campana en la que se busca.
     * @param string $tipo        TIPO_TICKET o TIPO_CODIGO.
     *
     * @return bool True si hay al menos un codigo de ese tipo.
     */
    private function hayLista(int $promocionId, string $tipo): bool
    {
        return (new CodigoValido())->contar($promocionId, $tipo)['total'] > 0;
    }

    /**
     * Devuelve un rechazo con su codigo y su texto.
     *
     * @param string $codigo Codigo corto del motivo.
     * @param string $texto  Texto para la pantalla.
     *
     * @return array{codigo: string, texto: string} El rechazo, listo para que lo
     *                                               escriba el motor.
     */
    private function rechazo(string $codigo, string $texto): array
    {
        return [
            'codigo' => $codigo,
            'texto'  => $texto !== '' ? $texto : 'Su participacion no es valida.',
        ];
    }
}
