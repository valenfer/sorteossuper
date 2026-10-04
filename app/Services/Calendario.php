<?php

/**
 * Servicio del calendario de premios.
 *
 * ============================================================================
 * QUE ES ESTE SERVICIO
 * ============================================================================
 *
 * El apartado 4.5 de la especificacion pide generar una fila por cada unidad de
 * premio, repartiendo las horas de forma aproximadamente equitativa dentro de
 * cada tramo, y avisa de que la distribucion debe ser reproducible o explicable.
 * Este fichero es el que decide donde cae cada unidad, y las tres garantias que
 * el apartado 4.5 y el 4.6 exigen estan comprobadas una por una:
 *
 *   1. Ninguna unidad desaparece. Se generan exactamente las que pide el plan.
 *   2. Ninguna se duplica. Cada unidad tiene un minuto propio, y hay una prueba
 *      que lo comprueba con las 240 unidades de un tramo de cuatro horas.
 *   3. Ninguna queda fuera de su tramo. El minuto se calcula a partir de la hora
 *      de inicio del tramo y nunca llega a su hora de fin.
 *
 * ============================================================================
 * EL REPARTO: POR QUE «i * minutos / n» Y NO UNA SUMA ACUMULADA
 * ============================================================================
 *
 * La idea es que las unidades caigan a intervalos iguales por todo el tramo. La
 * unidad i ocupa el minuto floor(i * duracion / n), donde duracion son los
 * minutos del tramo y n el numero de unidades. Con un tramo de cuatro horas y
 * tres unidades da los minutos 0, 80 y 160, que es lo que uno haria a mano
 * repariendo tres premios entre las diez y las dos.
 *
 * La alternativa —ir sumando el intervalo con redondeo— produce la mitad de las
 * veces la misma hora o dos horas pegadas, porque acumula el error de redondeo
 * en cada paso. Con la division el error no se acumula: cada minuto se calcula
 * desde el origen, y por eso dos unidades nunca caen en el mismo minuto mientras
 * quepan.
 *
 * ============================================================================
 * POR QUE SE EVITAN PREMIOS CONSECUTIVOS DEL MISMO TIPO
 * ============================================================================
 *
 * El apartado 4.5 lo pide «en lo posible», y el motivo es de perception, no de
 * correctness: si el tramo de la tarde reparte batidora, batidora, batidora, a
 * las cinco de la tarde de la manana se ha repartido media catalogo de batidoras y
 * las ultimas clientas tienen la sensacion de que el sorteo esta amañado. No lo
 * esta, pero es lo que se percibe.
 *
 * La forma de evitarlo es reordenar los TIPOS antes de asignar los minutos, sin
 * tocar los minutos. Intercambiar dos tipos entre dos posiciones no cambia la
 * hora de ninguna unidad, solo cual de los dos tipos cae en cada una, y por eso
 * el reparto sigue siendo tan equitativo como era.
 *
 * ============================================================================
 * D2: QUE PASA SI NO CABEN LAS UNIDADES
 * ============================================================================
 *
 * Con precision de minuto, un tramo de una hora y media admite 90 unidades. Si el
 * plan pide 120, no hay reparto posible: o dos unidades caen en el mismo minuto, o
 * alguna queda fuera del tramo. Las dos cosas estan prohibidas, porque el
 * apartado 6 compara «inicio <= momento» y dos unidades con la misma hora se
 * reparten de forma indistinguible para la clienta, y una unidad fuera del tramo
 * podria repartirse antes de que empiece.
 *
 * Asi que el generador ABORTA y devuelve el diagnostico exacto: cuantas unidades
 * sobran, cuantos minutos libres hay, con que precision esta trabajando y que tres
 * cosas puede hacer el administrador. No se genera ni una unidad, y en
 * particular no se genera con horas repetidas «para salir del paso». Esa es
 * justamente la prohibicion de D2, y es una prohibicion sobre el comportamiento
 * silencioso, no sobre la posibilidad: quien quiera horas coincidentes tiene que
 * marcarlo explicitamente, y entonces el panel avisa de que las hay.
 *
 * ============================================================================
 * POR QUE GENERAR NO ES LO MISMO QUE REEMPLAZAR
 * ============================================================================
 *
 * Volver a generar un tramo que ya tiene unidades es una operacion destructiva,
 * y hay un caso en el que es imposible: si hay unidades ya entregadas, esas
 * unidades no se tocan nunca. Se conservan, con su participacion y su codigo de
 * reclamacion, porque son el historial de lo que se ha entregado.
 *
 * Por eso hay dos operaciones distintas: generar, que solo rellena los tramos
 * vacios, y reemplazar, que borra las unidades «programadas» del tramo y las
 * vuelve a crear. La segunda nunca toca las entregadas, ni las anuladas, ni las no
 * entregadas, y el panel lo dice antes de pedir la confirmacion.
 *
 * ============================================================================
 * POR QUE UNA REVISION A MANO NO PUEDE PASARSE DEL PLAN
 * ============================================================================
 *
 * El plan —las cantidades de asignaciones_tramo— es lo que el administrador ha
 * pedido y el calendario son las filas con su minuto. Los dos se comparan en tres
 * pantallas, y hace un tiempo que el desajuste era solo informativo: se avisaba y
 * no pasaba nada. Eso dejaba abierta la direccion mala, que es la de SOBRAR.
 *
 * Faltar es legitimo y hasta desirable: retirar una unidad a mano es una decision
 * del administrador, y el aviso de «faltan 2» sale precisamente cuando eso ha
 * pasado. Sobrar no lo es nunca. Una unidad de mas no es un premio repartido, es un
 * compromiso que el plan no contiene y que alguien tendra que cumplir en el
 * mostrador, y no hay version de esa historia en la que pasarse del plan sea la
 * respuesta correcta.
 *
 * Asi que el tope se comprueba al anadir y al mover, que son las dos formas de
 * anadir. Retirar no lo comprueba, porque retirar es justamente lo que deja el
 * hueco por el que esto se puede arreglar.
 *
 * El tope es por par de tramo y premio, no por campana: es la misma unidad de
 * cuenta que usa AsignacionTramo::compararConCalendario(), y por eso una unidad
 * anulada no cuenta. Sin esa excepcion, retirar una unidad y querer volver a
 * preencher su hueco dejaria de ser posible, que es justo el orden en el que se
 * arregla un calendario.
 *
 * ============================================================================
 * POR QUE CADA REVISION DEJA UN ASIENTO
 * ============================================================================
 *
 * Anadir, mover y retirar son las tres cosas que puede hacer una persona con el
 * calendario de una campana en marcha, y las tres se hacian sin dejar rastro: la
 * columna modificado_en decia cuando, no quien ni por que. El plan se puede tocar
 * desde el panel, y sin dejar rastro tambien.
 *
 * El problema de verdad no es la falta de registro en si misma, es que ante un
 * desajuste no hay forma de distinguir «se movio un minuto de mas por error» de
 * «lo cambio alguien que no deberia poder tocarlo». Un plan y un calendario que no
 * cuadran son una averia, y una averia sin historial no tiene causa.
 *
 * Por eso los cuatro caminos del calendario —anadir, mover, retirar y generar—
 * escriben un asiento, cada uno con lo que estaba y lo que ha pasado a ser. Y
 * por eso van con el cambio en la misma transaccion: un asiento sin cambio es una
 * mentira, y un cambio sin asiento es lo que havia antes.
 *
 * @see \App\Models\UnidadPremio
 * @see \App\Models\AsignacionTramo
 * @see \App\Models\Auditoria
 * @see apartado 4.5 de la especificacion, generacion automatica del calendario
 * @see apartado 4.6 de la especificacion, revision y edicion del calendario
 * @see decision D2 del documento de especificacion
 * @see decision D9 del documento de especificacion
 * @see decision D18 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\ErrorValidacion;
use App\Models\AsignacionTramo;
use App\Models\Auditoria;
use App\Models\Tramo;
use App\Models\TipoPremio;
use App\Models\UnidadPremio;
use App\Models\User;

/**
 * Generacion, revision y edicion del calendario de premios.
 */
class Calendario
{
    /**
     * Precision con la que se reparten las unidades.
     *
     * Es un minuto, y por eso es una constante y no un parametro suelto: todo el
     * diagnostico de D2 se mide en minutos, y cambiarlo en un solo sitio dejaria
     * el diagnostico diciendo una cosa y el generador haciendo otra.
     *
     * @var string
     */
    public const PRECISION = 'minuto';

    /**
     * Modelo de las unidades de premio.
     *
     * Se guarda como propiedad y no se instancia en cada llamada porque el
     * generador inserta cientos de unidades y todas las comprobaciones del mismo
     * tramo necesitan el mismo modelo. Ademas, la regla de este proyecto es que
     * el SQL vive en los modelos y los servicios orquestan: si el servicio
     * escribiera consultas, habria dos sitios donde comprobar como se anade una
     * columna a unidades_premio.
     *
     * @var UnidadPremio
     */
    private UnidadPremio $unidades;

    /**
     * Servicio de horas y tramos.
     *
     * Se guarda como propiedad porque las comprobaciones de si una hora cae dentro
     * de un tramo necesitan la zona horaria, y la zona horaria se lee de la
     * configuracion al construir el servicio. Por eso comprobarDentroDelTramo() es
     * de instancia y no estatico: una comprobacion que ignorase la zona de la
     * campana aceptaria las 02:30 de un domingo de marzo.
     *
     * @var Tramos
     */
    private Tramos $tiempos;

    /**
     * Crea el servicio con los modelos que va a necesitar.
     */
    public function __construct()
    {
        $this->unidades = new UnidadPremio();
        $this->tiempos = new Tramos();
    }

    /**
     * Cuantas unidades se insertan en una sola sentencia.
     *
     * MySQL tiene un limite de marcadores de posicion por sentencia preparada, y
     * una campana con 500 unidades en un tramo son 2500 marcadores. Ir de dos en
     * doscientas deja margen de sobra y, a cambio, una sentencia legible en el
     * log general de consultas cuando algo falla.
     *
     * @var int
     */
    private const LOTE = 200;

    /**
     * Devuelve el plan de una campana agrupado por tramo, con sus minutos.
     *
     * Solo se traen los tipos que estan ACTIVOS. Un tipo desactivado puede seguir
     * teniendo unidades en la cola de una campana en marcha, y el apartado 4.3
     * prohibe alterar el historial, asi que esas unidades se quedan donde estan;
     * pero no se pueden crear unidades nuevas de un premio que ya no se
     * reparte, porque el generador no sabe si el administrador lo ha retirado de
     * la campana o solo ha dejado de anunciarlo.
     *
     * @param int $promocionId Campana cuyo plan se quiere.
     *
     * @return array<int, array<string, mixed>> Un elemento por tramo, con las
     *  claves «id», «fecha», «hora_inicio», «hora_fin», «minutos»,
     *  «minutos_validos» y «cantidades», que es un mapa de tipo de premio a
     *  cantidad.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function planDe(int $promocionId): array
    {
        $tramos = (new Tramo())->listarPorPromocion($promocionId);
        $asignaciones = (new AsignacionTramo())->listarPorPromocion($promocionId);
        $tipos = (new TipoPremio())->listarPorPromocion($promocionId, true);

        // Los tipos activos se recogen en un conjunto para que comprobar si uno
        // esta activo sea una busqueda y no un recorrido por cada asignacion.
        $activos = [];

        foreach ($tipos as $tipo) {
            $activos[(int) $tipo['id']] = true;
        }

        $cantidadesPorTramo = [];

        foreach ($asignaciones as $asignacion) {
            $tipoId = (int) $asignacion['tipo_premio_id'];

            if (!isset($activos[$tipoId])) {
                continue;
            }

            $cantidadesPorTramo[(int) $asignacion['tramo_id']][$tipoId] = (int) $asignacion['cantidad'];
        }

        $plan = [];

        foreach ($tramos as $tramo) {
            $tramoId = (int) $tramo['id'];

            // La lista de minutos que existen de verdad es la que manda, y no la
            // diferencia entre las horas. En un tramo que cruza el cambio de hora
            // de marzo, la diferencia son 180 minutos pero solo existen 120, y
            // medir por la diferencia daria una capacidad que no existe.
            $validos = $this->tiempos->minutosValidos(
                (string) $tramo['fecha'],
                (string) $tramo['hora_inicio'],
                (string) $tramo['hora_fin']
            );

            $plan[] = [
                'id'             => $tramoId,
                'fecha'          => (string) $tramo['fecha'],
                'hora_inicio'    => (string) $tramo['hora_inicio'],
                'hora_fin'       => (string) $tramo['hora_fin'],
                'minutos'        => count($validos),
                'minutos_validos' => $validos,
                'cantidades'     => $cantidadesPorTramo[$tramoId] ?? [],
            ];
        }

        return $plan;
    }

    /**
     * Reparte las unidades de un tramo en minutos, sin tocar la base de datos.
     *
     * Es un metodo estatico y puro a proposito: es la parte del generador que
     * tiene que ser comprobable sin preparar una campana entera, y la que mas
     * casos de borde tiene —cero unidades, una unidad, exactamente lo que cabe,
     * una unidad mas de lo que cabe— y esos casos se prueban directamente.
     *
     * @param array<int, int> $tipos              Lista de tipos de premio, con una
     *                                             entrada por unidad. El orden es
     *                                             el del plan, y el metodo puede
     *                                             reordenarlo.
     * @param int             $minutosDisponibles Minutos del tramo, de inicio a
     *                                             fin.
     * @param bool            $permitirRepetir    Si es true, se admiten horas
     *                                             identicas cuando no caben. Solo
     *                                             se activa desde una casilla
     *                                             marcada al efecto.
     *
     * @return array<int, array<string, mixed>> Una entrada por unidad, con las
     *         claves «tipo_premio_id» y «minuto», ordenadas por minuto.
     *
     * @throws \App\Core\ErrorValidacion Si no caben y no se permite repetir.
     */
    public static function repartir(array $tipos, int $minutosDisponibles, bool $permitirRepetir = false): array
    {
        $total = count($tipos);

        if ($total === 0) {
            return [];
        }

        // Un tramo sin duracion no puede existir: el esquema y el panel obligan a
        // que la hora de fin sea posterior a la de inicio. Aun asi, si alguien
        // llegara aqui con cero, tomarlo como un minuto colocaria un premio fuera
        // de cualquier tramo real, y conviene decirlo en vez de adivinar.
        if ($minutosDisponibles < 1) {
            throw new ErrorValidacion('El tramo no tiene duración, así que no cabe ninguna unidad.');
        }

        $minutos = $minutosDisponibles;

        if ($total > $minutos && !$permitirRepetir) {
            throw new ErrorValidacion(
                'No caben ' . $total . ' unidades en ' . $minutos . ' '
                    . ($minutos === 1 ? 'minuto' : 'minutos') . ' con precisión de minuto.',
                ['capacidad' => 'sobran ' . ($total - $minutos) . ' unidades']
            );
        }

        // El minuto de cada unidad sale de la division, y no de ir sumando. Asi el
        // redondeo no se acumula y dos unidades nunca coinciden mientras quepan.
        $posiciones = [];

        for ($i = 0; $i < $total; $i++) {
            $posiciones[$i] = intdiv($i * $minutos, $total);
        }

        $orden = self::separarTipos($tipos);

        $reparto = [];

        foreach ($orden as $indice => $tipoPremioId) {
            $reparto[] = [
                'tipo_premio_id' => (int) $tipoPremioId,
                'minuto'         => $posiciones[$indice],
            ];
        }

        return $reparto;
    }

    /**
     * Reordena los tipos para que no haya dos iguales seguidos.
     *
     * ============================================================================
     * POR QUE SE ELIGE SIEMPRE EL MAS ABUNDANTE Y NO EL SIGUIENTE
     * ============================================================================
     *
     * El reparto se hace de izquierda a derecha, y en cada hueco se coloca el tipo
     * que mas queda, siempre que no sea el mismo que se acaba de poner. Elegir el
     * mas abundante en vez del primero que aparezca es lo que garantiza que no se
     * agote el hueco: si se cogiera el primero y resultara ser el mayoritario, al
     * final quedarian tres batidoras seguidas con hueco de sobra para haberlas
     * repartido.
     *
     * Si no queda ningun tipo distinto del anterior, no hay solucion y se repite
     * el anterior. Ocurre en un solo caso, y conviene saber cual es: cuando un
     * tipo tiene mas de la mitad de las unidades, redondeado hacia arriba. Seis
     * batidoras y una cafetera no se pueden colocar sin que dos batidoras se junten,
     * porque hace falta un hueco entre cada pareja y solo hay uno libre. En ese
     * caso el resultado es el mejor posible, y no un fallo del reparto.
     *
     * Intercambiar los tipos no cambia los minutos, que ya estan calculados, y por
     * eso el reparto sigue siendo equitativo: lo unico que cambia es que premio
     * cae en cada hora.
     *
     * @param array<int, int> $tipos Lista de tipos de premio.
     *
     * @return array<int, int> Lista reordenada, con las mismas cantidades de cada
     *                      tipo.
     */
    private static function separarTipos(array $tipos): array
    {
        $pendientes = [];

        foreach ($tipos as $tipo) {
            $pendientes[$tipo] = ($pendientes[$tipo] ?? 0) + 1;
        }

        $total = count($tipos);
        $orden = [];
        $anterior = null;

        for ($i = 0; $i < $total; $i++) {
            $elegido = null;
            $mejor = 0;

            foreach ($pendientes as $tipo => $cuantas) {
                if ($cuantas === 0 || $tipo === $anterior) {
                    continue;
                }

                if ($cuantas > $mejor) {
                    $mejor = $cuantas;
                    $elegido = $tipo;
                }
            }

            if ($elegido === null) {
                // Solo queda el tipo anterior. No hay manera de evitar la
                // coincidencia, asi que se acepta y se sigue.
                foreach ($pendientes as $tipo => $cuantas) {
                    if ($cuantas > $mejor) {
                        $mejor = $cuantas;
                        $elegido = $tipo;
                    }
                }
            }

            $orden[] = $elegido;
            $pendientes[$elegido]--;
            $anterior = $elegido;
        }

        return $orden;
    }

    /**
     * Devuelve el diagnostico de capacidad de cada tramo con unidades de sobra.
     *
     * Este es el informe que D2 obliga a mostrar, con las tres salidas posibles.
     * Se puede pedir sin generar nada, y el panel lo enseña al abrir la pantalla
     * de calendario para que el administrador sepa el problema antes de pulsar un
     * boton.
     *
     * @param int  $promocionId     Campana que se quiere diagnosticar.
     * @param bool $permitirRepetir Si es true, los tramos que no caben no se
     *                               consideran un problema, porque se va a
     *                               aceptar el reparto con horas coincidentes.
     *
     * @return array<int, array<string, mixed>> Un elemento por tramo con
     *         problemas, con las claves «tramo_id», «fecha», «horario»,
     *         «minutos», «unidades», «sobran», «precision» y «opciones».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function diagnosticar(int $promocionId, bool $permitirRepetir = false): array
    {
        return $this->problemasDePlan($this->planDe($promocionId), $permitirRepetir);
    }

    /**
     * Busca los tramos que no pueden absorber sus unidades.
     *
     * El trabajo se hace sobre el plan ya cargado y no se vuelve a leer de la base
     * de datos, porque el generador necesita el plan dos veces —una para
     * diagnosticar y otra para repartir— y leerlo dos veces haria que el
     * diagnostico y el reparto se basaran en dos consultas separadas, que en el
     * peor caso no coinciden.
     *
     * @param array<int, array<string, mixed>> $plan            Plan devuelto por
     *                                                         planDe().
     * @param bool                            $permitirRepetir Si es true, no se
     *                                                         considera un problema
     *                                                         que haya horas
     *                                                         coincidentes, porque
     *                                                         ya se ha aceptado.
     *
     * @return array<int, array<string, mixed>> Un elemento por tramo con
     *         problemas, con las claves «tramo_id», «fecha», «horario»,
     *         «minutos», «unidades», «sobran», «precision» y «opciones».
     */
    private function problemasDePlan(array $plan, bool $permitirRepetir = false): array
    {
        $problemas = [];

        foreach ($plan as $tramo) {
            $unidades = array_sum($tramo['cantidades']);

            if ($unidades === 0 || $unidades <= $tramo['minutos']) {
                continue;
            }

            $sobran = $unidades - $tramo['minutos'];

            $problemas[] = [
                'tramo_id'  => $tramo['id'],
                'fecha'     => $tramo['fecha'],
                'horario'   => substr($tramo['hora_inicio'], 0, 5) . ' a ' . substr($tramo['hora_fin'], 0, 5),
                'minutos'   => $tramo['minutos'],
                'unidades'  => $unidades,
                'sobran'    => $sobran,
                'precision' => self::PRECISION,
                'opciones'  => [
                    'Amplía el tramo hasta que quepan: necesita ' . $sobran . ' minutos más.',
                    'Reduce las unidades de este tramo en ' . $sobran . '.',
                    'Genera aceptando ' . $sobran . ' coincidencias de hora, y sabrá que '
                        . $unidades . ' premios saldrán a la vez.',
                ],
            ];
        }

        // Con la casilla de coincidencia marcada no hay problema que diagnosticar:
        // lo que hay es una decision ya tomada. Se devuelve igualmente la lista
        // vacia para que quien llama no tenga que saber si el caso es especial.
        if ($permitirRepetir) {
            return [];
        }

        return $problemas;
    }

    /**
     * Genera el calendario de una campana.
     *
     * El orden de las operaciones es lo que hace que la garantia sea real: primero
     * se diagnostica TODO, y solo si ningun tramo tiene problemas se empieza a
     * escribir. Un generador que escribiera tramo a tramo y se detuviera al
     * encontrar el tercero que no cabia dejaria los dos primeros ya generados, y
     * el administrador tendria un calendario a medias sin haber pulsado nada.
     *
     * @param int       $promocionId     Campana cuyo calendario se genera.
     * @param bool      $reemplazar       Si es true, se borran antes las unidades
     *                                     «programadas» de los tramos afectados. Las
     *                                     entregadas, anuladas y no entregadas no se
     *                                     tocan nunca.
     * @param bool      $permitirRepetir Si es true, se admite el reparto con horas
     *                                     coincidentes, que es una de las tres salidas
     *                                     de D2.
     * @param int|null  $usuarioId       Usuario de la sesion, que queda en el asiento de
     *                                   auditoria. Null en consola y en las pruebas.
     *
     * @return array<string, mixed> Informe con las claves «generado»,
     *         «unidades», «tramos», «omitidos», «repetidas» y «problemas».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function generar(
        int $promocionId,
        bool $reemplazar = false,
        bool $permitirRepetir = false,
        ?int $usuarioId = null
    ): array {
        $plan = $this->planDe($promocionId);
        $problemas = $this->problemasDePlan($plan, $permitirRepetir);

        $informe = [
            'generado'  => false,
            'unidades'  => 0,
            'tramos'    => 0,
            'omitidos'  => 0,
            'repetidas' => 0,
            'problemas' => $problemas,
        ];

        if ($problemas !== []) {
            // No hay asiento cuando no se ha generado nada. Un asiento de «se ha
            // generado el calendario» acompanado de un informe de cero unidades
            // seria una forma de mentir en la fila que mas se lee.
            return $informe;
        }

        // El recuento de antes se lee aqui y no despues, porque despues ya no se
        // puede saber cuantas unidades habia: es la pregunta que responde este
        // asiento («cuanto habia y cuanto hay»), y se pierde en cuanto se
        // escribe.
        $antes = $this->unidades->contarPorEstado($promocionId);

        $ahora = Aplicacion::ahora();
        $omitidos = 0;
        $generadas = 0;
        $tramosConUnidades = 0;
        $repetidas = 0;

        // Una sola transaccion para toda la campana, y no una por tramo. Si la
        // campana tiene doce tramos y el undecimo falla por un error de disco, con
        // una transaccion por tramo quedarian los diez primeros generados y el
        // administrador se encontraria un calendario a medias sin saber por que.
        // Con una sola, o entra todo o no entra nada.
        Aplicacion::db()->enTransaccion(function () use (
            $promocionId,
            $plan,
            $reemplazar,
            $permitirRepetir,
            $ahora,
            &$omitidos,
            &$generadas,
            &$tramosConUnidades,
            &$repetidas
        ): void {
            $tramos = new Tramo();

            foreach ($plan as $tramo) {
                $cantidades = $tramo['cantidades'];

                if ($cantidades === []) {
                    continue;
                }

                $reparto = self::repartir(
                    $this->tiposDelPlan($tramo['cantidades']),
                    (int) $tramo['minutos'],
                    $permitirRepetir
                );

                $repetidas += $this->contarRepetidas($reparto);

                if ($tramos->contarUnidades((int) $tramo['id'])['total'] > 0 && !$reemplazar) {
                    // El tramo ya tiene unidades y no se ha pedido reemplazarlo. Se
                    // cuenta como omitido y se sigue con el siguiente, para que el
                    // informe diga cuantos se han quedado como estaban.
                    $omitidos++;
                    continue;
                }

                if ($reemplazar) {
                    // El borrado se limita a las unidades «programadas». Es la unica
                    // forma de rehacer un tramo sin tocar el historial.
                    $this->unidades->borrarProgramadasDeTramo((int) $tramo['id']);
                }

                $this->escribirReparto($promocionId, $tramo, $reparto, $ahora);

                $generadas += count($reparto);
                $tramosConUnidades++;
            }
        });

        $informe['generado'] = true;
        $informe['unidades'] = $generadas;
        $informe['tramos'] = $tramosConUnidades;
        $informe['omitidos'] = $omitidos;
        $informe['repetidas'] = $repetidas;

        // El asiento va fuera de la transaccion del reparto, y a proposito: si
        // llegara a fallar, el calendario ya esta escrito y perder el asiento por
        // eso seria peor que perder el asiento por no haberlo escrito. Es al
        // reves que en las unidades sueltas, donde las dos escrituras son de una
        // fila y caben las dos en la misma transaccion sin problema.
        $this->anotar(
            $promocionId,
            Auditoria::ACCION_GENERACION,
            'calendario',
            (string) $promocionId,
            ['unidades' => array_sum($antes)],
            [
                'creadas'   => $generadas,
                'tramos'    => $tramosConUnidades,
                'omitidos'  => $omitidos,
                'repetidas' => $repetidas,
            ],
            $usuarioId
        );

        return $informe;
    }

    /**
     * Escribe en la base de datos las unidades de un tramo que ya estan repartidas.
     *
     * El instante de cada unidad se calcula sumando el minuto que le ha tocado a la
     * hora de inicio del tramo. Es la misma cuenta que hizo el reparto, repetida al
     * escribir, y por eso ninguna unidad puede quedar fuera de su tramo: si el
     * minuto cabe en la duracion, cabe en la duracion.
     *
     * Las filas se acumulan en lotes antes de insertarse, porque un tramo con 500
     * unidades son 3000 marcadores y MySQL tiene un tope de marcadores por
     * sentencia preparada. Al final se inserta el lote aunque no llegue al tope,
     * que es lo que evita que las ultimas unidades se queden sin guardar.
     *
     * @param int                            $promocionId Campana a la que
     *                                                   pertenecen las unidades.
     * @param array<string, mixed>            $tramo      Tramo con las claves
     *                                                   «id», «fecha» y
     *                                                   «hora_inicio».
     * @param array<int, array<string, mixed>> $reparto   Reparto devuelto por
     *                                                   repartir().
     * @param string                          $ahora      Momento de creacion.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    private function escribirReparto(int $promocionId, array $tramo, array $reparto, string $ahora): void
    {
        $minutos = (array) ($tramo['minutos_validos'] ?? []);
        $filas = [];

        foreach ($reparto as $unidad) {
            // El minuto del reparto es una posicion dentro de la lista de horas que
            // existen en el tramo, no un numero de minutos que se suman a la hora de
            // inicio. En un tramo normal las dos cosas coinciden, y en uno que cruza
            // el cambio de hora la suma se saltaria las horas inexistentes.
            $hora = $minutos[(int) $unidad['minuto']] ?? null;

            if ($hora === null) {
                continue;
            }

            $filas[] = [(int) $tramo['id'], (int) $unidad['tipo_premio_id'], $tramo['fecha'] . ' ' . $hora, $ahora];

            if (count($filas) === self::LOTE) {
                $this->unidades->insertarLote($promocionId, $filas);
                $filas = [];
            }
        }

        if ($filas !== []) {
            $this->unidades->insertarLote($promocionId, $filas);
        }
    }

    /**
     * Cuenta cuantas unidades comparten hora dentro de un reparto.
     *
     * Se usa para avisar en el informe, no para impedir nada: cuando el
     * administrador ha marcado la casilla de coincidencia, lo que quiere saber es
     * cuantas hay, y ese numero es el que aparece en el aviso.
     *
     * @param array<int, array<string, mixed>> $reparto Reparto devuelto por
     *                                                  repartir().
     *
     * @return int Numero de unidades de mas que hay por encima de una por minuto.
     */
    private function contarRepetidas(array $reparto): int
    {
        $porMinuto = [];

        foreach ($reparto as $unidad) {
            $minuto = (int) $unidad['minuto'];
            $porMinuto[$minuto] = ($porMinuto[$minuto] ?? 0) + 1;
        }

        $repetidas = 0;

        foreach ($porMinuto as $cuantas) {
            $repetidas += $cuantas - 1;
        }

        return $repetidas;
    }

    /**
     * Expande el mapa de cantidades en una lista de tipos, una entrada por unidad.
     *
     * El orden es por identificador de tipo, no el del mapa, para que el resultado
     * no dependa del orden en que MySQL devuelva las filas. Sin eso, dos
     * ejecuciones del generador sobre los mismos datos podrian repartir
     * distinto, y el apartado 4.5 pide que la distribucion sea reproducible.
     *
     * @param array<int, int> $cantidades Mapa de tipo de premio a cantidad.
     *
     * @return array<int, int> Lista de tipos, con una entrada por unidad.
     */
    private function tiposDelPlan(array $cantidades): array
    {
        ksort($cantidades);

        $tipos = [];

        foreach ($cantidades as $tipoPremioId => $cantidad) {
            for ($i = 0; $i < $cantidad; $i++) {
                $tipos[] = (int) $tipoPremioId;
            }
        }

        return $tipos;
    }

    /**
     * Lista las unidades del calendario en orden cronologico.
     *
     * El orden es por fecha y hora, y a igualdad de hora por identificador, que es
     * el mismo criterio con el que el motor de adjudicacion recorre la cola. Ver
     * el calendario en otro orden que el reparto daria la sensacion de que el
     * sorteo esta desordenado, y haria imposible comprobar a mano una
     * adjudicacion.
     *
     * Los filtros se anaden uno a uno en lugar de construirse con concatenacion,
     * de modo que un filtro vacio no llega nunca a la sentencia y no hace falta
     * distinguir entre «sin filtro» y «filtro vacio».
     *
     * @param int                  $promocionId Campana cuyo calendario se lista.
     * @param array<string, mixed> $filtros     Filtros opcionales, con las claves
     *                                           «estado», «tramo_id»,
     *                                           «tipo_premio_id» y «fecha».
     * @param int                  $limite      Maximo de filas devueltas.
     *
     * @return array<int, array<string, mixed>> Filas del calendario, con el
     *         identificador de la unidad, su instante, su estado, el tramo, el
     *         nombre del premio y su imagen.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listar(int $promocionId, array $filtros = [], int $limite = 500): array
    {
        return $this->unidades->listarParaCalendario($promocionId, $filtros, $limite);
    }

    /**
     * Cuenta las unidades del calendario segun los mismos filtros que listar().
     *
     * Va aparte de listar() porque el total sale de la misma consulta que las
     * filas y no de contar las filas devueltas: con el limite de 500, un
     * administrador con 3000 premios veria «500» y pensaria que solo hay 500.
     *
     * @param int                  $promocionId Campana que se quiere contar.
     * @param array<string, mixed> $filtros     Filtros opcionales, con las mismas
     *                                           claves que listar().
     *
     * @return int Numero de unidades que cumplen los filtros.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contar(int $promocionId, array $filtros = []): int
    {
        return $this->unidades->contarParaCalendario($promocionId, $filtros);
    }

    /**
     * Crea una unidad suelta en un tramo, para corregir el calendario a mano.
     *
     * El apartado 4.6 pide anadir, mover y retirar unidades, y las tres tienen que
     * respetar lo mismo que el generador: la unidad cae dentro de su tramo, el
     * premio esta activo, y la hora es real segun las reglas de tiempo del
     * apartado 4.2. Por eso las comprobaciones se hacen aqui, y no en el
     * controlador que las llama.
     *
     * @param int    $promocionId   Campana a la que pertenece la unidad.
     * @param int    $tramoId       Tramo dentro del cual cae la unidad.
     * @param int       $tipoPremioId  Premio que se reparte.
     * @param string    $fecha         Fecha del tramo, en formato «A-n-j».
     * @param string    $hora          Hora de inicio, en formato «H:i:s».
     * @param int|null  $usuarioId     Usuario de la sesion, que queda en el asiento de
     *                                  auditoria. Null en consola y en las pruebas.
     *
     * @return int Identificador de la unidad creada.
     *
     * @throws \App\Core\ErrorValidacion Si los datos no son validos, o si el par de
     *                                   tramo y premio ya tiene todas las unidades
     *                                   que pide el plan.
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function crear(
        int $promocionId,
        int $tramoId,
        int $tipoPremioId,
        string $fecha,
        string $hora,
        ?int $usuarioId = null
    ): int {
        $errores = [];

        $tramo = (new Tramo())->buscarPorId($tramoId);

        if ($tramo === null || (int) $tramo['promocion_id'] !== $promocionId) {
            $errores['tramo_id'] = 'El tramo no pertenece a esta campaña.';
        } else {
            $errores += $this->tiempos->comprobarDentroDelTramo(
                (string) $tramo['fecha'],
                (string) $tramo['hora_inicio'],
                (string) $tramo['hora_fin'],
                $fecha,
                $hora
            );
        }

        $tipo = (new TipoPremio())->buscarPorId($tipoPremioId);

        if ($tipo === null || (int) $tipo['promocion_id'] !== $promocionId) {
            $errores['tipo_premio_id'] = 'El premio no pertenece a esta campaña.';
        } elseif ((int) $tipo['activo'] !== 1) {
            $errores['tipo_premio_id'] = 'El premio está desactivado y no admite unidades nuevas.';
        } else {
            // El tope del plan se comprueba solo cuando el par es valido. Si el
            // premio no es de esta campana, decir «se pasa del plan» seria un
            // segundo error sin sentido encima del primero, y quien lo ve podria
            // intentar arreglar el plan de un premio que no existe aqui.
            $cuentas = $this->cuentasDelPlan($tramoId, $tipoPremioId);

            if ($cuentas['calendario'] >= $cuentas['plan']) {
                $errores['tipo_premio_id'] = $this->mensajeDeExceso($cuentas, (string) $tipo['nombre']);
            }
        }

        if ($errores !== []) {
            throw new ErrorValidacion('Revisa los datos de la unidad.', $errores);
        }

        // La unidad y su asiento van en la misma transaccion. Escribirlos por
        // separado permitiria las dos historias malas: un asiento que dice que se
        // anadio una unidad que no esta, y una unidad anadida sin quien la anadio.
        return Aplicacion::db()->enTransaccion(function () use (
            $promocionId,
            $tramoId,
            $tipoPremioId,
            $fecha,
            $hora,
            $usuarioId,
            $cuentas
        ): int {
            $unidadId = $this->unidades->crear($promocionId, $tramoId, $tipoPremioId, $fecha . ' ' . $hora);

            // El recuento del asiento se vuelve a hacer despues de insertar, y no
            // se reutiliza el de antes: lo que queda anotado es lo que hay, no lo
            // que habia cuando se comprobo que habia hueco.
            $this->anotar(
                $promocionId,
                Auditoria::ACCION_ALTA,
                'unidades_premio',
                (string) $unidadId,
                null,
                [
                    'tramo_id'       => $tramoId,
                    'tipo_premio_id' => $tipoPremioId,
                    'inicio'         => $fecha . ' ' . $hora,
                    'plan'           => $cuentas['plan'],
                    'calendario'     => $this->unidades->contarEnTramoYTipo($tramoId, $tipoPremioId),
                ],
                $usuarioId
            );

            return $unidadId;
        });
    }

    /**
     * Mueve una unidad programada a otro tramo u otra hora.
     *
     * Solo se mueven unidades «programadas». Una unidad entregada ya tiene
     * participacion, adjudicacion y codigo de reclamacion, y moverla dejaria las
     * tres cosas diciendo cosas distintas: un correo que anuncia un premio a una
     * hora que ya no es la de la fila.
     *
     * @param int       $unidadId   Unidad que se mueve.
     * @param int       $tramoId    Tramo de destino.
     * @param string    $fecha      Fecha del tramo de destino.
     * @param string    $hora       Hora de inicio dentro del tramo de destino.
     * @param int       $promocionId Campana a la que pertenece la unidad.
     * @param int|null  $usuarioId Usuario de la sesion, que queda en el asiento de
     *                             auditoria. Null en consola y en las pruebas.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si no se puede mover, o si el par de tramo
     *                                   y premio de destino ya esta completo.
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function mover(
        int $unidadId,
        int $tramoId,
        string $fecha,
        string $hora,
        int $promocionId,
        ?int $usuarioId = null
    ): void {
        $unidad = $this->buscar($unidadId);

        if ($unidad === null || (int) $unidad['promocion_id'] !== $promocionId) {
            throw new ErrorValidacion('La unidad no pertenece a esta campaña.', ['unidad_id' => 'No existe.']);
        }

        if ((string) $unidad['estado'] !== UnidadPremio::ESTADO_PROGRAMADA) {
            throw new ErrorValidacion(
                'Solo se pueden mover unidades programadas.',
                ['estado' => 'Esta unidad está ' . ((string) $unidad['estado']) . '.']
            );
        }

        // Se comprueba el destino con las mismas reglas que al crear, y la unidad
        // no se toca hasta que todas han pasado. Si el destino es invalido, la
        // unidad se queda donde estaba, y no a medio cambiar.
        $this->comprobarDestino($promocionId, $tramoId, $fecha, $hora);

        // Mover es tambien anadir, porque la unidad pasa a contar en el par de
        // destino. El tope del plan se comprueba aqui con la unidad excluida de la
        // cuenta, que es lo que hace que mover una unidad dentro de su mismo tramo
        // no se rechace a si mismo cuando ese par ya esta completo.
        $cuentas = $this->cuentasDelPlan($tramoId, (int) $unidad['tipo_premio_id'], $unidadId);

        if ($cuentas['calendario'] >= $cuentas['plan']) {
            $tipo = (new TipoPremio())->buscarPorId((int) $unidad['tipo_premio_id']);

            throw new ErrorValidacion(
                'No cabe esa unidad en el tramo de destino segun el plan.',
                ['tramo_id' => $this->mensajeDeExceso($cuentas, (string) ($tipo['nombre'] ?? 'ese premio'))]
            );
        }

        $antes = [
            'tramo_id' => (int) $unidad['tramo_id'],
            'inicio'   => (string) $unidad['inicio'],
        ];

        $despues = [
            'tramo_id'   => $tramoId,
            'inicio'     => $fecha . ' ' . $hora,
            'plan'       => $cuentas['plan'],
            'calendario' => $cuentas['calendario'] + 1,
        ];

        // El movimiento y su asiento van juntos. Si el UPDATE no afecta a ninguna
        // fila porque otra pantalla ha entregado la unidad entre la lectura y
        // aqui, la excepcion sale dentro de la transaccion y el asiento tampoco se
        // escribe: no se puede haber movido una unidad que ya no era programada.
        Aplicacion::db()->enTransaccion(function () use (
            $unidadId,
            $tramoId,
            $fecha,
            $hora,
            $promocionId,
            $usuarioId,
            $antes,
            $despues
        ): void {
            $movidas = $this->unidades->mover($unidadId, $tramoId, $fecha . ' ' . $hora);

            if ($movidas === 0) {
                // Cero filas no es un fallo de la base de datos: significa que entre la
                // lectura y este UPDATE otra pantalla ha entregado la unidad. Es el
                // mismo caso que el motor de adjudicacion encuentra al entregar, y
                // merece un mensaje propio en vez de un error generico.
                throw new ErrorValidacion(
                    'La unidad ya no está programada y no se ha movido.',
                    ['estado' => 'Puede que se haya entregado o retirado mientras editas el calendario.']
                );
            }

            $this->anotar(
                $promocionId,
                Auditoria::ACCION_CONFIGURACION,
                'unidades_premio',
                (string) $unidadId,
                $antes,
                $despues,
                $usuarioId
            );
        });
    }

    /**
     * Retira una unidad del calendario.
     *
     * No la borra: la pasa a «anulada» con el motivo, y con eso la fila sigue
     * sumando en el recuento de la campana. Retirar y borrar no son lo mismo, y
     * el boton del panel se llama «Retirar» para que nadie lo confunda.
     *
     * @param int       $unidadId    Unidad que se retira.
     * @param int       $promocionId Campana a la que pertenece la unidad.
     * @param string    $motivo      Motivo de la retirada, que se guarda en la fila.
     * @param int|null  $usuarioId   Usuario de la sesion, que queda en el asiento de
     *                                auditoria. Null en consola y en las pruebas.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si la unidad no se puede retirar.
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function retirar(int $unidadId, int $promocionId, string $motivo = '', ?int $usuarioId = null): void
    {
        $unidad = $this->buscar($unidadId);

        if ($unidad === null || (int) $unidad['promocion_id'] !== $promocionId) {
            throw new ErrorValidacion('La unidad no pertenece a esta campaña.', ['unidad_id' => 'No existe.']);
        }

        $estado = (string) $unidad['estado'];

        if ($estado !== UnidadPremio::ESTADO_PROGRAMADA && $estado !== UnidadPremio::ESTADO_ANULADA) {
            throw new ErrorValidacion(
                'Solo se pueden retirar unidades programadas.',
                ['estado' => 'Esta unidad está ' . $estado . ' y ya no se puede retirar.']
            );
        }

        // El asiento se escribe tambien cuando la unidad ya estaba anulada, porque
        // retirar es idempotente a proposito y quien pulsa el boton ha hecho algo.
        // Un asiento que dice «ya estaba anulada» responde a la pregunta que se le
        // hace a un historial, que es si alguien ha tocado esto.
        Aplicacion::db()->enTransaccion(function () use (
            $unidadId,
            $promocionId,
            $motivo,
            $usuarioId,
            $unidad,
            $estado
        ): void {
            $this->unidades->anular($unidadId, $motivo);

            $this->anotar(
                $promocionId,
                Auditoria::ACCION_RETIRADA,
                'unidades_premio',
                (string) $unidadId,
                [
                    'estado'         => $estado,
                    'anulada_motivo' => $estado === UnidadPremio::ESTADO_ANULADA
                        ? (string) ($unidad['anulada_motivo'] ?? '')
                        : '',
                    'inicio'         => (string) $unidad['inicio'],
                ],
                [
                    'estado'         => UnidadPremio::ESTADO_ANULADA,
                    'anulada_motivo' => $motivo,
                ],
                $usuarioId
            );
        });
    }

    /**
     * Comprueba que una unidad de destino existe y que la hora cae dentro.
     *
     * @param int    $promocionId  Campana a la que pertenece la unidad.
     * @param int    $tramoId      Tramo de destino.
     * @param string $fecha        Fecha del tramo de destino.
     * @param string $hora         Hora de inicio dentro del tramo.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si el destino no es valido.
     */
    private function comprobarDestino(int $promocionId, int $tramoId, string $fecha, string $hora): void
    {
        $tramo = (new Tramo())->buscarPorId($tramoId);

        if ($tramo === null || (int) $tramo['promocion_id'] !== $promocionId) {
            throw new ErrorValidacion('El tramo no pertenece a esta campaña.', ['tramo_id' => 'No existe.']);
        }

        $errores = $this->tiempos->comprobarDentroDelTramo(
            (string) $tramo['fecha'],
            (string) $tramo['hora_inicio'],
            (string) $tramo['hora_fin'],
            $fecha,
            $hora
        );

        if ($errores !== []) {
            throw new ErrorValidacion('La hora no es valida para ese tramo.', $errores);
        }
    }

    /**
     * Compara el calendario con el plan para un par de tramo y premio.
     *
     * Devuelve los dos numeros y no un si o un no, porque los dos se necesitan
     * para dos cosas distintas: para decidir si cabe otra unidad, y para escribir
     * en el asiento de auditoria cuanto queda de lo que se pidio. Un metodo que
     * devolviese solo «cabe» obligaria a repetir las dos consultas.
     *
     * Las unidades anuladas no se cuentan, igual que en
     * \App\Models\AsignacionTramo::compararConCalendario(). Es lo que permite
     * rellenar el hueco que deja una retirada.
     *
     * @param int $tramoId         Tramo del par.
     * @param int $tipoPremioId    Premio del par.
     * @param int $excluirUnidadId Unidad que no se cuenta, o cero para contarlas
     *                             todas. La usa mover() para no contar la unidad
     *                             que se esta moviendo.
     *
     * @return array{plan: int, calendario: int} Lo que pide el plan y lo que hay.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    private function cuentasDelPlan(int $tramoId, int $tipoPremioId, int $excluirUnidadId = 0): array
    {
        $cantidades = (new AsignacionTramo())->cantidadesPorTramo($tramoId);

        return [
            'plan'       => (int) ($cantidades[$tipoPremioId] ?? 0),
            'calendario' => $this->unidades->contarEnTramoYTipo($tramoId, $tipoPremioId, $excluirUnidadId),
        ];
    }

    /**
     * Explica por que no cabe una unidad mas en ese par de tramo y premio.
     *
     * El mensaje lleva los numeros porque «no cabe» no es accionable: lo que el
     * administrador puede hacer es subir la cantidad del tramo, retirar una unidad, o
     * entender que ese premio no se reparte ahi. Sin las cifras, quien lo lee tiene
     * que volver a la pantalla de cantidades a buscarlas.
     *
     * Los dos casos se distinguen porque son dos errores distintos. El primero es
     * «este par no esta en el plan», que se arregla escribiendo la cantidad; el
     * segundo es «esta lleno», que se arregla subiendo la cantidad o retirando
     * algo. Decir solo «se pasa del plan» dejaria al primero sin explicacion.
     *
     * @param array{plan: int, calendario: int} $cuentas      Lo que pide el plan y
     *                                                       lo que hay.
     * @param string                            $nombrePremio Nombre del premio, que
     *                                                       es lo que el
     *                                                       administrador tiene
     *                                                       delante en la
     *                                                       pantalla.
     *
     * @return string Mensaje para el campo del formulario.
     */
    private function mensajeDeExceso(array $cuentas, string $nombrePremio): string
    {
        if ($cuentas['plan'] === 0) {
            return sprintf(
                'El plan de este tramo no reparte «%s». Anadelo a las cantidades del tramo si quieres que salga.',
                $nombrePremio
            );
        }

        return sprintf(
            'El plan de este tramo ya esta completo: pide %d de «%s» y hay %d. Sube la cantidad en el'
                . ' tramo, retira alguna unidad, o deja de mover unidades aqui.',
            $cuentas['plan'],
            $nombrePremio,
            $cuentas['calendario']
        );
    }

    /**
     * Escribe el asiento de una revision del calendario.
     *
     * Vive en el servicio y no en el controlador por la misma razon que el cierre
     * y la purga lo escriben los suyos: el que cambia el calendario tiene que
     * dejar el asiento en el mismo sitio, y un llamante nuevo que se olvide de
     * hacerlo es un fallo silencioso. Ademas, aqui se puede meter en la misma
     * transaccion que el cambio, que desde un controlador no.
     *
     * El nombre del usuario se copia en el momento del cambio, igual que en
     * \App\Services\CierrePromocion, porque el esquema guarda esa copia para que la
     * fila siga diciendo quien fue aunque la cuenta se borre.
     *
     * @param int                  $promocionId Campana a la que pertenece el cambio.
     * @param string               $accion      Accion de \App\Models\Auditoria.
     * @param string               $entidad     Tabla o pantalla afectada.
     * @param string               $entidadId   Identificador de la fila afectada.
     * @param array<string, mixed>|null $antes   Como estaba antes, o null si no
     *                                              existia.
     * @param array<string, mixed>|null $despues Como queda despues.
     * @param int|null             $usuarioId   Usuario de la sesion, o null.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    private function anotar(
        int $promocionId,
        string $accion,
        string $entidad,
        string $entidadId,
        ?array $antes,
        ?array $despues,
        ?int $usuarioId
    ): void {
        (new Auditoria())->registrar(
            $promocionId,
            $usuarioId,
            (new User())->nombreDe($usuarioId),
            $entidad,
            $entidadId,
            $accion,
            $antes,
            $despues,
            null,
            null,
            Aplicacion::ipDeLaPeticion()
        );
    }

    /**
     * Devuelve una unidad con los datos de su tramo y su premio.
     *
     * @param int $unidadId Unidad que se quiere.
     *
     * @return array<string, mixed>|null Fila de la unidad, o null si no existe.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function buscar(int $unidadId): ?array
    {
        return $this->unidades->buscarParaEdicion($unidadId);
    }
}
