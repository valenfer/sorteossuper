<?php

/**
 * Premios de la campana y formulario de participacion.
 *
 * ============================================================================
 * POR QUE LOS PREMIOS Y EL FORMULARIO ESTAN EN EL MISMO FICHERO
 * ============================================================================
 *
 * No poreconomia de ficheros, sino porque las dos pantallas comparten la misma
 * forma de trabajar: una lista de cosas que se anaden, se editan y se quitan,
 * cada una con su formulario y un boton de guardar. El codigo de las dos es
 * practicamente el mismo, y separarlo obligaria a duplicarlo.
 *
 * Lo que si se mantiene separado es la logica: los premios se guardan uno a uno
 * con TipoPremio::guardar(), y el formulario se guarda entero de golpe con
 * CampoFormulario::sustituirTodos(). Esa diferencia es deliberada y viene del
 * modelo de datos: un premio se puede desactivar sin perder el historial de
 * unidades que ya se han entregado, mientras que los campos del formulario se
 * sustituyen todos porque no tienen historial.
 *
 * ============================================================================
 * POR QUE EL FORMULARIO SE EDITA ENTERO Y NO CAMPO A CAMPO
 * ============================================================================
 *
 * La pantalla enseña los siete campos juntos, con su orden y su obligatoriedad,
 * y hay un solo boton de guardar. Editar un campo suelto obligaria a la
 * administrador a recordar cual estaba editando si se va a otra pantalla a
 * mirar algo, y el orden de los campos importa en un formulario de papel que
 * se imprime.
 *
 * @see \App\Models\TipoPremio
 * @see \App\Models\CampoFormulario
 * @see \App\Services\ConfiguracionPromocion::guardarCampos()
 * @see apartado 4.2 y 4.7 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ErrorValidacion;
use App\Core\Vista;
use App\Models\CampoFormulario;
use App\Models\Promocion;
use App\Models\TipoPremio;
use App\Services\ConfiguracionPromocion;
use App\Services\Imagenes;

/**
 * Premios, formulario de participacion y reglas de las reglas de participacion.
 */
class ControladorFormulario extends Controlador
{
    /**
     * Servicio de configuracion, que valida lo que llega de los formularios.
     *
     * @var \App\Services\ConfiguracionPromocion
     */
    private ConfiguracionPromocion $config;

    /**
     * Servicio de imagenes, para las fotos de los premios.
     *
     * @var \App\Services\Imagenes
     */
    private Imagenes $imagenes;

    /**
     * Prepara los servicios.
     */
    public function __construct()
    {
        $this->config = new ConfiguracionPromocion();
        $this->imagenes = new Imagenes();
    }

    // =========================================================================
    // Premios
    // =========================================================================

    /**
     * Muestra la lista de premios de la campana.
     *
     * @return void
     */
    public function premios(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

        $this->vista('admin/premios/listado', [
            'titulo'  => 'Premios de ' . $promocion['nombre'],
            'campana' => $promocion,
            'premios' => (new TipoPremio())->listarPorPromocion($id),
        ]);
    }

    /**
     * Anade un premio.
     *
     * @return void
     */
    public function crearPremio(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $tipos = new TipoPremio();
        $errores = [];

        $nombre = (string) $this->recibido('nombre', '');

        if ($nombre === '') {
            $errores['nombre'] = 'El premio necesita un nombre.';
        } elseif (mb_strlen($nombre) > 120) {
            $errores['nombre'] = 'El nombre es demasiado largo.';
        }

        $descripcion = (string) $this->recibido('descripcion', '');

        if (mb_strlen($descripcion) > 500) {
            $errores['descripcion'] = 'La descripcion es demasiado larga.';
        }

        // La imagen se sube antes de tocar la base de datos. Si se guardara
        // primero el premio y la subida fallara, quedaria un premio sin foto y
        // sin ninguna forma de saber que se intento subirla.
        $ruta = '';

        if (isset($_FILES['imagen']) && (int) ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $ruta = $this->imagenes->subir($_FILES['imagen'], $id, 'imagen');
            } catch (ErrorValidacion $e) {
                $errores += $e->errores();
            }
        }

        if ($errores !== []) {
            // Si la imagen SI se habia subido pero el premio ha fallado por
            // otra cosa (por ejemplo, un nombre vacio), el fichero se queda en
            // disco sin que ninguna fila de la base de datos lo apunte. Se
            // borra aqui, mientras todavia se sabe que es de este intento y no
            // de una subida buena.
            if ($ruta !== '') {
                $this->imagenes->borrar($ruta);
            }

            $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

            $this->vista('admin/premios/listado', [
                'titulo'    => 'Premios de ' . $promocion['nombre'],
                'campana'   => $promocion,
                'premios'   => $tipos->listarPorPromocion($id),
                'errores'   => $errores,
                'entrada'   => ['nombre' => $nombre, 'descripcion' => $descripcion],
            ]);
            return;
        }

        $tipos->guardar([
            'nombre'      => $nombre,
            'descripcion' => $descripcion,
            'imagen_ruta' => $ruta,
            'activo'      => true,
        ], $id);

        Vista::guardarAviso('Premio anadido.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/premios');
    }

    /**
     * Activa o desactiva un premio sin borrarlo.
     *
     * Un premio que ya ha dado unidades no se borra nunca: se desactiva. Asi el
     * historial de lo entregado sigue teniendo sentido y el calendario no se
     * queda cojo.
     *
     * @return void
     */
    public function alternarPremio(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $premioId = $this->parametroId('premio', 'admin/promociones/' . $id . '/premios');
        $tipos = new TipoPremio();

        $premio = $tipos->exigirPorId($premioId, 'admin/promociones/' . $id . '/premios', $id);
        $tipos->cambiarActivo($premioId, !$premio['activo']);

        Vista::guardarAviso(
            $premio['activo'] ? 'Premio desactivado.' : 'Premio activado.',
            'exito'
        );
        $this->redirigir('admin/promociones/' . $id . '/premios');
    }

    // =========================================================================
    // Formulario de participacion
    // =========================================================================

    /**
     * Muestra el formulario de participacion de la campana.
     *
     * @return void
     */
    public function formulario(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

        $this->vista('admin/formulario/listado', [
            'titulo'  => 'Formulario de ' . $promocion['nombre'],
            'campana' => $promocion,
            'campos'  => $this->conFilaLibre((new CampoFormulario())->listarPorPromocion($id)),
        ]);
    }

    /**
     * Guarda el formulario de participacion entero.
     *
     * @return void
     */
    public function guardarFormulario(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campos = $this->leerCampos();

        try {
            $this->config->guardarCampos($campos, $id);
        } catch (ErrorValidacion $e) {
            $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

            $this->vista('admin/formulario/listado', [
                'titulo'  => 'Formulario de ' . $promocion['nombre'],
                'campana' => $promocion,
                'campos'  => $this->conFilaLibre($campos),
                'errores' => $e->errores(),
            ]);
            return;
        }

        Vista::guardarAviso('Formulario guardado.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/formulario');
    }

    /**
     * Anade al final una fila vacia para poder escribir un campo nuevo.
     *
     * Anadir el campo con un boton y JavaScript, como se suele hacer, dejaria la
     * pantalla inservible en la tablet del mostrador cuando el JavaScript no
     * carga. Con una fila de mas al final el alta se hace escribiendo en ella y
     * pulsando el mismo boton de guardar, y no hay nada que dependa del
     * navegador. La fila vacia no estorba: al leerla se descarta.
     *
     * @param array<int, array<string, mixed>> $campos Campos ya guardados o leidos.
     *
     * @return array<int, array<string, mixed>> Campos con una fila vacia al final.
     */
    private function conFilaLibre(array $campos): array
    {
        $campos[] = [
            'clave'             => '',
            'etiqueta'          => '',
            'tipo'              => 'texto',
            'obligatorio'       => false,
            'visible'           => true,
            'valor_por_defecto' => '',
            'min_largo'         => '0',
            'max_largo'         => '255',
        ];

        return $campos;
    }

    /**
     * Lee del POST la lista de campos del formulario.
     *
     * El formulario se manda como campos con nombre «campo[n]», donde n es la
     * posicion. Se leen por posicion y no por clave porque el indice es lo unico
     * estable cuando un campo se borra de en medio: si las claves fueran los
     * identificadores, al borrar el tercero el cuarto pasaria a ser el tercero y
     * las cantidades que se moverian con el.
     *
     * @return array<int, array<string, mixed>> Campos leidos.
     */
    private function leerCampos(): array
    {
        $crudos = $_POST['campo'] ?? [];
        $campos = [];

        if (!is_array($crudos)) {
            return $campos;
        }

        foreach (array_values($crudos) as $crudo) {
            if (!is_array($crudo)) {
                continue;
            }

            $clave = trim((string) ($crudo['clave'] ?? ''));
            $etiqueta = trim((string) ($crudo['etiqueta'] ?? ''));

            // La fila que se pinta vacia al final de la tabla es para escribir un
            // campo nuevo. Si al guardar sigue vacia, se descarta en vez de
            // guardarse como un campo sin nombre, que luego el formulario de la
            // clienta ensenaria en blanco y la activacion rechazaria.
            if ($clave === '' && $etiqueta === '') {
                continue;
            }

            $campos[] = [
                'clave'             => $clave,
                'etiqueta'          => $etiqueta,
                'tipo'              => (string) ($crudo['tipo'] ?? 'texto'),
                'obligatorio'       => isset($crudo['obligatorio']),
                'visible'           => isset($crudo['visible']),
                'valor_por_defecto' => (string) ($crudo['valor_por_defecto'] ?? ''),
                'min_largo'         => (string) ($crudo['min_largo'] ?? '0'),
                'max_largo'         => (string) ($crudo['max_largo'] ?? '255'),
            ];
        }

        return $campos;
    }
}
