<?php

/**
 * Controlador base.
 *
 * De esta clase heredan todos los controladores de la aplicacion. Aporta lo
 * que todos necesitan: los parametros de la ruta, la lectura de la peticion,
 * el acceso a la sesion y los metodos de respuesta.
 *
 * ============================================================================
 * POR QUE NO HAY UNA CLASE DE VISTA HEREDADA DE ESTA
 * ============================================================================
 *
 * Los controladores no imprimen: devuelven datos y delegan en
 * \App\Core\Vista. Es una separacion que parece un exceso para un
 * proyecto pequeno, pero tiene una consecuencia practica importante: permite
 * que las mismas rutas que pintan una pantalla sean invocadas desde un script
 * de pruebas sin que se imprima nada. En el hito 6, cuando el adjudicador se
 * pruebe de forma automatizada, esa separacion evita tener que capturar la
 * salida para poder comprobar el resultado.
 *
 * @see \App\Core\Router
 * @see \App\Core\Vista
 * @see \App\Core\Csrf
 * @see \App\Core\Validador
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Clase base de todos los controladores.
 */
abstract class Controlador
{
    /**
     * Valores de los parametros extraidos de la ruta.
     *
     * Los asigna el enrutador antes de ejecutar el metodo de la ruta.
     *
     * @var array<string, string>
     */
    protected array $parametros = [];

    /**
     * Asigna al controlador los parametros extraidos de la ruta.
     *
     * @param array<string, string> $parametros Pares nombre y valor de los
     *                                         marcadores del patron de ruta.
     *
     * @return void
     */
    public function asignarParametros(array $parametros): void
    {
        $this->parametros = $parametros;
    }

    /**
     * Devuelve un valor de la ruta.
     *
     * @param string $nombre   Nombre del parametro.
     * @param mixed  $defecto  Valor a devolver si no existe.
     *
     * @return mixed Valor del parametro o el valor por defecto.
     */
    protected function parametro(string $nombre, $defecto = null)
    {
        return $this->parametros[$nombre] ?? $defecto;
    }

    /**
     * Devuelve un parametro de la ruta convertido a entero positivo.
     *
     * ESTA COMPROBACION ES LA QUE IMPIDE QUE UNA RUTA ROMPA ALGUIEN. Un
     * identificador de la URL llega como texto y lo controla quien escribe la
     * URL, no la aplicacion. Si «admin/tramos/abc/editar» llegase hasta la
     * consulta a la base de datos sin comprobar nada, MySQL lo compararia como
     * texto y la consulta no devolveria lo que el administrador espera.
     *
     * Con este metodo, lo que no sea un entero se responde con un 404 antes de
     * tocar la base de datos.
     *
     * @param string $nombre   Nombre del parametro.
     * @param string $ruta     Ruta pedida, para el mensaje de error.
     *
     * @return int Identificador correcto.
     *
     * @throws \App\Core\NoEncontrado Si el valor no es un entero valido.
     */
    protected function parametroId(string $nombre, string $ruta = ''): int
    {
        $valor = $this->parametro($nombre);

        // Se usa filter_var y no un simple is_numeric, porque is_numeric
        // acepta formas como «1e5», « 12» o «0x1A», que no son identificadores.
        $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false || $id === null) {
            throw new NoEncontrado($ruta !== '' ? $ruta : (string) $valor);
        }

        return (int) $id;
    }

    /**
     * Devuelve un valor recibido por GET.
     *
     * @param string $clave    Nombre del parametro de la peticion.
     * @param mixed  $defecto  Valor a devolver si no viene.
     *
     * @return mixed Valor recibido, ya recortado de espacios.
     */
    protected function entrada(string $clave, $defecto = null)
    {
        $valor = $_GET[$clave] ?? $defecto;

        return is_string($valor) ? trim($valor) : $valor;
    }

    /**
     * Devuelve un valor recibido por POST.
     *
     * @param string $clave   Nombre del campo del formulario.
     * @param mixed  $defecto Valor a devolver si no viene.
     *
     * @return mixed Valor recibido, ya recortado de espacios.
     */
    protected function recibido(string $clave, $defecto = null)
    {
        $valor = $_POST[$clave] ?? $defecto;

        return is_string($valor) ? trim($valor) : $valor;
    }

    /**
     * Devuelve un valor de POST marcado como casilla de verificacion.
     *
     * Una casilla sin marcar no aparece en la peticion, no llega como cadena
     * vacia. Traducirla a booleano aqui evita repetir la misma comprobacion en
     * cada sitio donde se lea, y sobre todo evita el fallo clasico de tratar
     * como verdadera la cadena «0».
     *
     * @param string $clave Nombre del campo del formulario.
     *
     * @return bool True si la casilla viene marcada.
     */
    protected function recibidoCasilla(string $clave): bool
    {
        $valor = $_POST[$clave] ?? null;

        return $valor !== null && $valor !== '' && $valor !== '0' && $valor !== 'false';
    }

    /**
     * Indica si la peticion actual es un envio de formulario.
     *
     * @return bool True si el metodo HTTP de la peticion es POST.
     */
    protected function esEnvio(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /**
     * Muestra una plantilla dentro de la maquetacion comun.
     *
     * @param string               $plantilla Ruta de la vista, relativa a
     *                                         views/, sin extension.
     * @param array<string, mixed> $datos     Variables que la vista necesita.
     *
     * @return void
     *
     * @throws \App\Core\ErrorAplicacion Si la vista no existe.
     */
    protected function vista(string $plantilla, array $datos = []): void
    {
        Vista::mostrar($plantilla, $datos);
    }

    /**
     * Renderiza una plantilla y devuelve el resultado como texto, sin imprimirlo.
     *
     * Es la via para generar un correo, una respuesta de JSON o una vista que se
     * quiere medir en una prueba, sin que nada salga por pantalla.
     *
     * @param string               $plantilla Ruta de la vista, sin extension.
     * @param array<string, mixed> $datos     Variables que la vista necesita.
     *
     * @return string Contenido ya renderizado.
     *
     * @throws \App\Core\ErrorAplicacion Si la vista no existe.
     */
    protected function render(string $plantilla, array $datos = []): string
    {
        return Vista::renderizar($plantilla, $datos);
    }

    /**
     * Manda al navegador una respuesta en JSON y termina la peticion.
     *
     * Se usa en las rutas que atienden al JavaScript de la propia aplicacion.
     * Los codigos de estado HTTP se fijan siempre, porque un 200 con un cuerpo
     * de error hace que el JavaScript no lo detecte.
     *
     * @param array<string, mixed> $datos       Contenido de la respuesta.
     * @param int                  $codigoHttp  Codigo de estado, 200 por defecto.
     *
     * @return void
     */
    protected function json(array $datos, int $codigoHttp = 200): void
    {
        // Se indica que la respuesta no se puede cachear: es el comportamiento
        // correcto para datos de una participacion, que jamas deben quedar en
        // una cache intermediaria.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code($codigoHttp);

        // Con JSON_INVALID_UTF8_SUBSTITUTE, un byte suelto de un campo mal
        // introducido produce un caracter de sustitucion en lugar de hacer
        // fallar json_encode y devolver una respuesta vacia sin explicacion.
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Manda al navegador una redireccion y termina la peticion.
     *
     * @param string $ruta   Ruta interna de destino.
     * @param int    $codigo Codigo de redireccion; 302 por defecto, que es el
     *                        que corresponde tras un envio de formulario.
     *
     * @return void
     */
    protected function redirigir(string $ruta, int $codigo = 302): void
    {
        // Se usa 303 y no 302 en los envios de formulario POST. Con 302, algunos
        // navegadores repiten la peticion con POST en la nueva URL, y la pantalla
        // de participacion volveria a adjudicar un premio. Con 303 el
        // navegador convierte la repeticion en GET, que es lo que se quiere.
        if ($this->esEnvio() && $codigo === 302) {
            $codigo = 303;
        }

        // En consola no hay cabeceras que mandar, y header() ahi no hace nada
        // util pero si avisa: «Cannot modify header information», porque la
        // salida ya ha empezado por la propia consola. El mismo criterio que
        // usa Csrf::token() para no inventar un token que luego no se podra
        // comprobar. Lo que se guarda es el aviso de la redireccion, que es lo
        // que las pruebas de consola miran.
        if (!Aplicacion::esPeticionWeb()) {
            Vista::guardarAviso('Redireccion a ' . $ruta, 'info');

            return;
        }

        header('Location: ' . Aplicacion::url($ruta), true, $codigo);
    }

    /**
     * Comprueba que la peticion lleva un token CSRF valido y termina si no.
     *
     * Se llama al principio de TODA ruta que modifique datos. El apartado 9 de
     * la especificacion lo exige, y \App\Core\Csrf explica el mecanismo.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si el token falta o no coincide.
     */
    protected function exigirCsrf(): void
    {
        Csrf::exigirValido();
    }
}
