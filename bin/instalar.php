<?php

/**
 * Instalador de la aplicacion.
 *
 * Prepara la base de datos y crea la cuenta de administrador. Se puede ejecutar
 * tantas veces como haga falta: no rompe nada de lo que ya hay.
 *
 * ---------------------------------------------------------------------------
 * USO
 * ---------------------------------------------------------------------------
 *
 *     php bin\instalar.php                     Instala con los datos de config.php
 *     php bin\instalar.php --crear-config      Crea config/config.php si falta
 *     php bin\instalar.php --crear-config --forzar
 *                                            Regenera el secreto de HMAC
 *     php bin\instalar.php --test              Instala tambien la base de pruebas
 *     php bin\instalar.php --diagnostico       Comprueba el entorno y no cambia nada
 *     php bin\instalar.php --ayuda             Esta ayuda
 *
 * ---------------------------------------------------------------------------
 * GARANTIA DE QUE NO ROMPE NADA
 * ---------------------------------------------------------------------------
 *
 * Es la decision D12, y es la razon de que este script tenga esta forma:
 * **nunca ejecuta DROP ni ALTER sobre datos existentes**. Todo lo que hace
 * lleva IF NOT EXISTS.
 *
 * El caso que motiva la decision es concreto: si la campana esta en marcha y
 * alguien vuelve a ejecutar el instalador por un descuido, un DROP habria
 * borrado el calendario de premios y todas las participaciones de la semana. Con
 * IF NOT EXISTS, el segundo arranque no hace nada.
 *
 * ---------------------------------------------------------------------------
 * QUE SE HACE EN CADA PASO
 * ---------------------------------------------------------------------------
 *
 *  1. Comprobar las extensiones de PHP que hacen falta.
 *  2. Cargar la configuracion, creandola si se pide con --crear-config.
 *  3. Conectar al servidor SIN indicar base de datos, para poder crearla.
 *  4. Crear la base de datos si no existe, con utf8mb4.
 *  5. Aplicar sql/schema.sql, troceado por sentencias.
 *  6. Crear la cuenta de administrador con una contrasena aleatoria.
 *  7. Aplicar las migraciones de sql/migraciones/, una sola vez cada una.
 *  8. Resumen de lo hecho, con la contrasena si se ha creado la cuenta.
 *
 * ---------------------------------------------------------------------------
 * LA CONTRASENA DEL ADMINISTRADOR
 * ---------------------------------------------------------------------------
 *
 * Se genera con random_bytes, que es la unica fuente de aleatoriedad que PHP
 * garantiza en cualquier plataforma. Se hashea con bcrypt y la contrasena en
 * claro se imprime UNA SOLA VEZ, aqui, y no se vuelve a guardar en ningun sitio.
 * No hay ninguna contrasena por defecto en el codigo.
 *
 * Si el instalador se ejecuta otra vez y la cuenta ya existe, NO se cambia su
 * contrasena: se limita a informar de que existe. Cambiar la contrasena de un
 * administrador sin que nadie lo pida seria una forma muy sencilla de dejar
 * fuera a alguien.
 *
 * @see sql\schema.sql
 * @see config\config.example.php
 * @see \App\Core\Aplicacion::arrancar()
 * @see decision D12
 */

declare(strict_types=1);

/**
 * Carga el arranque comun, que registra el autocargador de clases.
 *
 * Va antes de cualquier uso de una clase de la aplicacion. El comentario de
 * app/inicio.php explica con detalle por que hace falta este require y por que
 * esa clase es la unica que se carga sin pasar por el autocargador.
 */
require_once __DIR__ . '/../app/inicio.php';

/**
 * Comprueba que la ejecucion es por linea de comandos.
 *
 * @return void
 *
 * @throws \RuntimeException Si el script se invoca desde el navegador.
 */
function exigirConsola(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo 'Este script solo se puede ejecutar desde la consola.';
        exit(1);
    }
}

/**
 * Escribe un mensaje normal por consola.
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
 * Escribe un mensaje de operacion correcta.
 *
 * @param string $mensaje Texto a mostrar.
 *
 * @return void
 */
function ok(string $mensaje): void
{
    echo '[ok] ', $mensaje, PHP_EOL;
}

/**
 * Escribe un mensaje de error y termina con codigo de salida 1.
 *
 * @param string $mensaje Texto a mostrar.
 *
 * @return void
 */
function fallo(string $mensaje): void
{
    echo PHP_EOL, '[FALLO] ', $mensaje, PHP_EOL;
    exit(1);
}

/**
 * Muestra la ayuda del script.
 *
 * @return void
 */
function ayuda(): void
{
    $raiz = dirname(__DIR__);
    linea('Instalador de ' . basename($raiz) . ' (sorteos de supermercado)');
    linea('');
    linea('Uso: php bin\\instalar.php [opciones]');
    linea('');
    linea('  --crear-config   Crea config/config.php si no existe, con un secreto');
    linea('                   HMAC aleatorio.');
    linea('  --forzar         Con --crear-config, regenera config/config.php aunque');
    linea('                   ya exista. ADVERTENCIA: cambiar el secreto invalida');
    linea('                   las reglas de «una participacion por persona».');
    linea('  --test           Instala tambien la base de datos de pruebas, para poder');
    linea('                   ejecutar la suite de tests.');
    linea('  --diagnostico    Comprueba extensiones, configuracion, conexion y motor.');
    linea('                   No cambia nada.');
    linea('  --ayuda          Muestra esta ayuda.');
    linea('');
    linea('Este script nunca borra datos: solo crea lo que falta.');
    linea('');
}

/**
 * Analiza los argumentos de la linea de comandos.
 *
 * Solo se aceptan opciones con doble guion y sin valor. Las opciones que
 * necesitan un valor se leen de la configuracion, que es su sitio correcto.
 *
 * @param array<int, string> $argumentos Argumentos de $argv.
 *
 * @return array<string, bool> Opciones pedidas, indexadas por su nombre sin guiones.
 */
function argumentos(array $argumentos): array
{
    $opciones = [];

    foreach ($argumentos as $argumento) {
        if (strncmp($argumento, '--', 2) === 0) {
            $opciones[strtolower(substr($argumento, 2))] = true;
        }
    }

    return $opciones;
}

/**
 * Comprueba que estan las extensiones de PHP que necesita la aplicacion.
 *
 * @return void
 *
 * @throws \RuntimeException Si falta alguna, indicando cual falta.
 */
function comprobarExtensiones(): void
{
    // pdo_mysql es imprescindible: sin ella no hay base de datos.
    // mbstring se usa para medir y limpiar textos en UTF-8: con ella una palabra
    // acentuada mide bien y se puede pasar a minusculas.
    // fileinfo sirve para comprobar el tipo real de las imagenes subidas.
    // openssl se necesita para el transporte SMTP con STARTTLS.
    $necesarias = [
        'pdo_mysql' => 'acceso a la base de datos',
        'mbstring'  => 'tratamiento de textos UTF-8',
        'fileinfo'  => 'validacion de imagenes',
        'openssl'   => 'conexion SMTP segura',
    ];

    $faltantes = [];

    foreach ($necesarias as $extension => $para_que) {
        if (!extension_loaded($extension)) {
            $faltantes[] = $extension . ' (' . $para_que . ')';
        }
    }

    if ($faltantes !== []) {
        throw new RuntimeException(
            'Faltan extensiones de PHP en esta instalacion: ' . implode(', ', $faltantes)
            . '. Activalas en php.ini y vuelve a ejecutar el instalador.'
        );
    }

    ok('Extensiones disponibles: ' . implode(', ', array_keys($necesarias)));

    // La extension mail() es opcional, y se avisa de que no se usa para que
    // nadie se pregunte por que los correos no salen con la funcion mail().
    if (!extension_loaded('mail')) {
        linea('     Aviso: la extension mail() no esta activa. No es un problema: esta');
        linea('     aplicacion envia el correo con su propio transporte SMTP, y en');
        linea('     local usa la bandeja de salida del panel. Decision D1.');
    }
}

/**
 * Crea config/config.php a partir de la plantilla, con un secreto aleatorio.
 *
 * @param string $raiz Raiz del proyecto.
 *
 * @return void
 *
 * @throws \RuntimeException Si no se puede escribir el fichero.
 */
function crearConfig(string $raiz): void
{
    $plantilla = $raiz . '/config/config.example.php';
    $destino = $raiz . '/config/config.php';

    if (!is_file($plantilla)) {
        throw new RuntimeException('No se encuentra config/config.example.php.');
    }

    // La plantilla se carga antes de copiarla, para no dejar un config.php roto
    // que haria fallar toda la aplicacion. Si devuelve algo que no sea un array,
    // es que la propia plantilla esta mal y mejor saberlo ahora.
    $comprobacion = require $plantilla;
    if (!is_array($comprobacion)) {
        throw new RuntimeException('config/config.example.php no devuelve un array de configuracion.');
    }

    // 32 bytes aleatorios en hexadecimal son 64 caracteres, que es la longitud
    // que espera la plantilla.
    $secreto = bin2hex(random_bytes(32));
    $ahora = date('Y-m-d H:i:s');

    // El fichero se escribe con un heredoc, que permite interpolar el secreto y
    // la fecha sin construir la cadena concatenando. Las barras invertidas
    // antecedidas por dos signos de dollar escapan los signos de dollar de
    // PHP para que el fichero generado contenga el codigo tal cual, sin que se
    // interprete al generarse.
    $contenido = <<<PHP
<?php

/**
 * Configuracion local de la aplicacion.
 *
 * Este fichero NO se versiona: figura en el .gitignore y contiene las
 * credenciales reales de la base de datos y el secreto de HMAC.
 *
 * Se estructura como una lista de ANULACIONES sobre config/config.example.php,
 * de forma que solo hay que mantener aqui lo que de verdad cambia en esta
 * instalacion.
 *
 * Generado por bin/instalar.php el {$ahora}.
 *
 * ADVERTENCIA sobre el secreto de HMAC: cambiarlo con participaciones ya
 * registradas hace que las huellas antiguas dejen de coincidir y las reglas de
 * «una participacion por persona» empiecen a fallar en silencio.
 *
 * @see config\\config.example.php
 * @see \\App\\Core\\Aplicacion::config()
 */

declare(strict_types=1);

// Se carga la plantilla versionada, que es la que documenta cada clave.
\$plantilla = require __DIR__ . '/config.example.php';

// -----------------------------------------------------------------------------
// Anulaciones locales de esta instalacion.
// -----------------------------------------------------------------------------
\$local = [
    'seguridad' => [
        'secreto_hmac' => '{$secreto}',
    ],
];

// array_replace_recursive fusiona en profundidad: la plantilla aporta la
// estructura y los valores por defecto, y \$local sobrescribe lo de arriba.
return array_replace_recursive(\$plantilla, \$local);

PHP;

    if (file_put_contents($destino, $contenido) === false) {
        throw new RuntimeException(
            'No se ha podido escribir config/config.php. Comprueba los permisos de la carpeta config.'
        );
    }

    ok('Creado config/config.php con un secreto HMAC nuevo.');
}

/**
 * Trocea un fichero SQL en sentencias individuales, teniendo en cuenta los
 * comentarios y los literales de texto.
 *
 * ---------------------------------------------------------------------------
 * POR QUE NO SE USA UNA LIBRERIA DE SQL
 * ---------------------------------------------------------------------------
 *
 * El esquema no contiene procedimientos almacenados ni disparadores, asi que
 * basta con trocear por el punto y coma que cierra cada sentencia. Una
 * libreria haria el mismo trabajo y anadiria una dependencia, con lo que
 * incumple la decision D14.
 *
 * ---------------------------------------------------------------------------
 * EL PUNTO DELICADO
 * ---------------------------------------------------------------------------
 *
 * Un punto y coma NO cierra una sentencia si esta dentro de un comentario o
 * dentro de un texto entre comillas. Y las dos cosas ocurren en este proyecto:
 *
 *   - Los comentarios de este mismo esquema llevan punto y coma dentro, al
 *     explicar el troceado. Un troceador ingenuo partiria la sentencia por ahi
 *     y mandaria a MySQL un trozo de comentario sin su marcador inicial.
 *   - Los mensajes de texto de las tablas podrian llevar un punto y coma.
 *
 * Por eso el troceador lleva la cuenta de si esta dentro de un comentario de
 * linea (-- hasta el fin de linea), de un comentario de bloque (slash estrella
 * hasta estrella slash) o de un literal de comillas simples o dobles.
 *
 * @param string $sql Contenido completo del fichero.
 *
 * @return array<int, string> Sentencias SQL, sin el punto y coma final y sin
 *                           comentarios.
 */
function trocearSql(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $longitud = strlen($sql);

    // Los cuatro estados del recorrido. Solo hay uno activo a la vez.
    $enComillaSimple = false;
    $enComillaDoble = false;
    $enComentarioLinea = false;
    $enComentarioBloque = false;
    $escapado = false;

    for ($i = 0; $i < $longitud; $i++) {
        $caracter = $sql[$i];
        $siguiente = $i + 1 < $longitud ? $sql[$i + 1] : '';

        // ---- Estado: dentro de un comentario de linea -----------------------
        if ($enComillaSimple || $enComillaDoble) {
            // Un literal termina en el salto de linea, porque en MySQL una
            // cadena sin cerrar no puede pasar de linea. Sin esta proteccion,
            // un apostrofo dentro de un comentario al final de una linea
            // dejaria el troceador creyendo que empieza un texto, y todo lo
            // que viene despues se trataria como literal.
            $actual .= $caracter;

            if ($escapado) {
                $escapado = false;
                continue;
            }

            if ($caracter === '\\') {
                $escapado = true;
                continue;
            }

            if ($caracter === "'" && !$enComillaDoble) {
                $enComillaSimple = false;
            } elseif ($caracter === '"' && !$enComillaSimple) {
                $enComillaDoble = false;
            }

            continue;
        }

        if ($enComentarioLinea) {
            // Un comentario de linea se acaba en el salto de linea, que si se
            // conserva para que las sentencias de varias lineas lo sigan siendo.
            if ($caracter === "\n") {
                $enComentarioLinea = false;
                $actual .= $caracter;
            }

            continue;
        }

        if ($enComentarioBloque) {
            // Un comentario de bloque se cierra con la secuencia de dos
            // caracteres. Un asterisco suelto dentro no lo cierra.
            if ($caracter === '*' && $siguiente === '/') {
                $enComentarioBloque = false;
                $i++;
            }

            continue;
        }

        // ---- Estado: fuera de comentarios y literales ------------------------

        // Se guarda la barra invertida antes de mirar nada mas, porque escapa
        // el caracter siguiente y puede valer tanto para un apostro como para
        // el asterisco de inicio de un comentario.
        if ($caracter === '\\') {
            $actual .= $caracter;
            $escapado = true;
            continue;
        }

        if ($escapado) {
            $actual .= $caracter;
            $escapado = false;
            continue;
        }

        // Inicio de comentario de linea. Se admiten los dos estilos de MySQL:
        // doble guion, y numero seguido de guion.
        if (($caracter === '-' && $siguiente === '-') || ($caracter === '#' )) {
            $enComentarioLinea = true;
            $i += ($caracter === '-') ? 1 : 0;
            continue;
        }

        // Inicio de comentario de bloque. Se guarda un espacio para que las
        // palabras de dos comentarios de bloque contiguos no se peguen y
        // formen un identificador nuevo.
        if ($caracter === '/' && $siguiente === '*') {
            $enComentarioBloque = true;
            $actual .= ' ';
            $i++;
            continue;
        }

        // Apertura y cierre de literales de texto.
        if ($caracter === "'") {
            $enComillaSimple = true;
        } elseif ($caracter === '"') {
            $enComillaDoble = true;
        }

        // El punto y coma cierra la sentencia solo si estamos fuera de literales
        // y comentarios, que es justo el estado en el que estamos aqui.
        if ($caracter === ';') {
            $limpia = trim($actual);
            if ($limpia !== '') {
                $sentencias[] = $limpia;
            }
            $actual = '';
            continue;
        }

        $actual .= $caracter;
    }

    // La ultima sentencia puede no llevar punto y coma final.
    $limpia = trim($actual);
    if ($limpia !== '') {
        $sentencias[] = $limpia;
    }

    return $sentencias;
}

/**
 * Conecta al servidor y crea la base de datos si no existe.
 *
 * ---------------------------------------------------------------------------
 * POR QUE SE CONECTA SIN BASE DE DATOS
 * ---------------------------------------------------------------------------
 *
 * Para ejecutar «CREATE DATABASE IF NOT EXISTS» hay que estar conectado al
 * servidor, y se puede estar conectado a un servidor cuya base de datos
 * todavia no existe. Por eso el DSN de esta conexion no lleva dbname. La
 * segunda conexion, ya sobre la base, si lo lleva.
 *
 * @param array<string, mixed> $bd Bloque 'bd' de la configuracion.
 *
 * @return \PDO Conexion al servidor, con la base de datos ya creada.
 *
 * @throws \RuntimeException   Si el nombre de la base no es valido.
 * @throws \PDOException       Si no se puede conectar o crear la base.
 */
function conectarYCrearBase(array $bd): PDO
{
    $pdo = conectarAlServidor($bd);

    // El nombre de la base se valida antes de interpolarlo, porque aqui es un
    // valor de configuracion, es decir, de un fichero de texto, y conviene no
    // fiarse de su contenido. Un nombre valido solo lleva letras, digitos y
    // guiones bajos.
    $nombre = (string) $bd['nombre'];
    if (preg_match('/^[a-zA-Z0-9_]+$/', $nombre) !== 1) {
        throw new RuntimeException(
            "El nombre de la base de datos «{$nombre}» no es valido. "
            . 'Solo se admiten letras, digitos y guiones bajos.'
        );
    }

    // IF NOT EXISTS es la garantia de la decision D12: si ya existe, no se toca.
    // utf8mb4 con unicode_ci es lo que permite guardar la enye en el nombre de
    // un premio, y es la eleccion de la decision D11.
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    return $pdo;
}

/**
 * Abre una conexion al servidor de base de datos, sin seleccionar base alguna.
 *
 * Se separa de conectarYCrearBase() porque el diagnostico necesita exactamente
 * esta parte y nada mas: conectar, pero no crear.
 *
 * @param array<string, mixed> $bd Bloque 'bd' de la configuracion.
 *
 * @return \PDO Conexion al servidor.
 *
 * @throws \PDOException Si no se puede conectar.
 */
function conectarAlServidor(array $bd): PDO
{
    // El DSN no lleva dbname a proposito. Ver la nota de arriba.
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        $bd['host'],
        (int) $bd['puerto'],
        $bd['charset'] ?? 'utf8mb4'
    );

    // La contrasena va como segundo parametro de PDO y nunca dentro del DSN: un
    // fallo de conexion no puede incluirla en el mensaje de error.
    return new PDO($dsn, (string) $bd['usuario'], (string) ($bd['contrasena'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/**
 * Conecta con el servidor de base de datos sin crear ni modificar nada.
 *
 * Existe aparte de conectarYCrearBase() por una razon concreta: el diagnostico
 * promete no cambiar nada, y no puede cumplirlo usando la otra. Con nombre de
 * base de datos en el DSN, MySQL falla si la base no existe todavia, y con el
 * DSN sin nombre habria que crear la base para poder consultarla. Las dos
 * opciones cambian algo, y una de ellas cambia de verdad.
 *
 * En la practica esto importa poco, porque «crear una base vacia» no destruye
 * nada. Pero un diagnostico que se puede ejecutar sobre una instalacion en
 * produccion tiene que poder hacerlo sin escribir, y sobre todo sin depender
 * de tener permisos de creacion: quien solo puede leer, que es la situacion
 * normal en un servidor ya montado, debe poder ejecutarlo igual.
 *
 * Ademas, conectar aqui no concede ningun permiso nuevo, asi que el diagnostico
 * no puede dejar una base a la vista que antes no lo estuviera.
 *
 * @param array<string, mixed> $bd Bloque 'bd' de la configuracion.
 *
 * @return \PDO Conexion al servidor, sin base de datos seleccionada.
 *
 * @throws \RuntimeException Si el nombre de la base no es valido.
 * @throws \PDOException     Si no se puede conectar al servidor.
 */
function conectarSinCambiarNada(array $bd): PDO
{
    $pdo = conectarAlServidor($bd);
    $nombre = (string) $bd['nombre'];

    // Se valida el nombre aunque aqui todavia no se use en ninguna sentencia.
    // Es el mismo nombre que acabara interpolandose mas adelante en la
    // instalacion, y conviene que falle aqui, en el diagnostico, y no a mitad de
    // una instalacion con la base ya creada.
    if (preg_match('/^[a-zA-Z0-9_]+$/', $nombre) !== 1) {
        throw new RuntimeException(
            "El nombre de la base de datos «{$nombre}» no es valido. "
            . 'Solo se admiten letras, digitos y guiones bajos.'
        );
    }

    return $pdo;
}

/**
 * Aplica un fichero de esquema a la base de datos.
 *
 * @param \PDO   $pdo     Conexion ya establecida sobre la base de datos.
 * @param string $raiz    Raiz del proyecto.
 * @param string $fichero Ruta del fichero SQL, relativa a la raiz.
 *
 * @return int Numero de sentencias ejecutadas.
 *
 * @throws \RuntimeException Si el fichero no existe o una sentencia falla.
 */
function aplicarEsquema(PDO $pdo, string $raiz, string $fichero): int
{
    $ruta = $raiz . '/' . $fichero;

    if (!is_file($ruta)) {
        throw new RuntimeException("No se encuentra el fichero de esquema «{$fichero}».");
    }

    $sql = (string) file_get_contents($ruta);
    $sentencias = trocearSql($sql);
    $ejecutadas = 0;

    foreach ($sentencias as $sentencia) {
        $limpia = trim($sentencia);

        if ($limpia === '') {
            continue;
        }

        try {
            // exec() se usa porque las sentencias del esquema son DDL, que no
            // admiten marcadores de parametros. No hay ningun valor de la
            // peticion en este fichero, de modo que no hay nada que inyectar.
            $pdo->exec($limpia);
            $ejecutadas++;
        } catch (PDOException $e) {
            // Se reenvia indicando el numero de sentencia, porque un error de
            // MySQL sin saber en cual se produjo obliga a leer el fichero
            // entero para encontrarlo.
            throw new RuntimeException(
                "Fallo al aplicar la sentencia numero {$ejecutadas} de «{$fichero}»:\n"
                . $limpia . "\n\n"
                . 'Error de MySQL: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    return $ejecutadas;
}

/**
 * Crea la cuenta de administrador si todavia no existe.
 *
 * @param \PDO   $pdo Conexion a la base de datos de la campana.
 * @param string $bd  Nombre de la base, para el mensaje.
 *
 * @return string|null Contrasena generada, o null si la cuenta ya existia.
 *
 * @throws \RuntimeException Si no se puede crear la cuenta.
 */
function crearAdministrador(PDO $pdo, string $bd): ?string
{
    $nombre = 'admin';

    // Se usa prepare() con marcador, no una cadena con el valor pegado, aunque
    // aqui sea una constante: si manana el nombre se lee de la linea de
    // comandos, el codigo sera seguro sin tener que cambiarlo.
    $existe = $pdo->prepare('SELECT id FROM usuarios WHERE nombre = ? LIMIT 1');
    $existe->execute([$nombre]);

    if ($existe->fetch() !== false) {
        linea('     La cuenta «admin» ya existe. No se ha modificado su contrasena.');

        return null;
    }

    // 18 bytes aleatorios codificados en base64 dan unos 24 caracteres. Se
    // quitan los dos caracteres que en base64 no son alfanumericos, para que
    // la contrasena se pueda teclear y dictar sin confusiones. El resultado
    // sigue teniendo suficiente entropia para no ser adivinable.
    $contrasena = strtr(base64_encode(random_bytes(18)), '+/', '-_');
    $contrasena = rtrim($contrasena, '=');
    $contrasena = preg_replace('/[^A-Za-z0-9_\-]/', '', $contrasena) ?? '';

    // Si el filtrado hubiera dejado la cadena demasiado corta, se repite con
    // hexadecimal, que no tiene ningun caracter que retirar.
    if (strlen($contrasena) < 20) {
        $contrasena = bin2hex(random_bytes(12));
    }

    // El hash lo calcula el modelo, que es quien decide el algoritmo y comprueba
    // la longitud minima. Aqui solo se le pasa la contrasena en claro, y no se
    // guarda en ningun otro sitio.
    $hash = \App\Models\User::hashear($contrasena);

    $insercion = $pdo->prepare(
        'INSERT INTO usuarios (nombre, nombre_completo, contrasena_hash, rol, estado, creado_en)
         VALUES (?, ?, ?, ?, 1, ?)'
    );
    $insercion->execute([
        $nombre,
        'Administrador',
        $hash,
        \App\Core\Autorizacion::ROL_ADMINISTRADOR,
        \App\Core\Aplicacion::ahora(),
    ]);

    ok('Creada la cuenta de administrador «admin» en la base «' . $bd . '».');

    return $contrasena;
}

/**
 * Aplica las migraciones de sql/migraciones/, una sola vez cada una.
 *
 * @param \PDO   $pdo  Conexion a la base de datos.
 * @param string $raiz Raiz del proyecto.
 *
 * @return int Numero de migraciones aplicadas en esta ejecucion.
 *
 * @throws \RuntimeException Si una migracion falla.
 */
function aplicarMigraciones(PDO $pdo, string $raiz): int
{
    $carpeta = $raiz . '/sql/migraciones';

    if (!is_dir($carpeta)) {
        return 0;
    }

    // glob() devuelve false si hay un error del sistema de ficheros, que no es
    // un caso en el que haya que seguir, asi que se trata como carpeta vacia.
    $ficheros = glob($carpeta . '/*.sql');
    if ($ficheros === false) {
        return 0;
    }

    // Se ordenan por nombre, de forma que el numero con el que se nombra cada
    // fichero determina el orden de aplicacion.
    sort($ficheros);

    $aplicadas = 0;

    foreach ($ficheros as $fichero) {
        $version = basename($fichero, '.sql');

        // Se consulta la tabla de migraciones antes de aplicar. Es lo que
        // permite instalar una migracion nueva sobre una base de datos de una
        // campana en marcha sin recrear nada ni perder el calendario.
        $comprobacion = $pdo->prepare('SELECT version FROM migraciones WHERE version = ? LIMIT 1');
        $comprobacion->execute([$version]);

        if ($comprobacion->fetch() !== false) {
            linea('     Migracion ya aplicada, se omite: ' . $version);
            continue;
        }

        $inicio = microtime(true);
        aplicarEsquema($pdo, $raiz, 'sql/migraciones/' . basename($fichero));
        $duracion = (int) round((microtime(true) - $inicio) * 1000);

        $registro = $pdo->prepare(
            'INSERT INTO migraciones (version, aplicada_en, duracion_ms, comentarios)
             VALUES (?, ?, ?, ?)'
        );
        $registro->execute([$version, \App\Core\Aplicacion::ahora(), $duracion, 'Aplicada por bin/instalar.php']);

        ok('Migracion aplicada: ' . $version . ' (' . $duracion . ' ms)');
        $aplicadas++;
    }

    return $aplicadas;
}

/**
 * Conecta a la base ya creada y aplica el esquema.
 *
 * @param \PDO   $pdoServer Conexion obtenida de conectarYCrearBase.
 * @param string $raiz      Raiz del proyecto.
 * @param array<string, mixed> $bd    Bloque 'bd' de la configuracion.
 *
 * @return \PDO Conexion sobre la base de datos concreta.
 *
 * @throws \PDOException Si no se puede conectar ya a la base.
 */
function conectarABase(PDO $pdoServer, string $raiz, array $bd): PDO
{
    // El nombre se valida otra vez, aunque conectarYCrearBase ya lo hiciera.
    // Es una comprobacion de un centesimo de segundo que protege la sentencia
    // en la que el nombre se interpola entre comillas invertidas.
    $nombre = (string) $bd['nombre'];
    if (preg_match('/^[a-zA-Z0-9_]+$/', $nombre) !== 1) {
        throw new RuntimeException("El nombre de la base «{$nombre}» no es valido.");
    }

    // Se reutiliza la conexion del servidor cambiando la base de datos activa,
    // en lugar de abrir una segunda. Es mas barato y, sobre todo, mantiene el
    // mismo contexto para el diagnostico.
    $pdoServer->exec("USE `{$nombre}`");

    return $pdoServer;
}

/**
 * Instala una base de datos completa.
 *
 * @param string              $raiz            Raiz del proyecto.
 * @param array<string, mixed> $bd              Bloque 'bd' de la configuracion.
 * @param bool                $conAdministrador Si true, crea la cuenta de
 *                                             administrador.
 *
 * @return string|null Contrasena del administrador creada, o null si no se
 *                     creo ninguna.
 *
 * @throws \Throwable Si algo falla.
 */
function instalarBase(string $raiz, array $bd, bool $conAdministrador): ?string
{
    $pdo = conectarYCrearBase($bd);
    ok('Base de datos «' . $bd['nombre'] . '» disponible.');

    $pdo = conectarABase($pdo, $raiz, $bd);

    $sentencias = aplicarEsquema($pdo, $raiz, 'sql/schema.sql');
    ok('Esquema aplicado: ' . $sentencias . ' sentencias (todas con IF NOT EXISTS).');

    aplicarMigraciones($pdo, $raiz);

    if (!$conAdministrador) {
        return null;
    }

    return crearAdministrador($pdo, (string) $bd['nombre']);
}

/**
 * Comprueba el entorno y el estado de la instalacion, sin cambiar nada.
 *
 * @param string $raiz Raiz del proyecto.
 *
 * @return int Codigo de salida: 0 si todo esta bien, 1 si hay algun problema.
 */
function diagnosticar(string $raiz): int
{
    linea('Diagnostico de la instalacion');
    linea(str_repeat('-', 64));

    try {
        comprobarExtensiones();
    } catch (Throwable $e) {
        fallo($e->getMessage());
    }

    linea('Version de PHP: ' . PHP_VERSION);
    linea('Zona horaria de php.ini: ' . date_default_timezone_get());

    if (!is_file($raiz . '/config/config.php')) {
        fallo("No existe config/config.php. Ejecuta: php bin\\instalar.php --crear-config");
    }

    ok('Existe config/config.php');

    try {
        \App\Core\Aplicacion::arrancar($raiz);
    } catch (Throwable $e) {
        fallo('La configuracion no se puede cargar: ' . $e->getMessage());
    }

    // La zona horaria se compara con la que exige la especificacion. Si no
    // coincide, la adjudicacion puede desplazarse una hora en invierno, asi
    // que conviene avisar cuanto antes.
    $zona = (string) \App\Core\Aplicacion::ajuste('app.zona_horaria', '');
    if ($zona === 'Europe/Madrid') {
        ok('Zona horaria de la aplicacion: ' . $zona . ' (correcta)');
    } else {
        linea('[AVISO] La zona horaria de la aplicacion no es Europe/Madrid. Revisa config/config.php.');
    }

    $secreto = (string) \App\Core\Aplicacion::ajuste('seguridad.secreto_hmac', '');
    if (strlen($secreto) >= 32 && strpos($secreto, 'CAMBIAR') === false) {
        ok('Secreto de HMAC generado correctamente.');
    } else {
        linea('[AVISO] El secreto de HMAC es demasiado corto o sigue siendo el de ejemplo.');
    }

    $config = \App\Core\Aplicacion::config();

    try {
        // Aqui se conecta SIN crear. La ayuda dice que el diagnostico no cambia
        // nada, y por eso no se puede usar conectarYCrearBase(): ademas de
        // escribir, necesitaria permisos de creacion que en un servidor ya
        // montado no tiene por que tener quien lo diagnostica.
        $pdo = conectarSinCambiarNada($config['bd']);
        ok('Conexion con el servidor de base de datos correcta.');

        linea('Motor: ' . (string) $pdo->query('SELECT VERSION()')->fetchColumn());

        // Si la base no existe todavia, USE falla. No es un error del
        // diagnostico, es exactamente lo que hay que informar: la instalacion
        // esta a medias. Se dice cual es el siguiente paso y se sigue con el
        // resto de comprobaciones, que no dependen de la base.
        $baseExiste = true;

        try {
            $pdo = conectarABase($pdo, $raiz, $config['bd']);
        } catch (Throwable $e) {
            $baseExiste = false;
            linea('[AVISO] La base «' . $config['bd']['nombre'] . '» todavia no existe. '
                . 'Ejecuta: php bin\\instalar.php');
        }

        // La comprobacion de GET_LOCK es la mas importante de todas. Sin esa
        // funcion, dos tablets conectadas a la vez podrian leer la misma
        // unidad pendiente de la cola y repartirla entre las dos, que es
        // exactamente lo que el apartado 6 de la especificacion prohibe.
        $prueba = $pdo->query("SELECT GET_LOCK('sorteos:diagnostico', 1)");
        $conseguido = (int) $prueba->fetchColumn() === 1;
        $pdo->query("SELECT RELEASE_LOCK('sorteos:diagnostico')");

        if ($conseguido) {
            ok('La funcion GET_LOCK esta disponible: el bloqueo de adjudicacion funcionara.');
        } else {
            linea('[FALLO] GET_LOCK no responde. Sin ella NO se puede garantizar la adjudicacion.');
            return 1;
        }

        if ($baseExiste) {
            $tablas = (int) $pdo->query(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            )->fetchColumn();
            ok('Tablas creadas en «' . $config['bd']['nombre'] . '»: ' . $tablas);

            $usuarios = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
            ok('Cuentas de usuario: ' . $usuarios);
        } else {
            linea('Se omite la comprobacion de tablas: todavia no hay base de datos.');
        }

    } catch (Throwable $e) {
        fallo('No se pudo comprobar la base de datos: ' . $e->getMessage());
    }

    linea(str_repeat('-', 64));
    ok('Diagnostico terminado.');

    return 0;
}

/**
 * Cuerpo principal del instalador.
 *
 * @param array<int, string> $argumentos Argumentos de la linea de comandos.
 *
 * @return int Codigo de salida: 0 si todo va bien, 1 si hay algun fallo.
 */
function principal(array $argumentos): int
{
    $raiz = dirname(__DIR__);
    $opciones = argumentos($argumentos);

    if (isset($opciones['ayuda']) || isset($opciones['help']) || isset($opciones['h'])) {
        ayuda();
        return 0;
    }

    linea('Instalador de sorteos de supermercado');
    linea(str_repeat('=', 64));

    // 1. Extensiones. Se comprueban antes de nada mas porque un fallo aqui
    //    impide seguir y su mensaje es mas claro que cualquiera de los demas.
    try {
        comprobarExtensiones();
    } catch (Throwable $e) {
        fallo($e->getMessage());
    }

    // 2. Configuracion. Se crea si falta, o se regenera con --forzar.
    //
    // Antes se mira si esta ejecucion es solo un diagnostico, porque en ese caso
    // no se puede regenerar la configuracion ni siquiera con --forzar. Sin este
    // aviso, «--diagnostico --forzar» regeneraba el secreto de HMAC y despues
    // diagnosticaba, es decir, invalidaba en silencio todas las huellas de las
    // participaciones guardadas y luego hacia como si no hubiera pasado nada.
    // Un comando que dice no cambiar nada no puede cambiar el secreto.
    $ficheroConfig = $raiz . '/config/config.php';
    $forzar = isset($opciones['forzar']);
    $diagnosticando = isset($opciones['diagnostico']) || isset($opciones['diagnostico-completo']);

    if ($forzar && $diagnosticando) {
        linea('[AVISO] Se ignora --forzar: un diagnostico no modifica nada, ni');
        linea('         siquiera el secreto de HMAC. Para regenerar la');
        linea('         configuracion, ejecuta el instalador sin --diagnostico.');
        $forzar = false;
    }

    if ($forzar) {
        linea('[AVISO] Se va a regenerar config/config.php con un secreto HMAC nuevo.');
        linea('         Si ya hay participaciones registradas, las reglas de');
        linea('         «una participacion por persona» dejaran de funcionar.');
    }

    if (!is_file($ficheroConfig)) {
        if (!isset($opciones['crear-config'])) {
            fallo(
                "No existe config/config.php.\n"
                . "Para crearlo con un secreto aleatorio:\n\n"
                . "    php bin\\instalar.php --crear-config\n"
            );
        }

        try {
            crearConfig($raiz);
        } catch (Throwable $e) {
            fallo($e->getMessage());
        }
    } elseif ($forzar) {
        try {
            crearConfig($raiz);
        } catch (Throwable $e) {
            fallo($e->getMessage());
        }
    } else {
        ok('Configuracion cargada desde config/config.php');
    }

    // 3. El diagnostico se ejecuta despues de la configuracion y no modifica
    //    nada, de modo que se puede usar para comprobar una instalacion.
    if ($diagnosticando) {
        return diagnosticar($raiz);
    }

    // 4. Arranque del nucleo, que ya tiene la configuracion disponible.
    try {
        \App\Core\Aplicacion::arrancar($raiz);
    } catch (Throwable $e) {
        fallo('No se puede arrancar la aplicacion: ' . $e->getMessage());
    }

    linea('');
    linea('Instalando...');
    linea('');

    $contrasena = null;

    try {
        // Base de datos de la campana, con su cuenta de administrador.
        $config = \App\Core\Aplicacion::config();
        $contrasena = instalarBase($raiz, $config['bd'], true);

        // Base de datos de pruebas, con el mismo esquema. La cuenta de
        // administrador NO se crea aqui: las pruebas crean sus propias cuentas y
        // asi no dependen de una, ni la modifican.
        if (isset($opciones['test'])) {
            linea('');
            $bdPruebas = $config['bd'];
            $bdPruebas['nombre'] = (string) ($config['bd']['nombre_pruebas']
                ?? ($config['bd']['nombre'] . '_test'));

            instalarBase($raiz, $bdPruebas, false);
        }
    } catch (Throwable $e) {
        fallo($e->getMessage());
    }

    // 5. Resumen. La contrasena se imprime aqui y en ningun otro sitio.
    linea('');
    linea(str_repeat('=', 64));
    ok('Instalacion terminada. No se ha borrado ningun dato existente.');

    if (is_string($contrasena) && $contrasena !== '') {
        linea('');
        linea('  ==================================================================');
        linea('   CONTRASENA DEL ADMINISTRADOR. Se muestra solo esta vez.');
        linea('  ==================================================================');
        linea('');
        linea('      usuario:     admin');
        linea('      contrasena:  ' . $contrasena);
        linea('');
        linea('   Guardala ahora. No se vuelve a mostrar, y no hay ninguna forma de');
        linea('   recuperarla desde la aplicacion.');
        linea('');
        linea('  ==================================================================');
    } else {
        linea('');
        linea('  La cuenta de administrador ya existia, asi que su contrasena no se ha');
        linea('  modificado.');
    }

    linea('');
    linea('Siguientes pasos:');
    linea('  1. Arranca MySQL y Apache desde el panel de control de XAMPP.');
    linea('  2. Abre http://localhost/' . basename($raiz) . '/login');
    linea('  3. Entra con la cuenta de administrador.');
    linea('');

    return 0;
}

// -----------------------------------------------------------------------------
// Arranque del script.
// -----------------------------------------------------------------------------
try {
    exigirConsola();
    exit(principal($argv));
} catch (Throwable $e) {
    echo PHP_EOL, '[FALLO] ', $e->getMessage(), PHP_EOL;
    exit(1);
}
