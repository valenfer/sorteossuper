<?php

/**
 * Capa de acceso a la base de datos.
 *
 * Envuelve una unica conexion PDO y ofrece los metodos minimos que necesita la
 * aplicacion: ejecutar una consulta, leer filas, leer un valor, insertar y
 * trabajar dentro de una transaccion.
 *
 * ============================================================================
 * POR QUE EXISTE ESTA CLASE Y NO SE USA PDO DIRECTAMENTE
 * ============================================================================
 *
 * Tres motivos, todos deliberados:
 *
 * 1. Consultas parametrizadas SIEMPRE. Este es el unico punto de la aplicacion
 *    donde se escribe SQL, y no admite concatenar valores. Todos los metodos
 *    reciben los valores en un array de parametros que PDO envia por separado de
 *    la sentencia. Es la defensa frente a inyeccion SQL del apartado 9 de la
 *    especificacion, y centralizarla aqui evita depender de que cada
 *    programador se acuerde en cada consulta.
 *
 * 2. Errores saneados. Toda excepcion nativa de PDO se convierte en
 *    \App\Core\ErrorBaseDeDatos, que elimina la cadena de conexion antes de que
 *    el mensaje llegue a una pantalla. Ver la documentacion de esa clase.
 *
 * 3. Emulacion de preparacion desactivada. Se fija
 *    PDO::ATTR_EMULATE_PREPARES a false para que las consultas se preparen en
 *    el servidor y no en PHP. Con emulacion activa, PDO construye la sentencia
 *    final en PHP, y con determinados valores escapados a mano eso reintroduce
 *    riesgos de inyeccion. Con emulacion desactivada, el motor de MariaDB hace
 *    el trabajo, que es donde debe hacerse.
 *
 * ============================================================================
 * CONCURRENCIA: POR QUE LAS FUNCIONES DE BLOQUEO ESTAN AQUI
 * ============================================================================
 *
 * La regla central de adjudicacion (apartado 6 de la especificacion) exige que
 * dos dispositivos simultaneos no adjudicen la misma unidad de premio. Eso se
 * consigue con tres mecanismos, y este fichero aporta los dos primeros:
 *
 *   - \App\Core\Db::bloquearPromocion(), basado en la funcion GET_LOCK de
 *     MariaDB, que serializa TODAS las adjudicaciones de una misma promocion
 *     mientras dura la peticion. Es imprescindible, y no por eleccion: la
 *     opcion moderna de leer saltando las filas bloqueadas,
 *     SELECT ... FOR UPDATE SKIP LOCKED, NO EXISTE en MariaDB 10.4, que es el
 *     motor de este proyecto. Solo se podria usar con una version muy superior.
 *     Ver la decision D8 y D11.
 *
 *   - Los metodos \App\Core\Db::enTransaccion(), que envuelven la lectura de la
 *     unidad candidata y su actualizacion en una sola transaccion.
 *
 * El tercero, y mas importante, no esta aqui porque depende de cada tabla: es
 * el UPDATE ... WHERE estado = 'programada' cuya fila afectada se comprueba. Ver
 * el detalle en \App\Services\Adjudicador, que se implementa en el hito 2.
 *
 * ============================================================================
 * SOBRE EL USO DE GET_LOCK
 * ============================================================================
 *
 * GET_LOCK tiene una semantica que hay que respetar y que se comprueba en cada
 * llamada: en MariaDB 10.4 una misma conexion puede tener SOLO un bloqueo
 * con nombre a la vez. Volver a pedir un bloqueo que ya se tiene en la misma
 * conexion no es un error, pero libera silenciosamente el anterior y se queda
 * solo con el nuevo. Por eso el nucleo exige que se solicite una vez por
 * peticion y que se libere siempre en un bloque finally, aunque haya una
 * excepcion a mitad del adjudicar.
 *
 * Por ultimo, GET_LOCK NO se libera solo al cerrar la transaccion, sino al
 * cerrar la conexion o al llamar a RELEASE_LOCK. Por eso la transaccion y el
 * bloqueo se gestionan por separado y de forma explicita.
 *
 * @see \App\Core\Aplicacion
 * @see \App\Services\Adjudicador
 * @see apartado 6 de la especificacion, regla central de adjudicacion
 * @see apartado 9 de la especificacion, consultas parametrizadas
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Conexion PDO y utilidades de consulta.
 */
class Db
{
    /**
     * Prefijo de los nombres de bloqueo con nombre de MariaDB.
     *
     * Se antepone al identificador de la promocion para que este bloqueo no
     * pueda colisionar con uno ajeno si alguna vez se comparte el servidor de
     * base de datos con otra aplicacion.
     *
     * @var string
     */
    public const PREFIJO_BLOQUEO = 'sorteos:';

    /**
     * Instancia unica de la clase. La conexion se comparte durante toda la
     * peticion: abrir una conexion por cada consulta es carissimo y ademas
     * romperia los bloqueos con nombre, que pertenecen a la conexion.
     *
     * @var self|null
     */
    private static ?self $instancia = null;

    /**
     * Conexion PDO subyacente.
     *
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Profundidad de anidamiento de las transacciones.
     *
     * MariaDB no admite transacciones anidadas. Como puede ocurrir que un
     * servicio que ya esta dentro de una transaccion llame a otro metodo que
     * tambien la abre, se lleva la cuenta y solo la outermost crea el BEGIN
     * real. El COMMIT o el ROLLBACK definitivos los decide el nivel mas
     * externo.
     *
     * @var int
     */
    private int $profundidadTransaccion = 0;

    /**
     * Construye la conexion a partir de los parametros de configuracion.
     *
     * @param array<string, mixed> $bd Bloque 'bd' de la configuracion, con las
     *                              claves host, puerto, nombre, usuario,
     *                              contrasena, charset y collation.
     *
     * @throws \App\Core\ErrorConfiguracion Si falta alguna clave imprescindible.
     * @throws \App\Core\ErrorBaseDeDatos  Si no se puede conectar.
     */
    public function __construct(array $bd)
    {
        // Se comprueba que estan todas las claves antes de construir la cadena
        // de conexion, para que un error de configuracion se distinga de un
        // fallo de red al leer el mensaje.
        foreach (['host', 'puerto', 'nombre', 'usuario'] as $clave) {
            if (!isset($bd[$clave]) || $bd[$clave] === '') {
                throw ErrorConfiguracion::faltaClave('bd.' . $clave);
            }
        }

        // El DSN se compone a partir de valores ya comprobados y con el nombre
        // de la base entrecomillado, porque un nombre puede contener guion.
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $bd['host'],
            (int) $bd['puerto'],
            $bd['nombre'],
            $bd['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                (string) $bd['usuario'],
                (string) ($bd['contrasena'] ?? ''),
                [
                    // Las excepciones de PDO se convierten en excepciones de
                    // PHP, lo que permite capturarlas donde interesen.
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                    // Las filas llegan como arrays asociativos. Es lo que espera
                    // el resto de la aplicacion y evita depender del numero de
                    // columnas al leerlas.
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                    // Sin emulacion: la consulta se prepara en el motor. Es la
                    // opcion segura frente a inyeccion SQL.
                    PDO::ATTR_EMULATE_PREPARES => false,

                    // Devuelve las cadenas como cadenas y no como recursos, que es
                    // lo que espera el resto de la aplicacion.
                    PDO::ATTR_STRINGIFY_FETCHES => true,
                ]
            );
        } catch (PDOException $e) {
            // La excepcion nativa se envuelve en la propia, que sanea el
            // mensaje y descarta la cadena de conexion con la contrasena.
            throw new ErrorBaseDeDatos($e);
        }
    }

    /**
     * Devuelve la instancia unica, creandola con la configuracion si hace falta.
     *
     * El patron singleton es necesario por dos razones distintas: evita abrir
     * una conexion por consulta, y sobre todo garantiza que los bloqueos con
     * nombre de MariaDB, que pertenecen a la conexion, se compartan dentro de
     * una misma peticion.
     *
     * @param array<string, mixed>|null $bd Bloque 'bd' de la configuracion. Si
     *                                     se omite, se usa el de la aplicacion.
     *
     * @return self Conexion lista para usar.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si no se puede conectar.
     */
    public static function instancia(?array $bd = null): self
    {
        // Si ya existe, se devuelve sin más. No se comprueba que siga viva:
        // PDO no reconecta solo, y una conexion caída en una peticion larga
        // debe fallar y reintentarse en la siguiente.
        if (self::$instancia instanceof self) {
            return self::$instancia;
        }

        if ($bd === null) {
            $bd = Aplicacion::config()['bd'];
        }

        self::$instancia = new self($bd);
        return self::$instancia;
    }

    /**
     * Cierra la conexion y descarta la instancia unica.
     *
     * Se usa desde los scripts de linea de comandos, que deben terminar
     * liberando la conexion, y desde las pruebas, que necesitan partir de una
     * base recien conectada entre caso y caso.
     *
     * @return void
     */
    public static function cerrar(): void
    {
        self::$instancia = null;
    }

    /**
     * Devuelve la conexion PDO subyacente.
     *
     * El acceso directo se expone solo para lo que no tiene alternativa: la
     * conexion debe ir a una transaccion manual o a un SHOW TABLE STATUS.
     * Cualquier consulta debe pasar por los metodos de esta clase.
     *
     * @return PDO Conexion PDO abierta.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Ejecuta una consulta con sus parametros y devuelve el enunciado preparado.
     *
     * Este es el metodo de trabajo. Todos los demas se apoyan en el, y cualquier
     * metodo de la aplicacion que necesite una consulta nueva que no tenga uno
     * propio debe usar este.
     *
     * @param string               $sql       Consulta con marcadores de posicion
     *                                       «?», nunca con valores concatenados.
     * @param array<mixed>         $parametros Valores a enviar, en el orden en
     *                                       que aparecen los marcadores.
     *
     * @return \PDOStatement Enunciado preparado, ya ejecutado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta es invalida o viola una
     *                                    restriccion.
     */
    public function consultar(string $sql, array $parametros = []): PDOStatement
    {
        try {
            // Se prepara y se ejecuta en dos pasos en lugar de usar query() con
            // la sentencia ya montada, porque solo asi se envian los valores
            // por el canal seguro del protocolo.
            $enunciado = $this->pdo->prepare($sql);

            // execute() espera los valores en el mismo orden que los marcadores.
            // Con listas named en la consulta habria que pasar un array
            // asociativo, pero se usan marcadores de posicion para que el orden
            // sea explicito y no dependa del texto de la consulta.
            $enunciado->execute($parametros);

            return $enunciado;
        } catch (PDOException $e) {
            // Cualquier fallo de MySQL se traduce al error propio y saneado.
            throw new ErrorBaseDeDatos($e);
        }
    }

    /**
     * Devuelve todas las filas que devuelve una consulta.
     *
     * @param string       $sql        Consulta con marcadores de posicion.
     * @param array<mixed> $parametros Valores de los marcadores.
     *
     * @return array<int, array<string, mixed>> Filas obtenidas, vacio si no hay
     *                                        ninguna. Es un array normal, no un
     *                                        generador, porque el tamano de
     *                                        estas tablas es pequeno.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function todos(string $sql, array $parametros = []): array
    {
        return $this->consultar($sql, $parametros)->fetchAll();
    }

    /**
     * Devuelve la primera fila de una consulta, o null si no hay ninguna.
     *
     * @param string       $sql        Consulta con marcadores de posicion.
     * @param array<mixed> $parametros Valores de los marcadores.
     *
     * @return array<string, mixed>|null Primera fila, o null si el resultado
     *                                   esta vacio.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function uno(string $sql, array $parametros = []): ?array
    {
        // fetch() en lugar de fetchAll() evita traer el resto de filas cuando
        // solo interesa la primera, algo que importa en las consultas con
        // ORDER BY sobre la cola de premios.
        $fila = $this->consultar($sql, $parametros)->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * Devuelve el primer valor de la primera fila de una consulta.
     *
     * Es el metodo para contar filas y comprobar existencia sin traer la fila
     * entera. Devuelve null cuando no hay resultados, lo que obliga a comprobar
     * el valor con === null en lugar de con if(), porque un cero o una cadena
     * vacia son respuestas validas.
     *
     * @param string       $sql        Consulta con marcadores de posicion.
     * @param array<mixed> $parametros Valores de los marcadores.
     *
     * @return mixed Primer valor de la primera fila, o null si no hay filas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function valor(string $sql, array $parametros = [])
    {
        $fila = $this->uno($sql, $parametros);

        if ($fila === null) {
            return null;
        }

        // Se toma el primer valor del array en el orden en que lo devuelve el
        // motor, que es el orden de las columnas de la consulta.
        $valores = array_values($fila);

        return $valores[0] ?? null;
    }

    /**
     * Ejecuta una instruccion de escritura y devuelve el numero de filas
     * afectadas.
     *
     * El numero de filas afectadas es la pieza que garantiza que una unidad de
     * premio no se adjudica dos veces (apartado 9 de la especificacion). Un
     * UPDATE con WHERE estado = 'programada' devuelve 0 si otro dispositivo se
     * ha adelantado y ha cambiado el estado, y 1 si esta peticion ha ganado. El
     * servicio de adjudicacion decide en funcion de ese numero. Por eso el
     * metodo devuelve el recuento y no un booleano.
     *
     * @param string       $sql        Instruccion con marcadores de posicion.
     * @param array<mixed> $parametros Valores de los marcadores.
     *
     * @return int Numero de filas afectadas, de 0 a N.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function ejecutar(string $sql, array $parametros = []): int
    {
        return $this->consultar($sql, $parametros)->rowCount();
    }

    /**
     * Inserta una fila y devuelve el identificador generado.
     *
     * @param string       $sql        INSERT con marcadores de posicion.
     * @param array<mixed> $parametros Valores de los marcadores.
     *
     * @return int Identificador de la fila insertada, generado por AUTO_INCREMENT.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    public function insertar(string $sql, array $parametros = []): int
    {
        $this->consultar($sql, $parametros);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Ejecuta un callable dentro de una transaccion y confirma al terminar.
     *
     * Si el callable lanza una excepcion, se deshace todo lo que hubiera hecho
     * y la excepcion se vuelve a lanzar. Es la pieza que evita que una
     * adjudicacion a medias deje un premio consumido sin participacion
     * registrada, o al reves.
     *
     * Las transacciones anidadas se colapsan: si ya hay una abierta, este
     * metodo no abre otra, porque MariaDB no las admite, y se limita a ejecutar
     * el callable. Solo el nivel mas externo confirma o deshace.
     *
     * @param callable():mixed $operacion Codigo a ejecutar. Puede ser una
     *                                     funcion anonima o el nombre de un
     *                                     metodo de clase.
     *
     * @return mixed Lo que devuelva el callable, una vez confirmada la
     *               transaccion.
     *
     * @throws \Throwable La excepcion que lance el callable, tras deshacer la
     *                    transaccion.
     */
    public function enTransaccion(callable $operacion)
    {
        // Si ya estamos dentro de una transaccion, se ejecuta el codigo y se
        // deja la decision de confirmar o deshacer al nivel externo. Devolver
        // aqui es lo que hace que el anidamiento no rompa nada.
        if ($this->profundidadTransaccion > 0) {
            return $operacion();
        }

        $this->pdo->beginTransaction();
        $this->profundidadTransaccion = 1;

        try {
            $resultado = $operacion();

            // Un SELECT que devuelve menos filas de lo esperado, o un UPDATE que
            // no ha afectado a ninguna fila, no lanzan excepcion en PHP, y por
            // tanto no se desharia la transaccion. Es la razon por la que los
            // servicios comprueban el numero de filas afectadas.
            $this->pdo->commit();

            return $resultado;
        } catch (Throwable $e) {
            // Se deshace la transaccion, pero solo si sigue abierta: si el
            // fallo vino del propio COMMIT, MySQL ya la ha cerrado y un
            // rollback lanzaria una segunda excepcion tapando la original.
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // La excepcion original se propaga tal cual. El servicio de
            // adjudicacion aprovecha esto para distinguir un intento rechazado
            // de un fallo del sistema, y para poder reintentar.
            throw $e;
        } finally {
            // finally se ejecuta tanto en el camino feliz como en el de error,
            // de modo que el contador de profundidad nunca queda descolocado.
            $this->profundidadTransaccion = 0;
        }
    }

    /**
     * Indica si hay alguna transaccion abierta en esta conexion.
     *
     * @return bool True si hay al menos una transaccion en curso.
     */
    public function enTransaccionActiva(): bool
    {
        return $this->profundidadTransaccion > 0;
    }

    /**
     * Toma el bloqueo con nombre de una promocion.
     *
     * Es la primera linea de defensa del apartado 6 de la especificacion. Con
     * el bloqueo puesto, solo se adjudica una promocion a la vez, de modo que
     * dos tablets no pueden leer la misma unidad pendiente al mismo tiempo.
     *
     * En MariaDB 10.4 el bloqueo se concede de inmediato si esta libre; el
     * segundo parametro es el tiempo maximo de espera en segundos, y se fija a
     * 5 porque una cola de tablet no debe quedarse esperando mas: si en cinco
     * segundos no se concede, es preferible decir «intente de nuevo» que dejar
     * la pantalla congelada con cara de error.
     *
     * ============================================================================
     * POR QUE EL NOMBRE NO ES EL LITERAL DE LA DECISION D8
     * ============================================================================
     *
     * D8 escribe GET_LOCK('sorteo:{id}', 5). Aqui el nombre es
     * «sorteos:adjudicacion:{id}», y conviene decir por que no es una
     * contradiccion.
     *
     * Lo que D8 fija es que el bloqueo sea POR PROMOCION y que espere cinco
     * segundos, y eso es justo lo que hace este metodo: {id} es el
     * identificador de la promocion, no el de la unidad de premio. Ese es el
     * punto de la decision, y es lo que evita que dos tablets repartiendo
     * premios distintos de la misma campana se estorben.
     *
     * El nombre literal se ha alargado por un motivo tecnico que no aparece en
     * la especificacion: GET_LOCK usa un espacio de nombres GLOBAL del servidor
     * de MariaDB, no de la base de datos. Si este proyecto llegara a compartir
     * servidor con otra aplicacion, un «sorteo:1» PODria colisionar con el
     * «sorteo:1» de la otra, y el bloqueo cruzaria campanas de dos programas
     * que no se conocen. El prefijo lo hace imposible. Ver PREFIJO_BLOQUEO.
     *
     * @param int $promocionId Identificador de la promocion cuyo bloqueo se
     *                         solicita. El nombre real es
     *                         «sorteos:adjudicacion:{id}».
     * @param int $segundos    Tiempo maximo de espera para obtenerlo.
     *
     * @return bool True si se ha conseguido el bloqueo, false si no se pudo
     *              en el tiempo indicado. Un false NO es un error de la
     *              aplicacion, es contencion: la peticion debe reintentarse.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la funcion GET_LOCK no existe en el
     *                                    motor, lo que indicaria que no se esta
     *                                    hablando con MySQL o MariaDB.
     */
    public function bloquearPromocion(int $promocionId, int $segundos = 5): bool
    {
        $nombre = self::PREFIJO_BLOQUEO . 'adjudicacion:' . $promocionId;

        try {
            // Se pide el resultado con un SELECT para que PDO lo devuelva
            // directamente. Se podria usar query() con el valor concatenado,
            // pero se mantiene el criterio de no concatenar nunca: si el
            // identificador fuera una cadena, se mandaria como parametro
            // convertido a entero por MySQL.
            $resultado = $this->valor('SELECT GET_LOCK(?, ?)', [$nombre, $segundos]);

            // GET_LOCK devuelve 1 si se concede, 0 si se agota el tiempo y NULL
            // si hubo error. El null se trata como false, que es lo seguro: si
            // hay duda, no se adjudica.
            return (int) $resultado === 1;
        } catch (ErrorBaseDeDatos $e) {
            // Si la funcion no existe, el motor no es MySQL ni MariaDB y el
            // sistema de bloqueo no es fiable. Es un fallo grave de
            // instalacion y conviene que se vea claro en lugar de fallar en
            // silencio y adjudicar premios sin proteccion.
            throw new ErrorBaseDeDatos(
                new \PDOException('La funcion GET_LOCK no esta disponible en el motor de base de datos.')
            );
        }
    }

    /**
     * Libera el bloqueo con nombre de una promocion.
     *
     * Debe llamarse siempre en un bloque finally, incluso si la adjudicacion
     * ha fallado a mitad. Un bloqueo que se queda puesto por un error bloquea
     * las siguientes participaciones de toda la promocion, lo que en el
     * supermercado significa una cola de clientes esperando sin premio.
     *
     * @param int $promocionId Identificador de la promocion cuyo bloqueo se
     *                         libera.
     *
     * @return bool True si el bloqueo se ha liberado. False si no lo tenia o
     *              si ya se habia liberado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta de liberacion falla.
     */
    public function liberarBloqueoPromocion(int $promocionId): bool
    {
        $nombre = self::PREFIJO_BLOQUEO . 'adjudicacion:' . $promocionId;

        return (int) $this->valor('SELECT RELEASE_LOCK(?)', [$nombre]) === 1;
    }
}
