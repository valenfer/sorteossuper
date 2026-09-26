<?php

/**
 * Servicio de huellas de identidad.
 *
 * ============================================================================
 * QUE HACE Y POR QUE ESTA EN UN SERVICIO Y NO EN UN MODELO
 * ============================================================================
 *
 * Convierte un dato que identifica a una persona en una huella que se puede
 * indexar y comparar sin guardar el dato en claro. Lo usan, en este hito, la
 * lista de codigos validos, y en el hito 4 las reglas de «una participacion por
 * persona».
 *
 * No es un modelo porque no habla con ninguna tabla: es una funcion pura, y
 * aunque lo que produce acaba en la base de datos, la operacion en si —normalizar
 * y derivar— no es una consulta. Meterla en un modelo obligaria a crear un
 * objeto con una conexion a la base de datos para hacer una suma, que es lo que
 * se paga por la seguridad de que los modelos son los unicos que escriben SQL.
 *
 * ============================================================================
 * LA NORMALIZACION, Y POR QUE NO PUEDE SER OTRA
 * ============================================================================
 *
 * «Una participacion por ticket» solo significa algo si el mismo ticket se
 * reconoce al escribirlo de distintas formas. Un ticket puede llegar como
 * «AB-1234», «ab 1234», «  AB-1234  » o «ab1234», y son el mismo ticket. La
 * normalizacion los deja a todos en la misma forma antes de derivar la huella:
 *
 *   - Se pasan a minusculas con mb_strtolower y no con strtolower, porque un
 *     ticket puede llevar una «Ñ» o una tilde y strtolower solo funciona con el
 *     juego de caracteres de un byte.
 *   - Se quitan los espacios, incluidos los que hay dentro, que es lo que
 *     distingue un ticket bien escrito de uno tecleado de memoria.
 *   - Se quitan los separadores habituales —guiones, guiones bajos, puntos y
 *     barras— por la misma razon.
 *
 * La normalizacion es la decision mas delicate de D3, porque un unico indice
 * unico cubre cuatro reglas distintas y todas dependen de que dos personas
 * distintas nunca produzcan la misma cadena normalizada. Lo que NO se hace, y es
 * importante, es recortar a una longitud fija ni convertir numeros: si dos
 * tickets distintos tienen los mismos ultimos cuatro digitos, recortarlos haria
 * que el segundo pareciera el primero.
 *
 * ============================================================================
 * LA HUELLA
 * ============================================================================
 *
 * Es un HMAC-SHA256 con el secreto de la configuracion, codificado en
 * hexadecimal, que son los 64 caracteres que espera la columna CHAR(64).
 *
 * Se usa HMAC y no un hash simple porque el secreto es lo que hace que la huella
 * no se pueda calcular sin el. Sin secreto, quien tuviera una copia de la base de
 * datos podria probar «AB-1234» contra las huellas y saber si ese ticket esta en
 * la lista, que es justo lo que esta tabla existe para no revelar. Con secreto,
 * probar un million de tickets exige un million de HMAC, y el secreto no esta en
 * la base de datos sino en config/config.php, que no se versiona.
 *
 * Que la huella sea determinista es lo que permite el indice unico. Que no sea
 * reversible es lo que permite no guardar el dato.
 *
 * ============================================================================
 * EL AMBITO DENTRO DE LA HUELLA
 * ============================================================================
 *
 * Cada huella lleva dentro el ambito al que pertenece, y por eso un unico indice
 * unico (promocion_id, clave_unicidad) cubre a la vez «una por campana», «una por
 * dia» y «una por ticket»: «campana:7» y «campana:7|dia:2026-03-15» son cadenas
 * distintas, y por eso no chocan. Es la parte de D3 que mas problemas evita, y la
 * que esta comentada con mas detalle en el esquema, sobre reglas_participacion.
 *
 * @see \App\Models\CodigoValido
 * @see \App\Models\ReglaParticipacion
 * @see decision D3 del documento de especificacion, identidad de la persona
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\ErrorConfiguracion;

/**
 * Normalizacion y huella de los datos que identifican a una persona.
 */
class Huella
{
    /**
     * Longitud del resultado en hexadecimal de HMAC-SHA256.
     *
     * @var int
     */
    private const LONGITUD = 64;

    /**
     * Separa el ambito del dato dentro de la huella.
     *
     * El caracter es una barra vertical porque no puede aparecer en una clave de
     * campo ni en un identificador de ambito, y porque es lo que hace legible la
     * huella cuando alguien la mira para diagnosticar. No es que la huella sea
     * reversible: el ampersand solo separa, y la parte de la derecha es
     * igualmente el resultado del HMAC.
     *
     * @var string
     */
    public const SEPARADOR = '|';

    /**
     * Normaliza un dato de identidad para que dos formas de escribirlo coincidan.
     *
     * @param string $valor Valor tal como lo ha escrito la persona.
     *
     * @return string Forma normalizada, o cadena vacia si no queda nada.
     */
    public static function normalizar(string $valor): string
    {
        $normalizado = mb_strtolower(trim($valor), 'UTF-8');

        // Se quitan los espacios de cualquier tipo, incluidos los que no se ven.
        // Un tecleo en una tablet puede meter un espacio fino o un salto de linea
        // sin querer, y si el valor normalizado lo conserva, el mismo ticket
        // pasaria por dos RULE distintas y el indice unico no lo detectaria.
        $normalizado = preg_replace('/\s+/u', '', $normalizado) ?? '';

        // Y los separadores que usa la gente al dictar un ticket. No se quitan
        // todos los signos: solo los que aparecen entre dos grupos de letras o
        // digitos, que son los que existen para hacer el codigo legible.
        $normalizado = preg_replace('/[-_.\\/]+/u', '', $normalizado) ?? '';

        return $normalizado;
    }

    /**
     * Devuelve la huella de un dato dentro de un ambito.
     *
     * @param string $ambito Ambito al que pertenece el dato, por ejemplo
     *                        «campana:7», «campana:7|dia:2026-03-15» o
     *                        «ticket:AB1234».
     * @param string $valor  Dato sin normalizar; se normaliza aqui.
     *
     * @return string Huella de 64 caracteres en hexadecimal, o cadena vacia si el
     *                valor se queda sin nada tras normalizar.
     *
     * @throws \App\Core\ErrorConfiguracion Si el secreto HMAC no esta configurado.
     */
    public static function de(string $ambito, string $valor = ''): string
    {
        $normalizado = $valor === '' ? $ambito : self::normalizar($valor);

        if ($normalizado === '') {
            return '';
        }

        $secreto = (string) Aplicacion::ajuste('seguridad.secreto_hmac', '');

        // Sin secreto la huella seria un hash simple, que se puede calcular sin
        // acceso al servidor. Es mejor que la pantalla avise de que la
        // configuracion esta incompleta a que siga funcionando con una proteccion
        // que parece existir y no existe.
        if ($secreto === '') {
            throw ErrorConfiguracion::faltaClave('seguridad.secreto_hmac');
        }

        $ambitoNormalizado = trim($ambito);

        if ($ambitoNormalizado === '') {
            $contenido = $normalizado;
        } else {
            $contenido = $ambitoNormalizado . self::SEPARADOR . $normalizado;
        }

        return substr(hash_hmac('sha256', $contenido, $secreto), 0, self::LONGITUD);
    }

    /**
     * Devuelve el ambito de una campana, para una regla de duplicado global.
     *
     * @param int $promocionId Campana a la que pertenece la participacion.
     *
     * @return string Ambito con forma «campana:{id}».
     */
    public static function ambitoCampana(int $promocionId): string
    {
        return 'campana:' . $promocionId;
    }

    /**
     * Devuelve el ambito de un dia, para la regla de una participacion por dia.
     *
     * @param int    $promocionId Campana a la que pertenece la participacion.
     * @param string $fecha       Fecha en formato «A-n-j».
     *
     * @return string Ambito con forma «campana:{id}|dia:{fecha}».
     */
    public static function ambitoDia(int $promocionId, string $fecha): string
    {
        return self::ambitoCampana($promocionId) . self::SEPARADOR . 'dia:' . $fecha;
    }
}
