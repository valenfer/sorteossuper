<?php

/**
 * Modelo de la auditoria: la fila que deja constancia de quien hizo que.
 *
 * ============================================================================
 * POR QUE EXISTE UNA TABLA DE AUDITORIA Y NO UN LOG DE FICHERO
 * ============================================================================
 *
 * El log de ficheros que escriben \App\Core\Autorizacion y el nucleo es util
 * para depurar, pero se pierde en cuanto alguien rota el fichero o reinstala la
 * aplicacion, y no se puede consultar con una consulta de la base de datos. Aqui
 * hace falta justo lo contrario: poder preguntar «¿quien abrio esta campana?» o
 * «¿que paso con estas tres unidades sin entregar?» manyos meses despues, y poder
 * hacerlo filtrando por campana.
 *
 * Por eso la auditoria es una tabla y no un fichero. Y por eso guarda tambien
 * el nombre del usuario en el momento de escribir la fila, aunque la sesion solo
 * guarde su identificador: si dentro de dos años se renombra o se borra la
 * cuenta, la fila tiene que seguir diciendo quien lo hizo. Un identificador sin
 * nombre es una pista, no una respuesta.
 *
 * ============================================================================
 * POR QUE datos_antes Y datos_despues NO SIRVEN PARA TODO
 * ============================================================================
 *
 * El esquema los reserva para cambios de configuracion, y asi se respeta aqui.
 * Los filtros de una vista de lista y el numero de filas que ha visto el
 * administrador no son una configuracion que se pueda deshacer, y meterlos en
 * datos_despues los haria indistinguibles de un cambio real. Van en columnas
 * propias, filtros y filas_mostradas, que se anadieron con la migracion
 * 0001_auditoria_filtros.
 *
 * ============================================================================
 * LO QUE NUNCA SE ESCRIBE AQUI
 * ============================================================================
 *
 * El contenido de la columna datos de una participacion. Auditar que se ha
 * entregado un premio no necesita el nombre de quien lo recibio, y la decision
 * D10 dice que los datos personales de una persona no viajan a sitios que no los
 * necesitan. Lo que se guarda son identificadores y recuentos: quien ha visto
 * cuantos registros, con que filtro, y cuando. Eso es lo que exige la decision
 * D18, que es una constancia de accesos, no un volcado de contenido.
 *
 * @see \App\Services\Adjudicador
 * @see \App\Services\CierrePromocion
 * @see \App\Services\Seguimiento
 * @see apartados 3 y 8 de la especificacion
 * @see decisiones D10 y D18
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;

/**
 * Acceso a la tabla de auditoria.
 */
class Auditoria extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'auditoria';

    /**
     * Accion de una consulta o de una pantalla, sin que se cambie nada.
     *
     * @var string
     */
    public const ACCION_VISUALIZACION = 'visualizacion';

    /**
     * Accion de un cierre de campana.
     *
     * @var string
     */
    public const ACCION_CIERRE = 'cierre';

    /**
     * Accion de un cambio de configuracion, como los ajustes o el plan de premios.
     *
     * @var string
     */
    public const ACCION_CONFIGURACION = 'configuracion';

    /**
     * Accion de la creacion de una entidad.
     *
     * @var string
     */
    public const ACCION_ALTA = 'alta';

    /**
     * Accion del vaciado de datos personales por retencion.
     *
     * Es el asiento que deja constancia de una purga. Se escribe uno por campana,
     * no uno por fila vaciada: una campana puede tener veinte mil participaciones,
     * y veinte mil filas de auditoria en las que ademas no se puede decir ni que
     * se vacio serian inutiles. Lo que tiene que poder contestarse es «¿cuando se
     * purgo esta campana y cuantas filas?to», y eso cabe en un recuento.
     *
     * El recuento va en `datos_despues` y no en `filtros` ni en `filas_mostradas`:
     * estos dos describen lo que alguien ha mirado, que es otra cosa.
     *
     * @var string
     */
    public const ACCION_PURGA = 'purga';

    /**
     * Escribe una fila de auditoria.
     *
     * La accion no se comprueba contra una lista cerrada, a proposito: la tabla no
     * tiene ninguna restriccion sobre esa columna, y anadirla obligaria a
     * actualizar el esquema cada vez que se describa un tipo de cambio nuevo, que
     * es justo el tipo de obstaculo que hace que nadie escriba la auditoria. Las
     * constantes de esta clase documentan los valores de uso normal.
     *
     * La hora no se recibe como parametro a proposito: sale de
     * \App\Core\Aplicacion::ahora(), que ya aplica la zona horaria de la
     * aplicacion. Pedirla desde fuera abriria la puerta a que un cambio quedara
     * registrado con una hora distinta de la del servidor.
     *
     * @param int|null                  $promocionId  Campana a la que pertenece
     *                                                 el cambio, o null si el
     *                                                 cambio no depende de
     *                                                 ninguna campana. No se
     *                                                 admite el cero: la columna
     *                                                 es una clave foranea y el
     *                                                 cero no seria una
     *                                                 campana.
     * @param int|null                  $usuarioId    Usuario que lo ha hecho, o
     *                                                 null si no hay sesion, como
     *                                                 ocurre en las pruebas y en
     *                                                 los scripts de consola.
     * @param string                    $usuarioNombre Nombre legible del
     *                                                 usuario en el momento del
     *                                                 cambio.
     * @param string                    $entidad      Tabla o pantalla
     *                                                 afectada.
     * @param string                    $entidadId    Identificador de la fila o
     *                                                 de la pantalla.
     * @param string                    $accion       Que se ha hecho, en
     *                                                 minusculas y sin tildes.
     * @param array<string, mixed>|null $datosAntes   Como estaba antes, o null
     *                                                 si la entidad no existia.
     * @param array<string, mixed>|null $datosDespues Como queda despues, o null
     *                                                 si la entidad deja de
     *                                                 existir.
     * @param string|null               $filtros      Descripcion de los
     *                                                 filtros de una vista de
     *                                                 lista, o null si la fila
     *                                                 no procede de una.
     * @param int|null                  $filasMostradas Cuantas filas ha visto
     *                                                 la persona en esa vista.
     * @param string                    $ip           Direccion IP de la
     *                                                 peticion. La columna no
     *                                                 admite null, y en consola
     *                                                 se escribe la cadena
     *                                                 vacia.
     *
     * @return int Identificador de la fila escrita.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    public function registrar(
        ?int $promocionId,
        ?int $usuarioId,
        string $usuarioNombre,
        string $entidad,
        string $entidadId,
        string $accion,
        ?array $datosAntes = null,
        ?array $datosDespues = null,
        ?string $filtros = null,
        ?int $filasMostradas = null,
        string $ip = ''
    ): int {
        // Un identificador de usuario que no sea positivo se guarda como NULL. No
        // es un caso raro ni un error: \App\Core\Autorizacion::usuarioId() devuelve
        // cero cuando no hay sesion, que es lo que pasa en las pruebas y en los
        // scripts de linea de comandos, y la columna usuario_id es una clave
        // foranea a usuarios, donde el cero no puede existir porque la tabla empieza
        // en uno. Sin esta normalizacion, auditar una pantalla en consola
        // reventaria con un error de clave foranea, que es la peor forma de
        // descubrir que se estaba jugando con el cero.
        if ($usuarioId !== null && $usuarioId <= 0) {
            $usuarioId = null;
        }

        return $this->db->insertar(
            'INSERT INTO auditoria (
                 promocion_id,
                 usuario_id,
                 usuario_nombre,
                 entidad,
                 entidad_id,
                 accion,
                 datos_antes,
                 datos_despues,
                 filtros,
                 filas_mostradas,
                 ip,
                 creado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $promocionId,
                $usuarioId,
                $usuarioNombre,
                $entidad,
                $entidadId,
                $accion,
                $this->aJson($datosAntes),
                $this->aJson($datosDespues),
                $filtros,
                $filasMostradas,
                $ip,
                Aplicacion::ahora(),
            ]
        );
    }

    /**
     * Dice si una campana ya tiene escrito un asiento de una accion.
     *
     * ============================================================================
     * POR QUE HACE FALTA ESTE METODO Y NO UN CONTEO
     * ============================================================================
     *
     * Porque el conteo por accion se agrupa por accion y no se puede filtrar por
     * ella sin_GROUP BY una accion concreta, y la purga necesita preguntar por
     * una sola. Un EXISTS responde con un si o un no y no trae recuentos que
     * luego hay que descartar.
     *
     * La comprobacion es «existe ya el asiento de esta campana con esta accion»,
     * sin mirar la fecha ni quien lo escribio, porque en la purga lo que importa es
     * que la operacion ya se hizo y se hizo una vez. Volver a mirarla no aporta
     * nada: el vaciado de las tablas es idempotente y su recuento seria cero.
     *
     * El indice es (promocion_id, accion), asi que la busqueda no recorre la
     * tabla, que es lo que importa porque esta comprobacion se hace en cada
     * campana de cada pasada y el historial de auditoria es la tabla que mas crece
     * del sistema por culpa de las consultas del panel.
     *
     * @param int    $promocionId Campana que se comprueba.
     * @param int|null $usuarioId  Usuario que se filtra, o null para no filtrar.
     * @param string $entidad     Entidad que se filtra.
     * @param string $accion      Accion que se busca.
     *
     * @return bool True si ya hay al menos un asiento con esa accion.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function existe(int $promocionId, ?int $usuarioId, string $entidad, string $accion): bool
    {
        return (int) $this->db->valor(
            'SELECT EXISTS (
                 SELECT 1
                   FROM auditoria
                  WHERE promocion_id = ?
                    AND (? IS NULL OR usuario_id = ?)
                    AND entidad = ?
                    AND accion = ?
             )',
            [$promocionId, $usuarioId, $usuarioId, $entidad, $accion]
        ) === 1;
    }

    /**
     * Cuenta las filas de auditoria de una campana agrupadas por accion.
     *
     * Es lo que alimenta la casilla de auditoria del panel, y lo que hace que
     * una consulta repetida se vea en el recuento aunque el listado este mas
     * abajo en la pantalla.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return array<string, int> Acciones como claves y recuentos como valores.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPorAccion(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT accion, COUNT(*) AS total
               FROM auditoria
              WHERE promocion_id = ?
              GROUP BY accion',
            [$promocionId]
        );

        $recuento = [];

        foreach ($filas as $fila) {
            $recuento[(string) $fila['accion']] = (int) $fila['total'];
        }

        return $recuento;
    }

    /**
     * Devuelve las filas de auditoria de una campana, de la mas reciente a la mas
     * antigua.
     *
     * El limite va en la consulta y no se recorta despues, porque la tabla crece
     * con cada consulta que hace el administrador: un panel que se mira una vez al
     * dia durante un mes acumula miles de filas de visualizacion, y cargarlas
     * todas para ensenar las veinte ultimas es un desperdicio que se nota en un
     * servidor pequeno.
     *
     * @param int $promocionId Campana que se quiere listar.
     * @param int $limite     Cuantas filas como maximo se devuelven.
     *
     * @return array<int, array<string, mixed>> Filas ya ordenadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listarPorCampana(int $promocionId, int $limite = 50): array
    {
        if ($limite < 1) {
            $limite = 1;
        }

        return $this->db->todos(
            'SELECT id,
                    usuario_nombre,
                    entidad,
                    entidad_id,
                    accion,
                    datos_antes,
                    datos_despues,
                    filtros,
                    filas_mostradas,
                    creado_en
               FROM auditoria
              WHERE promocion_id = ?
              ORDER BY creado_en DESC, id DESC
              LIMIT ?',
            [$promocionId, $limite]
        );
    }

    /**
     * Convierte un documento de auditoria en el JSON que se guarda.
     *
     * Un null se escribe como NULL de SQL y no como el texto «null», para que se
     * distinga «no habia nada antes» de «habia un documento vacio». Es la
     * diferencia entre «se creo una campana nueva» y «se modifico una que no
     * tenia nombre», y se nota al leer el historial.
     *
     * @param array<string, mixed>|null $datos Documento a serializar.
     *
     * @return string|null Texto JSON, o null si no hay documento.
     */
    private function aJson(?array $datos): ?string
    {
        if ($datos === null) {
            return null;
        }

        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // json_encode solo falla con valores sin representacion en JSON, como un
        // NAN o una cadena con bytes invalidos. Los documentos que se auditan aqui
        // son escalares y cadenas cortas, asi que no deberia ocurrir nunca. Se
        // comprueba de todos modos, porque perder el detalle de un cambio es mejor
        // que hacer fallar la operacion que se pretedia registrar.
        return $json === false ? null : $json;
    }
}
