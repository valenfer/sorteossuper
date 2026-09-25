<?php

/**
 * Renderizador de plantillas.
 *
 * ============================================================================
 * COMO FUNCIONA, EN POCAS PALABRAS
 * ============================================================================
 *
 * Una plantilla es un fichero PHP de la carpeta views/ que recibe variables por
 * su nombre. Se ejecuta dentro de este metodo, de modo que las variables estan
 * disponibles tal cual, y su salida se captura en una cadena. Esa cadena se
 * pasa despues a la maquetacion comun, que la inserta donde corresponde.
 *
 * El resultado es que una vista de la aplicacion es un PHP normal: puede
 * tener un bucle, una condicion y lo que necesite, sin lenguaje de plantillas
 * que aprender ni sintaxis que colisione con la de PHP.
 *
 * ============================================================================
 * POR QUE LAS VARIABLES SE EXTRAEN CON extract() Y AUN ASI ES SEGURO
 * ============================================================================
 *
 * extract() crea variables a partir de las claves de un array, lo que en otro
 * contexto seria un problema grave. Aqui esta controlado por tres motivos:
 *
 *   1. El array lo construye el propio controlador, no lo recibe de la peticion.
 *      Un visitante no puede elegir que claves se extraen.
 *   2. Se fuerza el prefijo EXCLUIDO: el segundo parametro de extract() impide
 *      que se pise una variable existente.
 *   3. extract() solo crea variables simples, nunca funciones ni objetos, que es
 *      lo que lo hace peligroso en general.
 *
 * ============================================================================
 * ESCAPADO DE LA SALIDA
 * ============================================================================
 *
 * Todas las plantillas escapan lo que imprimen con e(). El apartado 9 de la
 * especificacion lo exige, y el motivo es concreto: los datos que escribe la
 * azafata vienen de una persona que esta tecleando delante de una clienta, y
 * pueden contener cualquier cosa, desde una tilde hasta una etiqueta de HTML.
 *
 * En las plantillas hay que usar e() SIEMPRE que se muestre algo que venga de
 * la base de datos o del POST. La excepcion son los valores de la clase Vista,
 * que ya son HTML construido y controlado por la aplicacion.
 *
 * @see \App\Core\Controlador::vista()
 * @see apartado 4.8 de la especificacion, campos del formulario
 * @see apartado 9 de la especificacion, las salidas HTML se escapan
 */

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Motor de plantillas con maquetacion comun.
 */
class Vista
{
    /**
     * Contenido de la pagina ya renderizado, listo para la maquetacion.
     *
     * @var string
     */
    public static string $contenido = '';

    /**
     * Titulo de la pagina, que la maquetacion coloca en el encabezado.
     *
     * @var string
     */
    public static string $titulo = 'Sorteos';

    /**
     * Ruta de la vista actual, sin la carpeta views/.
     *
     * @var string
     */
    public static string $vistaActual = '';

    /**
     * Muestra una vista dentro de la maquetacion comun.
     *
     * @param string               $plantilla Ruta de la vista relativa a
     *                                         views/, sin la extension .php.
     *                                         Por ejemplo «admin/tramos».
     * @param array<string, mixed> $datos     Variables que la vista necesita.
     *
     * @return void
     *
     * @throws \App\Core\ErrorAplicacion Si la vista no existe.
     */
    public static function mostrar(string $plantilla, array $datos = []): void
    {
        echo self::renderizar($plantilla, $datos, true);
    }

    /**
     * Renderiza una vista y devuelve el resultado.
     *
     * @param string               $plantilla  Ruta de la vista sin extension.
     * @param array<string, mixed> $datos      Variables para la vista.
     * @param bool                 $conMaquetacion Si se debe envolver el
     *                                            resultado en la maquetacion
     *                                            comun. False para generar un
     *                                            fragmento, por ejemplo el
     *                                            cuerpo de un correo.
     *
     * @return string Contenido renderizado.
     *
     * @throws \App\Core\ErrorAplicacion Si la vista no existe.
     */
    public static function renderizar(string $plantilla, array $datos = [], bool $conMaquetacion = true): string
    {
        // El titulo de la pagina se saca de los datos si viene dado, porque casi
        // todas las vistas lo necesitan y repetirlo en cada controlador seria
        // ruido.
        //
        // Se hace aqui y no en mostrar() a proposito. Antes estaba en mostrar()
        // y renderizar() lo ignoraba, de modo que el titulo solo se respetaba
        // si la vista se imprimia. Bastaba con llamar a renderizar() en lugar
        // de mostrar(), por ejemplo al montar el cuerpo de un correo, para que
        // el titulo se perdiera en silencio. Los dos caminos entran por aqui.
        if (isset($datos['titulo']) && is_string($datos['titulo']) && $datos['titulo'] !== '') {
            self::$titulo = $datos['titulo'];
        }

        $rutaFichero = self::rutaDe($plantilla);

        // Se comprueba que el fichero existe antes de incluirlo. Un include de
        // un fichero inexistente es un aviso y un objeto vacio, no una excepcion,
        // y el fallo apareceria mas tarde y en un sitio incomprensible.
        if (!is_file($rutaFichero)) {
            throw new ErrorAplicacion("No existe la vista «{$plantilla}».");
        }

        // Las variables de la vista se crean en este ambito, no en el de la
        // plantilla. extract() con la bandera EXTR_SKIP hace que una clave que
        // coincida con el nombre de una variable local no la pise: asi, un
        // controlador que pase «plantilla» o «datos» en sus datos no puede
        // romper el renderizado.
        extract($datos, EXTR_SKIP);

        // Se captura la salida de la plantilla. Con ob_start() y ob_get_clean()
        // nada de lo que escriba con echo llega al navegador hasta que se ha
        // terminado de renderizar entera.
        ob_start();

        try {
            require $rutaFichero;
        } catch (Throwable $e) {
            // Si la plantilla falla a mitad, hay que vaciar la salida parcial.
            // Sin esto, en la pantalla de la azafata apareceria el principio de
            // una pagina sin cabecera, mezclado con un error.
            ob_end_clean();

            // La excepcion se relanza para que la trate la capa de index.php
            // igual que cualquier otra.
            throw $e;
        }

        $cuerpo = (string) ob_get_clean();

        // La maquetacion solo se anade cuando la vista actual no ES la
        // maquetacion. Sin esta comprobacion, el layout se envolveria a si
        // mismo para siempre: al renderizar «layout» se llegaria aqui otra vez,
        // se volveria a envolver, y el navegador recibiria una respuesta que no
        // termina nunca y acaba agotando la memoria.
        if (!$conMaquetacion || $plantilla === 'layout') {
            return $cuerpo;
        }

        // El cuerpo de la vista y su ruta se guardan como estado, porque hay
        // partes de la maquetacion que los necesitan saber y no reciben datos.
        self::$vistaActual = $plantilla;
        self::$contenido = $cuerpo;

        // Y ademas se le pasan al layout como datos de la plantilla, que es la
        // via normal y la que hace que la maquetacion sea un fichero mas. Si
        // se limitara a guardarlos en el estado, la plantilla tendria que leer
        // Vista::$contenido, que es mas dificil de seguir y obliga a saber
        // como funciona el motor por dentro para escribir una vista.
        return self::renderizar('layout', [
            'titulo'    => self::$titulo,
            'contenido' => $cuerpo,
            'vista'     => $plantilla,
            'usuario'   => Autorizacion::usuario(),
        ], false);
    }

    /**
     * Devuelve la ruta absoluta del fichero de una vista.
     *
     * @param string $plantilla Ruta de la vista, sin extension.
     *
     * @return string Ruta absoluta del fichero .php correspondiente.
     */
    private static function rutaDe(string $plantilla): string
    {
        // Se quitan las barras de los extremos y cualquier secuencia «../».
        // Sin esta comprobacion, un controlador que pase un nombre de vista
        // construido con datos de la peticion permitiria incluir un fichero
        // de fuera de la carpeta views. Ahora mismo todos los nombres estan
        // escritos a mano en el codigo, pero la comprobacion cuesta una linea
        // y evita tener que confiar en que eso no cambie nunca.
        $limpia = str_replace(['..', "\0"], '', $plantilla);
        $limpia = trim(str_replace('\\', '/', $limpia), '/');

        return Aplicacion::raiz() . 'views/' . $limpia . '.php';
    }

    /**
     * Escapa un valor para insertarlo en una pagina HTML.
     *
     * Se usa en todas las plantillas. Las tres banderas importan:
     *
     *   - ENT_QUOTES: escapa tambien las comillas simples, que sin esto
     *     permitirian romper un atributo HTML del tipo onclick.
     *   - ENT_SUBSTITUTE: si el texto tiene una secuencia de bytes invalida,
     *     se sustituye por un caracter de reemplazo en lugar de devolver una
     *     cadena vacia. Con ENT_QUOTES a secas, un byte suelto de un campo
     *     tecleado a proposito dejaria el elemento HTML en blanco, y el
     *     formulario no llegaria a enviarse nunca.
     *   - UTF-8: sin esto, PHP escapa en ISO-8859-1 y las letras acentuadas
     *     aparecen deformadas en pantalla.
     *
     * @param mixed $valor Valor a escapar. Se acepta cualquier tipo porque a
     *                     veces se escapan numeros o booleanos.
     *
     * @return string Texto sin peligro para insertar en el HTML.
     */
    public static function e($valor): string
    {
        // Los null se convierten en cadena vacia en lugar de «1», que es lo que
        // haria un casteo directo, y null significa «sin dato» en esta
        // aplicacion.
        if ($valor === null) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escapa un valor para insertarlo dentro de un atributo JavaScript o de un
     * elemento HTML donde el escapado normal no basta.
     *
     * @param mixed $valor Valor a escapar.
     *
     * @return string Texto seguro para un contexto de JavaScript.
     */
    public static function json($valor): string
    {
        $texto = json_encode(
            $valor,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        // json_encode devuelve false si el valor no se puede representar. Las
        // banderas de arriba garantizan que solo puede fallar por datos no
        // validos, y en ese caso se devuelve un null de JSON, que es mejor que
        // una cadena vacia que romperia el JavaScript de la pagina.
        return $texto === false ? 'null' : $texto;
    }

    /**
     * Muestra un aviso de error o de confirmacion y lo consume.
     *
     * Los avisos viajan en la sesion, no en una cookie ni en la URL. Asi el
     * mensaje sobrevive a la redireccion que sigue a un envio de formulario,
     * y no se puede alterar desde fuera.
     *
     * Se consumen al mostrarse: si el usuario recarga la pagina, el aviso ya no
     * esta, en lugar de quedarse pegado.
     *
     * @param string $tipo Tipo de aviso, por ejemplo «error» o «aviso».
     *
     * @return string Mensaje, o cadena vacia si no habia ninguno de ese tipo.
     */
    public static function aviso(string $tipo = 'error'): string
    {
        if (!isset($_SESSION['avisos'][$tipo])) {
            return '';
        }

        $mensaje = (string) $_SESSION['avisos'][$tipo];
        unset($_SESSION['avisos'][$tipo]);

        return $mensaje;
    }

    /**
     * Guarda un aviso para que se muestre en la siguiente pantalla.
     *
     * @param string $mensaje Texto a mostrar.
     * @param string $tipo    Tipo de aviso, por ejemplo «error», «aviso» o
     *                        «exito».
     *
     * @return void
     */
    public static function guardarAviso(string $mensaje, string $tipo = 'error'): void
    {
        if (!isset($_SESSION['avisos']) || !is_array($_SESSION['avisos'])) {
            $_SESSION['avisos'] = [];
        }

        $_SESSION['avisos'][$tipo] = $mensaje;
    }
}
