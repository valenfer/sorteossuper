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
}$inicio = microtime(true);
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
