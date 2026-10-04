<?php

/**
 * Motor de adjudicacion de premios.
 *
 * ============================================================================
 * QUE ES Y POR QUE ES LA PIEZA MAS DELICADA DEL PROYECTO
 * ============================================================================
 *
 * Esta clase implementa la regla central del apartado 6: cada unidad de premio
 * tiene una hora programada que dice CUANDO queda disponible para el siguiente
 * participante valido, y la que llega antes se la lleva.
 *
 * Es la pieza mas delicada porque es la unica cuyo error no se ve en la
 * pantalla. Una pantalla que se rompe se ve en el mostrador, en el momento. Un
 * fallo de adjudicacion se manifiesta dias despues, cuando dos clientas han
 * recibido el mismo premio o cuando un premio que el presupuesto del
 * supermercado ya ha comprado no se ha entregado a nadie. Por eso el motor esta
 * escrito en la forma menos comoda posible: mucho comentario, comprobaciones
 * explicitas y tres defensas superpuestas contra la adjudicacion doble.
 *
 * ============================================================================
 * LAS TRES DEFENSAS CONTRA QUE DOS CLIENTAS RECIBAN EL MISMO PREMIO
 * ============================================================================
 *
 * Ninguna basta sola. Se usan las tres, y la que garantiza por si sola el
 * apartado 9 es la tercera, pero las otras dos existen porque la tercera solo
 * protege dentro de una transaccion y es facil que alguien la escriba mal.
 *
 *   1. EL BLOQUEO CON NOMBRE. \App\Core\Db::bloquearPromocion() serializa todas
 *      las adjudicaciones de una campana. Con el puesto, ninguna otra peticion
 *      puede estar mirando la cola a la vez. Es la primera linea del apartado 6.
 *
 *   2. EL FOR UPDATE. La lectura de la unidad candidata bloquea esa fila
 *      concreta hasta que la transaccion acaba, de modo que el valor de «estado»
 *      que el motor ha leido no puede cambiar por debajo mientras decide.
 *
 *   3. EL UPDATE CONDICIONAL Y EL RECUENTO DE FILAS. La entrega se hace con un
 *      «WHERE estado = 'programada'» y se comprueba que ha afectado a una fila.
 *      Si afecta a cero, otro proceso se ha adelantado, y el motor deshace lo
 *      suyo y busca la siguiente unidad en lugar de adjudicarla dos veces. Es la
 *      garantia de D8, y es la que sigue valiendo aunque alguien olvide el
 *      bloqueo o el FOR UPDATE.
 *
 * ============================================================================
 * EL ORDEN DE LAS OPERACIONES Y POR QUE ES ESTE
 * ============================================================================
 *
 * La lectura del intento repetido va la PRIMERA, antes de validar y antes de
 * tocar la cola. Es lo que convierte un doble clic o una recarga en una
 * no-operacion, que es el caso de aceptacion 7: si el intento ya se resolvio,
 * se devuelve su resultado tal cual y no se vuelve a adjudicar.
 *
 * La validacion va DESPUES, porque una regla que se comprueba despues de haber
 * tocado la cola ya no sirve de nada. Y la cola va al final, dentro de la misma
 * transaccion que la participacion, porque si la unidad no se puede entregar la
 * participacion tiene que desaparecer con ella: una participacion registrada sin
 * premio que en realidad si habia, es un fallo de contabilidad que no se
 * arregla.
 *
 * ============================================================================
 * LO QUE ESTE SERVICIO NO SABE
 * ============================================================================
 *
 * No sabe que reglas tiene la campana, ni si ahora mismo hay un tramo activo, ni
 * si el correo esta activado. De las reglas se encarga el validador inyectado
 * (hito 4), de la hora el reloj, y del correo la configuracion de la campana,
 * que se limitan por los interruptores del esquema. Un motor que empezara a
 * saber de todo eso dejaria de ser la pieza que se puede probar sola.
 *
 * @see \App\Services\ValidadorReglas
 * @see \App\Models\UnidadPremio
 * @see \App\Models\Participacion
 * @see \App\Models\IntentoRechazado
 * @see \App\Core\Db::bloquearPromocion()
 * @see apartado 6 de la especificacion, regla central de adjudicacion
 * @see apartado 9 de la especificacion, una unidad nunca se adjudica dos veces
 * @see casos de aceptacion 3, 4, 5, 6 y 7
 * @see decisiones D1, D4, D8, D9 y D10
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\Db;
use App\Core\ErrorAplicacion;
use App\Core\ErrorValidacion;
use App\Models\Correo;
use App\Models\IntentoRechazado;
use App\Models\Participacion;
use App\Models\UnidadPremio;

/**
 * Adjudicacion de premios bajo la regla central del apartado 6.
 */
class Adjudicador
{
    /**
     * Cuantas veces se busca otra unidad cuando la entrega sale con cero filas.
     *
     * Con el bloqueo de campana tomado este caso no deberia ocurrir nunca: el
     * FOR UPDATE y el GET_LOCK hacen que la fila no pueda cambiar entre la
     * lectura y la entrega. El bucle existe igualmente, y con un numero pequeno
     * de intentos, por dos razones.
     *
     * Una, que si alguien tocara el motor y dejara el GET_LOCK fuera, la
     * adjudicacion doble seguiria siendo imposible gracias al UPDATE condicional,
     * y ademas la campana no se quedaria sin premios.
     *
     * Dos, mas practica: el bucle convierte un fallo en un reintento y no en un
     * error en pantalla delante de una clienta, que es el sitio donde peor
     * queda un fallo.
     *
     * @var int
     */
    private const INTENTOS_DE_ENTREGA = 3;

    /**
     * Validador de reglas que decide si el intento es valido.
     *
     * @var \App\Services\ValidadorReglas
     */
    private ValidadorReglas $validador;

    /**
     * Cola de premios.
     *
     * @var \App\Models\UnidadPremio
     */
    private UnidadPremio $unidades;

    /**
     * Participaciones validas.
     *
     * @var \App\Models\Participacion
     */
    private Participacion $participaciones;

    /**
     * Intentos rechazados.
     *
     * @var \App\Models\IntentoRechazado
     */
    private IntentoRechazado $rechazos;

    /**
     * Cola de correo.
     *
     * @var \App\Models\Correo
     */
    private Correo $correos;

    /**
     * Construye el motor con su validador de reglas.
     *
     * El validador se recibe por el constructor y no se busca por dentro con un
     * instanceof o un nombre de clase, porque de esa forma el hito 2 puede
     * probarse con un validador que rechace a voluntad y el hito 4 solo tiene que
     * cumplir el contrato.
     *
     * @param \App\Services\ValidadorReglas $validador Reglas de la campana.
     */
    public function __construct(ValidadorReglas $validador)
    {
        $this->validador = $validador;
        $this->unidades = new UnidadPremio();
        $this->participaciones = new Participacion();
        $this->rechazos = new IntentoRechazado();
        $this->correos = new Correo();
    }

    /**
     * Registra una participacion y le adjudica el premio que le toque.
     *
     * Devuelve siempre un array con la misma forma, tanto si el intento se ha
     * rechazado, como si no le tocaba premio, como si se ha llevado uno. Que el
     * llamante tenga que distinguir tres casos se comprueba en un solo sitio, y
     * no repartido entre un return, una excepcion y un null.
     *
     * @param int                   $promocionId       Campana de la participacion.
     * @param string                $claveIdempotencia Identificador del intento,
     *                                                 que genera el navegador.
     *                                                 Un doble clic con la misma
     *                                                 clave devuelve el mismo
     *                                                 resultado.
     * @param int|null              $tramoId           Tramo activo en el
     *                                                 momento del intento, o
     *                                                 null si no hay ninguno. Un
     *                                                 intento sin tramo solo
     *                                                 puede acabar en rechazo.
     * @param array<string, mixed>  $datos             Lo que ha escrito la
     *                                                 clienta, ya validado por el
     *                                                 formulario.
     * @param string|null           $claveUnicidad     Huella HMAC de la
     *                                                 identidad, o null si la
     *                                                 campana no deduplica.
     * @param int|null              $usuarioAzafataId  Azafata que lo registra.
     * @param string|null           $momento           Instante real del
     *                                                 intento. Si se omite, se
     *                                                 toma el reloj de la campana.
     *                                                 Se acepta como parametro
     *                                                 para que las pruebas puedan
     *                                                 fijar la hora y comprobar
     *                                                 la cola sin esperar a que
     *                                                 llegue.
     *
     * @return array{resultado: string, participacion_id: int|null, unidad_id: int|null, codigo_reclamacion: string|null, motivo_codigo: string|null, motivo_texto: string|null, repetido: bool, correo_id: int|null}
     *         «premio», «sin_premio» o «rechazada», con la participacion creada
     *         o null en el caso de rechazo, la unidad entregada, el codigo de
     *         reclamacion, el motivo del rechazo y una marca que dice si esto
     *         es la repeticion de un intento ya resuelto.
     *
     * @throws \App\Core\ErrorValidacion    Si la clave de idempotencia no tiene
     *                                      un formato admisible.
     * @throws \App\Core\ErrorAplicacion    Si no se consigue el bloqueo de la
     *                                      campana, o si tras varios intentos no
     *                                      se puede entregar ninguna unidad.
     * @throws \App\Core\ErrorBaseDeDatos  Si alguna consulta falla.
     */
    public function registrar(
        int $promocionId,
        string $claveIdempotencia,
        ?int $tramoId,
        array $datos,
        ?string $claveUnicidad = null,
        ?int $usuarioAzafataId = null,
        ?string $momento = null
    ): array {
        $this->comprobarClaveIdempotencia($claveIdempotencia);

        $momento = $momento ?? Aplicacion::ahora();

        $db = Aplicacion::db();

        // La primera de las tres defensas. Sin este bloqueo, dos peticiones
        // simultaneas podrian leer la misma unidad pendiente.
        //
        // Si no se consigue en los cinco segundos de D8, NO es un error de la
        // aplicacion: es contencion. Se lanza ErrorAplicacion para que la
        // pantalla pueda decir «intente de nuevo» en vez de quedarse esperando, y
        // no se devuelve «sin premio», porque simular que no habia premios
        // cuando los hay seria el peor fallo posible de este servicio.
        if (!$db->bloquearPromocion($promocionId)) {
            throw new ErrorAplicacion(
                'Hay demasiadas participaciones simultaneas en esta campana. Vuelva a intentarlo.'
            );
        }

        // El bloqueo con nombre pertenece a la conexion, no a la transaccion, y
        // no se libera solo al hacer COMMIT. Si una excepcion lo dejara puesto,
        // quedarian TODAS las participaciones de la campana colgadas hasta que
        // la conexion se cerrara. Por eso se libera siempre, y en un finally.
        try {
            return $db->enTransaccion(function () use (
                $promocionId,
                $claveIdempotencia,
                $tramoId,
                $datos,
                $claveUnicidad,
                $usuarioAzafataId,
                $momento
            ): array {
                return $this->resolver(
                    $promocionId,
                    $claveIdempotencia,
                    $tramoId,
                    $datos,
                    $claveUnicidad,
                    $usuarioAzafataId,
                    $momento
                );
            });
        } finally {
            $db->liberarBloqueoPromocion($promocionId);
        }
    }

    /**
     * Resuelve un intento con la campana ya bloqueada y la transaccion abierta.
     *
     * Va en un metodo aparte y no dentro del closure de arriba para que se lea
     * entero de una sentada: es el algoritmo del apartado 6, paso a paso, y
     * mezclarlo con la gestion del bloqueo lo haria mas dificil de comprobar
     * contra el apartado.
     *
     * @param int                  $promocionId       Campana de la participacion.
     * @param string               $claveIdempotencia Identificador del intento.
     * @param int|null             $tramoId           Tramo activo, o null.
     * @param array<string, mixed> $datos             Datos de la participacion.
     * @param string|null          $claveUnicidad     Huella de identidad, o null.
     * @param int|null             $usuarioAzafataId  Azafata que lo registra.
     * @param string               $momento           Instante real del intento.
     *
     * @return array{resultado: string, participacion_id: int|null, unidad_id: int|null, codigo_reclamacion: string|null, motivo_codigo: string|null, motivo_texto: string|null, repetido: bool, correo_id: int|null}
     *         Resultado del intento, con la forma que documenta registrar().
     *
     * @throws \App\Core\ErrorAplicacion   Si no hay tramo activo, o si no se
     *                                     puede entregar ninguna unidad.
     * @throws \App\Core\ErrorBaseDeDatos Si alguna consulta falla, incluido el
     *                                    caso de que la clave de unicidad ya
     *                                    este en uso por otra participacion.
     */
    private function resolver(
        int $promocionId,
        string $claveIdempotencia,
        ?int $tramoId,
        array $datos,
        ?string $claveUnicidad,
        ?int $usuarioAzafataId,
        string $momento
    ): array {
        // ---- 1. ¿Es un intento que ya se resolvio? ---------------------------
        // El caso de aceptacion 7. Va primero, antes de validar y antes de
        // mirar la cola, porque si el intento ya tiene respuesta, la respuesta
        // que tiene es la correcta y no hay nada que decidir.
        $previa = $this->buscarIntentoResuelto($promocionId, $claveIdempotencia);

        if ($previa !== null) {
            $previa['repetido'] = true;
            return $previa;
        }

        // ---- 2. ¿Cumple las reglas? -----------------------------------------
        // La garantia del caso de aceptacion 5 vive aqui: si el validador dice
        // que no, se sale por una rama que no ha tocado unidades_premio, y por
        // tanto no puede haber consumido un premio aunque lo quiera.
        $intento = [
            'promocion_id'       => $promocionId,
            'tramo_id'           => $tramoId,
            'clave_idempotencia' => $claveIdempotencia,
            'clave_unicidad'     => $claveUnicidad,
            'momento'            => $momento,
            'datos'              => $datos,
            'usuario_azafata_id' => $usuarioAzafataId,
        ];

        $rechazo = $this->validador->validar($intento);

        if ($rechazo !== null) {
            $this->rechazos->crear(
                $promocionId,
                $claveIdempotencia,
                $momento,
                (string) ($rechazo['codigo'] ?? 'sin_detalle'),
                (string) ($rechazo['texto'] ?? 'Su participacion no es valida.'),
                $tramoId,
                $claveUnicidad,
                $usuarioAzafataId
            );

            return [
                'resultado'           => 'rechazada',
                'participacion_id'    => null,
                'unidad_id'           => null,
                'codigo_reclamacion'  => null,
                'motivo_codigo'       => (string) ($rechazo['codigo'] ?? 'sin_detalle'),
                'motivo_texto'        => (string) ($rechazo['texto'] ?? 'Su participacion no es valida.'),
                'repetido'            => false,
                'correo_id'           => null,
            ];
        }

        $plantilla = $this->datosDeLaCampana($promocionId);

        // ---- 3. Modo simulacion ---------------------------------------------
        // El esquema dice que en modo simulacion las participaciones «se procesan
        // de verdad pero se marcan como simuladas y no consumen premios reales ni
        // envian correo». Por eso aqui no se busca ninguna unidad: la
        // participacion se registra, y su resultado es el que le habria
        // correspondido, pero sin tocar la cola. Es lo que permite al
        // administrador ensayar una campana entera sin gastarse los premios.
        if ((int) $plantilla['modo_simulacion'] === 1) {
            $simulada = $this->registrarParticipacion(
                $promocionId,
                $tramoId,
                $claveIdempotencia,
                $momento,
                Participacion::RESULTADO_SIN_PREMIO,
                $datos,
                $claveUnicidad,
                $usuarioAzafataId,
                true
            );

            $this->anotarAuditoria(
                $promocionId,
                'participacion',
                (string) $simulada,
                'simulacion',
                $usuarioAzafataId
            );

            return [
                'resultado'          => Participacion::RESULTADO_SIN_PREMIO,
                'participacion_id'   => $simulada,
                'unidad_id'          => null,
                'codigo_reclamacion' => null,
                'motivo_codigo'      => null,
                'motivo_texto'       => null,
                'repetido'           => false,
                'correo_id'          => null,
            ];
        }

        // ---- 4. La cola de premios -------------------------------------------
        // A partir de aqui, y solo aqui, se toca la cola. Cada intento que llega
        // a este punto consume, como mucho, una unidad.
        for ($intentoDeEntrega = 1; $intentoDeEntrega <= self::INTENTOS_DE_ENTREGA; $intentoDeEntrega++) {
            $unidad = $this->unidades->primeraPendiente($promocionId, $momento);

            // ---- 4a. No queda ningun premio pendiente ------------------------
            if ($unidad === null) {
                $participacionId = $this->registrarParticipacion(
                    $promocionId,
                    $tramoId,
                    $claveIdempotencia,
                    $momento,
                    Participacion::RESULTADO_SIN_PREMIO,
                    $datos,
                    $claveUnicidad,
                    $usuarioAzafataId
                );

                // El correo de «no ha salido premio» se encola tambien dentro de
                // la transaccion, aunque todavia no se haya enviado nada. Ver D1.
                $correoId = $this->correos->encolar(
                    $promocionId,
                    $participacionId,
                    Correo::TIPO_NO_GANADOR,
                    $plantilla,
                    $this->valoresPara($datos, null, null, $plantilla)
                );

                $this->anotarAuditoria(
                    $promocionId,
                    'participacion',
                    (string) $participacionId,
                    'sin_premio',
                    $usuarioAzafataId
                );

                return [
                    'resultado'          => Participacion::RESULTADO_SIN_PREMIO,
                    'participacion_id'   => $participacionId,
                    'unidad_id'          => null,
                    'codigo_reclamacion' => null,
                    'motivo_codigo'      => null,
                    'motivo_texto'       => null,
                    'repetido'           => false,
                    'correo_id'          => $correoId,
                ];
            }

            // ---- 4b. Hay una unidad pendiente --------------------------------
            // Se registra la participacion ANTES de entregar la unidad, porque
            // unidades_premio.participacion_id necesita su identificador. Las dos
            // cosas van en la misma transaccion, asi que el orden no importa para
            // quien las ve: o se ven las dos, o no se ve ninguna.
            $participacionId = $this->registrarParticipacion(
                $promocionId,
                $tramoId,
                $claveIdempotencia,
                $momento,
                Participacion::RESULTADO_PREMIO,
                $datos,
                $claveUnicidad,
                $usuarioAzafataId
            );

            $codigo = $this->unidades->generarCodigoReclamacion();

            $filas = $this->unidades->entregar(
                (int) $unidad['id'],
                $participacionId,
                $momento,
                $codigo
            );

            // La tercera de las tres defensas. Un cero significa que otro
            // proceso se ha adelantado y ya se llevo esa unidad. No es un error:
            // se deshace la participacion que se acaba de crear y se vuelve a
            // buscar, ahora con la siguiente unidad de la cola. Lo que no se
            // puede es dejar la participacion registrada, porque quedaria una
            // participacion con resultado «premio» sin ninguna unidad, y eso ya
            // es contabilidad que no cuadra.
            if ($filas !== 1) {
                $this->deshacerParticipacion($participacionId);
                continue;
            }

            $correoId = $this->correos->encolar(
                $promocionId,
                $participacionId,
                Correo::TIPO_GANADOR,
                $plantilla,
                $this->valoresPara($datos, $codigo, (int) $unidad['tipo_premio_id'], $plantilla)
            );

            $this->anotarAuditoria(
                $promocionId,
                'unidades_premio',
                (string) $unidad['id'],
                'adjudicacion',
                $usuarioAzafataId
            );

            return [
                'resultado'          => Participacion::RESULTADO_PREMIO,
                'participacion_id'   => $participacionId,
                'unidad_id'          => (int) $unidad['id'],
                'codigo_reclamacion' => $codigo,
                'motivo_codigo'      => null,
                'motivo_texto'       => null,
                'repetido'           => false,
                'correo_id'          => $correoId,
            ];
        }

        // Se ha llegado hasta aqui con una unidad delante y sin poder
        // entregarla. Con el bloqueo de campana tomado no deberia pasar, y por
        // eso es un error y no un «sin premio»: fingir que no habia premio
        // cuando lo habia y no se ha podido entregar es como se pierde un
        // premio sin que nadie lo sepa.
        throw new ErrorAplicacion(
            'No se ha podido adjudicar ninguna unidad. Vuelva a intentarlo.'
        );
    }

    /**
     * Busca si un intento ya se resolvio antes, y devuelve su resultado.
     *
     * Mira en las dos tablas, en la que corresponda. Una participacion valida y un
     * rechazo son respuestas distintas al mismo tipo de peticion, y el motor no
     * sabe cual de las dos se produzco, asi que las dos se consultan.
     *
     * Se consulta primero la participacion, y el rechazo despues, porque lo
     * normal es que lo que exista sea una participacion: la consulta que acierta
     * es la que se hace primero, y la del rechazo solo se ejecuta cuando la
     * primera no ha encontrado nada. Un intento rechazado se detecta igual, por
     * el camino mas largo, que es un precio aceptable para un caso raro.
     *
     * @param int    $promocionId       Campana del intento.
     * @param string $claveIdempotencia Clave del intento.
     *
     * @return array<string, mixed>|null Resultado ya resuelto, o null si el
     *                                   intento es nuevo.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    private function buscarIntentoResuelto(int $promocionId, string $claveIdempotencia): ?array
    {
        $participacion = $this->participaciones->porClaveIdempotencia($promocionId, $claveIdempotencia);

        if ($participacion !== null) {
            // La unidad y el codigo de reclamacion se leen de la unidad que
            // quedo asociada. Si la participacion es «sin premio», no habra
            // ninguna, y por eso se dejan en null en vez de inventarlos.
            $unidadId = null;
            $codigo = null;

            $unidad = $this->db()->uno(
                'SELECT id, codigo_reclamacion
                   FROM unidades_premio
                  WHERE participacion_id = ?
                  LIMIT 1',
                [(int) $participacion['id']]
            );

            if ($unidad !== null) {
                $unidadId = (int) $unidad['id'];
                $codigo = (string) $unidad['codigo_reclamacion'];
            }

            return [
                'resultado'          => (string) $participacion['resultado'],
                'participacion_id'   => (int) $participacion['id'],
                'unidad_id'          => $unidadId,
                'codigo_reclamacion' => $codigo,
                'motivo_codigo'      => null,
                'motivo_texto'       => null,
                'repetido'           => false,
                'correo_id'          => null,
            ];
        }

        $rechazo = $this->rechazos->porClaveIdempotencia($promocionId, $claveIdempotencia);

        if ($rechazo === null) {
            return null;
        }

        return [
            'resultado'          => 'rechazada',
            'participacion_id'   => null,
            'unidad_id'          => null,
            'codigo_reclamacion' => null,
            'motivo_codigo'      => (string) $rechazo['motivo_codigo'],
            'motivo_texto'       => (string) $rechazo['motivo_texto'],
            'repetido'           => false,
            'correo_id'          => null,
        ];
    }

    /**
     * Registra una participacion valida con el resultado ya decidido.
     *
     * @param int                  $promocionId       Campana de la participacion.
     * @param int|null             $tramoId           Tramo activo, o null.
     * @param string               $claveIdempotencia Clave del intento.
     * @param string               $momento           Instante real.
     * @param string               $resultado         RESULTADO_PREMIO o
     *                                                 RESULTADO_SIN_PREMIO.
     * @param array<string, mixed> $datos             Datos de la participacion.
     * @param string|null          $claveUnicidad     Huella de identidad, o null.
     * @param int|null             $usuarioAzafataId  Azafata que lo registra.
     * @param bool                 $simulacion        True si la campana esta en
     *                                                 modo simulacion.
     *
     * @return int Identificador de la participacion creada.
     *
     * @throws \App\Core\ErrorAplicacion  Si no hay tramo y aun asi se intenta
     *                                    registrar la participacion.
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    private function registrarParticipacion(
        int $promocionId,
        ?int $tramoId,
        string $claveIdempotencia,
        string $momento,
        string $resultado,
        array $datos,
        ?string $claveUnicidad,
        ?int $usuarioAzafataId,
        bool $simulacion = false
    ): int {
        // Sin tramo no se puede registrar una participacion: la columna no admite
        // null. Si se llega aqui sin tramo es que el validador ha dado el visto
        // bueno a un intento fuera de horario, y eso es un fallo del validador, no
        // una situacion que se pueda registrar. Se dice con un mensaje claro en
        // vez de dejar que reviente la restriccion de la base.
        if ($tramoId === null) {
            throw new ErrorAplicacion(
                'Se ha validado una participacion sin tramo activo. Revise las reglas de la campana.'
            );
        }

        return $this->participaciones->crear(
            $promocionId,
            $tramoId,
            $claveIdempotencia,
            $momento,
            $resultado,
            $datos,
            $claveUnicidad,
            $usuarioAzafataId,
            $simulacion
        );
    }

    /**
     * Borra una participacion creada en este mismo intento de entrega.
     *
     * Solo se usa en el camino en que la entrega de la unidad sale con cero
     * filas, y siempre dentro de la transaccion, de modo que el DELETE no llega a
     * verse nunca desde fuera: si despues la entrega siguiente tiene exito y se
     * confirma, lo unico que sobrevive es la participacion buena; si la
     * transaccion entera se deshace, no sobrevive ninguna.
     *
     * La alternativa habria sido una sentencia de guardado o un UPDATE para dar
     * la participacion por anulada, y las dos son peores: dejan filas que el
     * panel de seguimiento contaria como participaciones validas de una clienta
     * que en realidad no participo.
     *
     * @param int $participacionId Participacion a borrar.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si el borrado falla.
     */
    private function deshacerParticipacion(int $participacionId): void
    {
        $this->db()->ejecutar('DELETE FROM participaciones WHERE id = ?', [$participacionId]);
    }

    /**
     * Anota una entrada en la tabla de auditoria.
     *
     * El apartado 7 pide que la auditoria cubra las adjudicaciones, y el panel de
     * seguimiento mostrara despues el historial de cada unidad. El nombre del
     * usuario se copia ademas del identificador, porque la cuenta puede
     * desactivarse o borrarse y un registro que apunta a un usuario que ya no
     * existe se queda sin decir quien fue.
     *
     * @param int         $promocionId Campana de la que es el registro.
     * @param string      $entidad     Tabla o elemento afectado.
     * @param string      $entidadId   Identificador del elemento, como texto.
     * @param string      $accion      Que se ha hecho, en pasado y en corto.
     * @param int|null    $usuarioId   Usuario que lo ha hecho, o null.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    private function anotarAuditoria(
        int $promocionId,
        string $entidad,
        string $entidadId,
        string $accion,
        ?int $usuarioId
    ): void {
        $nombre = '';

        if ($usuarioId !== null) {
            $nombre = (string) $this->db()->valor(
                'SELECT nombre FROM usuarios WHERE id = ? LIMIT 1',
                [$usuarioId]
            );
        }

        $this->db()->ejecutar(
            'INSERT INTO auditoria (
                 promocion_id, usuario_id, usuario_nombre, entidad, entidad_id,
                 accion, datos_antes, datos_despues, ip, creado_en
             ) VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
            [
                $promocionId,
                $usuarioId,
                $nombre,
                $entidad,
                $entidadId,
                $accion,
                Aplicacion::ipDeLaPeticion(),
                Aplicacion::ahora(),
            ]
        );
    }

    /**
     * Devuelve los datos de configuracion de la campana que necesita el motor.
     *
     * Se leen una sola vez por intento, y dentro de la transaccion, para que el
     * motor decida con el mismo estado de campana que va a tener la adjudicacion
     * que acaba de hacer.
     *
     * @param int $promocionId Campana que se quiere leer.
     *
     * @return array<string, mixed> Fila de la tabla promociones.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la campana no existe o la consulta
     *                                    falla.
     */
    private function datosDeLaCampana(int $promocionId): array
    {
        $campana = $this->db()->uno(
            'SELECT id, nombre, modo_simulacion, correo_ganador, correo_no_ganador,
                    correo_ganador_asunto, correo_ganador_cuerpo,
                    correo_no_ganador_asunto, correo_no_ganador_cuerpo
               FROM promociones
              WHERE id = ?
              LIMIT 1',
            [$promocionId]
        );

        if ($campana === null) {
            throw new ErrorAplicacion(
                "La campana «{$promocionId}» no existe. No se puede participar en una campana inexistente."
            );
        }

        return $campana;
    }

    /**
     * Prepara los valores con los que se sustituyen los marcadores del correo.
     *
     * Solo entran los marcadores de la lista blanca de \App\Models\Correo. El
     * nombre del premio se resuelve con una consulta a tipos_premio, y se hace
     * aqui y no en el modelo de correo porque el correo no deberia saber de
     * donde salen los datos de la campana.
     *
     * La direccion de correo se anade aparte, y bajo el nombre «correo», porque no
     * es un marcador: no se sustituye en el texto, que es justo lo que se quiere,
     * sino que la usa \App\Models\Correo para saber a quien va dirigido el
     * mensaje. Se copia con el mismo criterio del que usa alli, que busca
     * «correo», «email» o «e_mail», porque el nombre del campo lo decide el
     * administrador al configurar el formulario.
     *
     * @param array<string, mixed>  $datos          Datos de la participacion.
     * @param string|null           $codigo         Codigo de reclamacion, o null
     *                                             si no ha habido premio.
     * @param int|null              $tipoPremioId   Tipo de premio entregado, o
     *                                             null.
     * @param array<string, mixed>  $campana        Fila de la campana.
     *
     * @return array<string, string> Valores para los marcadores.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta del nombre del premio
     *                                    falla.
     */
    private function valoresPara(
        array $datos,
        ?string $codigo,
        ?int $tipoPremioId,
        array $campana
    ): array {
        $valores = [
            'nombre'    => (string) ($datos['nombre'] ?? ''),
            'promocion' => (string) ($campana['nombre'] ?? ''),
        ];

        if ($codigo !== null) {
            $valores['codigo'] = $codigo;
        }

        if ($tipoPremioId !== null) {
            $premio = $this->db()->valor(
                'SELECT nombre FROM tipos_premio WHERE id = ? LIMIT 1',
                [$tipoPremioId]
            );

            $valores['premio'] = (string) ($premio ?? '');
        }

        // El correo NO se anade a la lista de marcadores del modelo de correo, de
        // modo que nunca aparece en el texto. Solo viaja en el array para que el
        // modelo sepa a quien escribir.
        foreach (['correo', 'email', 'e_mail'] as $clave) {
            if (isset($datos[$clave]) && trim((string) $datos[$clave]) !== '') {
                $valores['correo'] = trim((string) $datos[$clave]);
                break;
            }
        }

        return $valores;
    }

    /**
     * Comprueba que una clave de idempotencia tenga un formato admisible.
     *
     * La columna es un CHAR(36) y lo que se espera es un identificador del tipo
     * UUID. Se comprueba el formato en vez de fiarse del navegador porque el
     * navegador es el cliente y no se le presupone nada: un valor con comillas o
     * con un punto y coma aqui seria un intento de inyeccion dirigido al primer
     * sitio donde se use, y porque se comprueba antes de tocar la base de datos,
     * ese intento falla con un mensaje de validacion y no con un error de SQL.
     *
     * @param string $clave Clave recibida.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si el formato no es admisible.
     */
    private function comprobarClaveIdempotencia(string $clave): void
    {
        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $clave) !== 1) {
            throw new ErrorValidacion(
                'El identificador del intento no tiene un formato valido. Vuelva a abrir la pantalla.'
            );
        }
    }

    /**
     * Devuelve la conexion de base de datos del modelo.
     *
     * Los modelos tienen la conexion en una propiedad protegida y el motor, que
     * es una clase y no un modelo, no puede verla. Este metodo es el puente, y
     * existe para que las cuatro consultas que el motor necesita hacer y que no
     * son de su propio modelo (la unidad ya adjudicada, la campana, el nombre
     * del premio, la auditoria) no se metan en un modelo que no les corresponde.
     *
     * @return \App\Core\Db Conexion de la aplicacion.
     */
    private function db(): Db
    {
        return Aplicacion::db();
    }
}
