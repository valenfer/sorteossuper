<?php

/**
 * Nucleo de arranque de la aplicacion.
 *
 * Esta clase hace todo lo que hay que hacer antes de poder atender una
 * peticion, y centraliza los servicios que despues usa el resto del codigo:
 * configuracion, acceso a la base de datos, zona horaria, sesion, plantillas y
 * generacion de URLs.
 *
 * ============================================================================
 * POR QUE NO HAY UN FRAMEWORK
 * ============================================================================
 *
 * El apartado 2 de la especificacion prohibe expresamente usar frameworks, y la
 * decision D14 va mas alla: cero dependencias externas, sin Composer, sin
 * jQuery y sin nada descargado de internet. En la practica eso significa que
 * las cuatro piezas que un framework suele aportar estan aqui, a mano y con
 * unas pocas lineas cada una:
 *
 *   - Autocarga de clases: \App\Core\Aplicacion::registrarAutoloader()
 *   - Inyeccion de dependencias: \App\Core\Aplicacion::db() y ::config()
 *   - Plantillas: \App\Core\Vista
 *   - Enrutado: \App\Core\Router
 *
 * Para un proyecto de este tamano sale mas barato escribirlas que mantenerlas
 * actualizadas, y evita el problema practico de que una app que vive en el
 * disco de un supermercado y se actualiza con un fichero nuevo se quede sin
 * funcionando por una dependencia que ha cambiado.
 *
 * ============================================================================
 * ZONA HORARIA
 * ============================================================================
 *
 * Se fija de forma explicita a Europe/Madrid y no se hereda de php.ini. La
 * instalacion de XAMPP de este entorno tiene «Europe/Berlin», que va una hora
 * por delante en invierno y dos en verano. Ese desfase no es un detalle
 * cosmético: compararia la hora actual con la hora programada de los premios en
 * una zona distinta de la suya, y adjudicaria antes o despues de lo debido.
 * Ver la decision D7.
 *
 * @see \App\Core\Db
 * @see \App\Core\Router
 * @see \App\Core\Vista
 * @see decisiones D5, D7 y D14 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Arranque de la aplicacion y contenedor de servicios.
 */
class Aplicacion
{
    /**
     * Variable de entorno que permite leer la configuracion de otro fichero.
     *
     * Lo usa el caso 19, que necesita que las peticiones HTTP se ejecuten
     * contra la base de pruebas. No hay otra forma de conseguirlo: una
     * peticion web no puede cambiar de base a proposito, porque eso permitiria
     * que un POST moviese los datos de una campana real. Cambiar el fichero
     * de configuracion de todo el proceso es otra cosa: lo decide quien arranca
     * el servidor, no quien envia el formulario.
     *
     * @var string
     */
    public const VARIABLE_CONFIG = 'SORTEOS_CONFIG';
    /**
     * Ruta absoluta de la raiz del proyecto, con barra final.
     *
     * @var string
     */
    private static string $raiz = '';

    /**
     * Configuracion cargada, ya combinada con la plantilla.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $config = null;

    /**
     * Ruta base con la que se montan las URLs, por ejemplo «/sorteos».
     *
     * @var string
     */
    private static string $urlBase = '';

    /**
     * Ruta desde la que se sirve la carpeta de assets, por ejemplo «/sorteos/assets».
     *
     * @var string
     */
    private static string $rutaAssets = '';

    /**
     * Bandera que indica si el arranque ya se ha completado.
     *
     * Sirve para no repetir la inicializacion si algo vuelve a llamar a
     * arrancar(), y para detectar el uso de servicios sin arrancar, que en ese
     * caso daria un fallo dificil de entender.
     *
     * @var bool
     */
    private static bool $arrancada = false;

    /**
     * Bandera que indica que el autocargador ya esta registrado.
     *
     * Existe por un problema de arranque. Para llamar a un metodo estatico de
     * esta clase, PHP tiene que haber cargado la clase antes, y solo puede
     * cargarla mediante el autocargador, que es precisamente lo que se registra
     * aqui dentro. Los puntos de entrada, como index.php o los scripts de
     * consola, resuelven el nudo con un require explicito de este fichero, y
     * luego llaman a arrancar(), que pasaria por aqui otra vez. Sin esta
     * bandera, el autocargador quedaria registrado dos veces y cada clase se
     * buscaria en disco por duplicado.
     *
     * @var bool
     */
    private static bool $autoloaderRegistrado = false;

    /**
     * Registra el autocargador de clases de la aplicacion.
     *
     * Es la sustitucion de Composer. Convierte un nombre de clase en la ruta
     * del fichero que la contiene, respetando la convencion PSR-4:
     *
     *     \App\Core\Db   ->   app\Core\Db.php
     *     \App\Models\User -> app\Models\User.php
     *
     * Para que funcione, el namespace debe coincidir exactamente con la carpeta.
     * Por eso el directorio se escribe «app\Core» y el namespace «App\Core»:
     * en Windows da igual, pero en Linux la coincidencia tiene que ser exacta y
     * asi queda bien desde el principio.
     *
     * @param string $raiz Ruta absoluta de la raiz del proyecto. El metodo
     *                      puede deducirla, pero se acepta como parametro para
     *                      que los scripts de consola, que no pasan por index.php,
     *                      puedan indicarla.
     *
     * @return void
     */
    public static function registrarAutoloader(string $raiz): void
    {
        // Se guarda la raiz para que el resto de metodos la usen. Se normaliza
        // a barras, que PHP entiende igual en Windows y en Linux, y se le anade
        // una barra final para poder concatenar sin comprobar nada.
        self::$raiz = rtrim(str_replace('\\', '/', $raiz), '/') . '/';

        // Registrar dos veces el mismo autocargador haria que cada clase se
        // buscase en disco por duplicado. El punto de entrada puede haberlo
        // registrado ya antes de llamar a arrancar(), que es el caso normal en
        // consola.
        if (self::$autoloaderRegistrado) {
            return;
        }

        self::$autoloaderRegistrado = true;

        // La clase «Aplicacion» vive en app\Core\Aplicacion.php, asi que el
        // prefijo de ruta incluye la carpeta app. Sin este «app/», el
        // autocargador buscaria <raiz>/Core/Aplicacion.php, que no existe, y el
        // fallo apareceria como «class not found» en la primera clase que se
        // usara despues.
        $prefijoRuta = self::$raiz . 'app/';

        spl_autoload_register(static function (string $clase) use ($prefijoRuta): void {
            // Solo se atienden las clases del espacio de nombres de la
            // aplicacion. Cualquier otra clase, venga de donde venga, la deja
            // pasar al siguiente autocargador: PHP se encarga de las clases
            // internas y de las de las extensiones.
            $prefijoClase = 'App\\';
            if (strncmp($clase, $prefijoClase, strlen($prefijoClase)) !== 0) {
                return;
            }

            // Se quita el prefijo del namespace y se convierte la barra
            // invertida del namespace en el separador de directorios.
            $rutaRelativa = substr($clase, strlen($prefijoClase));
            $rutaFichero = $prefijoRuta . str_replace('\\', '/', $rutaRelativa) . '.php';

            // Solo se carga el fichero si existe de verdad. Sin esta
            // comprobacion, require lanzaria un error fatal que convertiria
            // un simple error de escritura en un fallo de pantalla.
            if (is_file($rutaFichero)) {
                require_once $rutaFichero;
            }
        });
    }

    /**
     * Carga la configuracion combinando la plantilla con las anulaciones
     * locales.
     *
     * @return array<string, mixed> Configuracion completa de la aplicacion.
     *
     * @throws \App\Core\ErrorConfiguracion Si falta config/config.php.
     */
    public static function config(): array
    {
        // Se devuelve la copia ya cargada. Volver a leer los ficheros en cada
        // llamada seria un desperdicio, y algunos valores, como el secreto de
        // HMAC, deben ser siempre el mismo durante toda la peticion.
        if (is_array(self::$config)) {
            return self::$config;
        }

        $ficheroConfig = self::$raiz . 'config/config.php';

        // Si la variable de entorno apunta a otro fichero, ese es el que se lee.
        // Se comprueba que exista antes de cargarlo y, si no, se falla: caer en
        // silencio en config/config.php seria justo el fallo peligroso, que es
        // creer que se esta probando contra una base y estar escribiendo en otra.
        $ficheroIndicado = getenv(self::VARIABLE_CONFIG);
        if (is_string($ficheroIndicado) && trim($ficheroIndicado) !== '') {
            $ficheroConfig = self::rutaConfigIndicada(trim($ficheroIndicado));
        }

        // El mensaje distingue este fallo del caso en que el fichero existe
        // pero esta mal formado, que daria un error distinto y mas claro.
        if (!is_file($ficheroConfig)) {
            throw ErrorConfiguracion::faltaArchivoConfig();
        }

        // El fichero devuelve el array de configuracion. Si devuelve otra cosa,
        // es que esta mal escrito y hay que decirlo con claridad.
        $config = require $ficheroConfig;
        if (!is_array($config)) {
            throw new ErrorConfiguracion(
                $ficheroConfig . ' deberia devolver un array de configuracion.'
            );
        }

        self::$config = $config;
        return self::$config;
    }

    /**
     * Resuelve la ruta del fichero de configuracion indicado por entorno.
     *
     * Una ruta relativa se resuelve desde la raiz del proyecto, para que la
     * variable valga desde cualquier carpeta. Se normalizan las barras porque
     * en Windows se escribe con contrabarras y PHP entiende las dos.
     *
     * @param string $indicada Ruta tal y como viene en la variable de entorno.
     *
     * @return string Ruta absoluta, con barras, apuntando al fichero.
     *
     * @throws ErrorConfiguracion Si la ruta no existe o no es un fichero.
     */
    private static function rutaConfigIndicada(string $indicada): string
    {
        $ruta = str_replace('\\', '/', $indicada);

        if (!self::esAbsoluta($ruta)) {
            $ruta = self::$raiz . $ruta;
        }

        if (!is_file($ruta)) {
            throw ErrorConfiguracion::rutaConfigInvalida($indicada);
        }

        return $ruta;
    }

/**
     * Dice si una ruta con barras es absoluta, en los dos estilos de Windows y
     * en los de Unix.
     *
     * En Windows cuenta como absoluta «C:/...». Con una sola letra sin barra no
     * lo es: «C:» a secas es la carpeta actual de esa unidad.
     *
     * @param string $ruta Ruta ya normalizada a barras.
     *
     * @return bool
     */
    private static function esAbsoluta(string $ruta): bool
    {
        if ($ruta === '' || $ruta[0] !== '/') {
            // Una letra de unidad seguida de dos puntos es el otro caso de
            // ruta absoluta en Windows, y «C:/...» ya empieza por barra y ha
            // salido antes por la otra condicion.
            return preg_match('#^[A-Za-z]:/#', $ruta) === 1;
        }

        return true;
    }

    /**
     * Devuelve un valor de configuracion mediante su ruta con notacion de
     * puntos.
     *
     * @param string $ruta    Ruta de la clave, por ejemplo «bd.host» o
     *                        «correo.smtp.puerto». Sin espacios.
     * @param mixed  $defecto Valor a devolver si la clave no existe.
     *
     * @return mixed Valor de la clave, o el valor por defecto indicado.
     */
    public static function ajuste(string $ruta, $defecto = null)
    {
        // Se parte de la configuracion completa y se baja por la ruta. El
        // operador ?? encadenado no se puede usar porque PHP no permite ?? con
        // indices, de ahi el bucle.
        $actual = self::config();

        foreach (explode('.', $ruta) as $parte) {
            if (!is_array($actual) || !array_key_exists($parte, $actual)) {
                return $defecto;
            }
            $actual = $actual[$parte];
        }

        return $actual;
    }

    /**
     * Devuelve la conexion a la base de datos de la aplicacion.
     *
     * @return \App\Core\Db Conexion unica de la peticion en curso.
     *
     * @throws \App\Core\ErrorBaseDeatos Si no se puede conectar.
     */
    public static function db(): Db
    {
        // Se pasa la configuracion a proposito: la instancia unica guarda la
        // primera conexion que se creo, y en las pruebas hay que poder crear
        // una segunda contra la base de pruebas sin que la primera la impida.
        return Db::instancia(self::config()['bd']);
    }

    /**
     * Devuelve la ruta absoluta de la raiz del proyecto, con barra final.
     *
     * @return string Ruta absoluta, terminada en barra.
     */
    public static function raiz(): string
    {
        return self::$raiz;
    }

    /**
     * Inicializa la aplicacion: autocargador, configuracion, zona horaria,
     * errores y sesion.
     *
     * Es idempotente. Si la aplicacion ya esta arrancada, la llamada no hace
     * nada, de modo que index.php y los scripts de consola pueden invocarlo sin
     * tener que coordinarse.
     *
     * @param string $raiz Ruta absoluta de la raiz del proyecto. Si se omite, se
     *                      deduce de la ubicacion de este propio fichero, que
     *                      es la forma que usan tanto el servidor web como la
     *                      consola.
     *
     * @return void
     *
     * @throws \App\Core\ErrorConfiguracion Si falta la configuracion.
     */
    public static function arrancar(?string $raiz = null): void
    {
        if (self::$arrancada) {
            return;
        }

        // 1. Autocargador. Va primero porque el resto del arranque va a usar
        //    clases propias, y sin el no se podrian cargar.
        if ($raiz === null) {
            // __DIR__ es app\Core. Hay que subir dos niveles para llegar a la
            // raiz del proyecto, que es donde vive la carpeta app.
            $raiz = dirname(__DIR__, 2);
        }
        self::registrarAutoloader($raiz);

        // 2. Configuracion. Se lee ya aqui para que un error de configuracion
        //    aparezca antes de tocar la base de datos o la sesion.
        self::config();

        // 3. Zona horaria. Se fija antes que nada que use fechas, para que la
        //    conversion de dia de luz no sorprenda a nadie mas tarde.
        date_default_timezone_set((string) self::ajuste('app.zona_horaria', 'Europe/Madrid'));

        // 4. Avisos y conversiones a excepciones. Convertir los avisos en
        //    excepciones es lo que permite que un acceso a un indice inexistente
        //    quede registrado y no pase desapercibido.
        self::configurarErrores();

        // 5. Rutas web.
        self::calcularRutasWeb();

        // 6. Sesion. Solo se abre si el contexto es de peticion web: los
        //    scripts de consola no tienen cookie y abrirla ahi daria avisos.
        if (self::esPeticionWeb()) {
            self::abrirSesion();
        }

        self::$arrancada = true;
    }

    /**
     * Indica si la aplicacion ya se ha arrancado.
     *
     * @return bool True si arrancar() se ha completado.
     */
    public static function estaArrancada(): bool
    {
        return self::$arrancada;
    }

    /**
     * Configura la gestion de avisos y excepciones no capturadas.
     *
     * Dos modos, segun el entorno configurado:
     *
     *   - 'local'  -> se muestra el error por pantalla, con su traza. Solo en
     *                 desarrollo, nunca con datos reales de una campana.
     *   - otro     -> se registra en el log y se muestra un mensaje generico.
     *
     * En los dos casos se registra siempre en el log, porque el log es lo unico
     * que ayuda si el fallo aparece en la tablet de una tienda.
     *
     * @return void
     */
    private static function configurarErrores(): void
    {
        // El nivel de aviso se fija a E_ALL para que no se esconda ninguno,
        // incluido el mas estricto. Con la vista de desarrollo activada se
        // enciende la pantalla con el problema, que es lo que se quiere al
        // programar; en produccion se registra y se calla.
        error_reporting(E_ALL);
        ini_set('display_errors', '0');

        // Los avisos y las notificaciones se convierten en excepciones para que
        // el manejador de abajo los trate igual que un error. Un aviso por
        // variable no definida o por division entre cero no debe pasar
        // desapercibido en una aplicacion que maneja datos personales.
        set_error_handler(static function (int $nivel, string $mensaje, string $fichero, int $linea): bool {
            // Se respetan los avisos desactivados con @, que se usan todavia en
            // algunas funciones de PHP y no interestan aqui.
            if ((error_reporting() & $nivel) === 0) {
                return false;
            }

            // Los avisos E_NOTICE y E_DEPRECATED se elevate a excepcion solo si
            // el desarrollo esta activo. En una campana real, un aviso antiguo
            // de una libreria no debe impedir que una clienta participe.
            if (!self::depurar() && ($nivel === E_DEPRECATED || $nivel === E_NOTICE || $nivel === E_USER_DEPRECATED)) {
                return false;
            }

            throw new \ErrorException($mensaje, 0, $nivel, $fichero, $linea);
        });

        // El manejador de excepciones no capturadas. Registra y, segun el
        // entorno, muestra el error o una pantalla limpia.
        set_exception_handler(static function (Throwable $e): void {
            self::registrarError($e);

            if (self::depurar()) {
                // Se imprime la traza completa. Solo en desarrollo.
                echo '<pre style="background:#fdd;padding:1rem;white-space:pre-wrap;">';
                echo htmlspecialchars((string) $e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                echo '</pre>';
                return;
            }

            http_response_code(500);
            echo '<h1>Error interno</h1>';
            echo '<p>Se ha producido un error y se ha registrado. Avise al responsable.</p>';
        });
    }

    /**
     * Indica si la aplicacion esta en modo de desarrollo.
     *
     * @return bool True si se deben mostrar los errores internos por pantalla.
     */
    public static function depurar(): bool
    {
        return (bool) self::ajuste('app.depurar', false) && self::ajuste('app.entorno', 'local') === 'local';
    }

    /**
     * Escribe una entrada de error en el fichero de log.
     *
     * El log va a storage/logs/error.log, que esta fuera de todo lo que
     * Apache sirve. Se crean los permisos del fichero con 0600 porque puede
     * contener datos de la peticion.
     *
     * @param \Throwable $e Excepcion a registrar.
     *
     * @return void
     */
    private static function registrarError(Throwable $e): void
    {
        // Si la peticion viene de la web se registra tambien el sitio de
        // llamada, que es lo que permite saber de que pantalla salio el fallo.
        $sitio = self::esPeticionWeb() && isset($_SERVER['REQUEST_URI'])
            ? (string) $_SERVER['REQUEST_URI']
            : 'consola';

        $linea = sprintf(
            "[%s] %s: %s en %s:%d\nPeticion: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $sitio,
            $e->getTraceAsString()
        );

        // Si no se puede escribir el log no se debe generar un segundo error.
        // En una tablet con el disco lleno, fallar al fallar seria peor que no
        // tener log. Se silencia a proposito y se sigue.
        $directorio = self::$raiz . 'storage/logs';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        @file_put_contents($directorio . '/error.log', $linea, FILE_APPEND | LOCK_EX);
    }

    /**
     * Calcula la ruta base con la que se montan las URLs.
     *
     * La aplicacion vive en /sorteos dentro de XAMPP, pero el mismo codigo
     * tiene que funcionar en la raiz del dominio o en un subdirectorio
     * cualquiera. En lugar de fijarlo en la configuracion, que habria que
     * cambiar en cada instalacion, se deduce de la URL con la que se ha
     * llamado al script. Es una decion que evita tener que tocar la
     * configuracion al cambiar de carpeta o de servidor.
     *
     * @return void
     */
    private static function calcularRutasWeb(): void
    {
        if (!self::esPeticionWeb() || !isset($_SERVER['SCRIPT_NAME'])) {
            // En consola no hay URL, pero los scripts necesitan una raiz para
            // las rutas de sus enlaces. Se deja vacia y se usa raiz().
            self::$urlBase = '';
            self::$rutaAssets = '';
            return;
        }

        // SCRIPT_NAME es, en esta instalacion, «/sorteos/index.php».
        $script = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);

        // Se quita el nombre del script para quedarse con la carpeta.
        $carpeta = rtrim(str_replace('/index.php', '', $script), '/');

        self::$urlBase = $carpeta;
        self::$rutaAssets = $carpeta . '/assets';
    }

    /**
     * Devuelve la ruta base con la que se anteponen las URLs internas.
     *
     * @return string Ruta sin barra final, por ejemplo «/sorteos».
     */
    public static function urlBase(): string
    {
        return self::$urlBase;
    }

    /**
     * Devuelve la ruta desde la que se sirven los assets.
     *
     * @return string Ruta sin barra final, por ejemplo «/sorteos/assets».
     */
    public static function rutaAssets(): string
    {
        return self::$rutaAssets;
    }

    /**
     * Monta una URL interna a partir de una ruta relativa de la aplicacion.
     *
     * @param string $ruta  Ruta interna, por ejemplo «admin/tramos». La barra
     *                      inicial es opcional.
     * @param array<string, string|int> $consulta Parametros de la cadena de
     *                      consulta, que se anaden ya escapados.
     *
     * @return string URL completa, lista para un atributo href.
     */
    public static function url(string $ruta = '', array $consulta = []): string
    {
        $ruta = ltrim($ruta, '/');
        $url = self::$urlBase . '/' . $ruta;

        if ($consulta !== []) {
            // http_build_query se encarga de escapar los valores, de modo que
            // un valor con acentos o con & no rompe la URL.
            $url .= '?' . http_build_query($consulta);
        }

        return $url;
    }

    /**
     * Monta la URL de un asset, anadiendo la huella de su contenido.
     *
     * La huella cambia en cuanto cambia el fichero, lo que permite cachear los
     * assets durante un ano sin que un cambio obligue a recargar a mano en la
     * tablet.
     *
     * @param string $ruta Ruta del asset relativa a la carpeta assets, por
     *                      ejemplo «css/estilos.css».
     *
     * @return string URL del asset con el parametro de version.
     */
    public static function asset(string $ruta): string
    {
        $ruta = ltrim($ruta, '/');
        $fichero = self::$raiz . 'assets/' . $ruta;

        $version = is_file($fichero) ? substr((string) md5_file($fichero), 0, 8) : '0';

        return self::$rutaAssets . '/' . $ruta . '?v=' . $version;
    }

    /**
     * Cambia el nombre de la base de datos para el resto de la peticion.
     *
     * ============================================================================
     * PARA QUE EXISTE Y POR QUE NO ES UNA TRAMPA
     * ============================================================================
     *
     * Lo usa la suite de pruebas, y solo ella, para trabajar sobre
     * «sorteos_test» en lugar de sobre «sorteos». Se ha decidido que sea una
     * llamada explicita y no un parametro escondido de arranque, porque en
     * cuanto exista la posibilidad de cambiar de base, tiene que quedar claro
     * en el codigo quien la cambia y por que.
     *
     * El riesgo real de un metodo asi es que alguien lo use en produccion por
     * error. Para que no pueda pasar en silencio, este metodo se niega a
     * funcionar si no hay una sesion web de por medio, es decir, si no es la
     * consola. La aplicacion web, que es la que tiene datos de clientes, no
     * tiene ninguna forma de alcanzarlo.
     *
     * Ademas deja constancia en el log, con un aviso, de cada vez que se usa.
     * Si alguna vez apareciera una escritura a la base equivocada en el log de
     * errores, se veria de donde salio.
     *
     * @param string $nombre Nombre de la base de datos que se pasa a usar.
     *
     * @return void
     *
     * @throws \App\Core\ErrorAplicacion Si se llama desde una peticion web, o si
     *                                   el nombre no es valido.
     */
    public static function usarBaseDePruebas(string $nombre): void
    {
        if (self::esPeticionWeb()) {
            throw new ErrorAplicacion(
                'Cambiar de base de datos no se permite desde una peticion web.'
            );
        }

        // El mismo nombre que se comprueba al crear la base en el instalador.
        // Va aqui porque un nombre con comillas invertidas se podria colar en
        // una sentencia, y aqui la base solo llega a un nombre de base de datos
        // nuevo, pero mejor comprobarlo en los dos sitios.
        if (preg_match('/^[a-zA-Z0-9_]+$/', $nombre) !== 1) {
            throw new ErrorAplicacion("El nombre de base de datos «{$nombre}» no es valido.");
        }

        self::config();

        // Se comprueba que no se esta cambiando a la misma base, para que un
        // error de tecleo en el nombre no se tome por un acierto.
        if ($nombre === (string) self::$config['bd']['nombre']) {
            return;
        }

        self::$config['bd']['nombre'] = $nombre;

        // La conexion ya abierta apunta a la base anterior, asi que se cierra.
        // Sin esto, la siguiente consulta seguiria yendo a la campana, que es
        // justo el fallo que este metodo existe para evitar.
        Db::cerrar();

        error_log(
            '[pruebas] Base de datos cambiada a «' . $nombre . '». '
            . 'Nunca debe aparecer esto fuera de tests/run.php.'
        );
    }

    /**
     * Indica si el proceso actual es una peticion web y no un script de
     * consola.
     *
     * @return bool True si hay una peticion HTTP en curso.
     */
    public static function esPeticionWeb(): bool
    {
        return PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * Abre la sesion con los parametros de seguridad del apartado 3 de la
     * especificacion.
     *
     * Las medidas concretas son:
     *
     *   - cookie httponly: el JavaScript de la pagina no puede leerla, de modo
     *     que un XSS no puede robar la sesion.
     *   - cookie samesite: impide que una peticion venida de otro sitio viaje
     *     con la cookie, que es una parte del ataque CSRF.
     *   - verificacion de sesion: PHP genera un identificador de sesion nuevo
     *     a partir de los datos de la peticion, de modo que un identificador
     *     fijo enviado por un atacante no sirve de nada.
     *   - inactividad: se cierra la sesion si pasa demasiado tiempo sin
     *     actividad, para no dejar datos de una persona visibles en una tablet
     *     desatendida.
     *
     * El uso de la cookie de sesion no se restringe a https porque en el
     * supermercado la aplicacion se sirve por la red local sin TLS, y obligar
     * a https impediria usarla. En una instalacion publica si habria que
     * activarlo; se deja anotado en el README.
     *
     * @return void
     */
    private static function abrirSesion(): void
    {
        // El identificador de sesion se valida antes de usarlo. Si alguien
        // envia un identificador con caracteres no permitidos, PHP lo descarta
        // y genera otro, con lo que se evita el inyeccion arbitraria de sesion.
        if (isset($_COOKIE[session_name()]) && !self::identificadorSesionValido($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
        }

        session_name((string) self::ajuste('sesion.nombre', 'SORTEOSSID'));

        // Estos parametros se fijan ANTES de session_start(), que es cuando se
        // leen. Si se pusieran despues no tendrian ningun efecto.
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::$urlBase . '/',
            'domain'   => '',
            'secure'   => self::ajuste('sesion.httponly_secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // La cookie se borra al cerrar el navegador. La duracion de la sesion
        // la controla el temporizador de inactividad de mas abajo, no la
        // cookie, porque una cookie persistente dejaria al administrador
        // dentro de la campana siguiente en el mismo equipo.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) self::ajuste('sesion.vida_minutos', 240) * 60));

        // Los avisos de sesion de PHP no se muestran al usuario. Se prefiero un
        // error limpio a un aviso en medio de la pantalla de una tienda.
        ini_set('session.display_errors', '0');

        session_start();

        self::comprobarInactividad();
    }

    /**
     * Comprueba si la sesion lleva demasiado tiempo sin actividad y la cierra.
     *
     * @return void
     */
    private static function comprobarInactividad(): void
    {
        $inactividad = (int) self::ajuste('sesion.inactividad_minutos', 30);

        // Con 0 se desactiva la comprobacion. Se deja la posibilidad por si en
        // algun momento hace falta una sesion que no caduque.
        if ($inactividad <= 0) {
            return;
        }

        $ahora = time();
        $ultimo = isset($_SESSION['ultima_actividad']) ? (int) $_SESSION['ultima_actividad'] : 0;

        // Si la sesion es nueva, la marca se pone ahora y no se cierra nada.
        if ($ultimo > 0 && ($ahora - $ultimo) > ($inactividad * 60)) {
            self::cerrarSesion();
            return;
        }

        $_SESSION['ultima_actividad'] = $ahora;
    }

    /**
     * Cierra la sesion y borra sus datos.
     *
     * @return void
     */
    public static function cerrarSesion(): void
    {
        // Se vacian los datos de la sesion antes de destruirla. Sin esto, la
        // cookie podria seguir identificando al mismo navegador con los datos
        // antiguos en el almacenamiento del servidor.
        $_SESSION = [];

        // Se regenera el identificador en lugar de borrar la cookie.
        //
        // Borrarla parece lo natural, pero rompe cualquier aviso que la
        // aplicacion quisiera dejar para la pantalla siguiente: al no haber
        // cookie, la peticion siguiente llega sin identificador, abre una sesion
        // nueva y vacia, y el aviso se queda en una sesion a la que ya no se
        // puede volver. Por eso al salir dice «Has salido del sistema» y no
        // «la sesion ha desaparecido».
        //
        // Regenerar hace las dos cosas: los datos antiguos se borran del disco y
        // el navegador recibe un identificador nuevo, vacio y recien creado, en
        // el que la aplicacion ya puede dejar lo que necesite.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Indica si el identificador de sesion recibido tiene un formato valido.
     *
     * Solo se comprueba el formato. Un identificador bien formado puede ser
     * inventado por un atacante, pero uno mal formado sirve para intentar
     * inyectar caracteres en el identificador de sesion, y eso si conviene
     * cortarlo antes de que llegue a PHP.
     *
     * @param mixed $valor Valor crudo del identificador, de la cookie o de la
     *                     peticion.
     *
     * @return bool True si el formato es aceptable.
     */
    private static function identificadorSesionValido($valor): bool
    {
        // Solo se acepta lo que PHP genera, y el patron de session.sid_length
        // por defecto es de 32 caracteres alfanumericos.
        return is_string($valor)
            && $valor !== ''
            && preg_match('/^[A-Za-z0-9,\-]{16,128}$/', $valor) === 1;
    }

    /**
     * Devuelve el instante actual en la zona horaria de la campana, con el
     * formato que espera la base de datos.
     *
     * ESTE METODO ES EL UNICO LUGAR donde se obtiene la hora actual. Y hay una
     * razon concreta para que sea unico.
     *
     * Todas las fechas de la campana se guardan en hora local de Europa/Madrid
     * (decision D7), sin zona, porque asi son legibles en la base de datos y en
     * el panel. Para comparar la hora actual con la hora programada de un
     * premio hay que traducirla al mismo sitio, y si cada punto del codigo
     * hiciera esa conversion por su cuenta, bastaria que uno se olvidara para
     * que la adjudicacion se desplazase una hora en verano.
     *
     * Centralizarlo aqui evita ese error por construccion.
     *
     * @return string Fecha y hora en formato «A-n-j H:i:s», listo para MySQL.
     */
    public static function ahora(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone((string) self::ajuste('app.zona_horaria', 'Europe/Madrid'))))
            ->format('Y-m-d H:i:s');
    }
}
