<?php

/**
 * Cliente HTTP de verdad para el caso 19, y lo que hace falta para que esas
 * peticiones lleguen a la base de pruebas.
 *
 * ============================================================================
 * POR QUE HACE FALTA UN FICHERO PROPIO
 * ============================================================================
 *
 * El caso 19 no puede parecerse a los demás. Los demás hacen todo en consola:
 * una conexion a la base de pruebas y llamadas a metodos. El caso 19 tiene que
 * enviar dos peticiones HTTP de verdad, simultaneas, con dos sesiones
 * distintas, a un Apache de verdad. Eso son sockets, cabeceras y cookies, que
 * son cosas que no debe hacer a mano el caso 7 y volver a escribir aqui.
 *
 * Y hace falta resolver un problema que no aparece en ningun otro caso: una
 * peticion web no puede cambiar de base de datos a proposito, y con razon, que
 * si pudiera, un formulario moveria los datos de una campana real. La base la
 * decide la configuracion que se lee al arrancar, y aqui no hay nadie que
 * arranque nada, porque el Apache de XAMPP ya estaba encendido. Por eso se le
 * indica con la variable de entorno SORTEOS_CONFIG, que Apache recibe por
 * SetEnv desde un .htaccess temporal, y se le da una configuracion que solo
 * cambia el nombre de la base. Quien decide la base es quien arranca el
 * servidor, no quien manda el formulario.
 *
 * ============================================================================
 * POR QUE LA SIMULTANEIDAD SE DEMUESTRA Y NO SE SUPONE
 * ============================================================================
 *
 * Una prueba de simultaneidad que no demuestra la simultaneidad no vale nada:
 * si el servidor atendiera las dos peticiones encoladas una detras de otra,
 * todas las comprobaciones de resultado darian verde sin haber probado nada.
 *
 * El motor pide un cerrojo con nombre por campana antes de adjudicar nada, en
 * Db::bloquearPromocion. Si la consola lo retiene, las dos peticiones se quedan
 * esperando ese cerrojo dentro del servidor, y eso se ve en
 * information_schema.PROCESSLIST, que dice cuantos hilos estan esperando. Cuando
 * hay dos, el solapamiento esta demostrado y no supuesto. Se suelta el cerrojo
 * despues y las dos peticiones continues.
 *
 * En vez de eso, una barrera dentro del codigo habria exigido anadir una espera
 * a la aplicacion, en el camino que decide quien se lleva un premio de verdad,
 * solo para que una prueba pueda parar ese camino. Eso no se toca.
 */

declare(strict_types=1);

use App\Core\Aplicacion;

/**
 * Texto que precede a la linea que el caso 19 anade al .htaccess.
 *
 * Se busca al quitar la linea para no dejar el fichero de Apache con una
 * modificacion que nadie sabe de donde salio.
 *
 * @var string
 */
const MARCA_APUNTADO = '# Sorteos: caso 19, se retira al terminar';

/**
 * Estado que MariaDB muestra en un hilo esperando un cerrojo con nombre.
 *
 * @var string
 */
const ESTADO_BLOQUEO_NOMBRADO = 'User lock';

/**
 * ============================================================================
 * DIRECCION DEL SERVIDOR
 * ============================================================================
 */

/**
 * Busca un servidor web que sirva la aplicacion y conteste al formulario de
 * acceso.
 *
 * Se prueban un par de direcciones habituales de XAMPP porque «localhost» y
 * «127.0.0.1» no siempre resuelven al mismo sitio, y porque la carpeta que
 * sirve la aplicacion puede no llamarse «sorteos». Con la variable SORTEOS_URL
 * se indica a mano, que es lo que hace falta cuando la aplicacion esta en otra
 * carpeta o detras de otro puerto.
 *
 * @return array<string, mixed>|null Host, puerto y prefijo de URL, o null si no
 *                                    hay ninguno que conteste.
 */
function localizarServidorWeb(): ?array
{
    $indicada = getenv('SORTEOS_URL');
    $candidatas = is_string($indicada) && trim($indicada) !== ''
        ? [trim($indicada)]
        : ['http://localhost/sorteos', 'http://127.0.0.1/sorteos'];

    foreach ($candidatas as $candidata) {
        $partes = parse_url($candidata);

        if ($partes === false || !isset($partes['host'])) {
            continue;
        }

        $servidor = [
            'host'    => (string) $partes['host'],
            'puerto'  => (int) ($partes['port'] ?? 80),
            'prefijo' => rtrim((string) ($partes['path'] ?? ''), '/'),
        ];

        $respuesta = peticionWeb($servidor, '/login');

        if ($respuesta['estado'] === 200 && strpos($respuesta['cuerpo'], 'csrf_token') !== false) {
            return $servidor;
        }
    }

    return null;
}

/**
 * ============================================================================
 * PETICIONES POR SOCKET
 * ============================================================================
 *
 * No se usa la extension de cURL porque no viene en la instalacion de PHP de
 * este proyecto, y D14 no admite meterla. Con un socket y texto escrito a mano
 * bastan para las cuatro peticiones que hace este caso.
 */

/**
 * Abre un socket contra el servidor web.
 *
 * @param string $host     Nombre del servidor o direccion IP.
 * @param int    $puerto   Puerto de escucha.
 * @param float  $segundos Espera maxima al conectar.
 *
 * @return resource|null Socket abierto, o null si no se pudo conectar.
 */
function abrirSocketWeb(string $host, int $puerto, float $segundos = 10.0)
{
    $numeroError = 0;
    $textoError = '';

    $socket = @stream_socket_client(
        'tcp://' . $host . ':' . $puerto,
        $numeroError,
        $textoError,
        $segundos
    );

    if (!is_resource($socket)) {
        return null;
    }

    // La espera se aplica a las lecturas. Tiene que ser amplia porque, mientras
    // la consola retiene el cerrojo, las peticiones no contestan hasta que se
    // suelta, y una lectura que se rindiera antes mediria un fallo de la prueba
    // como un fallo de la aplicacion.
    stream_set_timeout($socket, 60);

    return $socket;
}

/**
 * Escribe una peticion HTTP completa en el socket, sin leer nada todavia.
 *
 * Que no lea nada es justo lo que hace posible la simultaneidad: las dos
 * peticiones se escriben una detras de otra y las dos se leen despues, cuando
 * ya estan las dos en marcha. Si esta funcion leyera, la segunda peticion no
 * saldria hasta que la primera hubiera terminado, y la prueba mediria dos
 * peticiones seguidas, que es justo lo que no se quiere medir.
 *
 * @param resource                $socket Socket abierto con abrirSocketWeb().
 * @param string                  $host   Servidor al que se envia la
 *                                          cabecera Host.
 * @param int                     $puerto Puerto al que se envia la cabecera
 *                                          Host.
 * @param string                  $ruta   Ruta y consulta, con la barra
 *                                          inicial.
 * @param array<string, string>|null $campos Campos del formulario, o null para
 *                                          una peticion GET.
 * @param string                  $cookie Cabecera Cookie con la sesion, o
 *                                          cadena vacia.
 *
 * @return void
 */
function escribirPeticionWeb(
    $socket,
    string $host,
    int $puerto,
    string $ruta,
    ?array $campos,
    string $cookie
): void {
    $cabeceras = [
        ($campos === null ? 'GET ' : 'POST ') . $ruta . ' HTTP/1.1',
        'Host: ' . $host . ':' . $puerto,
        'Accept: text/html',
        'Accept-Encoding: identity',
        // Se pide cerrar la conexion para poder leer la respuesta hasta el final
        // sin tener que respetar el Content-Length.
        'Connection: close',
    ];

    if ($cookie !== '') {
        $cabeceras[] = 'Cookie: ' . $cookie;
    }

    $cuerpo = '';

    if ($campos !== null) {
        $cuerpo = codificarCampos($campos);
        $cabeceras[] = 'Content-Type: application/x-www-form-urlencoded';
        $cabeceras[] = 'Content-Length: ' . strlen($cuerpo);
    }

    $peticion = implode("\r\n", $cabeceras) . "\r\n\r\n" . $cuerpo;

    // fwrite no garantiza escribirlo todo de una vez, asi que se repite hasta
    // que no queda nada. Con cuerpos de formulario pequenos casi nunca hace
    // falta, pero «casi nunca» es una condicion que aqui seria un fallo que solo
    // aparece en la maquina de otra persona.
    while ($peticion !== '') {
        $escrito = fwrite($socket, $peticion);

        if ($escrito === false || $escrito === 0) {
            return;
        }

        $peticion = substr($peticion, $escrito);
    }

    fflush($socket);
}

/**
 * Lee del socket hasta el final y separa la respuesta en estado, cabeceras y
 * cuerpo.
 *
 * @param resource $socket Socket abierto con abrirSocketWeb().
 *
 * @return array<string, mixed> Con «estado» (int), «cabeceras» (string),
 *                              «cuerpo» (string), «caducada» (bool) y «crudo»
 *                              (string).
 */
function leerRespuestaWeb($socket): array
{
    $crudo = '';

    while (!feof($socket)) {
        $trozo = fread($socket, 8192);

        if ($trozo === false || $trozo === '') {
            break;
        }

        $crudo .= $trozo;
    }

    $informacion = stream_get_meta_data($socket);
    fclose($socket);

    $separacion = strpos($crudo, "\r\n\r\n");

    if ($separacion === false) {
        return [
            'estado'    => 0,
            'cabeceras' => '',
            'cuerpo'    => '',
            'caducada'  => (bool) $informacion['timed_out'],
            'crudo'     => $crudo,
        ];
    }

    $cabeceras = substr($crudo, 0, $separacion);
    $cuerpo = substr($crudo, $separacion + 4);

    $estado = 0;

    if (preg_match('#^HTTP/1\.[01]\s+(\d{3})#', $cabeceras, $encontrado) === 1) {
        $estado = (int) $encontrado[1];
    }

    // Con «Connection: close» Apache deberia mandar Content-Length, pero si el
    // cuerpo es grande puede decidir trocearlo. Se quita el troceado para no
    // dejar pedazos de hexadecimal pegados en el HTML que se comprueba luego.
    if (stripos($cabeceras, 'Transfer-Encoding: chunked') !== false) {
        $cuerpo = descifrarTroceado($cuerpo);
    }

    return [
        'estado'    => $estado,
        'cabeceras' => $cabeceras,
        'cuerpo'    => $cuerpo,
        'caducada'  => (bool) $informacion['timed_out'],
        'crudo'     => $crudo,
    ];
}

/**
 * Quita el troceado por bloques de una respuesta HTTP.
 *
 * @param string $cuerpo Cuerpo con bloques de tamano en hexadecimal.
 *
 * @return string Cuerpo sin bloques ni lineas de tamano.
 */
function descifrarTroceado(string $cuerpo): string
{
    $resultado = '';
    $resto = $cuerpo;

    while (trim($resto) !== '') {
        $finLinea = strpos($resto, "\r\n");

        if ($finLinea === false) {
            break;
        }

        $tamano = hexdec(trim(substr($resto, 0, $finLinea)));

        if (!is_int($tamano) || $tamano <= 0) {
            break;
        }

        $resto = substr($resto, $finLinea + 2);
        $resultado .= substr($resto, 0, $tamano);
        $resto = substr($resto, $tamano + 2);
    }

    return $resultado;
}

/**
 * Hace una peticion de principio a fin y devuelve la respuesta.
 *
 * @param array<string, mixed> $servidor Host, puerto y prefijo de la
 *                                       aplicacion, como devuelve
 *                                       localizarServidorWeb().
 * @param string               $ruta     Ruta dentro de la aplicacion, con la
 *                                       barra inicial y sin el prefijo.
 * @param array<string, mixed> $opciones «campos» (array o null) y «cookie»
 *                                       (string).
 *
 * @return array<string, mixed> Respuesta con las mismas claves que
 *                              leerRespuestaWeb().
 */
function peticionWeb(array $servidor, string $ruta, array $opciones = []): array
{
    $socket = abrirSocketWeb((string) $servidor['host'], (int) $servidor['puerto']);

    if (!is_resource($socket)) {
        return [
            'estado'    => 0,
            'cabeceras' => '',
            'cuerpo'    => '',
            'caducada'  => false,
            'crudo'     => '',
        ];
    }

    escribirPeticionWeb(
        $socket,
        (string) $servidor['host'],
        (int) $servidor['puerto'],
        (string) $servidor['prefijo'] . $ruta,
        $opciones['campos'] ?? null,
        (string) ($opciones['cookie'] ?? '')
    );

    return leerRespuestaWeb($socket);
}

/**
 * ============================================================================
 * FORMULARIOS Y COOKIES
 * ============================================================================
 */

/**
 * Devuelve el valor de una cookie de las cabeceras de una respuesta.
 *
 * @param string $cabeceras Cabeceras de la respuesta, con sus lineas.
 * @param string $nombre    Nombre de la cookie buscada.
 *
 * @return string Valor de la cookie, o cadena vacia si no viene.
 */
function valorDeCookie(string $cabeceras, string $nombre): string
{
    foreach (preg_split('/\r\n/', $cabeceras) ?: [] as $linea) {
        if (stripos($linea, 'Set-Cookie:') !== 0) {
            continue;
        }

        if (preg_match('/\b' . preg_quote($nombre, '/') . '=([^;,\s]*)/i', $linea, $encontrado) === 1) {
            return $encontrado[1];
        }
    }

    return '';
}

/**
 * Monta el valor de la cabecera Cookie a partir del nombre y el valor.
 *
 * Se separa del anterior porque valorDeCookie() devuelve SOLO el valor, que es
 * lo que se necesita para compararlo, y la cabecera necesita las dos cosas
 * juntas. Mandar «Cookie: abc123» sin el nombre delante no es una cookie mal
 * puesta: es ninguna cookie, y el servidor abre una sesion nueva en cada
 * peticion sin avisar.
 *
 * @param string $nombre Nombre de la cookie.
 * @param string $valor  Valor de la cookie.
 *
 * @return string Texto completo del valor de la cabecera Cookie.
 */
function cabeceraDeCookie(string $nombre, string $valor): string
{
    return $nombre . '=' . $valor;
}

/**
 * Saca del HTML los campos de un formulario.
 *
 * Se leen del HTML y no se inventan a mano por dos razones: es lo que haria un
 * navegador, y asi la prueba falla si el formulario deja de llevar el token o la
 * clave de intento. Un token inventado pasaria la comprobacion del token
 * mientras no comprobaria nada.
 *
 * @param string $html HTML de la pagina del formulario.
 *
 * @return array<string, string> Valores de los campos, indexados por nombre.
 *                               Los campos sin value se quedan con cadena
 *                               vacia, que es lo que significa una casilla sin
 *                               marcar.
 */
function camposDeFormulario(string $html): array
{
    $campos = [];
    preg_match_all('#<input\b[^>]*>#i', $html, $encontradas);

    foreach ($encontradas[0] as $etiqueta) {
        if (preg_match('#\bname="([^"]*)"#i', $etiqueta, $nombre) !== 1) {
            continue;
        }

        $valor = '';

        if (preg_match('#\bvalue="([^"]*)"#i', $etiqueta, $encontrado) === 1) {
            $valor = $encontrado[1];
        }

        $clave = html_entity_decode($nombre[1], ENT_QUOTES, 'UTF-8');
        $campos[$clave] = html_entity_decode($valor, ENT_QUOTES, 'UTF-8');
    }

    return $campos;
}

/**
 * Codifica campos de formulario como cadena de consulta.
 *
 * @param array<string, string> $campos Campos a codificar.
 *
 * @return string Cadena «clave=valor&clave=valor».
 */
function codificarCampos(array $campos): string
{
    $partes = [];

    foreach ($campos as $clave => $valor) {
        $partes[] = urlencode((string) $clave) . '=' . urlencode((string) $valor);
    }

    return implode('&', $partes);
}

/**
 * ============================================================================
 * CONFIGURACION TEMPORAL PARA LAS PETICIONES WEB
 * ============================================================================
 */

/**
 * Escribe una configuracion que cambia solo el nombre de la base de datos.
 *
 * El fichero carga la configuracion de verdad y le cambia una clave, en vez de
 * escribir dentro una copia entera. Si se copiara todo, el secreto de HMAC y las
 * credenciales de MySQL quedarian en un fichero temporal, que es justo lo que
 * D12 prohibe y lo que hace que este fichero sea menos de lo que podria ser.
 *
 * @param string $bdPruebas Nombre de la base de pruebas.
 *
 * @return string Ruta absoluta del fichero escrito.
 *
 * @throws RuntimeException Si no se puede escribir el fichero.
 */
function escribirConfiguracionDePruebas(string $bdPruebas): string
{
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sorteos-config-pruebas.php';

    $contenido = "<?php\n\n"
        . "declare(strict_types=1);\n\n"
        . "// Fichero temporal que escribe el caso 19 de la suite. Se borra al terminar.\n"
        . "// Carga la configuracion de verdad y cambia solo el nombre de la base.\n"
        . "\$config = require '" . str_replace('\\', '/', Aplicacion::raiz()) . "config/config.php';\n"
        . "\$config['bd']['nombre'] = '" . $bdPruebas . "';\n"
        . "return \$config;\n";

    if (file_put_contents($ruta, $contenido) === false) {
        throw new RuntimeException('No se ha podido escribir la configuracion temporal de pruebas.');
    }

    return $ruta;
}

/**
 * Anade al .htaccess la linea que apunta Apache a la configuracion temporal.
 *
 * Se anade una linea y no se reescribe el fichero entero, porque el .htaccess
 * tiene dentro las reglas de reescritura y las cabeceras de seguridad, y perder
 * esas reglas por un caso de pruebas seria un accidente mucho mayor que el que
 * aqui se quiere evitar.
 *
 * @param string $rutaConfig Ruta del fichero de configuracion temporal.
 *
 * @return void
 */
function apuntarApacheAConfiguracion(string $rutaConfig): void
{
    $fichero = Aplicacion::raiz() . '.htaccess';

    quitarApuntadoDeApache();

    $contenido = is_file($fichero) ? (string) file_get_contents($fichero) : '';
    $finDeLinea = finalesDeLineaDe($contenido);

    $anadido = $finDeLinea
        . MARCA_APUNTADO . $finDeLinea
        . 'SetEnv SORTEOS_CONFIG "' . str_replace('\\', '/', $rutaConfig) . '"' . $finDeLinea;

    file_put_contents($fichero, $contenido . $anadido);
}

/**
 * Quita del .htaccess la linea que anade el caso 19.
 *
 * Se puede llamar las veces que haga falta y con el .htaccess que haya: si la
 * linea no esta, no hace nada. Esa idempotencia es la que permite llamarla al
 * empezar el caso, para arreglar lo que hubiera dejado una ejecucion
 * interrumpida, y al terminar, para no dejar nada puesto.
 *
 * @return void
 */
function quitarApuntadoDeApache(): void
{
    $fichero = Aplicacion::raiz() . '.htaccess';

    if (!is_file($fichero)) {
        return;
    }

    $contenido = (string) file_get_contents($fichero);
    $finDeLinea = finalesDeLineaDe($contenido);
    $lineas = preg_split('/\r\n|\r|\n/', $contenido) ?: [];
    $quedan = [];

    foreach ($lineas as $linea) {
        if (strpos($linea, 'SORTEOS_CONFIG') !== false || strpos($linea, MARCA_APUNTADO) !== false) {
            continue;
        }

        $quedan[] = $linea;
    }

    // Se quitan tambien las lineas en blanco que dejo la linea anadida. Sin
    // esto, cada ejecucion del caso 19 dejaba un par de lineas mas de las que
    // iba dejando, y el .htaccess acababa con un bloque de vacias que nadie
    // habia escrito y que nadie sabe quitar.
    file_put_contents($fichero, rtrim(implode($finDeLinea, $quedan), "\r\n") . $finDeLinea);
}

/**
 * Devuelve los finales de linea que usa ya el fichero, sin inventar los suyos.
 *
 * El .htaccess esta en CRLF porque en Windows asi lo deja el repositorio, y
 * reescribirlo con LF no rompe Apache pero ensucia el estado del git con un
 * cambio de 121 lineas que no ha escrito nadie. Por eso se lee como estaba y se
 * devuelve como estaba.
 *
 * @param string $contenido Contenido del fichero tal como estaba.
 *
 * @return string "\r\n" si el fichero usa CRLF, "\n" en cualquier otro caso.
 */
function finalesDeLineaDe(string $contenido): string
{
    return strpos($contenido, "\r\n") !== false ? "\r\n" : "\n";
}

/**
 * Borra el fichero de configuracion temporal.
 *
 * @param string $ruta Ruta del fichero a borrar.
 *
 * @return void
 */
function borrarConfiguracionDePruebas(string $ruta): void
{
    if ($ruta !== '' && is_file($ruta)) {
        @unlink($ruta);
    }
}

/**
 * ============================================================================
 * LA BARRAJA
 * ============================================================================
 */

/**
 * Cuenta cuantas peticiones estan esperando el cerrojo de adjudicacion.
 *
 * Cuenta hilos del servidor de base de datos, no peticiones del navegador: es
 * la unica forma de mirar dentro y ver si las dos llegaron a la vez. Lo que
 * sale de aqui no es una opinion sobre el tiempo que ha tardado, es la cuenta de
 * consultas esperando, leida de MariaDB.
 *
 * @return int Numero de conexiones esperando un cerrojo con nombre.
 */
function peticionesBloqueadas(): int
{
    return (int) Aplicacion::db()->valor(
        'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE STATE = ?',
        [ESTADO_BLOQUEO_NOMBRADO]
    );
}

/**
 * Espera a que aparezcan un numero de conexiones esperando el cerrojo.
 *
 * @param int   $minimo   Conexiones que tienen que estar esperando a la vez.
 * @param float $segundos Espera maxima.
 *
 * @return int Maximo de conexiones esperando visto durante la espera.
 */
function esperarBloqueoCompartido(int $minimo, float $segundos): int
{
    $maximo = 0;
    $caducado = microtime(true) + $segundos;

    do {
        $vistas = peticionesBloqueadas();

        if ($vistas > $maximo) {
            $maximo = $vistas;
        }

        if ($maximo >= $minimo) {
            break;
        }

        usleep(20000);
    } while (microtime(true) < $caducado);

    return $maximo;
}
