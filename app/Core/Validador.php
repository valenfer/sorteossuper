<?php

/**
 * Validador de datos de entrada.
 *
 * ============================================================================
 * POR QUE HAY UN VALIDADOR PROPIO Y NO LAS FUNCIONES DE PHP
 * ============================================================================
 *
 * PHP tiene filter_var y un conjunto de filtros, pero no cubren el caso que
 * necesita esta aplicacion: validar un conjunto de campos segun una
 * configuracion guardada en la base de datos y devolver TODOS los errores a la
 * vez, marcados por campo.
 *
 * Eso importa en la pantalla de participacion. Si una clienta deja el correo
 * vacio y el DNI con una letra, la azafata tiene que ver los dos problemas de
 * una vez. Un validador que se detiene en el primer error obliga a tres
 * viajes de ida y vuelta por la pantalla con la clienta esperando, que es la
 * peor situacion posible en un mostrador de un supermercado.
 *
 * ============================================================================
 * LOS MENSAJES ESTAN EN CASTELLANO Y NO REVELAN NADA
 * ============================================================================
 *
 * Cada mensaje explica que hay que corregir, sin repetir el valor recibido. Un
 * mensaje como «el correo 4f3a@ no es valido» incluiria datos tecleados por
 * error, y un mensaje como «ese DNI ya participa» revelaria informacion de otra
 * persona. El apartado 4.7 lo prohibe expresamente, asi que los mensajes de este
 * validador son genericos.
 *
 * @see \App\Core\ErrorValidacion
 * @see apartado 4.7 de la especificacion, requisitos de participacion
 * @see apartado 4.8 de la especificacion, campos del formulario
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Acumula los errores de validacion y comprueba las reglas campo a campo.
 */
class Validador
{
    /**
     * Errores detectados, indexados por nombre de campo.
     *
     * @var array<string, string>
     */
    private array $errores = [];

    /**
     * Datos ya limpiados y validados, listos para guardarse.
     *
     * @var array<string, mixed>
     */
    private array $datos = [];

    /**
     * Devuelve los errores detectados hasta ahora.
     *
     * @return array<string, string> Mensajes indexados por nombre de campo.
     */
    public function errores(): array
    {
        return $this->errores;
    }

    /**
     * Devuelve los datos validados.
     *
     * @return array<string, mixed> Valores ya limpiados.
     */
    public function datos(): array
    {
        return $this->datos;
    }

    /**
     * Indica si se ha detectado algun error.
     *
     * @return bool True si hay al menos un campo con error.
     */
    public function tieneErrores(): bool
    {
        return $this->errores !== [];
    }

    /**
     * Lanza una excepcion de validacion si hay errores acumulados.
     *
     * Se llama al final de un bloque de comprobaciones. Devuelve void porque su
     * unico proposito es cortar el flujo cuando algo falla.
     *
     * @param string $mensajeGeneral Texto que resume todos los errores, del
     *                               tipo «Revisa los campos marcados en rojo».
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si hay algun error registrado.
     */
    public function comprobar(string $mensajeGeneral = 'Revisa los campos marcados.'): void
    {
        if ($this->tieneErrores()) {
            throw new ErrorValidacion($mensajeGeneral, $this->errores);
        }
    }

    /**
     * Anota un error en un campo, si todavia no tiene uno.
     *
     * Se conserva el primer error de cada campo y no el ultimo a proposito: si
     * un campo esta vacio y ademas es demasiado corto, el mensaje que interesa
     * es el de que esta vacio, no el de longitud.
     *
     * @param string $campo   Nombre del campo con error.
     * @param string $mensaje Texto para la persona que lo rellena.
     *
     * @return void
     */
    public function anadirError(string $campo, string $mensaje): void
    {
        if (!isset($this->errores[$campo])) {
            $this->errores[$campo] = $mensaje;
        }
    }

    /**
     * Normaliza y valida un texto obligatorio con una longitud maxima.
     *
     * @param string $campo     Nombre del campo, para el indice de errores.
     * @param mixed  $valor     Valor recibido.
     * @param int    $maxLargo  Longitud maxima admitida.
     * @param int    $minLargo  Longitud minima admitida.
     *
     * @return string Valor limpio, o cadena vacia si no es valido.
     */
    public function textoObligatorio(string $campo, $valor, int $maxLargo = 200, int $minLargo = 1): string
    {
        $valor = self::aTexto($valor);

        // Se normalizan los espacios antes de medir, para que un campo con
        // espacios de mas no se rechace por longitud cuando el problema real
        // es que hay dos palabras pegadas.
        $valor = self::colapsarEspacios($valor);

        if ($valor === '') {
            $this->anadirError($campo, 'Este campo es obligatorio.');

            return '';
        }

        if (mb_strlen($valor, 'UTF-8') < $minLargo) {
            $this->anadirError($campo, 'Este campo es demasiado corto.');

            return '';
        }

        if (mb_strlen($valor, 'UTF-8') > $maxLargo) {
            $this->anadirError($campo, 'Este campo admite como mucho ' . $maxLargo . ' caracteres.');

            return '';
        }

        $this->datos[$campo] = $valor;

        return $valor;
    }

    /**
     * Normaliza y valida un campo de correo electronico.
     *
     * La comprobacion se hace con filter_var y despues con una expresion
     * regular mas exigente, porque el filtro de PHP acepta formas que luego
     * un servidor de correo rechazaria.
     *
     * @param string $campo Nombre del campo.
     * @param mixed  $valor Valor recibido.
     *
     * @return string Correo limpio en minusculas, o cadena vacia si falla.
     */
    public function correo(string $campo, $valor): string
    {
        $valor = self::aTexto($valor);

        if ($valor === '') {
            $this->anadirError($campo, 'Este campo es obligatorio.');

            return '';
        }

        if (mb_strlen($valor, 'UTF-8') > 190) {
            $this->anadirError($campo, 'Este campo es demasiado largo.');

            return '';
        }

        // El limite de 190 caracteres viene del protocolo SMTP: una direccion
        // mas larga no se podria entregar ni aunque fuese valida.
        if (filter_var($valor, FILTER_VALIDATE_EMAIL) === false) {
            $this->anadirError($campo, 'El correo electronico no tiene un formato valido.');

            return '';
        }

        // Comprobacion adicional de la parte que va tras la arroba. Se exige un
        // punto y un TLD de al menos dos letras, que es lo que aceptan hoy en
        // dia todos los proveedores de correo.
        if (preg_match('/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/u', $valor) !== 1) {
            $this->anadirError($campo, 'El correo electronico no tiene un formato valido.');

            return '';
        }

        // Se pasa a minusculas. Los correos no distinguen entre mayusculas y
        // minusculas en la parte del dominio, y en la local casi ninguno lo
        // hace, asi que guardarlos en minusculas evita tener dos cuentas
        // distintas para la misma persona.
        $valor = mb_strtolower($valor, 'UTF-8');

        $this->datos[$campo] = $valor;

        return $valor;
    }

    /**
     * Normaliza y valida un numero de telefono español.
     *
     * @param string $campo     Nombre del campo.
     * @param mixed  $valor     Valor recibido.
     * @param bool   $obligatorio Si false, un valor vacio se acepta.
     *
     * @return string Telefono normalizado, o cadena vacia si falla.
     */
    public function telefono(string $campo, $valor, bool $obligatorio = true): string
    {
        $valor = self::aTexto($valor);

        if ($valor === '') {
            if ($obligatorio) {
                $this->anadirError($campo, 'Este campo es obligatorio.');
            }

            return '';
        }

        // Se dejan solo los digitos. Una clienta puede escribir «911 22 33 44»,
        // «+34 911 22 33 44» o «911223344», y las tres formas son el mismo
        // numero. Guardarlo sin espacios ni simbolos es lo que permite
        // comparar despues con el mismo criterio.
        $soloDigitos = preg_replace('/[^0-9]/', '', $valor) ?? '';

        // Un telefono español tiene 9 digitos, con prefijo internacional de 34
        // si se escribe con el 34 delante: 34 seguido de los 9 digitos, o sea 11
        // en total. Ese es el unico prefijo que se acepta, y a proposito: si se
        // aceptara cualquier cantidad de digitos, «+1 234 567 890» pasaria la
        // comprobacion de longitud y llegaria hasta la base de datos.
        if (strlen($soloDigitos) === 9) {
            if (!self::digitosDeTelefonoValidos($soloDigitos)) {
                $this->anadirError($campo, 'El numero de telefono no parece correcto.');

                return '';
            }
        } elseif (strlen($soloDigitos) === 11 && strncmp($soloDigitos, '34', 2) === 0) {
            // Se quita el prefijo de Espana para validar y guardar los 9
            // digitos locales, de modo que «+34 600 11 22 33» y «600112233»
            // acaben en la misma cadena y la comparacion de la regla de
            // «una participacion por persona» los reconozca como la misma
            // persona.
            $local = substr($soloDigitos, 2);

            if (!self::digitosDeTelefonoValidos($local)) {
                $this->anadirError($campo, 'El numero de telefono no parece correcto.');

                return '';
            }

            $soloDigitos = $local;
        } else {
            $this->anadirError($campo, 'El telefono debe tener 9 digitos, con prefijo 34 opcional.');

            return '';
        }

        $this->datos[$campo] = $soloDigitos;

        return $soloDigitos;
    }

    /**
     * Comprueba que nueve digitos forman un telefono español posible.
     *
     * No se pretende validar contra la lista de prefijos asignados por la CNMC,
     * que ademas cambia con el tiempo. Lo que se busca es detectar al instante
     * los errores de tecleo mas frecuentes ante un mostrador, y eso se
     * consigue con las tres reglas de abajo, que entre las tres descartan la
     * practica totalidad de las erratas de una sola cifra:
     *
     *   1. El primer digito solo puede ser 6, 7, 8 o 9. Losmoviles empiezan
     *      por 6 o por 7 (este ultimo, los mas antiguos), y los fijos por 8 o
     *      por 9. Un 0 o un 1 inicial no existe en ningun caso, y lo mas probable
     *      es que se hayan intercambiado dos cifras.
     *   2. En un movil (6 o 7 inicial) el segundo digito no puede ser 0 ni 1.
     *   3. En un fijo (8 o 9 inicial) el segundo ni el tercero pueden ser 0 ni
     *      1, porque los prefijos fijos se emiten en bloques que empiezan por 8
     *      o 9 seguidos de dos o tres digitos no nulos.
     *
     * @param string $nueveDigitos Exactamente nueve digitos, sin signos.
     *
     * @return bool True si el numero es posible.
     */
    private static function digitosDeTelefonoValidos(string $nueveDigitos): bool
    {
        if (preg_match('/^[0-9]{9}$/', $nueveDigitos) !== 1) {
            return false;
        }

        $inicial = $nueveDigitos[0];

        // Regla 1: primer digito.
        if (!in_array($inicial, ['6', '7', '8', '9'], true)) {
            return false;
        }

        $esMovil = ($inicial === '6' || $inicial === '7');

        // Regla 2: segundo digito de un movil.
        if ($esMovil && in_array($nueveDigitos[1], ['0', '1'], true)) {
            return false;
        }

        // Regla 3: segundo y tercer digitos de un fijo.
        if (!$esMovil) {
            if (in_array($nueveDigitos[1], ['0', '1'], true)
                || in_array($nueveDigitos[2], ['0', '1'], true)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normaliza y valida un documento nacional de identidad.
     *
     * ============================================================================
     * NORMALIZACION
     * ============================================================================
     *
     * El DNI tiene ocho digitos y una letra. La letra no es aleatoria: se
     * calcula dividiendo la parte numerica entre 23 y tomando una letra de una
     * tabla. Esa comprobacion detecta la mayoria de los errores de tecleo, que
     * en un mostrador son muy frecuentes.
     *
     * ============================================================================
     * POR QUE SE GUARDA NORMALIZADO Y NO COMO SE ESCRIBE
     * ============================================================================
     *
     * Una misma persona puede escribir «12345678Z», «12345678 z» o
     * «12345678-z». Si se guardara tal cual, la regla de «una participacion por
     * persona» contaria tres participaciones distintas de la misma persona. Por
     * eso se normaliza a ocho digitos mas letra en mayuscula y sin separadores,
     * y solo esa forma normalizada se usa para comparar (decision D3).
     *
     * @param string $campo      Nombre del campo.
     * @param mixed  $valor      Valor recibido.
     * @param bool   $obligatorio Si false, un valor vacio se acepta.
     *
     * @return string DNI normalizado, o cadena vacia si falla.
     */
    public function dni(string $campo, $valor, bool $obligatorio = true): string
    {
        $valor = strtoupper(self::aTexto($valor));

        // Se quitan los separadores habituales: espacios, guiones y puntos.
        $valor = preg_replace('/[\s.\-]/u', '', $valor) ?? '';

        if ($valor === '') {
            if ($obligatorio) {
                $this->anadirError($campo, 'Este campo es obligatorio.');
            }

            return '';
        }

        // El formato debe ser exactamente ocho digitos y una letra.
        if (preg_match('/^[0-9]{8}[A-Z]$/', $valor) !== 1) {
            $this->anadirError($campo, 'El DNI debe tener ocho digitos y una letra.');

            return '';
        }

        // Comprobacion de la letra de control. El resto de dividir ocho digitos
        // entre 23 nunca es cero, asi que la division entera es exacta.
        $tabla = 'TRWAGMYFPDXBNJZSQVHLCKE';
        $indice = (int) ((int) substr($valor, 0, 8) % 23);

        if ($tabla[$indice] !== substr($valor, 8, 1)) {
            $this->anadirError($campo, 'El DNI no es correcto. Revisalo, por favor.');

            return '';
        }

        $this->datos[$campo] = $valor;

        return $valor;
    }

    /**
     * Normaliza y valida un numero entero dentro de un rango.
     *
     * @param string $campo    Nombre del campo.
     * @param mixed  $valor    Valor recibido.
     * @param int    $minimo   Valor minimo admitido.
     * @param int    $maximo   Valor maximo admitido.
     * @param bool   $obligatorio Si false, un valor vacio se acepta.
     *
     * @return int Valor convertido, o cero si falla.
     */
    public function entero(string $campo, $valor, int $minimo, int $maximo, bool $obligatorio = true): int
    {
        $valor = self::aTexto($valor);

        if ($valor === '') {
            if ($obligatorio) {
                $this->anadirError($campo, 'Este campo es obligatorio.');
            }

            return 0;
        }

        // Solo se admiten digitos con un signo opcional. Se descarta antes de
        // convertir, porque filter_var aceptaria «1e5» como cinco digitos.
        $esNumero = preg_match('/^-?[0-9]+$/', $valor) === 1;

        if (!$esNumero) {
            $this->anadirError($campo, 'Este campo debe ser un numero entero.');

            return 0;
        }

        $numero = (int) $valor;

        if ($numero < $minimo || $numero > $maximo) {
            $this->anadirError($campo, 'El valor debe estar entre ' . $minimo . ' y ' . $maximo . '.');

            return 0;
        }

        $this->datos[$campo] = $numero;

        return $numero;
    }

    /**
     * Comprueba que un campo obligatorio no este vacio.
     *
     * @param string $campo Nombre del campo.
     * @param mixed  $valor Valor recibido.
     *
     * @return bool True si tiene contenido.
     */
    public function obligatorio(string $campo, $valor): bool
    {
        if (self::aTexto($valor) === '') {
            $this->anadirError($campo, 'Este campo es obligatorio.');

            return false;
        }

        return true;
    }

    /**
     * Comprueba que un campo obligatorio de tipo casilla viene marcado.
     *
     * @param string $campo Nombre del campo.
     * @param mixed  $valor Valor recibido, del tipo que sea.
     *
     * @return bool True si esta marcado.
     */
    public function casillaObligatoria(string $campo, $valor): bool
    {
        $marcado = $valor !== null && $valor !== '' && $valor !== '0' && $valor !== 'false';

        if (!$marcado) {
            $this->anadirError($campo, 'Tienes que marcar esta casilla para continuar.');
        }

        return $marcado;
    }

    /**
     * Convierte cualquier valor a texto limpio.
     *
     * @param mixed $valor Valor de partida.
     *
     * @return string Texto recortado, o cadena vacia si no es texto.
     */
    public static function aTexto($valor): string
    {
        if ($valor === null || is_array($valor) || is_object($valor)) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return trim((string) $valor);
    }

    /**
     * Colapsa una secuencia de espacios por un solo espacio.
     *
     * @param string $texto Texto de partida.
     *
     * @return string Texto sin espacios repetidos ni saltos de linea sobrantes.
     */
    public static function colapsarEspacios(string $texto): string
    {
        // mb_convert_collapse mantiene la Ñ y las tildes como un solo caracter
        // al medirlos. Con preg_replace y el modificador u ocurre lo mismo, pero
        // se usa la funcion de mbstring por claridad.
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

        return trim($texto);
    }
}
