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

use App\Core\Aplicacion;

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
 * son la regla central del apartado 6 escrita como comprobacion.
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
 * @return void
 */
function caso5(): void
{
    echo 'Caso 5: la cola de premios reparte por orden y por hora', PHP_EOL;

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
