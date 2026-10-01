<?php

/**
 * Utilidades privadas para preparar escenarios de prueba de la adjudicacion.
 *
 * ============================================================================
 * POR QUE EL NOMBRE DE ESTE FICHERO EMPIEZA POR GUION BAJO
 * ============================================================================
 *
 * Porque bin/verificar_docs.php se salta los ficheros que empiezan por «_», como
 * explica el comentario de su funcion ficherosPhp(). Aqui no hay codigo de la
 * aplicacion que revisar, sino andamiaje de pruebas: dobles de prueba, generadores
 * de datos de pega y generadores de claves. Que el verificador no se pare a exigirle
 * PHPDoc a un generador de UUID de prueba no aporta nada, y ensuciar el informe
 * con avisos de ficheros que nadie va a leer si.
 *
 * Aun asi, el fichero se documenta como los demas, porque lo va a leer alguien
 * que este intentando entender una prueba que falla.
 *
 * ============================================================================
 * LA BASE DE DATOS
 * ============================================================================
 *
 * Todo lo que hay aqui escribe, y escribe mucho: crea campanas enteras con sus
 * tramos, sus premios y sus unidades. Solo puede ejecutarse desde la suite, que
 * antes de nada se ha asegurado de estar apuntando a la base de pruebas y de que
 * esa base no se llama igual que la de la campana. Ver el bloque de arranque de
 * tests/run.php.
 *
 * @see tests/run.php
 * @see \App\Services\Adjudicador
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Services\ValidadorReglas;

/**
 * Nombre de la campana de pruebas.
 *
 * Es una constante en vez de un argumento para que ningun caso pueda crear por
 * error una campana con otro nombre y dejar datos sueltos que no se limpian: el
 * borrado se hace por nombre, y un nombre distinto se escaparia.
 *
 * @return string Nombre de la campana de pruebas.
 */
function nombreCampanaDePrueba(): string
{
    return 'Campana de pruebas de adjudicacion';
}

/**
 * Genera un identificador de intento con el formato que espera la columna.
 *
 * Sale de md5 de una semilla, de modo que es siempre el mismo para la misma
 * semilla. Que sea determinista importa: una clave que cambiara en cada
 * ejecucion haria imposible comprobar que un reintento devuelve el mismo
 * resultado, que es justo lo que se quiere comprobar.
 *
 * @param string $semilla Texto del que se deriva la clave.
 *
 * @return string Identificador de 36 caracteres con forma de UUID.
 */
function claveDePrueba(string $semilla): string
{
    $hash = md5($semilla);

    return substr($hash, 0, 8)
        . '-' . substr($hash, 8, 4)
        . '-' . substr($hash, 12, 4)
        . '-' . substr($hash, 16, 4)
        . '-' . substr($hash, 20, 12);
}

/**
 * Borra la campana de pruebas y todo lo que cuelga de ella.
 *
 * ============================================================================
 * POR QUE NO BASTA UN SOLO DELETE Y HAY QUE IR TABLA POR TABLA
 * ============================================================================
 *
 * Seria natural escribir «DELETE FROM promociones WHERE nombre = ...» y confiar
 * en el ON DELETE CASCADE del esquema. No funciona, y conviene saber por que,
 * porque es una trampa que habria dado por buena la limpieza de estas pruebas.
 *
 * El esquema tiene dos restricciones deliberadas que son ON DELETE RESTRICT:
 * fk_unidades_tipo, que une unidades_premio con tipos_premio, y
 * fk_participaciones_tramo, que une participaciones con tramos. Con RESTRICT, a
 * diferencia de CASCADE o SET NULL, MariaDB comprueba la referencia en el mismo
 * instante en que se borra la fila padre y aborta si sigue en pie. Los demas
 * ON DELETE CASCADE no evitan el problema, solo lo esconden: al borrar la
 * campana se arrastran en cascada las unidades y los tipos de premio a la vez,
 * el motor llega a la segunda antes de haber borrado la primera, y falla con
 * «Cannot delete or update a parent row».
 *
 * Un orden que no se puede deshacer en cascada, porque RESTRICT lo impide desde
 * dentro, solo se puede resolver borrando antes las tablas hijas que bloquean a
 * las padres. De ahi la lista de abajo, y de ahi que el orden importe: si se
 * invierte, el fallo es el mismo.
 *
 * Que el esquema tenga estas dos restricciones no es un error suyo. En una
 * campana real, borrar un tipo de premio que ya ha sido entregado, o un tramo con
 * participaciones dentro, no deberia poder hacerse ni en silencio ni por accidente:
 * dejaria unidades de premio y participaciones apuntando a algo que ya no existe.
 * Que la limpieza de una prueba tenga que respetar ese orden es la prueba de que
 * la restriccion hace su trabajo.
 *
 * @return void
 *
 * @throws \App\Core\ErrorBaseDeDatos Si alguna de las tablas no existe.
 */
function borrarEscenarioDeAdjudicacion(): void
{
    $db = Aplicacion::db();
    $id = $db->valor('SELECT id FROM promociones WHERE nombre = ? LIMIT 1', [nombreCampanaDePrueba()]);

    // Si no hay campana no hay nada que borrar. Se sale aqui en vez de lanzar
    // una tanda de DELETE que no afectarian a ninguna fila.
    if ($id === null) {
        return;
    }

    $id = (int) $id;

    // El orden va de las tablas mas «hoja» a las mas «raiz», respetando los dos
    // RESTRICT del esquema. Los SET NULL no imponen nada, pero se borran tambien
    // para no dejar filas sueltas de pruebas anteriores.
    //
    // asignaciones_tramo NO aparece en la lista porque no tiene promocion_id: se
    // llega a ella por el tramo, y su fk_asignaciones_tramo es CASCADE, asi que
    // se lleva por delante al borrar el tramo. Ponerla aqui, con un
    // «WHERE promocion_id = ?» sobre una columna que no existe, daria un error de
    // columna desconocida y el fallo no diria nada del orden.
    $tablas = [
        'correos',
        'auditoria',
        'intentos_rechazados',
        'codigos_validos',
        'unidades_premio',
        'participaciones',
        'campos_formulario',
        'reglas_participacion',
        'configuracion_visual',
        'tipos_premio',
        'tramos',
    ];

    foreach ($tablas as $tabla) {
        $db->ejecutar('DELETE FROM ' . $tabla . ' WHERE promocion_id = ?', [$id]);
    }

    // Y la campana, que se busca por su clave primaria y no por promocion_id,
    // porque en ella la columna se llama id.
    $db->ejecutar('DELETE FROM promociones WHERE id = ?', [$id]);
}

/**
 * Crea una campana de pruebas con un tramo y las unidades que se le pidan.
 *
 * @param array<int, string> $horas     Horas programadas de las unidades, en
 *                                      formato «H:i:s». Se crean en ese orden y
 *                                      cada una es una unidad con su propia hora.
 * @param array<string, mixed> $opciones Ajustes de la campana:
 *                                      «correo» para activar el envio a
 *                                      ganadoras, «simulacion» para ponerla en
 *                                      modo ensayo.
 *
 * @return array<string, mixed> Identificadores de lo creado: «promocion»,
 *                             «tramo», «tipo_premio» y «unidades», que es la
 *                             lista de identificadores de unidad en el mismo
 *                             orden que las horas recibidas.
 */
function crearEscenarioDeAdjudicacion(array $horas, array $opciones = []): array
{
    $db = Aplicacion::db();
    $ahora = Aplicacion::ahora();

    $correo = !empty($opciones['correo']) ? 1 : 0;
    $simulacion = !empty($opciones['simulacion']) ? 1 : 0;

    // El tramo es de todo el dia: estos casos no prueban los horarios del tramo,
    // que son cosa del hito 4, sino la cola de premios. Que el tramo abarque las
    // horas que se van a usar evita tener que pensar en el dato mientras se
    // prueba otra cosa.
    $promocionId = $db->insertar(
        'INSERT INTO promociones (
             nombre, zona_horaria, estado, fecha_inicio, modo_simulacion,
             correo_ganador, correo_no_ganador,
             correo_ganador_asunto, correo_ganador_cuerpo,
             correo_no_ganador_asunto, correo_no_ganador_cuerpo,
             creado_en, actualizada_en
         ) VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            nombreCampanaDePrueba(),
            'Europe/Madrid',
            'activa',
            $simulacion,
            $correo,
            $correo,
            'Enhorabuena, {{nombre}}',
            'Se lleva el premio «{{premio}}». Su codigo es {{codigo}} en {{promocion}}.',
            'Gracias por participar, {{nombre}}',
            'Esta vez no ha habido suerte en {{promocion}}.',
            $ahora,
            $ahora,
        ]
    );

    // El tramo llega hasta las 23:59:00 y no hasta las 23:59:59 porque los
    // segundos tienen que valer cero: Tramos::esHora() los rechaza, que es lo
    // correcto, ya que el esquema guarda la hora con precision de minuto. Escribo
    // el INSERT a mano y saltarme el validador ha hecho durante un tiempo que este
    // tramo no sirviera para nada, y que al probar la participacion por HTTP
    // saltara el «sin tramo activo» sin que se entendiera por que, porque el dato
    // estaba bien mirado y solo era invalido para las reglas.
    $tramoId = $db->insertar(
        'INSERT INTO tramos (promocion_id, fecha, hora_inicio, hora_fin, creado_en)
         VALUES (?, CURDATE(), ?, ?, ?)',
        [$promocionId, '00:00:00', '23:59:00', $ahora]
    );

    $tipoPremioId = $db->insertar(
        'INSERT INTO tipos_premio (promocion_id, nombre, descripcion, creado_en, actualizado_en)
         VALUES (?, ?, ?, ?, ?)',
        [$promocionId, 'Voucher de 20 euros', 'Voucher de prueba', $ahora, $ahora]
    );

    $unidades = [];

    foreach ($horas as $hora) {
        $unidades[] = $db->insertar(
            'INSERT INTO unidades_premio (
                 promocion_id, tramo_id, tipo_premio_id, inicio, estado, creado_en, modificado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$promocionId, $tramoId, $tipoPremioId, date('Y-m-d') . ' ' . $hora, 'programada', $ahora, $ahora]
        );
    }

    return [
        'promocion'   => $promocionId,
        'tramo'       => $tramoId,
        'tipo_premio' => $tipoPremioId,
        'unidades'    => $unidades,
    ];
}

/**
 * Devuelve el instante de hoy a la hora indicada, para las pruebas de la cola.
 *
 * @param string $hora Hora en formato «H:i:s» o «H:i».
 *
 * @return string Fecha y hora de hoy, en formato «A-n-j H:i:s».
 */
function instanteDeHoy(string $hora): string
{
    if (strlen($hora) === 5) {
        $hora .= ':00';
    }

    return date('Y-m-d') . ' ' . $hora;
}

/**
 * Cuantos mensajes de correo hay ahora mismo en la cola de una campana.
 *
 * @param int $promocionId Campana que se quiere mirar.
 *
 * @return int Numero de mensajes en la cola, sin importar su estado.
 */
function mensajesEnCola(int $promocionId): int
{
    return (int) Aplicacion::db()->valor(
        'SELECT COUNT(*) FROM correos WHERE promocion_id = ?',
        [$promocionId]
    );
}

/**
 * Crea una campana con su tramo, su premio, sus campos y sus cantidades.
 *
 * Los casos 8, 9 y 10 del panel necesitan una campana que este «montada pero
 * todavia sin activar», que es el estado en el que se escribe todo el hito 3. Se
 * monta aqui con la misma forma que usaria el panel -servicios, no INSERT a
 * mano- porque una prueba que se monta con SQL distinto del que usa el codigo
 * comprobaria el SQL, no el codigo.
 *
 * @param array<string, mixed> $opciones Lo que se pueda cambiar del escenario:
 *                                      «premios» (numero de premios),
 *                                      «tramos» (numero de tramos),
 *                                      «campos» (si se crean los campos
 *                                      obligatorios del formulario),
 *                                      «reglas» (si se crean las reglas).
 *
 * @return array<string, mixed> Identificadores de lo creado: «promocion»,
 *                              «tramos», «tipos» y «campos».
 */
function crearEscenarioDePanel(array $opciones = []): array
{
    $db = Aplicacion::db();
    $ahora = Aplicacion::ahora();
    $config = new \App\Services\ConfiguracionPromocion();

    $numeroPremios = (int) ($opciones['premios'] ?? 1);
    $numeroTramos = (int) ($opciones['tramos'] ?? 1);
    $sufijo = (string) ($opciones['sufijo'] ?? 'panel');

    $promocionId = $db->insertar(
        'INSERT INTO promociones (
             nombre, zona_horaria, estado, fecha_inicio, creado_en, actualizada_en
         ) VALUES (?, ?, ?, CURDATE(), ?, ?)',
        [
            nombreCampanaDePrueba() . ' ' . $sufijo,
            'Europe/Madrid',
            \App\Models\Promocion::ESTADO_BORRADOR,
            $ahora,
            $ahora,
        ]
    );

    $tipos = [];
    $tramos = [];
    $campos = [];

    for ($n = 1; $n <= $numeroPremios; $n++) {
        $tipos[] = $db->insertar(
            'INSERT INTO tipos_premio (promocion_id, nombre, activo, creado_en, actualizado_en)
             VALUES (?, ?, 1, ?, ?)',
            [$promocionId, 'Premio de pruebas ' . $sufijo . ' ' . $n, $ahora, $ahora]
        );
    }

    for ($n = 1; $n <= $numeroTramos; $n++) {
        $tramoId = $db->insertar(
            'INSERT INTO tramos (promocion_id, fecha, hora_inicio, hora_fin, creado_en)
             VALUES (?, CURDATE(), ?, ?, ?)',
            [$promocionId, '1' . $n . ':00:00', '23:00:00', $ahora]
        );

        $tramos[] = $tramoId;

        // Dos unidades de cada premio en cada tramo: el generador tiene margen
        // de sobra, asi que estos casos prueban el camino feliz y no el limite.
        // Una sola fila por par, porque la tabla tiene clave unica sobre
        // (tramo_id, tipo_premio_id): la cantidad va en la fila, no en varias.
        foreach ($tipos as $tipoId) {
            $db->insertar(
                'INSERT INTO asignaciones_tramo (tramo_id, tipo_premio_id, cantidad)
                 VALUES (?, ?, ?)',
                [$tramoId, $tipoId, 2]
            );
        }
    }

    if (($opciones['campos'] ?? true) === true) {
        // Los ocho campos que el apartado 4.2 da por obligatorios en toda
        // campana. Se crean con la misma constante que usa el servicio para
        // revisar la activacion, para que un campo nuevo que se anada al
        // apartado 4.2 no haga que estas pruebas se queden verdes por un motivo
        // equivocado.
        $etiquetas = [
            'nombre'              => 'Nombre y apellidos',
            'dni'                 => 'DNI',
            'telefono'            => 'Telefono',
            'direccion'           => 'Direccion',
            'codigo_postal'       => 'Codigo postal',
            'num_ticket'          => 'Numero de ticket',
            'codigo_participacion' => 'Codigo de participacion',
            'email'               => 'Correo electronico',
        ];

        $campos = [];

        foreach (\App\Services\ConfiguracionPromocion::CAMPOS_OBLIGATORIOS as $clave) {
            $campos[] = [
                'clave'             => $clave,
                'etiqueta'          => $etiquetas[$clave] ?? $clave,
                'tipo'              => $clave === 'email' ? 'email' : 'texto',
                'obligatorio'       => true,
                'visible'           => true,
                'valor_por_defecto' => '',
                'min_largo'         => '0',
                'max_largo'         => '255',
            ];
        }

        $config->guardarCampos($campos, $promocionId);
        $campos = (new \App\Models\CampoFormulario())->claves($promocionId);
    }

    if (($opciones['reglas'] ?? true) === true) {
        $config->guardarReglas(['una_por_persona' => true], $promocionId);
    }

    return [
        'promocion' => $promocionId,
        'tramos'    => $tramos,
        'tipos'     => $tipos,
        'campos'    => $campos,
    ];
}

/**
 * Borra una campana de prueba y todo lo que cuelga de ella.
 *
 * Las pruebas del panel crean campanas, y sin esto la base de pruebas se
 * llenaria de campanas que no sirven para nada. Se borra en el orden inverso al
 * de las claves foraneas, y se empieza por las filas que tienen dependencias
 * para que ninguna fila deje referencing a algo que ya no esta.
 *
 * @param int $promocionId Campana que se quiere borrar.
 *
 * @return void
 */
function borrarEscenarioDePanel(int $promocionId): void
{
    $db = Aplicacion::db();

    // asignaciones_tramo cuelga del tramo y no de la campana, asi que se borra
    // con un JOIN en vez de con una columna propia. Se empieza por las filas
    // mas dependientes para que ninguna se quede apuntando a algo que ya no esta.
    $db->ejecutar(
        'DELETE a FROM asignaciones_tramo a
           INNER JOIN tramos t ON t.id = a.tramo_id
          WHERE t.promocion_id = ?',
        [$promocionId]
    );

    // El orden lo manda la base de datos, no la comodidad. Todo lo que tiene una
    // clave foranea a tramos tiene que irse antes que tramos: las participaciones
    // guardan en que tramo se registro, y los intentos rechazados tambien. Borrar
    // tramos antes que ellas deja filas apuntando a un tramo que ya no existe, y
    // MariaDB se niega con un error de integridad en vez de dejar el rastro.
    //
    // Por eso la lista esta en orden de dependencia decreciente y no agrupada por
    // parecido. Cualquier fila nueva que cuelgue de campana o de tramo tiene que
    // anadirse aqui por arriba, o el fallo aparecera como un error de base de datos
    // al final de un caso que en realidad habia pasado todo.
    $tablas = [
        'unidades_premio'      => 'promocion_id',
        'correos'             => 'promocion_id',
        'intentos_rechazados' => 'promocion_id',
        'participaciones'     => 'promocion_id',
        'campos_formulario'   => 'promocion_id',
        'reglas_participacion' => 'promocion_id',
        'configuracion_visual' => 'promocion_id',
        'tramos'              => 'promocion_id',
        'tipos_premio'        => 'promocion_id',
        // La campana se borra por su propia clave, que no se llama igual que la
        // de las demas. Por eso la columna va escrita al lado en vez de deducirse.
        'promociones'         => 'id',
    ];

    foreach ($tablas as $tabla => $columna) {
        $db->ejecutar("DELETE FROM {$tabla} WHERE {$columna} = ?", [$promocionId]);
    }
}

/**
 * Rellena $_POST y $_SERVER como si el navegador acabara de enviar el formulario.
 *
 * Las pantallas se prueban a traves de los controladores, que es donde estan
 * las comprobaciones que importan: que no falte el token, que el rol sea el
 * bueno y que los datos se lean del POST. Preparar el superglobal aqui, en vez
 * de llamar al metodo del controlador con parametros, es lo que hace que la
 * prueba falle si el metodo empieza a leer de $_POST algo que no esta.
 *
 * @param array<string, mixed> $post    Datos del formulario.
 * @param string               $destino Ruta a la que se envian.
 *
 * @return void
 */
function enviarFormulario(array $post, string $destino): void
{
    // El token se planta en la sesion a proposito. Csrf::token() devuelve cadena
    // vacia en consola porque no hay sesion que la guarde, pero
    // Csrf::exigirValido() si lee $_SESSION, y eso se puede escribir desde aqui.
    // Asi se prueban los POST de verdad, con la comprobacion del token de
    // por medio, en vez de saltarsela y luego Publiques sin comprobar.
    $_SESSION = $_SESSION ?? [];
    $_SESSION[\App\Core\Csrf::CAMPO] = 'token-de-prueba';
    $post[\App\Core\Csrf::CAMPO] = 'token-de-prueba';

    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = $destino;
}

/**
 * Limpia el superglobal de la peticion entre una prueba y otra.
 *
 * @return void
 */
function limpiarPeticion(): void
{
    $_POST = [];
    $_GET = [];
    unset($_FILES);
    $_SERVER['REQUEST_METHOD'] = 'GET';

    // isset y no un unset a secas: en consola puede que no exista $_SESSION, y
    // unset sobre un superglobal que no esta simplemente no hace nada, pero
    // leerlo antes si avisa.
    if (isset($_SESSION[\App\Core\Csrf::CAMPO])) {
        unset($_SESSION[\App\Core\Csrf::CAMPO]);
    }
}

/**
 * Llama a una accion de un controlador y devuelve el HTML que pinta.
 *
 * Las pantallas se prueban asi y no renderizando la plantilla a mano, porque lo
 * que hay que comprobar es que el controlador y la vista encajan: que la vista
 * recibe las claves que el controlador pasa, y que la pantalla enseña lo que
 * tiene que enseñar. Si se renderizara la plantilla suelta, una vista que
 * espera una clave que el controlador no manda pasaria la prueba y reventaria
 * en el navegador.
 *
 * No se puede probar por HTTP desde la consola porque \App\Core\Csrf solo
 * genera token en una peticion web, y sin sesion no hay token que comprobar.
 * Lo que si se prueba por HTTP, en el caso 4, es que el rol y el token se
 * exigen: una vez comprobado eso, pintar la pantalla no lo cambia.
 *
 * @param string                $controlador Clase del controlador.
 * @param string                $metodo      Accion a llamar.
 * @param array<string, mixed>  $parametros  Parametros de la ruta.
 *
 * @return string HTML que la accion ha impreso.
 */
function htmlDeAccion(string $controlador, string $metodo, array $parametros = []): string
{
    $clase = 'App\\Controllers\\' . $controlador;
    $instancia = new $clase();
    $instancia->asignarParametros($parametros);

    ob_start();

    try {
        $instancia->$metodo();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }

    return (string) ob_get_clean();
}

/**
 * Guarda las reglas de una campana dejando el resto en su valor por defecto.
 *
 * \App\Models\ReglaParticipacion::guardar() espera todas las casillas, porque en
 * el panel se guardan todas juntas. Una prueba que solo quiere activar una
 * llamada tendria que escribir las otras trece, y ese ruido hace que un fallo se
 * confunda con un dato mal puesto. Aqui se rellena lo que falte con los valores
 * por defecto, que es lo mismo que significa «no tocar lo demas».
 *
 * @param int                  $promocionId Campana cuyas reglas se guardan.
 * @param array<string, mixed> $datos       Casillas que se quieren cambiar.
 *
 * @return void
 */
function guardarReglas(int $promocionId, array $datos): void
{
    (new \App\Models\ReglaParticipacion())->guardar(
        $promocionId,
        array_merge((new \App\Models\ReglaParticipacion())->valoresPorDefecto(), $datos)
    );
}

/**
 * Un validador que acepta siempre.
 *
 * Es el caso normal de una campana sin reglas especiales, y tambien lo que
 * necesitan los casos que prueban la cola de premios sin que un rechazo los
 * desvie.
 */
class ValidadorQueAcepta implements ValidadorReglas
{    /**
     * Devuelve siempre null, es decir, el intento es valido.
     *
     * @param array<string, mixed> $intento Datos del intento.
     *
     * @return array{codigo: string, texto: string}|null Siempre null.
     */
    public function validar(array $intento): ?array
    {
        return null;
    }
}

/**
 * Un validador que rechaza siempre con el motivo que se le da.
 *
 * Sirve para probar el caso de aceptacion 5, el de rechazar a una persona que
 * infringe una regla sin que se consuma un premio. En el hito 4 lo sustituira la
 * implementacion de verdad, que mirara las reglas configuradas; lo unico que se le
 * pide es lo mismo que se le pide a aquella: que devuelva el motivo.
 */
class ValidadorQueRechaza implements ValidadorReglas
{
    /**
     * Codigo del motivo con el que se rechaza.
     *
     * @var string
     */
    private string $codigo;

    /**
     * Texto del motivo con el que se rechaza.
     *
     * @var string
     */
    private string $texto;

    /**
     * Cuantos intentos ha visto este validador.
     *
     * Lo usa una prueba para comprobar que un reintento con la misma clave NO
     * vuelve a consultar las reglas, que es lo que hace que el segundo clic de
     * una clienta que ya ha sido rechazada no genere un rechazo nuevo.
     *
     * @var int
     */
    public int $llamadas = 0;

    /**
     * Construye el validador con el motivo que va a devolver.
     *
     * @param string $codigo Codigo corto del motivo.
     * @param string $texto  Texto para la pantalla.
     */
    public function __construct(string $codigo = 'regla_infringida', string $texto = 'Ya ha participado en esta campana.')
    {
        $this->codigo = $codigo;
        $this->texto = $texto;
    }

    /**
     * Rechaza el intento y cuenta la llamada.
     *
     * @param array<string, mixed> $intento Datos del intento.
     *
     * @return array{codigo: string, texto: string} El motivo del rechazo.
     */
    public function validar(array $intento): ?array
    {
        $this->llamadas++;

        return ['codigo' => $this->codigo, 'texto' => $this->texto];
    }
}
