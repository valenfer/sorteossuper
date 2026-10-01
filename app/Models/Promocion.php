<?php

/**
 * Modelo de las promociones, que son la raiz de todo el arbol de una campana.
 *
 * ============================================================================
 * POR QUE LA PROMOCION ESTA SEPARADA DE SUS TRAMOS Y SUS PREMIOS
 * ============================================================================
 *
 * El apartado 4.1 de la especificacion pide poder gestionar «una o mas
 * promociones sin mezclar sus datos», y el apartado 7 la lista como la tabla de
 * la que cuelgan el resto. Aqui se ve el arbol entero:
 *
 *     promociones
 *       ├── tramos
 *       │     └── asignaciones_tramo
 *       │           └── tipos_premio
 *       │                 └── unidades_premio   (una fila por unidad)
 *       ├── campos_formulario
 *       ├── reglas_participacion
 *       ├── configuracion_visual
 *       └── codigos_validos
 *
 * Que la promocion sea la raiz y no un simple nombre en cada tabla no es un
 * capricho: es lo que permite borrar una campana entera con una instruccion, y
 * lo que hace imposible que un tramo o un premio se cuelgue de dos campanas.
 *
 * ============================================================================
 * POR QUE EL ESTADO ES UN ENUM DE LA BASE DE DATOS Y NO UN TEXTO LIBRE
 * ============================================================================
 *
 * El estado decide si la campana admite participaciones y si su calendario
 * puede editarse, asi que un valor mal escrito tiene consecuencias. MySQL lo
 * restringe con un ENUM, de modo que un «activada» con «a» de mas no llega
 * nunca a la base de datos: falla en la escritura, con el nombre de la columna en
 * el error, en lugar de guardarse y dejar una campana que no aparece en ningun
 * filtro.
 *
 * ============================================================================
 * LO QUE ESTE MODELO NO HACE
 * ============================================================================
 *
 * No valida. Comprobar que una campana esta completa para poder activarla es
 * cosa de \App\Services\ConfiguracionPromocion, y comprobar que dos tramos no se
 * solapan es cosa de \App\Services\Tramos. Repartir cada regla en la clase que
 * corresponde a su tema hace que el mismo dato no se compruebe de dos maneras
 * distintas en dos sitios distintos, que es como acaba habiendo una pantalla que
 * deja pasar lo que otra prohibe.
 *
 * @see \App\Services\ConfiguracionPromocion
 * @see \App\Services\Tramos
 * @see \App\Models\Tramo
 * @see apartado 4.1 de la especificacion, datos generales
 * @see apartado 7 de la especificacion, modelo de datos
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Autorizacion;
use App\Core\Modelo;
use App\Core\NoEncontrado;

/**
 * Acceso a la tabla de promociones.
 */
class Promocion extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'promociones';

    /**
     * Campana en borrador: se puede configurar pero no admite participaciones.
     *
     * @var string
     */
    public const ESTADO_BORRADOR = 'borrador';

    /**
     * Campana en marcha: admite participaciones y semana de premio.
     *
     * @var string
     */
    public const ESTADO_ACTIVA = 'activa';

    /**
     * Campana terminada: ya no admite participaciones y su calendario se
     * conserva solo para consulta.
     *
     * @var string
     */
    public const ESTADO_FINALIZADA = 'finalizada';

    /**
     * Los tres estados validos, en el orden en que los recorre una campana.
     *
     * El orden no es alfabetico sino logico, y por eso es una constante y no un
     * listado suelto en cada pantalla: es el orden en el que una campana avanza,
     * y el que usan los botones «siguiente paso» del panel.
     *
     * @var array<int, string>
     */
    public const ESTADOS = [
        self::ESTADO_BORRADOR,
        self::ESTADO_ACTIVA,
        self::ESTADO_FINALIZADA,
    ];

    /**
     * Devuelve todas las promociones con los recuentos que muestra el panel.
     *
     * Los recuentos vienen en la misma consulta y no en consultas aparte por
     * campana. Con cuatro recuentos y tres campanas seria un mismo numero de
     * consultas, pero con veinte campanas serian ochenta, y el panel de inicio
     * es la primera pantalla que ve quien entra: tiene que abrir rapido.
     *
     * Los recuentos se hacen con subconsultas correlacionadas y no con
     * COUNT(DISTINCT ...) y GROUP BY sobre una union de las cinco tablas, porque
     * asi el motor resuelve cada una con el indice de su tabla y el resultado
     * sale ya ordenado por el indice ix_promociones_estado.
     *
     * @return array<int, array<string, mixed>> Promociones ordenadas por estado
     *                                    y, dentro de cada estado, por nombre.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listar(): array
    {
        return $this->db->todos(
            'SELECT p.id,
                    p.nombre,
                    p.estado,
                    p.fecha_inicio,
                    p.fecha_fin,
                    p.comercio_nombre,
                    p.modo_simulacion,
                    p.actualizada_en,
                    (SELECT COUNT(*) FROM tramos t
                      WHERE t.promocion_id = p.id) AS tramos,
                    (SELECT COUNT(*) FROM tipos_premio tp
                      WHERE tp.promocion_id = p.id AND tp.activo = 1) AS tipos_activos,
                    (SELECT COUNT(*) FROM unidades_premio u
                      WHERE u.promocion_id = p.id) AS unidades,
                    (SELECT COUNT(*) FROM unidades_premio u
                      WHERE u.promocion_id = p.id AND u.estado = ?)
                        AS unidades_programadas,
                    (SELECT COUNT(*) FROM unidades_premio u
                      WHERE u.promocion_id = p.id AND u.estado = ?)
                        AS unidades_entregadas,
                    (SELECT COUNT(*) FROM participaciones pa
                      WHERE pa.promocion_id = p.id) AS participaciones
               FROM promociones p
              ORDER BY FIELD(p.estado, ?, ?, ?), p.nombre ASC',
            [
                self::ESTADO_BORRADOR,
                self::ESTADO_ACTIVA,
                self::ESTADO_FINALIZADA,
                UnidadPremio::ESTADO_PROGRAMADA,
                UnidadPremio::ESTADO_ENTREGADA,
            ]
        );
    }

    /**
     * Devuelve una campana o lanza el error de pagina inexistente.
     *
     * Se llama «exigirPorId» y no «buscarPorId» a proposito, porque no es la
     * misma operacion con otro nombre: el modelo base devuelve null cuando no
     * encuentra la fila, que es lo que necesita un servicio que decide; esto
     * responde 404, que es lo que necesita una pantalla. Confundir las dos cosas
     * es como un panel acaba pintando una campana vacia cuando lo que ha
     * ocurrido es que la URL estaba mal escrita.
     *
     * @param int    $id   Identificador de la campana.
     * @param string $ruta Ruta que se cita en el mensaje de error.
     *
     * @return array<string, mixed> Fila completa de la campana.
     *
     * @throws \App\Core\NoEncontrado Si no existe una campana con ese identificador.
     */
    public function exigirPorId(int $id, string $ruta = ''): array
    {
        $promocion = $this->buscarPorId($id);

        if ($promocion === null) {
            throw new NoEncontrado($ruta !== '' ? $ruta : 'promocion/' . $id);
        }

        return $promocion;
    }

    /**
     * Guarda los datos generales de una campana, creándola si no existe.
     *
     * Se resuelve el «INSERT o UPDATE» en PHP y no con un REPLACE: REPLACE
     * borra la fila y la vuelve a insertar, lo que con ON DELETE SET NULL y
     * CASCADE dejaria sin historial las unidades que cuelgan de la campana, y
     * ademas cambiaria su identificador. Un REPLACE sobre la tabla de la que
     * cuelgan quince filas mas es exactamente el tipo de atajo que no se nota
     * hasta que hay una campana en marcha.
     *
     * @param array<string, mixed> $datos Valores ya validados por el controlador.
     *                                    Las claves son obligatorias y son
     *                                    exactamente estas: «nombre»,
     *                                    «descripcion», «comercio_nombre»,
     *                                    «comercio_cif», «comercio_domicilio»,
     *                                    «comercio_telefono», «zona_horaria»,
     *                                    «estado», «fecha_inicio» y
     *                                    «fecha_fin». Las cuatro que pueden
     *                                    quedar vacias se pasan con la cadena
     *                                    vacia, no se omiten.
     *                                    Los datos de correo, el modo simulacion
     *                                    y la loteria persistente no se tocan
     *                                    aqui: van en guardarConfiguracion(),
     *                                    porque pertenecen a otra pantalla del
     *                                    panel y mezclarlos haria que guardar el
     *                                    nombre de la campana borrase el texto
     *                                    del correo de ganadoras.
     * @param int|null             $id    Identificador si se edita, o null si
     *                                    se crea una campana nueva.
     *
     * @return int Identificador de la campana guardada.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardar(array $datos, ?int $id = null): int
    {
        $ahora = Aplicacion::ahora();

        // La columna creado_por admite NULL y su clave foranea es SET NULL, de
        // modo que una campana sobrevive a que se borre su autor. Se guarda NULL
        // en lugar de cero cuando no hay sesion, porque el cero no es un
        // identificador valido y el esquema no lo comprueba.
        $usuarioId = Autorizacion::usuarioId();
        $autor = $usuarioId > 0 ? $usuarioId : null;

        if ($id === null) {
            return $this->db->insertar(
                'INSERT INTO promociones (
                     nombre, descripcion,
                     comercio_nombre, comercio_cif, comercio_domicilio, comercio_telefono,
                     zona_horaria, estado, fecha_inicio, fecha_fin,
                     creado_por, creado_en, actualizada_en
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $datos['nombre'],
                    $datos['descripcion'] !== '' ? $datos['descripcion'] : null,
                    $datos['comercio_nombre'],
                    $datos['comercio_cif'],
                    $datos['comercio_domicilio'],
                    $datos['comercio_telefono'],
                    $datos['zona_horaria'],
                    $datos['estado'],
                    $datos['fecha_inicio'] !== '' ? $datos['fecha_inicio'] : null,
                    $datos['fecha_fin'] !== '' ? $datos['fecha_fin'] : null,
                    $autor,
                    $ahora,
                    $ahora,
                ]
            );
        }

        // En una edicion no se toca creado_por ni creado_en: son la traza de
        // cuando nacio la campana, y quien la creo no deja de haberla creado
        // porque otro la edite. El estado si se puede cambiar, y por eso va
        // incluido en la lista de columnas que se actualizan.
        $this->db->ejecutar(
            'UPDATE promociones
                SET nombre = ?,
                    descripcion = ?,
                    comercio_nombre = ?,
                    comercio_cif = ?,
                    comercio_domicilio = ?,
                    comercio_telefono = ?,
                    zona_horaria = ?,
                    estado = ?,
                    fecha_inicio = ?,
                    fecha_fin = ?,
                    actualizada_en = ?
              WHERE id = ?',
            [
                $datos['nombre'],
                $datos['descripcion'] !== '' ? $datos['descripcion'] : null,
                $datos['comercio_nombre'],
                $datos['comercio_cif'],
                $datos['comercio_domicilio'],
                $datos['comercio_telefono'],
                $datos['zona_horaria'],
                $datos['estado'],
                $datos['fecha_inicio'] !== '' ? $datos['fecha_inicio'] : null,
                $datos['fecha_fin'] !== '' ? $datos['fecha_fin'] : null,
                $ahora,
                $id,
            ]
        );

        return $id;
    }

    /**
     * Guarda los ajustes de una campana que no son sus datos generales.
     *
     * Va aparte de guardar() a proposito. Son las columnas del correo, el modo
     * simulacion, la loteria persistente y la retencion, que el panel edita desde
     * una pantalla distinta. Si compartieran metodo, guardar el nombre de la
     * campana desde la ficha dejaria el cuerpo del correo de ganadoras vacio,
     * porque esa pantalla no tiene esos campos delante y no podria reenviarlos.
     *
     * Las claves son obligatorias y son exactamente estas: «modo_simulacion»,
     * «loteria_persiste», «correo_ganador», «correo_no_ganador»,
     * «correo_ganador_asunto», «correo_ganador_cuerpo»,
     * «correo_no_ganador_asunto», «correo_no_ganador_cuerpo» y «retencion_dias».
     * Las de tipo TINYINT(1) se pasan como true o false, y la de retencion como
     * un entero o null.
     *
     * @param array<string, mixed> $datos Ajustes ya validados por el controlador.
     * @param int                  $id    Identificador de la campana.
     *
     * @return int Numero de filas afectadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardarConfiguracion(array $datos, int $id): int
    {
        return $this->db->ejecutar(
            'UPDATE promociones
                SET modo_simulacion = ?,
                    loteria_persiste = ?,
                    correo_ganador = ?,
                    correo_no_ganador = ?,
                    correo_ganador_asunto = ?,
                    correo_ganador_cuerpo = ?,
                    correo_no_ganador_asunto = ?,
                    correo_no_ganador_cuerpo = ?,
                    retencion_dias = ?,
                    actualizada_en = ?
              WHERE id = ?',
            [
                $datos['modo_simulacion'] ? 1 : 0,
                $datos['loteria_persiste'],
                $datos['correo_ganador'] ? 1 : 0,
                $datos['correo_no_ganador'] ? 1 : 0,
                $datos['correo_ganador_asunto'],
                $datos['correo_ganador_cuerpo'] !== '' ? $datos['correo_ganador_cuerpo'] : null,
                $datos['correo_no_ganador_asunto'],
                $datos['correo_no_ganador_cuerpo'] !== '' ? $datos['correo_no_ganador_cuerpo'] : null,
                $datos['retencion_dias'],
                Aplicacion::ahora(),
                $id,
            ]
        );
    }

    /**
     * Cambia el estado de una campana.
     *
     * La comprobacion de si la campana esta completa para activarse NO esta
     * aqui: es cosa de \App\Services\ConfiguracionPromocion::puedeActivar(), y
     * este metodo solo escribe. La separacion es deliberada, porque el mismo
     * cambio de estado llega desde tres sitios —el boton de la ficha, el boton del
     * listado y el finalizador de la suite— y un servicio que valida no es lo
     * mismo que un modelo que escribe.
     *
     * @param int    $id     Identificador de la campana.
     * @param string $estado Estado nuevo, de la lista self::ESTADOS.
     *
     * @return int Numero de filas afectadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function cambiarEstado(int $id, string $estado): int
    {
        return $this->db->ejecutar(
            'UPDATE promociones
                SET estado = ?, actualizada_en = ?
              WHERE id = ?',
            [$estado, Aplicacion::ahora(), $id]
        );
    }

    /**
     * Actualiza la marca de tiempo de una campana sin tocar nada mas.
     *
     * Lo usa el servicio de configuracion al final de una transaccion, para que
     * el panel diga que una campana se ha tocado hoy aunque el cambioangible sea
     * una unidad de premio anulada. Es una fraccion de segundo de trabajo que
     * evita tener que buscar en el log que campana se esta moviendo.
     *
     * @param int $id Identificador de la campana.
     *
     * @return int Numero de filas afectadas.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function tocar(int $id): int
    {
        return $this->db->ejecutar(
            'UPDATE promociones SET actualizada_en = ? WHERE id = ?',
            [Aplicacion::ahora(), $id]
        );
    }

    /**
     * Cierra una campana: la pasa a finalizada y le pone la hora de cierre.
     *
     * ============================================================================
     * POR QUE ESTO NO ES UN cambiarEstado() MAS
     * ============================================================================
     *
     * Porque cerrar una campana no es cambiar una etiqueta: es registrar un hecho
     * con su hora, y la columna cerrada_en existe precisamente para eso. Si el
     * cierre se hiciera con el cambiarEstado() de arriba, la campana quedaria
     * finalizada sin saber cuando, y el panel no podria distinguir una campana
     * que se cerro hace un minuto de una que lleva un ano cerrada.
     *
     * El instante se recibe como parametro y no se pide aqui a
     * \App\Core\Aplicacion::ahora(), para que el servicio de cierre use la misma
     * hora en las unidades y en la campana. Si cada uno pidiera la hora por su
     * cuenta, entre una operacion y la otra pasarian unos milisegundos, y las dos
     * marcas serian distintas por un motivo que no significa nada.
     *
     * ============================================================================
     * POR QUE EL WHERE LLEVA EL ESTADO ANTERIOR
     * ============================================================================
     *
     * Por lo mismo que en \App\Models\UnidadPremio::noEntregarProgramadas(): para
     * que el cierre no pueda repetirse. Con WHERE estado = 'activa', la segunda
     * llamada no afecta a ninguna fila y el servicio puede detectarlo y avisar en
     * lugar de escribir una segunda vez. Sin esa condicion, un doble clic volveria
     * a confirmar el cierre y volveria a mover las marcas de tiempo de una
     * campana que ya estaba cerrada.
     *
     * @param int    $id      Identificador de la campana.
     * @param string $momento Instante del cierre, en el formato de la base de
     *                        datos.
     *
     * @return int Numero de filas afectadas: 1 si se ha cerrado ahora, 0 si ya
     *             estaba cerrada.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function finalizar(int $id, string $momento): int
    {
        return $this->db->ejecutar(
            'UPDATE promociones
                SET estado = ?,
                    cerrada_en = ?,
                    actualizada_en = ?
              WHERE id = ?
                AND estado = ?',
            [
                self::ESTADO_FINALIZADA,
                $momento,
                $momento,
                $id,
                self::ESTADO_ACTIVA,
            ]
        );
    }

    /**
     * Cuenta las campanas agrupadas por estado.
     *
     * @return array<string, int> Estados como claves y numero de campanas como
     *                            valores. Los estados sin campanas no aparecen.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPorEstado(): array
    {
        $filas = $this->db->todos(
            'SELECT estado, COUNT(*) AS total FROM promociones GROUP BY estado'
        );

        $recuento = [];

        foreach ($filas as $fila) {
            $recuento[(string) $fila['estado']] = (int) $fila['total'];
        }

        return $recuento;
    }
}
