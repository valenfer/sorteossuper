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
 * Y hay un motivo mas por el que el filtro no se puede anadir ni como
 * reposte: con un filtro por dia, un premio sin reclamar de ayer no volveria a
 * salir nunca, porque su fecha ya no seria la de hoy. No es que salga tarde, es
 * que desaparece de la cola, y el cierre lo convertiria en «no entregada» como
 * si alguien lo hubiera rechazado. El caso 5 de la suite monta dos dias reales y
 * comprueba que el premio de ayer sale el primero y que ni su «inicio» ni su
 * «tramo_id» se reescriben.
 *
 * «Por que la cola no se filtra por tramo» se completo en
 * UnidadPremio::primeraPendiente(), que es donde esta la consulta.
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
     * Estado de una unidad retirada por el administrador antes de repartirse.
     *
     * Retirar no borra la fila: la deja en «anulada» con su motivo. El motivo es
     * que el historial de una campana tiene que cuadrar, y si al retirar un premio
     * desapareciera su fila, el total de premios de la campana dejaria de coincidir
     * con la suma de los tramos sin que nadie pudiera explicar por que.
     *
     * @var string
     */
    public const ESTADO_ANULADA = 'anulada';

    /**
     * Estado de una unidad que llego a su hora y no pudo adjudicarse.
     *
     * No lo usa el generador de calendario, que solo crea unidades «programadas»,
     * pero el panel lo muestra en el recuento para que se vea que un premio
     * llego a su hora y se perdio, en vez de seguir esperando.
     *
     * @var string
     */
    public const ESTADO_NO_ENTREGADA = 'no_entregada';

    /**
     * Devuelve los cuatro estados posibles, con su texto para las personas.
     *
     * El estado es un ENUM de cuatro valores en el esquema, y el panel no puede
     * inventar etiquetas: si el esquema anade un estado, este metodo tiene que
     * contar con el, o el filtro del panel dejaria fuera unidades que si existen.
     *
     * @return array<string, string> Estados como claves y su etiqueta como
     *                              valor, en el orden en que aparecen en el
     *                              esquema.
     */
    public static function estados(): array
    {
        return [
            self::ESTADO_PROGRAMADA   => 'Programada',
            self::ESTADO_ENTREGADA    => 'Entregada',
            self::ESTADO_ANULADA      => 'Anulada',
            self::ESTADO_NO_ENTREGADA => 'No entregada',
        ];
    }

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
     * ============================================================================
     * LA COLA CRUZA LOS TRAMOS Y LOS DIAS, Y ESO ES D4
     * ============================================================================
     *
     * El WHERE filtra por campana, por estado y por «inicio <= momento», y nada
     * mas. No mira el tramo ni la fecha, y esa ausencia es deliberada: es la
     * decision D4, confirmada, de que la cola de premios se mantiene a lo largo
     * de los tramos y de los dias.
     *
     * Hay que decirlo porque la especificacion dice las dos cosas. Su tabla de
     * decisiones dice que «los premios pendientes pasan al tramo y al dia
     * siguiente», y su cuerpo dice que la cola persiste. La segunda es la buena,
     * por tres razones que se pueden comprobar en el codigo:
     *
     *   1. La cola se ordena por «inicio ASC, id ASC». Un premio del lunes sin
     *      reclamar tiene una hora anterior a la de cualquiera de hoy, asi que
     *      sale antes que los de hoy. Con un filtro por dia, ese premio no
     *      saldria nunca: se quedaria PROGRAMADA para siempre, o se perderia al
     *      cerrar la campana. El caso 5 de la suite lo comprueba.
     *
     *   2. «tramo_id» es la referencia autoritativa del horario original
     *      (apartado 9). Reescribirlo al pasar al dia siguiente haria que un
     *      premio del lunes pareciera suyo del martes.
     *
     *   3. «inicio» es contra lo que el panel mide el retraso. Un premio
     *     reclamado al dia siguiente tiene que informar de mas de un
     *      dia de retraso, y mover la fecha a hoy lo haria decir cero.
     *
     * No reubicar unidades es, por tanto, parte de la decision y no una
     * comodidad: la reubicacion seria la que romperia el retraso y el horario.
     * Si alguna vez hace falta reorganizar la cola, el sitio es una tabla de
     * asignaciones con su propia fecha, no «inicio» ni «tramo_id».
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
     * Cuenta las unidades de una campana que todavia se pueden entregar.
     *
     * Son las programadas, y solo ellas. Una unidad entregada ya no esta, y una
     * anulada se ha retirado a proposito. La cuenta es lo que permite distinguir
     * «la campana tiene plan» de «la campana tiene calendario»: el plan son
     * numeros en asignaciones_tramo y el calendario son estas unidades con su
     * minuto, y solo el motor de sorteo puede entregar una de estas.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return int Numero de unidades que siguen vivas.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarEntregables(int $promocionId): int
    {
        return (int) $this->db->valor(
            'SELECT COUNT(*)
               FROM unidades_premio
              WHERE promocion_id = ?
                AND estado = ?',
            [$promocionId, self::ESTADO_PROGRAMADA]
        );
    }

    /**
     * Cuenta las unidades de un par de tramo y premio, para compararlas con el plan.
     *
     * ============================================================================
     * POR QUE LAS ANULADAS NO CUENTAN
     * ============================================================================
     *
     * Porque la cuenta tiene que ser comparable con la de
     * \App\Models\AsignacionTramo::compararConCalendario(), que excluye las
     * anuladas por el mismo motivo, y porque es lo que espera quien retira una
     * unidad a mano: si ha retirado una de las tres que pedia el plan, ese par pasa
     * a tener hueco y hay que poder volver a rellenarlo. Contar tambien las
     * anuladas cerraria esa puerta para siempre y dejaria un hueco que no se puede
     * llenar desde el panel.
     *
     * La unidad que se esta moviendo se puede excluir, y hace falta: al mover una
     * unidad dentro de su mismo tramo, la cuenta sin excluirla diria que ese par ya
     * esta lleno y rechazaria un movimiento que no anade nada.
     *
     * @param int $tramoId         Tramo del par.
     * @param int $tipoPremioId    Premio del par.
     * @param int $excluirUnidadId Unidad que no se cuenta, o cero para contarlas
     *                             todas.
     *
     * @return int Numero de unidades que siguen vivas en ese par.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarEnTramoYTipo(int $tramoId, int $tipoPremioId, int $excluirUnidadId = 0): int
    {
        return (int) $this->db->valor(
            'SELECT COUNT(*)
               FROM unidades_premio
              WHERE tramo_id = ?
                AND tipo_premio_id = ?
                AND estado <> ?
                AND id <> ?',
            [$tramoId, $tipoPremioId, self::ESTADO_ANULADA, $excluirUnidadId]
        );
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

    /**
     * Lista las unidades de una campana para el panel de calendario.
     *
     * ============================================================================
     * POR QUE ESTA CONSULTA TRAE TRAMOS Y PREMIOS Y NO SE PUEDE QUITAR
     * ============================================================================
     *
     * La tabla de la pantalla de calendario necesita el nombre del premio y la
     * fecha y el horario de su tramo en cada fila. Sacarlos con una consulta por
     * fila significa 500 consultas para una campana normal, y el resultado se
     * nota en el tiempo de carga. Con los dos JOIN, la pantalla hace una consulta
     * y el indice ix_unidades_cola sigue sirviendo para filtrar por campana.
     *
     * El orden por instante y despues por identificador es el mismo con el que el
     * motor recorre la cola, y a proposito: si el panel y el motor ordenaran de
     * forma distinta, quien administra la campana veria un calendario que no se
     * parece en nada a como se han repartido los premios, y no podria comprobar
     * nada a mano.
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
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarParaCalendario(int $promocionId, array $filtros = [], int $limite = 500): array
    {
        $parametros = [$promocionId];
        $sentencia = 'SELECT u.id,
                             u.inicio,
                             u.estado,
                             u.codigo_reclamacion,
                             u.anulada_motivo,
                             u.participacion_id,
                             t.id AS tramo_id,
                             t.fecha,
                             t.hora_inicio,
                             t.hora_fin,
                             tp.id AS tipo_premio_id,
                             tp.nombre AS premio,
                             tp.imagen_ruta
                        FROM unidades_premio u
                        JOIN tramos t ON t.id = u.tramo_id
                        JOIN tipos_premio tp ON tp.id = u.tipo_premio_id
                       WHERE u.promocion_id = ?'
            . $this->filtrosDeCalendario($filtros, $parametros)
            . ' ORDER BY u.inicio ASC, u.id ASC
                       LIMIT ?';

        $parametros[] = max(1, $limite);

        return $this->db->todos($sentencia, $parametros);
    }

    /**
     * Cuenta las unidades del calendario con los mismos filtros que el listado.
     *
     * Va aparte porque el total no se puede sacar de las filas devueltas: con el
     * limite de 500, un administrador con tres mil premios veria «500» y creeria
     * que solo hay quinientos. Esta consulta cuenta las filas, no las que han
     * cabido en el limite.
     *
     * @param int                  $promocionId Campana que se quiere contar.
     * @param array<string, mixed> $filtros     Filtros opcionales, con las mismas
     *                                           claves que listarParaCalendario().
     *
     * @return int Numero de unidades que cumplen los filtros.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarParaCalendario(int $promocionId, array $filtros = []): int
    {
        $parametros = [$promocionId];
        $sentencia = 'SELECT COUNT(*) AS total
                        FROM unidades_premio u
                        JOIN tramos t ON t.id = u.tramo_id
                       WHERE u.promocion_id = ?'
            . $this->filtrosDeCalendario($filtros, $parametros);

        return (int) $this->db->valor($sentencia, $parametros);
    }

    /**
     * Construye el trozo de sentencia de los filtros del calendario.
     *
     * Se escribe una vez y se usa en el listado y en el recuento, porque si los
     * dos tuvieran su propia copia acabarian differiendo en cuanto se añadiera un
     * filtro nuevo, y el panel mostraria «de 812 unidades, 500» al filtrar por un
     * estado que en realidad no tiene ninguna. Cada filtro se anade con su
     * marcador y su valor, nunca con el valor pegado en la sentencia.
     *
     * @param array<string, mixed> $filtros    Filtros recibidos.
     * @param array<int, mixed>    $parametrosLista Valores que se van anadiendo,
     *                                                 porque se pasan por
     *                                                 referencia y quien llama los
     *                                                 necesita ya completos.
     *
     * @return string Fragmento de sentencia, con el espacio inicial incluido.
     */
    private function filtrosDeCalendario(array $filtros, array &$parametrosLista): string
    {
        $campos = [
            'estado'         => 'u.estado',
            'tramo_id'       => 'u.tramo_id',
            'tipo_premio_id' => 'u.tipo_premio_id',
            'fecha'          => 't.fecha',
        ];
        $fragmento = '';

        foreach ($campos as $clave => $columna) {
            if (!isset($filtros[$clave]) || $filtros[$clave] === '' || $filtros[$clave] === null) {
                continue;
            }

            $fragmento .= ' AND ' . $columna . ' = ?';
            $parametrosLista[] = $filtros[$clave];
        }

        return $fragmento;
    }

    /**
     * Lista los premios ya adjudicados de una campana, con su hora real.
     *
     * ============================================================================
     * POR QUE ESTA CONSULTA TRAE LAS DOS HORAS
     * ============================================================================
     *
     * Porque son las dos que se guardan y no se pueden recuperar una de la otra. En
     * inicio esta la hora en que el premio estaba previsto, que es una decision del
     * administrador; en adjudicada_en esta la hora en que se entrego de verdad, que
     * depende de cuando llego la siguiente participacion valida. En una campana que
     * funciona bien casi coinciden, y por eso puede parecer redundante guardarlas.
     *
     * Se juntas porque el apartado 8 pide expressly los «premios adjudicados con su
     * hora real», y esa pregunta no tiene respuesta sin las dos: si solo saliera
     * adjudicada_en, el administrador veria que un premio se entrego a las 11:20 sin
     * poder saber si estaba previsto a las 11:20 o si llevaba una hora de retraso. Y
     * el retraso es justo el sintoma que sirve para detectar que la campana va
     * justa: si se acumulan minutos de espera, o no hay azafatas o la hora punta
     * se ha llenado de participaciones.
     *
     * La diferencia se calcula en SQL con TIMESTAMPDIFF y se devuelve en minutos,
     * que es como la piensa quien mira la pantalla. El dato tambien se podria
     * calcular en PHP al pintar, pero entonces cada fila seria una resta de
     * fechas en el servidor y un numero que se podria leer en pantalla, y el
     * motor ya lo sabe hacer mejor.
     *
     * El ORDER BY es por adjudicada_en y no por inicio, porque lo que se mira es
     * la secuencia real de entrega. Con el orden del calendario, que es por
     * inicio, dos premios entregados en orden inverso aparecerian desordenados
     * respecto a como ocurrieron.
     *
     * Solo se listan las unidades entregadas: una anulada o una que se cerro sin
     * entregar no tiene hora real de adjudicacion, y meterlas aqui con la columna
     * a NULL haria que el administrador las Sumara como si hubieran ocurrido.
     *
     * @param int                  $promocionId Campana que se quiere listar.
     * @param array<string, mixed> $filtros     Filtros opcionales, con las claves
     *                                           «tramo_id», «tipo_premio_id» y
     *                                           «fecha». Se reaprovecha el
     *                                           filtro del calendario para que
     *                                           los dos listados no se separen.
     * @param int                  $limite      Maximo de filas devueltas.
     *
     * @return array<int, array<string, mixed>> Filas con la hora prevista, la
     *         hora real, los minutos de retraso, el tramo, el premio y el codigo
     *         de reclamacion.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarAdjudicadas(int $promocionId, array $filtros = [], int $limite = 200): array
    {
        $parametros = [$promocionId, self::ESTADO_ENTREGADA];
        $sentencia = 'SELECT u.id,
                             u.inicio,
                             u.adjudicada_en,
                             u.codigo_reclamacion,
                             TIMESTAMPDIFF(MINUTE, u.inicio, u.adjudicada_en) AS retraso_minutos,
                             u.participacion_id,
                             t.id AS tramo_id,
                             t.fecha,
                             t.hora_inicio,
                             t.hora_fin,
                             tp.id AS tipo_premio_id,
                             tp.nombre AS premio
                        FROM unidades_premio u
                        JOIN tramos t ON t.id = u.tramo_id
                        JOIN tipos_premio tp ON tp.id = u.tipo_premio_id
                       WHERE u.promocion_id = ?
                         AND u.estado = ?'
            . $this->filtrosDeCalendario($filtros, $parametros)
            . ' ORDER BY u.adjudicada_en DESC, u.id DESC
                        LIMIT ?';

        $parametros[] = max(1, $limite);

        return $this->db->todos($sentencia, $parametros);
    }

    /**
     * Cuenta los premios adjudicados con los mismos filtros que el listado.
     *
     * Va aparte, y por el mismo motivo que contarParaCalendario(): con un limite
     * de doscientas filas, el total no se puede sacar de lo devuelto o el panel
     * diria «200» a quien tenga mas.
     *
     * @param int                  $promocionId Campana que se quiere contar.
     * @param array<string, mixed> $filtros     Filtros, con las mismas claves que
     *                                           listarAdjudicadas().
     *
     * @return int Numero de premios entregados que cumplen los filtros.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarAdjudicadas(int $promocionId, array $filtros = []): int
    {
        $parametros = [$promocionId, self::ESTADO_ENTREGADA];

        $sentencia = 'SELECT COUNT(*)
                        FROM unidades_premio u
                        JOIN tramos t ON t.id = u.tramo_id
                       WHERE u.promocion_id = ?
                         AND u.estado = ?'
            . $this->filtrosDeCalendario($filtros, $parametros);

        return (int) $this->db->valor($sentencia, $parametros);
    }

    /**
     * Cuenta las unidades programadas cuya hora ya ha llegado.
     *
     * ============================================================================
     * POR QUE ESTO NO ES UNA RESTA
     * ============================================================================
     *
     * Se podria restar el numero de participaciones validas al total de unidades
     * programadas, y es tentador porque son los mismos numeros que el panel ya
     * tiene calculados. No se hace por dos razones.
     *
     * La primera es que no es lo mismo. Una participacion puede no haber recibido
     * premio porque no habia unidades disponibles en ese instante, no porque su
     * unidad siga esperando. Restar participaciones de unidades daria el numero de
     * premios que aun no han salido, que es una cifra distinta de la que pide el
     * apartado 8, que es la de los premios cuya hora todavia no ha llegado.
     *
     * La segunda es que el recuento sale de una cuenta en SQL sobre un indice, que
     * es una sola lectura, mientras que la resta haria que el panel tuviera que
     * mirar dos recuentos y restarlos en cada fila de la pantalla. Con una campana
     * grande se nota.
     *
     * Se cuentan las que estan programadas y ya han empezado, ni siquiera las
     * cuyas unidades siguen sin adjudicar: «premio esperando» es una promesa de la
     * campana, y el panel debe poder contrastarla con la reality de lo entregado.
     *
     * @param int    $promocionId Campana que se quiere contar.
     * @param string $momento     Instante de referencia.
     *
     * @return int Numero de unidades programadas cuya hora ya ha llegado.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPendientes(int $promocionId, string $momento): int
    {
        return (int) $this->db->valor(
            'SELECT COUNT(*)
               FROM unidades_premio
              WHERE promocion_id = ?
                AND estado = ?
                AND inicio <= ?',
            [$promocionId, self::ESTADO_PROGRAMADA, $momento]
        );
    }

    /**
     * Devuelve una unidad con los datos de su tramo, para editar o retirar.
     *
     * @param int $unidadId Unidad que se quiere.
     *
     * @return array<string, mixed>|null Fila de la unidad, o null si no existe.
     *
     * @throws ErrorBaseDeDatos Si la consulta falla.
     */
    public function buscarParaEdicion(int $unidadId): ?array
    {
        return $this->db->uno(
            'SELECT u.*, t.fecha, t.hora_inicio, t.hora_fin
               FROM unidades_premio u
               JOIN tramos t ON t.id = u.tramo_id
              WHERE u.id = ?
              LIMIT 1',
            [$unidadId]
        );
    }

    /**
     * Crea una unidad suelta ya con su estado «programada».
     *
     * El estado se pone aqui y no se recibe como parametro a proposito: esta tabla
     * solo admite cuatro valores y tres de ellos los pone el motor de
     * adjudicacion o el panel. Si quien llama pudiera elegir, habria una quinta
     * forma de crear una unidad entregada sin participacion ni codigo, que es
     * exactamente el tipo de fila que despues nadie sabe explicar.
     *
     * @param int    $promocionId  Campana a la que pertenece la unidad.
     * @param int    $tramoId      Tramo dentro del cual cae la unidad.
     * @param int    $tipoPremioId Premio que se reparte.
     * @param string $inicio       Instante de inicio, en formato «A-n-j H:i:s».
     *
     * @return int Identificador de la unidad creada.
     *
     * @throws ErrorBaseDeDatos Si la escritura falla.
     */
    public function crear(int $promocionId, int $tramoId, int $tipoPremioId, string $inicio): int
    {
        $ahora = \App\Core\Aplicacion::ahora();

        return $this->db->insertar(
            'INSERT INTO unidades_premio (
                 promocion_id, tramo_id, tipo_premio_id, inicio, estado, creado_en, modificado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $promocionId,
                $tramoId,
                $tipoPremioId,
                $inicio,
                self::ESTADO_PROGRAMADA,
                $ahora,
                $ahora,
            ]
        );
    }

    /**
     * Inserta de golpe un lote de unidades ya repartidas.
     *
     * El generador crea cientos de unidades por tramo, y dearlas de una en una
     * haria una consulta por unidad. Aqui van todas en una sentencia, agrupadas
     * en lotes: MySQL tiene un tope de marcadores por sentencia preparada, y un
     * tramo con 500 unidades son 2500 marcadores, de ahi el corte en doscientas.
     *
     * Se repite el mismo valor de «ahora» en creado_en y modificado_en porque la
     * unidad se crea ahora y su ultima modificacion es su creacion. Poner la
     * variable dos veces obliga a duplicar el valor, mientras que dejar que el
     * motor ponga NOW() haria que las dos columnas tuvieran segundos distintos
     * dentro de la misma fila.
     *
     * @param int                  $promocionId Campana a la que pertenecen
     *                                             todas las unidades del lote.
     * @param array<int, array<int, mixed>> $filas Filas de cuatro valores:
     *                                         identificador de tramo,
     *                                         identificador de tipo de premio,
     *                                         instante de inicio y momento de
     *                                         creacion.
     *
     * @return int Numero de filas insertadas.
     *
     * @throws ErrorBaseDeDatos Si la escritura falla.
     */
    public function insertarLote(int $promocionId, array $filas): int
    {
        if ($filas === []) {
            return 0;
        }

        // Los grupos de valores se unen con comas. Repetir la cadena sin separador
        // produce «VALUES (…)(…)», que es un error de sintaxis que solo aparece
        // con dos o mas filas, de modo que un tramo con un solo premio funcionaria
        // en pruebas y fallaria en la campana.
        $grupos = implode(', ', array_fill(0, count($filas), '(?, ?, ?, ?, ?, ?)'));

        $sentencia = 'INSERT INTO unidades_premio (
                         promocion_id, tramo_id, tipo_premio_id, inicio, creado_en, modificado_en
                     ) VALUES ' . $grupos;

        $parametros = [];

        foreach ($filas as $fila) {
            $parametros[] = $promocionId;
            $parametros[] = $fila[0];
            $parametros[] = $fila[1];
            $parametros[] = $fila[2];
            $parametros[] = $fila[3];
            $parametros[] = $fila[3];
        }

        return $this->db->ejecutar($sentencia, $parametros);
    }

    /**
     * Borra las unidades «programadas» de un tramo, para rehacer su calendario.
     *
     * El WHERE lleva el estado y no solo el tramo, y esa es toda la garantia de
     * que «reemplazar el calendario» no pueda tocar un premio ya entregado. Si
     * aqui se quitara el filtro de estado, la pantalla de reemplazo permitiria
     * borrar en un clic los premios que una clienta ya tiene en la mano, y sus
     * participaciones se quedarian con un premio que ya no existe.
     *
     * @param int $tramoId Tramo cuyo calendario se rehace.
     *
     * @return int Numero de unidades borradas.
     *
     * @throws ErrorBaseDeDatos Si la escritura falla.
     */
    public function borrarProgramadasDeTramo(int $tramoId): int
    {
        return $this->db->ejecutar(
            'DELETE FROM unidades_premio
              WHERE tramo_id = ?
                AND estado = ?',
            [$tramoId, self::ESTADO_PROGRAMADA]
        );
    }

    /**
     * Mueve una unidad programada a otro tramo y hora.
     *
     * El estado va en el WHERE por la misma razon que en el borrado: si otra
     * pantalla ha entregado esa unidad entre la lectura y este UPDATE, el cambio
     * no debe aplicarse. El recuento que devuelve el metodo lo comprueba quien
     * llama, que es quien puede avisar al administrador de que ya no era suya.
     *
     * @param int    $unidadId  Unidad que se mueve.
     * @param int    $tramoId   Tramo de destino.
     * @param string $inicio    Instante de inicio en el tramo de destino.
     *
     * @return int Numero de filas afectadas: 1 si se ha movido, 0 si ya no era
     *             programada.
     *
     * @throws ErrorBaseDeDatos Si la escritura falla.
     */
    public function mover(int $unidadId, int $tramoId, string $inicio): int
    {
        return $this->db->ejecutar(
            'UPDATE unidades_premio
                SET tramo_id = ?,
                    inicio = ?,
                    modificado_en = ?
              WHERE id = ?
                AND estado = ?',
            [$tramoId, $inicio, \App\Core\Aplicacion::ahora(), $unidadId, self::ESTADO_PROGRAMADA]
        );
    }

    /**
     * Retira una unidad, que es anularla con su motivo, no borrarla.
     *
     * ============================================================================
     * POR QUE RETIRAR NO ES BORRAR
     * ============================================================================
     *
     * El esquema tiene un estado «anulada» con su columna de motivo, y existe
     * precisamente para esto. Si retirar borrase la fila, el total de premios de
     * la campana dejaria de cuadrar con la suma de los tramos, y no habria forma
     * de demostrar que el premio se retiró a proposito y no se perdio por un
     * fallo. Guardar la fila con su motivo resuelve las dos cosas.
     *
     * La fecha de la anulacion no se guarda porque el esquema no tiene donde
     * guardarla, y anadir una columna por comodidad del panel seria cambiar el
     * esquema sin necesidad. La marca de tiempo esta en modificado_en, que cambia
     * en este mismo UPDATE, y ese dato basta para responder «cuando se retiro
     * esto».
     *
     * @param int    $unidadId Unidad que se retira.
     * @param string $motivo   Motivo de la retirada, que queda registrado.
     *
     * @return void
     *
     * @throws ErrorBaseDeDatos Si la escritura falla.
     */
    public function anular(int $unidadId, string $motivo): void
    {
        $ahora = \App\Core\Aplicacion::ahora();

        $this->db->ejecutar(
            'UPDATE unidades_premio
                SET estado = ?,
                    anulada_motivo = ?,
                    modificado_en = ?
              WHERE id = ?',
            [self::ESTADO_ANULADA, $motivo, $ahora, $unidadId]
        );
    }

    /**
     * Pasa a no_entregada todas las unidades programadas de una campana.
     *
     * Es la operacion central del cierre de una campana, y se hace de una sola
     * sentencia y no unidad por unidad, por dos razones.
     *
     * La primera es que el numero de unidades de una campana de un supermercado
     * puede ser de miles. Hacerlo fila a fila desde PHP convertiria un cierre en
     * miles de viajes de ida y vuelta que en una tienda con una conexion lenta se
     * notan. Una sentencia las resuelve todas dentro del motor.
     *
     * La segunda, y la importante, es que un UPDATE con WHERE sobre el estado no
     * puede afectar a una unidad que no estuviera programada aunque alguien lo
     * ejecutase dos veces. Eso hace que el cierre sea idempotente por su cuenta y
     * que un doble clic en el boton no pueda dejar unidades entregadas marcadas
     * como no entregadas. La condicion va en el WHERE y no se filtra en PHP por
     * eso mismo: el filtro tiene que estar en la sentencia que decide.
     *
     * ============================================================================
     * POR QUE NO SE TOCA anulada_motivo
     * ============================================================================
     *
     * Porque el esquema documenta ese campo como el motivo de una anulacion, y
     * que significa que alguien decidio retirar un premio del plan. Una unidad
     * que se cierra sin entregar es otra cosa: estaba en el plan, llego su hora y
     * nadie llego a reclamarlo. Poner el motivo del cierre en un campo que el
     * panel lee como «retirada manual» haria que una retirada se confunda con un
     * cierre al mirar el calendario, que es exactamente el fallo que el comentario
     * de anular() quiere evitar. El motivo del cierre no se pierde: esta en la
     * fila de auditoria del cierre, que si lo explica.
     *
     * ============================================================================
     * POR QUE NO SE ADJUDICA NADA AL CERRAR
     * ============================================================================
     *
     * Porque el cierre no es un sorteo. Un premio que nadie ha llegado a
     * reclamar no se le puede dar a la siguiente persona sin su participacion: eso
     * seria inventar una adjudicacion, con un correo y un codigo de reclamacion
     * de alguien que no existe. La decision D4 dice que la cola persiste entre
     * tramos mientras la campana siga abierta, y al cerrarla ya no hay a quien
     * dársela. Por eso el cierre consume las unidades, y no las reparte.
     *
     * @param int    $promocionId Campana que se cierra.
     * @param string $momento     Instante del cierre, que se escribe en
     *                            modificado_en de cada unidad.
     *
     * @return int Cuantas unidades han cambiado de estado, que es el numero de
     *             premios que se han quedado sin reclamar.
     *
     * @throws ErrorBaseDeDatos Si la escritura falla.
     */
    public function noEntregarProgramadas(int $promocionId, string $momento): int
    {
        return $this->db->ejecutar(
            'UPDATE unidades_premio
                SET estado = ?,
                    modificado_en = ?
              WHERE promocion_id = ?
                AND estado = ?',
            [
                self::ESTADO_NO_ENTREGADA,
                $momento,
                $promocionId,
                self::ESTADO_PROGRAMADA,
            ]
        );
    }
}
