<?php

/**
 * Servicio de imagenes de la campana.
 *
 * Guarda los banners y las imagenes de resultado que sube el administrador y
 * se ocupa de que nada ejecutable acabe en la carpeta de subidas.
 *
 * @see seccion 4.9 del documento de especificacion, imagenes de la campana
 * @see seccion 13.2, "Validacion de imagenes sin GD"
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\ErrorConfiguracion;
use App\Core\ErrorValidacion;
use App\Core\Validador;

/**
 * Valida y guarda las imagenes que sube el administrador.
 *
 * Este entorno no tiene GD ni Imagick, asi que aqui NO se redimensiona ni se
 * recodifica nada: la validacion se hace con finfo, getimagesize() y una lista
 * blanca de extensiones, y el fichero se guarda tal cual. La ultima barrera, si
 * aun asi colara algo ejecutable, la pone uploads/.htaccess, que anula los
 * manejadores de PHP y CGI en esa carpeta.
 *
 * El nombre original que envia el navegador se descarta por completo. Se genera
 * uno con el prefijo de la configuracion, una cadena aleatoria y la extension
 * real de la imagen. Asi no hay forma de que un nombre con «../», con tildes o
 * con espacios llegue a tocar el disco, y dos imagenes del mismo nombre original
 * no se pisan entre si.
 */
class Imagenes
{
    /**
     * Subcadena aleatoria para el nombre del fichero, en hexadecimal.
     *
     * @var int Longitud en caracteres.
     */
    private const ALEATORIO = 16;

    /**
     * Comprueba que la configuracion de ficheros tiene todo lo necesario.
     *
     * Se comprueba al usar el servicio y no al construirlo, porque el
     * constructor no debe fallar: el servicio se instancia en varios
     * controladores y un error ahi se llevaria por delante pantallas que no
     * tienen nada que ver con las imagenes.
     *
     * @return array<string, mixed> Seccion «archivos» de la configuracion.
     *
     * @throws \App\Core\ErrorConfiguracion Si falta alguna clave.
     */
    private function configArchivos(): array
    {
        $archivos = Aplicacion::config()['archivos'] ?? [];

        foreach (['max_bytes', 'directorio', 'prefijo'] as $clave) {
            if (!isset($archivos[$clave]) || (string) $archivos[$clave] === '') {
                throw new ErrorConfiguracion(
                    'Falta la clave «archivos.' . $clave . '» en la configuracion.'
                );
            }
        }

        if (!isset($archivos['extensiones']) || !is_array($archivos['extensiones']) || $archivos['extensiones'] === []) {
            throw new ErrorConfiguracion(
                'La clave «archivos.extensiones» debe ser una lista no vacia.'
            );
        }

        if (!isset($archivos['tipos_mime']) || !is_array($archivos['tipos_mime']) || $archivos['tipos_mime'] === []) {
            throw new ErrorConfiguracion(
                'La clave «archivos.tipos_mime» debe ser una lista no vacia.'
            );
        }

        return $archivos;
    }

    /**
     * Indica si una ruta guardada es una imagen de la carpeta de subidas.
     *
     * El formato de la ruta lo decide ESTE servicio, porque es quien la escribe,
     * y no cada consumidor por su cuenta. La forma es «carpeta/archivo.ext»,
     * con una sola barra: la carpeta es el identificador de la campana y el
     * nombre lo genera el servidor. Dos barras ya saldrian de la carpeta de
     * subidas, y una ruta absoluta o con «..» permitiria leer ficheros de
     * cualquier otro sitio.
     *
     * Las extensiones se toman de la configuracion y no de una lista escrita
     * aqui. Cuando la lista estaba duplicada se desincronizo: la validacion de
     * la campana admitia .gif y .svg mientras el servicio de subidas no, de
     * modo que se podian guardar rutas que el servicio de subida jamas
     * produciria. En particular, .svg es un XML que puede llevar script dentro,
     * y por mucho que la imagen se vea como un logo, en el navegador se
     * ejecuta.
     *
     * @param string $ruta Ruta guardada en la base de datos.
     *
     * @return bool True si la ruta es relativa y no sale de la carpeta.
     */
    public static function esRutaValida(string $ruta): bool
    {
        if ($ruta === '' || str_contains($ruta, '..') || str_contains($ruta, '\\') || str_contains($ruta, "\0")) {
            return false;
        }

        if (str_starts_with($ruta, '/') || preg_match('#^[a-z]:#i', $ruta) === 1) {
            return false;
        }

        $archivos = Aplicacion::config()['archivos'] ?? [];
        $extensiones = [];

        foreach ((array) ($archivos['extensiones'] ?? []) as $extension) {
            $extension = strtolower(trim((string) $extension, ". \t\n\r"));
            $extensiones[] = preg_quote($extension, '#');
        }

        if ($extensiones === []) {
            return false;
        }

        $patron = '#^[a-z0-9_-]+/[a-z0-9_.-]+\.(' . implode('|', $extensiones) . ')$#i';

        return preg_match($patron, $ruta) === 1;
    }

    /**
     * Guarda una imagen subida y devuelve la ruta relativa para la campana.
     *
     * Solo se ocupa de mirar el descriptor del fichero que llega en $_FILES.
     * En cuanto se sabe que hay una subida de verdad, el trabajo se pasa a
     * guardar(), que ya no depende de donde venga la ruta.
     *
     * @param array<string, mixed> $archivo     Un elemento de $_FILES.
     * @param int                  $promocionId Campana a la que pertenece.
     * @param string               $campo       Nombre del campo del formulario,
     *                                          para apuntar el error.
     *
     * @return string Ruta relativa del tipo «12/img_abcdef0123456789.jpg», o la
     *                cadena vacia si no se ha adjuntado ningun fichero.
     *
     * @throws \App\Core\ErrorValidacion Si la imagen no supera la validacion.
     */
    public function subir(array $archivo, int $promocionId, string $campo = 'imagen'): string
    {
        $v = new Validador();

        if (!isset($archivo['error']) || is_array($archivo['error'])) {
            $v->anadirError($campo, 'No se ha recibido ningun fichero.');

            $v->comprobar('Revisa la imagen.');
        }

        $error = (int) $archivo['error'];

        // UPLOAD_ERR_NO_FILE no es un error de la campana: es la respuesta
        // normal cuando el administrador elige «quitar la imagen» y no
        // adjunta nada. Se distingue del resto porque de lo contrario no se
        // podrian borrar imagenes.
        if ($error === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $v->anadirError($campo, 'La imagen es demasiado grande.');

            $v->comprobar('Revisa la imagen.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            $v->anadirError($campo, 'La imagen no se ha podido subir. Intentelo de nuevo.');

            $v->comprobar('Revisa la imagen.');
        }

        $temporal = (string) ($archivo['tmp_name'] ?? '');

        if ($temporal === '' || !is_uploaded_file($temporal)) {
            // Se comprueba con is_uploaded_file() y no solo con que el fichero
            // exista: sin esa comprobacion, un POST con tmp_name apuntando a
            // /config/config.php haria que el servicio leyera y publicara un
            // fichero de la aplicacion.
            $v->anadirError($campo, 'La imagen no se ha subido de forma valida.');

            $v->comprobar('Revisa la imagen.');
        }

        return $this->guardar($temporal, $promocionId, $campo);
    }

    /**
     * Valida un fichero ya subido y lo guarda en la carpeta de la campana.
     *
     * Va separado de subir() a proposito. is_uploaded_file() devuelve siempre
     * false cuando PHP corre por consola, de modo que el camino de arriba no
     * se puede ejecutar desde una prueba de linea de comandos; este si, y es el
     * que contiene todo el trabajo interesante: medidas, tipo, tamano y nombre.
     * La unica diferencia entre los dos es de donde sale el fichero, que ya se
     * ha comprobado antes de llegar aqui.
     *
     * @param string $temporal     Ruta del fichero ya subido.
     * @param int    $promocionId  Campana a la que pertenece.
     * @param string $campo        Nombre del campo del formulario.
     *
     * @return string Ruta relativa del tipo «12/img_abcdef0123456789.jpg».
     *
     * @throws \App\Core\ErrorValidacion Si la imagen no supera la validacion.
     */
    public function guardar(string $temporal, int $promocionId, string $campo = 'imagen'): string
    {
        $v = new Validador();
        $archivos = $this->configArchivos();
        $prefijo = (string) $archivos['prefijo'];

        if (!is_file($temporal)) {
            $v->anadirError($campo, 'La imagen no se ha subido de forma valida.');

            $v->comprobar('Revisa la imagen.');
        }

        $tamano = filesize($temporal);

        if ($tamano === false || $tamano < 1) {
            $v->anadirError($campo, 'La imagen esta vacia.');

            $v->comprobar('Revisa la imagen.');
        }

        if ($tamano > (int) $archivos['max_bytes']) {
            $v->anadirError($campo, sprintf(
                'La imagen no puede pasar de %s.',
                $this->formatoLegible((int) $archivos['max_bytes'])
            ));

            $v->comprobar('Revisa la imagen.');
        }

        $extension = $this->extensionReal($temporal, (array) $archivos['tipos_mime'], $v, $campo);

        if ($extension === null) {
            $v->comprobar('Revisa la imagen.');
        }

        $medidas = @getimagesize($temporal);

        if ($medidas === false || (int) $medidas[0] < 1 || (int) $medidas[1] < 1) {
            // getimagesize() lee la cabecera de verdad. Un fichero con la
            // extension correcta pero que no es una imagen —un .jpg que es un
            // ZIP o un script renombrado— pasa la lista blanca y se para aqui.
            $v->anadirError($campo, 'El fichero no es una imagen valida.');

            $v->comprobar('Revisa la imagen.');
        }

        $v->comprobar('Revisa la imagen.');

        $carpeta = (string) $promocionId;
        $directorio = $this->directorioDeCampana($carpeta);

        if (!is_dir($directorio) && !@mkdir($directorio, 0755, true) && !is_dir($directorio)) {
            throw new ErrorValidacion('No se ha podido crear la carpeta de las imagenes de la campana.');
        }

        $nombre = $prefijo . bin2hex(random_bytes(self::ALEATORIO / 2)) . '.' . $extension;
        $destino = $directorio . DIRECTORY_SEPARATOR . $nombre;

        if (!@rename($temporal, $destino) && !@copy($temporal, $destino)) {
            throw new ErrorValidacion('No se ha podido guardar la imagen en el servidor.');
        }

        @chmod($destino, 0644);

        return $carpeta . '/' . $nombre;
    }

    /**
     * Borra una imagen guardada, si sigue ahi.
     *
     * @param string $ruta Ruta guardada en la base de datos.
     *
     * @return bool True si habia fichero y se ha borrado.
     */
    public function borrar(string $ruta): bool
    {
        if (!self::esRutaValida($ruta)) {
            return false;
        }

        $fichero = $this->rutaAbsoluta($ruta);

        return is_file($fichero) && @unlink($fichero);
    }

    /**
     * Sustituye una imagen por otra nueva y borra la anterior.
     *
     * El orden es subir, guardar en la base de datos y despues borrar la
     * anterior. Si se borrara primero y la subida fallara, la campana se
     * quedaria sin la imagen que tenia y habria que volver a subirla. Asi, un
     * fallo deja la imagen antigua puesta y solo un sobrante sin usar, que no
     * se ve.
     *
     * @param string $ruta         Ruta anterior, o la cadena vacia.
     * @param string $nueva        Ruta nueva devuelta por subir().
     * @param string $campo        Campo del formulario, para apuntar el error.
     * @param int    $promocionId  Campana a la que pertenece.
     *
     * @return string Ruta nueva, o la anterior si no se ha subido nada.
     */
    public function sustituir(string $ruta, string $nueva, string $campo, int $promocionId): string
    {
        if ($nueva === '') {
            return $ruta;
        }

        if ($ruta !== '' && $ruta !== $nueva) {
            $this->borrar($ruta);
        }

        return $nueva;
    }

    /**
     * Borra todas las imagenes de una campana y su carpeta.
     *
     * Se usa al eliminar la campana. Se comprueba que la carpeta que se borra
     * es exactamente la de esa campana y que esta dentro de la carpeta de
     * subidas, porque un unlink() con la ruta equivocada no tiene vuelta atras.
     *
     * @param int $promocionId Campana cuyas imagenes se borran.
     *
     * @return int Numero de ficheros eliminados.
     */
    public function borrarCampana(int $promocionId): int
    {
        $directorio = $this->directorioDeCampana((string) $promocionId);
        $raiz = $this->directorioRaiz();

        if (!is_dir($directorio) || !str_starts_with($directorio, $raiz)) {
            return 0;
        }

        $borrados = 0;
        $entradas = @scandir($directorio);

        if ($entradas === false) {
            return 0;
        }

        foreach ($entradas as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $fichero = $directorio . DIRECTORY_SEPARATOR . $entrada;

            if (is_file($fichero) && @unlink($fichero)) {
                $borrados++;
            }
        }

        @rmdir($directorio);

        return $borrados;
    }

    /**
     * Devuelve la ruta absoluta de una imagen guardada.
     *
     * @param string $ruta Ruta relativa guardada.
     *
     * @return string Ruta absoluta, o la cadena vacia si la ruta no vale.
     */
    public function rutaAbsoluta(string $ruta): string
    {
        if (!self::esRutaValida($ruta)) {
            return '';
        }

        return $this->directorioRaiz() . str_replace('/', DIRECTORY_SEPARATOR, $ruta);
    }

    /**
     * Indica si una imagen existe en el disco.
     *
     * @param string $ruta Ruta relativa guardada.
     *
     * @return bool True si el fichero esta.
     */
    public function existe(string $ruta): bool
    {
        $absoluta = $this->rutaAbsoluta($ruta);

        return $absoluta !== '' && is_file($absoluta);
    }

    /**
     * Deduce la extension real de la imagen a partir de su contenido.
     *
     * La extension del fichero que envio el navegador NO se usa: se acepta
     * solo si concuerda con lo que el contenido dice ser. Asi, renombrar un
     * script a .jpg no sirve de nada, porque finfo seguira diciendo que es un
     * script y no una imagen.
     *
     * @param string                $temporal Ruta del fichero subido.
     * @param array<int, string>    $mimeOk   Tipos MIME aceptados.
     * @param \App\Core\Validador   $v        Validador para anotar el error.
     * @param string                $campo    Campo del formulario.
     *
     * @return string|null Extension en minusculas, o null si no es una imagen
     *                     de las aceptadas.
     */
    private function extensionReal(string $temporal, array $mimeOk, Validador $v, string $campo): ?string
    {
        $extension = null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $temporal);
                finfo_close($finfo);

                if (is_string($mime) && in_array($mime, $mimeOk, true)) {
                    $extension = $this->extensionDeMime($mime);
                }
            }
        }

        if ($extension === null) {
            $v->anadirError($campo, 'El tipo de fichero no es una imagen de las admitidas.');

            return null;
        }

        $permitidas = array_map(
            static fn (string $e): string => strtolower(trim($e, ". \t\n\r")),
            (array) (Aplicacion::config()['archivos']['extensiones'] ?? [])
        );

        if (!in_array($extension, $permitidas, true)) {
            // Puede pasar: la configuracion puede pedir image/webp en la lista
            // de tipos y no en la de extensiones, o al reves. El tipo y la
            // extension tienen que contar la misma historia.
            $v->anadirError($campo, 'Este formato de imagen no se admite.');

            return null;
        }

        return $extension;
    }

    /**
     * Traduce un tipo MIME a la extension que se guardara.
     *
     * @param string $mime Tipo MIME detectado por finfo.
     *
     * @return string|null Extension, o null si el tipo no es imagen.
     */
    private function extensionDeMime(string $mime): ?string
    {
        $tabla = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];

        return $tabla[strtolower($mime)] ?? null;
    }

    /**
     * Devuelve la ruta absoluta de la carpeta de subidas.
     *
     * @return string Ruta con barra final.
     *
     * @throws \App\Core\ErrorConfiguracion Si el directorio no es valido.
     */
    private function directorioRaiz(): string
    {
        $configurado = (string) $this->configArchivos()['directorio'];
        $ruta = Aplicacion::raiz() . trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configurado), DIRECTORY_SEPARATOR);

        if (str_contains($configurado, '..')) {
            throw new ErrorConfiguracion('La carpeta de subidas no puede contener «..».');
        }

        return $ruta . DIRECTORY_SEPARATOR;
    }

    /**
     * Devuelve la ruta absoluta de la carpeta de imagenes de una campana.
     *
     * @param string $carpeta Nombre de la carpeta, que es el id de la campana.
     *
     * @return string Ruta con barra final.
     *
     * @throws \App\Core\ErrorConfiguracion Si el nombre no es seguro.
     */
    private function directorioDeCampana(string $carpeta): string
    {
        if (preg_match('#^[0-9]+$#', $carpeta) !== 1) {
            throw new ErrorConfiguracion('La carpeta de una campana tiene que ser su identificador.');
        }

        return $this->directorioRaiz() . $carpeta . DIRECTORY_SEPARATOR;
    }

    /**
     * Formatea un tamano en bytes de forma que se pueda leer.
     *
     * @param int $bytes Numero de bytes.
     *
     * @return string Tamano con su unidad.
     */
    private function formatoLegible(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
