<?php

/**
 * Modelo de las participaciones validas de una campana.
 *
 * ============================================================================
 * POR QUE participaciones E intentos_rechazados SON DOS TABLAS Y NO UNA
 * ============================================================================
 *
 * Es la decision D10, y tiene una consecuencia tecnica que obliga a separarlas.
 * Esta tabla lleva un indice unico en (promocion_id, clave_unicidad) que
 * impide que una persona participe dos veces. Si los intentos rechazados se
 * guardaran aqui, un rechazo por estar ya dentro del limite haria fallar la
 * insercion de una segunda participacion valida de otra persona, y sobre todo
 * un rechazo por un motivo que no es de duplicado, como llegar fuera de
 * horario, podria ocupar el sitio de un indice unico sin motivo, haciendo que
 * una participacion valida posterior fallara por un error imposible de
 * entender.
 *
 * Por eso aqui solo entran participaciones que han superado todas las
 * comprobaciones, y los rechazos van a \App\Models\IntentoRechazado, que no
 * lleva ningun indice unico. Se siguen contando para el panel y para detectar
 * un intento de abusar, pero no bloquean nada.
 *
 * ============================================================================
 * LA CLAVE DE IDEMPOTENCIA
 * ============================================================================
 *
 * clave_idempotencia es un identificador del intento, de 36 caracteres, que
 * genera el navegador al abrir la pantalla 1 de la participacion y que viaja
 * hasta la pantalla de resultado. Un indice unico sobre
 * (promocion_id, clave_idempotencia) hace que un doble clic o una recarga
 * devuelvan el mismo resultado: la segunda insercion falla y el motor devuelve
 * la primera. Es el caso de aceptacion 7, y su implementacion esta descrita en
 * el comentario del propio esquema, junto a la columna.
 *
 * ============================================================================
 * LAS DOS HORAS QUE SE GUARDAN
 * ============================================================================
 *
 * momento es la hora REAL a la que se registro la participacion, y el instante
 * de la unidad adjudicada esta en adjudicada_en, dentro de unidades_premio. El
 * apartado 6 pide registrar ambos, y no son intercambiables: una participacion a
 * las 11:20 puede recibir un premio programado a las 10:12, y con una sola de
 * las dos horas no se podria ni explicar despues por que tardo doce minutos ni
 * auditar si el premio se entrego a tiempo.
 *
 * @see \App\Models\IntentoRechazado
 * @see \App\Services\Adjudicador
 * @see \App\Services\ValidadorReglas
 * @see apartado 6 de la especificacion, regla central de adjudicacion
 * @see decisiones D3, D4, D9, D10 y D18
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Modelo;
use App\Services\Huella;

/**
 * Acceso a la tabla de participaciones validas.
 */
class Participacion extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'participaciones';

    /**
     * Resultado de una participacion que se ha llevado un premio.
     *
     * @var string
     */
    public const RESULTADO_PREMIO = 'premio';

    /**
     * Resultado de una participacion valida a la que no le tocaba ningun premio.
     *
     * @var string
     */
    public const RESULTADO_SIN_PREMIO = 'sin_premio';

    /**
     * Busca la participacion de un intento anterior con la misma clave.
     *
     * Es la primera consulta que hace el motor, y la que convierte un doble clic
     * o una recarga en una no-operacion. Si encuentra la fila, el motor devuelve
     * su resultado tal cual y no vuelve a tocar la cola de premios: sin esto, un
     * doble clic podria dar dos premios a la misma persona, o uno a ella y otro
     * a la siguiente clienta de la cola.
     *
     * @param int    $promocionId       Campana de la participacion.
     * @param string $claveIdempotencia Clave del intento.
     *
     * @return array<string, mixed>|null La fila de la participacion, o null si
     *                                   este intento es nuevo.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function porClaveIdempotencia(int $promocionId, string $claveIdempotencia): ?array
    {
        return $this->db->uno(
            'SELECT id, resultado, momento, tramo_id
               FROM participaciones
              WHERE promocion_id = ?
                AND clave_idempotencia = ?
              LIMIT 1',
            [$promocionId, $claveIdempotencia]
        );
    }

    /**
     * Registra una participacion valida.
     *
     * El resultado va decided de antemano y no se toca despues. La columna es un
     * ENUM de dos valores, no admite un «pendiente», y anadirlo para dejar el
     * premio en duda hasta el final seria peor: dejaria participaciones a medias
     * si la transaccion se cortase, que es justo lo que la transaccion evita.
     *
     * La llamada va SIEMPRE dentro de la transaccion del motor. Por eso este
     * metodo no abre ni cierra transaccion: si la unidad no se puede entregar,
     * la participacion desaparece con ella y no queda el registro de un premio
     * que nadie recibio.
     *
     * @param int                   $promocionId       Campana de la participacion.
     * @param int                   $tramoId           Tramo en el que se ha
     *                                                 registrado. No puede ser
     *                                                 nulo: una participacion
     *                                                 valida siempre cae en un
     *                                                 tramo activo.
     * @param string                $claveIdempotencia Clave del intento.
     * @param string                $momento          Instante real de la
     *                                                 participacion.
     * @param string                $resultado        RESULTADO_PREMIO o
     *                                                 RESULTADO_SIN_PREMIO.
     * @param array<string, mixed>  $datos            Lo que ha escrito la clienta,
     *                                                 ya validado.
     * @param string|null           $claveUnicidad    Huella HMAC de la
     *                                                 identidad, o null si la
     *                                                 campana no tiene reglas de
     *                                                 duplicado.
     * @param int|null              $usuarioAzafataId Azafata que registro la
     *                                                 participacion.
     * @param bool                  $simulacion       True si la campana esta en
     *                                                 modo simulacion.
     *
     * @return int Identificador de la participacion creada.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla, includedo el
     *                                    caso de que la clave de unicidad ya
     *                                    este en uso por otra participacion.
     */
    public function crear(
        int $promocionId,
        int $tramoId,
        string $claveIdempotencia,
        string $momento,
        string $resultado,
        array $datos,
        ?string $claveUnicidad = null,
        ?int $usuarioAzafataId = null,
        bool $simulacion = false
    ): int {
        return $this->db->insertar(
            'INSERT INTO participaciones (
                 promocion_id,
                 tramo_id,
                 usuario_azafata_id,
                 clave_idempotencia,
                 clave_unicidad,
                 momento,
                 resultado,
                 datos,
                 es_simulacion,
                 creado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $promocionId,
                $tramoId,
                $usuarioAzafataId,
                $claveIdempotencia,
                $claveUnicidad,
                $momento,
                $resultado,
                $this->aJson($datos),
                $simulacion ? 1 : 0,
                $momento,
            ]
        );
    }

    /**
     * Indica si una clave de unicidad ya esta en uso en la campana.
     *
     * La comparacion no se hace con la identidad en claro sino con la huella
     * HMAC, que es lo unico que se guarda. Por eso la unica forma de responder
     * es haciendo la misma pregunta a la base de datos: no hay forma de
     * recalcular la huella de una participacion ya guardada, y no hace falta,
     * porque la huella de la identidad que llega en el intento se compara
     * directamente con la almacenada.
     *
     * La consulta toca el indice unico (promocion_id, clave_unicidad), que es
     * un indice compuesto y por tanto de lectura y no de recuento: responder si
     * existe una fila concreta cuesta lo mismo que leer la primera. Por eso el
     * LIMIT 1 no es una optimizacion sin importancia, sino el motivo de que la
     * comprobacion de duplicados quepa en la misma peticion que la adjudicacion
     * sin que se note.
     *
     * @param int    $promocionId   Campana en la que se busca.
     * @param string $claveUnicidad Huella HMAC de la identidad.
     *
     * @return bool True si ya hay una participacion con esa huella.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function existeClaveUnicidad(int $promocionId, string $claveUnicidad): bool
    {
        if ($claveUnicidad === '') {
            return false;
        }

        $existe = $this->db->valor(
            'SELECT 1 FROM participaciones
              WHERE promocion_id = ?
                AND clave_unicidad = ?
              LIMIT 1',
            [$promocionId, $claveUnicidad]
        );

        return $existe !== null;
    }

    /**
     * Indica si una identidad ya participar antes, en el dia indicado o no.
     *
     * ============================================================================
     * POR QUE ESTA CONSULTA NO USA EL INDICE UNICO
     * ============================================================================
     *
     * La columna clave_unicidad guarda una sola huella por participacion, la
     * canonica de ambito «campana», y el indice unico
     * (promocion_id, clave_unicidad) es el que garantiza la regla de una
     * participacion por campana. Las demas reglas de duplicado necesitan una
     * huella con otro ambito —«campana:7|dia:2026-03-15» o «ticket:abc123»— que
     * no cabe en esa columna porque hay una sola. Por eso se comprueban aqui, de
     * forma explicita, sobre el valor de identidad guardado en la columna datos.
     *
     * La normalizacion se hace en SQL, con LOWER y REPLACE, replicando lo que
     * hace \App\Services\Huella::normalizar(): minusculas, sin espacios y sin los
     * separadores que la gente pone al dictar un numero. Es una copia
     * consciente del metodo de PHP, no una coincidencia: si los dos dejaran de
     * coincidir, un ticket con espacios podria estar repetido y no contarse
     * como tal. Si alguna vez cambia Huella::normalizar, hay que cambiar aqui
     * tambien, y por eso la prueba de reglas mira justo este caso.
     *
     * La consulta no es indexada, y es una decision consciente. El numero de
     * participaciones de una campana de un supermercado cabe de sobra en memoria
     * y en disco, y esta consulta se ejecuta una vez por intento, con el bloqueo
     * de campana ya tomado por el motor. La alternativa —una columna y un indice
     * por cada regla— multiplica los indices para un tabla que no los necesita.
     *
     * @param int         $promocionId Campana en la que se busca.
     * @param string      $campo       Clave del campo dentro del JSON de datos.
     * @param string      $valor       Valor de identidad, sin normalizar.
     * @param string|null $fecha       Fecha en formato «A-n-j» para acotar al dia,
     *                                 o null para no acotar.
     *
     * @return bool True si esa identidad ya tiene una participacion.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function existeIdentidadEn(
        int $promocionId,
        string $campo,
        string $valor,
        ?string $fecha = null
    ): bool {
        if ($campo === '' || Huella::normalizar($valor) === '') {
            return false;
        }

        // La clave del campo la escribe el administrador, y se escapa para que
        // un campo con comillas o barra no pueda romper el JSON_PATH. Es el unico
        // dato que llega aqui de la configuracion y no de un conjunto cerrado, y
        // la consulta es parametrizada, pero la clave va dentro de una cadena de
        // MySQL y por eso necesita su propio escapado.
        $ruta = "$.\"" . str_replace(['\\', '"'], ['\\\\', '\\"'], $campo) . "\"";
        $normalizado = "LOWER(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE("
            . "JSON_UNQUOTE(JSON_EXTRACT(datos, ?)), ' ', ''), '\t', ''), '\n', ''), '-', ''), '_', '')))";

        $sql = 'SELECT 1 FROM participaciones
                  WHERE promocion_id = ?
                    AND ' . $normalizado . ' = ?';

        $parametros = [$promocionId, $ruta, Huella::normalizar($valor)];

        if ($fecha !== null && $fecha !== '') {
            $sql .= ' AND DATE(momento) = ?';
            $parametros[] = $fecha;
        }

        $sql .= ' LIMIT 1';

        return $this->db->valor($sql, $parametros) !== null;
    }

    /**
     * Vacia los datos personales de las participaciones de una campana.
     *
     * ============================================================================
     * POR QUE SE VACIA Y NO SE BORRA LA FILA
     * ============================================================================
     *
     * Borrar la fila parece lo natural y es lo que haria cualquiera que no haya
     * leido el apartado 6, que pide guardar una referencia inmutable entre la
     * participacion ganadora y la unidad adjudicada. Con la fila borrada:
     *
     *   - «ON DELETE SET NULL» de unidades_premio.participacion_id dejaria cada
     *     unidad sin decir que participacion gano el premio, que es justo lo que
     *     hay que poder demostrar.
     *   - El contador de participaciones del panel bajaria, y con el los
     *     porcentajes de premio que se ensegan a la direccion del comercio.
     *   - No se podria ni comprobar que un premio se entrego a quien se entrego,
     *     que es la pregunta que mas veces se hace despues de un sorteo.
     *
     * Asi que la fila se queda con lo que no identifica a nadie —momento, tramo,
     * resultado, el enlace a la unidad— y se vacia lo que si: el formulario, las
     * formas normalizadas y la huella de unicidad.
     *
     * ============================================================================
     * POR QUE «datos» SE QUEDA EN {} Y NO EN NULL
     * ============================================================================
     *
     * Porque la columna es NOT NULL y porque lleva un CHECK de JSON valido: NULL
     * no se podria, y un texto que no sea JSON lo rechazaria la base de datos. Un
     * objeto vacio dice exactamente lo que quiere decir —«aqui ya no hay datos»— y
     * ademas no duplica la fecha de purga, que ya esta en la columna purgada_en.
     *
     * Lo que tiene que hacer el codigo que lee «datos» despues de una purga es
     * tolerar que falten campos, y no dar por hecho que estan. El resultado de una
     * participacion ya purgada se enseña sin nombre, y eso es lo correcto.
     *
     * ============================================================================
     * POR QUE clave_unicidad TAMBIEN SE VACIA
     * ============================================================================
     *
     * Es un HMAC, no un DNI, y para alguien con el secreto no seria reversible. Pero
     * es un identificador estable de una persona, y dejarlo puesto haria que la
     * campana siguiera «conociendo» a quien participo aun despues de haber borrado
     * sus datos. Vaciarlo devuelve ademas la promesa del indice unico: si alguien
     * pidiera que la campana se reabriera, las filas purgadas ya no bloquean a
     * nadie, porque lo que las bloqueaba era precisamente lo que se ha borrado.
     *
     * ============================================================================
     * POR QUE ES IDEMPOTENTE Y POR QUE NO HACE FALTA UNA MARCA MAS
     * ============================================================================
     *
     * El WHERE filtra por «purgada_en IS NULL», que es la misma columna que deja
     * escrito. Una segunda pasada no encuentra filas y no cambia nada, y el guion
     * se puede ejecutar cada noche sin miedo, que es como se ejecuta un trabajo de
     * cron.
     *
     * @param int    $promocionId Campana que se purga.
     * @param string $momento     Instante en que se hace la purga.
     *
     * @return int Numero de participaciones que se han vaciado en esta pasada.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function purgar(int $promocionId, string $momento): int
    {
        return $this->db->ejecutar(
            'UPDATE participaciones
                SET datos = ?,
                    datos_normalizados = NULL,
                    clave_unicidad = NULL,
                    purgada_en = ?
              WHERE promocion_id = ?
                AND purgada_en IS NULL',
            ['{}', $momento, $promocionId]
        );
    }

    /**
     * Cuenta las participaciones de una campana que todavia tienen datos.
     *
     * Los mismos filtros que `purgar()`, porque una cuenta que no coincide con el
     * vaciado hace que la simulacion de la purga mienta.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return int Participaciones pendientes de vaciar.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPendientesDePurga(int $promocionId): int
    {
        return (int) $this->db->valor(
            'SELECT COUNT(*)
               FROM participaciones
              WHERE promocion_id = ?
                AND purgada_en IS NULL',
            [$promocionId]
        );
    }

    /**
     * Cuenta las participaciones de una campana por resultado.
     *
     * Lo usan las pruebas para comprobar que un rechazo no ha dejado rastro
     * como participacion, y lo usara el panel de seguimiento.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return array<string, int> Resultados como claves y recuentos como valores.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPorResultado(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT resultado, COUNT(*) AS total
               FROM participaciones
              WHERE promocion_id = ?
              GROUP BY resultado',
            [$promocionId]
        );

        $recuento = [];

        foreach ($filas as $fila) {
            $recuento[(string) $fila['resultado']] = (int) $fila['total'];
        }

        return $recuento;
    }

    /**
     * Convierte los datos de la participacion en el JSON que se guarda.
     *
     * La columna datos es un LONGTEXT con una restriccion CHECK que exige JSON
     * valido. Se serializa con JSON_UNESCAPED_UNICODE para que una enye se
     * guarde como «ñ» y no como «ñ»: el panel y el correo reconstruyen
     * el texto, y una secuencia de escapes se veria rara si alguien llegase a
     * mirar el campo en crudo.
     *
     * @param array<string, mixed> $datos Datos de la participacion.
     *
     * @return string Texto JSON, nunca vacio.
     */
    private function aJson(array $datos): string
    {
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Con los datos que llegan del formulario, que son cadenas y numeros
        // simples, json_encode no puede fallar. Se comprueba de todos modos para
        // que un fallo aqui no acabe escribiendo una cadena vacia en la columna
        // y saltandose la restriccion CHECK sin que nadie se entere.
        if ($json === false) {
            return '{}';
        }

        return $json;
    }
}
