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
    // Cuatro horas y ningun correo activado: con el correo apagado el codigo de
    // reclamacion tiene que verse en pantalla, porque es la unica via que le
    // queda a la persona de recoger el premio. Ese es el estado de partida.
    $escenario = crearEscenarioDeAdjudicacion(['10:00', '11:00', '12:00', '13:00']);
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
