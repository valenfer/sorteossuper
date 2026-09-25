<?php

/**
 * Control de acceso y de roles.
 *
 * Responde a dos preguntas en todo momento: quien esta usando la aplicacion y
 * si puede ver lo que esta pidiendo.
 *
 * ============================================================================
 * POR QUE EL CONTROL ESTA EN EL SERVIDOR Y NO EN LAS PANTALLAS
 * ============================================================================
 *
 * El apartado 3 de la especificacion es explicito: la comprobacion del permiso
 * tiene que hacerse tambien en el servidor. Ocultar un enlace del menu no
 * protege nada, porque la URL se puede escribir a mano en el navegador o
 * llamar con cualquier programa. Por eso la unica comprobacion que cuenta es
 * la que se hace al resolver la ruta, y por eso \App\Core\Router llama a
 * \App\Core\Autorizacion::exigir() antes de ejecutar ningun controlador.
 *
 * ============================================================================
 * POR QUE UN 404 Y NO UN 403
 * ============================================================================
 *
 * Cuando alguien sin permiso pide una pantalla que si existe, la respuesta es
 * 404 y no 403. Un 403 confirmaria que la direccion es real, y basta probar
 * rutas distintas para dibujar el mapa completo del panel. Un 404 no confirma
 * nada. El ADMINISTRADOR ve un aviso en el log cada vez que ocurre, para que el
 * intento no pase del todo desapercibido.
 *
 * ============================================================================
 * ESTADO ACTUAL
 * ============================================================================
 *
 * Esta clase resuelve la identidad de la sesion y el control de roles, que es
 * lo que necesita el enrutador para funcionar. El inicio de sesion completo
 * (validacion de contrasena, proteccion CSRF del formulario de acceso, limite
 * de intentos y bloqueo por fuerza bruta) se completa en el hito 2, junto con
 * la pantalla de alta de azafatas.
 *
 * @see \App\Core\Router
 * @see \App\Models\User
 * @see apartado 3 de la especificacion, roles y acceso
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Identidad de la sesion y control de permisos por rol.
 */
class Autorizacion
{
    /**
     * Rol de administrador, con acceso a todo el panel.
     */
    public const ROL_ADMINISTRADOR = 'administrador';

    /**
     * Rol de azafata, con acceso a la pantalla de participacion.
     */
    public const ROL_AZAFATA = 'azafata';

    /**
     * Lista de roles validos.
     *
     * Sirve para validar el rol guardado en la sesion. Si alguien manipulase
     * el fichero de sesion del servidor, un rol inventado no se reconoceria
     * como ninguno de estos y se negaria el acceso, en lugar de concederselo por
     * error.
     *
     * @var array<int, string>
     */
    public const ROLES = [self::ROL_ADMINISTRADOR, self::ROL_AZAFATA];

    /**
     * Nombre del usuario ya resuelto en esta peticion, o null si aun no se ha
     * consultado.
     *
     * @var string|null
     */
    private static ?string $nombreResuelto = null;

    /**
     * Devuelve los datos del usuario de la sesion en curso.
     *
     * Los datos se guardan enteros en la sesion al iniciar sesion: el
     * identificador y el rol. No se guarda el nombre ni ningun dato personal,
     * porque basta con el identificador para volver a consultar la base de
     * datos cuando haga falta, y guardar menos en la sesion reduce lo que hay
     * que limpiar al cerrar el navegador.
     *
     * @return array{id: int, rol: string}|null Datos del usuario, o null si no
     *                                            hay sesion iniciada.
     */
    public static function usuario(): ?array
    {
        // En consola no hay sesion. Los scripts de linea de comandos trabajan
        // sin usuario y no deben romperse por ello.
        if (!Aplicacion::esPeticionWeb() || session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $id = $_SESSION['usuario_id'] ?? null;
        $rol = $_SESSION['usuario_rol'] ?? null;

        // Los dos tienen que estar y ser del tipo esperado. Se comprueba en
        // lugar de fiarse, porque el almacenamiento de sesion es del servidor
        // pero el rol se escribe en un punto en el que todavia no se ha
        // validado contra la base de datos.
        if (!is_int($id) || $id <= 0 || !is_string($rol) || !in_array($rol, self::ROLES, true)) {
            return null;
        }

        return ['id' => $id, 'rol' => $rol];
    }

    /**
     * Devuelve el identificador del usuario en sesion, o cero si no hay ninguno.
     *
     * @return int Identificador del usuario, o 0 si no hay sesion iniciada.
     */
    public static function usuarioId(): int
    {
        return self::usuario()['id'] ?? 0;
    }

    /**
     * Indica si hay una sesion iniciada.
     *
     * @return bool True si hay un usuario identificado.
     */
    public static function haySesion(): bool
    {
        return self::usuario() !== null;
    }

    /**
     * Indica si el usuario en sesion tiene un rol concreto.
     *
     * @param string $rol Rol que se quiere comprobar.
     *
     * @return bool True si coincide el rol del usuario en sesion.
     */
    public static function es(string $rol): bool
    {
        $usuario = self::usuario();

        return $usuario !== null && $usuario['rol'] === $rol;
    }

    /**
     * Indica si el usuario en sesion es administrador.
     *
     * @return bool True si es administrador.
     */
    public static function esAdministrador(): bool
    {
        return self::es(self::ROL_ADMINISTRADOR);
    }

    /**
     * Exige un rol determinado, y corta la peticion si no se cumple.
     *
     * @param string $rol Rol necesario para continuar.
     *
     * @return void
     *
     * @throws \App\Core\Redirigir     Si no hay sesion iniciada.
     * @throws \App\Core\NoEncontrado  Si hay sesion, pero el rol no coincide.
     */
    public static function exigir(string $rol): void
    {
        $usuario = self::usuario();

        // Sin sesion no hay nada que ocultar. A quien no ha entrado no se le
        // esconde el panel, porque no hay forma de que sospeche que existe; lo
        // unico que hay que hacer es llevarle a la pantalla de acceso, que es
        // lo que espera de cualquier sitio. Un 404 aqui solo confunde, porque la
        // pagina si existe, y existe precisamente para el.
        if ($usuario === null) {
            self::registrarIntentoDenegado($rol);

            // 303 y no 302: obliga al navegador a repetir con GET aunque venga
            // de un POST, que es justo lo que se quiere al ir a la pantalla de
            // acceso. Sin esto, un formulario que se reenvia al login podria
            // llegar como POST y quedarse en bucle.
            throw new Redirigir('login', 303);
        }

        // Con sesion, en cambio, si se esconde. Una azafata que llega a /admin
        // ya sabe que el panel existe, porque esta leyendo el menu, asi que un
        // 404 no le oculta nada; lo que evita es el mensaje «no tienes
        // permiso», que le confirmaria que hay una zona restringida y la
        // incitaria a seguir probando rutas. Se responde como si la pagina no
        // existiera y el intento queda anotado.
        if ($usuario['rol'] !== $rol) {
            self::registrarIntentoDenegado($rol);
            throw new NoEncontrado($_GET['r'] ?? '');
        }
    }

    /**
     * Cierra la sesion del usuario.
     *
     * @return void
     */
    public static function salir(): void
    {
        // Se vacian los datos de la sesion, que es lo que hace falta para que
        // la tablet de la tienda no siga mostrando los datos del cliente
        // anterior. El borrado completo de la cookie lo hace el nucleo.
        $_SESSION = [];

        Aplicacion::cerrarSesion();
    }

    /**
     * Devuelve el nombre que se muestra en la cabecera.
     *
     * ============================================================================
     * POR QUE HACE FALTA UNA CONSULTA SI PODRIA GUARDARSE EN LA SESION
     * ============================================================================
     *
     * Por lo que dice el comentario de usuario(), en la sesion solo se guardan
     * el identificador y el rol. Para poner el nombre de la persona en la
     * cabecera hace falta una consulta mas.
     *
     * Y aun asi sale mas barato que guardar el nombre en la sesion, por dos
     * razones que no son evidentes:
     *
     *   - El nombre cambia. Si un administrador se renombra, con el nombre en la
     *     sesion el cambio no se veria hasta que se abriera otra sesion, y la
     *     cabecera seguiria mostrando el nombre viejo sin ningun aviso.
     *
     *   - La sesion es lo unico que sobrevive al cierre del navegador. Cuanto
     *     menos datos personales haya ahi, menos hay que limpiar, y menos
     *     oportunidad de que se queden restos en un almacenamiento compartido.
     *
     * El resultado se guarda en una variable estatica, que es memoria del
     * proceso y no del almacenamiento de sesiones. En una peticion solo se hace
     * una consulta aunque la cabecera se pinte en varios sitios.
     *
     * @return string Nombre legible, o cadena vacia si no hay sesion.
     */
    public static function nombreUsuario(): string
    {
        if (self::$nombreResuelto !== null) {
            return self::$nombreResuelto;
        }

        $usuario = self::usuario();

        if ($usuario === null) {
            return self::$nombreResuelto = '';
        }

        try {
            $fila = (new \App\Models\User())->buscarPorId($usuario['id']);
            self::$nombreResuelto = $fila === null
                ? ''
                : (string) ($fila['nombre_completo'] ?? $fila['nombre'] ?? '');
        } catch (\Throwable $e) {
            // Si la base de datos no responde, la cabecera se muestra sin
            // nombre. Perder el nombre es un molestia; perder la pagina entera
            // porque un campo informativo no ha podido consultarse, no.
            self::$nombreResuelto = '';
        }

        return self::$nombreResuelto;
    }

    /**
     * Registra en el log un intento de acceso a una zona restringida.
     *
     * @param string $rolExigido Rol que se ha pedido y no se ha tenido.
     *
     * @return void
     */
    private static function registrarIntentoDenegado(string $rolExigido): void
    {
        $usuario = self::usuario();

        $linea = sprintf(
            "[%s] Intento de acceso denegado. Rol necesario: %s. Usuario: %s. Peticion: %s\n\n",
            date('Y-m-d H:i:s'),
            $rolExigido,
            $usuario === null ? 'sin sesion' : $usuario['id'] . ' (' . $usuario['rol'] . ')',
            (string) ($_SERVER['REQUEST_URI'] ?? 'desconocida')
        );

        // Se escribe en un log aparte del de errores, para que el administrador
        // pueda revisar los intentos sospechosos sin tener que leer errores del
        // sistema mezclados.
        $directorio = Aplicacion::raiz() . 'storage/logs';
        if (!is_dir($directorio)) {
            @mkdir($directorio, 0750, true);
        }

        @file_put_contents($directorio . '/acceso.log', $linea, FILE_APPEND | LOCK_EX);
    }
}
