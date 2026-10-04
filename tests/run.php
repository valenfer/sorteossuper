<?php

/**
 * Suite de pruebas de la aplicacion.
 *
 * ============================================================================
 * POR QUE NO HAY PHPUNIT
 * ============================================================================
 *
 * PHPUnit y cualquier otro framework de pruebas son dependencias externas, y la
 * decision D14 las prohibe. Ademas, PHPUnit no esta instalado en XAMPP, y pedir
 * al responsable de una tienda que lo instale antes de poder hacer una prueba es
 * pedirle una cosa mas que hacer.
 *
 * Asi que aqui hay un ejecutor de pruebas de unosighty lineas. Hace lo unico que
 * hace falta: una lista de comprobaciones que devuelven verdadero o falso, un
 * recuento y un codigo de salida. Nada mas.
 *
 * Lo que si se ha copiado con cuidado de los frameworks buenos:
 *
 *   - Cada prueba dice que comprueba, en una frase. Una prueba que no se puede
 *     describir no se puede arreglar cuando falla.
 *   - Falla con un mensaje que dice que se esperaba y que se obtuvo, no solo
 *     «fallo». «Esperaba 9 digitos, recibio 7» localiza el fallo; «fallo» obliga
 *     a repetir la prueba a mano.
 *   - El codigo de salida es 0 o 1, para que un guion pueda encadenarse.
 *   - Cada caso se puede ejecutar solo, con --caso N, que es lo que hace el
 *     verificador de documentacion.
 *
 * ============================================================================
 * CONTRA QUE BASE DE DATOS SE EJECUTA
 * ============================================================================
 *
 * Contra «sorteos_test», nunca contra «sorteos». Una prueba que borra filas no
 * puede tocar la campana de un supermercado real, ni aunque sea «solo una tabla»
 * ni aunque «no parezca que importa». El nombre de la base se sale de la
 * configuracion y se comprueba antes de escribir nada, de modo que un cambio
 * accidental en config.php hace que las pruebas se neguen a arrancar en vez de
 * vaciar la campana.
 *
 * ============================================================================
 * USO
 * ============================================================================
 *
 *     php tests\\run.php                 Ejecuta todos los casos
 *     php tests\\run.php --caso 0        Ejecuta solo el caso 0
 *     php tests\\run.php --verbose       Muestra cada comprobacion
 *
 * @see bin\\verificar_docs.php
 * @see decision D14 del documento de especificacion
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/inicio.php';
require_once __DIR__ . '/_escenario.php';
require_once __DIR__ . '/_http.php';

use App\Core\Aplicacion;
use App\Services\Tramos;

/**
 * Numero de pruebas que han pasado en el caso en curso.
 *
 * @var int
 */
$pasadas = 0;

/**
 * Fallos encontrados, con su descripcion.
 *
 * @var array<int, string>
 */
$fallos = [];

/**
 * Nombre de la base de datos de la campana, guardado ANTES de cambiar a la de
 * pruebas, porque despues ya no se puede leer del sitio original.
 *
 * @var string
 */
$nombreCampana = '';

/**
 * Indica si se muestra cada comprobacion aunque pase.
 *
 * @var bool
 */
$detallado = false;

/**
 * Comprueba que una condicion es verdadera.
 *
 * @param bool   $condicion   Lo que se comprueba.
 * @param string $descripcion Que se esta comprobando, en una frase.
 * @param string $detalle     Texto que se muestra si falla, con lo esperado y
 *                            lo obtenido.
 *
 * @return void
 */
function comprobar(bool $condicion, string $descripcion, string $detalle = ''): void
{
    global $pasadas, $fallos, $detallado;

    if ($condicion) {
        $pasadas++;

        if ($detallado) {
            echo '  [ok]   ', $descripcion, PHP_EOL;
        }

        return;
    }

    $fallos[] = $descripcion . ($detalle !== '' ? ' (' . $detalle . ')' : '');
    echo '  [FALLO] ', $descripcion, PHP_EOL;

    if ($detalle !== '') {
        echo '          ', $detalle, PHP_EOL;
    }
}

/**
 * Comprueba que dos valores son iguales.
 *
 * @param mixed  $esperado   Valor que deberia ser.
 * @param mixed  $obtenido   Valor que ha salido.
 * @param string $descripcion Que se esta comprobando.
 *
 * @return void
 */
function comprobarIgual($esperado, $obtenido, string $descripcion): void
{
    comprobar(
        $esperado === $obtenido,
        $descripcion,
        'se esperaba ' . var_export($esperado, true) . ' y se obtuvo ' . var_export($obtenido, true)
    );
}

/**
 * Comprueba que una expresion lanza la excepcion esperada.
 *
 * @param string   $clase       Nombre completo de la excepcion que se espera.
 * @param callable $operacion   Codigo que deberia lanzar el fallo.
 * @param string   $descripcion Que se esta comprobando.
 *
 * @return void
 */
function comprobarFalla(string $clase, callable $operacion, string $descripcion): void
{
    try {
        $operacion();
    } catch (Throwable $e) {
        comprobar(
            $e instanceof $clase,
            $descripcion,
            'se esperaba ' . $clase . ' y se produjo ' . get_class($e) . ': ' . $e->getMessage()
        );

        return;
    }

    comprobar(false, $descripcion, 'no se produjo ninguna excepcion');
}

/**
 * Comprueba que un codigo falla y devuelve el fallo, para poder mirarlo.
 *
 * Es comprobarFalla() con una cosa mas: devuelve la excepcion. Hace falta cuando
 * lo que se quiere comprobar no es que la operacion falle, sino QUE DICE el
 * fallo. «No cabe en el plan» y «el tramo no existe» fallan los dos, y un
 * mensaje equivocado en el sitio equivocado es un fallo que comprobarFalla()
 * declara bueno.
 *
 * @param string   $clase       Nombre completo de la excepcion que se espera.
 * @param callable $operacion   Codigo que deberia lanzar el fallo.
 * @param string   $descripcion Que se esta comprobando.
 *
 * @return \Throwable La excepcion capturada.
 *
 * @throws \RuntimeException Si la operacion no falla con esa clase.
 */
function capturarFalla(string $clase, callable $operacion, string $descripcion): Throwable
{
    try {
        $operacion();
    } catch (Throwable $e) {
        comprobar(
            $e instanceof $clase,
            $descripcion,
            'se esperaba ' . $clase . ' y se produjo ' . get_class($e) . ': ' . $e->getMessage()
        );

        return $e;
    }

    throw new \RuntimeException($descripcion . ': no se produjo ninguna excepcion');
}

/**
 * Comprueba que un texto contiene un fragmento.
 *
 * Se usa con el HTML de las pantallas. Se busca el fragmento tal cual, sin
 * recortar espacios ni normalizar nada, porque el HTML que sale de la vista es
 * exactamente el que se envia al navegador: si un cierre de etiqueta se pierde o
 * sobra por un espacio de mas, el navegador lo va a notar igual que la prueba.
 *
 * @param string $texto       Texto en el que se busca.
 * @param string $fragmento   Lo que tiene que estar dentro.
 * @param string $descripcion Que se esta comprobando.
 *
 * @return void
 */
function comprobarContiene(string $texto, string $fragmento, string $descripcion): void
{
    comprobar(
        strpos($texto, $fragmento) !== false,
        $descripcion,
        'no aparece «' . $fragmento . '». Se han impreso ' . strlen($texto) . ' bytes'
    );
}

/**
 * Comprueba que un texto NO contiene un fragmento.
 *
 * El caso de uso es el inverso del de comprobarContiene(): hay cosas que no
 * deben aparecer nunca en una pantalla, y que se comprueban mejor buscando que
 * no estan que leyendo la pagina entera.
 *
 * @param string $texto       Texto en el que se busca.
 * @param string $fragmento   Lo que no tiene que estar dentro.
 * @param string $descripcion Que se esta comprobando.
 *
 * @return void
 */
function comprobarNoContiene(string $texto, string $fragmento, string $descripcion): void
{
    comprobar(
        strpos($texto, $fragmento) === false,
        $descripcion,
        'aparece «' . $fragmento . '» y no deberia'
    );
}

// -----------------------------------------------------------------------------
// Casos de pruebas
// -----------------------------------------------------------------------------

/**
 * Caso 0: el arranque, la configuracion y el esquema.
 *
 * Es el caso mas basico y el mas importante: si falla, todo lo demas dara
 * resultados sin significado, porque las pruebas siguientes se apoyan en que la
 * aplicacion arranca y en que la base de datos tiene lo que debe.
 *
 * @return void
 */
function caso0(): void
{
    echo 'Caso 0: el nucleo arranca y el esquema esta completo', PHP_EOL;

    global $bdPruebas, $nombreCampana;

    // ---- Arranque ------------------------------------------------------------
    comprobar(Aplicacion::estaArrancada(), 'La aplicacion aparece como arrancada');
    comprobar(Aplicacion::estaArrancada(), 'arrancar() es idempotente y no vuelve a hacer nada');

    // ---- Configuracion -------------------------------------------------------
    $config = Aplicacion::config();
    comprobar(is_array($config) && $config !== [], 'La configuracion se lee y es un array');

    comprobarIgual(
        'Europe/Madrid',
        Aplicacion::ajuste('app.zona_horaria'),
        'La zona horaria de la aplicacion es Europe/Madrid (decision D7)'
    );

    comprobar(
        date_default_timezone_get() === 'Europe/Madrid',
        'PHP tiene fijada Europe/Madrid, aunque php.ini diga otra cosa',
        'php.ini dice ' . date_default_timezone_get() . ' en el momento de la comprobacion'
    );

    $secreto = (string) Aplicacion::ajuste('seguridad.secreto_hmac', '');
    comprobar(
        strlen($secreto) >= 32,
        'El secreto de HMAC tiene al menos 32 caracteres',
        'mide ' . strlen($secreto) . ' caracteres'
    );

    comprobar(
        strpos($secreto, 'CAMBIAR') === false,
        'El secreto de HMAC no es el de ejemplo'
    );

    // ---- Base de datos -------------------------------------------------------
    $db = Aplicacion::db();
    $motor = (string) $db->valor('SELECT VERSION()');
    comprobar($motor !== '', 'La conexion con la base de datos responde', 'motor: ' . $motor);

    $base = (string) $db->valor('SELECT DATABASE()');
    comprobar(
        $base !== '',
        'La conexion tiene una base de datos seleccionada',
        'SELECT DATABASE() devolvio vacio: habria que revisar el USE del instalador'
    );

    // Esta es la comprobacion que mas merece la pena. Una prueba que escribe
    // sobre la base de la campana puede vaciar los datos de un supermercado
    // real, asi que se comprueba con el nombre que SEGURO no es el de la
    // campana.
    //
    // El nombre de la campana se guardó antes de cambiar de base, porque despues
    // de cambiar, Aplicacion::ajuste('bd.nombre') ya devuelve el de pruebas y
    // compararse consigo misma daria un falso positivo.
    comprobar(
        $base === $bdPruebas,
        'Las pruebas se ejecutan contra la base de pruebas',
        'se esta usando «' . $base . '» y se esperaba «' . $bdPruebas . '»'
    );

    comprobar(
        $base !== $nombreCampana,
        'Las pruebas NO se ejecutan contra la base de la campana (esta seria una perdida de datos)',
        'la campana es «' . $nombreCampana . '» y se esta usando esa misma base'
    );

    // ---- Esquema -------------------------------------------------------------
    $tablas = $db->todos(
        'SELECT TABLE_NAME AS nombre
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
          ORDER BY TABLE_NAME'
    );

    $nombres = array_column($tablas, 'nombre');

    $esperadas = [
        'asignaciones_tramo',
        'auditoria',
        'campos_formulario',
        'codigos_validos',
        'configuracion_visual',
        'correos',
        'intentos_rechazados',
        'migraciones',
        'participaciones',
        'promociones',
        'reglas_participacion',
        'tipos_premio',
        'tramos',
        'unidades_premio',
        'usuarios',
    ];

    foreach ($esperadas as $tabla) {
        comprobar(
            in_array($tabla, $nombres, true),
            'Existe la tabla ' . $tabla
        );
    }

    comprobar(
        count($nombres) === count($esperadas),
        'No hay tablas de mas en la base de pruebas',
        'se encontraron ' . count($nombres) . ' y se esperaban ' . count($esperadas)
    );

    // ---- Bloqueos ------------------------------------------------------------
    // GET_LOCK es la pieza de la que depende todo el reparto de premios. Si no
    // esta, dos tablets a la vez podrian dar la misma unidad a dos clientas.
    $conseguido = (int) $db->valor("SELECT GET_LOCK('sorteos:pruebas', 1)") === 1;
    $db->valor("SELECT RELEASE_LOCK('sorteos:pruebas')");

    comprobar(
        $conseguido,
        'La funcion GET_LOCK funciona: el reparto de premios quedara serializado'
    );
}

/**
 * Caso 1: el validador de datos.
 *
 * Comprueba lo que mas falla en el mostrador, que es la validacion de lo que
 * teclea la azafata delante de una clienta: el correo, el telefono, el DNI y los
 * campos obligatorios.
 *
 * @return void
 */
function caso1(): void
{
    echo 'Caso 1: las reglas de validacion del nucleo', PHP_EOL;

    // ---- Correo --------------------------------------------------------------
    $v = new \App\Core\Validador();
    comprobarIgual('ana@example.com', $v->correo('correo', '  Ana@Example.COM '), 'El correo se normaliza a minusculas y se recortan los espacios');
    comprobar($v->errores() === [], 'Un correo valido no produce errores');

    $v = new \App\Core\Validador();
    $v->correo('correo', 'esto-no-es-un-correo');
    comprobar($v->errores() !== [], 'Un correo sin arroba se rechaza');

    $v = new \App\Core\Validador();
    $v->correo('correo', 'ana@example');
    comprobar($v->errores() !== [], 'Un correo sin parte despues del punto del dominio se rechaza');

    $v = new \App\Core\Validador();
    $v->correo('correo', 'ana@sub.example.com');
    comprobar($v->errores() === [], 'Un correo con subdominio se acepta');

    // ---- Telefono ------------------------------------------------------------
    // Las reglas de telefono estan en Validador::digitosDeTelefonoValidos(), y
    // aqui se comprueban de forma indirecta, con los casos que las resumen.
    //
    // Un movil empieza por 6 o por 7 y su segundo digito no puede ser 0 ni 1.
    // Un fijo empieza por 8 o por 9 y sus segundo y tercer digitos tampoco
    // pueden ser 0 ni 1. Con eso se descartan la mayoria de las erratas de una
    // sola cifra, que es de donde vienen casi todos los errores de tecleo.
    $validos = [
        '634112233'          => 'movil',
        '734567890'          => 'movil antiguo',
        '789 123 456'        => 'movil con espacios',
        '834567890'          => 'fijo',
        '934567890'          => 'fijo',
    ];

    foreach ($validos as $telefono => $clase) {
        $v = new \App\Core\Validador();
        $resultado = $v->telefono('telefono', $telefono);
        comprobar(
            $v->errores() === [] && strlen($resultado) === 9,
            'El telefono «' . $telefono . '» se acepta (' . $clase . ')',
            'ha salido «' . $resultado . '»'
        );
    }

    $v = new \App\Core\Validador();
    comprobarIgual(
        '634112233',
        $v->telefono('telefono', '+34 634 11 22 33'),
        'El prefijo 34 se quita y se guardan solo los 9 digitos, para que el numero se pueda comparar con otro'
    );

    $imposibles = [
        '012345678'       => 'empieza por 0',
        '112345678'       => 'empieza por 1',
        '601112233'       => 'es un movil y el segundo digito es 0',
        '611112233'       => 'es un movil y el segundo digito es 1',
        '911223344'       => 'es un fijo y el segundo digito es 1',
        '901112233'       => 'es un fijo y el segundo digito es 0',
        '800112233'       => 'es un fijo y el tercer digito es 0',
        '60011223'        => 'solo tiene 8 digitos',
        '6001122334'      => 'tiene 10 digitos',
        'es el que me dijo mi prima' => 'no es un numero',
    ];

    foreach ($imposibles as $telefono => $motivo) {
        $v = new \App\Core\Validador();
        $v->telefono('telefono', $telefono);
        comprobar(
            $v->errores() !== [],
            'El telefono «' . $telefono . '» se rechaza (' . $motivo . ')'
        );
    }

    // ---- DNI -----------------------------------------------------------------
    $v = new \App\Core\Validador();
    comprobarIgual(
        '12345678Z',
        $v->dni('dni', ' 12345678-z '),
        'El DNI se normaliza sin separadores y en mayuscula'
    );

    $v = new \App\Core\Validador();
    $v->dni('dni', '12345678A');
    comprobar(
        $v->errores() !== [],
        'Un DNI cuya letra no corresponde se rechaza (decision D3)'
    );

    $v = new \App\Core\Validador();
    $v->dni('dni', '1234567');
    comprobar($v->errores() !== [], 'Un DNI con menos de 8 digitos se rechaza');

    // ---- Obligatorios --------------------------------------------------------
    $v = new \App\Core\Validador();
    $v->textoObligatorio('nombre', '');
    comprobar($v->errores() !== [], 'Un campo de texto obligatorio vacio produce error');

    $v = new \App\Core\Validador();
    $v->textoObligatorio('nombre', '   ');
    comprobar($v->errores() !== [], 'Un campo obligatorio con solo espacios produce error');

    $v = new \App\Core\Validador();
    $v->textoObligatorio('nombre', 'Ana');
    comprobar($v->errores() === [], 'Un campo obligatorio con contenido no produce error');

    // Un campo obligatorio no se marca como error si el campo no es obligatorio.
    $v = new \App\Core\Validador();
    $v->telefono('telefono', '', false);
    comprobar(
        $v->errores() === [],
        'Un campo opcional puede quedar vacio sin que sea un error'
    );
}

/**
 * Caso 2: el escapado de salidas y el token CSRF.
 *
 * El escapado es lo que impide que una clienta llamada «Ana <b>Ruiz</b>» rompa
 * la pagina, y lo que impide que un nombre con un guionhtml injecte codigo. Es
 * una de las medidas del apartado 9, y se comprueba de forma directa.
 *
 * @return void
 */
function caso2(): void
{
    echo 'Caso 2: el escapado de salidas y el token CSRF', PHP_EOL;

    // ---- Escapado ------------------------------------------------------------
    comprobar(
        \App\Core\Vista::e('<b>hola</b>') === '&lt;b&gt;hola&lt;/b&gt;',
        'Vista::e() escapa las etiquetas',
        'ha salido: ' . \App\Core\Vista::e('<b>hola</b>')
    );

    comprobar(
        \App\Core\Vista::e('a"b\'c') === 'a&quot;b&#039;c',
        'Vista::e() escapa las comillas de los atributos',
        'ha salido: ' . \App\Core\Vista::e('a"b\'c')
    );

    comprobar(
        \App\Core\Vista::e('Ana & Co') === 'Ana &amp; Co',
        'Vista::e() escapa el ampersand'
    );

    comprobar(
        \App\Core\Vista::e("línea\ty ñ") === "línea\ty ñ",
        'Vista::e() no toca los acentos, la enye ni la tabulacion'
    );

    comprobar(\App\Core\Vista::e(null) === '', 'Vista::e() convierte null en cadena vacia');
    comprobar(\App\Core\Vista::e(0) === '0', 'Vista::e() no convierte el cero en cadena vacia');
    comprobar(\App\Core\Vista::e(false) === '0', 'Vista::e() convierte false en «0» y no en cadena vacia');

    // Un byte suelto no puede hacer que el elemento quede en blanco. Con
    // ENT_QUOTES a secas, htmlspecialchars devuelve cadena vacia y el campo
    // desaparece de la pantalla, que es el peor fallo posible en un mostrador.
    $conByte = "Ana \xC3( Ruiz";
    comprobar(
        \App\Core\Vista::e($conByte) !== '',
        'Vista::e() no devuelve cadena vacia ante una secuencia de bytes invalida',
        'ha salido: ' . var_export(\App\Core\Vista::e($conByte), true)
    );

    // ---- JSON ----------------------------------------------------------------
    comprobar(
        \App\Core\Vista::json(['a' => '<b>']) === '{"a":"\u003Cb\u003E"}',
        'Vista::json() escapa las etiquetas para meterlas en JavaScript',
        'ha salido: ' . \App\Core\Vista::json(['a' => '<b>'])
    );

    // ---- CSRF ----------------------------------------------------------------
    // En consola no hay sesion, asi que el token no puede generarse. Se
    // comprueba que el nucleo lo detecta y no finge que hay uno, porque un token
    // inventado seria peor que ninguno.
    comprobar(
        \App\Core\Csrf::token() === '',
        'Sin sesion, Csrf::token() no devuelve ningun token en vez de uno falso'
    );

    comprobarFalla(
        \App\Core\ErrorValidacion::class,
        static function (): void {
            \App\Core\Csrf::exigirValido();
        },
        'Csrf::exigirValido() falla cuando no hay sesion con la que comparar'
    );
}

/**
 * Caso 3: el motor de plantillas.
 *
 * El fallo que comprueba este caso es el que mas caro sale si aparece: si la
 * maquetacion se envuelve a si misma, el navegador recibe una respuesta que no
 * termina nunca y la tablet se queda sin memoria.
 *
 * @return void
 */
function caso3(): void
{
    echo 'Caso 3: el motor de plantillas no se ejecuta en bucle', PHP_EOL;

    $html = \App\Core\Vista::renderizar('acceso/entrar', [
        'titulo' => 'Prueba',
        'csrf'   => 'token-de-prueba',
        'error'  => '',
        'aviso'  => '',
        'exito'  => '',
    ]);

    comprobar($html !== '', 'Renderizar una vista devuelve contenido');

    // Se cuenta cuantas veces aparece la apertura del documento. Tiene que ser
    // exactamente una: dos significaria que la maquetacion se ha envuelto a si
    // misma.
    $aperturas = substr_count($html, '<!DOCTYPE html>');
    comprobar(
        $aperturas === 1,
        'La maquetacion aparece una sola vez',
        'aparece ' . $aperturas . ' veces'
    );

    comprobar(
        strpos($html, '<title>Prueba') !== false,
        'El titulo de la vista llega a la cabecera'
    );

    comprobar(
        strpos($html, 'token-de-prueba') !== false,
        'El contenido de la vista queda dentro de la maquetacion'
    );

    comprobar(
        substr_count($html, '</html>') === 1,
        'La maquetacion se cierra una sola vez'
    );

    // Un fragmento sin maquetacion no debe traer la pagina entera. Es lo que
    // necesitan los correos.
    $fragmento = \App\Core\Vista::renderizar(
        'inicio/pantalla',
        [
            'titulo'      => 'Prueba',
            'rol'         => 'administrador',
            'provisional' => true,
            'error'       => '',
            'aviso'       => '',
            'exito'       => '',
        ],
        false
    );

    comprobar(
        strpos($fragmento, '<!DOCTYPE html>') === false,
        'Un fragmento se renderiza sin la maquetacion comun'
    );

    // Una vista que no existe debe dar un error claro, no un aviso de PHP.
    comprobarFalla(
        \App\Core\ErrorAplicacion::class,
        static function (): void {
            \App\Core\Vista::renderizar('no/existe', []);
        },
        'Pedir una vista inexistente lanza ErrorAplicacion con un mensaje claro'
    );

    // Y no debe dejar la salida a medias.
    comprobar(
        ob_get_level() === 0,
        'Un fallo al renderizar no deja la salida a medias capturada',
        'han quedado ' . ob_get_level() . ' niveles de salida abiertos'
    );
}

/**
 * Comprueba el control de acceso por rol.
 *
 * Este caso existe porque aqui se han encontrado fallos que ninguna prueba de
 * maquetacion ni de validacion habria detectado. En concreto, tratar por igual
 * a quien no ha entrado y a quien entra con el rol equivocado. No es lo mismo:
 * sin sesion no hay nada que esconder y la respuesta util es llevar a esa
 * persona a la pantalla de acceso; con sesion pero sin permiso, en cambio, si
 * conviene esconderse, y por eso se responde como si la pagina no existiera.
 *
 * QUE SE PUEDE COMPROBAR DESDE AQUI Y QUE NO
 * ============================================================================
 *
 * Autorizacion::usuario() empieza por una comprobacion que devuelve «nadie»
 * siempre que la peticion no venga de la web:
 *
 *     if (!Aplicacion::esPeticionWeb() || session_status() !== PHP_SESSION_ACTIVE)
 *
 * Y esPeticionWeb() mira directamente PHP_SAPI, que es una constante del
 * interprete y no se puede cambiar en caliente. En la consola, por tanto, el
 * nucleo da siempre por hecho que no hay nadie, y es lo correcto: los scripts de
 * linea de comandos se ejecutan sin usuario y no deben romperse por ello.
 *
 * La consecuencia es que desde aqui solo se puede comprobar la rama de «no hay
 * sesion», que es la que decide si se redirige o se responde 404. La rama del
 * rol equivocado necesita una peticion web de verdad, y se ha comprobado a mano
 * contra el servidor: un administrador recibe 404 al pedir /azafata, y un
 * usuario sin sesion recibe una redireccion 303 a la pantalla de acceso.
 *
 * Se deja constancia aqui porque es facil escribir una prueba que simule la
 * sesion en $_SESSION y de por comprobado un camino que en realidad no se ha
 * ejecutado: Autorizacion::usuario() ni siquiera mira $_SESSION cuando la
 * peticion no es web.
 *
 * @return void
 */
function caso4(): void
{
    echo 'Caso 4: el control de acceso por rol', PHP_EOL;

    // El nucleo anota los intentos denegados en un log de texto aparte del de
    // errores, para que el administrador pueda revisarlos sin leer errores del
    // sistema mezclados. Se mide su tamano antes de hacer nada, para poder
    // comprobar que se anota y para devolver el fichero a como estaba: una
    // prueba no debe dejar entradas de mentira en el log de acceso de una
    // instalacion de verdad.
    $log = \App\Core\Aplicacion::raiz() . 'storage/logs/acceso.log';
    $tamanoInicial = is_file($log) ? (int) filesize($log) : 0;

    // ---- La consola no tiene usuario -----------------------------------------
    // Aunque se rellene $_SESSION a mano, el nucleo devuelve null, porque
    // esPeticionWeb() es false en PHP_SAPI 'cli'. Se comprueba, para que si
    // alguien tocara esa guarda sin querer, esta prueba lo note.
    comprobar(
        !\App\Core\Aplicacion::esPeticionWeb(),
        'Desde la consola la peticion no se considera web'
    );

    comprobar(
        \App\Core\Autorizacion::usuario() === null,
        'Desde la consola no hay usuario en sesion, aunque se rellene la sesion'
    );

    comprobar(
        !\App\Core\Autorizacion::haySesion(),
        'Desde la consola no se considera que haya sesion iniciada'
    );

    // ---- Sin usuario, pedir una pantalla privada redirige al acceso ---------
    comprobarFalla(
        \App\Core\Redirigir::class,
        static function (): void {
            \App\Core\Autorizacion::exigir(\App\Core\Autorizacion::ROL_ADMINISTRADOR);
        },
        'Sin sesion, exigir un rol lanza Redirigir en vez de cortar con exit'
    );

    try {
        \App\Core\Autorizacion::exigir(\App\Core\Autorizacion::ROL_ADMINISTRADOR);
    } catch (\App\Core\Redirigir $e) {
        comprobar(
            $e->ruta() === 'login',
            'La redireccion lleva a la pantalla de acceso',
            'lleva a ' . $e->ruta()
        );

        // 303 y no 302 porque, si la redireccion llega en respuesta a un POST,
        // con 302 algunos navegadores repiten el POST en la nueva URL. Con 303
        // obligan a repetir con GET, que es lo unico sensato aqui.
        comprobar(
            $e->codigo() === 303,
            'La redireccion usa 303, que obliga a repetir con GET',
            'usa el ' . $e->codigo()
        );
    }

    comprobar(
        is_file($log) && (int) filesize($log) > $tamanoInicial,
        'El intento denegado queda anotado en el log de acceso'
    );

    // ---- El log se devuelve a como estaba ------------------------------------
    // Sin esto, cada vez que se ejecutase la suite se acumularian intentos que
    // en realidad no ha hecho nadie, y el log de acceso dejaria de servir para
    // justo lo que existe: Revision de lo que ha pasado de verdad.
    if ($tamanoInicial === 0) {
        @unlink($log);
    } elseif (is_file($log)) {
        file_put_contents(
            $log,
            (string) file_get_contents($log, false, null, 0, $tamanoInicial),
            LOCK_EX
        );
    }

    // Sin limpiar la cache de estadisticas, filesize() seguiria devolviendo el
    // valor anterior y la comprobacion de abajo daria bien aunque el fichero
    // no se hubiera recortado. PHP guarda el resultado de las llamadas al
    // sistema de ficheros para no repetirlas, y en un mismo script eso se nota.
    clearstatcache(true, $log);

    comprobar(
        !is_file($log) || (int) filesize($log) === $tamanoInicial,
        'La prueba no deja entradas falsas en el log de acceso'
    );
}

/**
 * Caso 5: la cola de premios y el reparto por orden.
 *
 * Cubre los casos de aceptacion 3 y 4 del apartado 10 de la especificacion, que
 * son la regla central del apartado 6 escrita como comprobacion, y ademas el
 * arrastre de la cola entre dias, que es la decision D4 confirmada.
 *
 * QUE SE COMPRUEBA Y POR QUE ESTOS DATOS
 * ============================================================================
 *
 * Se usan las tres horas del ejemplo del apartado 6, porque es el caso que la
 * especificacion describe palabra por palabra: premios a las 10:12, 10:30 y 11:00,
 * y la primera participacion llega a las 11:20. Lo que hay que comprobar es que
 * la de las 11:20 se lleva el de las 10:12, la siguiente el de las 10:30 y la
 * tercera el de las 11:00.
 *
 * El orden importa tanto como el resultado. Una cola que devolviera los premios
 * al azar daria el numero correcto de ganadoras, y aun asi estaria mal: el
 * apartado 12 dice que no se presente como aleatorio un resultado que depende de
 * horarios y de orden de participacion, y una cola desordenada haria que un
 * premio de las 10:32 se adjudicara antes que uno de las 10:12, que es
 * exactamente el fallo que el apartado 9 describe.
 *
 * LA SEGUNDA PARTE, LA DEL ARRASTRE ENTRE DIAS
 * ============================================================================
 *
 * Todo lo anterior pasa dentro de un solo dia, y hay una razon para que el caso
 * tenga dos mitades y no una. «La cola se mantiene a lo largo de los tramos y de
 * los dias» es una frase que no falla si nadie la ejecuta: el codigo haria lo que
 * hiciese y la suite seguiria en verde. Por eso D4, al confirmarse, se ha
 * convertido en comprobaciones y no en un comentario.
 *
 * El dato que hace falta para probarlo es un segundo dia, porque con uno solo no
 * hay arrastre que observar. Se monta una campana con dos tramos, uno por dia, con
 * un premio ayer sin reclamar y otro hoy todavia sin llegar a su hora. La
 * participacion entra hoy y tiene que llevarse el de ayer.
 *
 * Y se comprueba tambien lo que NO tiene que pasar, que es la mitad que mas
 * cuesta defender: ni «inicio» ni «tramo_id» se reescriben, y el retraso que ve
 * el panel sigue siendo de mas de un dia. Un premio reubicado al dia siguiente
 * pasaria la primera comprobacion y fallaria estas, y con razon: habria perdido
 * su horario original (D9) y su retraso real.
 *
 * @see \App\Models\UnidadPremio::primeraPendiente()
 * @see decision D4
 *
 * @return void
 */
function caso5(): void
{
    echo 'Caso 5: la cola reparte por orden y arrasta los premios entre dias', PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    $escenario = crearEscenarioDeAdjudicacion(['10:12:00', '10:30:00', '11:00:00', '12:00:00']);
    $unidades = $escenario['unidades'];

    $motor = new \App\Services\Adjudicador(new ValidadorQueAcepta());

    // ---- Antes de la hora del primer premio: sin premio --------------------
    // Caso de aceptacion 3. A las 09:00 no ha llegado ninguna unidad, y una
    // participacion sin premio no puede haber tocado la cola.
    $resultado = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso5-antes'),
        $escenario['tramo'],
        ['nombre' => 'Ana'],
        null,
        null,
        instanteDeHoy('09:00:00')
    );

    comprobarIgual('sin_premio', $resultado['resultado'], 'Antes de la hora del primer premio el resultado es sin premio');
    comprobar(
        $resultado['unidad_id'] === null,
        'Una participacion sin premio no recibe ninguna unidad'
    );

    $estados = (new \App\Models\UnidadPremio())->contarPorEstado($escenario['promocion']);
    comprobarIgual(4, $estados['programada'] ?? 0, 'El intento sin premio no ha consumido ninguna unidad');

    // ---- Tres unidades vencidas: una por participacion, de mas antigua a mas
    // Caso de aceptacion 4, con el ejemplo del apartado 6.
    $esperados = [
        'caso5-1' => $unidades[0],   // la de las 10:12
        'caso5-2' => $unidades[1],   // la de las 10:30
        'caso5-3' => $unidades[2],   // la de las 11:00
    ];

    foreach ($esperados as $semilla => $unidadEsperada) {
        $resultado = $motor->registrar(
            $escenario['promocion'],
            claveDePrueba($semilla),
            $escenario['tramo'],
            ['nombre' => 'Cliente ' . $semilla],
            null,
            null,
            instanteDeHoy('11:20:00')
        );

        comprobarIgual('premio', $resultado['resultado'], 'La participacion ' . $semilla . ' recibe un premio');
        comprobarIgual(
            $unidadEsperada,
            $resultado['unidad_id'],
            'La participacion ' . $semilla . ' se lleva la unidad que le toca por orden'
        );
    }

    // ---- Un premio futuro no se entrega antes de su hora -------------------
    // El apartado 9 lo pide de forma expresa. Queda una unidad, la de las 12:00,
    // y por mucho que se insista a las 11:20 no puede salir.
    $resultado = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso5-futuro'),
        $escenario['tramo'],
        ['nombre' => 'Cliente con demasiada suerte'],
        null,
        null,
        instanteDeHoy('11:20:00')
    );

    comprobarIgual(
        'sin_premio',
        $resultado['resultado'],
        'Un premio cuya hora no ha llegado no se entrega antes de su hora'
    );

    // ---- Y en cuanto llega su hora, se entrega ----------------------------
    $resultado = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso5-futuro-tarde'),
        $escenario['tramo'],
        ['nombre' => 'Cliente puntual'],
        null,
        null,
        instanteDeHoy('12:00:00')
    );

    comprobarIgual('premio', $resultado['resultado'], 'El premio se entrega en cuanto llega su hora exacta');
    comprobarIgual($unidades[3], $resultado['unidad_id'], 'Se entrega la unidad que quedaba, la de las 12:00');

    // ---- Estado final de la cola -------------------------------------------
    $estados = (new \App\Models\UnidadPremio())->contarPorEstado($escenario['promocion']);
    comprobarIgual(4, $estados['entregada'] ?? 0, 'Las cuatro unidades han acabado entregadas');
    comprobarIgual(0, $estados['programada'] ?? 0, 'No queda ninguna unidad programada');

    $resultados = (new \App\Models\Participacion())->contarPorResultado($escenario['promocion']);
    comprobarIgual(4, $resultados['premio'] ?? 0, 'Hay cuatro participaciones con premio');
    comprobarIgual(2, $resultados['sin_premio'] ?? 0, 'Hay dos participaciones sin premio');

    // ---- El codigo de reclamacion ------------------------------------------
    // Cada unidad lleva el suyo, y son distintos. Es lo que recibe la clienta y
    // lo que la azafata le entrega, asi que dos clientas con el mismo codigo
    // seria un fallo grave.
    $filas = \App\Core\Aplicacion::db()->todos(
        'SELECT codigo_reclamacion FROM unidades_premio WHERE promocion_id = ?',
        [$escenario['promocion']]
    );

    $valores = array_column($filas, 'codigo_reclamacion');
    comprobarIgual(4, count(array_unique($valores)), 'Cada unidad entregada tiene un codigo de reclamacion distinto');
    comprobar(
        count(array_filter($valores, static fn($codigo) => is_string($codigo) && $codigo !== '')) === 4,
        'Las cuatro unidades tienen un codigo de reclamacion guardado'
    );

    // ---- El correo se encola, no se envia ----------------------------------
    // La campana de este caso tiene el correo apagado, y el esquema lo pone por
    // defecto a proposito. Que no haya ningun mensaje en la cola lo comprueba.
    comprobarIgual(0, mensajesEnCola($escenario['promocion']), 'Sin correo activado no se encola ningun mensaje');

    borrarEscenarioDeAdjudicacion();

    // =========================================================================
    // EL ARRASTRE ENTRE DIAS, QUE ES LO QUE D4 DICE
    // =========================================================================
    //
    // Todo lo anterior ocurre dentro de un solo dia. Esta parte es la que fija la
    // decision D4, y no podia quedarse en un comentario: «la cola se mantiene a lo
    // largo de los tramos y de los dias» es una frase que no falla si nadie la
    // ejecuta, y este proyecto ha encontrado en la prueba los fallos que la vista
    // no ve.
    //
    // El montaje: dos dias, con un tramo cada uno. Ayer queda un premio sin
    // reclamar y hoy hay otro que aun no ha llegado a su hora. La participacion
    // entra hoy. Lo que tiene que pasar es que se lleve el de AYER, que es el mas
    // antiguo de la cola, y no el de hoy.
    $hoy = date('Y-m-d');
    $ayer = date('Y-m-d', strtotime('-1 day'));
    $db = \App\Core\Aplicacion::db();

    $dosDias = crearEscenarioDeAdjudicacion([], [
        'dias'        => [$ayer, $hoy],
        'horasPorDia' => [
            $ayer => ['10:12:00'],
            $hoy  => ['11:00:00'],
        ],
    ]);

    $premioDeAyer = (int) $db->valor(
        'SELECT id FROM unidades_premio WHERE promocion_id = ? AND inicio = ?',
        [$dosDias['promocion'], $ayer . ' 10:12:00']
    );
    $premioDeHoy = (int) $db->valor(
        'SELECT id FROM unidades_premio WHERE promocion_id = ? AND inicio = ?',
        [$dosDias['promocion'], $hoy . ' 11:00:00']
    );

    comprobar(
        $premioDeAyer > 0 && $premioDeHoy > 0,
        'El escenario de dos dias tiene un premio en cada dia'
    );

    $motor = new \App\Services\Adjudicador(new ValidadorQueAcepta());

    $resultado = $motor->registrar(
        $dosDias['promocion'],
        claveDePrueba('caso5-arrastre'),
        $dosDias['tramos'][$hoy],
        ['nombre' => 'Cliente del segundo dia'],
        null,
        null,
        $hoy . ' 11:20:00'
    );

    comprobarIgual('premio', $resultado['resultado'], 'La participacion del segundo dia recibe premio');
    comprobarIgual(
        $premioDeAyer,
        (int) $resultado['unidad_id'],
        'Y se lleva el premio de AYER, que es el mas antiguo de la cola'
    );

    // Y el premio de hoy sigue en la cola para la siguiente participacion, que es
    // lo que significa que la cola se ordene por hora programada y no por dia.
    comprobarIgual(
        'programada',
        (string) $db->valor(
            'SELECT estado FROM unidades_premio WHERE id = ?',
            [$premioDeHoy]
        ),
        'El premio de HOY sigue esperando, porque su turno es el siguiente'
    );

    // ---- Y LO QUE NO SE TOCA, QUE ES LA OTRA MITAD DE LA DECISION ----------
    //
    // Confirmar que la cola persiste no es solo decir que el premio viejo sigue
    // ahí. Es decir que NO se ha reescrito su fecha ni su tramo para que parezca
    // de hoy. Si alguien anade ese reubicado «para que la cola quede ordenada»,
    // estas dos comprobaciones son las que lo delatan, y ademas se pierde el
    // retraso real: el panel lo saca de la diferencia entre «inicio» y
    // «adjudicada_en», y un premio del lunes reclamado el martes tiene que
    // informar de mas de un dia de retraso, no de cero.
    $unidadAyer = $db->uno(
        'SELECT tramo_id, inicio, adjudicada_en FROM unidades_premio WHERE id = ?',
        [$premioDeAyer]
    );

    comprobarIgual(
        $ayer . ' 10:12:00',
        (string) $unidadAyer['inicio'],
        'La hora programada del premio sigue siendo la de ayer, sin reescribir'
    );
    comprobarIgual(
        $dosDias['tramos'][$ayer],
        (int) $unidadAyer['tramo_id'],
        'Y sigue en el tramo de ayer, que es la referencia autoritativa de D9'
    );
    comprobar(
        strtotime((string) $unidadAyer['adjudicada_en']) - strtotime((string) $unidadAyer['inicio'])
            > 24 * 60 * 60,
        'Y el retraso que informa el panel es de mas de un dia, no de cero'
    );

    borrarEscenarioDeAdjudicacion();
}

/**
 * Caso 6: las reglas y el reintento del mismo intento.
 *
 * Cubre los casos de aceptacion 5 y 7 del apartado 10, que son los dos que se
 * apoyan en la clave de idempotencia y en la separacion entre participaciones y
 * rechazos.
 *
 * @return void
 */
function caso6(): void
{
    echo 'Caso 6: rechazar sin consumir premio y reintentar sin duplicar', PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    $escenario = crearEscenarioDeAdjudicacion(['10:00:00', '10:30:00'], ['correo' => true]);
    $unidades = $escenario['unidades'];

    // ---- Rechazar sin consumir un premio (caso 5) -------------------------
    $validador = new ValidadorQueRechaza('duplicado', 'Ya ha participado en esta campana.');
    $motor = new \App\Services\Adjudicador($validador);

    $resultado = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso6-rechazo'),
        $escenario['tramo'],
        ['nombre' => 'Ana', 'correo' => 'ana@example.com'],
        null,
        null,
        instanteDeHoy('11:00:00')
    );

    comprobarIgual('rechazada', $resultado['resultado'], 'Un intento que infringe una regla se rechaza');
    comprobarIgual('duplicado', $resultado['motivo_codigo'], 'El rechazo guarda el codigo del motivo');
    comprobar(
        $resultado['participacion_id'] === null,
        'Un intento rechazado no se registra como participacion'
    );

    $estados = (new \App\Models\UnidadPremio())->contarPorEstado($escenario['promocion']);
    comprobarIgual(2, $estados['programada'] ?? 0, 'Un intento rechazado NO consume ninguna unidad');

    $participaciones = (new \App\Models\Participacion())->contarPorResultado($escenario['promocion']);
    comprobar(
        !isset($participaciones['premio']) && !isset($participaciones['sin_premio']),
        'Un rechazo no deja ninguna participacion registrada'
    );

    $rechazos = (new \App\Models\IntentoRechazado())->contarPorMotivo($escenario['promocion']);
    comprobarIgual(1, $rechazos['duplicado'] ?? 0, 'El rechazo queda anotado una sola vez');

    // ---- Un rechazo no guarda los datos de la clienta (decision D10) -------
    $ip = \App\Core\Aplicacion::db()->todos(
        'SELECT * FROM intentos_rechazados WHERE promocion_id = ?',
        [$escenario['promocion']]
    );

    comprobar(
        !array_key_exists('datos', $ip[0]),
        'La tabla de rechazos no tiene columna de datos, porque no se guarda lo que escribio la clienta'
    );

    // ---- El mismo intento rechazado otra vez (caso 7) ----------------------
    $llamadasAntes = $validador->llamadas;

    $repetido = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso6-rechazo'),
        $escenario['tramo'],
        ['nombre' => 'Ana', 'correo' => 'ana@example.com'],
        null,
        null,
        instanteDeHoy('11:00:00')
    );

    comprobarIgual('rechazada', $repetido['resultado'], 'Un intento rechazado que se repite devuelve el mismo rechazo');
    comprobar($repetido['repetido'], 'El motor marca la respuesta como repeticion');

    $rechazos = (new \App\Models\IntentoRechazado())->contarPorMotivo($escenario['promocion']);
    comprobarIgual(1, $rechazos['duplicado'] ?? 0, 'El reintento de un rechazo no genera una segunda fila');

    comprobar(
        $validador->llamadas === $llamadasAntes,
        'Un reintento no vuelve a pasar por las reglas, porque el intento ya estaba resuelto'
    );

    // ---- Ahora una participacion valida, y su reintento --------------------
    $motor = new \App\Services\Adjudicador(new ValidadorQueAcepta());

    $primera = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso6-ok'),
        $escenario['tramo'],
        ['nombre' => 'Luis', 'correo' => 'luis@example.com'],
        null,
        null,
        instanteDeHoy('11:00:00')
    );

    comprobarIgual('premio', $primera['resultado'], 'Un intento valido recibe el premio que le toca');
    comprobarIgual($unidades[0], $primera['unidad_id'], 'Se lleva la unidad mas antigua de la cola');

    $otraVez = $motor->registrar(
        $escenario['promocion'],
        claveDePrueba('caso6-ok'),
        $escenario['tramo'],
        ['nombre' => 'Luis', 'correo' => 'luis@example.com'],
        null,
        null,
        instanteDeHoy('11:00:00')
    );

    comprobarIgual(
        $primera['participacion_id'],
        $otraVez['participacion_id'],
        'Un doble clic devuelve la MISMA participacion y no crea una segunda'
    );

    comprobarIgual(
        $primera['unidad_id'],
        $otraVez['unidad_id'],
        'Un doble clic devuelve la MISMA unidad y no consume un segundo premio'
    );

    comprobarIgual(
        $primera['codigo_reclamacion'],
        $otraVez['codigo_reclamacion'],
        'Un doble clic devuelve el mismo codigo de reclamacion'
    );

    comprobar($otraVez['repetido'], 'El motor marca la segunda llamada como repeticion');

    $estados = (new \App\Models\UnidadPremio())->contarPorEstado($escenario['promocion']);
    comprobarIgual(1, $estados['entregada'] ?? 0, 'El doble clic no ha entregado una segunda unidad');

    $resultados = (new \App\Models\Participacion())->contarPorResultado($escenario['promocion']);
    comprobarIgual(1, $resultados['premio'] ?? 0, 'Solo hay una participacion registrada');

    // ---- El correo se encola dentro de la misma transaccion ----------------
    comprobarIgual(
        1,
        mensajesEnCola($escenario['promocion']),
        'Con correo activado se encola un mensaje por adjudicacion, y ninguno mas por el reintento'
    );

    $correo = \App\Core\Aplicacion::db()->uno(
        'SELECT tipo, destinatario, asunto, cuerpo, estado FROM correos WHERE promocion_id = ?',
        [$escenario['promocion']]
    );

    comprobarIgual('ganador', $correo['tipo'], 'El mensaje encolado es el de la ganadora');
    comprobarIgual('luis@example.com', $correo['destinatario'], 'El mensaje va al correo de la participacion');
    comprobarIgual('pendiente', $correo['estado'], 'El mensaje queda en la cola, sin enviar: el envio es del hito 5');
    comprobar(
        strpos((string) $correo['cuerpo'], (string) $primera['codigo_reclamacion']) !== false,
        'El cuerpo del correo lleva el codigo de reclamacion ya sustituido'
    );
    comprobar(
        strpos((string) $correo['cuerpo'], '{{') === false,
        'No queda ningun marcador sin sustituir en el correo'
    );

    // ---- Una clave con formato invalido se rechaza antes de tocar nada -----
    comprobarFalla(
        \App\Core\ErrorValidacion::class,
        static function () use ($motor, $escenario): void {
            $motor->registrar(
                $escenario['promocion'],
                "'; DROP TABLE participaciones; --",
                $escenario['tramo'],
                ['nombre' => 'Atacante'],
                null,
                null,
                instanteDeHoy('11:00:00')
            );
        },
        'Una clave de idempotencia con formato invalido se rechaza con un error de validacion'
    );

    comprobar(
        (int) \App\Core\Aplicacion::db()->valor('SELECT COUNT(*) FROM unidades_premio') > 0,
        'La tabla de unidades sigue en pie despues del intento de inyeccion'
    );

    borrarEscenarioDeAdjudicacion();
}

/**
 * Caso 7: dos participaciones simultaneas con una sola unidad.
 *
 * Cubre el caso de aceptacion 6, y es el unico caso de la suite que necesita
 * procesos de verdad. Ver el comentario de tests/_proceso.php para que no se puede
 * comprobar en un solo proceso.
 *
 * ============================================================================
 * QUE SE COMPRUEBA
 * ============================================================================
 *
 * Dos procesos con conexiones propias intentan participar en el mismo instante,
 * con una sola unidad disponible. Exactamente uno debe recibir el premio, el otro
 * debe quedarse sin premio, y en la tabla de unidades debe haber exactamente una
 * fila entregada.
 *
 * Las tres comprobaciones importan. Si los dos recibieran premio, la tercera
 * detectaria que hay dos unidades entregadas aunque solo habia una. Si los dos
 * quedaran sin premio, habria un premio que no se ha entregado a nadie. Y si solo
 * uno recibiera el premio pero quedaran dos participaciones con resultado «premio»,
 * la contabilidad de la campana no cuadraria aunque el reparto de premios fuera
 * correcto, que es justo lo que el apartado 6 pide evitar.
 *
 * @return void
 */
function caso7(): void
{
    echo 'Caso 7: dos participaciones simultaneas con una sola unidad', PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    // Una sola unidad, y dos claves de intento distintas: no es un reintento del
    // mismo intento, son dos personas distintas.
    $escenario = crearEscenarioDeAdjudicacion(['10:00:00']);

    $ficheroHijo = __DIR__ . '/_proceso.php';
    $directorio = sys_get_temp_dir();
    $salidas = [
        $directorio . '/sorteos-concurrencia-1.json',
        $directorio . '/sorteos-concurrencia-2.json',
    ];

    // Los ficheros de la ejecucion anterior podrian quedar si el proceso padre se
    // matase a mitad. Se borran antes de nada, para no leer un resultado viejo y
    // darlo por bueno.
    foreach ($salidas as $salida) {
        if (is_file($salida)) {
            @unlink($salida);
        }
    }

    $config = \App\Core\Aplicacion::config();
    $basePruebas = (string) $config['bd']['nombre'];

    $procesos = [];

    foreach ($salidas as $indice => $salida) {
        $orden = [
            PHP_BINARY,
            $ficheroHijo,
            $basePruebas,
            $salida,
            claveDePrueba('caso7-persona-' . $indice),
            instanteDeHoy('11:00:00'),
            (string) $escenario['tramo'],
            // El identificador de la campana va explicito, no se busca por
            // nombre dentro del proceso hijo. Ver la nota de _proceso.php.
            (string) $escenario['promocion'],
        ];

        $mandos = proc_open(
            $orden,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias
        );

        comprobar(
            is_resource($mandos),
            'Se ha podido lanzar el proceso ' . ($indice + 1) . ' de la prueba de concurrencia'
        );

        if (!is_resource($mandos)) {
            continue;
        }

        $procesos[$indice] = ['mandos' => $mandos, 'tuberias' => $tuberias];
    }

    // Se recoge la salida de los dos antes de esperar, para que ningun proceso se
    // quede bloqueado escribiendo en una tuberia que nadie lee.
    $errores = [];

    foreach ($procesos as $indice => $proceso) {
        $errores[$indice] = (string) stream_get_contents($proceso['tuberias'][1])
            . (string) stream_get_contents($proceso['tuberias'][2]);

        fclose($proceso['tuberias'][1]);
        fclose($proceso['tuberias'][2]);
    }

    foreach ($procesos as $proceso) {
        proc_close($proceso['mandos']);
    }

    // ---- Que los dos procesos hayan terminado bien ------------------------
    $lecturas = [];

    foreach ($salidas as $indice => $salida) {
        $contenido = is_file($salida) ? trim((string) file_get_contents($salida)) : '';
        $lecturas[$indice] = $contenido === '' ? null : json_decode($contenido, true);

        comprobar(
            is_array($lecturas[$indice]) && !empty($lecturas[$indice]['ok']),
            'El proceso ' . ($indice + 1) . ' ha terminado y ha escrito su resultado',
            $lecturas[$indice] === null
                ? 'no ha escrito nada. Su salida fue: ' . ($errores[$indice] ?? '(sin salida)')
                : json_encode($lecturas[$indice])
        );

        if (is_file($salida)) {
            @unlink($salida);
        }
    }

    if (in_array(null, $lecturas, true)) {
        // Si un proceso no ha llegado a escribir, no tiene sentido comprobar los
        // repartos: darian verde por falta de datos y no porque el motor funcione.
        // Se dice por pantalla y se sale del caso con los fallos ya anotados.
        comprobar(false, 'Sin los dos resultados no se puede comprobar el reparto');
        borrarEscenarioDeAdjudicacion();
        return;
    }

    $resultados = array_column(array_column($lecturas, 'resultado'), 'resultado');

    comprobarIgual(
        1,
        count(array_keys($resultados, 'premio', true)),
        'De las dos participaciones simultaneas, solo UNA recibe el premio'
    );

    comprobarIgual(
        1,
        count(array_keys($resultados, 'sin_premio', true)),
        'La otra participacion se queda sin premio, en vez de desaparecer'
    );

    comprobarIgual(2, count($resultados), 'Los dos procesos han respondido');

    // ---- Y la tabla de unidades lo confirma --------------------------------
    $estados = (new \App\Models\UnidadPremio())->contarPorEstado($escenario['promocion']);
    comprobarIgual(1, $estados['entregada'] ?? 0, 'Hay exactamente UNA unidad entregada de la que habia');
    comprobarIgual(0, $estados['programada'] ?? 0, 'No queda ninguna unidad programada');

    $resultadosDeLaCampana = (new \App\Models\Participacion())->contarPorResultado($escenario['promocion']);
    comprobarIgual(1, $resultadosDeLaCampana['premio'] ?? 0, 'Solo una participacion queda con resultado «premio»');
    comprobarIgual(1, $resultadosDeLaCampana['sin_premio'] ?? 0, 'La otra queda con resultado «sin premio»');

    // Las dos participaciones tienen que ser de dos personas distintas, que es lo
    // que distingue este caso del de un doble clic.
    $claves = (int) \App\Core\Aplicacion::db()->valor(
        'SELECT COUNT(DISTINCT clave_idempotencia) FROM participaciones WHERE promocion_id = ?',
        [$escenario['promocion']]
    );

    comprobarIgual(2, $claves, 'Las dos participaciones vienen de dos intentos distintos');

    borrarEscenarioDeAdjudicacion();
}

/**
 * Caso 8: el panel se monta y el reparto sale del plan.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE POR ESTE ORDEN
 * ============================================================================
 *
 * Monta una campana con la configuracion que exige el apartado 4.2 -premios,
 * tramos, cantidades, formulario y reglas- y luego hace lo que hace el
 * administrador: generar el calendario.
 *
 * El orden importa. Se comprueba primero que la campana esta incompleta y que la
 * activacion se niega, porque esa negativa es la que protege lo demas: si se
 * pudiera activar con el calendario vacio, todo lo que viene despues daria igual
 * de falso. Luego se genera, y solo entonces se comprueba que la campana queda
 * activable.
 *
 * La activacion se prueba por el servicio y no por el boton de la ficha, porque
 * la pulsacion exige un token de sesion que en consola no existe. Lo que se
 * comprueba con la pantalla es que el boton aparece o no segun la misma lista de
 * pendientes, que es lo que impide saltarsela.
 *
 * @return void
 */
function caso8(): void
{
    echo 'Caso 8: el panel se monta y el reparto sale del plan', PHP_EOL;

    $escenario = crearEscenarioDePanel(['premios' => 2, 'tramos' => 2]);
    $id = (int) $escenario['promocion'];
    $config = new \App\Services\ConfiguracionPromocion();

    // ---- Antes de generar, la campana no se puede activar ------------------
    $pendientes = $config->pendientesDeActivar($id);
    $sinCalendario = array_values(array_filter(
        $pendientes,
        static fn (string $p): bool => str_contains($p, 'calendario no esta generado')
    ));

    comprobar(
        count($sinCalendario) === 1,
        'Sin generar el reparto, la activacion avisa de que falta el calendario',
        'pendientes: ' . implode(' | ', $pendientes)
    );

    comprobarFalla(
        \App\Core\ErrorValidacion::class,
        static fn () => $config->activar($id),
        'Una campana sin calendario NO se puede activar'
    );

    comprobar(
        (new \App\Models\Promocion())->exigirPorId($id, 'x')['estado'] === \App\Models\Promocion::ESTADO_BORRADOR,
        'El intento fallido de activar ha dejado la campana en borrador'
    );

    // ---- Generar el reparto ------------------------------------------------
    $informe = (new \App\Services\Calendario())->generar($id);

    comprobar(
        (int) ($informe['generado'] ?? 0) > 0,
        'El generador ha repartido unidades a partir del plan',
        'informe: ' . json_encode($informe, JSON_UNESCAPED_UNICODE)
    );

    comprobar(
        empty($informe['problemas']),
        'El generador no ha encontrado problemas en el plan que se ha escrito',
        'problemas: ' . json_encode($informe['problemas'] ?? [], JSON_UNESCAPED_UNICODE)
    );

    // La comparacion tiene que dar el mismo numero que el plan: es la forma de
    // comprobar que el reparto ha salido entero, y no solo que el generator ha
    // dicho que si.
    $comparacion = $config->compararPlanYCalendario($id);
    $desajustes = array_values(array_filter($comparacion, static fn (array $f): bool => (bool) $f['cambia']));

    comprobarIgual(0, count($desajustes), 'El calendario coincide con el plan, fila a fila');

    // ---- Y ahora si se puede activar ---------------------------------------
    $pendientes = $config->pendientesDeActivar($id);
    comprobarIgual([], $pendientes, 'Con el calendario hecho, no falta nada para activar');

    $htmlFicha = htmlDeAccion('ControladorCampanas', 'ficha', ['id' => $id]);
    comprobarContiene($htmlFicha, 'Activar la campana', 'La ficha ofrece el boton de activar cuando ya no falta nada');

    $antesDeActivar = $config->avisosDeConfiguracion($id);
    $config->activar($id);
    limpiarPeticion();

    $campana = (new \App\Models\Promocion())->exigirPorId($id, 'x');
    comprobarIgual(\App\Models\Promocion::ESTADO_ACTIVA, $campana['estado'], 'La campana se ha activado de verdad');

    $despuesDeActivar = $config->avisosDeConfiguracion($id);
    comprobar(
        count($despuesDeActivar) === count($antesDeActivar),
        'Activar no ha inventado ni quitado avisos',
        'antes: ' . count($antesDeActivar) . ', despues: ' . count($despuesDeActivar)
    );

    // ---- Y una vez activa, la ficha ya no ofrece volver a activarla -------
    $htmlFicha = htmlDeAccion('ControladorCampanas', 'ficha', ['id' => $id]);
    comprobarContiene($htmlFicha, 'La campana esta activa', 'La ficha dice que la campana ya esta activa');
    comprobarNoContiene($htmlFicha, '>Activar la campana<', 'La ficha ya no ofrece activar una campana que lo esta');

    borrarEscenarioDePanel($id);
}

/**
 * Caso 9: cada pantalla del panel pinta y encaja con su controlador.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE
 * ============================================================================
 *
 * Abre las once pantallas del panel y comprueba que cada una se pinta y que
 * enseña lo suyo. El fallo que se busca aqui no es un error de PHP, sino el mas
 * dificil de ver: una vista que espera una clave que el controlador no le pasa,
 * o que pinta un dato de otra campana.
 *
 * Se comprueba tambien que la ficha no enseña datos de otra campana. Es el fallo
 * de seguridad mas probable de un panel con ocho pantallas, y el que menos se
 * nota a ojo, porque la pantalla se ve bien: solo que enseña el numero de otra.
 *
 * @return void
 */
function caso9(): void
{
    echo 'Caso 9: las once pantallas del panel pintan lo que deben', PHP_EOL;

    $primera = crearEscenarioDePanel(['premios' => 1, 'tramos' => 1, 'sufijo' => 'una']);
    $segunda = crearEscenarioDePanel(['premios' => 1, 'tramos' => 1, 'sufijo' => 'dos']);

    $una = (int) $primera['promocion'];
    $otra = (int) $segunda['promocion'];

    $nombreDeLaPrimera = (string) (new \App\Models\Promocion())->exigirPorId($una, 'x')['nombre'];
    $nombreDeLaOtra = (string) (new \App\Models\Promocion())->exigirPorId($otra, 'x')['nombre'];

    // ---- Las once pantallas se pintan sin romperse -------------------------
    $pantallas = [
        ['ControladorCampanas', 'listar', [], 'Campanas'],
        ['ControladorCampanas', 'ficha', ['id' => $una], 'Resumen'],
        ['ControladorCampanas', 'nueva', [], 'Nueva'],
        ['ControladorCampanas', 'editar', ['id' => $una], 'Comercio'],
        ['ControladorFormulario', 'premios', ['id' => $una], 'Anadir premio'],
        ['ControladorFormulario', 'formulario', ['id' => $una], 'el orden en el que'],
        ['ControladorCampana', 'reglas', ['id' => $una], 'Una participacion por persona'],
        ['ControladorCampana', 'ajustes', ['id' => $una], 'Modo simulacion'],
        ['ControladorCampana', 'apariencia', ['id' => $una], 'Textos de resultado'],
        ['ControladorCampana', 'tramos', ['id' => $una], 'Anadir tramo'],
        ['ControladorCampana', 'calendario', ['id' => $una], 'Generar el reparto'],
    ];

    foreach ($pantallas as [$controlador, $metodo, $parametros, $esperado]) {
        try {
            $html = htmlDeAccion($controlador, $metodo, $parametros);
            comprobar(
                strlen($html) > 500,
                'La pantalla ' . $controlador . '::' . $metodo . ' se ha pintado entera',
                'solo han salido ' . strlen($html) . ' bytes'
            );
            comprobarContiene($html, $esperado, 'La pantalla ' . $metodo . ' enseña su contenido');
        } catch (Throwable $e) {
            comprobar(false, 'La pantalla ' . $controlador . '::' . $metodo . ' se puede pintar', get_class($e) . ': ' . $e->getMessage());
        }
    }

    // ---- El listado ve las dos campanas, y la ficha solo la suya ----------
    $listado = htmlDeAccion('ControladorCampanas', 'listar', []);
    comprobarContiene($listado, htmlspecialchars($nombreDeLaPrimera, ENT_QUOTES, 'UTF-8'), 'El listado enseña la primera campana');
    comprobarContiene($listado, htmlspecialchars($nombreDeLaOtra, ENT_QUOTES, 'UTF-8'), 'El listado enseña la segunda campana');

    $ficha = htmlDeAccion('ControladorCampanas', 'ficha', ['id' => $una]);
    comprobarContiene($ficha, htmlspecialchars($nombreDeLaPrimera, ENT_QUOTES, 'UTF-8'), 'La ficha enseña el nombre de su campana');
    comprobarNoContiene($ficha, htmlspecialchars($nombreDeLaOtra, ENT_QUOTES, 'UTF-8'), 'La ficha NO enseña el nombre de otra campana');

    // El identificador de la otra campana tampoco puede aparecer. Se cuenta el
    // de la propia: si se pide la ficha con el identificador de la otra, no puede
    // enseñarla.
    $fichaDeLaOtra = htmlDeAccion('ControladorCampanas', 'ficha', ['id' => $otra]);
    comprobarContiene($fichaDeLaOtra, htmlspecialchars($nombreDeLaOtra, ENT_QUOTES, 'UTF-8'), 'La ficha de la segunda enseña su propio nombre');
    comprobarNoContiene($fichaDeLaOtra, htmlspecialchars($nombreDeLaPrimera, ENT_QUOTES, 'UTF-8'), 'La ficha de la segunda NO enseña la primera');

    // ---- Un tramo de otra campana no se puede tocar desde esta -------------
    $tramoAjeno = (int) $primera['tramos'][0];
    comprobarFalla(
        \App\Core\NoEncontrado::class,
        static fn () => (new \App\Models\Tramo())->exigirPorId($tramoAjeno, 'x', $otra),
        'Un tramo de la primera campana NO se puede editar desde la pantalla de la segunda'
    );

    // ---- Y un identificador que no es un numero da 404 antes de la consulta -
    comprobarFalla(
        \App\Core\NoEncontrado::class,
        static function () use ($otra): void {
            htmlDeAccion('ControladorCampanas', 'ficha', ['id' => '1;DROP TABLE promociones']);
        },
        'Un identificador con texto malicioso da 404 sin llegar a la base de datos'
    );

    borrarEscenarioDePanel($una);
    borrarEscenarioDePanel($otra);
}

/**
 * Caso 10: lo que el panel no deja hacer.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE
 * ============================================================================
 *
 * Un panel se juzga tambien por lo que se niega a hacer. Aqui se comprueban las
 * cuatro negaciones que protegen la campana: no se escribe el estado desde el
 * formulario de datos, no se solapan los tramos, no se accepta un tramo que no
 * cabe en sus minutos sin decirlo, y no se puede tocar un tramo de otra
 * campana.
 *
 * Se comprueban por el servicio y no por la pantalla porque son reglas de
 * negocio, y porque asi se pueden probar las cuatro sin montar un formulario con
 * token de sesion.
 *
 * @return void
 */
function caso10(): void
{
    echo 'Caso 10: lo que el panel no deja hacer', PHP_EOL;

    $escenario = crearEscenarioDePanel(['premios' => 1, 'tramos' => 1]);
    $id = (int) $escenario['promocion'];
    $config = new \App\Services\ConfiguracionPromocion();
    $tramos = new \App\Services\Tramos();
    $validador = new \App\Core\Validador();

    // ---- El estado no se puede cambiar desde los datos generales ----------
    comprobarFalla(
        \App\Core\ErrorValidacion::class,
        static function () use ($config, $id): void {
            $config->guardar([
                'nombre'             => 'Campana manipulada',
                'descripcion'        => '',
                'comercio_nombre'    => '',
                'comercio_cif'       => '',
                'comercio_domicilio' => '',
                'comercio_telefono'  => '',
                'fecha_inicio'       => date('Y-m-d'),
                'fecha_fin'          => '',
                'zona_horaria'       => 'Europe/Madrid',
                'estado'             => 'activa',
            ], $id);
        },
        'Los datos generales NO aceptan un estado activo'
    );

    $campana = (new \App\Models\Promocion())->exigirPorId($id, 'x');
    comprobarIgual(\App\Models\Promocion::ESTADO_BORRADOR, $campana['estado'], 'La campana sigue en borrador');

    // ---- Dos tramos que se solapan no se guardan --------------------------
    // El escenario deja un tramo de 11:00 a 23:00, que es el punto de partida de
    // estas dos comprobaciones. Un tramo de 10:00 a 12:00 se monta dentro de el
    // y uno de 09:00 a 11:00 lo toca justo por su primer extremo.
    $hoy = date('Y-m-d');
    $validador = new \App\Core\Validador();
    $datos = $tramos->validarCampos($validador, 'tramo', $hoy, '10:00', '12:00');
    comprobar($datos !== null, 'El tramo de las 10 a las 12 es valido en si mismo');

    $validador = new \App\Core\Validador();
    $datos = $tramos->validarCampos($validador, 'tramo', $hoy, '10:00', '12:00');
    $tramos->comprobarSolapes($validador, $id, 'tramo', (string) $datos['fecha'], (string) $datos['hora_inicio'], (string) $datos['hora_fin'], null);

    comprobar(
        $validador->tieneErrores(),
        'Un tramo que empieza dentro de otro se marca como solapado',
        'errores: ' . json_encode($validador->errores(), JSON_UNESCAPED_UNICODE)
    );

    // ---- Tocar un extremo si vale -----------------------------------------
    $validador = new \App\Core\Validador();
    $datos = $tramos->validarCampos($validador, 'tramo', $hoy, '09:00', '11:00');
    $tramos->comprobarSolapes($validador, $id, 'tramo', (string) $datos['fecha'], (string) $datos['hora_inicio'], (string) $datos['hora_fin'], null);
    comprobar(
        !$validador->tieneErrores(),
        'Un tramo que acaba justo cuando empieza el otro NO es un solape',
        'errores: ' . json_encode($validador->errores(), JSON_UNESCAPED_UNICODE)
    );

    // ---- Un tramo con horas invalidas no llega a la base de datos ---------
    $validador = new \App\Core\Validador();
    $datos = $tramos->validarCampos($validador, 'tramo', $hoy, '25:00', '26:00');
    comprobar(
        $datos === null,
        'Un tramo con horas que no existen se rechaza antes de escribir'
    );

    $horasDelTramo = (int) \App\Core\Aplicacion::db()->valor(
        'SELECT COUNT(*) FROM tramos WHERE promocion_id = ? AND hora_inicio = ?',
        [$id, '25:00:00']
    );
    comprobarIgual(0, $horasDelTramo, 'No ha quedado ningun tramo con horas imposibles en la tabla');

    // ---- Un tramo con demasiados premios se avisa antes de generar ---------
    // El tramo del escenario va de 11:00 a 23:00, o sea 720 minutos. Se piden
    // 800 premios: hay mas unidades que minutos y no cabe ni de lejos.
    $db = \App\Core\Aplicacion::db();
    $tramoId = (int) $escenario['tramos'][0];
    $db->ejecutar(
        'UPDATE asignaciones_tramo SET cantidad = ? WHERE tramo_id = ?',
        [800, $tramoId]
    );

    $diagnostico = (new \App\Services\Calendario())->diagnosticar($id);
    comprobar(
        $diagnostico !== [],
        'Un tramo con mas premios que minutos se diagnostica ANTES de generar',
        'diagnostico: ' . json_encode($diagnostico, JSON_UNESCAPED_UNICODE)
    );

    $html = htmlDeAccion('ControladorCampana', 'calendario', ['id' => $id]);
    comprobarContiene($html, 'no caben en su tiempo', 'La pantalla del calendario enseña el problema antes de generar');

    // Generar sin marcar la casilla tiene que negarse, no repartir a cojas. No
    // se lanza una excepcion: generar() devuelve el informe con generado=false y
    // la lista de problemas, que es lo que la pantalla del calendario enseña.
    $informe = (new \App\Services\Calendario())->generar($id);
    comprobar(
        ($informe['generado'] ?? true) === false && (int) ($informe['unidades'] ?? -1) === 0 && $informe['problemas'] !== [],
        'Generar un tramo que no cabe, sin marcar la casilla, se niega',
        'informe: ' . json_encode($informe, JSON_UNESCAPED_UNICODE)
    );

    comprobarIgual(
        0,
        (int) $db->valor('SELECT COUNT(*) FROM unidades_premio WHERE tramo_id = ?', [$tramoId]),
        'Y el reparto que no cabia no ha escrito ninguna unidad'
    );

    // ---- Y un tramo con unidades ya generadas no se borra ----------------
    $db->ejecutar('UPDATE asignaciones_tramo SET cantidad = ? WHERE tramo_id = ?', [2, $tramoId]);
    (new \App\Services\Calendario())->generar($id);
    comprobarIgual(
        2,
        (int) $db->valor('SELECT COUNT(*) FROM unidades_premio WHERE tramo_id = ?', [$tramoId]),
        'El tramo que si cabia se ha repartido en dos unidades'
    );

    // borrarSiEstaLibre() no lanza: devuelve 0 y deja el tramo como estaba.
    comprobarIgual(
        0,
        (new \App\Models\Tramo())->borrarSiEstaLibre($tramoId),
        'Un tramo con unidades NO se puede borrar'
    );

    comprobarIgual(
        1,
        (int) $db->valor('SELECT COUNT(*) FROM tramos WHERE id = ?', [$tramoId]),
        'Y el tramo con unidades sigue en la tabla'
    );

    // ---- La fila vacia del formulario se ignora, y no bloquea el guardado --
    // Esto se probo de verdad, mandando el POST, porque el fallo era justo del
    // navegador: la fila de abajo llevaba required en la etiqueta, asi que
    // pulsar «Guardar» sin tocarla no dejaba enviar nada. Y una fila a medias
    // se explica, pero no se guarda.
    $enviar = static function (array $campos) use ($id): string {
        enviarFormulario(['campo' => $campos], '/admin/campanas/' . $id . '/formulario');

        return htmlDeAccion('ControladorFormulario', 'guardarFormulario', ['id' => $id]);
    };

    $filaVacia = [
        'clave' => '', 'etiqueta' => '', 'tipo' => 'texto',
        'min_largo' => '0', 'max_largo' => '255',
    ];

    $html = $enviar([
        ['clave' => 'nombre', 'etiqueta' => 'Nombre y apellidos', 'tipo' => 'texto', 'obligatorio' => '1', 'visible' => '1', 'min_largo' => '0', 'max_largo' => '255'],
        ['clave' => 'dni', 'etiqueta' => 'DNI', 'tipo' => 'texto', 'obligatorio' => '1', 'visible' => '1', 'min_largo' => '0', 'max_largo' => '255'],
        $filaVacia,
    ]);

    limpiarPeticion();

    // claves() viene ordenado por clave, que es lo que necesita quien solo
    // quiere saber si existe una. El orden en el que se preguntan los campos
    // es otra cosa, y va en listarPorPromocion(), que es lo que lee la pantalla.
    $campos = new \App\Models\CampoFormulario();
    comprobar(
        $campos->claves($id) === ['dni', 'nombre'],
        'La fila de abajo en blanco NO se guarda como un campo mas',
        'guardados: ' . json_encode($campos->claves($id), JSON_UNESCAPED_UNICODE)
    );

    comprobar(
        array_map(
            static fn (array $c): string => (string) $c['clave'],
            $campos->listarPorPromocion($id)
        ) === ['nombre', 'dni'],
        'Y los campos se guardan en el orden en que estaban en la pantalla'
    );

    comprobarContiene(
        (string) htmlDeAccion('ControladorFormulario', 'formulario', ['id' => $id]),
        'Nombre y apellidos',
        'Y los campos escritos siguen en la pantalla'
    );

    // ---- Una fila a medias se explica, pero no se guarda ------------------
    $html = $enviar([
        ['clave' => 'nombre', 'etiqueta' => 'Nombre y apellidos', 'tipo' => 'texto', 'obligatorio' => '1', 'visible' => '1', 'min_largo' => '0', 'max_largo' => '255'],
        ['clave' => 'dni', 'etiqueta' => 'DNI', 'tipo' => 'texto', 'obligatorio' => '1', 'visible' => '1', 'min_largo' => '0', 'max_largo' => '255'],
        ['clave' => 'sin_etiqueta', 'etiqueta' => '', 'tipo' => 'texto', 'min_largo' => '0', 'max_largo' => '255'],
    ]);

    limpiarPeticion();

    comprobarContiene($html, 'no tiene etiqueta', 'Una fila con clave pero sin etiqueta se explica en la pantalla');

    comprobar(
        $campos->claves($id) === ['dni', 'nombre'],
        'Y esa fila a medias no llega a la base de datos',
        'guardados: ' . json_encode($campos->claves($id), JSON_UNESCAPED_UNICODE)
    );

    borrarEscenarioDePanel($id);
}

/**
 * Caso 11: las reglas de verdad, con la implementacion que las mira.
 *
 * El caso 6 prueba el motor con un validador de mentira, que rechaza siempre o
 * acepta siempre. Eso demuestra que el motor respeta el contrato, pero no que el
 * contrato se cumpla: no prueba que una persona con el DNI repetido sea
 * rechazada de verdad, ni que un codigo que no esta en la lista llegue a la cola.
 * Este caso mete la implementacion real, \App\Services\ReglasCampana, y comprueba
 * las reglas de duplicado del apartado 4.7 por separado y combinadas.
 *
 * La combinacion es la parte importante, y es la que obliga a pensar despues: con
 * una sola columna clave_unicidad, «una por campana» y «una por dia» no se pueden
 * resolver con una unica comparacion. La huella canonica cubre la regla de
 * campana por el indice unico, y la de dia se comprueba aparte sobre el valor
 * guardado en datos. Este caso verifica que las dos funcionan juntas.
 *
 * @return void
 */
function caso11(): void
{
    echo 'Caso 11: las reglas de verdad rechazan lo que deben', PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    $escenario = crearEscenarioDeAdjudicacion(['10:00:00', '10:30:00', '11:00:00', '11:30:00']);
    $promocion = $escenario['promocion'];

    // Sin reglas, todo pasa. Es el caso de la campana que no ha configurado nada,
    // y es el que hace que el resto de las comprobaciones signifiquen algo: si el
    // validador real rechazara sin motivo, las de abajo no distinguirian un
    // rechazo correcto de uno inventado.
    $primera = (new \App\Services\Adjudicador(new \App\Services\ReglasCampana()))->registrar(
        $promocion,
        claveDePrueba('caso11-sin-reglas'),
        $escenario['tramo'],
        ['dni' => '11111111A'],
        null,
        null,
        instanteDeHoy('11:00:00')
    );
    comprobarIgual('premio', $primera['resultado'], 'Una campana sin reglas acepta la participacion');

    // ---- Una por campana, resuelta por el indice unico ---------------------
    guardarReglas($promocion, [
        'una_por_campana' => 1,
        'campo_identidad' => 'dni',
    ]);

    $motor = new \App\Services\Adjudicador(new \App\Services\ReglasCampana());
    $ambito = \App\Services\Huella::ambitoCampana($promocion);

    $otra = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-otra-persona'),
        $escenario['tramo'],
        ['dni' => '22222222B'],
        \App\Services\Huella::de($ambito, '22222222B'),
        null,
        instanteDeHoy('11:00:00')
    );
    comprobarIgual('premio', $otra['resultado'], 'Con una por campana, otra persona si puede participar');

    $repetida = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-mismo-dni'),
        $escenario['tramo'],
        ['dni' => '22222222B'],
        \App\Services\Huella::de($ambito, '22222222B'),
        null,
        instanteDeHoy('11:00:00')
    );
    comprobarIgual('rechazada', $repetida['resultado'], 'Una por campana rechaza el DNI repetido');
    comprobarIgual('duplicado', $repetida['motivo_codigo'], 'El rechazo por duplicado lleva su codigo');

    // El mismo DNI con espacios y en minusculas tiene que contar como el mismo.
    // Es la normalizacion de la decision D3, y es la que evita que el indice
    // unico se esquive con un tecleo.
    $conEspacios = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-espacios'),
        $escenario['tramo'],
        ['dni' => ' 2222 2222 b '],
        \App\Services\Huella::de($ambito, '22222222B'),
        null,
        instanteDeHoy('11:00:00')
    );
    comprobarIgual(
        'rechazada',
        $conEspacios['resultado'],
        'Un DNI repetido con espacios y minusculas sigue siendo el mismo'
    );

    // ---- Una por dia, comprobada aparte de la de campana -------------------
    // Aqui esta la combinacion que obliga al reparto de la huella canonica: la
    // regla de dia se cumple con su propia comprobacion sobre datos, y no con el
    // indice unico. Se usa una campana nueva porque en la anterior el DNI 2222 ya
    // esta bloqueado por la regla de campana, y asi no se podria distinguir un
    // rechazo por dia de uno por campana.
    borrarEscenarioDeAdjudicacion();
    $escenario = crearEscenarioDeAdjudicacion(['12:00:00', '12:30:00']);
    $promocion = $escenario['promocion'];

    guardarReglas($promocion, [
        'una_por_dia'     => 1,
        'campo_identidad' => 'dni',
    ]);

    $motor = new \App\Services\Adjudicador(new \App\Services\ReglasCampana());
    $ambitoDia = \App\Services\Huella::ambitoDia($promocion, date('Y-m-d'));

    $hoy = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-dia-1'),
        $escenario['tramo'],
        ['dni' => '33333333C'],
        \App\Services\Huella::de($ambitoDia, '33333333C'),
        null,
        instanteDeHoy('12:00:00')
    );
    comprobarIgual('premio', $hoy['resultado'], 'Una por dia acepta la primera participacion del dia');

    $repetidoHoy = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-dia-2'),
        $escenario['tramo'],
        ['dni' => '33333333C'],
        \App\Services\Huella::de($ambitoDia, '33333333C'),
        null,
        instanteDeHoy('12:30:00')
    );
    comprobarIgual('rechazada', $repetidoHoy['resultado'], 'Una por dia rechaza el segundo intento del mismo dia');

    // ---- La lista de codigos, que es una regla ------------------------------
    // Las unidades se crean antes que los intentos a proposito: una unidad solo
    // se puede adjudicar cuando ya ha pasado su hora, y aqui lo que se prueba es
    // la regla, no la cola. Sin esto, el segundo intento_valido se quedaria sin
    // premio por falta de unidades y el fallo pareceria un problema de reglas.
    borrarEscenarioDeAdjudicacion();
    $escenario = crearEscenarioDeAdjudicacion(['12:00:00', '12:10:00', '12:20:00']);
    $promocion = $escenario['promocion'];

    guardarReglas($promocion, [
        'exigir_codigo' => 1,
    ]);

    // Sin lista cargada, la regla solo obliga a que el codigo no venga en blanco.
    // Es el uso legitimo de «exigir codigo» cuando el supermercado no ha
    // precargado ningun cupon, y por eso no puede rechazarse solo por no estar
    // en una lista que no existe.
    $sinLista = (new \App\Services\Adjudicador(new \App\Services\ReglasCampana()))->registrar(
        $promocion,
        claveDePrueba('caso11-codigo-sin-lista'),
        $escenario['tramo'],
        ['codigo_participacion' => 'CUALQUIER-CODIGO'],
        null,
        null,
        instanteDeHoy('12:30:00')
    );
    comprobarIgual(
        'premio',
        $sinLista['resultado'],
        'Un codigo cualquiera pasa cuando la campana no ha cargado ninguna lista'
    );

    (new \App\Models\CodigoValido())->anadir(
        $promocion,
        \App\Models\CodigoValido::TIPO_CODIGO,
        ['CODE-1']
    );

    $motor = new \App\Services\Adjudicador(new \App\Services\ReglasCampana());

    $sinCodigo = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-sin-codigo'),
        $escenario['tramo'],
        ['nombre' => 'Ana'],
        null,
        null,
        instanteDeHoy('12:30:00')
    );
    comprobarIgual('rechazada', $sinCodigo['resultado'], 'Si la campana exige codigo, sin codigo se rechaza');
    comprobarIgual('codigo_invalido', $sinCodigo['motivo_codigo'], 'El rechazo por codigo lleva su codigo');

    $codigoFalso = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-codigo-falso'),
        $escenario['tramo'],
        ['codigo_participacion' => 'CODE-999'],
        null,
        null,
        instanteDeHoy('12:30:00')
    );
    comprobarIgual(
        'rechazada',
        $codigoFalso['resultado'],
        'Con lista cargada, un codigo que no esta en ella se rechaza'
    );

    $codigoBueno = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-codigo-bueno'),
        $escenario['tramo'],
        ['codigo_participacion' => 'code 1'],
        null,
        null,
        instanteDeHoy('12:30:00')
    );
    comprobarIgual(
        'premio',
        $codigoBueno['resultado'],
        'Un codigo de la lista pasa, y con espacios porque la lista se normaliza igual'
    );

    // ---- La lista de tickets, que es otra regla distinta --------------------
    borrarEscenarioDeAdjudicacion();
    $escenario = crearEscenarioDeAdjudicacion(['13:00:00', '13:10:00', '13:20:00']);
    $promocion = $escenario['promocion'];

    guardarReglas($promocion, [
        'verificar_ticket' => 1,
    ]);

    (new \App\Models\CodigoValido())->anadir(
        $promocion,
        \App\Models\CodigoValido::TIPO_TICKET,
        ['TICKET-1']
    );

    $motor = new \App\Services\Adjudicador(new \App\Services\ReglasCampana());

    $ticketFalso = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-ticket-falso'),
        $escenario['tramo'],
        ['num_ticket' => 'TICKET-999'],
        null,
        null,
        instanteDeHoy('13:30:00')
    );
    comprobarIgual('rechazada', $ticketFalso['resultado'], 'Un ticket que no esta en la lista se rechaza');
    comprobarIgual(
        'ticket_no_verificado',
        $ticketFalso['motivo_codigo'],
        'El rechazo por ticket lleva su propio codigo, distinto del de codigo'
    );

    $ticketBueno = $motor->registrar(
        $promocion,
        claveDePrueba('caso11-ticket-bueno'),
        $escenario['tramo'],
        ['num_ticket' => 'ticket 1'],
        null,
        null,
        instanteDeHoy('13:30:00')
    );
    comprobarIgual(
        'premio',
        $ticketBueno['resultado'],
        'Un ticket de la lista pasa, y con espacios porque la lista se normaliza igual'
    );

    // ---- El texto del rechazo sale de la campana, no del codigo -------------
    guardarReglas($promocion, [
        'verificar_ticket'      => 1,
        'texto_rechazo_codigo'  => 'Este cupon no es de esta promocion.',
    ]);

    $propio = (new \App\Services\Adjudicador(new \App\Services\ReglasCampana()))->registrar(
        $promocion,
        claveDePrueba('caso11-texto'),
        $escenario['tramo'],
        ['num_ticket' => 'TICKET-404'],
        null,
        null,
        instanteDeHoy('13:30:00')
    );
    comprobarIgual(
        'Este cupon no es de esta promocion.',
        $propio['motivo_texto'],
        'El texto que ve la clienta es el que ha escrito la campana'
    );


    // ---- El ambito de la huella, y por que el motor no lo decide solo --------
    // Aqui importa una cosa que no se ve en el resto del caso: la huella NO la
    // calcula el motor. La calcula la pantalla de participacion y se la pasa como
    // dato, y el motor se limita a guardarla y a compararla. Por eso estas
    // comprobaciones la calculan igual que lo hace la pantalla, con el mismo
    // servicio, en vez de pasar null. Si se pasara null, estas reglas no se
    // estarian probando: estarian probando solo la mitad de la comprobacion que
    // hace la base de datos, y la otra mitad, la que elige el ambito, se
    // quedaria sin mirar.
    $registrar = static function (string $clave, array $datos, string $momento) use ($promocion, $escenario): array {
        $reglas = (new \App\Models\ReglaParticipacion())->leer($promocion);
        $huella = (new \App\Services\IdentidadCampana())
            ->huellaCanonica($promocion, $reglas, $datos, $momento);

        return (new \App\Services\Adjudicador(new \App\Services\ReglasCampana()))->registrar(
            $promocion,
            $clave,
            $escenario['tramo'],
            $datos,
            $huella,
            null,
            $momento
        );
    };

    // Lo que se comprueba aqui es si la participacion se ACEPTA o se RECHAZA, no
    // si lleva premio. A estas alturas del caso la cola de premios ya esta
    // vacia, y una participacion aceptada sin premio es una participacion
    // aceptada igual. Comprobar el premio aqui haria depender estas reglas de
    // cuantas unidades se han gastado antes, y la prueba pasaria o fallaria
    // segun el orden en que se ejecuten los casos.
    $aceptada = static function (array $resultado, string $porque): void {
        comprobar(
            $resultado['resultado'] !== 'rechazada',
            $porque,
            'ha salido ' . (string) $resultado['resultado'] . ' por '
                . (string) ($resultado['motivo_codigo'] ?? 'motivo desconocido')
        );
    };

    // ---- Con una por dia, al dia siguiente se vuelve a poder participar ------
    // Este es el caso que mas daño hacia antes. Si la huella guardada llevara el
    // ambito de campana en lugar del de dia, el indice unico veria la misma
    // huella al dia siguiente y rechazaria a todo el mundo para siempre, y el
    // rechazo apareceria un dia tarde, cuando ya nadie relaciona el segundo dia
    // con el primer rechazo. Nada habria dado error en ningun momento.
    guardarReglas($promocion, ['una_por_dia' => 1]);

    $primera = $registrar(
        claveDePrueba('caso11-dia-uno'),
        ['dni' => '33333333C', 'nombre' => 'Marta'],
        instanteDeHoy('13:30:00')
    );
    $aceptada($primera, 'El primer dia la participacion se acepta');

    $repetida = $registrar(
        claveDePrueba('caso11-dia-uno-otra-vez'),
        ['dni' => '33333333C', 'nombre' => 'Marta otra vez'],
        instanteDeHoy('13:31:00')
    );
    comprobarIgual(
        'rechazada',
        $repetida['resultado'],
        'El mismo dia, la segunda vez, se rechaza'
    );

    $manana = (new DateTimeImmutable(instanteDeHoy('13:30:00')))
        ->modify('+1 day')
        ->format('Y-m-d H:i:s');

    $alDiaSiguiente = $registrar(
        claveDePrueba('caso11-dia-dos'),
        ['dni' => '33333333C', 'nombre' => 'Marta al dia siguiente'],
        $manana
    );
    $aceptada($alDiaSiguiente, 'Con una por dia, al dia siguiente vuelve a poder participar');

    // Y las dos huellas tienen que ser distintas. Sin esta comprobacion, lo
    // anterior podria estar pasando por un motivo equivocado: si la huella del
    // dia siguiente no se guardara en absoluto, el indice no chocaria con nada y
    // la participacion pasaria por el motivo equivocado, que es el que no
    // cumple la regla de una por dia para el mismo dia.
    $huellas = \App\Core\Aplicacion::db()->todos(
        'SELECT clave_idempotencia, clave_unicidad FROM participaciones
          WHERE promocion_id = ? AND clave_idempotencia IN (?, ?)',
        [$promocion, claveDePrueba('caso11-dia-uno'), claveDePrueba('caso11-dia-dos')]
    );
    $porDia = [];
    foreach ($huellas as $fila) {
        $porDia[(string) $fila['clave_idempotencia']] = (string) $fila['clave_unicidad'];
    }

    comprobarIgual(2, count($porDia), 'Las dos participaciones de Marta se han guardado');
    comprobar(
        $porDia[claveDePrueba('caso11-dia-uno')] !== ''
            && $porDia[claveDePrueba('caso11-dia-uno')]
                !== $porDia[claveDePrueba('caso11-dia-dos')],
        'La huella de un dia y la de al siguiente no coinciden'
    );

    // ---- Una por ticket: la huella es del ticket, no de la persona ---------
    guardarReglas($promocion, ['una_por_ticket' => 1]);

    $aceptada(
        $registrar(
            claveDePrueba('caso11-ticket-nuevo'),
            ['dni' => '44444444D', 'num_ticket' => 'TICKET-9'],
            instanteDeHoy('13:30:00')
        ),
        'Un ticket nuevo pasa'
    );

    $aceptada(
        $registrar(
            claveDePrueba('caso11-otro-ticket'),
            ['dni' => '44444444D', 'num_ticket' => 'TICKET-10'],
            instanteDeHoy('13:30:00')
        ),
        'Con una por ticket, la misma persona con otro ticket si puede participar'
    );

    comprobarIgual(
        'rechazada',
        $registrar(
            claveDePrueba('caso11-ticket-repetido'),
            ['dni' => '55555555E', 'num_ticket' => 'ticket-9'],
            instanteDeHoy('13:30:00')
        )['resultado'],
        'Un ticket ya usado se rechaza, aunque lo escriba otra persona y con otros guiones'
    );

    // ---- Una por DNI se comprueba sobre el DNI, no sobre el campo elegido --
    // Aqui el campo de identidad de la campana es el correo, a proposito. Si la
    // regla compartiera la huella con el resto, la huella seria del correo, y dos
    // personas con el mismo DNI y distinto correo no chocarian nunca. La regla
    // no se estaria cumpliendo y nada pareceria roto.
    guardarReglas($promocion, ['una_por_dni' => 1, 'campo_identidad' => 'email']);

    $aceptada(
        $registrar(
            claveDePrueba('caso11-dni-uno'),
            ['dni' => '66666666F', 'email' => 'uno@example.com'],
            instanteDeHoy('13:30:00')
        ),
        'El primer DNI pasa'
    );

    comprobarIgual(
        'rechazada',
        $registrar(
            claveDePrueba('caso11-dni-otro-correo'),
            ['dni' => '66666666F', 'email' => 'otro@example.com'],
            instanteDeHoy('13:30:00')
        )['resultado'],
        'El mismo DNI con otro correo se rechaza, porque la regla es por DNI'
    );

    // ---- Sin reglas de duplicado no se guarda huella -------------------------
    // Guardando la huella de la cadena vacia, que es siempre la misma, el indice
    // unico rechazaria a la segunda participacion de cualquiera. Es el fallo que
    // hace que una campana sin reglas parezca tener una regla.
    guardarReglas($promocion, []);

    $libre = $registrar(
        claveDePrueba('caso11-sin-reglas'),
        ['dni' => '77777777G'],
        instanteDeHoy('13:30:00')
    );
    $aceptada($libre, 'Sin reglas, la participacion se acepta');

    comprobarIgual(
        '',
        (string) \App\Core\Aplicacion::db()->valor(
            'SELECT clave_unicidad FROM participaciones WHERE promocion_id = ? AND clave_idempotencia = ?',
            [$promocion, claveDePrueba('caso11-sin-reglas')]
        ) ?? '',
        'Una campana sin reglas de duplicado no guarda ninguna huella'
    );

    // ---- Una por campana manda sobre las demas ------------------------------
    // Con las dos reglas puestas, la mas restrictiva vigila la huella y la otra se
    // comprueba encima. Al dia siguiente sigue habiendo rechazo, y el motivo es
    // el de una por campana, que es el que no va a cambiar con el paso del
    // tiempo. Si mandara la de dia, esta comprobacion no tendria sentido.
    guardarReglas($promocion, ['una_por_campana' => 1, 'una_por_dia' => 1]);

    $aceptada(
        $registrar(
            claveDePrueba('caso11-combinada'),
            ['dni' => '88888888H'],
            instanteDeHoy('13:30:00')
        ),
        'Con una por campana y una por dia, la primera pasa'
    );

    comprobarIgual(
        'rechazada',
        $registrar(
            claveDePrueba('caso11-combinada-otro-dia'),
            ['dni' => '88888888H'],
            $manana
        )['resultado'],
        'Con una por campana y una por dia, al dia siguiente tambien se rechaza'
    );

    borrarEscenarioDeAdjudicacion();
}

/**
 * Doble de transporte de correo que falla a proposito, para el caso 12.
 *
 * Existe por el caso de aceptacion 9, que pide simular un fallo de envio. No se
 * puede provocar con un buzon de verdad sin romper algo: o se manda el correo, o
 * hay que esperar a que el servidor caiga, y una prueba que depende de cuando
 * se rompe el servidor no es una prueba, es una apuesta. Con este doble, el
 * fallo ocurre porque la prueba lo dice, y siempre en el momento exacto.
 *
 * Tambien guarda los mensajes que se le han pasado, para poder comprobar que el
 * asunto y el cuerpo que salen son los que escribio la campana. Con el
 * transporte de verdad no habria forma de verlo sin mandarselo a alguien.
 */
final class MailerQueFalla implements \App\Services\Mailer
{
    /** @var array<int, array<string, string>> Mensajes que se han intentado enviar. */
    public array $vistos = [];

    /** @var string Motivo que se devuelve como error. */
    private string $motivo;

    /**
     * @param string $motivo Texto que se dira que ha fallado.
     */
    public function __construct(string $motivo = 'el servidor de correo no contesta')
    {
        $this->motivo = $motivo;
    }

    /**
     * Anota el mensaje y falla.
     *
     * @param string                $destinatario Direccion de correo.
     * @param string                $asunto       Asunto ya sustituido.
     * @param string                $cuerpo       Cuerpo ya sustituido.
     * @param array<string, string> $cabeceras    Cabeceras adicionales.
     *
     * @return bool Siempre false.
     */
    public function enviar(
        string $destinatario,
        string $asunto,
        string $cuerpo,
        array $cabeceras = []
    ): bool {
        $this->vistos[] = [
            'destinatario' => $destinatario,
            'asunto' => $asunto,
            'cuerpo' => $cuerpo,
        ];

        return false;
    }

    /**
     * @return string Siempre «log», para no cambiar el transporte guardado.
     */
    public function nombre(): string
    {
        return 'log';
    }

    /**
     * @return string Motivo del fallo.
     */
    public function ultimoError(): string
    {
        return $this->motivo;
    }
}

/**
 * El hilo 5: la cola de correo se vacia, se reintenta y no manda dos veces.
 *
 * @return void
 */
function caso12(): void
{
    echo 'Caso 12: el processor de correo vacia la cola sin mandar dos veces', PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    $escenario = crearEscenarioDeAdjudicacion(
        ['10:00:00', '10:30:00', '11:00:00', '14:00:00'],
        ['correo' => true]
    );
    $promocion = (int) $escenario['promocion'];

    $correos = new \App\Models\Correo();
    $motor = new \App\Services\Adjudicador(new \App\Services\ReglasCampana());

    // ---- Una participacion con premio encola, y no envia --------------------
    // D1: el mensaje se encola dentro de la transaccion y se envia despues. La
    // comprobacion de que al adjudicar no se ha enviado nada es lo que separa
    // «encolar» de «mandar», y es la que evita que la azafata espere a que el
    // correo salga antes de ver el resultado.
    $ganadora = $motor->registrar(
        $promocion,
        claveDePrueba('caso12-ganadora'),
        $escenario['tramo'],
        ['nombre' => 'Ana', 'email' => 'ana@example.com', 'dni' => '11111111A'],
        null,
        null,
        instanteDeHoy('10:00:00')
    );
    comprobarIgual('premio', $ganadora['resultado'], 'La primera participacion se lleva el premio');

    $estados = $correos->contarPorEstado($promocion);
    comprobarIgual(1, $estados['pendiente'] ?? 0, 'Adjudicar encola el mensaje y no lo envia');
    comprobar(
        !isset($estados['enviado']),
        'En este momento no hay ningun mensaje marcado como enviado'
    );

    // ---- La primera pasada envia y marca ------------------------------------
    $recuento = (new \App\Services\ProcesadorCorreo())->procesar();

    comprobarIgual(1, $recuento['enviados'], 'La primera pasada envia el mensaje pendiente');
    comprobarIgual(0, $recuento['fallidos'], 'La primera pasada no tiene fallos');
    comprobarIgual(0, $recuento['omitidos'], 'La primera pasada no omite nada');

    $estados = $correos->contarPorEstado($promocion);
    comprobarIgual(1, $estados['enviado'] ?? 0, 'El mensaje queda como enviado');

    $fila = \App\Core\Aplicacion::db()->uno(
        'SELECT estado, transporte, enviado_en, intentos, ultimo_error
           FROM correos WHERE promocion_id = ? ORDER BY id DESC LIMIT 1',
        [$promocion]
    );
    comprobarIgual('log', (string) $fila['transporte'], 'Se recuerda por que transporte salio');
    comprobar(
        $fila['enviado_en'] !== null,
        'Un mensaje enviado lleva la hora a la que salio'
    );
    comprobarIgual(1, (int) $fila['intentos'], 'Un envio necesita un solo intento');
    comprobar(
        $fila['ultimo_error'] === null,
        'Un mensaje enviado no guarda error ninguno'
    );

    // ---- La segunda pasada no vuelve a mandar -------------------------------
    // Esta es la comprobacion que mas importa del caso. Adjudicar no es lo mismo
    // que enviar, y si el processor no aparta el mensaje al mandarlo, la segunda
    // vez que pase se mandaria otra vez y la clienta recibiria dos correos de su
    // mismo premio.
    $otra = (new \App\Services\ProcesadorCorreo())->procesar();
    comprobarIgual(0, $otra['vistos'], 'La segunda pasada no ve nada pendiente');
    comprobarIgual(0, $otra['enviados'], 'La segunda pasada no manda nada');
    comprobarIgual(
        1,
        $correos->contarPorEstado($promocion)['enviado'] ?? 0,
        'Sigue habiendo un unico mensaje enviado, no dos'
    );

    // ---- Una participacion sin premio tambien encola, si la campana manda --
    // A las 09:00 todavia no hay ninguna unidad programada, que es el caso 3: se
    // registra la participacion, no se toca la cola, y aun asi se encola el
    // correo de «gracias por participar».
    $perdedora = $motor->registrar(
        $promocion,
        claveDePrueba('caso12-perdedora'),
        $escenario['tramo'],
        ['nombre' => 'Luis', 'email' => 'luis@example.com', 'dni' => '22222222B'],
        null,
        null,
        instanteDeHoy('09:00:00')
    );
    comprobarIgual('sin_premio', $perdedora['resultado'], 'La segunda participacion no lleva premio');
    comprobarIgual(
        1,
        $correos->contarPorEstado($promocion)['pendiente'] ?? 0,
        'La participacion sin premio tambien encola su correo'
    );

    // ---- Un fallo de envio deja el mensaje en error, con su motivo ---------
    // Caso de aceptacion 9. Y sobre todo: la adjudicacion NO se toca. Un correo
    // que no sale no puede convertir un premio entregado en una participacion sin
    // premio, ni devolver la unidad a la cola.
    $fallido = new MailerQueFalla('550 la direccion no existe');
    $recuento = (new \App\Services\ProcesadorCorreo(null, $fallido))->procesar();

    comprobarIgual(1, $recuento['fallidos'], 'El mensaje que falla se cuenta como fallido');
    comprobarIgual(0, $recuento['enviados'], 'No se cuenta como enviado el que ha fallado');

    $fila = \App\Core\Aplicacion::db()->uno(
        'SELECT estado, ultimo_error, bloqueado_hasta, intentos
           FROM correos WHERE promocion_id = ? ORDER BY id DESC LIMIT 1',
        [$promocion]
    );
    comprobarIgual('error', (string) $fila['estado'], 'El mensaje que falla queda en estado de error');
    comprobar(
        str_contains((string) $fila['ultimo_error'], '550'),
        'Se guarda el motivo real del fallo, no un texto generico',
        'ultimo_error vale: ' . (string) $fila['ultimo_error']
    );
    comprobar(
        $fila['bloqueado_hasta'] !== null,
        'Un mensaje fallido se bloquea un tiempo para no reintentarse en bucle'
    );

    // La fila de la participacion es la que dice si la adjudicacion se ha
    // tocado. Se lee de la tabla y no del valor que devolvio el motor, porque
    // aquel era de antes del fallo de correo y no probaria nada.
    $guardada = \App\Core\Aplicacion::db()->uno(
        'SELECT resultado FROM participaciones
          WHERE promocion_id = ? AND clave_idempotencia = ?',
        [$promocion, claveDePrueba('caso12-perdedora')]
    );
    comprobarIgual(
        'sin_premio',
        (string) $guardada['resultado'],
        'La adjudicacion sigue siendo valida despues de un fallo de correo'
    );

    // Y la cola de premios sigue como estaba: sigue habiendo exactamente una
    // unidad entregada, la de Ana. Un fallo de correo no puede devolverla ni
    // entregar otra, porque el correo va por detras de la adjudicacion.
    comprobarIgual(
        1,
        (int) \App\Core\Aplicacion::db()->valor(
            "SELECT COUNT(*) FROM unidades_premio
              WHERE promocion_id = ? AND estado = 'entregada'",
            [$promocion]
        ),
        'El fallo de correo no devuelve ni entrega ninguna unidad'
    );

    comprobarIgual(
        0,
        (new \App\Services\ProcesadorCorreo())->procesar()['vistos'],
        'Un mensaje bloqueado no se vuelve a intentar en la siguiente pasada'
    );

    // ---- El limite se respeta ----------------------------------------------
    // Sin esto, una campana con dos mil mensajes encolados vaciaria la cola
    // entera en una pasada, sin pausa para nadie, y en la pantalla de la azafata
    // no habria diferencia porque quien lo llama es un proceso aparte.
    $db = \App\Core\Aplicacion::db();
    $db->ejecutar(
        "UPDATE correos SET estado = 'pendiente', bloqueado_hasta = NULL, intentos = 0
          WHERE promocion_id = ? AND estado <> 'pendiente'",
        [$promocion]
    );
    comprobarIgual(
        1,
        (new \App\Services\ProcesadorCorreo())->procesar(1)['vistos'],
        'Con limite 1 solo se ve un mensaje, aunque haya dos en la cola'
    );
    comprobarIgual(
        1,
        $correos->contarPorEstado($promocion)['pendiente'] ?? 0,
        'Despues de la pasada con limite 1 queda un pendiente para la siguiente'
    );

    // ---- Un transporte desconocido cae en el log, no se pierde el mensaje ----
    $db->ejecutar(
        "UPDATE correos SET transporte = 'inventado' WHERE promocion_id = ?",
        [$promocion]
    );
    comprobarIgual(
        1,
        (new \App\Services\ProcesadorCorreo())->procesar()['enviados'],
        'Un mensaje con transporte desconocido se envia igualmente, por el log'
    );

    // ---- El transporte SMTP habla el protocolo de verdad --------------------
    // Todo lo anterior ha usado el transporte «log», que no falla nunca. Eso no
    // prueba que el cliente SMTP funciona: solo que se guarda el texto. Aqui se
    // levanta un servidor SMTP falso en otro proceso y se le manda un mensaje de
    // verdad, que es la unica forma de saber que el EHLO, el MAIL FROM y el
    // punto final se escriben donde toca. Un unit test sobre el texto montado
    // pasaria con el protocolo entero mal.
    $transcripcion = sys_get_temp_dir() . '/sorteos_smtp_'
        . str_replace('-', '', claveDePrueba('caso12')) . '.txt';

    if (is_file($transcripcion)) {
        @unlink($transcripcion);
    }

    // El puerto 0 deja que el sistema elija uno libre. Probar uno fijo seria
    // pedir que la prueba falle el dia que otro proceso lo tenga cogido.
    $mandosSmtp = proc_open(
        [PHP_BINARY, __DIR__ . '/_smtp_falso.php', $transcripcion, '0'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $tuberiasSmtp
    );

    comprobar(
        is_resource($mandosSmtp),
        'Se ha podido levantar el servidor SMTP falso'
    );

    if (is_resource($mandosSmtp)) {
        $puerto = 0;
        $espera = microtime(true) + 10;

        // Se espera a que el servidor diga por que puerto esta. Se lee del
        // fichero y no se prueba a lo bruto, porque si se conecta antes de que
        // el hijo haya abierto el socket el fallo es «conexion rechazada» y no se
        // distingue de un fallo de SMTP de verdad.
        while (microtime(true) < $espera) {
            clearstatcache(true, $transcripcion);
            $contenido = is_file($transcripcion)
                ? (string) file_get_contents($transcripcion)
                : '';

            if (preg_match('/PUERTO (\d+)/', $contenido, $encontrado) === 1) {
                $puerto = (int) $encontrado[1];
                break;
            }

            usleep(50_000);
        }

        comprobar($puerto > 0, 'El servidor SMTP falso ha dicho su puerto');

        if ($puerto > 0) {
            $smtp = new \App\Services\MailerSmtp('sorteos@supermercado.local');

            // El transporte se construye con la configuracion de la instalacion,
            // y aqui se sustituyen los datos del servidor y el modo de seguridad
            // por los de esta prueba. El modo se pone a cadena vacia a proposito:
            // es el unico que no necesita TLS, y asi se comprueba tambien que el
            // cliente NO intenta STARTTLS aunque el servidor lo anuncie.
            $reflejo = new \ReflectionClass($smtp);
            $fijar = static function (string $propiedad, $valor) use ($reflejo, $smtp): void {
                $atributo = $reflejo->getProperty($propiedad);
                $atributo->setAccessible(true);
                $atributo->setValue($smtp, $valor);
            };

            $fijar('host', '127.0.0.1');
            $fijar('puerto', $puerto);
            $fijar('seguridad', '');
            $fijar('usuario', '');

            $enviado = $smtp->enviar(
                'luis@example.com',
                'Enhorabuena, Luis',
                "Primera linea.\n.Una linea que empieza por punto.\nUltima linea."
            );

            comprobar($enviado, 'El cliente SMTP da el mensaje por enviado');

            // proc_close() ya cierra las tuberias, asi que solo se cierran si
            // siguen abiertas. Sin esta comprobacion, fclose() avisaria de un
            // recurso que no es una tuberia y el caso terminaria con un fallo que
            // no tiene nada que ver con lo que se estaba probando.
            proc_close($mandosSmtp);

            foreach ([1, 2] as $conducto) {
                if (isset($tuberiasSmtp[$conducto]) && is_resource($tuberiasSmtp[$conducto])) {
                    fclose($tuberiasSmtp[$conducto]);
                }
            }

            $conversacion = is_file($transcripcion)
                ? (string) file_get_contents($transcripcion)
                : '';

            comprobarContiene(
                $conversacion,
                'EHLO',
                'El cliente se ha presentado con EHLO'
            );
            comprobarNoContiene(
                $conversacion,
                'AVISO el cliente ha usado HELO',
                'El cliente no ha usado HELO, que no permite ver las capacidades'
            );
            comprobarContiene(
                $conversacion,
                'MAIL FROM:<sorteos@supermercado.local>',
                'Ha salido el MAIL FROM con la direccion de la campana'
            );
            comprobarContiene(
                $conversacion,
                'RCPT TO:<luis@example.com>',
                'Ha salido el RCPT TO con el destinatario'
            );
            comprobarContiene(
                $conversacion,
                'QUIT',
                'El cliente se despide con QUIT en vez de soltar la conexion'
            );
            comprobarContiene(
                $conversacion,
                'Subject: Enhorabuena, Luis',
                'Ha llegado el asunto que escribio la campana'
            );
            comprobarContiene(
                $conversacion,
                'CUERPO Primera linea.',
                'Ha llegado el cuerpo del mensaje'
            );
            comprobarContiene(
                $conversacion,
                'CUERPO ..Una linea que empieza por punto.',
                'La linea que empieza por punto va escapada, para no cerrar el mensaje antes de tiempo'
            );
            comprobarContiene(
                $conversacion,
                'FIN',
                'La conversacion ha terminado sola, sin quedarse colgada'
            );

            @unlink($transcripcion);
        }
    }

    borrarEscenarioDeAdjudicacion();
}

/**
 * Cubre la pantalla de participacion de punta a punta, por HTTP y no por
 * servicios.
 *
 * Los casos anteriores llaman al motor directamente, y eso es lo correcto para
 * probar las reglas. Pero deja fuera una capa entera, que es la que de verdad usa
 * la azafata: el formulario, el POST y la pantalla de resultado. Esta capa tiene
 * tres cosas que los servicios no pueden ver:
 *
 *   1. Que el controlador y la vista encajen. La vista pide claves que el
 *      controlador tiene que pasarle. Si falta una, la pantalla sale a medias o
 *      con un aviso de variable no definida, y ningun servicio se entera porque
 *      los servicios no saben nada de vistas.
 *
 *   2. Que el POST lleve lo que el motor necesita y el motor no puede inventar:
 *      el token CSRF, el identificador de intento y, cuando la campana la pide,
 *      la casilla de consentimiento. Las tres viajan en campos que genera el
 *      propio formulario. Si el nombre de uno se cambia en la vista y no en el
 *      controlador, el formulario se manda entero y no pasa nada, en silencio.
 *      El caso 9 finding de este bloque es el consentimiento: la casilla la
 *      pinta la vista, no es un campo configurado, y al no pasar por la misma
 *      puerta que los demas se perdia por el camino. Toda campaign con
 *      «exigir consentimiento» habria rechazado a todo el mundo, marcando la
 *      casilla o sin ella.
 *
 *   3. Que la pantalla diga lo que tiene que decir y no lo contrario. En
 *      particular, que el codigo de reclamacion se ensene o no segun se mande o
 *      no el correo, porque si aparece en pantalla cuando ya va por correo se
 *      multiplican los sitios por los que se puede leer el codigo de alguien.
 *
 * El control de rol NO se prueba aqui, y es a proposito. Lo aplica el
 * enrutador, no el controlador, y en linea de comandos no hay sesion de
 * navegador, de modo que Autorizacion no ve ningun usuario y todo lo pasaria
 * como anonimo. Una prueba de rol aqui solo mediria que no hay sesion. El rol se
 * comprueba en los casos de acceso del hito 1, que es donde vive.
 *
 * @return void
 */
function caso13(): void
{
    // Cuatro premios y ningun correo activado: con el correo apagado el codigo de
    // reclamacion tiene que verse en pantalla, porque es la unica via que le
    // queda a la persona de recoger el premio. Ese es el estado de partida.
    //
    // Las horas de los premios salen del reloj y no se escriben a mano, y no es
    // mania: este caso va por la pantalla, asi que la participacion se registra con
    // la hora de verdad y el motor solo entrega premios cuya hora ya ha pasado. Con
    // las horas fijas -10:00, 11:00, 12:00 y 13:00- el caso solo pasaba a partir de
    // las 13:00, y a las 09:33 la participacion salia «sin premio» y cinco
    // comprobaciones caian sin que hubiera cambiado nada. Ver horasPasadasDeHoy().
    $escenario = crearEscenarioDeAdjudicacion(horasPasadasDeHoy(4));
    $promocion = $escenario['promocion'];

    (new \App\Models\CampoFormulario())->sustituirTodos($promocion, [
        [
            'clave' => 'dni', 'etiqueta' => 'DNI', 'tipo' => 'texto',
            'obligatorio' => true, 'visible' => true, 'orden' => 1,
            'valor_por_defecto' => '', 'min_largo' => 0, 'max_largo' => 255,
        ],
        [
            'clave' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'texto',
            'obligatorio' => true, 'visible' => true, 'orden' => 2,
            'valor_por_defecto' => '', 'min_largo' => 0, 'max_largo' => 255,
        ],
        // El correo se declara aqui aunque en la primera participacion no se
        // mande ningun correo, porque despues se enciende el envio de la campana
        // sin volver a tocar el formulario. Una campana que encola correo y no
        // tiene ningun campo de correo esta mal configurada, y el propio motor lo
        // avisa en vez de encolar un mensaje sin destinatario.
        [
            'clave' => 'correo', 'etiqueta' => 'Correo electronico', 'tipo' => 'email',
            'obligatorio' => true, 'visible' => true, 'orden' => 3,
            'valor_por_defecto' => '', 'min_largo' => 0, 'max_largo' => 255,
        ],
    ]);

    // La deduplicacion se enciende YA, antes de la primera participacion, y no
    // luego. Encenderla despues no sirve para probarla: las participaciones
    // anteriores se guardaron sin huella, porque sin reglas no hay nada que
    // guardar, y entonces la repetida no tiene contra que chocar y entra. El
    // rechazo se produciria por un motivo equivocado y la prueba diria que la
    // regla funciona cuando en realidad no estaba mirandola.
    guardarReglas($promocion, [
        'exigir_consentimiento'   => 1,
        'texto_consentimiento'    => 'He leido el aviso de privacidad de esta promocion.',
        'una_por_campana'         => 1,
        'texto_rechazo_duplicado' => 'Ya has participado en esta promocion.',
    ]);

    // ---- El formulario lleva lo que el POST va a necesitar -----------------
    $formulario = htmlDeAccion('ControladorParticipacion', 'formulario', ['id' => $promocion]);

    comprobarContiene(
        $formulario,
        'name="' . \App\Core\Csrf::CAMPO . '"',
        'El formulario lleva el campo de token CSRF'
    );
    comprobarContiene(
        $formulario,
        'name="idempotencia"',
        'El formulario lleva el identificador de intento, que es la garantia del doble clic'
    );
    comprobarContiene(
        $formulario,
        'name="consentimiento"',
        'La casilla de consentimiento se pinta cuando la campana la exige'
    );
    comprobarContiene(
        $formulario,
        'He leido el aviso de privacidad de esta promocion.',
        'El texto de consentimiento sale tal cual lo ha escrito la campana'
    );
    comprobarContiene(
        $formulario,
        'DNI',
        'Se ve el campo que la campana ha declarado obligatorio'
    );
    comprobarNoContiene(
        $formulario,
        'Notice:',
        'La pantalla del formulario sale sin avisos de PHP'
    );

    // El identificador de intento tiene que ser un UUID de verdad, no una cadena
    // cualquiera: el motor lo exige con esa forma y rechazaria el POST entero si
    // no la tuviera, con un error que no explica nada util a la azafata.
    comprobar(
        preg_match('/name="idempotencia" value="[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"/', $formulario) === 1,
        'El identificador de intento que viaja en el formulario tiene forma de UUID'
    );

    // ---- Mandar el formulario y ver el resultado ----------------------------
    limpiarPeticion();
    enviarFormulario(
        [
            'idempotencia'  => claveDePrueba('caso13-ganadora'),
            'dni'           => '12345678Z',
            'nombre'        => 'Elena',
            'correo'        => 'elena@example.com',
            'consentimiento' => 'on',
        ],
        '/azafata/promociones/' . $promocion . '/participar'
    );

    $resultado = htmlDeAccion('ControladorParticipacion', 'registrar', ['id' => $promocion]);

    comprobarNoContiene(
        $resultado,
        'Notice:',
        'La pantalla de resultado sale sin avisos de PHP'
    );
    comprobarContiene(
        $resultado,
        'Enhorabuena',
        'Una participacion valida lleva a la pantalla de premio, no a un error'
    );

    // Con el correo apagado, el codigo se ve. Y no se comprueba solo que aparezca
    // la caja: se compara con el que se guardo, porque un codigo que se enseña
    // pero no es el de la participacion es peor que no enseñar ninguno. El codigo
    // vive en la unidad adjudicada y no en la participacion, porque es la unidad
    // entregada la que hay que recoger en el mostrador.
    $codigoGuardado = (string) \App\Core\Aplicacion::db()->valor(
        'SELECT u.codigo_reclamacion FROM unidades_premio u
           JOIN participaciones p ON p.id = u.participacion_id
          WHERE p.promocion_id = ? AND p.clave_idempotencia = ?',
        [$promocion, claveDePrueba('caso13-ganadora')]
    );

    comprobar($codigoGuardado !== '', 'La participacion ganadora tiene codigo de reclamacion');
    comprobarContiene(
        $resultado,
        $codigoGuardado,
        'En pantalla sale el codigo que de verdad se ha guardado, no otro'
    );

    // ---- Con el correo activado, el codigo desaparece de la pantalla ---------
    // Se enciende el correo de la campana y se manda otra participacion con
    // otra persona. El codigo ya no se enseña, porque va a llegar por correo, y
    // la pantalla se queda en el texto de la campana.
    \App\Core\Aplicacion::db()->ejecutar(
        'UPDATE promociones SET correo_ganador = 1, correo_no_ganador = 1 WHERE id = ?',
        [$promocion]
    );

    limpiarPeticion();
    enviarFormulario(
        [
            'idempotencia'  => claveDePrueba('caso13-con-correo'),
            'dni'           => '87654321X',
            'nombre'        => 'Raul',
            'correo'        => 'raul@example.com',
            'consentimiento' => 'on',
        ],
        '/azafata/promociones/' . $promocion . '/participar'
    );

    $conCorreo = htmlDeAccion('ControladorParticipacion', 'registrar', ['id' => $promocion]);

    comprobarContiene(
        $conCorreo,
        'Enhorabuena',
        'La segunda participacion tambien gana'
    );
    comprobarNoContiene(
        $conCorreo,
        'resultado-codigo',
        'Con el correo de premio activado, el codigo no se enseña en pantalla'
    );

    // Solo ha encolado la segunda. La primera se adjudico con el correo apagado, y
    // una participacion que no manda correo no deja nada en la cola. Se cuenta
    // exactamente una, y no dos, porque este es el momento de comprobar que el
    // correo se decide en el momento de adjudicar y no despues: si el mensaje se
    // encolara al abrir la pantalla de resultado, la primera tambien habria
    // dejado uno y el «apago el codigo porque va por correo» de mas arriba seria
    // una mentira.
    comprobarIgual(
        1,
        (int) \App\Core\Aplicacion::db()->valor(
            'SELECT COUNT(*) FROM correos WHERE promocion_id = ?',
            [$promocion]
        ),
        'Solo la participacion con el correo activado ha dejado correo en la cola'
    );

    // ---- Un rechazo se explica con las palabras de la campana ---------------
    // La deduplicacion sigue puesta, con el texto que escribio la campana, y por
    // eso esta repetida tiene que caer. Elena ya participated con el mismo DNI.
    limpiarPeticion();
    enviarFormulario(
        [
            'idempotencia'  => claveDePrueba('caso13-rechazada'),
            'dni'           => '12345678Z',
            'nombre'        => 'Elena otra vez',
            'correo'        => 'elena@example.com',
            'consentimiento' => 'on',
        ],
        '/azafata/promociones/' . $promocion . '/participar'
    );

    $rechazo = htmlDeAccion('ControladorParticipacion', 'registrar', ['id' => $promocion]);

    comprobarContiene(
        $rechazo,
        'Ya has participado en esta promocion.',
        'El rechazo enseña el texto que ha escrito la campana, no un codigo interno'
    );
    comprobarNoContiene(
        $rechazo,
        'Notice:',
        'Un rechazo tampoco es un error de PHP'
    );
    comprobarNoContiene(
        $rechazo,
        'resultado-codigo',
        'Un rechazo no enseña ningun codigo de reclamacion'
    );

    // Y el rechazo no ha tocado las participaciones buenas: una participacion
    // rechazada no puede haber deshecho un premio ya entregado.
    comprobarIgual(
        2,
        (int) \App\Core\Aplicacion::db()->valor(
            'SELECT COUNT(*) FROM participaciones WHERE promocion_id = ? AND resultado = ?',
            [$promocion, 'premio']
        ),
        'Las dos ganadoras siguen ahi despues del rechazo de la siguiente'
    );

    // ---- Sin marcar la casilla de consentimiento, no hay participacion -------
    // Esta es la comprobacion que encontre al escribir este caso: la casilla la
    // pinta la vista y no es un campo configurado, asi que no pasaba por la
    // puerta de recoger() y las reglas no la veian nunca. Con la casilla marcada
    // de verdad, la participacion tambien se rechazaba.
    guardarReglas($promocion, [
        'exigir_consentimiento' => 1,
        'texto_consentimiento'  => 'He leido el aviso de privacidad de esta promocion.',
    ]);

    limpiarPeticion();
    enviarFormulario(
        [
            'idempotencia' => claveDePrueba('caso13-sin-consentimiento'),
            'dni'          => '11223344Y',
            'nombre'       => 'Sin marcar',
            'correo'       => 'sinmarcar@example.com',
        ],
        '/azafata/promociones/' . $promocion . '/participar'
    );

    $sinConsentimiento = htmlDeAccion('ControladorParticipacion', 'registrar', ['id' => $promocion]);

    comprobarContiene(
        $sinConsentimiento,
        'Es necesario aceptar el aviso de privacidad.',
        'Sin marcar la casilla, el rechazo explica cual era el problema'
    );
    comprobarNoContiene(
        $sinConsentimiento,
        'Enhorabuena',
        'Sin casilla de consentimiento no se gana nada'
    );

    // Y la prueba de verdad no es que se rechace, sino que la casilla LLEGA. Con
    // las mismas reglas y el mismo DNI, marcando la casilla, esta vez entra. Si
    // la casilla se perdiera por el camino, las dos participaciones darian el
    // mismo resultado y esta comprobacion no distinguiria un caso del otro.
    limpiarPeticion();
    enviarFormulario(
        [
            'idempotencia'  => claveDePrueba('caso13-con-consentimiento'),
            'dni'           => '11223344Y',
            'nombre'        => 'Marcada',
            'correo'        => 'marcada@example.com',
            'consentimiento' => 'on',
        ],
        '/azafata/promociones/' . $promocion . '/participar'
    );

    comprobarContiene(
        htmlDeAccion('ControladorParticipacion', 'registrar', ['id' => $promocion]),
        'Enhorabuena',
        'La misma persona, marcando la casilla, si participa'
    );

    // ---- Por que la pantalla compara con precision de minuto ----------------
    // La pantalla de participacion busca el tramo vigente con el reloj de la
    // campana, que trae segundos, y Tramos solo acepta horas cuyo segundo sea
    // cero, porque el esquema guarda la hora con precision de minuto. Si la
    // pantalla pasara el instante entero, habria tramo vigente 1 segundo de cada
    // 60 y toda participacion reventaria con «sin tramo activo» el resto del
    // tiempo. Es un fallo que sale y vuelve segun la hora a la que se pruebe,
    // asi que aqui se comprueba con las sesenta posiciones del reloj y no con la
    // hora del momento. El resto del caso depende de que haya tramo, asi que
    // esta comprobacion es la que lo deja escrito en vez de confiar en la suerte.
    $fallosMinuto = 0;
    $conSegundos = 0;

    for ($segundo = 0; $segundo < 60; $segundo++) {
        $instante = date('Y-m-d H:i:') . str_pad((string) $segundo, 2, '0', STR_PAD_LEFT);

        if (!Tramos::esHora(substr($instante, 11, 5))) {
            $fallosMinuto++;
        }

        if ($segundo > 0 && Tramos::esHora(substr($instante, 11, 8))) {
            $conSegundos++;
        }
    }

    comprobar(
        $fallosMinuto === 0,
        'La hora con precision de minuto vale en las sesenta posiciones del reloj',
        (string) $fallosMinuto . ' no valian'
    );
    comprobar(
        $conSegundos === 0,
        'Y ninguna hora con segundos distintos de cero valeria, que es lo que obliga a truncar',
        (string) $conSegundos . ' valian'
    );

    limpiarPeticion();
    borrarEscenarioDeAdjudicacion();
}

/**
 * Caso 17: la ruleta decorativa de la pantalla 2 (D19).
 *
 * ============================================================================
 * POR QUE ESTE CASO NO ES «QUE SE PINTE UNA RUEDA»
 * ============================================================================
 *
 * La ruleta es decorado, y una comprobacion de que aparece en pantalla pasaria
 * aunque se cumpliera justo lo que D19 prohibe. Lo que hay que mirar es lo que
 * la ruleta dice, porque su unico peligro es ese: si insinua que el premio depende
 * de donde pare el dedo, cada vez que el giro no coincida con el premio hay una
 * reclamacion, y el mostrador tiene que ir a explicar que el azar no decide nada.
 *
 * Las tres cosas que se comprueban son, por tanto, las tres que hacen que la
 * ruleta NO diga eso:
 *
 *   1. Los sectores no llevan texto. Ni nombres de premios, ni nada. Un sector
 *      rotulado con «Voucher de 20 euros» convierte una decoracion en una
 *      promesa, y el nombre del premio que se ha ganado no tendria por que
 *      coincidir con el sector donde se ha parado.
 *
 *   2. El unico texto es el nombre del comercio en el centro, que es el logotipo
 *      que pide D19. Y sale escapado, como sale todo lo demás.
 *
 *   3. El resultado NO depende de que el giro funcione. Se comprueba que el
 *      texto del resultado esta en el HTML de la pagina, sin ejecutar nada: si
 *      estuviera escondido por el CSS y lo revelara un temporizador de
 *      JavaScript, un fallo del script dejaria a la clienta mirando una pantalla
 *      sin resultado. Esa es la comprobacion que mas cuesta y la que mas
 *      importaba.
 *
 * Y una cuarta, mas pequena pero del mismo tipo: un rechazo NO gira ruleta.
 * Hacer girar una rueda sobre una participacion que no se ha registrado solo
 * haria esperar a la clienta para recibir un «su participacion no se ha
 * registrado», que ya se le puede decir de frente.
 *
 * ============================================================================
 * POR QUE NO SE COMPRUEBA EL GIRO EN UN NAVEGADOR REAL
 * ============================================================================
 *
 * Que la rueda de seis vueltas y cuarto termine apuntando arriba es cosa del CSS,
 * y una prueba de PHP no puede medirlo. Lo que si se puede comprobar, y se
 * comprueba, es lo que el servidor entrega y lo que el CSS declara: que las
 * clases existen, que el texto esta en el HTML y que el CSS no depende de
 * JavaScript. Una prueba que necesitara un navegador seria justo lo que D14 no
 * quiere: otra dependencia que instalar, y otra que se rompe sin avisar.
 *
 * @see \App\Core\Vista::renderizar()
 * @see decision D19 del documento de especificacion
 * @see apartado 5, pantalla 2
 *
 * @return void
 */
function caso17(): void
{
    echo 'Caso 17: la ruleta es decorada y el resultado no depende de ella', PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    $escenario = crearEscenarioDeAdjudicacion(['10:00', '11:00']);
    $promocion = (int) $escenario['promocion'];

    // El nombre del comercio es el logotipo del centro de la ruleta, y se pone uno
    // reconocible para que se pueda distinguir del nombre de la campana, que es lo
    // que se enseña cuando el comercio no esta rellenado.
    Aplicacion::db()->ejecutar(
        "UPDATE promociones SET comercio_nombre = 'Surtiduria del Casco' WHERE id = ?",
        [$promocion]
    );

    // Un tipo de premio con un nombre que se reconoceria en cualquier sitio. Este
    // texto es el que no puede aparecer en ningun sector de la ruleta, y la
    // comprobacion de abajo lo busca.
    $tipoPremioId = (int) $escenario['tipo_premio'];
    Aplicacion::db()->ejecutar(
        'UPDATE tipos_premio SET nombre = ? WHERE id = ?',
        ['Cesta de la compra', $tipoPremioId]
    );

    $motor = new \App\Services\Adjudicador(new ValidadorQueAcepta());

    $resultado = $motor->registrar(
        $promocion,
        claveDePrueba('caso17-ganadora'),
        $escenario['tramo'],
        ['nombre' => 'Clienta de la ruleta'],
        null,
        null,
        instanteDeHoy('12:00:00')
    );

    comprobarIgual('premio', $resultado['resultado'], 'La participacion que abre el caso gana un premio');

    $campana = (new \App\Models\Promocion())->exigirPorId($promocion, 'x');
    $visual = (new \App\Models\ConfiguracionVisual())->leer($promocion);

    $html = \App\Core\Vista::renderizar('participacion/resultado', [
        'titulo'    => 'Resultado',
        'campana'   => $campana,
        'resultado' => $resultado,
        'visual'    => $visual,
        'destino'   => 'azafata/promociones/' . $promocion . '/participar',
    ]);

    // ---- 1. Los sectores no llevan texto ------------------------------------
    // El nombre del tipo de premio, «Cesta de la compra», es lo que un sector
    // rotulado llevaria. No tiene que aparecer en ningun sitio de la pagina, y
    // tampoco en la parte de la ruleta.
    comprobarNoContiene(
        $html,
        'Cesta de la compra',
        'El nombre del premio NO aparece en la pagina, y por tanto tampoco en la ruleta'
    );

    // Y el bloque de los sectores no lleva ningun texto dentro, que es mas fuerte
    // que no encontrar este nombre concreto: si mañana se rotula un sector con
    // cualquier otro nombre de premio, esta comprobacion lo detecta sin tocar la
    // prueba.
    //
    // El corte empieza justo despues del cierre de la etiqueta de apertura, por
    // eso el patron esta escrito con «class="ruleta-sectores">» y no solo con el
    // nombre de la clase: si empezara en «ruleta-sectores» se llevaria por
    // delante el resto de los atributos, y con ellos el texto que tienen, que
    // para entonces ya no seria ninguno pero haria fallar la prueba sin motivo.
    // Termina en el primer «</div>», que es el de los propios sectores porque
    // dentro no hay nada anidado.
    $coincideSectores = preg_match('/<div class="ruleta-sectores">(.*?)<\/div>/s', $html, $coincide);
    $contenidoSectores = $coincideSectores === 1 ? (string) $coincide[1] : '';

    comprobar(
        $coincideSectores === 1,
        'La ruleta trae su bloque de sectores'
    );

    // El resultado del strip_tags se compara con una cadena vacia y no con null:
    // aqui se mira que no quede ni una letra, y los espacios de indentacion no
    // cuentan, pero un texto vacio de verdad tampoco.
    comprobar(
        trim(strip_tags($contenidoSectores)) === '',
        'El bloque de sectores no contiene texto ninguno',
        'contiene: ' . substr(trim(strip_tags($contenidoSectores)), 0, 120)
    );

    // ---- 2. El unico texto es el nombre del comercio ------------------------
    comprobarContiene(
        $html,
        'ruleta-logo',
        'El centro de la ruleta lleva el logotipo, que es el nombre del comercio'
    );
    comprobarContiene(
        $html,
        'Surtiduria del Casco',
        'Y el nombre del comercio es el que aparece en el centro'
    );

    // ---- 3. El resultado esta en el HTML, no lo pone JavaScript --------------
    // Esta es la comprobacion central del caso. El texto del resultado se busca
    // en el HTML que devuelve el servidor, antes de que el navegador ejecute
    // nada. Si estuviera oculto y lo revelara un script, aqui no apareceria, y
    // un fallo del script dejaria a la clienta sin ver su resultado.
    comprobarContiene(
        $html,
        'Enhorabuena',
        'El resultado esta escrito en el HTML, no lo anade el navegador'
    );
    comprobarContiene(
        $html,
        'resultado-bloque',
        'El bloque del resultado existe en el HTML de la pagina'
    );

    // Y el bloque del resultado NO lleva la clase que anula el retardo. En un
    // resultado adjudicado el retardo es lo correcto, porque hay ruleta girando;
    // lo que no puede ser es que el retardo decida si el texto existe.
    comprobarNoContiene(
        $html,
        'resultado-revelado',
        'Y el bloque adjudicado no se quita el retardo del giro, que es lo que toca'
    );

    // ---- Y que el rechazo no hace girar la ruleta ---------------------------
    borrarEscenarioDeAdjudicacion();

    $escenario = crearEscenarioDeAdjudicacion(['10:00']);
    $promocion = (int) $escenario['promocion'];
    $campana = (new \App\Models\Promocion())->exigirPorId($promocion, 'x');

    // Un rechazo no pasa por el motor de una participacion valida: se construye
    // el mismo array que devolveria el motor, que es lo que la vista recibe, y se
    // comprueba lo que la vista hace con el. Montar un rechazo de verdad exigiria
    // configurar una regla y su texto, y aqui lo que se prueba es la vista.
    $rechazoHtml = \App\Core\Vista::renderizar('participacion/resultado', [
        'titulo'    => 'Resultado',
        'campana'   => $campana,
        'resultado' => [
            'resultado'        => 'rechazada',
            'motivo_texto'     => 'Este cupon no es de esta promocion.',
            'codigo_reclamacion' => '',
        ],
        'visual'    => (new \App\Models\ConfiguracionVisual())->leer($promocion),
        'destino'   => 'azafata/promociones/' . $promocion . '/participar',
    ]);

    comprobarNoContiene(
        $rechazoHtml,
        'ruleta-sectores',
        'Un rechazo no gira ruleta: no hay nada que sortear'
    );
    comprobarContiene(
        $rechazoHtml,
        'Este cupon no es de esta promocion.',
        'Y el motivo del rechazo se dice de frente, sin esperar a ninguna animacion'
    );
    comprobarContiene(
        $rechazoHtml,
        'resultado-revelado',
        'Y su texto lleva el retardo anulado, porque sin ruleta no hay nada que esperar'
    );

    // ---- Y que el CSS declara las dos animaciones --------------------------
    // El giro y la aparicion del resultado se declaran en la hoja de estilos, no
    // en el script. Se comprueba que las dos estan, porque si la segunda se
    // moviera al script se habria perdido justamente la garantia del punto 3.
    $css = (string) file_get_contents(
        dirname(__DIR__) . '/assets/css/estilos.css'
    );

    comprobar(
        str_contains($css, '@keyframes girar'),
        'El giro de la ruleta esta declarado en el CSS'
    );
    comprobar(
        str_contains($css, '@keyframes revelar'),
        'Y la aparicion del resultado tambien, no en un temporizador de JavaScript'
    );
    comprobar(
        !str_contains($css, 'url('),
        'La ruleta no carga ninguna imagen: sale de un degradado, como manda D14'
    );

    borrarEscenarioDeAdjudicacion();
    limpiarPeticion();
}

/**
 * Caso 18: las horas que no existen y las que ocurren dos veces (cambio de hora).
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE
 * ============================================================================
 *
 * El codigo ya valida el cambio de hora: `Tramos::comprobarCambioDeHora()` rechaza
 * el tramo que cruza la ventana, `Tramos::minutosValidos()` se salta las horas que
 * no llegaron a existir y `Tramos::comprobarDentroDelTramo()` no acepta que se
 * coloque un premio a una hora que ese dia no ocurrio. Todo eso estaba escrito y
 * **no tinha ni una sola prueba**, que es la forma mas comoda de que una regla se
 * rompa sin que nadie se entere: no hay nada que avise.
 *
 * La regla de fondo cabe en dos frases, y son las dos ramas del salto:
 *
 *   1. En marzo el reloj salta de las 02:00 a las 03:00. Entre esas dos horas de
 *      pared NO EXISTE NINGUNA. Un tramo que las cover y un premio colocado a las
 *      02:30 harian referencia a una hora que ningun reloj iba a marcar.
 *
 *   2. En octubre el reloj atrasa de las 03:00 a las 02:00. Entre las 02:00 y las
 *      03:00 CADA HORA OCURRE DOS VECES. Aqui la hora si existe, pero es ambigua:
 *      un premio placed a las 02:30 no sabria cual de las dos veces es.
 *
 * Que las dos ramas se comprueben por separado no es purismo. Un fallo que quitara
 * una de las dos comprobaciones dejaria pasar la mitad de los tramos que hoy se
 * rechazan, y no habria ningun aviso: el resultado seria un calendario con premios
 * en horas que no existen, que es un fallo que no se ve hasta que alguien pregunta
 * por un premio que no se ha repartido.
 *
 * ============================================================================
 * POR QUE LAS FECHAS ESTAN ESCRITAS A MANO Y NO SE CALCULAN
 * ============================================================================
 *
 * Cabria la tentacion de calcular «el ultimo domingo de marzo de este ano» para
 * que la prueba siga valiendo dentro de tres años. Seria un error. El calculo
 * devolveria una fecha de 2029, 2030 o mas adelante, y la base de datos de zonas
 * horarias puede no tener todavia la transicion de ese dia, con lo que
 * `cambioDeHora()` devolveria null y la comprobacion pasaria sin comprobar nada:
 * exactamente el fallo que esta prueba existe para cazar. Escribir 2026-03-29 y
 * 2026-10-25 a mano es escribir un hecho, y un hecho no caduca.
 *
 * @see \App\Services\Tramos::cambioDeHora()
 * @see \App\Services\Tramos::comprobarCambioDeHora()
 * @see \App\Services\Tramos::minutosValidos()
 * @see \App\Services\Tramos::comprobarDentroDelTramo()
 *
 * @return void
 */
function caso18(): void
{
    echo 'Caso 18: las horas que no existen y las que ocurren dos veces', PHP_EOL;

    $tramos = new \App\Services\Tramos();

    // Los dos dias del cambio de hora de 2026 en Europe/Madrid, y un dia normal
    // de cada lado para comprobar que no se inventan ventanas donde no las hay.
    $marzo = '2026-03-29';
    $octubre = '2026-10-25';
    $normal = '2026-07-01';

    // ---- 1. Los dias que no tienen cambio de hora ---------------------------
    // Esto va el primero a proposito. Si `cambioDeHora()` devolviera una ventana
    // inventada en cualquier dia, todas las comprobaciones siguientes pasarian por
    // casualidad, por un motivo equivocado. Un dia de julio no tiene ningun salto.
    foreach ([$normal, '2026-03-28', '2026-03-30', '2026-10-24', '2026-10-26'] as $diaSinSalto) {
        comprobar(
            $tramos->cambioDeHora($diaSinSalto) === null,
            'El ' . $diaSinSalto . ' no tiene cambio de hora y no inventa ninguna ventana'
        );
    }

    // ---- 2. Las dos ventanas, que son iguales y las dos ramas distintas ---
    $cambioMarzo = $tramos->cambioDeHora($marzo);
    $cambioOctubre = $tramos->cambioDeHora($octubre);

    comprobar($cambioMarzo !== null, 'El ' . $marzo . ' tiene cambio de hora');
    comprobar($cambioOctubre !== null, 'Y el ' . $octubre . ' tambien');

    // La ventana de pared es la misma en las dos fechas: de las 02:00 a las 03:00.
    // Lo que cambia es lo que pasa dentro, y por eso las dos se comprueban por
    // separado en los puntos 4 y 5.
    comprobarIgual('02:00:00', (string) ($cambioMarzo['ventana_desde'] ?? ''), 'En marzo la ventana empieza a las 02:00');
    comprobarIgual('03:00:00', (string) ($cambioMarzo['ventana_hasta'] ?? ''), 'Y acaba a las 03:00');

    comprobarIgual(true, $cambioMarzo['adelanta'] ?? null, 'En marzo el reloj adelanta: sobran horas');
    comprobarIgual(false, $cambioOctubre['adelanta'] ?? null, 'En octubre el reloj atrasa: sobran repeticiones');

    // El motivo lo lee alguien que no sabe lo que es un desplazamiento horario,
    // asi que se comprueba que dice lo que tiene que decir y no solo que existe.
    comprobarContiene(
        (string) ($cambioMarzo['motivo'] ?? ''),
        'no existe ninguna hora',
        'El aviso de marzo dice que no existe ninguna hora'
    );
    comprobarContiene(
        (string) ($cambioOctubre['motivo'] ?? ''),
        'ocurre dos veces',
        'Y el de octubre dice que cada hora ocurre dos veces'
    );

    // ---- 3. El tramo que cruza la ventana se rechaza -------------------------
    // El caso central. Un tramo de la 01:00 a las 04:00 de un domingo de marzo es
    // perfectamente bueno en cualquier otro dia, y ese dia no vale: su mitad cae
    // en horas que no existen.
    $validador = new \App\Core\Validador();
    $tramos->comprobarCambioDeHora($validador, 'tramo', $marzo, '01:00', '04:00');

    comprobar(
        $validador->tieneErrores(),
        'Un tramo de 01:00 a 04:00 el dia del cambio de marzo NO es valido'
    );
    comprobarContiene(
        json_encode($validador->errores(), JSON_UNESCAPED_UNICODE) ?: '',
        'cambia la hora',
        'Y el motivo le dice a la azafata que ese dia cambia la hora'
    );

    // El error va en el campo del inicio, que es donde esta el boton de guardar y
    // donde el navegador ira a mirar. Si se colgara de otro nombre, el formulario
    // no lo moveria nunca.
    comprobar(
        array_key_exists('tramo_inicio', $validador->errores()),
        'Y el error se cuelga del campo del inicio, no de un campo cualquiera'
    );

    // ---- 4. Los bordes de la ventana, que es donde se equivoca uno -----------
    // Estas dos son las comprobaciones que mas valor tienen de todo el caso, y no
    // por lo que miran sino por lo que impiden. La condicion de solape es
    // «empieza antes de que acabe la ventana y acaba despues de que empiece». Un
    // signo mal puesto, o un <= donde tocaba un <, aqui no prohibiria un tramo
    // malo: prohibiria dos tramos buenos, el de la madrugada que acaba justo cuando
    // empieza el hueco y el de la tarde que empieza justo cuando acaba.
    //
    // Y prohibirlos seria peor que no mirar nada. Un tramo de 00:00 a 02:00 el
    // domingo de cambio es el turno de apertura de un supermercado, y el de
    // 03:00 a 06:00 es el de la mañana. Los dos son validos y los dos estan en el
    // limite de una hora de pared.
    $validador = new \App\Core\Validador();
    $tramos->comprobarCambioDeHora($validador, 'tramo', $marzo, '00:00', '02:00');
    comprobar(
        !$validador->tieneErrores(),
        'Un tramo que ACABA justo cuando empieza el hueco es valido: no lo cruza',
        'errores: ' . json_encode($validador->errores(), JSON_UNESCAPED_UNICODE)
    );

    $validador = new \App\Core\Validador();
    $tramos->comprobarCambioDeHora($validador, 'tramo', $marzo, '03:00', '06:00');
    comprobar(
        !$validador->tieneErrores(),
        'Y uno que EMPIEZA justo cuando acaba el hueco tambien es valido',
        'errores: ' . json_encode($validador->errores(), JSON_UNESCAPED_UNICODE)
    );

    // Y el caso intermedio, que es el que se parece al error: empezar un minuto
    // antes del final de la ventana ya es estar dentro.
    $validador = new \App\Core\Validador();
    $tramos->comprobarCambioDeHora($validador, 'tramo', $marzo, '02:01', '04:00');
    comprobar(
        $validador->tieneErrores(),
        'Un minuto dentro del hueco ya es dentro: 02:01 a 04:00 no vale'
    );

    // ---- 5. Lo mismo en octubre, y el caso en que no hay hueco --------------
    // En octubre la ventana de pared es identica, asi que el tramo que la cruza
    // tambien se rechaza. No por las horas inexistentes, que ahi no hay ninguna,
    // sino por la ambiguedad: un premio a las 02:30 no sabria cual de las dos
    // veces que ocurrio es.
    $validador = new \App\Core\Validador();
    $tramos->comprobarCambioDeHora($validador, 'tramo', $octubre, '01:00', '04:00');
    comprobar(
        $validador->tieneErrores(),
        'En octubre el tramo que cruza la ventana tambien se rechaza, por ambigua que es'
    );

    // Y en un dia normal no hay nada que rechazar, ni aunque el tramo sea el mas
    // largo del dia entero.
    $validador = new \App\Core\Validador();
    $tramos->comprobarCambioDeHora($validador, 'tramo', $normal, '00:00', '23:59');
    comprobar(
        !$validador->tieneErrores(),
        'En un dia sin cambio de hora un tramo de todo el dia es valido'
    );

    // ---- 6. Cuantos minutos caben de verdad ---------------------------------
    // Aqui esta la consecuencia de todo lo anterior, y es donde se ve el dano en
    // numero. Un tramo de 01:00 a 04:00 tiene 180 minutos de reloj. En marzo solo
    // existen 120, porque los 60 de las 02:00 no llegaron a pasar. Si el generador
    // repartiera por la resta, admitiria 180 premios y colocaria los ultimos 60 en
    // horas que no existen: filas fantasma que MariaDB acepta sin decir nada y que
    // el motor no adjudicaria nunca.
    $minutosNormales = $tramos->minutosValidos($normal, '01:00:00', '04:00:00');
    comprobarIgual(180, count($minutosNormales), 'Un dia normal da los 180 minutos que tiene el tramo');

    $minutosMarzo = $tramos->minutosValidos($marzo, '01:00:00', '04:00:00');
    comprobarIgual(120, count($minutosMarzo), 'En marzo el mismo tramo da 120 minutos, no 180');

    // Y que no quede ni una hora de las que no existen. Esto es mas fuerte que
    // contar: si el codigo se saltara 30 minutos en vez de 60, el recuento habria
    // delatado el cambio, pero esta comprobacion delata el fallo
    // aunque el numero saliera bien por casualidad.
    $horasDelHueco = array_values(array_filter(
        $minutosMarzo,
        static fn (string $hora): bool => str_starts_with($hora, '02:')
    ));

    comprobar(
        $horasDelHueco === [],
        'Y ninguna de las horas entregadas es una hora que ese dia no existio',
        'horas del hueco: ' . implode(', ', array_slice($horasDelHueco, 0, 5))
    );

    // En octubre NO hay horas que no existan, asi que el tramo da los 180 minutos
    // enteros. Esta es la asimetria deliberada del codigo, y por eso se comprueba
    // con su numero: en octubre la hora 02:30 existe, dos veces, y es una hora
    // repartible. Perderla, como hace el error que esta comprobacion caza, seria
    // quitarle a un supermercado los premios de una hora entera un domingo al año.
    $minutosOctubre = $tramos->minutosValidos($octubre, '01:00:00', '04:00:00');
    comprobarIgual(180, count($minutosOctubre), 'En octubre el mismo tramo si da los 180 minutos');

    comprobar(
        count(array_unique($minutosOctubre)) === count($minutosOctubre),
        'Y no hay horas repetidas, aunque las de las 02:00 ocurran dos veces'
    );

    // ---- 7. Que no se pueda colocar un premio en una hora que no ocurrio -----
    // El calendario deja mover y anadir unidades a mano, asi que el tramo puede
    // estar bien y el premio colocado en una hora que ese dia no existia. MariaDB
    // la aceptaria sin pestanear, porque para ella 02:30 es una hora perfectamente
    // normal, y el premio se quedaria en la cola para siempre sin repartirse.
    $errores = $tramos->comprobarDentroDelTramo($marzo, '01:00:00', '04:00:00', $marzo, '02:30');
    comprobar(
        $errores !== [],
        'No se puede colocar un premio a las 02:30 de un dia en que esa hora no existio'
    );
    comprobarContiene(
        json_encode($errores, JSON_UNESCAPED_UNICODE) ?: '',
        'no existen',
        'Y el error lo explica en castellano, no con un codigo'
    );

    // Media hora mas tarde si existe, y entra sin problema.
    $errores = $tramos->comprobarDentroDelTramo($marzo, '01:00:00', '04:00:00', $marzo, '03:30');
    comprobar(
        $errores === [],
        'Pero a las 03:30 si, porque esa hora ya existe'
    );

    // Y en octubre las 02:30 tambien se aceptan, por lo mismo que antes: existen.
    $errores = $tramos->comprobarDentroDelTramo($octubre, '01:00:00', '04:00:00', $octubre, '02:30');
    comprobar(
        $errores === [],
        'Y en octubre las 02:30 tambien valen, porque esas horas existen'
    );

    // ---- 8. Y que el panel no deje colar un tramo por la puerta de atrás -----
    // Todo lo anterior es el servicio. El servicio es el que razona, pero el que
    // guarda es el controlador, asi que se prueba el camino entero: se monta un
    // tramo de 01:00 a 04:00 en el dia del cambio y se pide que se valide por la
    // misma via que el panel. Un servicio bien escrito al que nadie llama en la
    // escritura de la fila es una regla que no protege de nada.
    $escenario = crearEscenarioDePanel(['premios' => 1, 'tramos' => 1]);
    $id = (int) $escenario['promocion'];

    $validador = new \App\Core\Validador();
    $datos = $tramos->validarCampos($validador, 'tramo', $marzo, '01:00', '04:00');
    $tramos->comprobarCambioDeHora($validador, 'tramo', (string) $datos['fecha'], (string) $datos['hora_inicio'], (string) $datos['hora_fin']);

    comprobar(
        $datos !== null && $validador->tieneErrores(),
        'El camino que usa el panel para guardar un tramo tampoco deja cruzar el hueco'
    );

    // Y nada de eso ha escrito una fila: la comprobacion tiene que haber ocurrido
    // antes de tocar la tabla, no despues de arreglarlo.
    $tramosEnElDia = (int) \App\Core\Aplicacion::db()->valor(
        'SELECT COUNT(*) FROM tramos WHERE promocion_id = ? AND fecha = ?',
        [$id, $marzo]
    );

    comprobarIgual(0, $tramosEnElDia, 'Y en la tabla no ha quedado ningun tramo para ese dia');

    borrarEscenarioDePanel($id);
    limpiarPeticion();
}

/**
 * Caso 14: cerrar una campana deja los premios sin entregar y lo apunta.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE
 * ============================================================================
 *
 * El cierre es la operacion mas dificil de deshacer de todo el panel, y por eso
 * se comprueba en tres frentes distintos y no solo en que la campana pase a
 * finalizada.
 *
 * Primero, que las unidades programadas pasan a no_entregadas y que las que ya
 * estaban entregadas NO se tocan. Esa segunda parte es la importante: un cierre
 * que volviera limpia el estado de toda la cola dejaria el historico de premios
 * entregado inservible, y es el fallo mas grave posible porque no se ve hasta que
 * alguien intenta resolver una reclamacion de hace tres semanas.
 *
 * Segundo, que cerrar dos veces no hace nada la segunda vez. Un doble clic en un
 * boton es lo mas normal del mundo en una tablet con la pantalla suelta, y no
 * puede dejar dos filas de auditoria ni mover otra vez las marcas de tiempo.
 *
 * Tercero, que queda escrito quien ha cerrado, cuando y cuantos premios se han
 * quedado sin reclamar. Sin esa fila, despues no hay forma de responder a la
 * pregunta que mas se le hace a un supermercado: «¿y estos tres premios por que
 * no se entregaron?».
 *
 * @return void
 */
function caso14(): void
{
    echo 'Caso 14: cerrar la campana consume los premios sin entregar y lo registra', PHP_EOL;

    $db = Aplicacion::db();
    $escenario = crearEscenarioDePanel(['premios' => 1, 'tramos' => 1]);
    $id = (int) $escenario['promocion'];

    (new \App\Services\Calendario())->generar($id);
    (new \App\Services\ConfiguracionPromocion())->activar($id);
    limpiarPeticion();

    $unidades = new \App\Models\UnidadPremio();

    // El escenario pone dos unidades de cada premio en cada tramo, y hay un solo
    // tramo con un solo premio, asi que hay dos. Se coge una para entregarla a
    // mano y dejar la otra programada: sin esa entrega, el cierre no tendria nada
    // que conservar y la comprobacion central no probaria nada.
    $ids = array_map(
        static fn (array $fila): int => (int) $fila['id'],
        $unidades->listarParaCalendario($id, [], 10)
    );

    comprobarIgual(2, count($ids), 'El escenario ha dejado dos unidades programadas');

    $estados = $unidades->contarPorEstado($id);
    comprobarIgual(2, (int) ($estados[\App\Models\UnidadPremio::ESTADO_PROGRAMADA] ?? 0), 'Las dos unidades empiezan programadas');

    // La entrega se hace con el codigo de la propia participacion, no con un
    // UPDATE a mano, para que lo que se comprueba despues sea el estado que deja
    // el motor y no uno escrito a proposito para que la prueba pase.
    //
    // Las horas van en la fecha del tramo, que el escenario de pruebas pone en la
    // de hoy. Se leen de ahi en vez de escribirse fijas por la misma razon que en
    // el caso 15: una fecha escrita a mano hace que el caso dependa del dia en que
    // se ejecuta.
    $fecha = (string) (new \App\Models\Tramo())->exigirPorId((int) $escenario['tramos'][0], '', $id)['fecha'];
    $entrega = $fecha . ' 10:12:00';
    $cierreInstante = $fecha . ' 20:00:00';

    $participacionId = $db->insertar(
        'INSERT INTO participaciones (
             promocion_id, tramo_id, clave_idempotencia, momento, resultado, datos, es_simulacion, creado_en
         ) VALUES (?, ?, ?, ?, ?, ?, 0, ?)',
        [
            $id,
            (int) $escenario['tramos'][0],
            claveDePrueba('caso14-ganadora'),
            $entrega,
            \App\Models\Participacion::RESULTADO_PREMIO,
            '{"dni":"12345678Z"}',
            $entrega,
        ]
    );

    $codigo = $unidades->generarCodigoReclamacion();
    $unidades->entregar($ids[0], $participacionId, $entrega, $codigo);

    $estados = $unidades->contarPorEstado($id);
    comprobarIgual(1, (int) ($estados[\App\Models\UnidadPremio::ESTADO_ENTREGADA] ?? 0), 'Antes de cerrar hay una unidad entregada');

    // ---- El cierre --------------------------------------------------------
    $cierre = new \App\Services\CierrePromocion();
    $instante = $cierreInstante;

    comprobar(
        $cierre->puedeCerrar($id),
        'Una campana activa se puede cerrar'
    );

    $resultado = $cierre->cerrar($id, $instante, null);

    comprobarIgual(
        \App\Models\Promocion::ESTADO_FINALIZADA,
        $resultado['estado'],
        'El cierre devuelve la campana ya finalizada'
    );
    comprobarIgual(1, (int) $resultado['unidades_no_entregadas'], 'El cierre dice que solo se ha quedado sin entregar una unidad');
    comprobarIgual(1, (int) $resultado['unidades_pendientes'], 'Y dice que antes del cierre quedaba una unidad viva');

    $campana = (new \App\Models\Promocion())->exigirPorId($id);
    comprobarIgual(\App\Models\Promocion::ESTADO_FINALIZADA, $campana['estado'], 'La campana esta finalizada de verdad en la base de datos');
    comprobarIgual($instante, (string) $campana['cerrada_en'], 'Y tiene escrita la hora del cierre, no la hora de ahora');

    $estados = $unidades->contarPorEstado($id);
    comprobarIgual(1, (int) ($estados[\App\Models\UnidadPremio::ESTADO_ENTREGADA] ?? 0), 'La unidad ya entregada sigue entregada despues del cierre');
    comprobarIgual(1, (int) ($estados[\App\Models\UnidadPremio::ESTADO_NO_ENTREGADA] ?? 0), 'La unidad programada ha pasado a no entregada');
    comprobarIgual(0, (int) ($estados[\App\Models\UnidadPremio::ESTADO_PROGRAMADA] ?? 0), 'No queda ninguna unidad programada');

    // La unidad entregada tiene que conservar su codigo y su participacion. Es lo
    // que permite resolver una reclamacion semanas despues, y un cierre que lo
    // limpiara dejaria el premio entregado sin dueno.
    $guardada = $unidades->buscarPorId($ids[0]);
    comprobarIgual($codigo, (string) ($guardada['codigo_reclamacion'] ?? ''), 'El codigo de reclamacion de la unidad entregada sobrevive al cierre');
    comprobarIgual($participacionId, (int) ($guardada['participacion_id'] ?? 0), 'Y la participacion que se llevo el premio tambien');

    // ---- Y queda escrito --------------------------------------------------
    $lineas = (new \App\Models\Auditoria())->listarPorCampana($id, 50);
    $cierres = array_values(array_filter(
        $lineas,
        static fn (array $l): bool => (string) $l['accion'] === \App\Models\Auditoria::ACCION_CIERRE
    ));

    comprobarIgual(1, count($cierres), 'El cierre ha dejado exactamente una anotacion de auditoria');

    if ($cierres !== []) {
        $despues = json_decode((string) $cierres[0]['datos_despues'], true);

        comprobarIgual(
            1,
            (int) ($despues['unidades_no_entregadas'] ?? 0),
            'La anotacion dice cuantas unidades se han quedado sin entregar'
        );
        comprobarIgual(
            \App\Models\Promocion::ESTADO_FINALIZADA,
            (string) ($despues['estado'] ?? ''),
            'Y con que estado ha quedado la campana'
        );

        $antes = json_decode((string) $cierres[0]['datos_antes'], true);
        comprobarIgual(
            \App\Models\Promocion::ESTADO_ACTIVA,
            (string) ($antes['estado'] ?? ''),
            'Y tambien guarda el estado que tenia antes'
        );
    }

    // ---- Cerrar dos veces no rompe nada ------------------------------------
    comprobarFalla(
        \App\Core\ErrorAplicacion::class,
        static fn () => $cierre->cerrar($id, '2026-03-16 21:00:00', null),
        'Una campana ya cerrada no se puede volver a cerrar'
    );

    limpiarPeticion();

    comprobar(
        !$cierre->puedeCerrar($id),
        'Y la ficha ya no la ofrece como cerrable'
    );

    $lineas = (new \App\Models\Auditoria())->listarPorCampana($id, 50);
    $cierres = array_values(array_filter(
        $lineas,
        static fn (array $l): bool => (string) $l['accion'] === \App\Models\Auditoria::ACCION_CIERRE
    ));

    comprobarIgual(1, count($cierres), 'El segundo intento no ha escrito una segunda anotacion');

    comprobarIgual(
        $instante,
        (string) ((new \App\Models\Promocion())->exigirPorId($id))['cerrada_en'],
        'Ni ha movido la hora de cierre a la del segundo intento'
    );

    // ---- Y por la pantalla, que es como lo va a usar la gente ---------------
    $html = htmlDeAccion('ControladorSeguimiento', 'panel', ['id' => $id]);
    comprobarNoContiene($html, 'Notice:', 'El panel de una campana cerrada se pinta sin avisos de PHP');
    comprobarContiene($html, 'Esta campana no esta activa', 'El panel avisa de que ya no se puede cerrar');

    // Se busca la accion del formulario y no el texto del boton, porque el titulo
    // de la seccion tambien dice «Cerrar la campana» y una comprobacion por texto
    // pasaria aunque el boton estuviese escondido: es el fallo clasico de
    // comprobar en un panel por una palabra que aparece en varios sitios.
    comprobarNoContiene(
        $html,
        '/seguimiento/cerrar',
        'Y no ofrece el formulario de cierre'
    );

    limpiarPeticion();
    borrarEscenarioDePanel($id);
}

/**
 * Caso 15: el panel de seguimiento enseña las cifras y filtra lo que se le pide.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE
 * ============================================================================
 *
 * El apartado 8 pide nueve cosas en una pantalla y tres filtros. Lo que se
 * comprueba aqui no es que la pantalla se pinte, que es lo facil, sino que las
 * cifras sean las de esta campana y no las de otra, que los tres filtros
 * aprieten de verdad, y que una fecha que no es una fecha no rompa la pantalla.
 *
 * El ultimo punto es el que mas veces falla en un panel con filtros. Un filtro de
 * fecha que se pasa tal cual a la consulta produce un error de MySQL —o peor, un
 * error de PHP— cuando alguien escribe «ayer» en el campo, y en un supermercado
 * eso significa una pantalla en blanco mientras el administrador espera. Aqui se
 * comprueba que una fecha imposible se ignora y se muestra todo, que es lo que
 * espera quien se equivoca al escribir.
 *
 * Tambien se comprueba la auditoria de la D18: que mirar el panel escriba una
 * fila con quien ha mirado, con que filtro y cuantas filas, y que la pantalla
 * cuente esa propia fila en su historial.
 *
 * @return void
 */
function caso15(): void
{
    echo 'Caso 15: el panel de seguimiento enseña las cifras y filtra', PHP_EOL;

    $db = Aplicacion::db();
    $escenario = crearEscenarioDePanel(['premios' => 2, 'tramos' => 2]);
    $id = (int) $escenario['promocion'];
    $tramos = $escenario['tramos'];
    $tipos = $escenario['tipos'];

    (new \App\Services\Calendario())->generar($id);
    (new \App\Services\ConfiguracionPromocion())->activar($id);
    limpiarPeticion();

    $unidades = new \App\Models\UnidadPremio();

    // Dos premios por dos tramos dan ocho unidades. Se toma como referencia un
    // instante posterior a la generacion, para que «pendientes» tenga sentido y
    // no dependa de la hora a la que se este ejecutando la suite.
    //
    // La fecha NO se escribe a mano: el escenario de pruebas crea los tramos con
    // la fecha de hoy, porque «hoy» es lo unico que esta dentro del horario que
    // ha puesto el generador. Si aqui se escribiera una fecha fija, el caso
    // pasaria un dia y fallaria otro, y solo por la fecha: es exactamente el tipo
    // de prueba que parece solida y no lo es. Se lee la del tramo y se construye
    // el instante a partir de ella.
    $tramo = (new \App\Models\Tramo())->exigirPorId((int) $tramos[0], '', $id);
    $fechaEscenario = (string) $tramo['fecha'];
    $momento = $fechaEscenario . ' 23:30:00';

    // ---- Una unidad entregada, para que la lista de entregas no este vacia ---
    $ids = array_map(
        static fn (array $f): int => (int) $f['id'],
        $unidades->listarParaCalendario($id, [], 20)
    );
    comprobarIgual(8, count($ids), 'El escenario ha repartido ocho unidades');

    $participacionId = $db->insertar(
        'INSERT INTO participaciones (
             promocion_id, tramo_id, clave_idempotencia, momento, resultado, datos, es_simulacion, creado_en
         ) VALUES (?, ?, ?, ?, ?, ?, 0, ?)',
        [
            $id,
            (int) $tramos[0],
            claveDePrueba('caso15-ganadora'),
            '2026-03-15 10:12:00',
            \App\Models\Participacion::RESULTADO_PREMIO,
            '{"dni":"87654321X"}',
            '2026-03-15 10:12:00',
        ]
    );

    $unidades->entregar(
        $ids[0],
        $participacionId,
        '2026-03-15 10:19:00',
        $unidades->generarCodigoReclamacion()
    );

    $seguimiento = new \App\Services\Seguimiento();
    $panel = $seguimiento->panel($id, [], $momento);

    // ---- Las cifras de arriba ---------------------------------------------
    comprobarIgual(8, (int) $panel['unidades']['total'], 'El panel cuenta las ocho unidades del plan');
    comprobarIgual(1, (int) $panel['unidades']['entregadas'], 'Y sabe que una se ha entregado');
    comprobarIgual(7, (int) $panel['unidades']['programadas'], 'Y que las otras siete siguen programadas');
    comprobarIgual(0, (int) $panel['unidades']['no_entregadas'], 'Ninguna se ha quedado sin entregar todavia');
    comprobarIgual(1, (int) $panel['participaciones']['total'], 'Cuenta la participacion valida');
    comprobarIgual(1, (int) $panel['participaciones']['con_premio'], 'Y la cuenta como con premio');

    // «Pendientes» son las programadas cuya hora ya ha llegado, no las que faltan
    // por entregar. Con un instante posterior a la generacion, las ocho cumplen
    // eso, y el caso distingue los dos numeros.
    comprobarIgual(
        7,
        (int) $panel['unidades']['pendientes'],
        'Las siete programadas ya disponibles cuentan como pendientes'
    );

    comprobarIgual(8, (int) $panel['listado']['total_unidades'], 'El listado sin filtros trae las ocho unidades');
    comprobarIgual(1, (int) $panel['listado']['total_adjudicadas'], 'Y de las entregas solo hay una');

    // ---- Las dos horas, que es lo que pide el apartado 8 --------------------
    comprobarIgual(1, count($panel['listado']['adjudicadas']), 'La lista de premios entregados trae esa unidad');

    if ($panel['listado']['adjudicadas'] !== []) {
        $entrega = $panel['listado']['adjudicadas'][0];

        comprobar(
            (string) $entrega['inicio'] !== '',
            'La entrega trae la hora prevista del premio'
        );
        comprobarIgual(
            '2026-03-15 10:19:00',
            (string) $entrega['adjudicada_en'],
            'Y trae la hora real a la que se entrego'
        );
        comprobar(
            isset($entrega['retraso_minutos']),
            'Y el numero de minutos de espera entre las dos horas'
        );
    }

    // ---- Los tres filtros del apartado 8 -----------------------------------
    $porTramo = $seguimiento->panel($id, ['tramo_id' => (int) $tramos[0]], $momento);
    comprobarIgual(
        4,
        (int) $porTramo['listado']['total_unidades'],
        'Filtrar por un tramo de dos deja las cuatro unidades de ese tramo'
    );
    comprobar(
        count($porTramo['listado']['unidades']) === 4,
        'Y el listado trae esas mismas cuatro filas'
    );

    // El filtro acota el listado, no los totales de arriba. Es la distincion que
    // mas confunde a quien mira la pantalla: las cifras grandes son de toda la
    // campana, y el filtro solo acts sobre las filas.
    comprobarIgual(
        8,
        (int) $porTramo['unidades']['total'],
        'El filtro por tramo no cambia los totales de la campana'
    );

    $porPremio = $seguimiento->panel($id, ['tipo_premio_id' => (int) $tipos[0]], $momento);
    comprobarIgual(
        4,
        (int) $porPremio['listado']['total_unidades'],
        'Filtrar por un tipo de premio deja las cuatro unidades de ese premio'
    );

    $porFecha = $seguimiento->panel($id, ['fecha' => $fechaEscenario], $momento);
    comprobarIgual(
        8,
        (int) $porFecha['listado']['total_unidades'],
        'Filtrar por la fecha del escenario deja las ocho unidades'
    );

    $otraFecha = $seguimiento->panel($id, ['fecha' => '2020-01-01'], $momento);
    comprobarIgual(
        0,
        (int) $otraFecha['listado']['total_unidades'],
        'Filtrar por una fecha en la que no hay nada deja el listado vacio'
    );

    // ---- Una fecha que no es una fecha no rompe nada -----------------------
    foreach (['2026-13-45', 'ayer', '', '15/03/2026'] as $mala) {
        $invalida = $seguimiento->panel($id, ['fecha' => $mala], $momento);

        comprobar(
            !isset($invalida['filtros']['fecha']),
            'Una fecha que no es una fecha se ignora en vez de mandarse a la consulta: ' . $mala
        );
        comprobarIgual(
            8,
            (int) $invalida['listado']['total_unidades'],
            'Y con esa fecha mal escrita se ve la campana entera: ' . $mala
        );
    }

    // Un filtro con nombre inventado tambien se ignora, en lugar de llegar a la
    // consulta como si fuera un nombre de columna.
    $inventado = $seguimiento->panel($id, ['columna_secreta' => 'x'], $momento);
    comprobarIgual(
        8,
        (int) $inventado['listado']['total_unidades'],
        'Un filtro con un nombre que no existe se ignora y no ensucia la consulta'
    );

    // ---- La auditoria de la D18 --------------------------------------------
    $antes = (new \App\Models\Auditoria())->contarPorAccion($id);

    $visita = $seguimiento->anotarVisita(
        $id,
        ['fecha' => $fechaEscenario, 'tramo_id' => (int) $tramos[0], 'tipo_premio_id' => (int) $tipos[0]],
        4,
        null
    );

    comprobar($visita > 0, 'Mirar el panel escribe una anotacion de auditoria');

    $despues = (new \App\Models\Auditoria())->contarPorAccion($id);
    comprobarIgual(
        (int) (($antes[\App\Models\Auditoria::ACCION_VISUALIZACION] ?? 0) + 1),
        (int) ($despues[\App\Models\Auditoria::ACCION_VISUALIZACION] ?? 0),
        'Y el recuento de visualizaciones sube en uno'
    );

    $linea = $db->uno(
        'SELECT usuario_nombre, entidad, accion, filtros, filas_mostradas
           FROM auditoria WHERE id = ? LIMIT 1',
        [$visita]
    );

    comprobarIgual('seguimiento', (string) ($linea['entidad'] ?? ''), 'La anotacion dice de que pantalla es');
    comprobarIgual(
        \App\Models\Auditoria::ACCION_VISUALIZACION,
        (string) ($linea['accion'] ?? ''),
        'Y que ha sido una visualizacion, no un cambio'
    );
    comprobar(
        str_contains((string) ($linea['filtros'] ?? ''), 'tramo ' . (int) $tramos[0]),
        'Y guarda que filtro se estaba usando',
        'filtros: ' . (string) ($linea['filtros'] ?? '')
    );
    comprobarIgual(4, (int) ($linea['filas_mostradas'] ?? 0), 'Y cuantas filas ha visto');

    // ---- Y la pantalla sale ------------------------------------------------
    $html = htmlDeAccion('ControladorSeguimiento', 'panel', ['id' => $id]);
    comprobarNoContiene($html, 'Notice:', 'El panel se pinta sin avisos de PHP');
    comprobarNoContiene($html, 'Warning:', 'ni avisos de tipo Warning');
    comprobarContiene($html, 'Como va la campana', 'Enseña las cifras de la campana');
    comprobarContiene($html, 'Historial de auditoria', 'Enseña el historial de auditoria');
    comprobarContiene(
        $html,
        '/seguimiento/cerrar',
        'Enseña el formulario de cierre, porque la campana esta activa'
    );

    // El filtro llega a la vista por la URL y la vista lo pinta marcado. Se
    // comprueba el valor, no solo que el campo exista, porque un desplegable que
    // no recuerda lo que se eligio es la forma de que alguien filtre dos veces sin
    // querer y no llegue a ver nada.
    //
    // El filtro se pone en $_GET a mano y no en los parametros de la ruta, porque
    // asignarParametros() solo rellena los marcadores del patron —el «id»— y los
    // filtros de la URL los lee el controlador de $_GET, como los leeria el
    // navegador. Pasarlos por ahi haria que esta comprobacion no probara nada: la
    // pantalla saldria sin filtro y el filtro se perderia en silencio.
    limpiarPeticion();
    $_GET['tramo'] = (string) (int) $tramos[1];
    $htmlConFiltro = htmlDeAccion('ControladorSeguimiento', 'panel', ['id' => $id]);

    comprobar(
        preg_match('/<option value="' . (int) $tramos[1] . '"\s+selected/', $htmlConFiltro) === 1,
        'El desplegable de tramos recuerda el tramo que se ha filtrado'
    );
    comprobarContiene(
        $htmlConFiltro,
        'con el filtro puesto',
        'Y la pantalla avisa de que el listado va filtrado'
    );
    comprobarContiene(
        $htmlConFiltro,
        'Quitar filtros',
        'Y ofrece quitar el filtro, que solo tiene sentido si hay uno'
    );

    // ---- El campo de fecha empieza vacio -----------------------------------
    // El listado se pinta entero y sin filtrar, asi que el campo de fecha tiene
    // que estar vacio. Ponerle la fecha de hoy «ayudaria», pero estaria mintiendo:
    // quien mirase el campo creeria que hay un filtro puesto, y al pulsar
    // «Aplicar» se quedaria viendo un listado filtrado sin haberlo pedido.
    comprobar(
        preg_match('/<input[^>]*name="fecha"[^>]*value=""\s*>/', $html) === 1
        || preg_match('/<input[^>]*value=""\s*[^>]*name="fecha"/', $html) === 1,
        'El campo de fecha se pinta vacio cuando no hay filtro'
    );

    // ---- Y el calendario que ya no cuadra con el plan ----------------------
    // El apartado 8 pide que el panel enseñe las diferencias detectadas al
    // modificar el calendario. Aqui no hay ninguna, y es lo correcto: el
    // generador reparte segun el plan, de modo que un plan de dos unidades por
    // premio y tramo produce exactamente ese calendario. Comprobar que aqui no
    // hay aviso cuando no lo hay seria la mitad del trabajo.
    //
    // La diferencia se provoca a mano, y de la manera mas parecida a la que pasa
    // en una campana de verdad: se toca el plan despues de generar. Si en vez de
    // eso se escribieran unidades sueltas a mano, la comparacion tambien saltaria
    // pero por un motivo distinto —un calendario editado a pelo— y la prueba
    // estaria comprobando otra cosa. Subir la cantidad del plan deja el
    // calendario intacto, que es lo que se ve a mitad de campana.
    comprobarIgual(
        [],
        $panel['comparacion'],
        'Con el calendario recien generado no hay diferencias que avisar'
    );

    $db->ejecutar(
        'UPDATE asignaciones_tramo
            SET cantidad = cantidad + 1
          WHERE tramo_id = ? AND tipo_premio_id = ?',
        [(int) $tramos[0], (int) $tipos[0]]
    );

    $desajustado = $seguimiento->panel($id, [], $momento);

    comprobar(
        $desajustado['comparacion'] !== [],
        'En cuanto el plan ya no cuadra, el panel avisa'
    );

    comprobarIgual(
        1,
        count($desajustado['comparacion']),
        'Y avisa solo de la pareja que se ha tocado, no de todas'
    );

    comprobar(
        (bool) $desajustado['comparacion'][0]['cambia'],
        'La fila que avisa viene marcada como desajustada'
    );
    comprobarIgual(
        1,
        (int) $desajustado['comparacion'][0]['faltan'],
        'Y dice que falta una unidad, que es lo que se ha pedido de mas'
    );
    comprobar(
        (string) $desajustado['comparacion'][0]['tramo_nombre'] !== ''
        && (string) $desajustado['comparacion'][0]['tipo_nombre'] !== '',
        'Con nombre de tramo y de premio, que si no el aviso no dice de quien'
    );

    limpiarPeticion();
    $html = htmlDeAccion('ControladorSeguimiento', 'panel', ['id' => $id]);
    comprobarContiene(
        $html,
        'El calendario ya no es el que se planeo',
        'Y la pantalla enseña la tabla de diferencias'
    );
    comprobarContiene(
        $html,
        'faltan',
        'Diciendo cuantas unidades faltan'
    );

    limpiarPeticion();
    borrarEscenarioDePanel($id);
}

/**
 * Cierra una campana de prueba con el instante que se le pase.
 *
 * Se escribe a mano y no se llama a CierrePromocion a proposito. El servicio de
 * cierre deja el instante en el momento en que se llama, que hoy, y una prueba que
 * necesita una campana «cerrada hace dos años» no puede esperar a que el reloj
 * llegue ahi. Ademas, pasar por el cierre real traeria el estado del plan de
 * premios y el resto de comprobaciones del cierre, que no son lo que se prueba
 * aqui: lo que se prueba es la purga.
 *
 * @param int    $promocionId Campana que se cierra.
 * @param string $cerradaEn   Instante en que se considera cerrada.
 * @param int|null $retencionDias Dias que se conservan, o null para no purgar nunca.
 *
 * @return void
 */
function cerrarParaPurga(int $promocionId, string $cerradaEn, ?int $retencionDias): void
{
    $db = Aplicacion::db();

    $db->ejecutar(
        'UPDATE promociones
            SET estado = ?,
                cerrada_en = ?,
                retencion_dias = ?,
                actualizada_en = ?
          WHERE id = ?',
        [
            \App\Models\Promocion::ESTADO_FINALIZADA,
            $cerradaEn,
            $retencionDias,
            $cerradaEn,
            $promocionId,
        ]
    );
}

/**
 * Crea una participacion con datos personales, para probar la purga.
 *
 * @param int    $promocionId Campana a la que pertenece.
 * @param int    $tramoId     Tramo en el que se registro.
 * @param string $semilla     Semilla de la clave de idempotencia.
 * @param string $datos       Datos personales en JSON.
 * @param string $momento     Instante de la participacion.
 *
 * @return int Identificador de la participacion creada.
 */
function participarParaPurga(int $promocionId, int $tramoId, string $semilla, string $datos, string $momento): int
{
    $db = Aplicacion::db();

    return $db->insertar(
        'INSERT INTO participaciones (
             promocion_id, tramo_id, clave_idempotencia, clave_unicidad,
             momento, resultado, datos, datos_normalizados, creado_en
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $promocionId,
            $tramoId,
            claveDePrueba($semilla),
            hash('sha256', $semilla),
            $momento,
            \App\Models\Participacion::RESULTADO_SIN_PREMIO,
            $datos,
            '{"dni":"87654321X"}',
            $momento,
        ]
    );
}

/**
 * Ejecuta una operacion y devuelve el mensaje del error de aplicacion que lance.
 *
 * Las comprobaciones de que una purga NO ocurre necesitan esto. Antes se
 * comprobaba que el metodo devolviera null, y era un error de la prueba, no del
 * codigo: devolver null cuando alguien pide purgar una campana en concreto
 * significa «no hay nada que hacer», y quien lo pide no puede saber si la
 * campana ya estaba purgada, si no tenia datos o si el guion esta roto. Un error
 * con el motivo en el mensaje es lo que hace falta.
 *
 * @param callable():mixed $operacion Operacion que se espera que falle.
 * @param string           $fragmento  Parte del mensaje que tiene que aparecer.
 * @param string           $descripcion Texto de la comprobacion si pasa.
 *
 * @return bool True si el error que se ha lanzado contiene el fragmento.
 */
function mensajeDeError(callable $operacion, string $fragmento, string $descripcion): bool
{
    try {
        $operacion();
    } catch (\App\Core\ErrorAplicacion $e) {
        $cumple = str_contains($e->getMessage(), $fragmento);
        comprobar($cumple, $descripcion, 'El mensaje fue: ' . $e->getMessage());

        return $cumple;
    }

    comprobar(false, $descripcion, 'La operacion no ha lanzado ningun error');

    return false;
}

/**
 * Prueba la purga de retencion: cuando se vacia, cuando no, y que sobrevive.
 *
 * El caso va de menos a mas. Primero se comprueba que NO se purga lo que no
 * debe —una campana dentro de su plazo, una sin plazo, una que sigue abierta—,
 * porque si el filtro de fechas fallara, todo lo demas pasaria igual y el fallo
 * quedaria escondido. Y al final se comprueba que se purga dos veces sin hacer
 * nada la segunda, que es la propiedad de la que depende que un trabajo de cron
 * se pueda repetir cada noche.
 *
 * @return void
 */
function caso16(): void
{
    echo 'Caso 16: la purga vacia los datos personales cuando vence el plazo', PHP_EOL;

    $db = Aplicacion::db();
    $purgador = new \App\Services\Purgador();
    $hoy = date('Y-m-d');

    // ======================================================================
    // Una campana que todavia esta dentro de su plazo no se purga. Es la
    // comprobacion que va primera a proposito: si el filtro de fechas fallara,
    // todo lo demas de este caso pasaria igual y el fallo estaria escondido.
    // ======================================================================
    $dentro = crearEscenarioDePanel(['sufijo' => 'purgada-dentro']);
    $idDentro = (int) $dentro['promocion'];

    // Cerrada hace un dia, con quince dias de retencion. El plazo no ha vencido.
    cerrarParaPurga($idDentro, date('Y-m-d', strtotime('-1 day')) . ' 10:00:00', 15);

    $pId = participarParaPurga(
        $idDentro,
        (int) $dentro['tramos'][0],
        'caso16-dentro',
        '{"dni":"11111111X","nombre":"Persona Dentro"}',
        $hoy . ' 09:00:00'
    );

// No se espera un null: el servicio lanza un error con el motivo. Devolver
    // null cuando alguien pide purgar una campana en concreto significaria «no hay
    // nada que hacer», y quien lo pide no sabria si ya estaba purgada, si no
    // tenia datos o si el guion esta roto.
    mensajeDeError(
        static fn (): ?array => $purgador->purgarCampana($idDentro, Aplicacion::ahora(), false),
        'plazo de retencion',
        'Una campana dentro de su plazo no se purga, y se dice por que'
    );

    $participacion = $db->uno('SELECT datos, purgada_en FROM participaciones WHERE id = ?', [$pId]);
    comprobar(
        $participacion !== null && (string) $participacion['datos'] !== '{}',
        'Y sus datos siguen intactos, que es lo importante'
    );
    comprobar(
        $participacion !== null && $participacion['purgada_en'] === null,
        'Sin marca de purga, porque no se ha purgado'
    );

    borrarEscenarioDePanel($idDentro);

    // ======================================================================
    // Una campana sin plazo de retencion no se purga nunca, aunque lleva
    // cerrada mas de un ano.
    // ======================================================================
    $sinPlazo = crearEscenarioDePanel(['sufijo' => 'purgada-sin-plazo']);
    $idSinPlazo = (int) $sinPlazo['promocion'];

    cerrarParaPurga($idSinPlazo, date('Y-m-d', strtotime('-400 days')) . ' 10:00:00', null);

    mensajeDeError(
        static fn (): ?array => $purgador->purgarCampana($idSinPlazo, Aplicacion::ahora(), false),
        'no tiene plazo de retencion',
        'Una campana sin plazo de retencion no se purga, por muy cerrada que este'
    );

    borrarEscenarioDePanel($idSinPlazo);

    // ======================================================================
    // Una campana activa no se purga, porque el plazo se cuenta desde el
    // cierre y una campana activa no esta cerrada.
    // ======================================================================
    $activa = crearEscenarioDePanel(['sufijo' => 'purgada-activa']);
    $idActiva = (int) $activa['promocion'];

    $db->ejecutar(
        'UPDATE promociones SET retencion_dias = 1 WHERE id = ?',
        [$idActiva]
    );

    mensajeDeError(
        static fn (): ?array => $purgador->purgarCampana($idActiva, Aplicacion::ahora(), false),
        'no esta cerrada',
        'Una campana que sigue activa no se purga aunque su retencion sea de un dia'
    );

    borrarEscenarioDePanel($idActiva);

    // ======================================================================
    // Y ahora la campana que si se purga: cerrada hace treinta dias, con
    // quince de retencion.
    // ======================================================================
    $escenario = crearEscenarioDePanel(['sufijo' => 'purgada']);
    $id = (int) $escenario['promocion'];
    $tramo = (int) $escenario['tramos'][0];
    $cerrada = date('Y-m-d', strtotime('-30 days')) . ' 10:00:00';

    cerrarParaPurga($id, $cerrada, 15);

    $ganadora = participarParaPurga(
        $id,
        $tramo,
        'caso16-ganadora',
        '{"dni":"22222222Y","nombre":"Persona Ganadora","email":"ganadora@ejemplo.es"}',
        date('Y-m-d', strtotime('-30 days')) . ' 11:00:00'
    );

    $perdida = participarParaPurga(
        $id,
        $tramo,
        'caso16-perdida',
        '{"dni":"33333333Z","nombre":"Persona Perdida"}',
        date('Y-m-d', strtotime('-30 days')) . ' 11:30:00'
    );

    // Un rechazo: no tiene datos personales, pero si una huella de identidad.
    $db->insertar(
        'INSERT INTO intentos_rechazados (
             promocion_id, tramo_id, clave_idempotencia, clave_identidad,
             motivo_codigo, motivo_texto, momento
         ) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [
            $id,
            $tramo,
            claveDePrueba('caso16-rechazo'),
            hash('sha256', 'caso16-rechazo'),
            'dni_duplicado',
            'El DNI ya habia participado',
            date('Y-m-d', strtotime('-30 days')) . ' 12:00:00',
        ]
    );

    // ---- Correos: uno enviado, uno con error y uno pendiente ---------------
    // El pendiente es la parte importante del caso. Si la purga lo vaciara, el
    // worker lo enviaria despues y la tienda recibiria un correo sin cuerpo y
    // sin destinatario, con el codigo de reclamacion perdido. Se comprueba
    // expressly que sobrevive intacto.
    $correoEnviado = $db->insertar(
        'INSERT INTO correos (
             promocion_id, tipo, destinatario, asunto, cuerpo, variables,
             transporte, estado, intentos, enviado_en, creado_en
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $id,
            'ganador',
            'ganadora@ejemplo.es',
            'Has ganado',
            'Tu codigo de reclamacion es ABC-123, ' . 'Persona Ganadora',
            '{"nombre":"Persona Ganadora"}',
            'log',
            'enviado',
            1,
            date('Y-m-d', strtotime('-30 days')) . ' 13:00:00',
            date('Y-m-d', strtotime('-30 days')) . ' 12:30:00',
        ]
    );

    $correoError = $db->insertar(
        'INSERT INTO correos (
             promocion_id, tipo, destinatario, asunto, cuerpo, variables,
             transporte, estado, intentos, ultimo_error, creado_en
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $id,
            'no_ganador',
            'perdida@ejemplo.es',
            'Gracias por participar',
            'Persona Perdida',
            '{"nombre":"Persona Perdida"}',
            'smtp',
            'error',
            3,
            'Conexion rechazada',
            date('Y-m-d', strtotime('-30 days')) . ' 12:30:00',
        ]
    );

    $correoPendiente = $db->insertar(
        'INSERT INTO correos (
             promocion_id, tipo, destinatario, asunto, cuerpo, variables,
             transporte, estado, intentos, creado_en
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $id,
            'ganador',
            'pendiente@ejemplo.es',
            'Has ganado',
            'Tu codigo de reclamacion es XYZ-789, Persona Pendiente',
            '{"nombre":"Persona Pendiente"}',
            'log',
            'pendiente',
            0,
            date('Y-m-d', strtotime('-30 days')) . ' 12:30:00',
        ]
    );

    // ---- La simulacion no escribe nada ------------------------------------
    $simulacion = $purgador->purgarCampana($id, Aplicacion::ahora(), true);

    comprobarIgual(2, (int) $simulacion['participaciones'], 'La simulacion cuenta las dos participaciones');
    comprobarIgual(2, (int) $simulacion['correos'], 'Y los dos correos ya despachados');
    comprobarIgual(1, (int) $simulacion['rechazos'], 'Y el rechazo con huella');

    $antesDeSimular = $db->uno('SELECT datos FROM participaciones WHERE id = ?', [$ganadora]);
    comprobar(
        $antesDeSimular !== null && (string) $antesDeSimular['datos'] !== '{}',
        'La simulacion no ha vaciado ninguna participacion'
    );

    $auditoriasAntes = (int) $db->valor(
        'SELECT COUNT(*) FROM auditoria WHERE promocion_id = ? AND accion = ?',
        [$id, \App\Models\Auditoria::ACCION_PURGA]
    );
    comprobarIgual(0, $auditoriasAntes, 'Y no ha escrito ninguna auditoria');

    // ---- Ahora la purga de verdad -----------------------------------------
    $detalle = $purgador->purgarCampana($id, Aplicacion::ahora(), false);

    comprobar($detalle !== null, 'La campana vencida se purga');
    comprobarIgual(2, (int) $detalle['participaciones'], 'Vacia las dos participaciones');
    comprobarIgual(2, (int) $detalle['correos'], 'Y los dos correos despachados');
    comprobarIgual(1, (int) $detalle['rechazos'], 'Y el rechazo con huella');

    // ---- Lo vaciado --------------------------------------------------------
    $tras = $db->uno('SELECT * FROM participaciones WHERE id = ?', [$ganadora]);
    comprobarIgual('{}', (string) $tras['datos'], 'La participacion se queda con un JSON vacio');
    comprobarIgual(null, $tras['datos_normalizados'], 'Y sin sus formas normalizadas');
    comprobarIgual(null, $tras['clave_unicidad'], 'Y sin la huella de unicidad');
    comprobar(
        $tras['purgada_en'] !== null,
        'Y con la marca de purga puesta'
    );
    comprobar(
        str_starts_with((string) $tras['purgada_en'], date('Y-m-d')),
        'La marca de purga lleva la fecha de la pasada, no una fecha inventada'
    );

    // Lo que NO se vacia: la fila sigue y con ella el rastro del sorteo.
    comprobarIgual('sin_premio', (string) $tras['resultado'], 'La fila conserva su resultado');
    comprobarIgual(
        $tramo,
        (int) $tras['tramo_id'],
        'Y el tramo en el que se registro, que es lo que demuestra que ocurrio'
    );
    comprobar(
        (string) $tras['momento'] !== '',
        'Y el momento exacto de la participacion'
    );

    // El DNI no aparece en ninguna parte de la participacion.
    comprobar(
        strpos((string) $db->valor('SELECT datos FROM participaciones WHERE id = ?', [$perdida]), '33333333Z') === false,
        'El DNI de la participacion perdida tampoco aparece ya en la base'
    );

    // ---- Correos: los despachados vacios, el pendiente intacto -----------
    $correo = $db->uno('SELECT * FROM correos WHERE id = ?', [$correoEnviado]);
    comprobarIgual('', (string) $correo['destinatario'], 'El correo enviado se queda sin destinatario');
    comprobarIgual('', (string) $correo['cuerpo'], 'Y sin cuerpo');
    comprobarIgual(null, $correo['variables'], 'Y sin las variables con las que se monto');
    comprobarIgual('log', (string) $correo['transporte'], 'Pero conserva el transporte');
    comprobarIgual(1, (int) $correo['intentos'], 'Y los intentos que costo');
    comprobarIgual('enviado', (string) $correo['estado'], 'Y su estado');
    comprobar($correo['purgada_en'] !== null, 'Con la marca de purga puesta');

    $fallido = $db->uno('SELECT * FROM correos WHERE id = ?', [$correoError]);
    comprobarIgual('', (string) $fallido['cuerpo'], 'El correo con error tambien se vacia');
    comprobarIgual('Conexion rechazada', (string) $fallido['ultimo_error'], 'Y conserva el motivo del fallo');

    $sigue = $db->uno('SELECT * FROM correos WHERE id = ?', [$correoPendiente]);
    comprobarIgual(
        'pendiente@ejemplo.es',
        (string) $sigue['destinatario'],
        'El correo PENDIENTE conserva su destinatario, porque todavia puede salir'
    );
    comprobar(
        str_contains((string) $sigue['cuerpo'], 'XYZ-789'),
        'Y conserva su cuerpo con el codigo de reclamacion, que si no se perderia'
    );
    comprobarIgual(null, $sigue['purgada_en'], 'Y no lleva marca de purga');

    // ---- Rechazos ----------------------------------------------------------
    $rechazo = $db->uno('SELECT * FROM intentos_rechazados WHERE promocion_id = ?', [$id]);
    comprobarIgual(null, $rechazo['clave_identidad'], 'El rechazo se queda sin huella de identidad');
    comprobarIgual('dni_duplicado', (string) $rechazo['motivo_codigo'], 'Pero conserva el motivo, que es lo que se cuenta');

    // ---- Una sola auditoria, con el recuento ------------------------------
    $asientos = $db->todos(
        'SELECT datos_despues FROM auditoria WHERE promocion_id = ? AND accion = ?',
        [$id, \App\Models\Auditoria::ACCION_PURGA]
    );
    comprobarIgual(1, count($asientos), 'La purga escribe un unico asiento de auditoria');

    if ($asientos !== []) {
        $datos = json_decode((string) $asientos[0]['datos_despues'], true);
        comprobar(
            is_array($datos) && (int) ($datos['participaciones'] ?? 0) === 2,
            'Y el asiento guarda el recuento de participaciones vaciadas'
        );
        comprobar(
            is_array($datos) && (int) ($datos['correos'] ?? 0) === 2,
            'Y el de correos'
        );
    }

    // Y que el asiento no contiene ningun dato personal.
    comprobar(
        strpos((string) ($asientos[0]['datos_despues'] ?? ''), '87654321') === false
        && strpos((string) ($asientos[0]['datos_despues'] ?? ''), 'ganadora@ejemplo.es') === false,
        'El asiento de auditoria no contiene ningun dato personal, solo el recuento'
    );

    // ---- Idempotencia: purgar dos veces -----------------------------------
    $purgador2 = new \App\Services\Purgador();
    comprobarIgual(
        null,
        $purgador2->purgarCampana($id, Aplicacion::ahora(), false),
        'Purgar una campana ya purgada no hace nada'
    );
    comprobarIgual(
        1,
        (int) $db->valor(
            'SELECT COUNT(*) FROM auditoria WHERE promocion_id = ? AND accion = ?',
            [$id, \App\Models\Auditoria::ACCION_PURGA]
        ),
        'Y no escribe un segundo asiento, que es lo que llenaria el historial'
    );

    // ---- La pasada por listado --------------------------------------------
    // campanasParaPurgar debe ofrecer la campana mientras su plazo no se haya
    // purgado, y el servicio debe saltarsela. Se comprueba que la lista la ve y
    // que el recuento de purgadas no la cuenta.
    $purgador3 = new \App\Services\Purgador();
    $recuento = $purgador3->purgar(Aplicacion::ahora(), 50, false);
    comprobarIgual(
        0,
        (int) $recuento['purgadas'],
        'La pasada por listado no cuenta como purgada una campana que ya lo estaba'
    );

    limpiarPeticion();
    borrarEscenarioDePanel($id);
}

/**
 * Caso 19: dos participaciones simultaneas por HTTP de verdad.
 *
 * ============================================================================
 * QUE COMPRUEBA Y POR QUE HACE FALTA SI EL CASO 7 YA LO HACE
 * ============================================================================
 *
 * El caso 7 ya comprueba que dos personas no se llevan el mismo premio, y lo
 * hace con dos procesos de PHP. Lo que no comprueba es lo que pasa por el
 * camino de verdad: que el servidor atienda las dos peticiones a la vez, que
 * cada una llegue con su sesion, y que el cerrojo de la campana repartir uno y
 * solo uno cuando las dos estan dentro al mismo tiempo.
 *
 * Por eso D6 pide dos peticiones HTTP simultaneas, y por eso este caso usa
 * sockets contra el Apache de XAMPP en vez de llamar a metodos. Es el unico
 * caso de la suite que necesita un servidor de verdad, y por eso se salta a
 * gritos, y no en silencio, cuando no lo encuentra.
 *
 * ============================================================================
 * COMO SE DEMUESTRA LA SIMULTANEIDAD, QUE NO ES LO OBVIO
 * ============================================================================
 *
 * Lo facil seria mandar las dos peticiones y mirar cuanto tardan. No vale: si el
 * servidor las atendiera una detras de otra, los repartos saldrian bien y la
 * prueba pasaria sin haber comprobado nada. Un tiempo de respuesta mas corto
 * que la suma de los dos no distingue «el servidor las atendio a la vez» de «el
 * motor hizo esperar a la segunda», que es justo lo que pasa siempre.
 *
 * Lo que si lo demuestra es mirar dentro. El motor pide un cerrojo con nombre
 * por campana antes de adjudicar. Este caso lo retiene desde la consola, manda
 * las dos peticiones, y cuenta cuantos hilos de MariaDB se quedan esperando ese
 * cerrojo. Si son dos, las dos peticiones estaban dentro del servidor a la vez,
 * y eso no es una opinion: es la cuenta de una tabla del servidor de base de
 * datos. Despues suelta el cerrojo y las dos peticiones continues.
 *
 * Que las dos sesiones sean distintas no es un detalle: PHP bloquea una sesion
 * mientras la tiene ocupada, asi que con la misma cookie la segunda peticion
 * esperaria en la puerta del servidor y este caso no probaria nada.
 *
 * @return void
 */
function caso19(): void
{
    echo 'Caso 19: dos participaciones simultaneas por HTTP de verdad', PHP_EOL;

    // Se quita cualquier apuntado que hubiera dejado una ejecucion anterior
    // interrumpida, antes de tocar nada. Si la maquina se apago a mitad del caso
    // anterior, esto es lo que evita que la aplicacion real siga apuntando a la
    // base de pruebas.
    quitarApuntadoDeApache();

    $servidor = localizarServidorWeb();

    if ($servidor === null) {
        echo '  [OMITIDO] No se ha encontrado ningun servidor web que sirva la aplicacion.', PHP_EOL;
        echo '            El caso 19 manda dos peticiones HTTP de verdad y sin servidor', PHP_EOL;
        echo '            no se puede comprobar. Arranca Apache en XAMPP y vuelve a', PHP_EOL;
        echo '            lanzar la suite. Si la aplicacion se sirve en otra direccion,', PHP_EOL;
        echo '            se indica con la variable SORTEOS_URL.', PHP_EOL;
        return;
    }

    echo '  -> servidor: ' . $servidor['host'] . ':' . $servidor['puerto'] . $servidor['prefijo'],
        PHP_EOL;

    borrarEscenarioDeAdjudicacion();

    $config = Aplicacion::config();
    $bdPruebas = (string) $config['bd']['nombre'];
    $nombreCookie = (string) ($config['sesion']['nombre'] ?? 'SORTEOSSID');

    // Una unidad disponible desde el principio del dia. Por HTTP el momento es
    // el de verdad, que no se puede elegir como en el caso 7, asi que la unidad
    // tiene que estar pendiente a cualquier hora en que se lance la suite.
    $escenario = crearEscenarioDeAdjudicacion(['00:00:00']);
    $promocionId = (int) $escenario['promocion'];

    $rutaConfig = '';
    $contrasena = 'prueba-caso19';
    $nombres = ['caso19-uno', 'caso19-dos'];
    $sesiones = [];
    $sockets = [];
    $respuestas = [];

    // Red de seguridad para el caso de que el caso se interrupta con una
    // excepcion: el .htaccess se queda apuntando a un fichero que esta a punto de
    // borrarse, y la aplicacion real dejaria de arrancar. Se registra aqui y no
    // al final, porque un throw salta directamente al manejador del caso.
    //
    // No basta con quitar el SetEnv. Si el caso se cae con las dos peticiones HTTP
    // dentro del servidor, esas peticiones siguen adjudicando mientras la limpieza
    // borra la campana, y el borrado se come la clave foranea de una participacion
    // que acaba de aparecer. Por eso la red cierra antes los sockets, que es lo
    // que hace que Apache aborte las peticiones, y solo despues borra. Cerrarlos
    // es lo que hace un navegador al cerrar la pestana, y por eso funciona.
    register_shutdown_function(static function () use (&$sockets, &$nombres, $promocionId): void {
        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        $sockets = [];

        try {
            borrarEscenarioDeAdjudicacion();
        } catch (Throwable $error) {
            // Se avisa, pero no se relanza: relanzar aqui tapa el fallo real que
            // todavia no se ha impreso, que es el que hay que arreglar.
            fwrite(STDERR, '[pruebas] La limpieza del caso 19 no ha podido borrar la campana: '
                . $error->getMessage() . PHP_EOL);
        }

        $db = \App\Core\Aplicacion::db();

        foreach ($nombres as $nombre) {
            $db->ejecutar('DELETE FROM usuarios WHERE nombre = ?', [$nombre]);
        }

        quitarApuntadoDeApache();
        borrarConfiguracionDePruebas(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sorteos-config-pruebas.php'
        );
    });

    try {
        $rutaConfig = escribirConfiguracionDePruebas($bdPruebas);
        apuntarApacheAConfiguracion($rutaConfig);

        // ---- Dos cuentas de azafata y dos sesiones -------------------------
        foreach ($nombres as $indice => $nombre) {
            (new \App\Models\User())->crear(
                $nombre,
                $contrasena,
                \App\Core\Autorizacion::ROL_AZAFATA,
                'Azafata de prueba del caso 19',
                $promocionId
            );

            $sesiones[$indice] = sesionDeAzafata(
                $servidor,
                $nombre,
                $contrasena,
                $nombreCookie,
                $promocionId
            );
        }

        comprobar(
            isset($sesiones[0], $sesiones[1]) && $sesiones[0]['cookie'] !== ''
                && $sesiones[1]['cookie'] !== '',
            'Las dos azafatas han entrado con sesiones distintas'
        );

        comprobar(
            isset($sesiones[0], $sesiones[1]) && $sesiones[0]['cookie'] !== $sesiones[1]['cookie'],
            'Las dos sesiones son de verdad distintas, y no la misma cookie dos veces'
        );

        if (!isset($sesiones[0], $sesiones[1])
            || $sesiones[0]['cookie'] === ''
            || $sesiones[1]['cookie'] === ''
            || $sesiones[0]['ruta'] === ''
            || $sesiones[1]['ruta'] === ''
        ) {
            echo '  -> no se ha podido entrar en las dos cuentas, el caso se para aqui', PHP_EOL;
            return;
        }

        // ---- La barrera: el cerrojo retenido desde la consola --------------
        $db = Aplicacion::db();

        comprobar(
            $db->bloquearPromocion($promocionId, 30),
            'La consola ha retenido el cerrojo de adjudicacion de la campana'
        );

        try {
            // Las dos peticiones se escriben una detras de otra y no se lee
            // ninguna. Al no leer, la segunda sale mientras la primera sigue
            // sin contestar, que es lo que las hace simultaneas.
            foreach ($sesiones as $indice => $sesion) {
                $socket = abrirSocketWeb((string) $servidor['host'], (int) $servidor['puerto']);

                comprobar(
                    is_resource($socket),
                    'Se ha abierto la conexion de la peticion ' . ($indice + 1)
                );

                if (!is_resource($socket)) {
                    continue;
                }

                escribirPeticionWeb(
                    $socket,
                    (string) $servidor['host'],
                    (int) $servidor['puerto'],
                    // El prefijo se pone aqui a mano porque se llama a
                    // escribirPeticionWeb() directamente y no a peticionWeb(),
                    // que es quien lo antepondria.
                    (string) $servidor['prefijo'] . $sesion['ruta'],
                    $sesion['campos'],
                    cabeceraDeCookie($nombreCookie, $sesion['cookie'])
                );

                $sockets[$indice] = $socket;
            }

            // Ahora se mira dentro. Se espera poco a proposito: el motor da el
            // cerrojo por perdido a los cinco segundos y responderia «intente de
            // nuevo», que es contencion y no un fallo de adjudicacion.
            $vistas = esperarBloqueoCompartido(2, 2.0);

            comprobar(
                $vistas >= 2,
                'Las DOS peticiones estaban dentro del servidor a la vez, esperando el cerrojo',
                'Se ha visto como mucho ' . $vistas . ' peticion(es) esperando a la vez. Con una '
                    . 'sola, el servidor las ha atendido encoladas y este caso no demuestra '
                    . 'nada sobre la simultaneidad.'
            );
        } finally {
            $db->liberarBloqueoPromocion($promocionId);
        }

        foreach ($sockets as $indice => $socket) {
            $respuestas[$indice] = leerRespuestaWeb($socket);
        }

        // ---- Que las dos hayan respondido con la pantalla de resultado ----
        foreach ($respuestas as $indice => $respuesta) {
            comprobar(
                $respuesta['estado'] === 200,
                'La peticion ' . ($indice + 1) . ' ha contestado con estado 200',
                $respuesta['caducada']
                    ? 'La lectura se ha quedado sin respuesta. Suele querer decir que el '
                        . 'cerrojo se ha retenido mas de lo que el motor aguanta.'
                    : 'Ha contestado con el estado ' . $respuesta['estado']
            );

            comprobar(
                !$respuesta['caducada'],
                'La peticion ' . ($indice + 1) . ' ha contestado dentro de tiempo'
            );
        }

        if (count($respuestas) !== 2) {
            comprobar(false, 'Sin las dos respuestas no se puede comprobar el reparto');
            return;
        }

        $resultados = array_map('resultadoDePantalla', array_column($respuestas, 'cuerpo'));

        comprobarIgual(
            1,
            count(array_keys($resultados, 'premio', true)),
            'De las dos participaciones por HTTP, solo UNA recibe el premio'
        );

        comprobarIgual(
            1,
            count(array_keys($resultados, 'sin_premio', true)),
            'La otra se queda sin premio por HTTP, en vez de desaparecer'
        );

        // ---- Y la tabla de unidades lo confirma, como en el caso 7 ----------
        $estados = (new \App\Models\UnidadPremio())->contarPorEstado($promocionId);
        comprobarIgual(1, $estados['entregada'] ?? 0, 'Hay exactamente UNA unidad entregada, la que habia');
        comprobarIgual(0, $estados['programada'] ?? 0, 'No queda ninguna unidad programada');

        $porResultado = (new \App\Models\Participacion())->contarPorResultado($promocionId);
        comprobarIgual(1, $porResultado['premio'] ?? 0, 'Solo una participacion queda con resultado «premio»');
        comprobarIgual(
            1,
            $porResultado['sin_premio'] ?? 0,
            'La otra queda con resultado «sin premio», y ninguna con «rechazada»'
        );
        comprobarIgual(
            0,
            $porResultado['rechazada'] ?? 0,
            'Ninguna de las dos se ha rechazado, que seria otra cosa distinta'
        );

        $claves = (int) $db->valor(
            'SELECT COUNT(DISTINCT clave_idempotencia) FROM participaciones WHERE promocion_id = ?',
            [$promocionId]
        );

        comprobarIgual(2, $claves, 'Las dos participaciones vienen de dos intentos distintos');
    } finally {
        quitarApuntadoDeApache();
        borrarConfiguracionDePruebas($rutaConfig);

        foreach ($sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        borrarEscenarioDeAdjudicacion();

        // Las cuentas se borran aqui y no en borrarEscenarioDeAdjudicacion porque
        // la tabla de usuarios no cuelga de la campana: al borrarla, su
        // promocion_id se queda a NULL y las cuentas se quedarian colgando en la
        // base de pruebas para siempre.
        $db = Aplicacion::db();

        foreach ($nombres as $nombre) {
            $db->ejecutar('DELETE FROM usuarios WHERE nombre = ?', [$nombre]);
        }
    }
}

/**
 * Entra por HTTP como una azafata y deja preparada la peticion de participación.
 *
 * Hace los tres pasos de un navegador: pedir el formulario de acceso, mandarlo
 * con el nombre y la contrasena, y pedir despues el formulario de participación.
 * Del ultimo se queda el token y la clave de intento tal y como los ha enviado
 * el servidor.
 *
 * @param array<string, mixed> $servidor    Host, puerto y prefijo, como
 *                                          devuelve localizarServidorWeb().
 * @param string               $nombre      Nombre de acceso de la azafata.
 * @param string               $contrasena  Contrasena de la azafata.
 * @param string               $nombreCookie Nombre de la cookie de sesion.
 * @param int                  $promocionId Campana a la que esta asignada.
 *
 * @return array<string, mixed> «cookie» con el valor de la sesion, «ruta» con
 *                              la ruta de participación y «campos» con lo que
 *                              hay que mandar.
 */
function sesionDeAzafata(
    array $servidor,
    string $nombre,
    string $contrasena,
    string $nombreCookie,
    int $promocionId
): array {
    $vacio = ['cookie' => '', 'ruta' => '', 'campos' => []];

    $acceso = peticionWeb($servidor, '/login');

    if ($acceso['estado'] !== 200) {
        return $vacio;
    }

    $campos = camposDeFormulario($acceso['cuerpo']);

    if (!isset($campos['csrf_token'])) {
        return $vacio;
    }

    // La cookie de la sesion se arrastra al enviar el acceso. Sin ella, la
    // peticion llega sin sesion, el token no tiene contra que compararse y el
    // acceso se responde con un 403 que no dice nada de por que.
    $cookiePrevia = valorDeCookie($acceso['cabeceras'], $nombreCookie);

    $campos['nombre'] = $nombre;
    $campos['contrasena'] = $contrasena;

    $entrada = peticionWeb(
        $servidor,
        '/login',
        [
            'campos' => $campos,
            'cookie' => cabeceraDeCookie($nombreCookie, $cookiePrevia),
        ]
    );

    // La sesion se toma de la respuesta del acceso y no de la del formulario: al
    // entrar se cambia el identificador de sesion, para que una cookie puesta a
    // mano antes de entrar no sirva de nada.
    $cookie = valorDeCookie($entrada['cabeceras'], $nombreCookie);

    if ($cookie === '') {
        return $vacio;
    }

    $ruta = '/azafata/promociones/' . $promocionId . '/participar';
    $formulario = peticionWeb(
        $servidor,
        $ruta,
        ['cookie' => cabeceraDeCookie($nombreCookie, $cookie)]
    );

    if ($formulario['estado'] !== 200) {
        return $vacio;
    }

    $camposParticipacion = camposDeFormulario($formulario['cuerpo']);

    // Si no hay token ni clave de intento no se ha llegado al formulario de
    // participación, sino a otra pagina. Lo mas probable es que el acceso haya
    // fallado y esto sea la pantalla de acceso otra vez.
    if (!isset($camposParticipacion['csrf_token'], $camposParticipacion['idempotencia'])) {
        return $vacio;
    }

    return ['cookie' => $cookie, 'ruta' => $ruta, 'campos' => $camposParticipacion];}

/**
 * Deduce el resultado de una participación de la pantalla que ha devuelto.
 *
 * No mira la base de datos, que es lo que ya comprueban las comprobaciones de
 * despues: mira el HTML, para comprobar que lo que sale por HTTP dice lo que
 * veria la azafata en la tablet.
 *
 * @param string $html Cuerpo de la respuesta.
 *
 * @return string «premio», «sin_premio», «rechazada» o «desconocido».
 */
function resultadoDePantalla(string $html): string
{
    if (strpos($html, 'Enhorabuena') !== false) {
        return 'premio';
    }

    if (strpos($html, 'Gracias por participar') !== false) {
        return 'sin_premio';
    }

    if (strpos($html, 'no se ha registrado') !== false) {
        return 'rechazada';
    }

    return 'desconocido';
}

/**
 * Caso 20: las imagenes de la campana, que no se probaban en ningun sitio.
 *
 * app/Services/Imagenes.php es la parte del proyecto que decide que ficheros
 * se guardan en el disco y que rutas se sirven como imagen. Son dos decisiones
 * de seguridad, no de estilo, y ninguna estaba cubierta. Este caso las cubre,
 * y ademas comprueba contra Apache lo que no se puede comprobar desde consola:
 * que una imagen subida se vea de verdad y que un .php en la misma carpeta no
 * se ejecute.
 *
 * Las dos mitades van separadas a proposito:
 *
 *   1. El servicio, que se puede probar entero sin servidor.
 *   2. Apache, que necesita el servidor y se salta sin el, como el caso 19.
 *
 * @return void
 */
function caso20(): void
{
    echo 'Caso 20: las imagenes se validan y se sirven sin ejecutarse', PHP_EOL;

    $servidor = localizarServidorWeb();
    $escenario = null;

    if ($servidor === null) {
        echo '  [OMITIDO] No hay servidor web: la parte de Apache se omite. '
            . 'La validacion de ficheros y rutas se comprueba igual.', PHP_EOL;
    }

    // La red de seguridad esta antes que nada, igual que en el caso 19, porque
    // un fallo a mitad no debe dejar una campana con imagenes dentro.
    //
    // El .php plantado en uploads/ tambien se quita aqui y no solo en el finally:
    // si el caso muere antes de llegar a plantarlo no hay nada que borrar, y si
    // muere despues, el shutdown lo recoge igual que el finally.
    $campanaId = 0;

    registrarLimpiezaCaso20(static function () use (&$campanaId): void {
        @unlink(\App\Core\Aplicacion::raiz() . 'uploads/caso20-plantado.php');

        if ($campanaId > 0) {
            (new \App\Services\Imagenes())->borrarCampana($campanaId);
        }
    });

    try {
        // =====================================================================
        // PARTE 1. EL SERVICIO, SIN NECESIDAD DE SERVIDOR
        // =====================================================================

        $imagenes = new \App\Services\Imagenes();

        // ---- 1.1. Las rutas que no pueden existir de ninguna manera --------
        //
        // Esta lista va primero por lo mismo que en el caso 18: si una sola de
        // estas comprobaciones pasara por un motivo equivocado, las demas no
        // dirian nada. Cada entrada lleva el motivo por el que se rechaza, que
        // es la parte que no se deduce leyendo el patron.
        $rutasMalas = [
            '../../../config/config.php'      => 'sale de la carpeta con ..',
            '1/../../config.php'             => 'sale de la carpeta con .. en medio',
            '/var/www/config.php'            => 'es una ruta absoluta con barra',
            'C:/xampp/htdocs/sorteos/config.php' => 'es una ruta absoluta de Windows',
            '1\\img.jpg'                     => 'usa contrabarras en vez de barras',
            "1/img.jpg\0.php"               => 'lleva un byte nulo',
            ''                               => 'esta vacia',
            '1/img.php'                      => 'tiene una extension que no es imagen',
            '1/img.svg'                      => 'tiene .svg, que es un XML con script dentro',
            '1/../../1/img.png'             => 'sube y luego baja de la carpeta',
            'imagen.jpg'                     => 'no dice de que campana es',
            '1/sub/carpeta/img.png'          => 'tiene carpetas de mas',
            'espacio /img.png'               => 'tiene un espacio en el nombre de la campana',
        ];

        foreach ($rutasMalas as $ruta => $motivo) {
            comprobar(
                !\App\Services\Imagenes::esRutaValida($ruta),
                'La ruta «' . $ruta . '» se rechaza porque ' . $motivo
            );
        }

        // Las tres primeras deserving de un nombre propio, porque son las que
        // alguien escribiria sin pensar que son un ataque.
        comprobar(
            !\App\Services\Imagenes::esRutaValida('../../config/config.php'),
            'Una ruta con .. no puede leer config/config.php, que es el fichero mas peligroso del proyecto'
        );

        comprobar(
            (new \App\Services\Imagenes())->rutaAbsoluta('../../config/config.php') === '',
            'Y rutaAbsoluta() devuelve cadena vacia en vez de una ruta por la que leerlo'
        );

        comprobar(
            (new \App\Services\Imagenes())->existe('../../config/config.php') === false,
            'Y existe() dice que no, aunque el fichero exista de verdad'
        );

        comprobar(
            (new \App\Services\Imagenes())->borrar('../../config/config.php') === false,
            'Y borrar() se niega a borrar config/config.php'
        );

        // ---- 1.2. Las rutas que si valen ------------------------------------
        //
        // Las de arriba dicen que se rechaza lo malo, pero no que se acepte lo
        // bueno: un patron que rechazase todo pasa esas comprobaciones igual.
        // Estas dos lo tapan, con la extension que devuelve guardar() mas
        // arriba y con las de la configuracion.
        $config = \App\Core\Aplicacion::config();
        $unaExtension = (string) $config['archivos']['extensiones'][0];
        $prefijo = (string) $config['archivos']['prefijo'];

        foreach ($config['archivos']['extensiones'] as $extension) {
            comprobar(
                \App\Services\Imagenes::esRutaValida('7/' . $prefijo . 'a1b2c3.' . $extension),
                'Una ruta con la extension admitida «' . $extension . '» es valida'
            );
        }

        comprobar(
            \App\Services\Imagenes::esRutaValida('7/' . $prefijo . 'a1b2c3.' . $unaExtension),
            'Una ruta con el nombre que genera el servicio es valida'
        );

        // ---- 1.3. Un fichero vacio no se guarda ------------------------------
        $vacio = sys_get_temp_dir() . '/caso20-vacio.png';
        file_put_contents($vacio, '');

        $errorVacio = '';
        try {
            (new \App\Services\Imagenes())->guardar($vacio, 999999);
        } catch (\App\Core\ErrorValidacion $e) {
            $errorVacio = $e->errores()['imagen'] ?? $e->getMessage();
        }

        unlink($vacio);

        comprobarContiene($errorVacio, 'vacia', 'Un fichero de cero bytes se rechaza y lo dice');
        comprobar(
            !is_dir(\App\Core\Aplicacion::raiz() . 'uploads/999999'),
            'Y no se crea la carpeta de la campana cuando la subida falla'
        );

        // ---- 1.4. Un script renombrado a .png se rechaza ---------------------
        //
        // Este es el caso que hace que la validacion exista. La extension es
        // la de una imagen y la lista blanca la acepta, asi que lo unica que
        // puede pararlo es leer la cabecera de verdad. El script lleva su
        // propio <script> para que se vea que no es un PNG disguise.
        $falso = sys_get_temp_dir() . '/caso20-falso.png';
        file_put_contents($falso, '<?php echo "ejecutado"; ?><script>alert(1)</script>');

        $errorFalso = '';
        try {
            (new \App\Services\Imagenes())->guardar($falso, 999999);
        } catch (\App\Core\ErrorValidacion $e) {
            $errorFalso = $e->errores()['imagen'] ?? $e->getMessage();
        }

        unlink($falso);

        comprobar(
            $errorFalso !== '',
            'Un script PHP renombrado a .png se rechaza, aunque su extension sea la de una imagen'
        );

        comprobarContiene(
            $errorFalso,
            'imagen',
            'Y el motivo habla de imagen, que es lo que el administrador puede arreglar'
        );

        comprobar(
            !is_file(\App\Core\Aplicacion::raiz() . 'uploads/999999'),
            'Y no queda nada guardado en la carpeta de la campana'
        );

        // ---- 1.5. Una imagen de verdad se guarda y se devuelve la ruta ------
        //
        // Aqui ya se necesita una campana, porque el servicio guarda en la
        // carpeta que lleva el identificador. Se usa la que hay, y se borra al
        // terminar, en la red de seguridad de arriba.
        $escenario = crearEscenarioDeAdjudicacion(['00:00:00']);
        $campanaId = (int) $escenario['promocion'];

        $png = escribirImagenDePrueba(sys_get_temp_dir() . '/caso20-real.png');
        $ruta = (new \App\Services\Imagenes())->guardar($png, $campanaId);
        @unlink($png);

        comprobar(
            \App\Services\Imagenes::esRutaValida($ruta),
            'La ruta que devuelve guardar() es una ruta valida, sin tocar la base de datos para saberlo'
        );

        comprobarIgual(
            $campanaId,
            (int) explode('/', $ruta)[0],
            'La ruta guardada lleva la carpeta de su campana, que es su identificador'
        );

        comprobar(
            (new \App\Services\Imagenes())->existe($ruta),
            'Y el fichero esta en el disco donde la ruta dice'
        );

        // El nombre lo genera el servidor y no el navegador, y esto es lo que
        // impide que dos imagenes con el mismo nombre original se pisen.
        comprobar(
            str_starts_with(basename($ruta), $prefijo),
            'El nombre del fichero lo pone el servicio, con el prefijo de la configuracion'
        );

        comprobarNoContiene(
            basename($ruta),
            'caso20',
            'Y no aparece en el nombre nada de lo que el administrador escribio'
        );

        comprobar(
            filesize((new \App\Services\Imagenes())->rutaAbsoluta($ruta)) > 0,
            'El fichero guardado no esta vacio'
        );

        // ---- 1.6. Dos imagenes del mismo nombre no se pisan ------------------
        $otraRuta = (new \App\Services\Imagenes())->guardar(
            escribirImagenDePrueba(sys_get_temp_dir() . '/caso20-real2.png'),
            $campanaId
        );
        @unlink(sys_get_temp_dir() . '/caso20-real2.png');

        comprobar(
            $otraRuta !== $ruta,
            'Dos imagenes guardadas en la misma campana reciben nombres distintos'
        );

        comprobar(
            (new \App\Services\Imagenes())->existe($ruta) && (new \App\Services\Imagenes())->existe($otraRuta),
            'Y las dos siguen en el disco, sin pisarse'
        );

        // ---- 1.7. Sustituir deja la anterior fuera, pero no antes de tiempo --
        //
        // El orden importa: si se borrara la anterior antes de guardar la
        // nueva, un fallo dejaria la campana sin imagen. Se comprueba que al
        // sustituir sin subir nada, la anterior sigue donde estaba.
        comprobarIgual(
            $ruta,
            $imagenes->sustituir($ruta, '', 'imagen', $campanaId),
            'Si no se sube nada, sustituir() devuelve la ruta anterior sin tocarla'
        );

        comprobar(
            $imagenes->existe($ruta),
            'Y el fichero anterior sigue existiendo, que es lo que evita perderlo'
        );

        $rutaSustituida = $imagenes->sustituir($ruta, $otraRuta, 'imagen', $campanaId);

        comprobarIgual($otraRuta, $rutaSustituida, 'Si se sube otra, sustituir() devuelve la nueva');

        comprobar(
            !$imagenes->existe($ruta),
            'Y borra la anterior, que ya no usa nadie'
        );

        // ---- 1.8. borrarCampana() no sale de uploads -------------------------
        //
        // Se comprueba con un identificador inventado que ademas apunta fuera.
        // borrarCampana() solo recibe un int, asi que el ataque tendria que ir
        // por la comprobacion str_starts_with() que hace, y esto la ejercita.
        comprobarIgual(
            0,
            $imagenes->borrarCampana(0),
            'borrarCampana() con identificador 0 no borra nada'
        );

        comprobar(
            is_file(\App\Core\Aplicacion::raiz() . 'config/config.php'),
            'Y config/config.php sigue en su sitio, que es lo que de verdad importa'
        );

        // ---- 1.9. La extension sale del contenido, no del nombre -------------
        //
        // Se guarda un PNG con nombre .jpg. Si el nombre mandara, se guardaria
        // como .jpg y con extension .jpg seria un fichero que no es lo que dice.
        $conNombreFalso = sys_get_temp_dir() . '/caso20-enga-no.jpg';
        escribirImagenDePrueba($conNombreFalso);

        $rutaConNombreFalso = $imagenes->guardar($conNombreFalso, $campanaId);
        @unlink($conNombreFalso);

        comprobar(
            str_ends_with($rutaConNombreFalso, '.png'),
            'Una imagen de verdad con extension .jpg se guarda como lo que su contenido dice, no como su nombre'
        );

        comprobarContiene(
            $rutaConNombreFalso,
            '/',
            'Y la ruta devuelta es relativa a la carpeta de la campana'
        );

        // =====================================================================
        // PARTE 2. APACHE, QUE NECESITA SERVIDOR
        // =====================================================================
        //
        // Todo lo de arriba es el servicio. Falta lo que solo se puede ver con
        // el servidor delante: que la imagen se sirva de verdad, y que un
        // .php en la misma carpeta no se ejecute. Esto ultimo lo sostiene
        // uploads/.htaccess y no el codigo, asi que probarlo leyendo el fichero
        // no probaria nada.

        if ($servidor === null) {
            echo '  -> la parte de Apache se omite: sin servidor no hay quien sirva el fichero', PHP_EOL;

            return;
        }

        // La URL se compone con el prefijo porque las imagenes cuelgan de la
        // raiz del proyecto, no de una ruta de la aplicacion.
        $imagen = peticionWeb($servidor, '/uploads/' . $rutaConNombreFalso);

        comprobar(
            $imagen['estado'] === 200,
            'La imagen guardada la sirve Apache con estado 200',
            'Ha contestado ' . $imagen['estado'] . '. Si es 403, el .htaccess de la raiz esta '
                . 'bloqueando la carpeta uploads entera y las imagenes del panel no se ven nunca.'
        );

        comprobar(
            $imagen['cabeceras'] !== '',
            'Y llega con cabeceras, que es lo que significa que la ha servido el servidor de ficheros'
        );

        comprobar(
            stripos($imagen['cabeceras'], 'image/') !== false,
            'Y con un tipo MIME de imagen, no como un fichero de texto',
            'Las cabeceras empiezan asi: ' . substr($imagen['cabeceras'], 0, 120)
        );

        // ---- 2.2. Un .php en esa carpeta no se ejecuta ------------------------
        //
        // La ultima barrera de D14 y del apartado 13.2. Se planta un fichero
        // real con una marca que delataria la ejecucion y se pide por HTTP: si
        // Apache lo ejecutara, el cuerpo seria «EJECUTADO».
        $plantado = \App\Core\Aplicacion::raiz() . 'uploads/caso20-plantado.php';

        if (!is_file($plantado)) {
            file_put_contents($plantado, "<?php echo 'MARCA-CASO20-EJECUTADO'; ?>\n");
        }

        // Se pide con POST y no con GET a proposito: si Apache lo ejecutara, el
        // metodo no cambiaria nada, pero mandarlo como POST deja claro que no
        // se esta comprobando solo que un GET no lo ejecuta.
        $ejecutado = peticionWeb($servidor, '/uploads/caso20-plantado.php', ['metodo' => 'POST']);
        $cuerpoEjecutado = (string) $ejecutado['cuerpo'];

        comprobar(
            !str_contains($cuerpoEjecutado, 'MARCA-CASO20-EJECUTADO'),
            'Un .php en la carpeta uploads no se ejecuta: Apache no ha devuelto la marca que lleva dentro',
            'Ha devuelto ' . var_export(substr($cuerpoEjecutado, 0, 120), true)
        );

        comprobar(
            $ejecutado['estado'] === 403,
            'Y contesta 403, que es la respuesta que evita que alguien se entere de que hay un PHP ahi'
        );

        unlink($plantado);

        // ---- 2.3. Las imagenes de verdad se ven en la pantalla de resultado --
        //
        // El caso 8 de la especificacion pide comprobar que lo configurado en el
        // panel aparece en las pantallas. Esta es la comprobacion de que la
        // ruta guardada llega al HTML: que se pinte el <img> con esa ruta.
        $visual = (new \App\Models\ConfiguracionVisual())->leer($campanaId);
        $visual['resultado_premio_ruta'] = $rutaConNombreFalso;
        (new \App\Models\ConfiguracionVisual())->guardar($campanaId, $visual);

        comprobarIgual(
            $rutaConNombreFalso,
            (new \App\Models\ConfiguracionVisual())->leer($campanaId)['resultado_premio_ruta'] ?? '',
            'La ruta guardada se vuelve a leer igual, sin que nada la haya cambiado por el camino'
        );

        // Las tres pantallas del apartado 5 tienen que ensenarsela a la clienta:
        // el formulario, el resultado con premio y el resultado sin premio. La
        // de resultado elige entre las dos imagenes segun lo que salio, asi que
        // hay que pintarla dos veces.
        $htmlResultado = resultadoDePantallaEnCrudo($campanaId, true);

        comprobarContiene(
            $htmlResultado,
            'uploads/' . $rutaConNombreFalso,
            'La pantalla de resultado pinta la imagen de premio que hay configurada'
        );

        comprobar(
            str_contains($htmlResultado, '<img'),
            'Y lo hace con una etiqueta img de verdad, no con el texto de la ruta'
        );

        $htmlSinPremio = resultadoDePantallaEnCrudo($campanaId, false);

        comprobarNoContiene(
            $htmlSinPremio,
            'resultado_premio_ruta',
            'Y la pantalla de resultado sin premio no usa la imagen de premio'
        );

        // ---- 2.4. Los banners salen en las tres pantallas -------------------
        //
        // El panel guardaba banner_sup_ruta y banner_pie_ruta y las telas de
        // participacion no las pintaban: se podian subir dos imagenes, verlas en
        // la vista previa del panel y no aparecer nunca en la campana. El caso 8
        // pide que lo configurado se vea, asi que aqui se comprueba en las tres
        // pantallas del apartado 5.
        $visual['banner_sup_ruta'] = $rutaConNombreFalso;
        $visual['banner_sup_alt'] = 'Cartel de la promocion';
        $visual['banner_pie_ruta'] = $rutaConNombreFalso;
        $visual['banner_pie_alt'] = 'Aviso del pie';
        (new \App\Models\ConfiguracionVisual())->guardar($campanaId, $visual);

        $htmlFormulario = formularioDePantallaEnCrudo($campanaId);
        $htmlResultado = resultadoDePantallaEnCrudo($campanaId, true);
        $htmlSinPremio = resultadoDePantallaEnCrudo($campanaId, false);
        $pantallas = [
            'formulario'           => $htmlFormulario,
            'resultado con premio' => $htmlResultado,
            'resultado sin premio' => $htmlSinPremio,
        ];

        foreach ($pantallas as $nombreDePantalla => $htmlDePantalla) {
            comprobarContiene(
                $htmlDePantalla,
                'uploads/' . $rutaConNombreFalso,
                'El banner configurado se ve en la pantalla de ' . $nombreDePantalla
            );

            comprobarContiene(
                $htmlDePantalla,
                'Cartel de la promocion',
                'Y con su texto alternativo, que es lo que lee el lector de pantalla'
            );
        }

        comprobarNoContiene(
            $htmlFormulario,
            'resultado_premio_ruta',
            'El formulario no se lleva por delante la imagen de resultado, que es de otra pantalla'
        );
    } finally {
        // La red de seguridad se encarga del resto: borra la carpeta de la
        // campana y su contenido, y quita el SetEnv si llego a ponerse.
        (new \App\Services\Imagenes())->borrarCampana($campanaId > 0 ? $campanaId : 999999);

        @unlink(\App\Core\Aplicacion::raiz() . 'uploads/caso20-plantado.php');

        if ($escenario !== null) {
            borrarEscenarioDeAdjudicacion();
        }
    }
}

/**
 * Escribe una imagen valida en la ruta indicada y la devuelve.
 *
 * Se escribe un PNG de 1x1 con bytes fijos, sin usar GD, porque en este
 * entorno no hay GD ni Imagick y el servicio los necesita para validar. Es la
 * unica forma de tener un fichero que finfo y getimagesize reconozcan de verdad.
 *
 * @param string $ruta Ruta donde se escribe el fichero.
 *
 * @return string La ruta indicada, para poder encadenar la llamada.
 */
function escribirImagenDePrueba(string $ruta): string
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );

    file_put_contents($ruta, $png === false ? '' : $png);

    return $ruta;
}

/**
 * Pinta la pantalla de participacion y devuelve el HTML entero.
 *
 * Lo normal en la suite es mirar el h1 para saber si hubo premio, y aqui hace
 * falta el HTML completo, porque lo que se busca es una etiqueta concreta que
 * solo aparece si la vista ha decidido pintar la imagen.
 *
 * @param int  $campanaId Campana cuya pantalla se quiere pintar.
 * @param bool $premio    Si se pinta el resultado con premio o sin el.
 *
 * @return string El HTML de la pantalla.
 */
function resultadoDePantallaEnCrudo(int $campanaId, bool $premio): string
{
    // No se inserta una participacion de verdad: a la vista solo le hacen
    // falta cuatro datos, y escribir en participaciones por pintar una pantalla
    // seria ensuciar la base por una comprobacion que no va de adjudicacion.
    //
    // La forma de $resultado es la que devuelve el motor, y la vista deduce de
    // ella si hubo premio: no hay que pasar «premio» como bandera aparte, o la
    // pantalla se probaria con una forma que el motor nunca devuelve.
    return \App\Core\Vista::renderizar('participacion/resultado', [
        'campana'   => (new \App\Models\Promocion())->exigirPorId($campanaId),
        'visual'    => (new \App\Models\ConfiguracionVisual())->leer($campanaId),
        'resultado' => [
            'resultado'          => $premio ? 'premio' : 'sin_premio',
            'codigo_reclamacion' => '',
            'motivo_texto'       => '',
        ],
        'destino'   => 'azafata/promociones/' . $campanaId . '/participar',
    ]);
}

/**
 * Pinta la pantalla del formulario de participacion y devuelve el HTML entero.
 *
 * Es la primera de las tres pantallas del apartado 5. Se pinta suelta, con un
 * tramo y sin campos, porque lo que se mira es si el banner llega al HTML y no
 * que el formulario tenga algo que rellenar.
 *
 * @param int $campanaId Campana cuyo formulario se quiere pintar.
 *
 * @return string El HTML de la pantalla.
 */
function formularioDePantallaEnCrudo(int $campanaId): string
{
    return \App\Core\Vista::renderizar('participacion/formulario', [
        'campana'       => (new \App\Models\Promocion())->exigirPorId($campanaId),
        'visual'        => (new \App\Models\ConfiguracionVisual())->leer($campanaId),
        'campos'        => [],
        'tramo'         => null,
        'reglas'        => [],
        'titulo'        => 'Participar',
        'idempotencia'  => 'caso20',
        'csrf'          => '',
        'destino'       => 'azafata/promociones/' . $campanaId . '/participar',
    ]);
}

/**
 * Registra una limpieza que se ejecuta al final del caso 20, pase lo que pase.
 *
 * @param callable():void $limpieza Lo que hay que dejar como estaba.
 *
 * @return void
 */
function registrarLimpiezaCaso20(callable $limpieza): void
{
    register_shutdown_function(static function () use ($limpieza): void {
        try {
            $limpieza();
        } catch (Throwable $error) {
            fwrite(STDERR, '[pruebas] La limpieza del caso 20 no ha podido terminar: '
                . $error->getMessage() . PHP_EOL);
        }
    });
}

/**
 * Caso 21: el calendario se revisa a mano, que es lo que pedia el caso 2.
 *
 * ============================================================================
 * POR QUE ESTE CASO EXISTE
 * ============================================================================
 *
 * `Calendario::crear()` y `Calendario::mover()` llevan desde el hito 3 escritos,
 * probados por su codigo y sin que **nadie los llame**: no habia ruta, ni accion de
 * controlador, ni boton. Solo `retirar()` estaba conectado de punta a punta. El
 * apartado 4.6 pide anadir, mover y retirar unidades, y el panel solo permitia una
 * de las tres, asi que la revision del calendario era en teoria una pantalla y en
 * la practica no se podia hacer.
 *
 * Lo que se comprueba aqui no es que el servicio funcione —eso ya lo hacia— sino
 * que las tres operaciones estan **conectadas**: hay ruta, hay boton, hay token, y
 * el resultado se ve en la tabla de la misma pantalla. Un servicio correcto sin
 * ruta es codigo muerto, y es exactamente lo que havia.
 *
 * ============================================================================
 * LA FECHA LA PONE EL TRAMO, Y POR QUE
 * ============================================================================
 *
 * El formulario pide un tramo y una hora, y no una fecha. La combinacion imposible
 * —el tramo del martes con la fecha del jueves— no se puede escribir, y esa es la
 * forma de que no llegue al servicio. Aun asi se manda a proposito una fecha falsa
 * en el POST para comprobar que **se ignora**: si alguien anadiera un campo de
 * fecha al formulario, la prueba lo notaria, porque la unidad caeria en el dia
 * equivocado y nadie se enteraria hasta que la campana repartiera a destiempo.
 *
 * ============================================================================
 * LO QUE NO SE PUEDE MOVER, Y POR QUE ES LA MITAD DE LA PRUEBA
 * ============================================================================
 *
 * Solo se mueven unidades programadas. Una unidad entregada ya tiene
 * participacion, adjudicacion y codigo de reclamacion, y moverla dejaria las tres
 * cosas diciendo cosas distintas: un correo anunciando un premio a una hora que ya
 * no es la de la fila. Se comprueba en las dos direcciones: que el servicio
 * rechaza moverla, y que la pantalla **no ofrece el boton** de mover en una fila
 * que ya no esta programada. Lo segundo es lo que evita el error de verdad, porque
 * un boton que no deberia estar es un error que el usuario ve y el servicio no
 * puede evitar.
 *
 * @return void
 */
function caso21(): void
{
    echo 'Caso 21: el calendario se anade, se mueve y se retira a mano', PHP_EOL;

    $escenario = null;

    try {
        $escenario = crearEscenarioDePanel([
            'premios' => 2,
            'tramos'  => 2,
            'sufijo'  => 'cal21',
        ]);

        $id = (int) $escenario['promocion'];
        $tramos = array_map('intval', $escenario['tramos']);
        $tipos = array_map('intval', $escenario['tipos']);
        $otro = crearEscenarioDePanel([
            'premios' => 1,
            'tramos'  => 1,
            'sufijo'  => 'cal21-ajena',
        ]);

        $calendario = new \App\Services\Calendario();
        $generado = $calendario->generar($id);

        comprobar(
            ($generado['generado'] ?? false) === true && (int) ($generado['unidades'] ?? 0) === 8,
            'El generador ha repartido 8 unidades: dos premios por dos tramos',
            'informe: ' . json_encode($generado, JSON_UNESCAPED_UNICODE)
        );

        // =====================================================================
        // 1. LAS TRES OPERACIONES ESTAN CONECTADAS
        // =====================================================================
        $html = htmlDeAccion('ControladorCampana', 'calendario', ['id' => $id]);

        comprobarContiene(
            $html,
            '/calendario/unidad',
            'La pantalla del calendario ofrece el formulario de anadir una unidad'
        );
        comprobarContiene(
            $html,
            'Anadir unidad',
            'Y el boton se llama asi, no «guardar»'
        );
        comprobarContiene(
            $html,
            '/mover',
            'Y cada unidad programada trae su formulario para moverla'
        );

        // El numero de botones de mover tiene que ser el de unidades programadas.
        // Es la comprobacion que detecta el fallo en el otro sentido: un boton de
        // mover en una fila entregada, o ninguno en una programada.
        $programadas = array_values(array_filter(
            $calendario->listar($id),
            static fn (array $u): bool => (string) $u['estado'] === \App\Models\UnidadPremio::ESTADO_PROGRAMADA
        ));
        comprobarIgual(
            count($programadas),
            substr_count($html, 'class="mover-unidad"'),
            'Hay un formulario de mover por cada unidad programada, ni uno mas'
        );

        // =====================================================================
        // 2. ANADIR UNA UNIDAD
        // =====================================================================
        // El generador ha dejado los cuatro pares de tramo y premio llenos: hay
        // dos unidades de cada premio en cada tramo y dos unidades de plan. Anadir
        // una tercera es pasar del plan, y el servicio lo rechaza. Es lo primero
        // que se comprueba, porque es el cambio de contrato del hito 11 y porque
        // todo lo que viene despues necesita un hueco de verdad, no uno simulado.
        $antes = $calendario->contar($id);
        $lleno = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], $tipos[0], date('Y-m-d'), '12:30'),
            'Con el par de tramo y premio ya completo, anadir una unidad se rechaza'
        );

        comprobarIgual(
            $antes,
            $calendario->contar($id),
            'Y el rechazo no deja ninguna unidad nueva'
        );

        // El mensaje tiene que decir cuanto se pidio y cuanto hay. «No cabe» a
        // secas deja al administrador yendo a otra pantalla a buscar los numeros.
        comprobar(
            strpos((string) ($lleno->errores()['tipo_premio_id'] ?? ''), 'pide 2') !== false,
            'Y el error dice cuanto pedia el plan y cuanto hay, no solo que no cabe',
            'mensaje: ' . (string) ($lleno->errores()['tipo_premio_id'] ?? '(ninguno)')
        );

        // ---- Para anadir hay que abrir sitio antes --------------------------
        // Se retira una unidad a mano, que es la forma de abrirlo que tiene el
        // administrador desde la misma pantalla. La retirada no la hace el caso 22
        // para que aqui no se mezclen las dos mitades del contrato.
        $paraRetirar = null;

        foreach ($calendario->listar($id) as $fila) {
            if (
                (int) $fila['tramo_id'] === $tramos[0]
                && (int) $fila['tipo_premio_id'] === $tipos[0]
                && (string) $fila['estado'] === \App\Models\UnidadPremio::ESTADO_PROGRAMADA
            ) {
                $paraRetirar = $fila;

                break;
            }
        }

        comprobar(
            $paraRetirar !== null,
            'El escenario tiene una unidad programada del par que se va a abrir'
        );

        $calendario->retirar((int) ($paraRetirar['id'] ?? 0), $id, 'para abrir sitio en la prueba 21');

        // ---- Y la pantalla avisa de que ahora falta -------------------------
        // Esto es lo que pide el caso 2: al editar a mano, el panel tiene que
        // decir que el calendario se ha separado del plan, y no limitarse a
        // aceptarlo en silencio. Aqui la separacion va por debajo, que es la
        // direccion legitima: retirar una unidad es una decision del
        // administrador.
        $desajustes = array_values(array_filter(
            (new \App\Services\ConfiguracionPromocion())->compararPlanYCalendario($id),
            static fn (array $fila): bool => (bool) $fila['cambia']
        ));

        comprobarIgual(
            1,
            count($desajustes),
            'Retirar una unidad deja el par del tramo corto en una unidad'
        );

        $html = htmlDeAccion('ControladorCampana', 'calendario', ['id' => $id]);
        comprobarContiene(
            $html,
            'Plan frente a calendario',
            'Y la pantalla lo enseña, en vez de dejar que se note al repartir'
        );
        comprobarContiene(
            $html,
            'Faltan 1',
            'Con las unidades que faltan, que es la direccion que se puede arreglar'
        );

        // ---- Y ahora si se puede anadir -------------------------------------
        $antes = $calendario->contar($id);

        // La fecha del POST es falsa y a proposito: el tramo manda. Y la hora, 12:30,
        // cae dentro del tramo, que en este escenario empieza a las 11:00.
        limpiarPeticion();
        enviarFormulario(
            ['tramo_id' => $tramos[0], 'premio_id' => $tipos[0], 'hora' => '12:30', 'fecha' => '2001-01-01'],
            '/admin/promociones/' . $id . '/calendario/unidad'
        );
        htmlDeAccion('ControladorCampana', 'crearUnidad', ['id' => $id]);

        comprobarIgual(
            $antes + 1,
            $calendario->contar($id),
            'La accion de anadir deja una unidad mas en el calendario'
        );

        $anadidas = array_values(array_filter(
            $calendario->listar($id),
            static fn (array $u): bool => substr((string) $u['inicio'], 11, 5) === '12:30'
        ));
        comprobarIgual(1, count($anadidas), 'La unidad anadida es la unica de las 12:30');

        $nueva = $calendario->buscar((int) ($anadidas[0]['id'] ?? 0));

        comprobar(
            $nueva !== null && (string) $nueva['estado'] === \App\Models\UnidadPremio::ESTADO_PROGRAMADA,
            'Y la unidad anadida nace programada, que es lo unico que se puede mover luego'
        );
        comprobar(
            $nueva !== null && substr((string) $nueva['inicio'], 11, 5) === '12:30',
            'Con la hora que se escribio',
            'inicio: ' . ($nueva === null ? 'no existe' : (string) $nueva['inicio'])
        );
        comprobar(
            $nueva !== null && substr((string) $nueva['inicio'], 0, 10) === date('Y-m-d'),
            'Y con la fecha del tramo, no con la del POST, que era de 2001',
            'inicio: ' . ($nueva === null ? 'no existe' : (string) $nueva['inicio'])
        );

        // El hueco se ha vuelto a llenar, y con el aviso disappears: el desajuste
        // que se avisa es el que existe, no el que se ha arreglado.
        comprobar(
            array_values(array_filter(
                (new \App\Services\ConfiguracionPromocion())->compararPlanYCalendario($id),
                static fn (array $fila): bool => (bool) $fila['cambia']
            )) === [],
            'Rellenado el hueco, el calendario vuelve a cuadrar con el plan'
        );

        limpiarPeticion();

        // ---- Y con el par vuelto a llenar, otra unidad se rechaza otra vez ----
        // El caso 22 cubre los dos limites con detalle. Aqui basta con que el
        // rechazo no se haya quedado en la primera vez: un tope que solo se
        // comprueba al principio de una pantalla es un tope que se esquiva
        // recargando.
        $sinPlan = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], $tipos[0], date('Y-m-d'), '12:45'),
            'Con el par ya relleno otra vez, una unidad mas se vuelve a rechazar'
        );

        comprobar(
            strpos((string) ($sinPlan->errores()['tipo_premio_id'] ?? ''), 'ya esta completo') !== false,
            'Y el mensaje es el del par lleno, no el de un tramo que no existe',
            'mensaje: ' . (string) ($sinPlan->errores()['tipo_premio_id'] ?? '(ninguno)')
        );

        // ---- Una hora antes del tramo tampoco vale --------------------------
        $antes = $calendario->contar($id);
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], $tipos[0], date('Y-m-d'), '10:30'),
            'Una unidad a las 10:30, con un tramo que empieza a las 11:00, se rechaza'
        );
        comprobarIgual(
            $antes,
            $calendario->contar($id),
            'Y no se ha creado ninguna fila por el intento'
        );

        // ---- Una hora fuera del tramo no crea nada --------------------------
        $antes = $calendario->contar($id);
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], $tipos[0], date('Y-m-d'), '23:30'),
            'Una unidad a las 23:30, con un tramo que acaba a las 23:00, se rechaza'
        );
        comprobarIgual(
            $antes,
            $calendario->contar($id),
            'Y no se ha creado ninguna fila por el intento de las 23:30'
        );

        // ---- Un tramo de otra campana tampoco ------------------------------
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, (int) $otro['tramos'][0], $tipos[0], date('Y-m-d'), '10:00'),
            'No se puede anadir una unidad a un tramo de otra campana'
        );
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], (int) $otro['tipos'][0], date('Y-m-d'), '10:00'),
            'Ni con un premio de otra campana'
        );

        // ---- Un premio desactivado no admite unidades nuevas ---------------
        // El HTML se pide antes de volver a activarlo: si se pintara despues, la
        // comprobacion del desplegable pasaria siempre y no probaria nada.
        \App\Core\Aplicacion::db()->ejecutar(
            'UPDATE tipos_premio SET activo = 0 WHERE id = ?',
            [$tipos[1]]
        );
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], $tipos[1], date('Y-m-d'), '11:00'),
            'Un premio desactivado no admite unidades nuevas'
        );

        // El desplegable no ofrece los desactivados, en vez de ofrecerlos y dejar
        // que el servicio los rechace despues de haber escrito la hora. Se mira
        // solo dentro del desplegable: el nombre del premio aparece legitimamente
        // en la tabla de unidades de mas abajo.
        $html = htmlDeAccion('ControladorCampana', 'calendario', ['id' => $id]);
        comprobar(
            preg_match('#<select id="anadir-premio".*?</select>#s', $html, $desplegable) === 1,
            'Se encuentra el desplegable de premios del formulario de anadir'
        );
        comprobarContiene(
            (string) ($desplegable[0] ?? ''),
            'Premio de pruebas cal21 1',
            'Y ofrece el premio activo'
        );
        comprobarNoContiene(
            (string) ($desplegable[0] ?? ''),
            'Premio de pruebas cal21 2',
            'Y no ofrece el premio que se acaba de desactivar'
        );

        // Y con el premio ya activo otra vez, vuelve a aparecer: la comprobacion
        // anterior no se podia deber a un desplegable siempre vacio.
        \App\Core\Aplicacion::db()->ejecutar(
            'UPDATE tipos_premio SET activo = 1 WHERE id = ?',
            [$tipos[1]]
        );
        $html = htmlDeAccion('ControladorCampana', 'calendario', ['id' => $id]);
        comprobarContiene($html, 'Premio de pruebas cal21 2', 'Al reactivarlo, el desplegable lo vuelve a ofrecer');

        // =====================================================================
        // 3. MOVER UNA UNIDAD
        // =====================================================================
        // Se mueve una unidad dentro de su propio tramo, que es el movimiento que
        // tiene que seguir funcionando con el par lleno. La cuenta del destino
        // excluye la unidad que se esta moviendo justamente para esto: sin la
        // excepcion, cambiar una unidad de las 12:10 a las 12:15 se rechazaria
        // porque el par ya tiene dos de dos, cuando no ha anadido nada.
        $movible = null;

        foreach ($programadas as $fila) {
            if (
                (int) $fila['tramo_id'] === $tramos[0]
                && (int) $fila['tipo_premio_id'] === $tipos[1]
            ) {
                $movible = $fila;

                break;
            }
        }

        comprobar(
            $movible !== null,
            'El escenario tiene una unidad del segundo premio en el primer tramo'
        );

        $unidadId = (int) ($movible['id'] ?? 0);
        $tramoAntes = (int) ($movible['tramo_id'] ?? 0);

        limpiarPeticion();
        enviarFormulario(
            ['tramo_id' => $tramos[0], 'hora' => '15:45', 'fecha' => '2001-01-01'],
            '/admin/promociones/' . $id . '/calendario/' . $unidadId . '/mover'
        );
        htmlDeAccion('ControladorCampana', 'moverUnidad', ['id' => $id, 'unidad' => $unidadId]);

        $movida = $calendario->buscar($unidadId);
        comprobar(
            $movida !== null && (int) $movida['tramo_id'] === $tramoAntes,
            'Mover dentro del mismo tramo deja la unidad en el mismo tramo',
            'tramo: ' . ($movida === null ? 'no existe' : (string) $movida['tramo_id'])
        );
        comprobar(
            $movida !== null && substr((string) $movida['inicio'], 11, 5) === '15:45',
            'Y a la hora escrita, dentro del tramo',
            'inicio: ' . ($movida === null ? 'no existe' : (string) $movida['inicio'])
        );
        comprobar(
            $movida !== null && (string) $movida['estado'] === \App\Models\UnidadPremio::ESTADO_PROGRAMADA,
            'Y sigue programada: mover no entrega ni adjudica'
        );

        // ---- Y a un tramo donde el par ya esta lleno, no ---------------------
        // El destino es ahora el segundo tramo con el mismo premio, que lleva sus
        // dos unidades de plan. Es el limite del hito 11, y aqui solo se comprueba
        // que no rompe el servicio; el caso 22 lo mira con el mensaje y la
        // auditoria.
        $llenoAlMover = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover($unidadId, $tramos[1], date('Y-m-d'), '15:45', $id),
            'Mover a un par de tramo y premio que ya esta completo se rechaza'
        );

        comprobar(
            strpos(
                implode(' ', array_values($llenoAlMover->errores())),
                'ya esta completo'
            ) !== false,
            'Y el error dice que el par esta lleno, con los numeros del plan',
            'mensaje: ' . implode(' ', array_values($llenoAlMover->errores()))
        );
        comprobar(
            (string) ($calendario->buscar($unidadId)['inicio'] ?? '') === date('Y-m-d') . ' 15:45:00',
            'Y la unidad se queda en el tramo de origen, sin quedar a medio cambiar'
        );

        // ---- Un movimiento fallido se repinta con lo que se escribio --------
        // Si la fila volviera con la hora de antes, habria que repetir el trabajo
        // y volver a fallar, y el error se veria en un campo que ya no es el que
        // se escribio.
        limpiarPeticion();
        enviarFormulario(
            ['tramo_id' => $tramos[0], 'hora' => '23:30'],
            '/admin/promociones/' . $id . '/calendario/' . $unidadId . '/mover'
        );
        $html = htmlDeAccion('ControladorCampana', 'moverUnidad', ['id' => $id, 'unidad' => $unidadId]);

        // Se mira el campo de esa fila y no la pagina entera: el formulario de anadir
        // tambien conserva la hora que se escribio, y por eso hay dos campos con
        // 23:30 en el HTML.
        comprobar(
            preg_match(
                '/id="mover-hora-' . $unidadId . '"[^>]*value="23:30"/',
                $html
            ) === 1,
            'La fila que no se pudo mover vuelve con la hora que se escribio'
        );
        comprobarNoContiene(
            $html,
            'Notice:',
            'Y el repintado sale sin avisos de PHP'
        );

        limpiarPeticion();

        // ---- Un destino invalido deja la unidad donde estaba ---------------
        $antesDeMover = (string) ($calendario->buscar($unidadId)['inicio'] ?? '');
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover($unidadId, $tramos[0], date('Y-m-d'), '23:30', $id),
            'Mover a una hora que no cae en el tramo de destino se rechaza'
        );
        comprobarIgual(
            $antesDeMover,
            (string) ($calendario->buscar($unidadId)['inicio'] ?? ''),
            'Y la unidad se queda donde estaba, sin quedar a medio cambiar'
        );

        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover($unidadId, (int) $otro['tramos'][0], date('Y-m-d'), '10:00', $id),
            'Mover a un tramo de otra campana se rechaza'
        );

        // ---- Una unidad de otra campana no se mueve ------------------------
        // El generador necesita que su campana tenga unidades propias, porque si
        // no el servicio no tendria nada que rechazar y la prueba pasaria sin
        // haber probado nada.
        $calendario->generar((int) $otro['promocion']);
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover(
                (int) $calendario->listar((int) $otro['promocion'])[0]['id'],
                $tramos[0],
                date('Y-m-d'),
                '12:30',
                $id
            ),
            'Una unidad que no es de esta campana no se mueve'
        );

        // =====================================================================
        // 4. LO QUE YA NO SE PUEDE MOVER: RETIRADA
        // =====================================================================
        $retirada = (int) $movible['id'];
        $calendario->retirar($retirada, $id, 'prueba del caso 21');
        $anulada = $calendario->buscar($retirada);

        comprobar(
            $anulada !== null && (string) $anulada['estado'] === \App\Models\UnidadPremio::ESTADO_ANULADA,
            'Retirar deja la unidad anulada y no la borra, que es lo que hace que el historial cuadre'
        );
        comprobarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover($retirada, $tramos[0], date('Y-m-d'), '10:00', $id),
            'Una unidad anulada ya no se puede mover'
        );

        // Y la pantalla se queda sin el boton de mover en esa fila, que es la
        // mitad del contrato: el servicio rechaza, pero el boton no deberia
        // haberse ofrecido.
        $html = htmlDeAccion('ControladorCampana', 'calendario', ['id' => $id]);
        comprobarNoContiene(
            $html,
            'id="mover-' . $retirada . '"',
            'La fila de la unidad anulada no ofrece ni el desplegable ni el boton de mover'
        );
        comprobarNoContiene(
            $html,
            '/calendario/' . $retirada . '/retirar',
            'Y tampoco ofrece retirarla otra vez, porque ya esta anulada'
        );

        // =====================================================================
        // 5. EL ERROR DE RETIRAR NO DICE QUE HA IDO BIEN
        // =====================================================================
        // El fallo era que el aviso de exito se guardaba tambien cuando la
        // operacion habia fallado: el administrador leia «Unidad retirada» encima
        // del error que decia lo contrario. Se prueba con una unidad que llega a
        // su hora sin poder adjudicarse, porque es un estado que el panel teaches
        // de verdad y que el servicio no admite para retirar.
        $noEntregada = (int) $calendario->listar($id)[1]['id'];
        \App\Core\Aplicacion::db()->ejecutar(
            'UPDATE unidades_premio SET estado = ? WHERE id = ?',
            [\App\Models\UnidadPremio::ESTADO_NO_ENTREGADA, $noEntregada]
        );

        limpiarPeticion();
        enviarFormulario(
            ['motivo' => 'prueba del caso 21'],
            '/admin/promociones/' . $id . '/calendario/' . $noEntregada . '/retirar'
        );

        // No hay que coger Redirigir: desde la consola Controlador::redirigir() solo
        // guarda un aviso y devuelve, porque header() ahi no hace nada util.
        htmlDeAccion('ControladorCampana', 'retirarUnidad', ['id' => $id, 'unidad' => $noEntregada]);

        comprobar(
            \App\Core\Vista::aviso('error') !== '',
            'Retirar una unidad que ya no esta programada avisa de que no se puede'
        );
        comprobarNoContiene(
            \App\Core\Vista::aviso('exito'),
            'Unidad retirada',
            'Y no dice encima que se ha retirado, que era el fallo'
        );
        comprobar(
            (string) ($calendario->buscar($noEntregada)['estado'] ?? '') === \App\Models\UnidadPremio::ESTADO_NO_ENTREGADA,
            'Y la unidad se queda como estaba'
        );

        limpiarPeticion();
        borrarEscenarioDePanel($id);
        borrarEscenarioDePanel((int) $otro['promocion']);
    } catch (Throwable $error) {
        // Si el caso muere a mitad, la red de seguridad deja la base como estaba.
        foreach ([[$escenario, 'borrarEscenarioDePanel']] as $par) {
            if ($par[0] === null) {
                continue;
            }

            try {
                $par[1]((int) $par[0]['promocion']);
            } catch (Throwable $ignorado) {
                fwrite(STDERR, '[pruebas] No se ha podido limpiar: ' . $ignorado->getMessage() . PHP_EOL);
            }
        }

        throw $error;
    }
}

/**
 * Caso 22: el calendario no se pasa del plan y cada revision deja asiento.
 *
 * El caso 21 comprueba que anadir, mover y retirar funcionan. Este comprueba las
 * dos reglas del hito 11, que son las que hacen que esas tres operaciones sean
 * seguras de usar en una campana en marcha:
 *
 * 1. El calendario no puede pasar del plan al anadir ni al mover, y el par que el
 *    plan no reparte no admite unidades ni aunque este vacio.
 * 2. Cada revision deja un asiento, con quien la hizo y con lo que habia antes.
 *
 * Las dos se comprueban juntas a proposito, porque se necesitan mutuamente: un
 * tope sin asiento no se puede auditar, y un asiento sin tope solo sirve para
 * anotar los errores que el propio sistema deja pasar.
 *
 * @return void
 *
 * @throws \RuntimeException Si un caso se deja a medias.
 */
function caso22(): void
{
    echo 'Caso 22: el calendario no se pasa del plan y cada revision deja asiento', PHP_EOL;

    $principal = null;
    $ajena = null;
    $nombreUsuario = 'cal22';
    $db = \App\Core\Aplicacion::db();

    try {
        $principal = crearEscenarioDePanel(['premios' => 2, 'tramos' => 2, 'sufijo' => 'cal22']);
        $ajena = crearEscenarioDePanel(['premios' => 1, 'tramos' => 1, 'sufijo' => 'cal22-ajena']);

        $id = (int) $principal['promocion'];
        $tramos = array_map('intval', $principal['tramos']);
        $tipos = array_map('intval', $principal['tipos']);
        $otra = (int) $ajena['promocion'];

        // El usuario existe para que el asiento tenga un nombre que comprobar. Sin
        // el, todas las filas saldrian con la cadena vacia y la prueba pasaria
        // sin haber probado que el nombre se copia.
        $usuarioId = (new \App\Models\User())->crear(
            $nombreUsuario,
            'cal22-contrasena',
            \App\Core\Autorizacion::ROL_ADMINISTRADOR,
            'Administrador del caso 22',
            null
        );

        $calendario = new \App\Services\Calendario();
        $asientos = static fn (int $campanaId, string $accion): array => array_values(array_filter(
            (new \App\Models\Auditoria())->listarPorCampana($campanaId, 200),
            static fn (array $linea): bool => (string) $linea['accion'] === $accion
        ));
        $cuantasHay = static fn (int $campanaId): int => count(
            (new \App\Models\Auditoria())->listarPorCampana($campanaId, 200)
        );

        // =====================================================================
        // 1. GENERAR DEJA UN ASIENTO, Y NO UNO POR UNIDAD
        // =====================================================================
        $antesDeGenerar = $cuantasHay($id);
        $generado = $calendario->generar($id, false, false, $usuarioId);

        comprobar(
            ($generado['generado'] ?? false) === true && (int) ($generado['unidades'] ?? 0) === 8,
            'Se generan las ocho unidades del escenario',
            'informe: ' . json_encode($generado, JSON_UNESCAPED_UNICODE)
        );

        comprobarIgual(
            $antesDeGenerar + 1,
            $cuantasHay($id),
            'Y quedan ocho unidades y un solo asiento, no ocho asientos'
        );

        $generaciones = $asientos($id, \App\Models\Auditoria::ACCION_GENERACION);

        comprobarIgual(1, count($generaciones), 'Hay exactamente un asiento de generacion');

        if ($generaciones !== []) {
            $fila = $generaciones[0];

            comprobarIgual('calendario', (string) $fila['entidad'], 'El asiento apunta al calendario, no a una unidad');
            comprobarIgual((string) $id, (string) $fila['entidad_id'], 'Y su identificador es el de la campana');

            $antesDeLaGeneracion = json_decode((string) $fila['datos_antes'], true);
            $despuesDeLaGeneracion = json_decode((string) $fila['datos_despues'], true);

            comprobarIgual(
                0,
                (int) ($antesDeLaGeneracion['unidades'] ?? -1),
                'El asiento dice que no habia ninguna unidad antes de generar'
            );
            comprobarIgual(
                8,
                (int) ($despuesDeLaGeneracion['creadas'] ?? -1),
                'Y dice cuantas han salido, que es lo que se preguntara luego'
            );

            // El nombre se copia en el momento del cambio. La lista por campana no
            // trae el identificador de usuario, asi que se va a la tabla: lo que
            // importa es que la fila apunte a un usuario de verdad y no al cero
            // que devuelve Autorizacion::usuarioId() sin sesion.
            comprobarIgual(
                $nombreUsuario,
                (string) ($db->valor(
                    'SELECT usuario_nombre FROM auditoria WHERE id = ?',
                    [(int) $fila['id']]
                ) ?? ''),
                'Y guarda el nombre del usuario en el momento del cambio'
            );

            comprobarIgual(
                $usuarioId,
                (int) ($db->valor(
                    'SELECT usuario_id FROM auditoria WHERE id = ?',
                    [(int) $fila['id']]
                ) ?? 0),
                'Y tambien su identificador, que es lo que permite seguir a esa persona'
            );
        }

        // =====================================================================
        // 2. UN PLAN QUE NO CABE NO GENERA Y NO DEJA ASIENTO
        // =====================================================================
        // El generador diagnostica antes de escribir. Un asiento de «se ha generado
        // el calendario» acompanado de un informe de cero unidades seria una
        // manera muy comoda de mentir en la fila que mas se lee.
        $calendario->generar($otra, false, false, $usuarioId);
        $antesDeGenerar = $cuantasHay($otra);

        $db->ejecutar(
            'UPDATE asignaciones_tramo SET cantidad = 800 WHERE tramo_id = ?',
            [(int) $ajena['tramos'][0]]
        );

        $imposible = $calendario->generar($otra, false, false, $usuarioId);

        comprobar(
            ($imposible['generado'] ?? true) === false && ($imposible['problemas'] ?? []) !== [],
            'Un plan que no cabe en el tramo no genera nada'
        );
        comprobarIgual(
            $antesDeGenerar,
            $cuantasHay($otra),
            'Y no deja un asiento de generacion que diga que si'
        );

        // =====================================================================
        // 3. ANADIR CUANDO EL PAR YA ESTA COMPLETO
        // =====================================================================
        $antesDeAnadir = $cuantasHay($id);
        $lleno = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[0], $tipos[0], date('Y-m-d'), '12:30', $usuarioId),
            'Anadir una unidad a un par que ya tiene todas las del plan se rechaza'
        );

        comprobarIgual(
            8,
            $calendario->contar($id),
            'Y no se ha creado ninguna unidad'
        );
        comprobarIgual(
            $antesDeAnadir,
            $cuantasHay($id),
            'Ni se ha escrito ningun asiento: un rechazo no es una revision'
        );

        $mensaje = (string) ($lleno->errores()['tipo_premio_id'] ?? '');

        comprobar(
            strpos($mensaje, 'pide 2') !== false && strpos($mensaje, 'hay 2') !== false,
            'El error lleva los dos numeros, para que se sepa cuanto hay que subir o retirar',
            'mensaje: ' . $mensaje
        );

        // =====================================================================
        // 4. UN PAR QUE EL PLAN NO REPARTE NO ADMITE UNIDADES
        // =====================================================================
        // El plan se borra por debajo del calendario, que es como se queda una
        // par de tramo y premio cuando se toca el plan desde la pantalla de
        // cantidades. Un par vacio con dos unidades y cero de plan no puede
        // aceptarse como si nada, porque es el otro camino de pasarse del plan.
        $db->ejecutar(
            'DELETE FROM asignaciones_tramo WHERE tramo_id = ? AND tipo_premio_id = ?',
            [$tramos[1], $tipos[1]]
        );

        $sinPlan = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->crear($id, $tramos[1], $tipos[1], date('Y-m-d'), '15:45', $usuarioId),
            'Anadir a un par que el plan no reparte se rechaza, aunque este vacio'
        );

        comprobar(
            strpos((string) ($sinPlan->errores()['tipo_premio_id'] ?? ''), 'no reparte') !== false,
            'Y el mensaje dice que el tramo no reparte ese premio, que se arregla en las cantidades',
            'mensaje: ' . (string) ($sinPlan->errores()['tipo_premio_id'] ?? '(ninguno)')
        );
        comprobarIgual(
            $antesDeAnadir,
            $cuantasHay($id),
            'Ese rechazo tampoco deja asiento'
        );

        // =====================================================================
        // 5. RETIRAR ABRE EL HUECO Y ANADIR LO RELLENA
        // =====================================================================
        $programadas = array_values(array_filter(
            $calendario->listar($id),
            static fn (array $u): bool => (string) $u['estado'] === \App\Models\UnidadPremio::ESTADO_PROGRAMADA
        ));

        $suelta = null;
        $primeraDelSegundo = null;
        $segundaDelSegundo = null;

        foreach ($programadas as $fila) {
            if ((int) $fila['tramo_id'] === $tramos[0] && (int) $fila['tipo_premio_id'] === $tipos[0] && $suelta === null) {
                $suelta = $fila;
            }

            if ((int) $fila['tramo_id'] === $tramos[0] && (int) $fila['tipo_premio_id'] === $tipos[1]) {
                if ($primeraDelSegundo === null) {
                    $primeraDelSegundo = $fila;
                } else {
                    $segundaDelSegundo = $fila;
                }
            }
        }

        comprobar(
            $suelta !== null && $primeraDelSegundo !== null && $segundaDelSegundo !== null,
            'El escenario tiene las tres unidades que hacen falta para las pruebas siguientes'
        );

        $idSuelta = (int) ($suelta['id'] ?? 0);
        $calendario->retirar($idSuelta, $id, 'se lleva un premio de mas', $usuarioId);

        $retiradas = $asientos($id, \App\Models\Auditoria::ACCION_RETIRADA);

        comprobarIgual(1, count($retiradas), 'Retirar una unidad deja un asiento propio');

        if ($retiradas !== []) {
            $antes = json_decode((string) $retiradas[0]['datos_antes'], true);
            $despues = json_decode((string) $retiradas[0]['datos_despues'], true);

            comprobarIgual(
                \App\Models\UnidadPremio::ESTADO_PROGRAMADA,
                (string) ($antes['estado'] ?? ''),
                'El asiento guarda el estado que tenia antes'
            );
            comprobarIgual(
                \App\Models\UnidadPremio::ESTADO_ANULADA,
                (string) ($despues['estado'] ?? ''),
                'Y el que ha quedado despues'
            );
            comprobarIgual(
                'se lleva un premio de mas',
                (string) ($despues['anulada_motivo'] ?? ''),
                'Incluido el motivo, que es la mitad del motivo de que exista'
            );
        }

        // Y con el hueco ya abierto, anadir vuelve a funcionar.
        $nueva = $calendario->crear($id, $tramos[0], $tipos[0], date('Y-m-d'), '12:30', $usuarioId);

        comprobar(
            $nueva > 0 && (int) $calendario->buscar($nueva)['tramo_id'] === $tramos[0],
            'Retirar una unidad abre el hueco, y anadir vuelve a poder hacer'
        );

        $altas = $asientos($id, \App\Models\Auditoria::ACCION_ALTA);

        comprobarIgual(1, count($altas), 'Y el alta deja un asiento, con su propia accion');

        if ($altas !== []) {
            $datos = json_decode((string) $altas[0]['datos_despues'], true);

            comprobarIgual(
                (string) $nueva,
                (string) $altas[0]['entidad_id'],
                'El asiento del alta apunta a la unidad creada'
            );
            comprobar(
                $altas[0]['datos_antes'] === null,
                'Y no inventa un estado anterior para algo que no existia',
                'datos_antes: ' . var_export($altas[0]['datos_antes'], true)
            );
            comprobarIgual(
                $tramos[0],
                (int) ($datos['tramo_id'] ?? -1),
                'El asiento dice en que tramo ha quedado la unidad'
            );
            comprobarIgual(
                2,
                (int) ($datos['plan'] ?? -1),
                'Y lleva cuanto pedia el plan para ese par'
            );
            comprobarIgual(
                2,
                (int) ($datos['calendario'] ?? -1),
                'Y cuanto hay ya, que es la cuenta despues de insertar y no antes'
            );
        }

        // Retirar otra vez la misma unidad no falla y si deja rastro. Retirar es
        // idempotente a proposito —la pantalla no ofrece el boton, pero el servicio
        // no puede depender de eso— y quien pulsa algo ha hecho algo.
        $antesDeRepetir = count($retiradas);
        $calendario->retirar($idSuelta, $id, 'otra vez', $usuarioId);

        comprobarIgual(
            $antesDeRepetir + 1,
            count($asientos($id, \App\Models\Auditoria::ACCION_RETIRADA)),
            'Retirar una unidad ya anulada vuelve a escribir asiento, en vez de no hacer nada'
        );

        // =====================================================================
        // 6. MOVER DENTRO DE SU TRAMO SIEMPRE, Y A UN PAR LLENO NUNCA
        // =====================================================================
        $movible = (int) ($segundaDelSegundo['id'] ?? 0);
        $antesDeMover = $cuantasHay($id);

        // Dentro de su mismo par: la cuenta lo excluye y el movimiento no anade
        // nada, asi que tiene que salir bien aunque el par este lleno.
        $calendario->mover($movible, $tramos[0], date('Y-m-d'), '16:30', $id, $usuarioId);

        comprobar(
            substr((string) ($calendario->buscar($movible)['inicio'] ?? ''), 11, 5) === '16:30',
            'Mover una unidad dentro de su propio tramo funciona aunque el par este completo'
        );

        $movimientos = $asientos($id, \App\Models\Auditoria::ACCION_CONFIGURACION);

        comprobarIgual(1, count($movimientos), 'Y el movimiento deja un asiento');

        if ($movimientos !== []) {
            $antes = json_decode((string) $movimientos[0]['datos_antes'], true);
            $despues = json_decode((string) $movimientos[0]['datos_despues'], true);

            comprobarIgual(
                $tramos[0],
                (int) ($antes['tramo_id'] ?? -1),
                'El asiento del movimiento guarda el tramo de origen'
            );
            comprobar(
                (int) ($despues['tramo_id'] ?? -1) === $tramos[0]
                    && substr((string) ($despues['inicio'] ?? ''), 11, 5) === '16:30',
                'Y el de destino, con la hora escrita',
                'despues: ' . json_encode($despues, JSON_UNESCAPED_UNICODE)
            );
        }

        // A un par lleno de otro tramo, no. La unidad es del primer premio, y el
        // par de destino son las 16:30 del segundo tramo, que lleva sus dos
        // unidades de plan.
        $llenoAlMover = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover($nueva, $tramos[1], date('Y-m-d'), '16:30', $id, $usuarioId),
            'Mover a un par completo se rechaza'
        );

        comprobarIgual(
            $antesDeMover + 1,
            $cuantasHay($id),
            'El movimiento rechazado no escribe ni unidad ni asiento'
        );
        comprobar(
            substr((string) ($calendario->buscar($nueva)['inicio'] ?? ''), 11, 5) === '12:30',
            'Y la unidad se queda donde estaba, sin quedar a medio cambiar'
        );
        comprobar(
            strpos(implode(' ', array_values($llenoAlMover->errores())), 'ya esta completo') !== false,
            'Con un mensaje que explica que el par esta lleno',
            'mensaje: ' . implode(' ', array_values($llenoAlMover->errores()))
        );

        // Y a un par que el plan no reparte, tampoco.
        $sinPlanAlMover = capturarFalla(
            \App\Core\ErrorValidacion::class,
            static fn () => $calendario->mover(
                (int) ($primeraDelSegundo['id'] ?? 0),
                $tramos[1],
                date('Y-m-d'),
                '15:45',
                $id,
                $usuarioId
            ),
            'Mover a un par que el plan no reparte se rechaza'
        );

        comprobar(
            strpos(implode(' ', array_values($sinPlanAlMover->errores())), 'no reparte') !== false,
            'Y tambien con el mensaje del par que no esta en el plan',
            'mensaje: ' . implode(' ', array_values($sinPlanAlMover->errores()))
        );
        comprobarIgual(
            $antesDeMover + 1,
            $cuantasHay($id),
            'Ese rechazo tampoco deja asiento'
        );

        // =====================================================================
        // 7. LA PANTALLA CUENTA LA HISTORIA EN PALABRAS
        // =====================================================================
        // La columna guarda el nombre corto de la accion porque asi se filtra, pero
        // el historial se lee a ojo. Sin frase, buscar «retirada» entre las filas es
        // pasar la hoja entera.
        limpiarPeticion();
        $html = htmlDeAccion('ControladorSeguimiento', 'panel', ['id' => $id]);

        comprobarContiene($html, 'Que se hizo', 'El historial del panel tiene su columna de que se hizo');
        comprobarContiene(
            $html,
            \App\Models\Auditoria::descripcionDe(\App\Models\Auditoria::ACCION_GENERACION),
            'Y la generacion aparece traducida a una frase'
        );
        comprobarContiene(
            $html,
            \App\Models\Auditoria::descripcionDe(\App\Models\Auditoria::ACCION_RETIRADA),
            'Y tambien la retirada'
        );
        comprobarNoContiene($html, 'Notice:', 'Y el panel se pinta sin avisos de PHP');
    } catch (Throwable $error) {
        foreach ([[$principal, 'borrarEscenarioDePanel'], [$ajena, 'borrarEscenarioDePanel']] as $par) {
            if ($par[0] === null) {
                continue;
            }

            try {
                $par[1]((int) $par[0]['promocion']);
            } catch (Throwable $ignorado) {
                fwrite(STDERR, '[pruebas] No se ha podido limpiar: ' . $ignorado->getMessage() . PHP_EOL);
            }
        }

        throw $error;
    } finally {
        $db->ejecutar('DELETE FROM usuarios WHERE nombre = ?', [$nombreUsuario]);
        limpiarPeticion();
    }
}

/**
 * Lista de casos disponibles, indexada por numero.
 *
 * @var array<int, callable():void>
 */
const PRUEBAS = [
    0 => 'caso0',
    1 => 'caso1',
    2 => 'caso2',
    3 => 'caso3',
    4 => 'caso4',
    5 => 'caso5',
    6 => 'caso6',
    7 => 'caso7',
    8 => 'caso8',
    9 => 'caso9',
    10 => 'caso10',
    11 => 'caso11',
    12 => 'caso12',
    13 => 'caso13',
    14 => 'caso14',
    15 => 'caso15',
    16 => 'caso16',
    17 => 'caso17',
    18 => 'caso18',
    19 => 'caso19',
    20 => 'caso20',
    21 => 'caso21',
    22 => 'caso22',
];

// -----------------------------------------------------------------------------
// Cuerpo principal.
// -----------------------------------------------------------------------------

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo se puede ejecutar desde la consola.');
}

$casosPedidos = [];

// El recorrido va con indice y no con foreach porque, al leer «--caso 0», hay
// que poder saltarse el argumento siguiente, que es el numero del caso.
for ($posicion = 1; isset($argv[$posicion]); $posicion++) {
    $argumento = $argv[$posicion];

    if ($argumento === '--verbose' || $argumento === '-v') {
        $detallado = true;
        continue;
    }

    if (strncmp($argumento, '--caso', 6) === 0) {
        // Se aceptan las dos formas que usan los guiones de Unix. Con «--caso=0»
        // el numero va dentro del mismo argumento; con «--caso 0» va en el
        // argumento siguiente, y por eso el recorrido va indexado y no con
        // foreach: hace falta poder mirar el siguiente.
        $valor = ltrim(substr($argumento, 6), '=');

        if ($valor === '' && isset($argv[$posicion + 1])) {
            $valor = $argv[$posicion + 1];
            $posicion++;
        }

        if ($valor === '' || !ctype_digit($valor)) {
            echo 'Falta el numero de caso: usa --caso 0', PHP_EOL;
            exit(1);
        }

        $casosPedidos[] = (int) $valor;
        continue;
    }

    if ($argumento === '--ayuda' || $argumento === '--help') {
        echo 'Suite de pruebas de los sorteos', PHP_EOL, PHP_EOL;
        echo 'Uso: php tests\\run.php [--caso N] [--verbose]', PHP_EOL, PHP_EOL;
        echo 'Casos disponibles:', PHP_EOL;

        foreach (PRUEBAS as $numero => $nombre) {
            echo '  ', $numero, '  ', $nombre, PHP_EOL;
        }

        exit(0);
    }

    echo 'Opcion desconocida: ', $argumento, PHP_EOL;
    echo 'Usa --ayuda para ver las opciones.', PHP_EOL;
    exit(1);
}

if ($casosPedidos === []) {
    $casosPedidos = array_keys(PRUEBAS);
}

$raiz = dirname(__DIR__);

try {
    Aplicacion::arrancar($raiz);
} catch (Throwable $e) {
    echo '[FALLO] No se puede arrancar la aplicacion: ', $e->getMessage(), PHP_EOL;
    exit(1);
}

// Se apunta a la base de pruebas antes de hacer nada. El metodo se niega a
// funcionar desde una peticion web, comprueba que el nombre sea valido y anota
// el cambio en el log, de modo que un cambio descuidado en config.php no
// convierta una prueba en un borrado de la campana real.
$config = Aplicacion::config();
$nombreCampana = (string) $config['bd']['nombre'];
$bdPruebas = (string) ($config['bd']['nombre_pruebas'] ?? ($nombreCampana . '_test'));

if ($bdPruebas === $nombreCampana) {
    echo '[FALLO] La base de pruebas y la de la campana se llaman igual.', PHP_EOL;
    echo '         Las pruebas no se ejecutarian, por si acaso. Revisa el bloque', PHP_EOL;
    echo '         «bd.nombre_pruebas» de config/config.php.', PHP_EOL;
    exit(1);
}

try {
    Aplicacion::usarBaseDePruebas($bdPruebas);
} catch (Throwable $e) {
    echo '[FALLO] No se pudo apuntar a la base de pruebas: ', $e->getMessage(), PHP_EOL;
    echo '         Ejecuta antes: php bin\\instalar.php --test', PHP_EOL;
    exit(1);
}

$inicio = microtime(true);
$totalFallos = 0;
$totalPasadas = 0;

echo str_repeat('=', 64), PHP_EOL;
echo 'Suite de pruebas - base «', $bdPruebas, '»', PHP_EOL;
echo str_repeat('=', 64), PHP_EOL;
echo PHP_EOL;

foreach ($casosPedidos as $caso) {
    if (!isset(PRUEBAS[$caso])) {
        echo 'No existe el caso ', $caso, '.', PHP_EOL;
        $totalFallos++;
        continue;
    }

    $pasadas = 0;
    $fallos = [];

    // Cada caso va protegido. Sin esto, un error inesperado en el caso 1
    // cortaria la ejecucion y los casos 2 y 3 no se comprobarian nunca, y el
    // resumen diria que todo esta bien porque no llego a contarlos. Un fallo
    // debe verse como un fallo, no como una ejecucion que se corta en silencio.
    try {
        PRUEBAS[$caso]();
    } catch (Throwable $e) {
        $fallos[] = 'El caso ha lanzado ' . get_class($e) . ': ' . $e->getMessage();
        echo '  [FALLO] El caso se ha interrumpido con una excepcion no controlada', PHP_EOL;
        echo '          ', get_class($e), ': ', $e->getMessage(), PHP_EOL;
        echo '          en ', $e->getFile(), ' linea ', $e->getLine(), PHP_EOL;
    }

    $fallosCaso = count($fallos);
    $totalFallos += $fallosCaso;
    $totalPasadas += $pasadas;

    echo '  -> ', $pasadas, ' comprobacion', $pasadas === 1 ? '' : 'es', ' correcta',
        $pasadas === 1 ? '' : 's', ', ', $fallosCaso, ' fallo',
        $fallosCaso === 1 ? '' : 's', PHP_EOL, PHP_EOL;
}

$duracion = round((microtime(true) - $inicio) * 1000);

echo str_repeat('=', 64), PHP_EOL;

if ($totalFallos === 0) {
    echo 'Todo correcto: ', count($casosPedidos), ' caso', count($casosPedidos) === 1 ? '' : 's',
        ' ejecutados, ', $totalPasadas, ' comprobaciones en ', $duracion, ' ms.', PHP_EOL;
    exit(0);
}

echo $totalFallos, ' comprobacion', $totalFallos === 1 ? '' : 'es', ' han fallado.', PHP_EOL;
exit(1);
