<?php

/**
 * Pantallas de la campana: listado, ficha y datos generales.
 *
 * ============================================================================
 * QUE HAY EN ESTE CONTROLADOR Y QUE HAY EN OTRO
 * ============================================================================
 *
 * La configuracion de una campana son ocho formularios. Si todos estuvieran en
 * una sola clase, ese fichero pasaria de mil lineas y cualquier cambio en uno
 * de ellos haria conflictos en todos los demas. Asi que se reparten por
 * pantalla y por parecido:
 *
 *   - Este: el listado de campanas, su ficha y los datos generales.
 *   - ControladorPremios: los premios y sus cantidades por tramo.
 *   - ControladorFormulario: los campos que rellena la clienta y las reglas.
 *   - ControladorCalendario: los tramos, el calendario y la activacion.
 *
 * Lo que comparten no se repite porque esta en el servicio
 * \App\Services\ConfiguracionPromocion y en los modelos. Aqui no hay
 * validacion: eso es del servicio, que se puede probar sin pasar por HTTP.
 *
 * ============================================================================
 * POR QUE LA FICHA ES UNA PANTALLA Y NO UN MENU
 * ============================================================================
 *
 * Al abrir una campana hay que responder a una pregunta de golpe: «¿esta
 * lista?». Si esa respuesta estuviera repartida en ocho pantallas, habría que
 * entrar en las ocho para averiguarlo. Por eso la ficha trae el resumen con los
 * totales y la lista de lo que falta, y el boton de activar esta ahi, no escondido
 * en un submenu. Es tambien lo que pide el apartado 4.4.
 *
 * @see \App\Services\ConfiguracionPromocion
 * @see \App\Core\Csrf
 * @see apartado 4 del documento de especificacion, configuracion de la campana
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ErrorValidacion;
use App\Core\Vista;
use App\Models\Promocion;
use App\Services\ConfiguracionPromocion;

/**
 * Listado de campanas, ficha de campana y formulario de datos generales.
 */
class ControladorCampanas extends Controlador
{
    /**
     * Servicio de configuracion, que valida lo que llega de los formularios.
     *
     * @var \App\Services\ConfiguracionPromocion
     */
    private ConfiguracionPromocion $config;

    /**
     * Prepara los servicios.
     */
    public function __construct()
    {
        $this->config = new ConfiguracionPromocion();
    }

    /**
     * Lista todas las campanas.
     *
     * @return void
     */
    public function listar(): void
    {
        $promociones = new Promocion();

        $this->vista('admin/campanas/listado', [
            'titulo'   => 'Campanas',
            'campanas' => $promociones->listar(),
            'porEstado' => $promociones->contarPorEstado(),
        ]);
    }

    /**
     * Muestra el formulario de datos generales de una campana nueva.
     *
     * @return void
     */
    public function nueva(): void
    {
        $this->formularioGeneral(null, []);
    }

    /**
     * Crea una campana con los datos generales.
     *
     * @return void
     */
    public function crear(): void
    {
        $this->exigirCsrf();

        try {
            $id = $this->config->guardar($this->datosGenerales());
        } catch (ErrorValidacion $e) {
            // Se vuelve a pintar el formulario con lo que se habia escrito. Sin
            // esto, corregir un solo campo obligaria a reescribir la campana
            // entera, que en un supermercado significa cola parada.
            $this->formularioGeneral(null, $this->datosGenerales(), $e->errores());
            return;
        }

        Vista::guardarAviso('Campana creada. Ya puedes anadirle premios.', 'exito');
        $this->redirigir('admin/promociones/' . $id);
    }

    /**
     * Muestra el formulario de datos generales de una campana existente.
     *
     * @return void
     */
    public function editar(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $this->formularioGeneral($id, []);
    }

    /**
     * Guarda los datos generales de una campana existente.
     *
     * @return void
     */
    public function actualizar(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');

        try {
            $this->config->guardar($this->datosGenerales(), $id);
        } catch (ErrorValidacion $e) {
            $this->formularioGeneral($id, $this->datosGenerales(), $e->errores());
            return;
        }

        Vista::guardarAviso('Datos guardados.', 'exito');
        $this->redirigir('admin/promociones/' . $id);
    }

    /**
     * Muestra la ficha de la campana, con el resumen y lo que falta.
     *
     * @return void
     */
    public function ficha(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

        $this->vista('admin/campanas/ficha', [
            'titulo'     => $promocion['nombre'],
            'campana'    => $promocion,
            'resumen'    => $this->config->resumen($id),
            'pendientes' => $this->config->pendientesDeActivar($id),
            'avisos'     => $this->config->avisosDeConfiguracion($id),
            'comparacion' => $this->config->compararPlanYCalendario($id),
        ]);
    }

    /**
     * Activa la campana, previa revision de lo que falta.
     *
     * Es un POST y no un enlace porque activar manda correo a la gente y saca
     * premios de la caja. Con un enlace, un clic de mas en el sitio equivocado
     * pondria en marcha una campana a medio preparar.
     *
     * @return void
     */
    public function activar(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');

        try {
            $this->config->activar($id);
        } catch (ErrorValidacion $e) {
            foreach ($e->errores() as $mensaje) {
                Vista::guardarAviso((string) $mensaje, 'error');
            }

            $this->redirigir('admin/promociones/' . $id);
            return;
        }

        Vista::guardarAviso('Campana activada.', 'exito');
        $this->redirigir('admin/promociones/' . $id);
    }

    /**
     * Pinta el formulario de datos generales.
     *
     * @param int|null                  $id      Campana a editar, o null si es
     *                                             nueva.
     * @param array<string, mixed>       $entrada Valores que se han enviado, para
     *                                             no perderlos si hay errores.
     * @param array<string, string>      $errores Errores por campo.
     *
     * @return void
     */
    private function formularioGeneral(?int $id, array $entrada, array $errores = []): void
    {
        $campana = [];

        if ($id !== null) {
            $campana = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);
        }

        $this->vista('admin/campanas/general', [
            'titulo'   => $id === null ? 'Campana nueva' : 'Datos de la campana',
            'id'       => $id,
            'campana'  => $campana,
            'entrada'  => $entrada,
            'errores'  => $errores,
        ]);
    }

    /**
     * Lee del POST los campos del formulario de datos generales.
     *
     * Se separan del resto de metodos porque la usan dos rutas, crear() y
     * actualizar(), que hacen exactamente lo mismo con la entrada.
     *
     * @return array<string, mixed> Datos del formulario.
     */
    private function datosGenerales(): array
    {
        return [
            'nombre'             => (string) $this->recibido('nombre', ''),
            'descripcion'        => (string) $this->recibido('descripcion', ''),
            'comercio_nombre'    => (string) $this->recibido('comercio_nombre', ''),
            'comercio_cif'       => (string) $this->recibido('comercio_cif', ''),
            'comercio_domicilio' => (string) $this->recibido('comercio_domicilio', ''),
            'comercio_telefono'  => (string) $this->recibido('comercio_telefono', ''),
            'zona_horaria'       => (string) $this->recibido('zona_horaria', 'Europe/Madrid'),
            'fecha_inicio'       => (string) $this->recibido('fecha_inicio', ''),
            'fecha_fin'          => (string) $this->recibido('fecha_fin', ''),
        ];
    }
}
