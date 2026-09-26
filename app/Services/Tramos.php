<?php

/**
 * Servicio de validacion de los tramos de participacion.
 *
 * ============================================================================
 * POR QUE ESTO ES UN SERVICIO Y NO PARTE DEL MODELO
 * ============================================================================
 *
 * El comentario de la tabla tramos en el esquema remite a este fichero, y dice
 * por que: «el servicio de validaciones comprueba ademas dos cosas que una
 * restriccion CHECK no puede», que son el solape con otros tramos y el cruce del
 * cambio de hora de verano.
 *
 * Las dos necesitan mirar otras filas, y la segunda necesita ademas el calendario
 * de la zona horaria, que no tiene nada que ver con la base de datos. Un modelo
 * que las comprobara tendria que pedir el tramo entero de la campana para poder
 * decidir si uno nuevo se solapa, y eso es un trabajo de servicio: deciding, no
 * consultando.
 *
 * ============================================================================
 * EL SOLAPE: POR QUE DOS TRAMOS QUE SE TOCAN NO SE SOLAPAN
 * ============================================================================
 *
 * El ejemplo del apartado 4.2 pone un tramo de 10:00 a 14:00 y otro de 16:00 a
 * 21:00, pero el caso interesante es el de 10:00 a 14:00 seguido de 14:00 a 18:00.
 * Con una comprobacion ingenua —«¿el nuevo empieza antes de que termine el
 * anterior?»— el segundo se rechazaria, y no debe: a las 14:00 en punto la
 * participacion pertenece al primer tramo, y a las 14:01 al segundo. Los dos
 * conviven. Solo se solapan cuando el intervalo del otro atraviesa el nuestro:
 *
 *     se solapan  <=>  otro_inicio < mi_fin  Y  otro_fin > mi_inicio
 *
 * Esa es exactamente la condicion que usa Tramo::solapadosCon(), y por eso no se
 * reimplementa aqui: la regla vive en el SQL, que la aplica el motor, y aqui solo
 * se traduce el resultado a un mensaje.
 *
 * ============================================================================
 * EL CAMBIO DE HORA: POR QUE UN TRAMO QUE LO CRUZA NO TIENE SOLUCION
 * ============================================================================
 *
 * El ultimo domingo de marzo, a las 02:00 en punto, el reloj salta a las 03:00.
 * Las 02:30 no existen. El ultimo domingo de octubre, a las 03:00, el reloj
 * vuelve a las 02:00, y las 02:30 occurre dos veces. Son las dos caras del mismo
 * problema: una hora de pared no es unico punto del tiempo.
 *
 * Para un tramo eso no es un detalle matematico. Si un tramo empieza a la 01:30 y
 * acaba a las 02:30 de un dia de octubre, la hora de fin se cumple dos veces, y no
 * hay forma de saber cual de las dos es la buena sin ambiguedad. Si un tramo
 * empieza a la 02:30 de marzo, esa hora no llega nunca y el tramo no serviria de
 * nada. En ambos casos la aplicacion no puede cumplir lo que el administrador ha
 * pedido, asi que lo dice en vez de aceptarlo en silencio y que luego falle en una
 * campana real.
 *
 * La ventana exacta se calcula con las transiciones que publica la base de datos de
 * zonas horarias del sistema, y no con una regla fija de «de dos a tres». Las
 * fechas cambian cada ano —en 2026 son el 29 de marzo y el 25 de octubre— y ademas
 * no todos los paises cambian a la misma hora. Preguntar al sistema es la unica
 * forma de no tener que actualizar el codigo cada primavera.
 *
 * ============================================================================
 * LO QUE ESTE SERVICIO NO HACE
 * ============================================================================
 *
 * No guarda nada. Los tramos se escriben a traves de \App\Models\Tramo, dentro de
 * una transaccion que abre el controlador. Un servicio que guardase por su cuenta
 * haria imposible «crear el tramo y sus cantidades, o no crear nada», que es la
 * garantia que el apartado 9 pide para cualquier operacion que modifique datos.
 *
 * @see \App\Models\Tramo::solapadosCon()
 * @see apartado 4.2 de la especificacion, dias y jornadas
 * @see decision D7 del documento de especificacion, zona horaria
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Validador;
use App\Models\Tramo;

/**
 * Validacion de los tramos de participacion.
 */
class Tramos
{
    /**
     * Zona horaria con la que se mira el cambio de hora.
     *
     * Se lee de la configuracion y no se escribe aqui a proposito. D7 fija
     * Europe/Madrid para la aplicacion, pero el unico sitio donde debe estar
     * escrito es la configuracion: si el supermercado instala esto en un pais con
     * otra zona, se cambia en un fichero y no en el codigo.
     *
     * @var string
     */
    private string $zonaHoraria;

    /**
     * Modelo de los tramos.
     *
     * @var \App\Models\Tramo
     */
    private Tramo $tramos;

    /**
     * Construye el servicio con la zona horaria de la aplicacion.
     */
    public function __construct()
    {
        $this->zonaHoraria = (string) \App\Core\Aplicacion::ajuste('app.zona_horaria', 'Europe/Madrid');
        $this->tramos = new Tramo();
    }

    /**
     * Convierte una hora «H:i:s» en el numero de minutos desde medianoche.
     *
     * @param string $hora Hora en formato «H:i:s» o «H:i».
     *
     * @return int Minutos desde medianoche, de 0 a 1439.
     */
    public static function aMinutos(string $hora): int
    {
        $partes = explode(':', $hora);

        $horas = isset($partes[0]) ? (int) $partes[0] : 0;
        $minutos = isset($partes[1]) ? (int) $partes[1] : 0;

        return $horas * 60 + $minutos;
    }

    /**
     * Convierte un numero de minutos en una hora «H:i:s».
     *
     * @param int $minutos Minutos desde medianoche.
     *
     * @return string Hora en formato «H:i:s».
     */
    public static function aHora(int $minutos): string
    {
        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return sprintf('%02d:%02d:00', $horas, $resto);
    }

    /**
     * Devuelve la duracion de un tramo en minutos.
     *
     * @param string $horaInicio Hora de inicio en formato «H:i:s».
     * @param string $horaFin    Hora de fin en formato «H:i:s».
     *
     * @return int Numero de minutos entre el inicio y el fin.
     */
    public static function duracion(string $horaInicio, string $horaFin): int
    {
        return self::aMinutos($horaFin) - self::aMinutos($horaInicio);
    }

    /**
     * Valida la forma de los campos de un tramo y anota los errores.
     *
     * ============================================================================
     * POR QUE SE USA EL VALIDADOR Y NO UNA EXCEPCION POR CADA COSA
     * ============================================================================
     *
     * El panel de tramos es una tabla con un formulario por fila. Si el
     * administrador corrige tres tramos a la vez y cada uno devuelve un error
     * distinto en una peticion, tendria que corregirlos de uno en uno. Con el
     * validador se accumulatean todos los errores y se devuelven juntos, que es lo
     * que el propio nucleo ya hace en la pantalla de participacion.
     *
     * @param Validador $validador  Acumulador de errores, ya con los campos
     *                              comunes comprobados.
     * @param string    $campo      Prefijo de los nombres de campo, por ejemplo
     *                              «tramo_7» para no mezclar los errores de dos
     *                              filas de la tabla.
     * @param string    $fecha      Fecha recibida.
     * @param string    $horaInicio Hora de inicio recibida.
     * @param string    $horaFin    Hora de fin recibida.
     *
     * @return array<string, string>|null Valores ya normalizados, o null si hay
     *                                  errores de forma.
     */
    public function validarCampos(
        Validador $validador,
        string $campo,
        string $fecha,
        string $horaInicio,
        string $horaFin
    ): ?array {
        $fechaValida = self::esFecha($fecha);
        $inicioValido = self::esHora($horaInicio);
        $finValido = self::esHora($horaFin);

        if (!$fechaValida) {
            $validador->anadirError($campo . '_fecha', 'La fecha no es válida. Usa el formato AAAA-MM-DD.');
        }

        if (!$inicioValido) {
            $validador->anadirError($campo . '_inicio', 'La hora de inicio no es válida. Usa el formato HH:MM.');
        }

        if (!$finValido) {
            $validador->anadirError($campo . '_fin', 'La hora de fin no es válida. Usa el formato HH:MM.');
        }

        if ($inicioValido && $finValido && self::aMinutos($horaFin) <= self::aMinutos($horaInicio)) {
            // El esquema tiene un CHECK para esto, pero el CHECK solo produce un
            // error de MariaDB con el nombre de la columna y el codigo 3819, que
            // no le dice a nadie que la hora de fin va antes que la de inicio. El
            // mensaje util se escribe aqui, y el CHECK se queda como la red de
            // seguridad para lo que se escriba por otra via.
            $validador->anadirError($campo . '_fin', 'La hora de fin tiene que ser posterior a la de inicio.');
        }

        if ($validador->tieneErrores()) {
            return null;
        }

        return [
            'fecha'       => $fecha,
            'hora_inicio' => self::aHora(self::aMinutos($horaInicio)),
            'hora_fin'    => self::aHora(self::aMinutos($horaFin)),
        ];
    }

    /**
     * Comprueba que un tramo no se solape con otro de la misma campana.
     *
     * @param Validador $validador  Acumulador de errores.
     * @param int       $promocionId Campana a la que pertenece el tramo.
     * @param string    $campo      Prefijo de los nombres de campo.
     * @param string    $fecha      Fecha del tramo.
     * @param string    $horaInicio Hora de inicio normalizada.
     * @param string    $horaFin    Hora de fin normalizada.
     * @param int|null  $excluirId  Tramo que se esta editando, para no
     *                               compararlo consigo mismo.
     *
     * @return void
     */
    public function comprobarSolapes(
        Validador $validador,
        int $promocionId,
        string $campo,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?int $excluirId = null
    ): void {
        $solapados = $this->tramos->solapadosCon(
            $promocionId,
            $fecha,
            $horaInicio,
            $horaFin,
            $excluirId
        );

        if ($solapados === []) {
            return;
        }

        // El mensaje dice cuanto se solapa y con que tramo, pero no da por hecho
        // que el administrador recuerde el horario que tiene puesto. Con una
        // campana de veinte tramos, decir «se solapa con el de las 16:00 a las
        // 21:00» es la diferencia entre arreglarlo en diez segundos y tener que
        // buscarlo.
        $horarios = [];

        foreach ($solapados as $otro) {
            $horarios[] = substr((string) $otro['hora_inicio'], 0, 5) . ' a ' . substr((string) $otro['hora_fin'], 0, 5);
        }

        $validador->anadirError(
            $campo . '_inicio',
            'Este tramo se solapa con el de las ' . implode(' y el de las ', $horarios) . ' del mismo día.'
        );
    }

    /**
     * Comprueba que el tramo no cruce el cambio de hora de verano.
     *
     * @param Validador $validador  Acumulador de errores.
     * @param string    $campo      Prefijo de los nombres de campo.
     * @param string    $fecha      Fecha del tramo.
     * @param string    $horaInicio Hora de inicio normalizada.
     * @param string    $horaFin    Hora de fin normalizada.
     *
     * @return void
     */
    public function comprobarCambioDeHora(
        Validador $validador,
        string $campo,
        string $fecha,
        string $horaInicio,
        string $horaFin
    ): void {
        $cambio = $this->cambioDeHora($fecha);

        if ($cambio === null) {
            return;
        }

        $inicio = self::aMinutos($horaInicio);
        $fin = self::aMinutos($horaFin);

        // El solape con la ventana se calcula en minutos, igual que la consulta
        // de solapes entre tramos, y por el mismo motivo: dos intervalos se
        // pisan cuando uno empieza antes de que el otro acabe.
        $desde = self::aMinutos($cambio['ventana_desde']);
        $hasta = self::aMinutos($cambio['ventana_hasta']);

        if ($inicio < $hasta && $fin > $desde) {
            $validador->anadirError(
                $campo . '_inicio',
                'Ese día cambia la hora: ' . $cambio['motivo']
                    . '. Ningún tramo puede empezar antes de las '
                    . substr($cambio['ventana_desde'], 0, 5) . ' ni acabar después de las '
                    . substr($cambio['ventana_hasta'], 0, 5) . '.'
            );
        }
    }

    /**
     * Devuelve el cambio de hora que hay en una fecha, si lo hay.
     *
     * La consulta se hace a la base de datos de zonas horarias del sistema con
     * timezone_transitions_get(), que devuelve los saltos de desplazamiento entre
     * dos instantes. Se le pasan las medianoches del dia, convertidas a marca de
     * tiempo, porque la funcion trabaja en UTC y las horas locales no existen para
     * ella.
     *
     * ============================================================================
     * POR QUE LA VENTANA SE CALCULA CON LOS DOS DESPLAZAMIENTOS
     * ============================================================================
     *
     * El instante del salto es el mismo antes y despues —en UTC no cambia—, pero
     * la hora de pared que le corresponde es distinta segun el desplazamiento que
     * se este mirando. En marzo, el salto de las 01:00 UTC son las 02:00 si se
     * mira con el desplazamiento de invierno y las 03:00 con el de verano.
     *
     * Asi que la ventana se construye con el minimo y el maximo de los dos
     * desplazamientos, y sale correcta en los dos casos sin tratar la primavera y
     * el otono por separado: en ambos sale el intervalo de una hora que va de las
     * 02:00 a las 03:00, que es exactamente la hora ambigua o inexistente.
     *
     * @param string $fecha Fecha en formato «A-n-j».
     *
     * @return array<string, mixed>|null Null si ese dia no hay cambio de hora, o
     *                                 un array con las claves «ventana_desde»,
     *                                 «ventana_hasta», «adelanta» y «motivo».
     */
    public function cambioDeHora(string $fecha): ?array
    {
        $zona = new \DateTimeZone($this->zonaHoraria);

        $inicioUtc = strtotime($fecha . ' 00:00:00 UTC');
        $finUtc = strtotime($fecha . ' 23:59:59 UTC');

        if ($inicioUtc === false || $finUtc === false) {
            return null;
        }

        $transiciones = timezone_transitions_get($zona, (int) $inicioUtc, (int) $finUtc);

        // El primer elemento del array que devuelve la funcion es el estado del
        // desplazamiento en el instante inicial, no una transicion. Sin al menos
        // dos elementos no ha habido ningun salto, que es el caso de cualquier
        // dia que no sea el ultimo domingo de marzo o de octubre.
        if (!is_array($transiciones) || count($transiciones) < 2) {
            return null;
        }

        $salto = $transiciones[1];
        $instante = (int) $salto['ts'];

        // «offset» es el desplazamiento que hay DESPUES del salto. El de antes se
        // obtiene pidiéndole a la propia zona cuanto desplazaba un segundo antes,
        // que es la unica forma de no suponer que el cambio es siempre de una
        // hora: hay zonas que saltan treinta minutos.
        $despues = $instante + (int) $salto['offset'];
        $antes = $instante + self::desplazamientoEn($zona, $instante - 1);

        $ventanaDesde = min($antes, $despues);
        $ventanaHasta = max($antes, $despues);

        $marcaDesde = self::horaDePared($ventanaDesde);
        $marcaHasta = self::horaDePared($ventanaHasta);

        // El salto hacia delante es el de marzo: las horas intermedias no llegan a
        // ocurrir. El hacia atras es el de octubre: ocurren dos veces. El motivo se
        // escribe en castellano claro porque lo lee alguien que no sabe lo que es
        // un desplazamiento horario.
        $adelanta = $despues > $antes;

        if ($adelanta) {
            $motivo = 'entre las ' . $marcaDesde . ' y las ' . $marcaHasta . ' no existe ninguna hora';
        } else {
            $motivo = 'entre las ' . $marcaDesde . ' y las ' . $marcaHasta . ' cada hora ocurre dos veces';
        }

        return [
            'ventana_desde' => $marcaDesde . ':00',
            'ventana_hasta' => $marcaHasta . ':00',
            'adelanta'     => $adelanta,
            'motivo'       => $motivo,
        ];
    }

    /**
     * Devuelve la hora de pared de una marca de tiempo, sin cambiar de zona.
     *
     * ============================================================================
     * POR QUE HACE FALTA ESTE METODO
     * ============================================================================
     *
     * Al pedir a PHP la hora de un instante en una zona horaria, devuelve la hora
     * local de esa zona. Aqui lo que se tiene es otra cosa: una marca de tiempo a
     * la que se le ha añadido el desplazamiento a proposito, de modo que al
     * leerla en UTC sale la hora de pared que marcaba el reloj de la campana.
     *
     * Si se hiciera al reves —convertir a la zona otra vez— el desplazamiento se
     * aplicaria dos veces y la ventana saldria corrida dos horas, que es como se
     * acaba prohibiendo un tramo de cuatro a cinco de la tarde de un domingo de
     * octubre, que es un tramo perfectamente bueno.
     *
     * @param int $marca Marca de tiempo ya desplazada.
     *
     * @return string Hora de pared en formato «H:i».
     */
    private static function horaDePared(int $marca): string
    {
        return gmdate('H:i', $marca);
    }

    /**
     * Devuelve el desplazamiento en segundos de una zona en un instante.
     *
     * @param \DateTimeZone $zona     Zona horaria.
     * @param int           $instante Marca de tiempo en segundos.
     *
     * @return int Desplazamiento respecto a UTC, en segundos.
     */
    private static function desplazamientoEn(\DateTimeZone $zona, int $instante): int
    {
        return (new \DateTime('@' . $instante))->setTimezone($zona)->getOffset();
    }

    /**
     * Devuelve las horas que existen de verdad dentro de un tramo.
     *
     * ============================================================================
     * EL PROBLEMA QUE ESTE METODO RESUELVE
     * ============================================================================
     *
     * Restar horas da un numero de minutos, pero no siempre son los minutos que
     * existen. El domingo 29 de marzo de 2026, en Espana, el reloj salta de las
     * 02:00 a las 03:00. Un tramo de la 01:00 a las 04:00 de ese dia tiene
     * «180 minutos» de diferencia entre sus horas, pero en realidad son 120: los
     * 60 minutos de las 02:00 no llegaron a existir.
     *
     * Eso importa por dos razones, y las dos son graves si se ignoran:
     *
     *   - Si la capacidad del tramo se calculara con la resta, un tramo de 01:00 a
     *     04:00 de ese dia admitiria 180 premios, pero solo se pueden colocar 120,
     *     y los ultimos 60 caerian en horas que no existen. MariaDB los aceptaria
     *     sin decir nada, y el motor de adjudicacion compararia contra un reloj
     *     que nunca marcara esa hora.
     *
     *   - Si el reparto usara la resta, colocaria premios en las 02:00 y las 02:30
     *     de un dia en el que esas horas no existen, que es exactamente el tipo de
     *     fila fantasma que despues nadie sabe explicar.
     *
     * La solucion es no calcular la lista por aritmetica, sino recorrer el tramo
     * minuto a minuto desde su hora de inicio y quedarse con los que existen. En
     * un dia sin cambio de hora, que es la mayoria, el atajo es directo: se usa la
     * cuenta normal y no se recorre nada. Solo cuando ese dia tiene cambio se
     * recorre, y son como mucho 1440 minutos.
     *
     * El ultimo minuto que devuelve es el anterior a la hora de fin, porque la
     * hora de fin ya es del turno siguiente. Una unidad colocada en la hora de fin
     * quedaria empatada con la primera del tramo siguiente, y el apartado 6 ordena
     * la cola por instante sin mirar el tramo.
     *
     * @param string $fecha      Fecha del tramo, en formato «A-n-j».
     * @param string $horaInicio Hora de inicio del tramo, en formato «H:i:s».
     * @param string $horaFin    Hora de fin del tramo, en formato «H:i:s».
     *
     * @return array<int, string> Horas «H:i:s» que existen dentro del tramo, en
     *                           orden ascendente, sin la hora de fin.
     */
    public function minutosValidos(string $fecha, string $horaInicio, string $horaFin): array
    {
        $desde = self::aMinutos($horaInicio);
        $hasta = self::aMinutos($horaFin);

        $cambio = $this->cambioDeHora($fecha);

        // Dia sin cambio de hora: la cuenta normal sirve y es exacta.
        if ($cambio === null) {
            $minutos = [];

            for ($m = $desde; $m < $hasta; $m++) {
                $minutos[] = self::aHora($m);
            }

            return $minutos;
        }

        $minutosDelHueco = $this->minutosDelHueco($cambio);
        $validos = [];

        for ($m = $desde; $m < $hasta; $m++) {
            $hora = self::aHora($m);

            if ($minutosDelHueco !== null && $hora >= $minutosDelHueco['desde'] && $hora < $minutosDelHueco['hasta']) {
                // Esta hora no existio ese dia: se salta.
                continue;
            }

            $validos[] = $hora;
        }

        // En el cambio de octubre las horas entre las 02:00 y las 03:00 existen
        // dos veces. El recorrido las encontrara repetidas, y el array_unique las
        // deja una. Un tramo asi no se deberia haber podido crear —el panel
        // rechaza los tramos con horas ambiguas—, pero si llegara desde otra via,
        // al menos no se generarian dos unidades identicas.
        return array_values(array_unique($validos));
    }

    /**
     * Devuelve el hueco de horas que no existen en el dia del cambio.
     *
     * Solo tiene sentido en el salto hacia delante. En el de octubre no hay horas
     * que no existan, sino horas que existen dos veces, y por eso devuelve null.
     *
     * @param array<string, mixed> $cambio Cambio devuelto por cambioDeHora().
     *
     * @return array<string, string>|null Hueco con las claves «desde» y «hasta»,
     *                                 o null si no hay horas inexistentes.
     */
    private function minutosDelHueco(array $cambio): ?array
    {
        if (!$cambio['adelanta']) {
            return null;
        }

        return [
            'desde' => (string) $cambio['ventana_desde'],
            'hasta' => (string) $cambio['ventana_hasta'],
        ];
    }

    /**
     * Comprueba que una fecha y una hora caen dentro de un tramo.
     *
     * ============================================================================
     * QUE HACE FALTA Y POR QUE NO BASTA COMPARAR HORAS
     * ============================================================================
     *
     * El panel de calendario permite anadir y mover unidades a mano, y ahi hay tres
     * cosas que un «if ($hora >= $inicio && $hora <= $fin)» no detectaria:
     *
     *   1. Que la fecha sea la del tramo. Una unidad a las 10:00 del dia 30 en un
     *      tramo del dia 27 no esta dentro del tramo, por muy buena que sea la hora,
     *      y se quedaria esperando en la cola hasta que llegara ese dia, dos dias
     *      despues de haberla creado el administrador que creyo que ya estaba.
     *
     *   2. Que la hora exista. En el cambio de hora de marzo, las 02:30 no existen:
     *      el reloj salta de las 02:00 a las 03:00. Aceptarla producen una unidad
     *      con una hora que MariaDB ordenaria bien pero que ningun reloj llegaria a
     *      marcar, y por tanto un premio que no se repartiria nunca.
     *
     *   3. Que la hora no sea exactamente la de fin. Un tramo que acaba a las 14:00
     *      no incluye las 14:00, porque esa hora ya es del turno siguiente, y el
     *      apartado 6 ordena la cola por instante sin mirar el tramo. Admitir el
     *      borde haria que una unidad del turno de manana quedara empatada con la
     *      primera del turno de tarde.
     *
     * @param string $fechaTramo  Fecha del tramo, en formato «A-n-j».
     * @param string $horaInicio  Hora de inicio del tramo, en formato «H:i:s».
     * @param string $horaFin     Hora de fin del tramo, en formato «H:i:s».
     * @param string $fecha       Fecha que se quiere comprobar.
     * @param string $hora        Hora que se quiere comprobar, en formato «H:i»
     *                             o «H:i:s».
     * @param string $campo       Prefijo de los nombres de campo del error.
     *
     * @return array<string, string> Errores encontrados, con el nombre del campo
     *                              como clave. Vacio si la fecha y la hora son
     *                              validas y caen dentro del tramo.
     */
    public function comprobarDentroDelTramo(
        string $fechaTramo,
        string $horaInicio,
        string $horaFin,
        string $fecha,
        string $hora,
        string $campo = 'inicio'
    ): array {
        $errores = [];

        if (!self::esFecha($fecha)) {
            $errores[$campo] = 'La fecha no es válida. Usa el formato AAAA-MM-DD.';

            return $errores;
        }

        if ($fecha !== $fechaTramo) {
            $errores[$campo] = 'La fecha debe ser la del tramo, que es el ' . self::fechaLegible($fechaTramo) . '.';

            return $errores;
        }

        if (!self::esHora($hora)) {
            $errores[$campo] = 'La hora no es válida. Usa el formato HH:MM.';

            return $errores;
        }

        $minuto = self::aMinutos($hora);
        $desde = self::aMinutos($horaInicio);
        $hasta = self::aMinutos($horaFin);

        if ($minuto < $desde || $minuto >= $hasta) {
            $errores[$campo] = 'La hora tiene que estar entre las '
                . substr($horaInicio, 0, 5) . ' y las ' . substr($horaFin, 0, 5) . ', sin incluir el final.';

            return $errores;
        }

        // La comprobacion del hueco de cambio de hora va despues de la de rango, y
        // a proposito: si la hora esta fuera del tramo, el problema es el rango, y
        // decir «esa hora no existe» de un tramo que no llega a esa hora seria
        // confuso.
        $cambio = $this->cambioDeHora($fecha);

        if ($cambio !== null && $cambio['adelanta']) {
            $horaNormalizada = self::aHora($minuto);

            if ($horaNormalizada >= $cambio['ventana_desde'] && $horaNormalizada < $cambio['ventana_hasta']) {
                $errores[$campo] = 'Las ' . substr($horaNormalizada, 0, 5) . ' no existen el '
                    . self::fechaLegible($fecha) . ': el reloj salta de las '
                    . substr($cambio['ventana_desde'], 0, 5) . ' a las '
                    . substr($cambio['ventana_hasta'], 0, 5) . ' ese día.';
            }
        }

        return $errores;
    }

    /**
     * Devuelve una fecha en el formato que se lee en un mensaje de error.
     *
     * ============================================================================
     * POR QUE HAY UN METODO PARA ESTO
     * ============================================================================
     *
     * La base de datos guarda las fechas en «2026-03-29», que es lo que entiende
     * MySQL y lo que hay que enviarle de vuelta. En un mensaje, en cambio, nadie
     * quiere leer «Las 02:30 no existen el 2026-03-29»: parece un identificador o
     * un numero de referencia, no un dia. Poner «el 29/03/2026» cuesta tres lineas
     * y evita tener que acordarse de hacerlo cada vez que se escribe un mensaje.
     *
     * @param string $fecha Fecha en formato «A-n-j».
     *
     * @return string Fecha en formato «d/m/A», o el texto original si no se puede
     *                convertir.
     */
    private static function fechaLegible(string $fecha): string
    {
        $instante = \DateTime::createFromFormat('Y-m-d', $fecha);

        if ($instante === false) {
            return $fecha;
        }

        return $instante->format('d/m/Y');
    }

    /**
     * Indica si un texto tiene forma de fecha «A-n-j» y existe de verdad.
     *
     * Se usa checkdate() y no solo una expresion regular, porque «2026-02-30» la
     * pasa cualquier expresion y no existe. Un tramo en el dia 30 de febrero es un
     * tramo que nunca va a ocurrir, y dejarlo pasar significa un dia sin
     * participaciones que nadie sabe explicar.
     *
     * @param string $fecha Texto recibido.
     *
     * @return bool True si la fecha existe.
     */
    public static function esFecha(string $fecha): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes) !== 1) {
            return false;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }

    /**
     * Indica si un texto tiene forma de hora «H:i» o «H:i:s».
     *
     * @param string $hora Texto recibido.
     *
     * @return bool True si la hora existe.
     */
    public static function esHora(string $hora): bool
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $hora, $partes) !== 1) {
            return false;
        }

        $horas = (int) $partes[1];
        $minutos = (int) $partes[2];
        $segundos = isset($partes[3]) ? (int) $partes[3] : 0;

        // Los segundos se aceptan pero solo valen 0: el esquema guarda la hora
        // como TIME y el generador trabaja con precision de minuto, asi que
        // permitir «10:00:30» crearia tramos que el calendario no podria repartir
        // sin salirse de ellos.
        return $horas >= 0 && $horas <= 23 && $minutos >= 0 && $minutos <= 59 && $segundos === 0;
    }
}
