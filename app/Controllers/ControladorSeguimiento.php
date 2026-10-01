<?php

/**
 * Panel de seguimiento de una campana y cierre de la campana.
 *
 * ============================================================================
 * POR QUE EL CIERRE ESTA EN EL MISMO CONTROLADOR QUE EL PANEL
 * ============================================================================
 *
 * Porque las dos pantallas son la ultima pantalla de una campana. El panel es
 * donde se mira lo que ha pasado y se decide que ya no queda nada que repartir; el
 * cierre es la accion que se ejecuta con esa decision tomada. Separarlos en dos
 * controladores obligaria a saltar de una pantalla a otra justo en el momento en
 * que el administrador esta tomando la decision, que es cuando un salto a otra
 * pantalla hace que alguien se lo piense dos veces y cierre la pestana.
 *
 * ============================================================================
 * POR QUE CERRAR ES UN POST CON CSRF Y NO UN ENLACE
 * ============================================================================
 *
 * Por lo mismo que activar: cerrar es irreversible en la practica. Una vez
 * cerrada la campana, las unidades programadas pasan a no entregadas y no hay
 * forma de volverlas a programar. Un enlace se activa con un clic, y un clic se
 * da sin querer. Con un formulario hay que querer de verdad, y el token de CSRF
 * impide que un sitio de terceros pueda mandar el cierre desde otro pagina.
 *
 * ============================================================================
 * POR QUE EL PANEL ESCRIBE AUDITORIA ANTES DE PINTAR NADA
 * ============================================================================
 *
 * Porque es lo que exige la decision D18: no se guarda «quien ha visto esto»,
 * sino «quien ha visto esto, con que filtro y cuantas filas». La llamada a
 * anotarVisita() va antes de la vista, no despues, y no por prudencia abstracta:
 * si la escritura fallara despues de haber salido datos personales en la pantalla,
 * quedaria una pagina entera en el navegador de alguien sin rastro de que se ha
 * visto. La fila se escribe aunque luego la vista falle al pintarse, y eso tambien
 * es lo correcto: los datos llegaron a existir en la salida de la aplicacion.
 *
 * @see \App\Services\Seguimiento
 * @see \App\Services\CierrePromocion
 * @see \App\Models\Auditoria
 * @see \App\Core\Csrf
 * @see apartado 8 de la especificacion
 * @see decisiones D4 y D18
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Autorizacion;
use App\Core\Controlador;
use App\Core\ErrorAplicacion;
use App\Core\Vista;
use App\Models\UnidadPremio;
use App\Services\CierrePromocion;
use App\Services\Seguimiento;

/**
 * Panel de seguimiento y cierre de una campana.
 */
class ControladorSeguimiento extends Controlador
{
    /**
     * Servicio que reunion los datos del panel.
     *
     * @var \App\Services\Seguimiento
     */
    private Seguimiento $seguimiento;

    /**
     * Servicio que cierra la campana.
     *
     * @var \App\Services\CierrePromocion
     */
    private CierrePromocion $cierre;

    /**
     * Prepara los servicios.
     */
    public function __construct()
    {
        $this->seguimiento = new Seguimiento();
        $this->cierre = new CierrePromocion();
    }

    /**
     * Muestra el panel de seguimiento de una campana.
     *
     * @return void
     */
    public function panel(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $filtros = $this->filtros();

        $datos = $this->seguimiento->panel($id, $filtros);

        // La auditoria de la visita se escribe con el total del listado principal,
        // que es el total ya filtrado y no el de la campana entera. Es el unico
        // numero que responde a «¿cuanto ha visto esta visita?».
        $this->seguimiento->anotarVisita(
            $id,
            $datos['filtros'],
            (int) $datos['listado']['total_unidades'],
            Autorizacion::usuarioId()
        );

        // El historial de auditoria se relee despues de anotar la visita, para que
        // la linea que se acaba de escribir se vea en la propia pantalla. Sin este
        // segundo paso, el administrador veria un recuento que no incluye su
        // propia consulta y creeria que la D18 no esta funcionando.
        $datos['auditoria']['historial'] = (new \App\Models\Auditoria())->listarPorCampana(
            $id,
            Seguimiento::LIMITE_AUDITORIA
        );

        $this->vista('admin/seguimiento/panel', [
            'titulo' => 'Seguimiento de ' . $datos['campana']['nombre'],

            // La clave se llama «panel» y no «datos» a proposito. Al pintar, el
            // nucleo extrae las variables con extract() y EXTR_SKIP, y el nombre
            // del array que se esta extrayendo es $datos: una clave llamada
            // «datos» no llegaria nunca a la vista, porque extract() respeta las
            // variables que ya existen. El fallo es silencioso —la vista recibe un
            // array distinto del que cree y avisa de una clave que falta—, asi
            // que conviene no usar ese nombre.
            'panel' => $datos,
            'estados' => UnidadPremio::estados(),
            'puedeCerrar' => $this->cierre->puedeCerrar($id),
        ]);
    }

    /**
     * Cierra la campana y avisa de lo que se ha quedado sin entregar.
     *
     * El mensaje dice cuantas unidades se han quedado sin reclamar en singular o
     * en plural, porque «1 premios sin entregar» en una pantalla de tienda queda
     * mal y el administrador lo va a leer tres veces.
     *
     * @return void
     */
    public function cerrar(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');

        try {
            $resultado = $this->cierre->cerrar($id, null, Autorizacion::usuarioId());
        } catch (ErrorAplicacion $e) {
            Vista::guardarAviso($e->getMessage(), 'error');
            $this->redirigir('admin/promociones/' . $id . '/seguimiento');
            return;
        }

        $sinEntregar = (int) $resultado['unidades_no_entregadas'];

        if ($sinEntregar === 0) {
            Vista::guardarAviso('Campana cerrada. No queda ningun premio sin entregar.', 'exito');
        } else {
            Vista::guardarAviso(sprintf(
                'Campana cerrada. Se han quedado %d %s sin entregar.',
                $sinEntregar,
                $sinEntregar === 1 ? 'premio' : 'premios'
            ), 'exito');
        }

        $this->redirigir('admin/promociones/' . $id . '/seguimiento');
    }

    /**
     * Lee los filtros de la URL.
     *
     * Los nombres de los parametros son los del apartado 8: fecha, tramo y premio.
     * Los dos identificadores se aceptan como numero y se descartan si no lo son,
     * en lugar de fallar con un error, porque un enlace guardado con un tramo que
     * ya no tiene que devolver un 404 a un administrador que solo queria mirar el
     * panel. La fecha se pasa tal cual y la valida el servicio, que es quien sabe
     * que formatos acepta.
     *
     * @return array<string, mixed> Filtros leidos de la peticion.
     */
    private function filtros(): array
    {
        $filtros = [];

        $fecha = trim((string) $this->entrada('fecha', ''));

        if ($fecha !== '') {
            $filtros['fecha'] = $fecha;
        }

        $tramoId = (int) $this->entrada('tramo', 0);

        if ($tramoId > 0) {
            $filtros['tramo_id'] = $tramoId;
        }

        $premioId = (int) $this->entrada('premio', 0);

        if ($premioId > 0) {
            $filtros['tipo_premio_id'] = $premioId;
        }

        return $filtros;
    }
}
