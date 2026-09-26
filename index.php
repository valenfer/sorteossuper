<?php

/**
 * Punto de entrada unico de la aplicacion.
 *
 * ============================================================================
 * POR QUE EXISTE UN SOLO FICHERO DE ENTRADA
 * ============================================================================
 *
 * Apache no sabe que hacer con la URL «/sorteos/admin/tramos/7», porque en el
 * disco no hay ninguna carpeta llamada «admin» ni ningun fichero «7». El
 * archivo .htaccess de la raiz lo resuelve: manda cualquier peticion que no
 * corresponda a un fichero real a este index.php, y le pasa la ruta pedida en
 * el parametro «r».
 *
 * La ventaja de que todo entre por aqui, y no por una entrada por carpeta, es
 * que las comprobaciones de seguridad se hacen en un solo sitio y no pueden
 * olvidarse en una pantalla concreta. En cualquier otro diseño, forgetting de
 * proteger un directorio entero seria un fallo de seguridad completo.
 *
 * ============================================================================
 * LO QUE HACE ESTE FICHERO, EN ORDEN
 * ============================================================================
 *
 *  1. Cargar el arranque comun, que registra el autocargador de clases.
 *  2. Arrancar la aplicacion: configuracion, zona horaria, errores y sesion.
 *  3. Poner un manejador de excepciones que convierta cada tipo de fallo en
 *     una respuesta HTTP correcta, con su codigo y su pantalla.
 *  4. Registrar las rutas.
 *  5. Despachar.
 *
 * ============================================================================
 * LOS CODIGOS DE ESTADO NO SON UN DETALLE
 * ============================================================================
 *
 * Una pagina de error que devuelve 200 es peor que no tener pagina de error,
 * porque un script que monitoriza el sitio, un buscador o un proxy verian que
 * todo va bien. Ademas, un 404 sin codigo correcto hace que los navegadores
 * cacheen el error y lo muestren al volver a una URL que ya funciona.
 *
 * Por eso este fichero distingue los cuatro casos de verdad:
 *
 *   - 404: la URL no existe, o el usuario no tiene permiso para verla.
 *   - 403: el CSRF no era valido, o la campana esta en un estado que no admite
 *     la operacion pedida.
 *   - 422: el formulario llego con datos que no pasan la validacion. La pagina
 *     se vuelve a pintar con los errores, que es lo que espera la azafata.
 *   - 500: cualquier otra cosa, que es un fallo nuestro y no de quien esta
 *     usando la pantalla.
 *
 * @see app\inicio.php
 * @see \App\Core\Router
 * @see \App\Core\Aplicacion::configurarErrores()
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Autorizacion;
use App\Controllers\ControladorAcceso;
use App\Core\ErrorAplicacion;
use App\Core\ErrorConfiguracion;
use App\Core\ErrorValidacion;
use App\Core\NoEncontrado;
use App\Core\Redirigir;
use App\Core\Router;

// 1 y 2. El arranque comun registra el autocargador; arrancar() carga la
// configuracion, fija Europe/Madrid, instala los manejadores de error de
// Aplicacion y abre la sesion. El comentario de app/inicio.php explica por que
// este require tiene que existir y no puede esperar al autocargador.
require_once __DIR__ . '/app/inicio.php';

try {
    Aplicacion::arrancar();
} catch (ErrorConfiguracion $e) {
    // Esta es la situacion mas comun en una instalacion nueva: se ha copiado el
    // proyecto pero todavia no se ha ejecutado el instalador. Se responde con
    // instrucciones en HTML, porque el mensaje va a ver alguien que esta
    // abriendo la pagina en un navegador y no en una consola.
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">',
        '<title>Falta instalar</title></head><body>',
        '<h1>La aplicacion todavia no esta instalada</h1>',
        '<p>Ejecuta esto desde la consola de XAMPP y recarga esta pagina:</p>',
        '<pre>php bin\\instalar.php --crear-config</pre>',
        '<p>Si el problema sigue, revisa que exista el fichero ',
        '<code>config/config.php</code>.</p>',
        '</body></html>';
    exit;
} catch (Throwable $e) {
    // Un fallo al arrancar no depende de la peticion, asi que no tiene sentido
    // intentar pintar una pantalla de la aplicacion: todavia no hay datos ni
    // sesion. Se dice lo justo para poder diagnosticar.
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'La aplicacion no se ha podido arrancar: ', $e->getMessage();
    exit;
}

// -----------------------------------------------------------------------------
// 3. Manejador de excepciones propio.
// -----------------------------------------------------------------------------

/**
 * Traduce una excepcion a una respuesta HTTP con su codigo y su pantalla.
 *
 * Se declara como funcion suelta, y no como metodo de una clase, porque es la
 * unica pieza de este fichero que no necesita estado de la aplicacion, y
 * declararla dentro de index.php deja claro que pertenece a esta capa y a
 * ninguna otra.
 *
 * @param Throwable $e       Excepcion que no se ha tratado antes.
 * @param string    $mensaje Texto ya en claro para la persona que lo ve.
 * @param int       $codigo  Codigo de estado HTTP de la respuesta.
 *
 * @return void
 */
function responderConError(Throwable $e, string $mensaje, int $codigo): void
{
    // El manejador de excepcion de Aplicacion ya ha registrado el fallo en el
    // log. Aqui solo se produce la respuesta, porque registrar el mismo fallo
    // dos veces llenaria el log de duplicados.
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: text/html; charset=utf-8');

        // Las pantallas de error no se cachean nunca. Si una se cacheara, al
        // arreglar el problema seguiria apareciendo hasta que expirase.
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $titulo = match ($codigo) {
        403     => 'No permitido',
        404     => 'No encontrado',
        422     => 'Datos incorrectos',
        503     => 'No disponible',
        default => 'Error interno',
    };

    $detalle = '';

    // En desarrollo se enseña la traza. En una campana real, jamas: el mensaje
    // lleva rutas del servidor, nombres de clases y a veces datos de la
    // peticion, que es exactamente lo que no debe salir de la tablet.
    if (Aplicacion::depurar()) {
        $detalle = '<pre class="traza">'
            . htmlspecialchars((string) $e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre>';
    }

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">',
        '<meta name="viewport" content="width=device-width, initial-scale=1">',
        '<meta name="robots" content="noindex, nofollow">',
        '<title>', htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'), '</title>',
        '<style>body{font-family:system-ui,sans-serif;max-width:44rem;margin:4rem auto;',
        'padding:0 1rem;line-height:1.5}h1{font-size:1.5rem}',
        'pre.traza{background:#f4f4f4;padding:1rem;overflow:auto;',
        'font-size:.8rem;white-space:pre-wrap}a{color:#06c}</style>',
        '</head><body>',
        '<h1>', htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8'), '</h1>',
        '<p>', htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'), '</p>',
        $detalle,
        '<p><a href="', htmlspecialchars(Aplicacion::url(''), ENT_QUOTES, 'UTF-8'), '">Volver al inicio</a></p>',
        '</body></html>';
}

// El manejador sustituye al de Aplicacion, que se limita a imprimir la traza
// desnuda. Aqui se decide el codigo de estado, que es lo que un cliente HTTP
// necesita para saber que ha pasado de verdad.
set_exception_handler(static function (Throwable $e): void {
    // Una redireccion no es un error y no se pinta ninguna pantalla: solo se
    // manda la cabecera Location y el codigo correspondiente. Se comprueba antes
    // que las cabeceras no se hayan enviado ya, porque si la redireccion salta
    // desde codigo que ya ha empezado a escribir la respuesta, lo unico que se
    // puede hacer es terminar la pagina tal cual.
    if ($e instanceof Redirigir) {
        if (!headers_sent()) {
            http_response_code($e->codigo());
            header('Location: ' . Aplicacion::url($e->ruta()), true, $e->codigo());
        }

        return;
    }

    if ($e instanceof NoEncontrado) {
        // Se agrupan 403 y 404 a proposito, y no por descuido. Distinguir «no
        // existe» de «no tienes permiso» permitiria a un atacante adivinar que
        // URLs existen probandolas con cuentas distintas, que es justo lo que
        // el apartado 3 prohibe. Las dos responden igual.
        responderConError($e, 'La pagina que buscas no existe o no puedes verla.', 404);
        return;
    }

    if ($e instanceof ErrorValidacion) {
        responderConError($e, 'Los datos del formulario no son validos.', 422);
        return;
    }

    if ($e instanceof ErrorAplicacion) {
        // Un ErrorAplicacion es siempre un fallo de programacion o de
        // despliegue: una vista que no esta, una ruta mal escrita, una tabla
        // que falta. El usuario no puede arreglarlo, asi que se le da el boton
        // de recargar y ya.
        responderConError($e, 'La pagina no se ha podido mostrar.', 500);
        return;
    }

    responderConError($e, 'Se ha producido un error inesperado.', 500);
});

// -----------------------------------------------------------------------------
// 4. Rutas.
// -----------------------------------------------------------------------------

$router = Router::instancia();

// ---- Acceso ---------------------------------------------------------------
// Las tres rutas de acceso son publicas, porque para entrar hay que poder
// llegar a ellas sin estar dentro. El resto de la aplicacion, no.
//
// El nombre del controlador va SIN el namespace, porque el enrutador lo
// completa con «App\Controllers». Se escribe como cadena, y no como
// ControladorAcceso::class, para no tener dos formas de nombrar lo mismo en el
// mismo fichero.
$router->get('login', 'ControladorAcceso', 'formulario');
$router->get('', 'ControladorAcceso', 'formulario');
$router->post('login', 'ControladorAcceso', 'entrar');
$router->post('salir', 'ControladorAcceso', 'salir');

// ---- Pantallas de destino -------------------------------------------------
// Cada rol exige su propia pantalla. El enrutador comprueba el rol en el
// servidor, antes de ejecutar nada, de modo que escribir «/azafata» a mano sin
// ser azafata no abre la pantalla: responde igual que una URL inexistente.
$router->get('admin', 'ControladorCampanas', 'listar', Autorizacion::ROL_ADMINISTRADOR);
$router->get('azafata', 'ControladorInicio', 'mostrador', Autorizacion::ROL_AZAFATA);

// ---- Panel del administrador ----------------------------------------------
// Todas las rutas del panel exigen el rol de administrador, y todas lo ponen una
// a una. No hay un «prefijo que las cubre a todas» en este enrutador, y anadirlo
// por el camino corto seria justo el tipo de olvido que hace que una pantalla
// sensible quede abierta. Ver \App\Core\Router::ejecutar().
//
// El orden de las declaraciones no importa para el despacho: el enrutador prueba
// primero las coincidencia exactas y despues las que llevan parametros. Se
// agrupan por pantalla y no por metodo HTTP para que se lea como el menu del
// panel.
$admin = Autorizacion::ROL_ADMINISTRADOR;

// Listado de campanas y datos generales.
$router->get('admin/campanas/nueva', 'ControladorCampanas', 'nueva', $admin);
$router->post('admin/campanas/nueva', 'ControladorCampanas', 'crear', $admin);
$router->get('admin/promociones/{id}', 'ControladorCampanas', 'ficha', $admin);
$router->get('admin/promociones/{id}/editar', 'ControladorCampanas', 'editar', $admin);
$router->post('admin/promociones/{id}/editar', 'ControladorCampanas', 'actualizar', $admin);

// Activacion. Es un POST porque manda correo y saca premios de la caja: no
// puede saltar con un clic de mas en un enlace.
$router->post('admin/promociones/{id}/activar', 'ControladorCampanas', 'activar', $admin);

// Premios.
$router->get('admin/promociones/{id}/premios', 'ControladorFormulario', 'premios', $admin);
$router->post('admin/promociones/{id}/premios', 'ControladorFormulario', 'crearPremio', $admin);
$router->post('admin/promociones/{id}/premios/{premio}/alternar', 'ControladorFormulario', 'alternarPremio', $admin);

// Formulario de participacion.
$router->get('admin/promociones/{id}/formulario', 'ControladorFormulario', 'formulario', $admin);
$router->post('admin/promociones/{id}/formulario', 'ControladorFormulario', 'guardarFormulario', $admin);

// Participacion en la campana.
$router->get('admin/promociones/{id}/participar', 'ControladorParticipacion', 'formulario', $admin);
$router->post('admin/promociones/{id}/participar', 'ControladorParticipacion', 'registrar', $admin);

// Reglas, ajustes y apariencia.
$router->get('admin/promociones/{id}/reglas', 'ControladorCampana', 'reglas', $admin);
$router->post('admin/promociones/{id}/reglas', 'ControladorCampana', 'guardarReglas', $admin);
$router->get('admin/promociones/{id}/ajustes', 'ControladorCampana', 'ajustes', $admin);
$router->post('admin/promociones/{id}/ajustes', 'ControladorCampana', 'guardarAjustes', $admin);
$router->get('admin/promociones/{id}/apariencia', 'ControladorCampana', 'apariencia', $admin);
$router->post('admin/promociones/{id}/apariencia', 'ControladorCampana', 'guardarApariencia', $admin);

// Tramos y cantidades por tramo.
$router->get('admin/promociones/{id}/tramos', 'ControladorCampana', 'tramos', $admin);
$router->post('admin/promociones/{id}/tramos', 'ControladorCampana', 'crearTramo', $admin);
$router->post('admin/promociones/{id}/tramos/{tramo}/borrar', 'ControladorCampana', 'borrarTramo', $admin);
$router->post('admin/promociones/{id}/tramos/{tramo}/cantidades', 'ControladorCampana', 'guardarCantidades', $admin);

// Calendario.
$router->get('admin/promociones/{id}/calendario', 'ControladorCampana', 'calendario', $admin);
$router->post('admin/promociones/{id}/calendario/generar', 'ControladorCampana', 'generarCalendario', $admin);
$router->post('admin/promociones/{id}/calendario/{unidad}/retirar', 'ControladorCampana', 'retirarUnidad', $admin);

// -----------------------------------------------------------------------------
// 5. Despacho.
// -----------------------------------------------------------------------------

try {
    $router->despachar();
} catch (Redirigir $e) {
    // El nucleo lanza esta excepcion para cortar la peticion y mandar al
    // navegador a otra pagina. No se pinta ninguna pantalla de error, porque no
    // es un error: es el comportamiento normal de quien abre una pantalla
    // privada sin haber entrado.
    if (!headers_sent()) {
        http_response_code($e->codigo());
        header('Location: ' . Aplicacion::url($e->ruta()), true, $e->codigo());
    }
} catch (NoEncontrado $e) {
    responderConError($e, 'La pagina que buscas no existe o no puedes verla.', 404);
} catch (ErrorValidacion $e) {
    // El token CSRF caducado es el caso mas frecuente aqui, y merece un
    // mensaje propio: recargar la pagina lo arregla, y decir solo «los datos
    // no son validos» dejaria a la azafata sin saber que hacer.
    //
    // La comprobacion es al reves de lo que parece a primera vista: se mira si
    // NO hay lista de errores. Un ErrorValidacion sin errores es el caso del
    // token caducado, que no viene con una lista porque no es un dato
    // incorrecto, sino que falta una comprobacion. Si la lista no esta vacia,
    // entonces si son datos, y se enseña lo que falla.
    if ($e->errores() === []) {
        $mensaje = 'La sesion ha caducado. Recarga la pagina e intentalo de nuevo.';
    } else {
        $mensaje = implode(' ', array_values($e->errores()));
    }

    responderConError($e, $mensaje, 403);
} catch (ErrorAplicacion $e) {
    responderConError($e, 'La pagina no se ha podido mostrar.', 500);
}
