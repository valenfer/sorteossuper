<?php

/**
 * Verificador de la documentacion y de las reglas del proyecto.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE HACE FALTA
 * ============================================================================
 *
 * La especificacion obliga a documentar el codigo de forma exhaustiva, y esa
 * obligacion es facil de cumplir hoy y de perder mañana. En cuanto hay veinte
 * ficheros, nadie lee ya los comentarios de los ficheros que acaba de escribir.
 *
 * Por eso este script convierte las reglas en un programa que se ejecuta. Si
 * falta un @param, el script falla. Si aparece una palabra en un idioma que no
 * toca, falla. Si alguien deja un print de depuracion, falla. No es una ayuda:
 * es la unica forma de que la regla siga valiendo en el hito 9.
 *
 * ============================================================================
 * QUE REGLAS APLICA, Y POR QUE CADA UNA
 * ============================================================================
 *
 * Las reglas estan en una constante al principio del script, todas juntas y a
 * la vista, para que se puedan leer de un tirón y cambiar sin buscar por el
 * codigo. Son de tres tipos:
 *
 *   1. Documentacion: fichero, clases, metodos, y etiquetas PHPDoc completas.
 *   2. Higiene del texto: codificacion, finales de linea, espacios, y que la
 *      prosa este en castellano.
 *   3. Dependencias prohibidas: nada de frameworks, ni CDN, ni funciones
 *      peligrosas. Es la decision D14, y es la que mas conviene automatizar,
 *      porque basta con que alguien pegue un ejemplo de Bootstrap de internet
 *      para que entre una dependencia en un proyecto que no las tiene.
 *
 * ============================================================================
 * UN DETALLE SOBRE EL IDIOMA, QUE NO ES COSA ESTETICA
 * ============================================================================
 *
 * La prosa de los comentarios va en castellano y las etiquetas PHPDoc
 * (@param, @return, @throws) van en ingles, que es lo que cualquier generador de
 * documentacion espera. El script comprueba las dos cosas por separado.
 *
 * Y hay una comprobacion mas, que parece rara y no lo es: se busca cualquier
 * caracter que no pertenezca al castellano ni a los simbolos ASCII. Es una red
 * de seguridad contra un error que se cuela al escribir deprisa, del tipo de
 * una palabra en cirilico o en chino metida en un comentario. No busca faltas de
 * ortografia, busca caracteres de otro alfabeto, y en un proyecto en castellano
 * cualquier aparicion es un error de tecleo.
 *
 * @see config\config.example.php
 * @see decisiones D14 y D15 del documento de especificacion
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/inicio.php';

use App\Core\Aplicacion;

/**
 * Reglas que el proyecto tiene que cumplir siempre.
 *
 * Se declaran aqui y no repartidas por el script porque tienen que poder leerse
 * seguidas: si una regla viviera dentro de la funcion que la comprueba, nadie
 * sabria cuantas hay.
 *
 * @var array<string, array<int, array<string, mixed>>>
 */
const REGLAS = [
    // -------------------------------------------------------------------------
    // 1. Documentacion
    // -------------------------------------------------------------------------
    'documentacion' => [
        [
            'titulo'  => 'Cada fichero PHP empieza con un comentario de cabecera',
            'gravedad' => 'error',
        ],
        [
            'titulo'  => 'Cada clase tiene su comentario de descripcion',
            'gravedad' => 'error',
        ],
        [
            'titulo'  => 'Cada metodo tiene su comentario PHPDoc',
            'gravedad' => 'error',
        ],
        [
            'titulo'  => 'Cada @param esta documentado y en el orden de la firma',
            'gravedad' => 'error',
        ],
        [
            'titulo'  => 'Cada metodo declara @return',
            'gravedad' => 'error',
        ],
        [
            'titulo'  => 'Las etiquetas PHPDoc estan en ingles',
            'gravedad' => 'error',
        ],
        [
            'titulo'  => 'La prosa de los comentarios esta en castellano',
            'gravedad' => 'error',
        ],
    ],

    // -------------------------------------------------------------------------
    // 2. Higiene del texto
    // -------------------------------------------------------------------------
    'higiene' => [
        [
            'titulo'   => 'El fichero no empieza con marca de orden de bytes',
            'gravedad' => 'error',
            'motivo'   => 'Con la marca, la sentencia declare(strict_types=1) deja de ser '
                . 'la primera del script y PHP da un error fatal.',
        ],
        [
            'titulo'   => 'El fichero termina en salto de linea',
            'gravedad' => 'error',
            'motivo'   => 'Sin el, cualquier parche posterior muestra el cambio entero como '
                . 'una sola linea.',
        ],
        [
            'titulo'   => 'No hay espacios al final de las lineas',
            'gravedad' => 'aviso',
        ],
        [
            'titulo'   => 'No hay tabuladores en la sangria',
            'gravedad' => 'error',
            'motivo'   => 'En un fichero que van a leer varias personas, cada una con su '
                . 'editor, la mezcla de tabuladores y espacios produce un diff ilegible.',
        ],
        [
            'titulo'   => 'La codificacion es UTF-8 valido',
            'gravedad' => 'error',
            'motivo'   => 'Un byte suelto haria que el navegador mostrara caracteres raros en '
                . 'lugar de la enye de un nombre.',
        ],
    ],

    // -------------------------------------------------------------------------
    // 3. Dependencias prohibidas y restos de depuracion
    // -------------------------------------------------------------------------
    'dependencias' => [
        [
            'titulo'    => 'No se importan clases de frameworks ni de bibliotecas externas',
            'gravedad'  => 'error',
            'patron'    => '/^\s*use\s+(Bootstrap|Twig|Symfony|Laravel|Illuminate|jQuery|'
                . 'Illuminate\\\\|Psr\\\\|Monolog\\\\|Doctrine\\\\|PHPUnit\\\\)/mi',
            'mensaje'   => 'Decision D14: cero dependencias. Todo lo que haga falta va en app/.',
        ],
        [
            'titulo'    => 'No hay referencias a CDNs ni a recursos de otros dominios',
            'gravedad'  => 'error',
            'patron'    => '#(https?:)?//(?!localhost|127\.0\.0\.1)[a-z0-9.-]+\.[a-z]{2,}/#i',
            'mensaje'   => 'Decision D14: una aplicacion que depende de la red deja de '
                . 'funcionar en un local sin conexion. Los assets son locales.',
        ],
        [
            'titulo'    => 'No hay llamadas a eval',
            'gravedad'  => 'error',
            'patron'    => '/\beval\s*\(/',
            'mensaje'   => 'Ejecutar codigo de una cadena convierte cualquier inyeccion en '
                . 'ejecucion de codigo.',
        ],
        [
            'titulo'    => 'No hay restos de depuracion',
            'gravedad'  => 'error',
            'patron'    => '/(?<![\w$>])(var_dump|print_r|debug_zval_refcount|debug_zval_dump)\s*\(/i',
            'mensaje'   => 'Un var_dump que se queda en el codigo imprime datos personales '
                . 'de una clienta en la pantalla de una tienda.',
        ],
        [
            'titulo'    => 'No hay llamadas a die ni a exit en la aplicacion web',
            'gravedad'  => 'error',

            // Esta regla se aplica solo a app/, que es donde vive el codigo con
            // clases y metodos. Fuera de ahi el script mas corto tiene su
            // propia logica y sus propias razones:
            //
            //   - En index.php, el arranque responde «aun no estas instalado»
            //    y termina con exit. Es el final de la peticion, no un fallo a
            //    medias: la respuesta ya esta escrita entera y no hay ninguna
            //    clase a la que volver.
            //   - En bin/ y en tests/, exit() con un codigo de salida es la
            //    manera correcta de terminar, y es lo que necesitan para poder
            //    encadenarse en un guion.
            'solo'      => 'app',
            'patron'    => '/(?<![\w$>])(die|exit)\s*(\(|;|\?)/',
            'mensaje'   => 'exit() dentro de un metodo deja la peticion a medias, sin '
                . 'cabeceras coherentes. Para cortar la peticion se lanza una excepcion.',
        ],
    ],
];

/**
 * Analiza los argumentos de la linea de comandos.
 *
 * Solo se aceptan opciones con doble guion y sin valor.
 *
 * @param array<int, string> $argumentos Argumentos de $argv.
 *
 * @return array<string, string|bool> Opciones pedidas, indexadas por su nombre
 *                                   sin guiones. Una opcion sin valor vale true;
 *                                   una con valor lo guarda.
 */
function argumentos(array $argumentos): array
{
    $opciones = [];

    foreach ($argumentos as $argumento) {
        if (strncmp($argumento, '--', 2) !== 0) {
            continue;
        }

        $cuerpo = substr($argumento, 2);
        $igual = strpos($cuerpo, '=');

        if ($igual === false) {
            $opciones[strtolower($cuerpo)] = true;
            continue;
        }

        $opciones[strtolower(substr($cuerpo, 0, $igual))] = substr($cuerpo, $igual + 1);
    }

    return $opciones;
}

/**
 * Una prueba de la suite, con su titulo y los pasos que cubre.
 *
 * Se declaran aqui y no en el fichero de pruebas porque este script es quien
 * decide que caso se ejecuta y en que orden se muestran, y tener la lista a la
 * vista en el mismo sitio evita que se desincronicen.
 *
 * @var array<int, array<string, mixed>>
 */
const CASOS = [
    0 => [
        'titulo' => 'El nucleo arranca y el esquema esta completo',
        'pasos'  => [
            'La clase Aplicacion esta cargada y la configuracion se lee.',
            'La zona horaria de la aplicacion es Europe/Madrid.',
            'El secreto de HMAC no es el de ejemplo.',
            'La conexion con la base de datos responde.',
            'Las quince tablas del esquema existen.',
        ],
    ],
    1 => [
        'titulo' => 'Las reglas de validacion del nucleo',
        'pasos'  => [
            'Los correos se normalizan a minusculas y se rechazan los mal formados.',
            'Los telefonos moviles se aceptan y los imposibles se rechazan.',
            'Los DNI se normalizan y se comprueba la letra.',
            'Los campos obligatorios vacios producen error.',
        ],
    ],
    2 => [
        'titulo' => 'El escapado de salidas y el token CSRF',
        'pasos'  => [
            'Vista::e() escapa las comillas y las etiquetas.',
            'Vista::e() no rompe con un byte no valido.',
            'El token CSRF es estable dentro de una peticion y se puede comprobar.',
        ],
    ],
    3 => [
        'titulo' => 'El motor de plantillas no se ejecuta en bucle',
        'pasos'  => [
            'Renderizar una vista devuelve la maquetacion envuelta una sola vez.',
            'El contenido de la vista aparece dentro de la maquetacion.',
        ],
    ],
];

/**
 * Problemas encontrados, agrupados por fichero.
 *
 * @var array<string, array<int, array{gravedad: string, regla: string, linea: int, detalle: string}>>
 */
$problemas = [];

/**
 * Anota un problema encontrado.
 *
 * @param string $gravedad 'error' o 'aviso'.
 * @param string $regla    Titulo de la regla incumplida.
 * @param string $fichero  Ruta relativa del fichero.
 * @param int    $linea    Numero de linea, o 0 si no se sabe.
 * @param string $detalle  Explicacion concreta.
 *
 * @return void
 */
function anotar(string $gravedad, string $regla, string $fichero, int $linea, string $detalle): void
{
    global $problemas;

    $problemas[$fichero][] = [
        'gravedad' => $gravedad,
        'regla'    => $regla,
        'linea'    => $linea,
        'detalle'  => $detalle,
    ];
}

/**
 * Devuelve los ficheros PHP del proyecto, en orden y saltando los ignorados.
 *
 * Se recorren a mano en lugar de usar un glob recursivo con comodines porque el
 * recuento de ficheros tiene que ser controlado: si el recorrido metiera por
 * error una carpeta de pruebas antiguas o de un despliegue, el verificador
 * fallaria por culpa de ficheros que no forman parte del proyecto.
 *
 * @param string $raiz Raiz del proyecto.
 *
 * @return array<int, string> Rutas absolutas de los ficheros PHP.
 */
function ficherosPhp(string $raiz): array
{
    $carpetas = [
        '/app',
        '/bin',
        '/tests',
    ];

    $ficheros = [];

    // La raiz se comprueba de forma explicita. index.php esta fuera de app y es
    // el punto de entrada, asi que un recorrido de solo app/ se lo dejaria fuera
    // sin avisar.
    foreach (['/index.php'] as $suelto) {
        if (is_file($raiz . $suelto)) {
            $ficheros[] = $raiz . $suelto;
        }
    }

    foreach ($carpetas as $carpeta) {
        $ruta = $raiz . $carpeta;

        if (!is_dir($ruta)) {
            continue;
        }

        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterador as $entrada) {
            if ($entrada->isFile() && strtolower($entrada->getExtension()) === 'php') {
                $ficheros[] = $entrada->getPathname();
            }
        }
    }

    // Los ficheros que empiezan por _ son auxiliares privados de las pruebas y
    // no forman parte de la aplicacion. Se omiten para no avisar de problemas
    // en codigo que ya nadie va a leer.
    $ficheros = array_filter($ficheros, static function (string $fichero): bool {
        return basename($fichero)[0] !== '_';
    });

    sort($ficheros);

    return array_values($ficheros);
}

/**
 * Devuelve el numero de linea en el que empieza un elemento del flujo de
 * tokens, contando desde el principio del fichero.
 *
 * Se cuenta a mano, linea a linea, en lugar de buscar con strpos, porque buscar
 * devolveria la primera aparicion del texto, que puede estar antes en un
 * comentario.
 *
 * @param string $codigo Texto completo del fichero.
 * @param int    $offset Posicion en el texto a partir de la cual contar.
 *
 * @return int Numero de linea, contando desde 1.
 */
function lineaDe(string $codigo, int $offset): int
{
    $linea = 1;
    $longitud = min($offset, strlen($codigo));

    for ($i = 0; $i < $longitud; $i++) {
        if ($codigo[$i] === "\n") {
            $linea++;
        }
    }

    return $linea;
}

/**
 * Comprueba las reglas de higiene del fichero sobre su texto crudo.
 *
 * Se trabaja sobre el texto y no sobre los tokens porque estas reglas miran
 * bytes, no codigo: la marca de orden de bytes, el salto de linea final y los
 * espacios al final no se ven despues de analizar el fichero.
 *
 * @param string $codigo   Contenido del fichero.
 * @param string $fichero  Ruta relativa, para los mensajes.
 * @param string $relativa Ruta relativa real, para el informe.
 *
 * @return void
 */
function comprobarHigiene(string $codigo, string $fichero, string $relativa): void
{
    // Marca de orden de bytes. Es la comprobacion mas importante de este
    // bloque, porque su presencia hace que PHP de un error fatal y no un aviso:
    // un fichero con BOM no arranca, y el sintoma («strict_types must be the
    // first statement») no dice nada de la causa real.
    if (strncmp($codigo, "\xEF\xBB\xBF", 3) === 0) {
        anotar(
            'error',
            'El fichero no empieza con marca de orden de bytes',
            $relativa,
            1,
            'Empieza por los bytes EF BB BF. Hay que guardarlo como UTF-8 sin BOM.'
        );
    }

    if ($codigo !== '' && substr($codigo, -1) !== "\n") {
        anotar(
            'error',
            'El fichero termina en salto de linea',
            $relativa,
            substr_count($codigo, "\n") + 1,
            'La ultima linea se queda sin el salto de linea que tiene que tener.'
        );
    }

    $lineas = explode("\n", $codigo);

    foreach ($lineas as $indice => $lineaActual) {
        $numero = $indice + 1;

        if (rtrim($lineaActual, "\r") !== rtrim($lineaActual, " \t\r")) {
            anotar(
                'aviso',
                'No hay espacios al final de las lineas',
                $relativa,
                $numero,
                'La linea termina en espacios.'
            );
        }

        if (strpos($lineaActual, "\t") !== false) {
            // Se comprueba que el tabulador sea de sangria. Un tabulador dentro
            // de un texto, por ejemplo una marca de este comentario, es
            // legitimo y no debe avisar.
            if (preg_match('/^\t+/', $lineaActual) === 1) {
                anotar(
                    'error',
                    'No hay tabuladores en la sangria',
                    $relativa,
                    $numero,
                    'La sangria empieza con tabulador. Usa cuatro espacios.'
                );
            }
        }
    }

    // La codificacion se comprueba convirtiendo el texto de vuelta. mb_convert
    // devuelve el texto original si es valido, y lanza excepcion si no lo es.
    if (!mb_check_encoding($codigo, 'UTF-8')) {
        anotar(
            'error',
            'La codificacion es UTF-8 valido',
            $relativa,
            0,
            'El fichero tiene bytes que no forman un texto UTF-8 valido.'
        );
    }
}

/**
 * Comprueba la prosa de un comentario: que este en castellano.
 *
 * La busqueda se hace sobre el texto de los comentarios ya extraidos, y no
 * sobre el fichero entero, para no confundir el castellano de un nombre de
 * clase o de una tabla, que van en ingles por convenio.
 *
 * @param string $texto    Texto del comentario.
 * @param string $relativa Ruta del fichero.
 * @param int    $linea    Linea en la que empieza.
 *
 * @return void
 */
function comprobarIdioma(string $texto, string $relativa, int $linea): void
{
    // Se buscan los bloques de alfabetos que no tienen nada que ver con el
    // castellano: cirilico, chino, japones, coreano, arabe, hebreo, devanagari y
    // los alfabetos griegos. Los acentos y la enye se permiten, porque son
    // precisamente lo que distingue el castellano de un fichero que se ha
    // generado con la configuracion regional por defecto de un ingles.
    $alfabetos = [
        'cirilico'   => '/\p{Cyrillic}/u',
        'griego'     => '/\p{Greek}/u',
        'arabe'      => '/\p{Arabic}/u',
        'hebreo'     => '/\p{Hebrew}/u',
        'devanagari' => '/\p{Devanagari}/u',
        'chino'      => '/\p{Han}/u',
        'japones'    => '/\p{Hiragana}|\p{Katakana}/u',
        'coreano'    => '/\p{Hangul}/u',
    ];

    foreach ($alfabetos as $nombre => $patron) {
        if (preg_match($patron, $texto, $coincidencia) === 1) {
            $posicion = strpos($texto, $coincidencia[0]);

            $detalle = 'Aparece un caracter del alfabeto ' . $nombre . ' («' . $coincidencia[0]
                . '») a mitad de un comentario en castellano. Suele ser una palabra que se '
                . 'ha colado de otro idioma al escribir deprisa.';

            if ($posicion !== false) {
                $trozo = substr($texto, max(0, $posicion - 30), 70);
                $detalle .= ' Contexto: «' . preg_replace('/\s+/u', ' ', $trozo) . '».';
            }

            anotar('error', 'La prosa de los comentarios esta en castellano', $relativa, $linea, $detalle);
            return;
        }
    }
}

/**
 * Comprueba que las etiquetas PHPDoc de un bloque estan en ingles.
 *
 * Se comprueba el nombre de la etiqueta, no su contenido. El contenido va en la
 * lengua que se hable: los parametros se describen en castellano, y el
 * nombre de la etiqueta tiene que ser el que espera cualquier generador de
 * documentacion.
 *
 * @param string $bloque   Texto del bloque PHPDoc.
 * @param string $relativa Ruta del fichero.
 * @param int    $linea    Linea de inicio del bloque.
 *
 * @return void
 */
function comprobarEtiquetas(string $bloque, string $relativa, int $linea): void
{
    // Etiquetas PHPDoc válidas, en la forma en que las escribe phpDocumentor.
    // Una que no esté en esta lista es un error, porque phpDocumentor la
    // descartaría en silencio y el texto se perdería de la documentación.
    $validas = [
        'param', 'return', 'throws', 'var', 'see', 'since', 'deprecated',
        'author', 'copyright', 'license', 'package', 'subpackage', 'example',
        'uses', 'used-by', 'inheritdoc', 'template', 'psalm-', 'phpstan-',
    ];

    preg_match_all('/@([a-zA-Z][a-zA-Z0-9_-]*)/', $bloque, $coincidencias);

    foreach ($coincidencias[1] as $etiqueta) {
        $permitida = false;

        foreach ($validas as $valida) {
            if (strncmp($etiqueta, $valida, strlen($valida)) === 0) {
                $permitida = true;
                break;
            }
        }

        if (!$permitida) {
            anotar(
                'error',
                'Las etiquetas PHPDoc estan en ingles',
                $relativa,
                $linea,
                'La etiqueta @' . $etiqueta . ' no es una etiqueta PHPDoc estandar. '
                . 'Las que se usan en este proyecto son: ' . implode(', ', $validas) . '.'
            );
        }
    }
}

/**
 * Comprueba la documentacion de un fichero, mediante su flujo de tokens.
 *
 * Analizar el fichero con token_get_all y no con expresiones regulares sobre
 * el texto tiene una ventaja decisiva: distingue el codigo de los comentarios.
 * Una expresion regular no sabe si una palabra esta dentro de un /* ... *\/ o
 * fuera, y acabaria buscando @param en el codigo y esperando que no aparezca.
 *
 * @param string $codigo    Contenido del fichero.
 * @param string $relativa  Ruta relativa, para los mensajes.
 * @param string $absoluta  Ruta absoluta, para los mensajes.
 *
 * @return void
 */
function comprobarDocumentacion(string $codigo, string $relativa, string $absoluta): void
{
    $tokens = token_get_all($codigo);

    // El flujo empieza con el de apertura «<?php». El indice del primer token
    // util es el siguiente.
    $total = count($tokens);

    // -------------------------------------------------------------------------
    // 1. Comentario de cabecera del fichero.
    // -------------------------------------------------------------------------
    $cabecera = null;

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];

        // Se salta la etiqueta de apertura, los espacios y los comentarios que
        // no sean de bloque. La etiqueta de apertura es T_OPEN_TAG y no es un
        // comentario, asi que hay que nombrarla aparte: sin este salto el
        // recorrido terminaria en el primer token y ningun fichero tendria
        // cabecera.
        if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT], true)) {
            continue;
        }

        if (is_array($token) && $token[0] === T_DECLARE) {
            break;
        }

        if (is_array($token) && $token[0] === T_DOC_COMMENT) {
            $cabecera = $token;
        }

        break;
    }

    if ($cabecera === null) {
        anotar(
            'error',
            'Cada fichero PHP empieza con un comentario de cabecera',
            $relativa,
            1,
            'Falta el comentario /** ... */ que explica para que sirve el fichero. '
            . 'Va justo despues de <?php y antes de declare().'
        );
    } else {
        comprobarIdioma($cabecera[1], $relativa, $cabecera[2]);
    }

    // -------------------------------------------------------------------------
    // 2 y 3. Clases y metodos.
    // -------------------------------------------------------------------------
    // Se lleva la profundidad de llaves para distinguir los metodos, que estan
    // dentro de una clase, de las funciones sueltas de bin/, que no.
    $profundidad = 0;
    $claseActual = '';
    $esperaDeclaracion = false;

    foreach ($tokens as $indice => $token) {
        if (!is_array($token)) {
            if ($token === '{') {
                $profundidad++;
            } elseif ($token === '}') {
                $profundidad--;

                // Al cerrarse una clase se vuelve a estar fuera de ella, y el
                // nombre guardado deja de servir. Sin esta limpieza, un metodo
                // de una funcion suelta acabaria atribuido a la ultima clase
                // declarada antes.
                if ($profundidad < 1) {
                    $profundidad = 0;
                    $claseActual = '';
                }
            }

            // Un punto y coma despues de un cierre de parentesis o de llave
            // cierra la declaracion de un metodo abstracto o de una interfaz, que
            // no tiene cuerpo. Es el mismo caso que un cuerpo, asi que se
            // comprueba igual.
            if ($token === ';' && $esperaDeclaracion) {
                $esperaDeclaracion = false;
            }

            continue;
        }

        [$tipo, $texto, $linea] = [$token[0], $token[1], $token[2]];

        switch ($tipo) {
            case T_WHITESPACE:
            case T_COMMENT:
                // Un comentario normal no cuenta como documentacion de nada.
                if ($tipo === T_COMMENT) {
                    comprobarIdioma($texto, $relativa, $linea);
                }
                break;

            case T_DOC_COMMENT:
                comprobarIdioma($texto, $relativa, $linea);
                comprobarEtiquetas($texto, $relativa, $linea);
                break;

            case T_CLASS:
            case T_INTERFACE:
            case T_TRAIT:
                // T_CLASS tambien aparece en «UnaClase::class», que es una
                // referencia a la constante class y no una declaracion. Se
                // distingue por lo que hay justo antes: si es la flecha de
                // alcance, no hay ninguna clase nueva que declarar.
                if (esReferenciaDeClase($tokens, $indice)) {
                    break;
                }

                // El nombre de la clase va justo despues, y se busca para poder
                // nombrarla en los mensajes de error de sus metodos.
                $claseActual = nombreDeClase($tokens, $indice);
                comprobarCabeceraDeClase($tokens, $indice, $relativa, $claseActual);
                $esperaDeclaracion = true;
                break;

            // T_ENUM no se escribe en la lista de casos porque no existe en
            // PHP 8.0, que es la version de este proyecto, y nombrar una
            // constante inexistente es un error fatal. En PHP 8.1 y superiores
            // la enumeracion se reconoce por su nombre, que empieza por
            // mayuscula, y basta con mirar el texto del token.

            case T_FUNCTION:
                // Si es un metodo o una funcion con nombre. Lo decide el
                // parenthesis que sigue: una funcion anonima (una «closure»)
                // empieza por «(» y no tiene a nadie que documentar, asi que
                // se salta. Un metodo o una funcion con nombre, no.
                if (!esClosure($tokens, $indice)) {
                    // Que este dentro de una clase se decide por la profundidad
                    // de llaves, no por un indicador que se puede desajustar con
                    // el primero que modifique una clase. Una clase siempre
                    // empieza con una llave, asi que estar en la profundidad 1 o
                    // mas significa estar dentro de ella.
                    comprobarMetodo($tokens, $indice, $relativa, $profundidad >= 1, $claseActual);
                }

                $esperaDeclaracion = true;
                break;

            default:
                // Una enumeracion es como una clase, pero en PHP 8.0 el token
                // T_ENUM no existe todavia y el analizador le llama «enum». Se
                // reconoce por el nombre del token, que empieza por mayuscula.
                if ($tipo === T_STRING && strtoupper($texto) === 'ENUM') {
                    $claseActual = nombreDeClase($tokens, $indice);
                    $esperaDeclaracion = true;
                    break;
                }

                $esperaDeclaracion = false;
                break;
        }
    }
}

/**
 * Comprueba que una clase tenga su comentario de documentacion.
 *
 * Una clase que dice que hace es mucho mas util que una cuyo nombre ya lo dice.
 * «Db» no dice si abre conexiones o si las cachea; un comentario lo aclara. Y
 * En este proyecto hay clases cuyo nombre corto no dice lo que hacen, como
 * ErrorAplicacion y ErrorBaseDeDatos.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Flujo de tokens.
 * @param int                                                  $indice Posicion de T_CLASS.
 * @param string                                               $relativa Ruta del fichero.
 * @param string                                               $clase   Nombre de la clase.
 *
 * @return void
 */
function comprobarCabeceraDeClase(array $tokens, int $indice, string $relativa, string $clase): void
{
    // T_READONLY no se nombra porque es de PHP 8.2 y este proyecto es de PHP
    // 8.0. En 8.0 todavia no hay clases de solo lectura.
    $modificadores = [T_WHITESPACE, T_FINAL, T_ABSTRACT];
    $documentacion = null;
    $linea = is_array($tokens[$indice]) ? $tokens[$indice][2] : 0;

    for ($i = $indice - 1; $i >= 0; $i--) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], $modificadores, true)) {
            continue;
        }

        if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOC_COMMENT) {
            $documentacion = $tokens[$i][1];
        }

        break;
    }

    if ($documentacion === null) {
        anotar(
            'error',
            'Cada clase tiene su comentario PHPDoc',
            $relativa,
            $linea,
            'La clase ' . $clase . ' no tiene comentario /** ... */ antes de la palabra class.'
        );

        return;
    }

    // El comentario tiene que decir para que sirve la clase, no limitarse a
    // existir. Un bloque que solo repite el nombre de la clase no ayuda a
    // nadie, y es el fallo tipico cuando el comentario se rellena por
    // automatismo. Se exige una frase de al menos quince caracteres.
    $resumen = resumenDe($documentacion);

    if (strlen($resumen) < 15) {
        anotar(
            'aviso',
            'El comentario de la clase explica para que sirve',
            $relativa,
            $linea,
            'La clase ' . $clase . ' tiene un comentario demasiado corto para aporte algo: «'
            . $resumen . '».'
        );
    }
}

/**
 * Indica si una posicion del flujo es una referencia «::class» y no una
 * declaracion de clase.
 *
 * PHP usa la misma palabra «class» para dos cosas distintas: para declarar una
 * clase y para referirse a ella con la constante «::class», como en
 * «get_class(new Usuario())» o en «ErrorValidacion::class». La diferencia esta
 * en lo que hay delante: una declaracion no la precede nada, y una referencia
 * siempre la precede la flecha de alcance «::».
 *
 * Sin esta distincion, cada «::class» del proyecto se daria de error como si
 * fuese una clase sin documentacion.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Flujo de tokens.
 * @param int                                                  $indice Posicion del token.
 *
 * @return bool True si es una referencia «::class».
 */
function esReferenciaDeClase(array $tokens, int $indice): bool
{
    for ($i = $indice - 1; $i >= 0; $i--) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            continue;
        }

        // T_DOUBLE_COLON es la flecha de alcance, «::». En PHP 8 se llama asi;
        // en versiones anteriores tambien, asi que no hace falta comprobar
        // nada mas.
        return is_array($tokens[$i]) && $tokens[$i][0] === T_DOUBLE_COLON;
    }

    return false;
}

/**
 * Extrae la frase de resumen de un bloque PHPDoc.
 *
 * Es la primera frase del comentario: la que va antes de la primera etiqueta
 * de las que empiezan por arroba, o todo el bloque si no hay ninguna. Sirve
 * para comprobar que el comentario dice algo y no solo que existe.
 *
 * @param string $bloque Texto completo del bloque PHPDoc.
 *
 * @return string La frase de resumen, sin las marcas de asterisco y sin los
 *                asteriscos de decoracion.
 */
function resumenDe(string $bloque): string
{
    // Se quita la abertura y el cierre del bloque.
    $limpio = preg_replace('#^\s*/\*\*|\*/\s*$#', '', $bloque);
    $limpio = (string) $limpio;

    // Cada linea empieza con un asterisco de decoracion, o con uno de apertura.
    $limpio = preg_replace('#^\s*\*ic?/?\s?#m', '', $limpio);
    $limpio = (string) $limpio;

    // A partir de la primera etiqueta, lo que hay debajo es detalle y ya no
    // resumen. Por eso el texto se corta aqui y no al final del bloque.
    $etiqueta = strpos($limpio, '@');
    $primera = $etiqueta === false ? $limpio : substr($limpio, 0, $etiqueta);

    // Se corta en el primer punto, que es donde acaba una frase, y se quitan
    // los asteriscos de las separaciones de pagina.
    $punto = strpos($primera, '.');
    $frase = $punto === false ? $primera : substr($primera, 0, $punto);

    return trim(preg_replace('/\s+/u', ' ', $frase) ?? '');
}

/**
 * Indica si una posicion del flujo corresponde a una funcion anonima.
 *
 * Una funcion anonima, mas conocida como «closure», se declara asi:
 *
 *     function (int $a): int { return $a; }
 *
 * Es decir, que despues de la palabra «function» viene un parentesis de
 * apertura y no un nombre. No tiene a nadie al que documentar ni sitio donde
 * poner el comentario, asi que el verificador la salta.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Flujo de tokens.
 * @param int                                                  $indice Posicion de T_FUNCTION.
 *
 * @return bool True si es una funcion anonima.
 */
function esClosure(array $tokens, int $indice): bool
{
    $total = count($tokens);

    for ($i = $indice + 1; $i < $total; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            continue;
        }

        if ($tokens[$i] === '&') {
            continue;
        }

        return $tokens[$i] === '(';
    }

    return false;
}

/**
 * Extrae el nombre de la clase que se declara en una posicion del flujo.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Flujo de tokens.
 * @param int                                                  $indice Posicion de T_CLASS.
 *
 * @return string Nombre de la clase, o cadena vacia si no se encuentra.
 */
function nombreDeClase(array $tokens, int $indice): string
{
    $total = count($tokens);

    for ($i = $indice + 1; $i < $total; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
            return $tokens[$i][1];
        }
    }

    return '';
}

/**
 * Comprueba que un metodo o funcion tenga su documentacion completa.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Flujo de tokens.
 * @param int                                                  $indice Posicion de T_FUNCTION.
 * @param string                                               $relativa Ruta del fichero.
 * @param bool                                                $enClase Si es un metodo.
 * @param string                                               $clase   Clase que lo contiene.
 *
 * @return void
 */
function comprobarMetodo(array $tokens, int $indice, string $relativa, bool $enClase, string $clase): void
{
    $total = count($tokens);

    // ---- Nombre y linea del metodo ------------------------------------------
    $nombre = '(sin nombre)';
    $lineaNombre = 0;

    for ($i = $indice + 1; $i < $total; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            continue;
        }

        // Un & antes del nombre significa que devuelve por referencia. No
        // impide documentarlo, asi que se salta y se sigue.
        if ($tokens[$i] === '&') {
            continue;
        }

        if (is_array($tokens[$i]) && $tokens[$i][0] === T_STRING) {
            $nombre = $tokens[$i][1];
            $lineaNombre = $tokens[$i][2];
        }

        break;
    }

    $donde = $enClase ? 'el metodo ' . $clase . '::' . $nombre . '()' : 'la funcion ' . $nombre . '()';
    $linea = is_array($tokens[$indice]) ? $tokens[$indice][2] : 0;

    // ---- El comentario de documentation -------------------------------------
    // Se mira hacia atras desde la palabra «function», saltando los espacios y
    // los modificadores. Los modificadores importan mucho: en
    //
    //     /**
    //      * Documentacion.
    //      */
    //     public static function tabla(): string
    //
    // el comentario no esta pegado a «function», sino dos palabras antes. Sin
    // saltar los modificadores, todos los metodos con «public» parecerian no
    // tener documentacion, que es justo lo que pasa si no se hace.
    $modificadores = [T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL];
    $documentacion = null;

    for ($i = $indice - 1; $i >= 0; $i--) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], $modificadores, true)) {
            continue;
        }

        if ($tokens[$i] === '&') {
            continue;
        }

        if (is_array($tokens[$i]) && $tokens[$i][0] === T_DOC_COMMENT) {
            $documentacion = $tokens[$i][1];
        }

        break;
    }

    if ($documentacion === null) {
        anotar(
            'error',
            'Cada metodo tiene su comentario PHPDoc',
            $relativa,
            $linea,
            $donde . ' no tiene comentario /** ... */ antes de la palabra function.'
        );

        return;
    }

    // ---- Los parametros --------------------------------------------------------
    $parametros = parametrosDe($tokens, $indice);

    // El tipo se captura como «todo lo que hay entre @param y el nombre del
    // parametro». No se puede usar \S+ porque los tipos genericos de PHP
    // contienen espacios:
    //
    //     @param array<int, array{0: int, 1: string}> $tokens
    //
    // Con \S+ el tipo se cortaria en «array<int,» y despues el nombre no
    // encajaria, de modo que el parametro pareceria no estar documentado.
    preg_match_all('/@param\s+([^$]*?)\$(\w+)/', $documentacion, $coincidencias, PREG_SET_ORDER);
    $documentados = [];

    foreach ($coincidencias as $coincidencia) {
        $documentados[$coincidencia[2]] = trim($coincidencia[1]);
    }

    // Se comprueba que esten todos, y en el mismo orden que en la firma. El
    // orden importa porque es por el orden por el que se lee: un @param fuera
    // de sitio obliga a ir y volver entre la firma y el comentario.
    $ordenFirma = array_keys($parametros);
    $ordenDoc = array_keys($documentados);

    foreach ($ordenFirma as $posicion => $parametro) {
        if (!isset($documentados[$parametro])) {
            anotar(
                'error',
                'Cada @param esta documentado y en el orden de la firma',
                $relativa,
                $linea,
                $donde . ' declara $' . $parametro . ' y no lo documenta con @param.'
            );
        }
    }

    if ($ordenFirma !== [] && $ordenFirma !== $ordenDoc) {
        // Solo se avisa del orden si estan todos. Si falta alguno, ya hay un
        // error mas claro que dice cual falta, y este seria redundante.
        $faltan = array_diff($ordenFirma, $ordenDoc);

        if ($faltan === []) {
            anotar(
                'aviso',
                'Cada @param esta documentado y en el orden de la firma',
                $relativa,
                $linea,
                $donde . ' documenta los parametros en otro orden que el de la firma. '
                . 'Firma: ' . implode(', ', $ordenFirma) . '. Comentario: ' . implode(', ', $ordenDoc) . '.'
            );
        }
    }

    foreach ($documentados as $parametro => $tipo) {
        if (!isset($parametros[$parametro])) {
            // Un @param de un parametro que no existe es peor que uno que
            // falta: hace creer que el metodo recibe un dato que no recibe.
            continue;
        }

        if ($tipo !== '' && $parametros[$parametro] !== '' && $tipo !== $parametros[$parametro]) {
            anotar(
                'aviso',
                'Cada @param esta documentado y en el orden de la firma',
                $relativa,
                $linea,
                $donde . ': @param $' . $parametro . ' dice «' . $tipo . '» y la firma dice «'
                . $parametros[$parametro] . '».'
            );
        }
    }

    // ---- El valor de retorno --------------------------------------------------
    // Un metodo sin @return deja al que lea el comentario sin saber si puede
    // usar lo que devuelve, asi que se exige siempre.
    //
    // Los constructores y el destructor son la excepcion: en PHP no pueden
    // devolver nada, no se puede declarar «: void» en su firma y escribir
    // «@return void» en ellos es ruido. La regla de PHPDoc lo dice
    // explicitamente, asi que aqui se respeta.
    $esConstructor = $nombre === '__construct' || $nombre === '__destruct';

    if ($esConstructor) {
        return;
    }

    if (preg_match('/@return\b/', $documentacion) !== 1) {
        anotar(
            'error',
            'Cada metodo declara @return',
            $relativa,
            $linea,
            $donde . ' no declara @return. Si no devuelve nada, se escribe @return void.'
        );
    }
}

/**
 * Extrae los parametros de un metodo, con su tipo declarado.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Flujo de tokens.
 * @param int                                                  $indice Posicion de T_FUNCTION.
 *
 * @return array<string, string> Nombre del parametro y tipo declarado.
 */
function parametrosDe(array $tokens, int $indice): array
{
    $total = count($tokens);
    $parametros = [];

    // Se busca el parentesis que abre la lista de parametros.
    $abre = -1;

    for ($i = $indice + 1; $i < $total; $i++) {
        if ($tokens[$i] === '(') {
            $abre = $i;
            break;
        }

        // Si se encuentra la llave de apertura del cuerpo antes que el
        // parentesis, es una declaracion sin parametros.
        if ($tokens[$i] === '{') {
            return [];
        }
    }

    if ($abre < 0) {
        return [];
    }

    // Se recorre la lista hasta el parentesis que la cierra, contando la
    // profundidad para no confundirse con un valor por defecto que sea un
    // array.
    $nivel = 0;
    $tipoActual = '';
    $nombre = '';
    $esperaNombre = false;

    for ($i = $abre; $i < $total; $i++) {
        $token = $tokens[$i];

        if ($token === '(') {
            $nivel++;
            continue;
        }

        if ($token === ')') {
            $nivel--;

            if ($nivel === 0) {
                break;
            }

            continue;
        }

        if (!is_array($token)) {
            // Una coma al nivel de la lista cierra el parametro actual.
            if ($token === ',' && $nivel === 1) {
                if ($nombre !== '') {
                    $parametros[$nombre] = $tipoActual;
                }

                $nombre = '';
                $tipoActual = '';
                $esperaNombre = false;
            }

            continue;
        }

        switch ($token[0]) {
            case T_WHITESPACE:
                break;

            case T_VARIABLE:
                $nombre = substr($token[1], 1);
                $esperaNombre = false;
                break;

            case T_STRING:
            case T_ARRAY:
            case T_CALLABLE:
            case T_NS_SEPARATOR:
            case T_ELLIPSIS:
                if ($esperaNombre) {
                    $tipoActual .= $token[1];
                }

                break;

            default:
                // Cualquier otro token (una constante de clase, un «mixed» de
                // PHP 8, etc.) forma parte del tipo.
                if ($esperaNombre && $token[1] !== '&' && $token[1] !== '?') {
                    $tipoActual .= $token[1];
                }

                break;
        }

        // Tras el tipo se espera el nombre del parametro.
        if ($tipoActual !== '' && $nombre === '') {
            $esperaNombre = true;
        }

        if ($token[0] === T_VARIABLE) {
            $tipoActual = rtrim($tipoActual);
        }
    }

    if ($nombre !== '') {
        $parametros[$nombre] = $tipoActual;
    }

    return $parametros;
}

/**
 * Comprueba las reglas de dependencias prohibidas sobre el codigo.
 *
 * Se lee el fichero entero sin analizar, porque estas reglas miran el texto tal
 * cual: un import de un framework esta en un use, y un CDN esta dentro de una
 * cadena, y en los dos casos el contenido bruto es lo que hay que buscar.
 *
 * @param string $codigo   Contenido del fichero.
 * @param string $relativa Ruta del fichero.
 *
 * @return void
 */
function comprobarDependencias(string $codigo, string $relativa): void
{
    // Se calcula una sola vez si el fichero es codigo con clases. Lo es todo lo
    // que hay en app/, que es donde un exit() dentro de un metodo deja la
    // peticion a medias. Ni el controlador frontal ni los scripts de consola
    // tienen ese problema.
    $esAplicacion = strpos($relativa, 'app/') === 0;

    foreach (REGLAS['dependencias'] as $regla) {
        // Una regla puede limitarse a una parte del proyecto con la clave «solo».
        // Sin ese limite, la regla de exit( tambien se aplicaria al instalador
        // y al propio verificador, donde exit() es lo correcto.
        $ambito = $regla['solo'] ?? 'todo';

        if ($ambito === 'app' && !$esAplicacion) {
            continue;
        }

        if (preg_match($regla['patron'], $codigo, $coincidencia, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        $posicion = $coincidencia[0][1];

        anotar(
            $regla['gravedad'],
            $regla['titulo'],
            $relativa,
            lineaDe($codigo, $posicion),
            $regla['mensaje'] . ' Se ha encontrado: «' . trim($coincidencia[0][0]) . '».'
        );
    }
}

/**
 * Muestra la ayuda del script.
 *
 * @return void
 */
function ayuda(): void
{
    linea('Verificador de documentacion y de reglas del proyecto');
    linea('');
    linea('Uso: php bin\\verificar_docs.php [opciones]');
    linea('');
    linea('  --caso N   Ejecuta tambien los casos de la suite de pruebas.');
    linea('            Por ejemplo --caso 0 para el arranque y el esquema.');
    linea('  --ayuda    Muestra esta ayuda.');
    linea('');
    linea('Codigo de salida: 0 si todo esta bien, 1 si hay algun error.');
    linea('');
}

/**
 * Muestra un mensaje por consola.
 *
 * @param string $mensaje Texto a mostrar.
 *
 * @return void
 */
function linea(string $mensaje): void
{
    echo $mensaje, PHP_EOL;
}

/**
 * Ejecuta el verificador sobre todos los ficheros del proyecto.
 *
 * @param string $raiz Raiz del proyecto.
 *
 * @return int Codigo de salida.
 */
function verificarTodo(string $raiz): int
{
    global $problemas;

    $ficheros = ficherosPhp($raiz);

    linea('Revisando ' . count($ficheros) . ' ficheros PHP...');
    linea('');

    foreach ($ficheros as $fichero) {
        $relativa = str_replace('\\', '/', substr($fichero, strlen($raiz) + 1));
        $codigo = (string) file_get_contents($fichero);

        comprobarHigiene($codigo, $fichero, $relativa);
        comprobarDocumentacion($codigo, $relativa, $fichero);
        comprobarDependencias($codigo, $relativa);
    }

    // -------------------------------------------------------------------------
    // Informe
    // -------------------------------------------------------------------------
    $errores = 0;
    $avisos = 0;

    foreach ($problemas as $relativa => $lista) {
        linea('--- ' . $relativa);

        foreach ($lista as $problema) {
            if ($problema['gravedad'] === 'error') {
                $errores++;
                $etiqueta = 'ERROR';
            } else {
                $avisos++;
                $etiqueta = 'aviso';
            }

            linea(sprintf(
                '  %-5s linea %-4d %s',
                $etiqueta,
                $problema['linea'],
                $problema['regla']
            ));

            if ($problema['detalle'] !== '') {
                linea('        ' . $problema['detalle']);
            }
        }

        linea('');
    }

    linea(str_repeat('=', 64));

    if ($errores === 0 && $avisos === 0) {
        linea('Todo correcto: ' . count($ficheros) . ' ficheros, sin problemas.');

        return 0;
    }

    linea(sprintf(
        '%d error%s y %d aviso%s en %d fichero%s.',
        $errores,
        $errores === 1 ? '' : 'es',
        $avisos,
        $avisos === 1 ? '' : 's',
        count($problemas),
        count($problemas) === 1 ? '' : 's'
    ));

    return $errores > 0 ? 1 : 0;
}

// -----------------------------------------------------------------------------
// Cuerpo principal.
// -----------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

$argumentos = argumentos($argv);

if (isset($argumentos['ayuda']) || isset($argumentos['help'])) {
    ayuda();
    exit(0);
}

$raiz = dirname(__DIR__);

try {
    \App\Core\Aplicacion::arrancar($raiz);
} catch (Throwable $e) {
    linea('[FALLO] No se puede arrancar la aplicacion: ' . $e->getMessage());
    exit(1);
}

$codigoSalida = verificarTodo($raiz);

// El caso de pruebas se ejecuta despues, para que el informe de documentacion
// se pueda leer antes de que la suite empiece a escribir en la base de pruebas.
if (isset($argumentos['caso'])) {
    $caso = (int) $argumentos['caso'];

    if (!isset(CASOS[$caso])) {
        linea('');
        linea('No existe el caso ' . $caso . '. Los casos disponibles son: '
            . implode(', ', array_keys(CASOS)) . '.');
        exit(1);
    }

    linea('');
    linea('Ejecutando el caso ' . $caso . ': ' . CASOS[$caso]['titulo']);

    $salidaPruebas = [];
    $codigoResultado = 0;

    passthru(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/tests/run.php')
        . ' --caso ' . $caso,
        $codigoResultado
    );

    $codigoSalida = max($codigoSalida, $codigoResultado);
}

exit($codigoSalida);
