<?php

/**
 * Enrutador de URLs.
 *
 * Traduce la ruta de la URL al metodo de un controlador, y centraliza la
 * conversion de excepciones en respuestas HTTP.
 *
 * ============================================================================
 * POR QUE UN ENRUTADOR PROPIO Y NO UNO DE FRAMEWORK
 * ============================================================================
 *
 * Por la decision D14, sin dependencias externas. En total son unas 200 lineas
 * y cubren lo que esta aplicacion necesita: rutas con parametros, metodos
 * HTTP, control de rol y traduccion de excepciones. Un framework traeria
 * ademas toda una infraestructura de la que aqui no se usa ni el 10%.
 *
 * ============================================================================
 * FORMATO DE LAS RUTAS
 * ============================================================================
 *
 * Las rutas se declaran con el nombre del controlador y del metodo separados
 * por «:», y con los parametros entre llaves. Un ejemplo real de este proyecto:
 *
 *     $r->get('/admin/tramos/{id}/editar', 'AdminTramos:editar');
 *
 * Una URL con un texto que no sea un numero produce un 404 en lugar de intentar
 * buscar por «abc», y la comprobacion del formato se hace antes de la consulta
 * a la base de datos, que es donde de verdad importa.
 *
 * ============================================================================
 * POR QUE UN 404 Y NO UN 403 CUANDO FALTA PERMISO
 * ============================================================================
 *
 * Cuando una azafata pide una pantalla del panel, la respuesta es 404 y no 403.
 * Un 403 confirmaria que la pagina existe, y probando rutas una persona
 * cualquiera podria enumerar todas las pantallas de administracion. Un 404 no
 * confirma nada. Ver \App\Core\NoEncontrado.
 *
 * @see \App\Core\NoEncontrado
 * @see \App\Core\Controlador
 * @see apartado 3 de la especificacion, roles y acceso
 * @see decisiones D5 y D14
 */

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Tabla de rutas y dispatcher de controladores.
 */
class Router
{
    /**
     * Rutas registradas, indexadas por metodo HTTP y luego por patron.
     *
     * El patron de la clave es el texto de la ruta con los parametros
     * sustituidos por su posicion, por ejemplo «admin/tramos/{id}/editar».
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $rutas = [];

    /**
     * Filtro de rol exigido a una ruta.
     *
     * Se guarda aparte y no en la definicion de la ruta para poder compararla
     * sin parsear de nuevo el patron.
     *
     * @var array<string, string|null>
     */
    private array $roles = [];

    /**
     * Enrutador de la peticion en curso.
     *
     * @var self|null
     */
    private static ?self $instancia = null;

    /**
     * Devuelve el enrutador de la peticion en curso, creandolo si hace falta.
     *
     * @return self Enrutador listo para registrar rutas y despachar.
     */
    public static function instancia(): self
    {
        if (!self::$instancia instanceof self) {
            self::$instancia = new self();
        }

        return self::$instancia;
    }

    /**
     * Registra una ruta que responde a peticiones GET.
     *
     * @param string      $patron  Patron de la ruta, con los parametros entre
     *                             llaves, por ejemplo «admin/tramos/{id}».
     * @param string      $controlador Nombre de la clase controladora, sin el
     *                             prefijo App\Controllers, por ejemplo
     *                             «AdminTramos».
     * @param string      $metodo  Nombre del metodo publico que atiende la ruta.
     * @param string|null $rol     Rol exigido, 'administrador' o 'azafata'. Con
     *                             null, la ruta es publica.
     *
     * @return void
     */
    public function get(string $patron, string $controlador, string $metodo, ?string $rol = null): void
    {
        $this->registrar('GET', $patron, $controlador, $metodo, $rol);
    }

    /**
     * Registra una ruta que responde a peticiones POST.
     *
     * @param string      $patron      Patron de la ruta.
     * @param string      $controlador Nombre de la clase controladora.
     * @param string      $metodo      Nombre del metodo publico.
     * @param string|null $rol         Rol exigido, o null si es publica.
     *
     * @return void
     */
    public function post(string $patron, string $controlador, string $metodo, ?string $rol = null): void
    {
        $this->registrar('POST', $patron, $controlador, $metodo, $rol);
    }

    /**
     * Anade una ruta a la tabla.
     *
     * @param string      $metodoHttp  Metodo HTTP al que responde.
     * @param string      $patron      Patron de la ruta.
     * @param string      $controlador Nombre de la clase controladora.
     * @param string      $metodo      Nombre del metodo del controlador.
     * @param string|null $rol         Rol exigido, o null si es publica.
     *
     * @return void
     */
    private function registrar(string $metodoHttp, string $patron, string $controlador, string $metodo, ?string $rol): void
    {
        // Se normaliza el patron quitando la barra inicial y la final, para
        // que «/admin» y «admin» sean la misma ruta y no dos.
        $patron = '/' . trim($patron, '/');

        $this->rutas[$metodoHttp][$patron] = [
            'controlador' => $controlador,
            'metodo'      => $metodo,
        ];

        $this->roles[$metodoHttp . ' ' . $patron] = $rol;
    }

    /**
     * Atiende la peticion actual y ejecuta el controlador correspondiente.
     *
     * @return void
     */
    public function despachar(): void
    {
        $ruta = $this->rutaActual();
        $metodoHttp = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // rutaActual() devuelve la ruta sin barras, por ejemplo «admin/tramos».
        // registrar() guarda las claves CON una barra delante, porque asi las
        // que llevan parametros se distinguen de un vistazo. La busqueda se
        // normaliza igual, y con la misma regla, para que no se comparen
        // «admin/tramos» con «/admin/tramos» y no se encuentre nunca nada.
        $busqueda = '/' . trim($ruta, '/');

        // Se busca una coincidencia exacta primero. Es el camino rapido, y
        // cubre todas las rutas sin parametros, que son la mayoria.
        if (isset($this->rutas[$metodoHttp][$busqueda])) {
            $definicion = $this->rutas[$metodoHttp][$busqueda];
            $this->ejecutar($definicion, [], $this->roles[$metodoHttp . ' ' . $busqueda] ?? null);
            return;
        }

        // Si no hay coincidencia exacta, se prueban las rutas con parametros.
        // Se recorren todas las del metodo HTTP actual.
        foreach ($this->rutas[$metodoHttp] ?? [] as $patron => $definicion) {
            $parametros = $this->coincidir($patron, $ruta);

            if ($parametros !== null) {
                $this->ejecutar($definicion, $parametros, $this->roles[$metodoHttp . ' ' . $patron] ?? null);
                return;
            }
        }

        // Ninguna ruta coincide. Se lanza el 404, que la capa de arriba
        // convierte en la pantalla de error.
        throw new NoEncontrado($ruta);
    }

    /**
     * Devuelve la ruta interna pedida, sin la barra inicial.
     *
     * El valor llega en el parametro «r» que anade el .htaccess. Si no esta
     * presente, se deduce de PATH_INFO o de REQUEST_URI, para que la
     * aplicacion tambien funcione sin mod_rewrite, en URLs del tipo
     * «index.php?r=admin/tramos».
     *
     * @return string Ruta interna normalizada, sin barra inicial ni final.
     */
    public function rutaActual(): string
    {
        $ruta = $_GET['r'] ?? '';

        // Si no hay parametro r, se recurre a PATH_INFO, que es lo que deja
        // Apache cuando AllowOverride no incluye mod_rewrite.
        if ($ruta === '' && isset($_SERVER['PATH_INFO'])) {
            $ruta = (string) $_SERVER['PATH_INFO'];
        }

        // Por ultimo, si tampoco hay PATH_INFO, se quita del REQUEST_URI el
        // directorio en el que vive la aplicacion.
        if ($ruta === '' && isset($_SERVER['REQUEST_URI'])) {
            $uri = (string) $_SERVER['REQUEST_URI'];
            $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

            // De SCRIPT_NAME hay que quitar el NOMBRE DEL FICHERO y quedarse
            // solo con el directorio. En esta instalacion SCRIPT_NAME es
            // «/sorteos/index.php» y lo que hay que quitar del REQUEST_URI es
            // «/sorteos», no «/sorteos/index.php»: el nombre del script no
            // aparece en la URL pedida, porque la reescritura se encargo de
            // esconderlo. Intentar quitar el nombre entero no encuentra nada, y la
            // ruta resultante era «sorteos/login» en vez de «login».
            $directorio = rtrim(str_replace('\\', '/', dirname($script)), '/');

            if ($directorio !== '' && $directorio !== '.' && strncmp($uri, $directorio, strlen($directorio)) === 0) {
                $ruta = substr($uri, strlen($directorio));
            } else {
                $ruta = $uri;
            }

            // Se quita la posible cadena de consulta.
            $posicion = strpos($ruta, '?');
            if ($posicion !== false) {
                $ruta = substr($ruta, 0, $posicion);
            }
        }

        return trim((string) $ruta, '/');
    }

    /**
     * Comprueba si una ruta con parametros coincide con la ruta pedida y
     * devuelve los valores de los parametros.
     *
     * @param string $patron Patron registrado, con los parametros entre llaves.
     * @param string $ruta   Ruta pedida, sin barras.
     *
     * @return array<string, string>|null Parametros extraidos si coincide, o
     *                                   null si no coincide.
     */
    private function coincidir(string $patron, string $ruta): ?array
    {
        // Solo se prueban los patrones que llevan parametros. Es una comprobacion
        // barata que evita partir las rutas estaticas en trozos sin motivo.
        if (strpos($patron, '{') === false) {
            return null;
        }

        $partesPatron = explode('/', trim($patron, '/'));
        $partesRuta = $ruta === '' ? [] : explode('/', $ruta);

        // Si el numero de trozos no es el mismo, no puede coincidir. Esta
        // comprobacion descarta de golpe la mayoria de las rutas candidatas.
        if (count($partesPatron) !== count($partesRuta)) {
            return null;
        }

        $parametros = [];

        foreach ($partesPatron as $indice => $parte) {
            // Si el trozo del patron empieza y acaba por llave, es un parametro.
            if (strlen($parte) > 2 && $parte[0] === '{' && substr($parte, -1) === '}') {
                $nombre = substr($parte, 1, -1);

                // El valor llega ya decodificado por PHP, pero se vuelve a
                // aplicar la decodificacion de URL porque el .htaccess puede
                // haberlo dejado codificado segun como se construya la URL.
                $parametros[$nombre] = rawurldecode($partesRuta[$indice]);
                continue;
            }

            // Un trozo fijo tiene que ser identico al de la ruta pedida.
            if ($parte !== $partesRuta[$indice]) {
                return null;
            }
        }

        return $parametros;
    }

    /**
     * Ejecuta el metodo del controlador indicado, comprobando antes el rol.
     *
     * @param array<string, string> $definicion Pares «controlador» y «metodo».
     * @param array<string, string> $parametros Valores de los parametros de la
     *                                        ruta.
     * @param string|null           $rol        Rol exigido, o null si es publica.
     *
     * @return void
     */
    private function ejecutar(array $definicion, array $parametros, ?string $rol): void
    {
        // El control de acceso se comprueba aqui, en el servidor, y no solo
        // ocultando enlaces en el HTML. Ocultar un enlace no protege nada: la
        // URL se puede escribir a mano. Es lo que exige el apartado 3.
        if ($rol !== null) {
            Autorizacion::exigir($rol);
        }

        // El nombre del controlador se convierte en la clase completa. Se
        // comprueba que exista antes de llamarla, para que un nombre mal
        // escrito en las rutas dea un error claro y no un fallo fatal.
        $clase = '\\App\\Controllers\\' . $definicion['controlador'];

        if (!class_exists($clase)) {
            throw new ErrorAplicacion("No existe el controlador «{$definicion['controlador']}» de la ruta.");
        }

        $controlador = new $clase();

        // El metodo tiene que existir y ser publico. Comprobarlo evita que un
        // error de escritura en las rutas provoque un error fatal de PHP en
        // lugar de un mensaje util.
        if (!method_exists($controlador, $definicion['metodo'])) {
            throw new ErrorAplicacion(
                "El controlador «{$definicion['controlador']}» no tiene el metodo «{$definicion['metodo']}»."
            );
        }

        $metodo = new \ReflectionMethod($controlador, $definicion['metodo']);
        if (!$metodo->isPublic()) {
            throw new ErrorAplicacion(
                "El metodo «{$definicion['metodo']}» de «{$definicion['controlador']}» no es publico."
            );
        }

        $controlador->asignarParametros($parametros);

        // La excepcion se deja subir hasta la capa de index.php, que es la unica
        // que sabe como convertirla en una respuesta HTTP completa.
        $controlador->{$definicion['metodo']}();
    }
}
