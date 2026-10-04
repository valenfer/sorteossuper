<?php

/**
 * Panel de seguimiento de una campana.
 *
 * ============================================================================
 * QUE ES ESTE SERVICIO Y POR QUE NO ES UN CONTROLADOR
 * ============================================================================
 *
 * El apartado 8 pide una vista con nueve cosas distintas: la campana, el tramo
 * actual, las unidades por estado, las participaciones validas, los rechazos, los
 * premios con su hora real, el estado de los correos, las diferencias del
 * calendario y la auditoria. Reunirlas son consultas, y por la arquitectura de
 * este proyecto las consultas viven en los modelos, nunca en un controlador.
 *
 * Aqui no hay ni una sola sentencia SQL. Este servicio solo decide el orden en
 * que se hacen las preguntas y la forma de los datos que devuelve, que es justo
 * lo que un controlador no deberia hacer porque no es su trabajo. Asi el mismo
 * panel se puede pedir desde la pantalla, desde un script de comprobacion o desde
 * una prueba, y sale igual en los tres sitios.
 *
 * ============================================================================
 * POR QUE EL RECUENTO NO VIENE DEL LISTADO
 * ============================================================================
 *
 * Por el limite de filas. El listado de unidades esta acotado a quinientas
 * filas y el de adjudicaciones a doscientas, porque una campana grande no cabe en
 * una pantalla ni en un smartphone. Si el total se sacara contando las filas
 * devueltas, un administrador con tres mil premios veria «500» creyendo que hay
 * quinientos. Por eso los totales van en consultas de COUNT propias, igual que
 * ya hace el calendario con contarParaCalendario().
 *
 * ============================================================================
 * POR QUE EL FILTRO SE APLICA A TODO Y SE DESCRIBE EN LA AUDITORIA
 * ============================================================================
 *
 * Un unico juego de filtros —fecha, tramo y tipo de premio, los tres que pide el
 * apartado 8— se aplica a los dos listados, para que el administrador no tenga
 * que aprender dos veces como acota una pantalla. Y el mismo filtro se escribe
 * en la fila de auditoria, porque un filtro del que no queda constancia no
 * permite reconstruir despues «que estaba viendo esta persona cuando dijo que
 * faltaba un premio». Eso es lo que exige la decision D18.
 *
 * @see \App\Controllers\ControladorSeguimiento
 * @see \App\Models\Auditoria
 * @see \App\Models\UnidadPremio::listarAdjudicadas()
 * @see \App\Services\Calendario::diagnosticar()
 * @see apartado 8 de la especificacion
 * @see decisiones D4, D10 y D18
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Models\Auditoria;
use App\Models\Correo;
use App\Models\IntentoRechazado;
use App\Models\Participacion;
use App\Models\Promocion;
use App\Models\Tramo;
use App\Models\TipoPremio;
use App\Models\UnidadPremio;
use App\Services\ConfiguracionPromocion;

/**
 * Consulta y agrega los datos del panel de seguimiento.
 */
class Seguimiento
{
    /**
     * Maximo de unidades que se listan en el panel.
     *
     * @var int
     */
    public const LIMITE_UNIDADES = 500;

    /**
     * Maximo de adjudicaciones que se listan en el panel.
     *
     * @var int
     */
    public const LIMITE_ADJUDICACIONES = 200;

    /**
     * Maximo de filas de auditoria que se listan en el panel.
     *
     * @var int
     */
    public const LIMITE_AUDITORIA = 25;

    /**
     * Devuelve todo lo que el panel necesita pintar.
     *
     * ============================================================================
     * POR QUE SE DEVUELVE UN ARRAY Y NO SE PASA POR REFERENCIA
     * ============================================================================
     *
     * Por la misma razon que en el resto del proyecto: la vista recibe datos, no
     * servicios. Si la vista pudiera preguntar cosas por su cuenta, cada etiqueta
     * de la pantalla seria un sitio mas donde se puede colar una consulta mal
     * escrita, y eso es justo lo que la capa de modelos evita.
     *
     * @param int                  $promocionId Campana que se quiere seguir.
     * @param array<string, mixed> $filtros     Filtros con las claves «fecha»,
     *                                           «tramo_id» y «tipo_premio_id»,
     *                                           ya validadas y ya limpiadas por
     *                                           el controlador.
     * @param string|null          $momento     Instante de referencia, o null
     *                                           para tomar el actual. Se puede
     *                                           forzar en las pruebas.
     *
     * @return array<string, mixed> Datos del panel, con la forma que describe la
     *         clave «panel».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si alguna consulta falla.
     * @throws \App\Core\ErrorAplicacion Si la campana no existe.
     */
    public function panel(int $promocionId, array $filtros = [], ?string $momento = null): array
    {
        $momento = $momento ?? Aplicacion::ahora();
        $campana = (new Promocion())->exigirPorId($promocionId);
        $unidades = new UnidadPremio();

        // Los tres filtros se limpian aqui tambien, y no solo en el controlador,
        // porque este servicio se puede llamar desde una prueba o desde un script
        // que no pase por el controlador. Una clave de filtro que no exista se
        // ignora en lugar de producir un error de clave, que es lo que pasaria si
        // se le pasara un filtro con nombre equivocado esperando un error claro.
        $limpios = $this->limpiarFiltros($filtros);

        $estados = $unidades->contarPorEstado($promocionId);
        $resultados = (new Participacion())->contarPorResultado($promocionId);
        $rechazos = (new IntentoRechazado())->contarPorMotivo($promocionId);
        $correos = (new Correo())->contarPorEstado($promocionId);
        $auditorias = (new Auditoria())->contarPorAccion($promocionId);

        return [
            'campana' => $campana,
            'tramo_actual' => $this->tramoActual($promocionId, $momento),
            'momento' => $momento,

            // Los cinco estados que pide el apartado 8, mas las pendientes. Se
            // devuelven todos con nombre, tambien los que valen cero, porque una
            // pantalla que solo enseña los que hay obliga a pensar «¿y las
            // anuladas?» para descubrir que no las hay. Que aparezca un 0 es
            // informacion, y es la que hace falta para entender por que dos
            // unidades mas no cuadran.
            'unidades' => [
                'programadas' => (int) ($estados[UnidadPremio::ESTADO_PROGRAMADA] ?? 0),
                'pendientes' => $this->unidadesPendientes($promocionId, $momento),
                'entregadas' => (int) ($estados[UnidadPremio::ESTADO_ENTREGADA] ?? 0),
                'no_entregadas' => (int) ($estados[UnidadPremio::ESTADO_NO_ENTREGADA] ?? 0),
                'anuladas' => (int) ($estados[UnidadPremio::ESTADO_ANULADA] ?? 0),
                'total' => array_sum($estados),
            ],

            'participaciones' => [
                'total' => array_sum($resultados),
                'con_premio' => (int) ($resultados[Participacion::RESULTADO_PREMIO] ?? 0),
                'sin_premio' => (int) ($resultados[Participacion::RESULTADO_SIN_PREMIO] ?? 0),
            ],

            'rechazos' => [
                'total' => array_sum($rechazos),
                'por_motivo' => $rechazos,
            ],

            'correos' => $correos,

            'auditoria' => [
                'por_accion' => $auditorias,
                'total' => array_sum($auditorias),
            ],

            'listado' => [
                'unidades' => $unidades->listarParaCalendario(
                    $promocionId,
                    $limpios,
                    self::LIMITE_UNIDADES
                ),
                'total_unidades' => $unidades->contarParaCalendario($promocionId, $limpios),
                'adjudicadas' => $unidades->listarAdjudicadas(
                    $promocionId,
                    $limpios,
                    self::LIMITE_ADJUDICACIONES
                ),
                'total_adjudicadas' => $unidades->contarAdjudicadas($promocionId, $limpios),
            ],

            'filtros' => $limpios,
            'tramos' => (new Tramo())->listarPorPromocion($promocionId),
            'premios' => (new TipoPremio())->listarPorPromocion($promocionId),

            // Solo las filas en las que el calendario se ha separado del plan. El
            // apartado 8 pide ver las diferencias detectadas al modificar el
            // calendario, no el plan entero: una tabla con quinientas filas
            // donde nada hay que comparar esconde justamente lo que se ha venido
            // a mirar.
            //
            // Se filtra aqui y no en la vista, al reves de lo que hace la ficha de
            // la campana, porque aqui el filtro es parte del significado de la
            // clave: una comparacion vacia y una comparacion con cuatro filas
            // desajustadas son cosas distintas, y solo la segunda merece un
            // aviso. En la ficha la tabla completa es el objeto; aqui es un
            // aviso, y por eso lo decide el servicio y no la plantilla.
            'comparacion' => $this->desajustesDelCalendario($promocionId),
        ];
    }

    /**
     * Devuelve los tramos y premios cuyo calendario ya no cuadra con el plan.
     *
     * El apartado 8 pide que el panel de seguimiento enseñe las diferencias
     * detectadas al modificar el calendario. La comparacion la sabe hacer
     * ConfiguracionPromocion, que ya la usa la ficha de la campana, y aqui no se
     * reimplementa: una segunda version de la misma comparacion divergiria de la
     * primera en cuanto uno cambiase una regla, y el panel acabaria diciendo que
     * el calendario cuadra cuando la ficha dice que no.
     *
     * @param int $promocionId Campana que se compara.
     *
     * @return array<int, array<string, mixed>> Solo las filas con «cambia» a
     *                                  true, con las claves que describe
     *                                  \App\Services\ConfiguracionPromocion::compararPlanYCalendario().
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    private function desajustesDelCalendario(int $promocionId): array
    {
        $comparacion = (new ConfiguracionPromocion())->compararPlanYCalendario($promocionId);

        return array_values(array_filter(
            $comparacion,
            static fn (array $fila): bool => (bool) $fila['cambia']
        ));
    }

    /**
     * Devuelve el tramo en el que esta la campana en este momento.
     *
     * ============================================================================
     * POR QUE SE CALCULA Y NO SE GUARDA EN UNA COLUMNA
     * ============================================================================
     *
     * Porque un tramo guardado tendria que actualizarse cada vez que la campana
     * avanzase, y eso obliga a tocar la base de datos en cada participacion para
     * guardar un dato que se deduce del reloj. Ademas, guardarlo en una columna
     * permitiria que se quedase desactualizado: si alguien cambia el calendario,
     * o se para el reloj de la tienda, la columna diria un tramo y la campana
     * estaria en otro. Aqui la pregunta se responde con la misma definicion que usa
     * el motor para decidir si admite una participacion, que es la unica que
     * importa.
     *
     * El criterio es el mismo que usa el motor para admitir una participacion, y
     * la consulta vive en el modelo de tramos, no aqui. Este metodo se queda con
     * la decision —que tramo toca— y delega la lectura, que es lo unico que
     * corresponde al modelo de la tabla.
     *
     * @param int    $promocionId Campana que se quiere consultar.
     * @param string $momento     Instante de referencia.
     *
     * @return array<string, mixed>|null El tramo en curso, o null si ahora
     *                                   mismo la campana esta fuera de horario.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function tramoActual(int $promocionId, string $momento): ?array
    {
        return (new Tramo())->actualEn($promocionId, $momento);
    }

    /**
     * Cuenta las unidades programadas que todavia no han llegado a su hora.
     *
     * ============================================================================
     * POR QUE ESTO NO ES UNA RESTA
     * ============================================================================
     *
     * Se podria restar el numero de participaciones validas al total de unidades
     * programadas, y es tentador porque son los mismos numeros que ya estan
     * calculados. No se hace por dos razones.
     *
     * La primera es que no es lo mismo. Una participacion puede no haber recibido
     * premio porque no habia unidades disponibles en ese instante, no porque su
     * unidad siga esperando. Restar participaciones de unidades daria la cuenta de
     * los premios que aun no han salido, que es un numero distinto del que pide el
     * apartado 8, que es el de los premios cuya hora aun no ha llegado.
     *
     * La segunda es que el recuento sale de una cuenta en SQL sobre un indice, que
     * es una sola lectura, mientras que la resta haria que el panel tuviera que
     * mirar dos recuentos y restarlos en cada fila de la pantalla. Con una campana
     * grande se nota.
     *
     * La cuenta la hace el modelo de unidades, que es quien sabe de unidades. Aqui
     * no hay SQL.
     *
     * @param int    $promocionId Campana que se quiere contar.
     * @param string $momento     Instante de referencia.
     *
     * @return int Numero de unidades programadas cuya hora ya ha llegado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function unidadesPendientes(int $promocionId, string $momento): int
    {
        return (new UnidadPremio())->contarPendientes($promocionId, $momento);
    }

    /**
     * Escribe en la auditoria que el administrador ha mirado el panel.
     *
     * Es el asiento que exige la decision D18. Se escribe una vez por visita, no
     * una por cada dato que se enseña, porque lo que tiene que quedar escrito es
     * «esta persona ha mirado esta campana con estos filtros», y un asiento por
     * cada cifra haria que el historial fuera ilegible sin aportar nada: con nueve
     * indicadores y dos listados, cada recarga dejaria once filas y el recuento
     * de la propia auditoria, que tambien se enseña en la pantalla, creceria sin
     * parar.
     *
     * filas_mostradas lleva el total de unidades del listado, que es el listado
     * principal de la pantalla y el que lleva los codigos de reclamacion. No es el
     * total de la campana, sino el de lo que se ha enseñado con el filtro puesto,
     * que es lo unico que puede contestar a la pregunta «¿cuanto ha visto esta
     * visita?».
     *
     * @param int                  $promocionId  Campana que se ha mirado.
     * @param array<string, mixed> $filtros      Filtros aplicados, ya limpiados.
     * @param int                  $filasMostradas Cuantas unidades se han
     *                                              enseñado.
     * @param int|null             $usuarioId     Usuario de la sesion, o null.
     *
     * @return int Identificador de la fila de auditoria escrita.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    public function anotarVisita(
        int $promocionId,
        array $filtros,
        int $filasMostradas,
        ?int $usuarioId = null
    ): int {
        // El nombre se copia por identificador y no con
        // Autorizacion::nombreUsuario(), que es lo que hacen el cierre, la purga y
        // el calendario. La razon es que los dos no pueden discrepar: el metodo
        // recibe el usuario que sea, y si el nombre se leyera de la sesion, una
        // llamada con un identificador y la sesion de otra persona escribiria el
        // nombre equivocado al lado del identificador correcto.
        return (new Auditoria())->registrar(
            $promocionId,
            $usuarioId,
            (new \App\Models\User())->nombreDe($usuarioId),
            'seguimiento',
            (string) $promocionId,
            Auditoria::ACCION_VISUALIZACION,
            null,
            null,
            $this->describirFiltros($filtros),
            $filasMostradas,
            Aplicacion::ipDeLaPeticion()
        );
    }

    /**
     * Deja los tres filtros del apartado 8 en un formato limpio.
     *
     * Solo se aceptan las claves que el apartado 8 nombra. Una clave de mas se
     * ignora en lugar de pasarse a la consulta: sin esta lista blanca, un filtro
     * con nombre de columna podria llegar hasta la sentencia de los modelos. No
     * seria una inyeccion —el valor va siempre como marcador— pero si una forma de
     * filtrar por algo que nadie ha pedido, y quien lo escribiera no tendria ni
     * idea de que esta ahi.
     *
     * @param array<string, mixed> $filtros Filtros recibidos.
     *
     * @return array<string, mixed> Filtros validos, en su forma definitiva.
     */
    private function limpiarFiltros(array $filtros): array
    {
        $limpios = [];

        $fecha = trim((string) ($filtros['fecha'] ?? ''));

        // La fecha se valida con el formato exacto de MySQL y se vuelve a
        // componer. Escribiendola de nuevo con el propio formato, una fecha como
        // «2026-13-45» o «ayer» se queda en la cadena vacia y no llega a la
        // consulta, en vez de producir un error de MySQL en la pantalla.
        if ($fecha !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes) === 1) {
            if (checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
                $limpios['fecha'] = $fecha;
            }
        }

        $tramoId = (int) ($filtros['tramo_id'] ?? 0);

        if ($tramoId > 0) {
            $limpios['tramo_id'] = $tramoId;
        }

        $premioId = (int) ($filtros['tipo_premio_id'] ?? 0);

        if ($premioId > 0) {
            $limpios['tipo_premio_id'] = $premioId;
        }

        return $limpios;
    }

    /**
     * Convierte los filtros en una frase legible para la auditoria.
     *
     * Se escribe una frase y no un volcado del array porque esta columna se lee a
     * ojo, en el historial, buscando «¿que estaba mirando esta persona?». Una
     * frase como «fecha 2026-03-15, tramo 7, premio 2» se entiende a la primera;
     * un array con tres claves y sus valores, no.
     *
     * @param array<string, mixed> $filtros Filtros ya limpiados.
     *
     * @return string Descripcion de los filtros, o «sin filtro» si no hay ninguno.
     */
    private function describirFiltros(array $filtros): string
    {
        $partes = [];

        if (isset($filtros['fecha'])) {
            $partes[] = 'fecha ' . (string) $filtros['fecha'];
        }

        if (isset($filtros['tramo_id'])) {
            $partes[] = 'tramo ' . (int) $filtros['tramo_id'];
        }

        if (isset($filtros['tipo_premio_id'])) {
            $partes[] = 'premio ' . (int) $filtros['tipo_premio_id'];
        }

        return $partes === [] ? 'sin filtro' : implode(', ', $partes);
    }
}
