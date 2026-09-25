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
