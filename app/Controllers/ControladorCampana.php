<?php

/**
 * Reglas, ajustes, apariencia, tramos y calendario de una campana.
 *
 * ============================================================================
 * POR QUE ESTAS PANTALLAS JUNTAS
 * ============================================================================
 *
 * Son las que se rellenan una vez y no se tocan cada dia. Se agrupan porque
 * comparten una misma forma: formulario, guardar, volver a la ficha. Lo que se
 * toca a menudo —los tramos y el calendario— tambien va aqui, pero en sus
 * propias rutas, porque esas si admiten varias acciones distintas sobre la
 * misma pantalla.
 *
 * ============================================================================
 * LA SEPARACION QUE MAS IMPORTA
 * ============================================================================
 *
 * Ninguna de estas rutas decide nada de negocio. Las comprobaciones estan en
 * \App\Services\ConfiguracionPromocion, en \App\Services\Tramos y en
 * \App\Services\Calendario, y se pueden probar sin pasar por HTTP ni por un
 * navegador. Aqui solo se traduce el POST a los datos que el servicio espera y
 * se elige a donde ir despues.
 *
 * Eso tiene una consecuencia util: un cambio en las reglas de validacion no
 * obliga a tocar ni una sola linea de este fichero, porque no hay reglas aqui.
 *
 * @see \App\Services\ConfiguracionPromocion
 * @see \App\Services\Tramos
 * @see \App\Services\Calendario
 * @see apartado 4.5 a 4.9 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controlador;
use App\Core\ErrorValidacion;
use App\Core\NoEncontrado;
use App\Core\Validador;
use App\Core\Vista;
use App\Models\AsignacionTramo;
use App\Models\CampoFormulario;
use App\Models\ConfiguracionVisual;
use App\Models\Promocion;
use App\Models\ReglaParticipacion;
use App\Models\TipoPremio;
use App\Models\Tramo;
use App\Models\UnidadPremio;
use App\Services\Calendario;
use App\Services\ConfiguracionPromocion;
use App\Services\Imagenes;
use App\Services\Tramos as ServicioTramos;

/**
 * Pantallas de configuracion avanzada y de reparto de premios.
 */
class ControladorCampana extends Controlador
{
    /**
     * Servicio de configuracion de la campana.
     *
     * @var \App\Services\ConfiguracionPromocion
     */
    private ConfiguracionPromocion $config;

    /**
     * Servicio de tramos, con las comprobaciones de solapes y cambio de hora.
     *
     * @var \App\Services\Tramos
     */
    private ServicioTramos $tramos;

    /**
     * Servicio de calendario, que genera y edita las unidades de premio.
     *
     * @var \App\Services\Calendario
     */
    private Calendario $calendario;

    /**
     * Prepara los servicios.
     */
    public function __construct()
    {
        $this->config = new ConfiguracionPromocion();
        $this->tramos = new ServicioTramos();
        $this->calendario = new Calendario();
    }

    // =========================================================================
    // Reglas
    // =========================================================================

    /**
     * Muestra las reglas de participacion.
     *
     * @return void
     */
    public function reglas(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);

        $this->vista('admin/configuracion/reglas', [
            'titulo'  => 'Reglas de ' . $campana['nombre'],
            'campana' => $campana,
            'reglas'  => (new ReglaParticipacion())->leer($id),
            'claves'  => (new CampoFormulario())->claves($id),
            'errores' => [],
        ]);
    }

    /**
     * Guarda las reglas de participacion.
     *
     * @return void
     */
    public function guardarReglas(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);

        $entrada = [
            'una_por_campana'               => $this->recibidoCasilla('una_por_campana'),
            'una_por_dia'                   => $this->recibidoCasilla('una_por_dia'),
            'una_por_ticket'                => $this->recibidoCasilla('una_por_ticket'),
            'una_por_dni'                   => $this->recibidoCasilla('una_por_dni'),
            'exigir_consentimiento'         => $this->recibidoCasilla('exigir_consentimiento'),
            'texto_consentimiento'          => (string) $this->recibido('texto_consentimiento', ''),
            'verificar_ticket'              => $this->recibidoCasilla('verificar_ticket'),
            'exigir_codigo'                 => $this->recibidoCasilla('exigir_codigo'),
            'campo_identidad'               => (string) $this->recibido('campo_identidad', ''),
            'texto_rechazo_horario'         => (string) $this->recibido('texto_rechazo_horario', ''),
            'texto_rechazo_duplicado'       => (string) $this->recibido('texto_rechazo_duplicado', ''),
            'texto_rechazo_codigo'          => (string) $this->recibido('texto_rechazo_codigo', ''),
            'texto_rechazo_consentimiento'  => (string) $this->recibido('texto_rechazo_consentimiento', ''),
        ];

        try {
            $this->config->guardarReglas($entrada, $id);
        } catch (ErrorValidacion $e) {
            $this->vista('admin/configuracion/reglas', [
                'titulo'  => 'Reglas de ' . $campana['nombre'],
                'campana' => $campana,
                // Se mergean las reglas guardadas con lo enviado, para que la
                // pantalla enseñe lo que se ha escrito y no lo que habia antes.
                'reglas'  => array_merge((new ReglaParticipacion())->leer($id), $entrada),
                'claves'  => (new CampoFormulario())->claves($id),
                'errores' => $e->errores(),
            ]);
            return;
        }

        Vista::guardarAviso('Reglas guardadas.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/reglas');
    }

    // =========================================================================
    // Ajustes
    // =========================================================================

    /**
     * Muestra los ajustes de correo, loteria y retencion.
     *
     * @return void
     */
    public function ajustes(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);

        $this->vista('admin/configuracion/ajustes', [
            'titulo'  => 'Ajustes de ' . $campana['nombre'],
            'campana' => $campana,
            'ajustes' => $this->ajustesDe($id),
            'errores' => [],
        ]);
    }

    /**
     * Guarda los ajustes de la campana.
     *
     * @return void
     */
    public function guardarAjustes(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);

        $entrada = [
            'modo_simulacion'          => $this->recibidoCasilla('modo_simulacion'),
            'correo_ganador'           => $this->recibidoCasilla('correo_ganador'),
            'correo_ganador_asunto'    => (string) $this->recibido('correo_ganador_asunto', ''),
            'correo_ganador_cuerpo'    => (string) $this->recibido('correo_ganador_cuerpo', ''),
            'correo_no_ganador'        => $this->recibidoCasilla('correo_no_ganador'),
            'correo_no_ganador_asunto' => (string) $this->recibido('correo_no_ganador_asunto', ''),
            'correo_no_ganador_cuerpo' => (string) $this->recibido('correo_no_ganador_cuerpo', ''),
            'retencion_dias'           => (string) $this->recibido('retencion_dias', ''),
        ];

        try {
            $this->config->guardarAjustes($entrada, $id);
        } catch (ErrorValidacion $e) {
            $this->vista('admin/configuracion/ajustes', [
                'titulo'  => 'Ajustes de ' . $campana['nombre'],
                'campana' => $campana,
                'ajustes' => array_merge($this->ajustesDe($id), $entrada),
                'errores' => $e->errores(),
            ]);
            return;
        }

        Vista::guardarAviso('Ajustes guardados.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/ajustes');
    }

    // =========================================================================
    // Apariencia
    // =========================================================================

    /**
     * Muestra la apariencia de la campana.
     *
     * @return void
     */
    public function apariencia(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);

        $this->vista('admin/configuracion/apariencia', [
            'titulo'  => 'Apariencia de ' . $campana['nombre'],
            'campana' => $campana,
            'visual'  => (new ConfiguracionVisual())->leer($id),
            'errores' => [],
        ]);
    }

    /**
     * Guarda la apariencia y sube las imagenes que acompana.
     *
     * Las imagenes se suben ANTES de escribir en la base de datos. Al reves, un
     * fallo de disco dejaria guardada una ruta que no apunta a ninguna imagen, y
     * la campana se veria con un hueco donde deberia estar el banner.
     *
     * Subir antes tiene una consecuencia, y hay que recoger despues lo que ha
     * quedado sin usar, que son las dos caras de la misma moneda:
     *
     *   - Si el guardado falla, la base de datos no ha cambiado, o sea que las
     *     imagenes de antes siguen siendo las buenas, y las que se acaban de
     *     subir no las usa nadie. Se borran.
     *   - Si el guardado va bien, la nueva ya esta apuntada y la que estaba
     *     antes ya no la apunta nadie. Se borra tambien, que si no la carpeta de
     *     la campana se llenaria de versiones viejas que nadie ve pero que
     *     siguen ocupando disco.
     *
     * El borrado va DESPUES de guardar en los dos casos, nunca antes: si se
     * borrara la imagen vieja antes de apuntar la nueva, un fallo dejaria la
     * campana sin banner, que es justo lo que este orden evita.
     *
     * @return void
     */
    public function guardarApariencia(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $imagenes = new Imagenes();
        $visual = (new ConfiguracionVisual())->leer($id);

        // Rutas nuevas subidas en ESTA peticion, y las que han quedado detras.
        // Las dos vacias si el administrador no ha tocado ninguna imagen.
        $subidas = [];
        $anteriores = [];


        $entrada = [
            'color_primario'          => (string) $this->recibido('color_primario', ''),
            'color_secundario'        => (string) $this->recibido('color_secundario', ''),
            'color_acento'            => (string) $this->recibido('color_acento', ''),
            'color_texto'             => (string) $this->recibido('color_texto', ''),
            'color_fondo'             => (string) $this->recibido('color_fondo', ''),
            'texto_ganador'           => (string) $this->recibido('texto_ganador', ''),
            'texto_no_ganador'        => (string) $this->recibido('texto_no_ganador', ''),
            'resultado_premio_texto'  => (string) $this->recibido('resultado_premio_texto', ''),
            'resultado_no_premio_texto' => (string) $this->recibido('resultado_no_premio_texto', ''),
        ];

        foreach (['banner_sup', 'banner_pie'] as $campo) {
            $entrada[$campo . '_ruta'] = (string) ($visual[$campo . '_ruta'] ?? '');
            $entrada[$campo . '_alt'] = (string) ($visual[$campo . '_alt'] ?? '');

            if (isset($_FILES[$campo]) && (int) ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $nueva = $imagenes->subir($_FILES[$campo], $id, $campo);
                    $anteriores[$campo] = $entrada[$campo . '_ruta'];
                    $subidas[$campo] = $nueva;
                    $entrada[$campo . '_ruta'] = $nueva;
                    $entrada[$campo . '_alt'] = (string) $this->recibido($campo . '_alt', '');
                } catch (ErrorValidacion $e) {
                    foreach ($e->errores() as $mensaje) {
                        Vista::guardarAviso((string) $mensaje, 'error');
                    }
                }
            }
        }

        foreach (['resultado_premio', 'resultado_no_premio'] as $campo) {
            $entrada[$campo . '_ruta'] = (string) ($visual[$campo . '_ruta'] ?? '');

            if (isset($_FILES[$campo]) && (int) ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $nueva = $imagenes->subir($_FILES[$campo], $id, $campo);
                    $anteriores[$campo] = $entrada[$campo . '_ruta'];
                    $subidas[$campo] = $nueva;
                    $entrada[$campo . '_ruta'] = $nueva;
                } catch (ErrorValidacion $e) {
                    foreach ($e->errores() as $mensaje) {
                        Vista::guardarAviso((string) $mensaje, 'error');
                    }
                }
            }
        }

        try {
            $this->config->guardarApariencia($entrada, $id);
        } catch (ErrorValidacion $e) {
            // El guardado no ha pasado, asi que en la base de datos siguen
            // mandando las imagenes de antes. Las de ahora no las usa nadie.
            foreach ($subidas as $ruta) {
                $imagenes->borrar($ruta);
            }

            $this->vista('admin/configuracion/apariencia', [
                'titulo'  => 'Apariencia de ' . $campana['nombre'],
                'campana' => $campana,
                'visual'  => array_merge($visual, $entrada),
                'errores' => $e->errores(),
            ]);
            return;
        }

        // El guardado ya ha pasado: las nuevas son las que mandan y las
        // anteriores ya no las apunta nadie.
        foreach ($subidas as $campo => $ruta) {
            $anterior = (string) ($anteriores[$campo] ?? '');

            if ($anterior !== '' && $anterior !== $ruta) {
                $imagenes->borrar($anterior);
            }
        }

        Vista::guardarAviso('Apariencia guardada.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/apariencia');
    }

    // =========================================================================
    // Tramos y cantidades
    // =========================================================================

    /**
     * Muestra los tramos y la cantidad que le toca a cada premio en cada uno.
     *
     * Las dos cosas estan en la misma pantalla porque son la misma decision:
     * «este dia, de tal hora a tal hora, se reparten estos premios». Separarlas
     * obligaba a saltar entre dos pantallas para escribir un numero.
     *
     * @return void
     */
    public function tramos(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $tramos = new Tramo();

        $filas = [];

        foreach ($tramos->listarPorPromocion($id) as $tramo) {
            $filas[] = [
                'tramo'      => $tramo,
                'etiqueta'   => Tramo::etiqueta($tramo),
                'cantidades' => (new AsignacionTramo())->cantidadesPorTramo((int) $tramo['id']),
            ];
        }

        $this->vista('admin/calendario/tramos', [
            'titulo'      => 'Tramos de ' . $campana['nombre'],
            'campana'     => $campana,
            'filas'       => $filas,
            'premios'     => (new TipoPremio())->listarPorPromocion($id),
            'cambiosHora' => $this->cambiosDeHora($campana),
            'errores'     => [],
        ]);
    }


    /**
     * Anade un tramo.
     *
     * @return void
     */
    public function crearTramo(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $recibido = $this->leerTramo();
        $validador = new Validador();

        // El servicio normaliza y anota los errores en el validador, que es lo
        // que permite devolverlos todos juntos en vez de parar en el primero.
        $datos = $this->tramos->validarCampos(
            $validador,
            'tramo',
            $recibido['fecha'],
            $recibido['hora_inicio'],
            $recibido['hora_fin']
        );

        if ($datos !== null) {
            $this->tramos->comprobarSolapes(
                $validador,
                $id,
                'tramo',
                $datos['fecha'],
                $datos['hora_inicio'],
                $datos['hora_fin'],
                null
            );

            $this->tramos->comprobarCambioDeHora(
                $validador,
                'tramo',
                $datos['fecha'],
                $datos['hora_inicio'],
                $datos['hora_fin']
            );
        }

        // Se comprueba antes de guardar, y se vuelve a pintar la pantalla con
        // lo escrito. Perder la tabla de tramos porque faltaba un dos en una
        // hora seria la forma rapida de que nadie usara esta pantalla.
        if ($validador->tieneErrores()) {
            $this->pintarTramos($id, $campana, $validador->errores(), $recibido);
            return;
        }

        (new Tramo())->guardar((array) $datos, $id);

        Vista::guardarAviso('Tramo anadido.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/tramos');
    }

    /**
     * Guarda las cantidades de premios de un tramo.
     *
     * @return void
     */
    public function guardarCantidades(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $tramoId = $this->parametroId('tramo', 'admin/promociones/' . $id . '/tramos');
        $tramos = new Tramo();
        $tramos->exigirPorId($tramoId, 'admin/promociones/' . $id . '/tramos', $id);

        $cantidades = [];
        $premios = [];

        foreach ((new TipoPremio())->listarPorPromocion($id) as $premio) {
            $premios[(int) $premio['id']] = (string) $premio['nombre'];
        }

        $crudos = $_POST['cantidad'] ?? [];

        if (is_array($crudos)) {
            foreach ($crudos as $premioId => $valor) {
                $valor = trim((string) $valor);

                if ($valor === '') {
                    continue;
                }

                if (!isset($premios[(int) $premioId])) {
                    continue;
                }

                $cantidades[(int) $premioId] = max(0, (int) $valor);
            }
        }

        // No se comprueba que el tramo tenga premios, y no por descuido: se
        // admite un tramo vacio a proposito. Puede que se haya creado el tramo
        // antes de decidir que se reparte, y borrarlo dejaria sin efecto el
        // orden en que se han escrito los demas. El aviso sale al activar la
        // campana, que es cuando de verdad hace falta saberlo.
        (new AsignacionTramo())->sustituirPorTramo($tramoId, $cantidades);
        (new Promocion())->tocar($id);

        Vista::guardarAviso('Cantidades guardadas.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/tramos');
    }

    /**
     * Borra un tramo que todavia no tiene unidades de premio.
     *
     * @return void
     */
    public function borrarTramo(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $this->exigirCampana($id);
        $tramoId = $this->parametroId('tramo', 'admin/promociones/' . $id . '/tramos');
        $tramos = new Tramo();
        $tramos->exigirPorId($tramoId, 'admin/promociones/' . $id . '/tramos', $id);

        $borrados = $tramos->borrarSiEstaLibre($tramoId);

        if ($borrados > 0) {
            Vista::guardarAviso('Tramo borrado.', 'exito');
        } else {
            Vista::guardarAviso(
                'No se ha borrado: el tramo ya tiene unidades de premio. Retira esas unidades primero.',
                'error'
            );
        }

        $this->redirigir('admin/promociones/' . $id . '/tramos');
    }

    // =========================================================================
    // Calendario
    // =========================================================================

    /**
* Muestra el calendario, el plan y la diferencia entre los dos.
     *
     * @return void
     */
    public function calendario(): void
    {
        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $filtros = $this->filtros();

        $this->pintarCalendario($id, $campana, $filtros, [], []);
    }

    /**
     * Anade una unidad suelta al calendario.
     *
     * Es la primera de las tres revisiones que pide el apartado 4.6. El tramo se
     * elige de una lista y la fecha la pone el propio tramo, no se escribe aparte:
     * pedir una fecha y un tramo por separado deja abierta la combinacion imposible
     * de un tramo del martes con la fecha del jueves, que el servicio rechazaria
     * con un mensaje que la administradora no podria ver en el formulario.
     *
     * @return void
     */
    public function crearUnidad(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $tramoId = (int) $this->recibido('tramo_id', '0');
        $premioId = (int) $this->recibido('premio_id', '0');
        $hora = trim((string) $this->recibido('hora', ''));

        // El tramo se exige aqui y no solo en el servicio porque un tramo que no
        // es de esta campana no es un dato que corregir: es una peticion que no
        // tiene sentido, y merece un 404 y no un error de validacion. El premio,
        // en cambio, si puede ser un dato equivocado —un desplegable que se ha
        // quedado desfasado— y por eso se responde con ErrorValidacion, que
        // devuelve a la pantalla con el formulario lleno.
        $tramo = $this->exigirTramoDeLaCampana($tramoId, $id);

        try {
            $this->calendario->crear(
                $id,
                $tramoId,
                $premioId,
                (string) $tramo['fecha'],
                $hora
            );
        } catch (ErrorValidacion $e) {
            $this->pintarCalendario(
                $id,
                $campana,
                $this->filtros(),
                $e->errores(),
                ['tramo_id' => $tramoId, 'premio_id' => $premioId, 'hora' => $hora]
            );
            return;
        }

        Vista::guardarAviso('Unidad anadida al calendario.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/calendario');
    }

    /**
     * Mueve una unidad programada a otro tramo u otra hora.
     *
     * Mover no es retirar ni borrar: cambia la hora de una fila que aun no ha
     * pasado nada, y por eso va en su propia ruta y con su propio boton.
     *
     * @return void
     */
    public function moverUnidad(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $campana = $this->exigirCampana($id);
        $unidadId = $this->parametroId('unidad', 'admin/promociones/' . $id . '/calendario');
        $tramoId = (int) $this->recibido('tramo_id', '0');
        $hora = trim((string) $this->recibido('hora', ''));

        // El tramo de destino se exige aqui y no en el servicio por una razon
        // concreta: si no existe, la pantalla se tiene que repintar con un error
        // intelligible, y el servicio solo sabe lanzar ErrorValidacion de reglas
        // de negocio, no de enlaces rotos.
        $tramo = $this->exigirTramoDeLaCampana($tramoId, $id);

        try {
            $this->calendario->mover($unidadId, $tramoId, (string) $tramo['fecha'], $hora, $id);
        } catch (ErrorValidacion $e) {
            $this->pintarCalendario(
                $id,
                $campana,
                $this->filtros(),
                $e->errores(),
                ['unidad_id' => $unidadId, 'tramo_id' => $tramoId, 'hora' => $hora]
            );
            return;
        }

        Vista::guardarAviso('Unidad movida.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/calendario');
    }

    /**
     * Genera el calendario a partir del plan.
     *
     * @return void
     */
    public function generarCalendario(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $this->exigirCampana($id);
        $reemplazar = $this->recibidoCasilla('reemplazar');
        $permitirRepetir = $this->recibidoCasilla('permitir_repetir');

        try {
            $resultado = $this->calendario->generar($id, $reemplazar, $permitirRepetir);
        } catch (ErrorValidacion $e) {
            foreach ($e->errores() as $mensaje) {
                Vista::guardarAviso((string) $mensaje, 'error');
            }

            $this->redirigir('admin/promociones/' . $id . '/calendario');
            return;
        }

        Vista::guardarAviso(sprintf(
            'Calendario generado: %d unidades nuevas.',
            (int) ($resultado['creadas'] ?? 0)
        ), 'exito');

        $this->redirigir('admin/promociones/' . $id . '/calendario');
    }

    /**
     * Retira una unidad de premio del calendario.
     *
     * No se borra: se anula, dejando el motivo. Un premio ya retirado forma
     * parte del historial de la campana y borrarlo haria imposible saber que
     * paso con el.
     *
     * @return void
     */
    public function retirarUnidad(): void
    {
        $this->exigirCsrf();

        $id = $this->parametroId('id', 'admin/promociones');
        $this->exigirCampana($id);
        $unidadId = $this->parametroId('unidad', 'admin/promociones/' . $id . '/calendario');
$motivo = (string) $this->recibido('motivo', '');

        try {
            $this->calendario->retirar($unidadId, $id, $motivo);
        } catch (ErrorValidacion $e) {
            foreach ($e->errores() as $mensaje) {
                Vista::guardarAviso((string) $mensaje, 'error');
            }

            // Sin este return se guardaba tambien el «Unidad retirada» de abajo
            // encima de los errores, y el administrador leia que habia funcionado
            // justo despues de un aviso que dice lo contrario.
            $this->redirigir('admin/promociones/' . $id . '/calendario');
            return;
        }

        Vista::guardarAviso('Unidad retirada.', 'exito');
        $this->redirigir('admin/promociones/' . $id . '/calendario');
    }

    // =========================================================================
    // Ayudas internas
    // =========================================================================

    /**
     * Carga una campana o responde con un 404 si no existe.
     *
     * Se delega en exigirPorId() en lugar de repetir la comprobacion en cada
     * ruta, porque una ruta que se olvide de ella abriria la pantalla de
     * configuracion de la campana 999 a quien solo tiene permiso sobre la 7.
     *
     * @param int $id Identificador de la campana.
     *
     * @return array<string, mixed> Fila de la campana.
     */
    private function exigirCampana(int $id): array
    {
        return (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);
    }

/**
     * Carga un tramo o responde con un 404 si no es de esta campana.
     *
     * El caso de uso es anadir y mover unidades: las dos necesitan la fecha del
     * tramo de destino y no la piden por escrito, precisamente para que no se
     * pueda escribir un tramo del martes con la fecha del jueves.
     *
     * @param int $tramoId Tramo que se quiere.
     * @param int $id      Campana a la que tiene que pertenecer.
     *
     * @return array<string, mixed> Fila del tramo.
     */
    private function exigirTramoDeLaCampana(int $tramoId, int $id): array
    {
        $tramo = (new Tramo())->buscarPorId($tramoId);

        if ($tramo === null || (int) $tramo['promocion_id'] !== $id) {
            throw new NoEncontrado('Ese tramo no existe en esta campana.');
        }

        return $tramo;
    }

    /**
     * Vuelve a pintar la pantalla del calendario con los errores.
     *
     * El error se pinta junto al formulario en vez de en un aviso de una vez,
     * porque «la hora tiene que estar entre las 10:00 y las 14:00» no dice nada
     * util si desaparece al cambiar de pantalla, y el tramo elegido hay que
     * recordarselo a quien esta corrigiendo.
     *
     * @param int                   $id      Campana.
     * @param array<string, mixed>  $campana Fila de la campana.
     * @param array<string, mixed>  $filtros Filtros que venian aplicados.
     * @param array<string, string> $errores Errores por campo.
     * @param array<string, mixed>  $entrada Valores enviados, para no perderlos.
     *
     * @return void
     */
    private function pintarCalendario(
        int $id,
        array $campana,
        array $filtros,
        array $errores,
        array $entrada
    ): void {
        $this->vista('admin/calendario/listado', [
            'titulo'      => 'Calendario de ' . $campana['nombre'],
            'campana'     => $campana,
            'unidades'    => $this->calendario->listar($id, $filtros, 500),
            'total'       => $this->calendario->contar($id, $filtros),
            'filtros'     => $filtros,
            'tramos'      => (new Tramo())->listarPorPromocion($id),
            'premios'     => (new TipoPremio())->listarPorPromocion($id, true),
            'diagnostico' => $this->calendario->diagnosticar($id),
            'comparacion' => $this->config->compararPlanYCalendario($id),
            'estados'     => UnidadPremio::estados(),
            'errores'     => $errores,
            'entrada'     => $entrada,
        ]);
    }

    /**
     * Lee del POST los campos de un tramo.
     *
     * @return array<string, mixed> Fecha y horas, sin validar.
     */
    private function leerTramo(): array
    {
        return [
            'fecha'       => (string) $this->recibido('fecha', ''),
            'hora_inicio' => (string) $this->recibido('hora_inicio', ''),
            'hora_fin'    => (string) $this->recibido('hora_fin', ''),
        ];
    }

    /**
     * Vuelve a pintar la pantalla de tramos con los errores.
     *
     * @param int                    $id      Campana.
     * @param array<string, mixed>   $campana Fila de la campana.
     * @param array<string, string>  $errores Errores por campo.
     * @param array<string, mixed>   $datos   Valores enviados.
     *
     * @return void
     */
    private function pintarTramos(int $id, array $campana, array $errores, array $datos): void
    {
        $tramos = new Tramo();
        $filas = [];

        foreach ($tramos->listarPorPromocion($id) as $tramo) {
            $filas[] = [
                'tramo'      => $tramo,
                'etiqueta'   => Tramo::etiqueta($tramo),
                'cantidades' => (new AsignacionTramo())->cantidadesPorTramo((int) $tramo['id']),
            ];
        }

        $this->vista('admin/calendario/tramos', [
            'titulo'      => 'Tramos de ' . $campana['nombre'],
            'campana'     => $campana,
            'filas'       => $filas,
            'premios'     => (new TipoPremio())->listarPorPromocion($id),
            'cambiosHora' => $this->cambiosDeHora($campana),
            'errores'     => $errores,
            'entrada'     => $datos,
        ]);
    }

    /**
     * Dias del periodo de la campana en los que cambia la hora.
     *
     * La pantalla de tramos necesita avisar de esto, pero no puede limitarse a
     * mirar la fecha de inicio de la campana: un tramo se puede anadir para
     * cualquier dia del periodo, y el salto de hora cae en un dia concreto. Por
     * eso se recorre el periodo entero y se devuelven todos los dias con cambio,
     * para que la pantalla pueda marcar cuales son y avisar en el momento de la
     * fecha que se esta escribiendo.
     *
     * El recorrido tiene un tope de un ano. Una campana puede no tener fecha de
     * fin, y recorrerla dia a dia hasta que termine no acaba nunca; con el tope
     * se enseña lo del primer ano, que es donde estan todos los saltos de hora
     * que puede tener una campana, porque en un ano hay como maximo dos. Si se
     * llega al tope, el ultimo elemento lleva la marca de que la lista esta
     * recortada, y la pantalla lo dice.
     *
     * @param array<string, mixed> $campana Fila de la campana.
     *
     * @return array<int, array<string, mixed>> Dias con cambio de hora.
     */
    private function cambiosDeHora(array $campana): array
    {
        $inicio = (string) ($campana['fecha_inicio'] ?? '');

        if ($inicio === '' || strtotime($inicio) === false) {
            return [];
        }

        $fin = (string) ($campana['fecha_fin'] ?? '');
        $tope = strtotime($inicio . ' +1 year');
        $cambios = [];
        $dia = strtotime($inicio);
        $recortado = false;

        while ($dia !== false) {
            if ($tope !== false && $dia > $tope) {
                $recortado = true;
                break;
            }

            if ($fin !== '' && strtotime($fin) !== false && $dia > strtotime($fin)) {
                break;
            }

            $fecha = date('Y-m-d', $dia);
            $cambio = $this->tramos->cambioDeHora($fecha);

            if ($cambio !== null) {
                $cambios[] = $cambio + ['fecha' => $fecha];
            }

            $dia = strtotime('+1 day', (int) $dia);
        }

        if ($recortado && $cambios !== []) {
            $cambios[count($cambios) - 1]['recortado'] = true;
        }

        return $cambios;
    }


    /**
     * Lee los ajustes de una campana para la pantalla.
     *
     * @param int $id Identificador de la campana.
     *
     * @return array<string, mixed> Ajustes, con los valores por defecto si faltan.
     */
    private function ajustesDe(int $id): array
    {
        $campana = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

        // Los ajustes no viven en una tabla propia: son columnas de la propia
        // campana. Se leen de la fila que exigePorId() ya trae, de modo que la
        // pantalla no hace una segunda consulta.
        $claves = [
            'modo_simulacion', 'loteria_persiste', 'correo_ganador', 'correo_no_ganador',
            'correo_ganador_asunto', 'correo_ganador_cuerpo', 'correo_no_ganador_asunto',
            'correo_no_ganador_cuerpo', 'retencion_dias',
        ];
        $ajustes = [];

        foreach ($claves as $clave) {
            $ajustes[$clave] = $campana[$clave] ?? null;
        }

        return $ajustes;
    }

    /**
     * Lee los filtros del calendario de la URL.
     *
     * @return array<string, mixed> Filtros limpios.
     */
    private function filtros(): array
    {
        $filtros = [];

        $tramoId = (int) $this->entrada('tramo', 0);

        if ($tramoId > 0) {
            $filtros['tramo_id'] = $tramoId;
        }

        $premioId = (int) $this->entrada('premio', 0);

        if ($premioId > 0) {
            $filtros['tipo_premio_id'] = $premioId;
        }

        $estado = (string) $this->entrada('estado', '');

        if ($estado !== '' && array_key_exists($estado, UnidadPremio::estados())) {
            $filtros['estado'] = $estado;
        }

        return $filtros;
    }
}
