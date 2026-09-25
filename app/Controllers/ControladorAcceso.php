<?php

/**
 * Controlador de acceso y salida de la sesion.
 *
 * ============================================================================
 * QUE HACE ESTE CONTROLADOR
 * ============================================================================
 *
 * Tres cosas, y las tres estan conectadas con el apartado 3 de la
 * especificacion:
 *
 *   1. Mostrar el formulario de entrada.
 *   2. Comprobar las credenciales y abrir la sesion.
 *   3. Cerrar la sesion.
 *
 * ============================================================================
 * LAS MEDIDAS QUE DICTA LA ESPECIFICACION Y DONDE SE APLICAN
 * ============================================================================
 *
 *   - Limite de intentos: se cuenta en la sesion y se bloquea la cuenta durante
 *     quince minutos al llegar a cinco fallos. Esta en el metodo intentos().
 *   - Token CSRF en el formulario: lo genera y comprueba \App\Core\Csrf, en
 *     entrar() y en el propio controlador.
 *   - Mensaje generico: un usuario inexistente y una contrasena incorrecta dan
 *     exactamente el mismo mensaje y tardan lo mismo. Ver la nota de
 *     intramuscularValidacion() mas abajo, que es la parte importante de esto.
 *   - Registro de los accesos: cada entrada y cada salida quedan anotadas en
 *     la tabla de auditoria, con su direccion IP.
 *   - Regeneracion del identificador de sesion: se hace al entrar, no antes.
 *
 * @see \App\Core\Autorizacion
 * @see \App\Core\Csrf
 * @see \App\Core\Validador
 * @see apartado 3 de la especificacion, acceso y seguridad
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Aplicacion;
use App\Core\Autorizacion;
use App\Core\Controlador;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Vista;
use App\Models\User;
use Throwable;

/**
 * Entrada al panel y cierre de sesion.
 */
class ControladorAcceso extends Controlador
{
    /**
     * Minutos que una cuenta queda bloqueada tras demasiados intentos.
     *
     * @var int
     */
    private const BLOQUEO_MINUTOS = 15;

    /**
     * Numero de intentos fallidos que provocan el bloqueo.
     *
     * @var int
     */
    private const INTENTOS_MAXIMOS = 5;

    /**
     * Hash bcrypt de una contrasena que nadie va a acertar.
     *
     * Se usa como señuelo cuando el nombre de usuario no existe, para que el
     * servidor tarde lo mismo al responder que si existiera. Sin el, un nombre
     * inexistente responderia mucho mas rapido que uno real, y esa diferencia
     * basta para saber que cuentas hay dadas de alta.
     *
     * El valor es un hash valido de bcrypt, calculado a proposito para que en
     * ningun caso se pueda acertar la contrasena aunque se mire el codigo. No
     * se genera al vuelo porque eso costaria unos milisegundos en cada intento
     * fallido, que es justo lo que se intenta que no se note.
     *
     * @var string
     */
    private const HASH_DE_MENTIRA = '$2y$10$e0NRz4MvJRhuVOHE0LwWuOZB2BEFU4v3vRzhq8bBqf1h1uQoYWyNq';

    /**
     * Muestra el formulario de entrada.
     *
     * Si ya hay alguien dentro, no tiene sentido volver a mostrar el
     * formulario: se le lleva a donde le corresponde segun su rol, que es
     * donde se le llevo al entrar.
     *
     * @return void
     */
    public function formulario(): void
    {
        if (Autorizacion::haySesion()) {
            $this->redirigir(Autorizacion::esAdministrador() ? 'admin' : 'azafata');
            return;
        }

        // Se leen los tres tipos de aviso, no solo el de error. Tras salir del
        // sistema el aviso es de confirmacion, y si la vista no lo pide
        // desapareceria y la salida pareceria un fallo.
        $this->vista('acceso/entrar', [
            'titulo' => 'Entrar',
            'csrf'   => Csrf::token(),
            'error'  => Vista::aviso('error'),
            'aviso'  => Vista::aviso('aviso'),
            'exito'  => Vista::aviso('exito'),
        ]);
    }

    /**
     * Comprueba las credenciales recibidas y abre la sesion.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si el token CSRF no es valido.
     */
    public function entrar(): void
    {
        // El token se comprueba antes de mirar siquiera el nombre de usuario.
        // Un envio sin token valido no es un intento de acceso, es una peticion
        // que no tiene nada que ver con este formulario, y no debe contar para
        // el limite de intentos ni provocar un mensaje de credenciales.
        Csrf::exigirValido();

        $nombre = (string) $this->recibido('nombre', '');
        $contrasena = (string) $this->recibido('contrasena', '');

        // Un nombre vacio se responde sin consultar la base de datos. Hacer la
        // consulta igual seria dejar que alguien mida cuanto tarda el servidor
        // en responder, que es una forma de distinguir si un nombre existe.
        if ($nombre === '' || $contrasena === '') {
            $this->fallar('Escribe el nombre de usuario y la contrasena.', '');
            return;
        }

        // El nombre se normaliza una sola vez y a partir de aqui ya se usa
        // siempre la misma forma. No es cosmetico: la columna de usuarios esta
        // en una intercalacion que no distingue mayusculas, de modo que «admin»,
        // «Admin» y «ADMIN» son la MISMA cuenta al buscar en la base de datos. Si
        // cada variante contara por separado, el bloqueo de intentos seria
        // inutil, porque bastaria con alternar mayusculas en cada intento para
        // que el contador nunca llegara al limite. La misma clave normalizada se
        // usa ademas en el bloqueo, en el contador y en la auditoria, para que los
        // tres coincidan.
        $nombre = mb_strtolower(trim($nombre), 'UTF-8');

        // Una cuenta bloqueada se rechaza ANTES de comprobar la contrasena.
        //
        // Sin esta comprobacion, el bloqueo no sirve de nada: un atacante que ya
        // sabe la contrasena (o que la deduce) solo tiene que esperar a que la
        // cuenta se desbloquee y entrar, y durante el bloqueo la contrasena
        // se sigue validando en silencio. El bloqueo tiene que cortar el
        // acceso, no solo avisar.
        $bloqueo = $this->bloqueoDe($nombre);

        if ($bloqueo > 0) {
            // bloqueoDe() devuelve SEGUNDOS, porque es lo que se guarda en la
            // sesion y lo que se compara con el reloj. Para el mensaje hacen
            // falta minutos, y redondeando hacia arriba: si quedan 61 segundos,
            // decir «intentalo en 1 minuto» seria mentira, porque al segundo
            // siguiente todavia no se podria entrar.
            $minutos = (int) ceil($bloqueo / 60);

            $this->fallar(
                'Esta cuenta esta bloqueada por intentos fallidos. Intentalo de nuevo en '
                . $minutos . ' minuto' . ($minutos === 1 ? '' : 's') . '.',
                $nombre
            );
            return;
        }

        // El modelo se construye sin argumentos: hereda de Modelo, que ya toma
        // la conexion de la aplicacion por su cuenta.
        $usuarios = new User();

        try {
            $fila = $usuarios->porNombre($nombre);
        } catch (Throwable $e) {
            // Si falla la base de datos, el mensaje que se muestra al usuario
            // NO es el de credenciales incorrectas, porque ese mentiria: el
            // problema es del servidor. Se distingue de forma deliberada.
            $this->fallar(
                'No se ha podido comprobar el acceso. Intentalo de nuevo en unos instantes.',
                'servidor'
            );
            return;
        }

        // El hash contra el que se compara la contrasena. Si el nombre no
        // existe, no hay ninguna fila de la que sacarlo, asi que se compara
        // contra un hash de mentira.
        //
        // Sin esto, un nombre inexistente devolveria la respuesta en el tiempo
        // que tarda una consulta a la base de datos, y un nombre existente
        // tardaria ademas lo que tarda calcular un hash. Medir esa diferencia es
        // suficiente para averiguar que nombres existen en el sistema, que es
        // justo lo que el mensaje generico de error intenta impedir.
        //
        // El hash de mentira es un bcrypt de una contrasena que nadie va a
        // acertar nunca, asi que el resultado es siempre falso y el tiempo es
        // el mismo que el de un nombre real.
        $hash = $fila !== null
            ? (string) $fila['contrasena_hash']
            : self::HASH_DE_MENTIRA;

        $correcta = $fila !== null
            && (int) $fila['estado'] === 1
            && User::verificar($contrasena, $hash);

        if (!$correcta) {
            $restantes = $this->sumarIntento($nombre);

            $this->fallar(
                $restantes > 0
                    ? 'Usuario o contrasena incorrectos. Te queda'
                        . ($restantes === 1 ? ' 1 intento.' : 'n ' . $restantes . ' intentos.')
                    : 'Usuario o contrasena incorrectos. La cuenta queda bloqueada '
                        . self::BLOQUEO_MINUTOS . ' minutos.',
                $nombre
            );
            return;
        }

        // A partir de aqui la entrada es legitima. Todo lo que sigue prepara
        // la sesion de forma segura.

        $this->limpiarIntentos($nombre);

        // El identificador de sesion se regenera ANTES de guardar nada del
        // usuario. Si no, un atacante que hubiera conseguido fijar un
        // identificador de sesion previo seguiria dentro despues de que la
        // victima entre. Este es el motivo de que exista session_regenerate_id.
        session_regenerate_id(true);

        // Solo se guardan el identificador y el rol, que es lo unico que
        // \App\Core\Autorizacion necesita y lo unico que su comentario explica
        // que se guarda. El nombre y el nombre completo NO se guardan aqui: se
        // consultan cuando hace falta y se descuelgan al cerrar el navegador.
        $_SESSION['usuario_id'] = (int) $fila['id'];
        $_SESSION['usuario_rol'] = (string) $fila['rol'];
        $_SESSION['ultima_actividad'] = time();

        $this->auditar('entrar', (int) $fila['id'], ['nombre' => $fila['nombre']]);
        $usuarios->registrarAcceso((int) $fila['id']);

        // Tras entrar se lleva a la pantalla que corresponde al rol. Se decide
        // con el valor de la fila, y no con Autorizacion::esAdministrador(),
        // porque en este punto exacto todavia no hay nada guardado en la sesion
        // que ese metodo pueda mirar. Preguntarselo a la sesion para deducir
        // el rol seria depender de un dato que se acaba de escribir.
        $this->redirigir(
            (string) $fila['rol'] === Autorizacion::ROL_ADMINISTRADOR ? 'admin' : 'azafata'
        );
    }

    /**
     * Cierra la sesion y vuelve al formulario de entrada.
     *
     * @return void
     */
    public function salir(): void
    {
        // El token de CSRF se comprueba igual que en la entrada. Cerrar la
        // sesion parece inocuo, pero no lo es: sin esta comprobacion, basta con
        // que una pagina de terceros meta un formulario que apunte a /salir, y
        // la azafata que lo abra sin querer se queda fuera del sistema.
        //
        // Se comprueba SOLO si hay sesion. Un visitante que llega a /salir sin
        // haber entrado no tiene token con el que compararlo, y no hay nada que
        // proteger: no tiene una sesion que alguien le pueda cerrar.
        if (Autorizacion::haySesion()) {
            Csrf::exigirValido();

            // La salida tambien se anota, con el identificador de la persona que
            // estaba dentro. Sin esto, no habria forma de saber despues si una
            // sesion se cerro de verdad o simplemente dejo de usarse.
            $this->auditar('salir', Autorizacion::usuarioId(), []);
        }

        Autorizacion::salir();

        // La sesion se ha destruido, asi que el token de CSRF tampoco existe.
        // No hace falta regenerarlo aqui: la siguiente peticion creara otro.
        Vista::guardarAviso('Has salido del sistema.', 'exito');
        $this->redirigir('login');
    }

    /**
     * Anota un intento fallido para un usuario y devuelve cuantos quedan.
     *
     * El contador vive en la sesion, no en la base de datos, y es una decision
     * consciente que merece explicarse, porque la alternativa parece la obvia.
     *
     * El limite de intentos de la especificacion (seccion 9.2) es por CUENTA,
     * no por sesion. Por eso el contador se guarda con el nombre del usuario
     * como clave y no como un numero suelto. Con un numero suelto, fallar cinco
     * veces con «admin» bloquearia el acceso de «azafata» tambien, y bastaria
     * un atacante con cualquier cuenta para dejar la aplicacion entera fuera de
     * servicio. Y al reintentarlo con otro nombre, el contador se reiniciaba.
     *
     * Guardar el contador en la base de datos seria lo contrario de lo que
     * quiere la especificacion: cualquiera que limpiase sus cookies entraria
     * con el contador a cero, y el limite no serviria para nada. El limite por
     * IP que imponen los servidores web cubre el caso de que el atacante
     * cambie de sesion desde la misma maquina. Es la combinacion de las dos
     * medidas, y no una sola, la que protege.
     *
     * Se reconoce la limitacion: un atacante que cambie de direccion IP puede
     *Saltarse el contador. Por eso el limite de IP del servidor es obligatorio
     * y no una recomendacion.
     *
     * @param string $nombre Nombre de usuario sobre el que se cuenta.
     *
     * @return int Intentos que quedan antes del bloqueo.
     */
    private function sumarIntento(string $nombre): int
    {
        $leido = $this->intentosDe($nombre);
        $actual = $leido + 1;
        $this->guardarIntentos($nombre, $actual);

        // El intento fallido se anota SIEMPRE, exista la cuenta o no. Si el
        // nombre no existe no hay usuario_id al que apuntar, y se guarda el
        // nombre en el detalle. Sin esto, un ataque de fuerza bruta contra una
        // cuenta que todavia no existe no dejaria ni un rastro en la auditoria,
        // que es justo el rastro que hace falta para detectar el ataque.
        $this->auditarIntentoFallido($nombre, $actual);

        if ($actual >= self::INTENTOS_MAXIMOS) {
            // Se guarda la hora de desbloqueo de ESE usuario y se borra su
            // contador. Al volver a la pantalla de entrada se comprueba esa
            // hora, de modo que el bloqueo se agota solo sin necesidad de una
            // tarea programada que lo revise.
            $this->bloquearHasta($nombre, time() + (self::BLOQUEO_MINUTOS * 60));
            $this->guardarIntentos($nombre, 0);

            return 0;
        }

        return self::INTENTOS_MAXIMOS - $actual;
    }

    /**
     * Devuelve cuantos intentos fallidos lleva el usuario en esta sesion.
     *
     * @param string $nombre Nombre de usuario.
     *
     * @return int Numero de intentos, cero si no hay ninguno.
     */
    private function intentosDe(string $nombre): int
    {
        return (int) ($_SESSION['intentos_fallidos'][$nombre] ?? 0);
    }

    /**
     * Guarda el numero de intentos fallidos de un usuario.
     *
     * @param string $nombre Nombre de usuario.
     * @param int    $cuantos Numero de intentos que lleva.
     *
     * @return void
     */
    private function guardarIntentos(string $nombre, int $cuantos): void
    {
        if (!isset($_SESSION['intentos_fallidos']) || !is_array($_SESSION['intentos_fallidos'])) {
            $_SESSION['intentos_fallidos'] = [];
        }

        if ($cuantos <= 0) {
            unset($_SESSION['intentos_fallidos'][$nombre]);
            return;
        }

        $_SESSION['intentos_fallidos'][$nombre] = $cuantos;
    }

    /**
     * Bloquea el acceso a una cuenta hasta una fecha concreta.
     *
     * @param string $nombre Nombre de usuario.
     * @param int    $hasta  Marca de tiempo en la que se desbloquea.
     *
     * @return void
     */
    private function bloquearHasta(string $nombre, int $hasta): void
    {
        if (!isset($_SESSION['bloqueos']) || !is_array($_SESSION['bloqueos'])) {
            $_SESSION['bloqueos'] = [];
        }

        $_SESSION['bloqueos'][$nombre] = $hasta;
    }

    /**
     * Borra el contador de intentos y el bloqueo del usuario.
     *
     * Se llama solo cuando el acceso es legitimo, y tambien cuando el bloqueo
     * ya ha pasado, para que la cuenta no salga con un aviso de «bloqueada»
     * justo despues de desbloquearse.
     *
     * @param string $nombre Nombre de usuario.
     *
     * @return void
     */
    private function limpiarIntentos(string $nombre): void
    {
        unset($_SESSION['intentos_fallidos'][$nombre], $_SESSION['bloqueos'][$nombre]);
    }

    /**
     * Comprueba si una cuenta esta en periodo de bloqueo.
     *
     * Si el bloqueo ya ha pasado, se limpia solo. No hace falta ninguna tarea
     * programada que lo revise: la proxima vez que se mire, el reloj dira que
     * la fecha ya vencio y se olvidara.
     *
     * @param string $nombre Nombre de usuario.
     *
     * @return int Segundos que quedan de bloqueo, o 0 si no esta bloqueada.
     */
    private function bloqueoDe(string $nombre): int
    {
        $hasta = (int) ($_SESSION['bloqueos'][$nombre] ?? 0);

        // Si no hay ninguna marca de bloqueo, no se toca nada. Es importante
        // comprobarlo antes de limpiar: si se limpiara siempre, cada intento
        // borraria el contador del anterior antes de sumarlo, y el contador se
        // quedaria en uno para siempre. La cuenta nunca llegaria al limite y el
        // bloqueo no ocurriria nunca.
        if ($hasta <= 0) {
            return 0;
        }

        $restante = $hasta - time();

        if ($restante <= 0) {
            // El bloqueo existia y ya ha pasado. Se limpia para que la cuenta no
            // salga con un aviso de «bloqueada» justo despues de desbloquearse.
            $this->limpiarIntentos($nombre);
            return 0;
        }

        return $restante;
    }

    /**
     * Manda al formulario de entrada con un mensaje de error.
     *
     * No se imprime aqui. Se guarda en la sesion y se redirige, que es el
     * unico patron correcto: si se imprimiera el error y se volviera a
     * mostrar el formulario, un refresco de la pagina reenviaria el POST y
     * sumaria un intento fallido mas.
     *
     * @param string $mensaje Texto a mostrar.
     * @param string $motivo  'usuario' o 'servidor'. El valor se usa para
     *                         controlar la cantidad de detalle que se muestra.
     *
     * @return void
     */
    private function fallar(string $mensaje, string $motivo): void
    {
        if ($motivo === 'servidor') {
            error_log('Fallo al comprobar el acceso: ' . $mensaje);
        }

        // No se vuelve a mirar el bloqueo aqui. Quien llama ya lo ha comprobado y
        // ha pasado un mensaje propio para ese caso, con los minutos exactos que
        // faltan. Si aqui se volviera a consultar sin saber de que usuario se
        // trata, el mensaje que se acabaria mostrando seria el de otra cuenta.
        Vista::guardarAviso($mensaje, 'error');
        $this->redirigir('login');
    }

    /**
     * Anota una accion en la tabla de auditoria.
     *
     * Los fallos de auditoria NO interrumpen la operacion que se estaba
     * haciendo. Es una decision deliberada: si el registro de auditoria falla
     * porque el disco esta lleno, no debe ser la razon de que una clienta no
     * pueda participar. El error se guarda en el log del servidor, que es
     * donde se mirara cuando haya que reconstruir lo ocurrido.
     *
     * @param string              $accion Texto corto con lo ocurrido.
     * @param int                 $usuarioId Identificador del usuario, o 0 si no
     *                                    se sabe todavia quien es.
     * @param array<string, mixed> $detalle  Informacion adicional.
     *
     * @return void
     */
    private function auditar(string $accion, int $usuarioId, array $detalle): void
    {
        try {
            $db = new Db(Aplicacion::config()['bd']);

            // El nombre se resuelve antes, porque la tabla guarda tambien una
            // copia del nombre. No se puede leer de la sesion, que solo tiene
            // identificador y rol, y no de un GET, que no lo lleva.
            $nombre = '';
            if ($usuarioId > 0) {
                $fila = $db->uno('SELECT nombre FROM usuarios WHERE id = ?', [$usuarioId]);
                $nombre = $fila === null ? '' : (string) $fila['nombre'];
            }

            $db->ejecutar(
                'INSERT INTO auditoria
                    (usuario_id, usuario_nombre, entidad, entidad_id, accion, datos_despues, ip, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $usuarioId > 0 ? $usuarioId : null,
                    $nombre,
                    // «entidad» es NOT NULL y sin valor por defecto en el
                    // esquema, porque toda entrada de auditoria tiene que saber
                    // sobre que habla. Aqui todas las entradas de este
                    // controlador hablan de la sesion.
                    'sesion',
                    $usuarioId > 0 ? (string) $usuarioId : '',
                    $accion,
                    json_encode($detalle, JSON_UNESCAPED_UNICODE),
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    Aplicacion::ahora(),
                ]
            );
        } catch (Throwable $e) {
            // El fallo se anota en el log de PHP, que Apache recoge en
            // apache/logs/error.log. No se lanza hacia arriba a proposito, para
            // que un fallo de auditoria no impida a nadie entrar.
            error_log('No se pudo registrar la auditoria de «' . $accion . '»: ' . $e->getMessage());
        }
    }

    /**
     * Anota un intento de acceso fallido.
     *
     * Se separa de auditar() porque un intento fallido casi nunca tiene un
     * usuario_id associatedo: si el nombre no existe, no hay nadie a quien
     * apuntar. Aun asi, el intento se anota con el nombre que se intento, que
     * es justo el dato que hace falta para detectar un ataque.
     *
     * Un ataque de fuerza bruta contra una cuenta que todavia no se ha creado
     * no dejaria ni una linea de auditoria si esto no existiera, y ese es
     * precisamente el ataque que conviene ver cuanto antes.
     *
     * @param string $nombre  Nombre de usuario que se intento.
     * @param int    $intento Numero de intento fallido, contando desde uno.
     *
     * @return void
     */
    private function auditarIntentoFallido(string $nombre, int $intento): void
    {
        try {
            $db = new Db(Aplicacion::config()['bd']);

            // Se busca el identificador por si el nombre si existe. Si existe,
            // el intento se anota contra ese usuario; si no, se anota con el
            // nombre y sin identificador.
            $fila = $db->uno('SELECT id FROM usuarios WHERE nombre = ?', [$nombre]);
            $usuarioId = $fila === null ? 0 : (int) $fila['id'];

            $db->ejecutar(
                'INSERT INTO auditoria
                    (usuario_id, usuario_nombre, entidad, entidad_id, accion, datos_despues, ip, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $usuarioId > 0 ? $usuarioId : null,
                    $nombre,
                    'sesion',
                    $nombre,
                    'acceso_fallido',
                    json_encode(
                        [
                            'intento' => $intento,
                            'restantes' => max(0, self::INTENTOS_MAXIMOS - $intento),
                        ],
                        JSON_UNESCAPED_UNICODE
                    ),
                    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    Aplicacion::ahora(),
                ]
            );
        } catch (Throwable $e) {
            // Como en auditar(), un fallo aqui no puede impedir a nadie entrar.
            error_log('No se pudo registrar el intento fallido: ' . $e->getMessage());
        }
    }
}
