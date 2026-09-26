<?php

/**
 * Servicio de configuracion de una campana.
 *
 * ============================================================================
 * QUE ES ESTE SERVICIO
 * ============================================================================
 *
 * El apartado 4.1 de la especificacion pide que el administrador pueda revisar
 * la campana antes de activarla. Este fichero es el que sabe decir si puede, y
 * por que no. No es un formality: una campana activa sin tramos se queda
 * abierta sin que nadie pueda participar, y una campana activa sin formulario
 * con correo se queda abierta sin poder avisar a nadie de que ha ganado.
 *
 * Ambos errores son irreversibles en la practica. Un cliente que entra, rellena
 * el formulario y no ve respuesta cree que el sorteo esta roto, y no hay forma
 * de arreglarlo: la participacion ya se registro.
 *
 * ============================================================================
 * POR QUE LA LISTA DE REQUISITOS ESTA EN CODIGO Y NO EN LA BASE DE DATOS
 * ============================================================================
 *
 * porque es un contrato del negocio, no un dato que cambie de campana a campana.
 * Meterlo en una tabla haria que un cambio de criterio se aplicase a las
 * campanas ya revisadas, y que dos campanas activadas en dias distintos
 * cumplieran listas distintas sin que nadie lo hubiera decidido. Aqui el cambio
 * de criterio es un cambio de codigo, que se revisa, y afecta a todo el mundo
 * por igual.
 *
 * ============================================================================
 * POR QUE ACTIVAR ES UNA OPERACION DISTINTA DE CAMBIAR EL ESTADO
 * ============================================================================
 *
 * El estado de la campana se cambia desde tres sitios —la ficha, el listado y el
 * cierre automatico al pasar la fecha— y dos de ellos no deben pasar por la lista
 * de requisitos. El listado permite dar de alta una campana para trabajar en
 * ella, y el cierre tiene que poder actuar sobre una campana que se quedo a medio
 * configurar. Por eso el modelo expone cambiarEstado() sin comprobaciones y este
 * servicio expone activar(), que es la unica via que aplica la lista.
 *
 * @see \App\Models\Promocion::cambiarEstado()
 * @see \App\Core\Validador
 * @see apartado 4.1 de la especificacion, datos generales
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Core\ErrorValidacion;
use App\Core\Validador;
use App\Models\AsignacionTramo;
use App\Models\CampoFormulario;
use App\Models\CodigoValido;
use App\Models\ConfiguracionVisual;
use App\Models\Promocion;
use App\Models\ReglaParticipacion;
use App\Models\TipoPremio;
use App\Models\Tramo;
use App\Models\UnidadPremio;

/**
 * Revision, guardado y activacion de la configuracion de una campana.
 */
class ConfiguracionPromocion
{
    /**
     * Campos que el apartado 4.8 oblige a ofrecer, ademas de los configurables.
     *
     * Se comprueba que existan como clave, no que esten visibles: un campo
     * invisible no impide participar, pero un campo que no existe del todo
     * significa que la campana recogera algo que el administrador no ha
     * reviewed, que es justo lo que se quiere evitar.
     *
     * @var array<int, string>
     */
    public const CAMPOS_OBLIGATORIOS = [
        'nombre',
        'dni',
        'telefono',
        'direccion',
        'codigo_postal',
        'num_ticket',
        'codigo_participacion',
        'email',
    ];

    /**
     * Tipos admitidos para un campo del formulario.
     *
     * Coinciden con el ENUM de la columna tipo, y se repiten aqui para que un
     * tipo desconocido se pare en la pantalla con un mensaje en vez de llegar al
     * motor y volver como un error de base de datos que no explica nada.
     *
     * @var array<int, string>
     */
    public const TIPOS_CAMPO = ['texto', 'email', 'telefono', 'entero', 'fecha', 'area'];

    /**
     * Colores que tienen que ser un hexadecimal de seis digitos.
     *
     * Un color mal escrito no da error: se ignora y la pagina sale sin fondo, que
     * es un fallo que se descubre en la pantalla de la campana y no en la del
     * panel. Se comprueba al guardar, que es donde se puede avisar.
     *
     * @var array<int, string>
     */
    public const COLORES = [
        'color_fondo',
        'color_texto',
        'color_primario',
        'color_acento',
        'color_campos',
        'color_bordes',
    ];

    /**
     * Modelo de las campanas.
     *
     * @var Promocion
     */
    private Promocion $promociones;

    /**
     * Modelo de los tramos.
     *
     * @var Tramo
     */
    private Tramo $tramos;

    /**
     * Modelo de los tipos de premio.
     *
     * @var TipoPremio
     */
    private TipoPremio $tipos;

    /**
     * Modelo del plan por tramo.
     *
     * @var AsignacionTramo
     */
    private AsignacionTramo $asignaciones;

    /**
     * Modelo de los campos del formulario.
     *
     * @var CampoFormulario
     */
    private CampoFormulario $campos;

    /**
     * Modelo de las reglas de participacion.
     *
     * @var ReglaParticipacion
     */
    private ReglaParticipacion $reglas;

    /**
     * Modelo de la apariencia.
     *
     * @var ConfiguracionVisual
     */
    private ConfiguracionVisual $visual;

    /**
     * Modelo de las listas de tickets y codigos.
     *
     * @var CodigoValido
     */
    private CodigoValido $codigos;

    /**
     * Crea el servicio con los modelos que va a necesitar.
     */
    public function __construct()
    {
        $this->promociones = new Promocion();
        $this->tramos = new Tramo();
        $this->tipos = new TipoPremio();
        $this->asignaciones = new AsignacionTramo();
        $this->campos = new CampoFormulario();
        $this->reglas = new ReglaParticipacion();
        $this->visual = new ConfiguracionVisual();
        $this->codigos = new CodigoValido();
    }

    /**
     * Revisa si una campana puede pasar a activa, y dice que le falta si no puede.
     *
     * No lanza excepciones: devuelve la lista de pendientes. La pantalla de
     * revision la pinta, y activar() es la que la convierte en un error. Esa
     * separacion es la que permite abrir la revision sin miedo: aunque falte
     * todo, la pantalla tiene que abrir y decir «no faltan mas que cinco cosas».
     *
     * @param int $promocionId Campana que se quiere revisar.
     *
     * @return array<int, string> Pendientes, vacia si la campana puede activarse.
     */
    public function pendientesDeActivar(int $promocionId): array
    {
        $promocion = $this->promociones->exigirPorId($promocionId, 'admin/promociones/' . $promocionId);
        $pendientes = [];

        if ((string) $promocion['nombre'] === '') {
            $pendientes[] = 'La campana no tiene nombre.';
        }

        if ((string) $promocion['zona_horaria'] === '') {
            $pendientes[] = 'La campana no tiene zona horaria, y sin ella los tramos no significan nada.';
        }

        if ((string) ($promocion['fecha_inicio'] ?? '') === '') {
            $pendientes[] = 'La campana no tiene fecha de inicio.';
        }

        $fechas = $this->tramos->fechasDe($promocionId);

        if ($fechas === []) {
            $pendientes[] = 'La campana no tiene ningun tramo, y sin tramos no hay donde repartir premios.';
        }

        // Solo importan los tramos dentro del periodo. Un tramo en una fecha que
        // se sale de la campana no es un error de la campana, es un error del
        // tramo, y el mensaje tiene que senalar el tramo y no la campana entera.
        $fuera = $this->tramosFueraDelPeriodo($promocionId, $fechas);

        foreach ($fuera as $tramo) {
            $pendientes[] = sprintf(
                'El tramo del %s a las %s esta fuera del periodo de la campana.',
                $this->fechaLegible((string) $tramo['fecha']),
                substr((string) $tramo['hora_inicio'], 0, 5)
            );
        }

        $tipos = $this->tipos->listarPorPromocion($promocionId, true);

        if ($tipos === []) {
            $pendientes[] = 'La campana no tiene ningun premio activo.';
        }

        if ($this->asignaciones->totalPlan($promocionId) < 1) {
            $pendientes[] = 'No hay cantidades asignadas a ningun tramo, asi que no se puede generar el calendario.';
        }

        // Que haya cantidades NO es lo mismo que haya calendario. El plan son
        // numeros en una tabla; el calendario son las unidades con su minuto, que
        // es lo unico que el motor de sorteo puede entregar. Una campana con
        // todo el plan escrito y el calendario sin generar se podria activar sin
        // esta comprobacion, y al abrirla nadie podria ganar nada sin que nadie
        // hubiera hecho nada mal.
        if ((new UnidadPremio())->contarEntregables($promocionId) < 1) {
            $pendientes[] = 'El calendario no esta generado. Hay que generar el reparto antes de abrir la campana.';
        }

        $tiposSinCantidad = [];
        // totalesPorTipo() devuelve una lista de filas ordenadas de mayor a
        // menor, no un mapa. Se reindexa aqui porque lo que se necesita es
        // «este tipo, cuanto tiene», y con el mapa esa pregunta es un acceso
        // directo en vez de un recorrido por todos los premios de la campana.
        $totales = [];

        foreach ($this->asignaciones->totalesPorTipo($promocionId) as $fila) {
            $totales[(int) $fila['tipo_premio_id']] = (int) $fila['total'];
        }

        foreach ($tipos as $tipo) {
            if (($totales[(int) $tipo['id']] ?? 0) < 1) {
                $tiposSinCantidad[] = (string) $tipo['nombre'];
            }
        }

        foreach ($tiposSinCantidad as $nombre) {
            $pendientes[] = sprintf('El premio «%s» esta activo pero no tiene cantidades asignadas.', $nombre);
        }

        $claves = $this->campos->claves($promocionId);

        foreach (self::CAMPOS_OBLIGATORIOS as $clave) {
            if (!in_array($clave, $claves, true)) {
                $pendientes[] = sprintf('El formulario no tiene el campo «%s».', $clave);
            }
        }

        $visibles = $this->campos->listarPorPromocion($promocionId, true);

        if ($visibles === []) {
            $pendientes[] = 'El formulario no tiene ningun campo visible.';
        }

        if (!$this->tieneCampoDeContacto($visibles)) {
            $pendientes[] = 'El formulario no pide ningun dato de contacto, y sin ellos no se puede avisar a nadie.';
        }

        $reglas = $this->reglas->leer($promocionId);

        if ($this->necesitaListaDeTickets($reglas) && $this->codigos->contar($promocionId, CodigoValido::TIPO_TICKET)['total'] < 1) {
            $pendientes[] = 'Se pide verificar el ticket pero no hay ningun ticket cargado en la lista.';
        }

        if ($this->necesitaListaDeCodigos($reglas) && $this->codigos->contar($promocionId, CodigoValido::TIPO_CODIGO)['total'] < 1) {
            $pendientes[] = 'Se pide un codigo de participacion pero no hay ningun codigo cargado en la lista.';
        }

        // Los avisos de las reglas NO se mezclan aqui a proposito. Dicen cosas
        // que hay que ver al configurar —«tu ticket no se verifica contra la
        // caja»— pero no impiden activar: el apartado 4.7 pide que la
        // declaracion sea visible, no que se impida hacer el sorteo. Si se
        // mezclaran, ninguna campana con control de duplicados podria activarse
        // nunca, que es el modo que recomienda D3 cuando no hay integracion con
        // la caja. Se devuelven aparte, en resumen()['avisos'].
        return $pendientes;
    }

    /**
     * Devuelve los avisos de configuracion que no impiden activar la campana.
     *
     * @param int $promocionId Campana que se revisa.
     *
     * @return array<int, string> Avisos, vacia si no hay ninguno.
     */
    public function avisosDeConfiguracion(int $promocionId): array
    {
        $this->promociones->exigirPorId($promocionId, 'admin/promociones/' . $promocionId);

        return $this->avisosDeReglas(
            $this->reglas->leer($promocionId),
            $this->campos->claves($promocionId)
        );
    }

    /**
     * Indica si una campana esta lista para activarse.
     *
     * @param int $promocionId Campana que se quiere revisar.
     *
     * @return bool True si no falta nada.
     */
    public function puedeActivar(int $promocionId): bool
    {
        return $this->pendientesDeActivar($promocionId) === [];
    }

    /**
     * Activa una campana, y se niega a hacerlo si le falta algo.
     *
     * @param int $promocionId Campana que se quiere activar.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si a la campana le falta algo, con el
     *                                   detalle indexado por el requisito que
     *                                   no se cumple.
     */
    public function activar(int $promocionId): void
    {
        $pendientes = $this->pendientesDeActivar($promocionId);

        if ($pendientes === []) {
            $this->promociones->cambiarEstado($promocionId, Promocion::ESTADO_ACTIVA);

            return;
        }

        $errores = [];

        foreach ($pendientes as $indice => $pendiente) {
            $errores['pendiente_' . $indice] = $pendiente;
        }

        throw new ErrorValidacion(
            'La campana no se puede activar todavia: le faltan ' . count($pendientes) . ' cosa'
                . (count($pendientes) === 1 ? '' : 's') . '.',
            $errores
        );
    }

    /**
     * Guarda los datos generales de una campana.
     *
     * @param array<string, mixed> $entrada Datos tal y como llegan del formulario.
     * @param int|null             $id      Campana a editar, o null si es nueva.
     *
     * @return int Identificador de la campana guardada.
     *
     * @throws \App\Core\ErrorValidacion Si algun campo no cumple las reglas.
     */
    public function guardar(array $entrada, ?int $id = null): int
    {
        $v = new Validador();

        $nombre = $v->textoObligatorio('nombre', $entrada['nombre'] ?? '', 120);
        $descripcion = trim((string) ($entrada['descripcion'] ?? ''));

        if (mb_strlen($descripcion) > 2000) {
            $v->anadirError('descripcion', 'La descripcion es demasiado larga.');
        }

        $comercioNombre = trim((string) ($entrada['comercio_nombre'] ?? ''));

        if (mb_strlen($comercioNombre) > 150) {
            $v->anadirError('comercio_nombre', 'El nombre del comercio es demasiado largo.');
        }

        $cif = $this->validarCif($entrada['comercio_cif'] ?? '');
        $telefono = trim((string) ($entrada['comercio_telefono'] ?? ''));

        if ($telefono !== '' && preg_match('/^[0-9 +.\-()]{6,30}$/', $telefono) !== 1) {
            $v->anadirError('comercio_telefono', 'El telefono del comercio no tiene un formato valido.');
        }

        $domicilio = trim((string) ($entrada['comercio_domicilio'] ?? ''));

        if (mb_strlen($domicilio) > 200) {
            $v->anadirError('comercio_domicilio', 'La direccion del comercio es demasiado larga.');
        }

        $zona = trim((string) ($entrada['zona_horaria'] ?? ''));

        if ($zona === '') {
            $zona = 'Europe/Madrid';
        } elseif (!in_array($zona, timezone_identifiers_list(), true)) {
            $v->anadirError('zona_horaria', 'La zona horaria no es valida.');
        }

        $inicio = $this->validarFecha($v, 'fecha_inicio', $entrada['fecha_inicio'] ?? '');
        $fin = $this->validarFecha($v, 'fecha_fin', $entrada['fecha_fin'] ?? '');

        if ($inicio !== null && $fin !== null && $fin < $inicio) {
            $v->anadirError('fecha_fin', 'La fecha de fin no puede ser anterior a la de inicio.');
        }

        // El estado NO se acepta desde este formulario. Se decide en otro
        // sitio y a proposito:
        //
        //   - Una campana nueva nace siempre en borrador. Si el formulario
        //     pudiera mandar «activa», bastaba con recargar la pagina con un
        //     campo estado=activa en el POST para saltarse activar() y toda la
        //     revision de tramos, premios, cantidades y formulario.
        //   - Al editar se conserva el estado que ya tiene. Guardar los datos
        //     generales no puede(des)activar nada, porque cambiar el estado es
        //     una decision aparte con su propia confirmacion.
        //
        // Activar pasa por activar() y finalizar tendra su propia accion, con el
        // mismo cuidado: los dos son estados que el administrador decide, no
        // datos que se guarden de paso al editar el nombre.
        //
        // Y si el POST trae un estado, se rechaza en vez de ignorarlo. Ignorarlo
        // seria mas corto, pero dejaria un fallo invisible: alguien que
        // escribiera estado=activa creeria que la campana se ha activado y lo
        // que pasaria es que el formulario se guardaria sin decir nada y la
        // campana seguiria en borrador. Un error que no se ve es peor que un
        // error que se ve. Que ademas se mande a proposito o no da igual: el
        // formulario no tiene ese campo, y una clave que no existe en el
        // formulario es un error de quien la manda, no algo que se normalice.
        if (array_key_exists('estado', $entrada)) {
            $v->anadirError(
                'estado',
                'El estado de la campana no se cambia desde aqui. Activala desde su ficha.'
            );
        }

        $estado = Promocion::ESTADO_BORRADOR;

        if ($id !== null) {
            $actual = $this->promociones->exigirPorId($id, 'admin/promociones/' . $id);

            if ($actual['estado'] !== Promocion::ESTADO_BORRADOR) {
                $estado = (string) $actual['estado'];
            }
        }

        $v->comprobar('Revisa los campos marcados.');

        return $this->promociones->guardar([
            'nombre'             => $nombre,
            'descripcion'        => $descripcion,
            'comercio_nombre'    => $comercioNombre,
            'comercio_cif'       => $cif,
            'comercio_domicilio' => $domicilio,
            'comercio_telefono'  => $telefono,
            'zona_horaria'       => $zona,
            'estado'             => $estado,
            'fecha_inicio'       => $inicio ?? '',
            'fecha_fin'          => $fin ?? '',
        ], $id);
    }

    /**
     * Guarda los ajustes que no son datos generales: correo, simulacion, D4 y retencion.
     *
     * Van aparte de guardar() porque el panel los edita en otra pantalla, y
     * mezclarlos haria que guardar el nombre de la campana borrase el cuerpo del
     * correo de ganadoras.
     *
     * @param array<string, mixed> $entrada    Datos tal y como llegan del formulario.
     * @param int                  $promocionId Campana a la que pertenecen.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si algun campo no cumple las reglas.
     */
    public function guardarAjustes(array $entrada, int $promocionId): void
    {
        $v = new Validador();

        $loteria = (string) ($entrada['loteria_persiste'] ?? 'si');

        if (!in_array($loteria, ['si', 'no'], true)) {
            $loteria = 'si';
        }

        $asunto = trim((string) ($entrada['correo_ganador_asunto'] ?? ''));
        $cuerpo = trim((string) ($entrada['correo_ganador_cuerpo'] ?? ''));

        if ($asunto !== '' && mb_strlen($asunto) > 190) {
            $v->anadirError('correo_ganador_asunto', 'El asunto del correo es demasiado largo.');
        }

        // El correo solo se revisa si se va a enviar. Pedir un cuerpo con el
        // interruptor apagado seria trabajo que nadie mira, y el apartado 4.9
        // pide que el envio este desactivado por defecto.
        $correoGanador = self::interruptor($entrada['correo_ganador'] ?? false);

        if ($correoGanador && $cuerpo === '') {
            $v->anadirError('correo_ganador_cuerpo', 'Si se avisa por correo hay que escribir el mensaje.');
        }

        if ($correoGanador) {
            $this->comprobarMarcadores($v, 'correo_ganador_cuerpo', $cuerpo);
        }

        $asuntoNoGanador = trim((string) ($entrada['correo_no_ganador_asunto'] ?? ''));

        if ($asuntoNoGanador !== '' && mb_strlen($asuntoNoGanador) > 190) {
            $v->anadirError('correo_no_ganador_asunto', 'El asunto del correo es demasiado largo.');
        }

        $cuerpoNoGanador = trim((string) ($entrada['correo_no_ganador_cuerpo'] ?? ''));
        $correoNoGanador = self::interruptor($entrada['correo_no_ganador'] ?? false);

        if ($correoNoGanador && $cuerpoNoGanador === '') {
            $v->anadirError('correo_no_ganador_cuerpo', 'Si se avisa por correo hay que escribir el mensaje.');
        }

        if ($correoNoGanador) {
            $this->comprobarMarcadores($v, 'correo_no_ganador_cuerpo', $cuerpoNoGanador);
        }

        $retencion = $entrada['retencion_dias'] ?? '';

        if ($retencion === '' || $retencion === null) {
            $retencionDias = null;
        } else {
            $retencionDias = $v->entero('retencion_dias', $retencion, 1, 3650, true);
        }

        $v->comprobar('Revisa los campos marcados.');

        $this->promociones->guardarConfiguracion([
            'modo_simulacion'         => self::interruptor($entrada['modo_simulacion'] ?? false),
            'loteria_persiste'        => $loteria,
            'correo_ganador'          => $correoGanador,
            'correo_no_ganador'       => $correoNoGanador,
            'correo_ganador_asunto'   => $asunto,
            'correo_ganador_cuerpo'   => $cuerpo,
            'correo_no_ganador_asunto' => $asuntoNoGanador,
            'correo_no_ganador_cuerpo' => $cuerpoNoGanador,
            'retencion_dias'          => $retencionDias,
        ], $promocionId);
    }

    /**
     * Guarda el formulario de participacion de una campana.
     *
     * El guardado es de todo o nada: sustituirTodos() borra lo anterior y
     * escribe lo nuevo, de forma que una pantalla a medio enviar no deja la
     * campana con tres campos de los siete. La operacion corre dentro de la
     * transaccion que se abre aqui, porque el borrado y los INSERT tienen que
     * ser atomicos.
     *
     * @param array<int, array<string, mixed>> $campos      Campos del formulario.
     * @param int                              $promocionId Campana a la que pertenecen.
     *
     * @return int Numero de campos guardados.
     *
     * @throws \App\Core\ErrorValidacion Si algun campo no cumple las reglas, o
     *                                   si la lista de campos no es utilizable.
     */
    public function guardarCampos(array $campos, int $promocionId): int
    {
        $v = new Validador();
        $limpios = [];
        $vistos = [];
        $orden = 0;

        foreach ($campos as $campo) {
            $orden++;
            $clave = $this->claveDeCampo($v, (string) ($campo['clave'] ?? ''), $orden);

            if ($clave === '') {
                continue;
            }

            if (in_array($clave, $vistos, true)) {
                $v->anadirError('campos', sprintf('El campo «%s» esta repetido.', $clave));
                continue;
            }

            $vistos[] = $clave;

            $etiqueta = trim((string) ($campo['etiqueta'] ?? ''));

            if ($etiqueta === '') {
                $v->anadirError('campos', sprintf('El campo «%s» no tiene etiqueta.', $clave));
                continue;
            }

            if (mb_strlen($etiqueta) > 80) {
                $v->anadirError('campos', sprintf('La etiqueta del campo «%s» es demasiado larga.', $clave));
                continue;
            }

            $tipo = (string) ($campo['tipo'] ?? 'texto');

            if (!in_array($tipo, self::TIPOS_CAMPO, true)) {
                $v->anadirError('campos', sprintf('El campo «%s» tiene un tipo que no existe.', $clave));
                continue;
            }

            $minimo = (int) ($campo['min_largo'] ?? 0);
            $maximo = (int) ($campo['max_largo'] ?? 200);

            if ($minimo < 0 || $maximo < 1) {
                $v->anadirError('campos', sprintf('El campo «%s» tiene limites que no admiten ningun valor.', $clave));
                continue;
            }

            if ($minimo > $maximo) {
                $v->anadirError('campos', sprintf('El campo «%s» pide mas caracteres de los que admite.', $clave));
                continue;
            }

            $limpio = [
                'clave'             => $clave,
                'etiqueta'          => $etiqueta,
                'tipo'              => $tipo,
                'obligatorio'       => self::interruptor($campo['obligatorio'] ?? false),
                'visible'           => self::interruptor($campo['visible'] ?? true),
                'orden'             => $orden,
                'valor_por_defecto' => trim((string) ($campo['valor_por_defecto'] ?? '')),
                'min_largo'         => $minimo,
                'max_largo'         => $maximo,
            ];

            // El valor por defecto no puede violar los limites del propio campo,
            // o la pantalla abriria con un campo ya relleno que no se podra
            // enviar hasta corregirlo.
            if (mb_strlen($limpio['valor_por_defecto']) > $maximo) {
                $v->anadirError('campos', sprintf('El valor por defecto del campo «%s» es mas largo que el maximo.', $clave));
                continue;
            }

            $limpios[] = $limpio;
        }

        if ($limpios === []) {
            $v->anadirError('campos', 'El formulario no puede quedarse sin campos.');
        }

        $visibles = array_filter($limpios, static fn (array $c): bool => (bool) $c['visible']);

        if ($visibles === []) {
            $v->anadirError('campos', 'El formulario no puede quedarse sin campos a la vista.');
        }

        $v->comprobar('Revisa el formulario.');

        $guardados = Aplicacion::db()->enTransaccion(
            fn (): int => $this->campos->sustituirTodos($promocionId, $limpios)
        );

        $this->promociones->tocar($promocionId);

        return $guardados;
    }

    /**
     * Guarda las reglas de participacion de una campana.
     *
     * @param array<string, mixed> $entrada     Datos tal y como llegan del formulario.
     * @param int                  $promocionId Campana a la que pertenecen.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si las reglas no son utilizables.
     */
    public function guardarReglas(array $entrada, int $promocionId): void
    {
        $v = new Validador();

        $identidad = trim((string) ($entrada['campo_identidad'] ?? ''));

        // El campo de identidad tiene que existir en el formulario. Si no
        // existe, la regla de una por persona se aplicaria contra un campo que
        // la clienta no envia, y no se podria rechazar a nadie: la comprobacion
        // daria «correcto» siempre.
        if ($identidad !== '' && !in_array($identidad, $this->campos->claves($promocionId), true)) {
            $v->anadirError('campo_identidad', 'El campo de identidad no existe en el formulario de esta campana.');
        }

        $consentimiento = self::interruptor($entrada['exigir_consentimiento'] ?? false);
        $textoConsentimiento = trim((string) ($entrada['texto_consentimiento'] ?? ''));

        if ($consentimiento && $textoConsentimiento === '') {
            $v->anadirError('texto_consentimiento', 'Si se pide consentimiento hay que escribir lo que se acepta.');
        }

        $verificar = self::interruptor($entrada['verificar_ticket'] ?? false);
        $exigirCodigo = self::interruptor($entrada['exigir_codigo'] ?? false);

        $textos = [];

        foreach ([
            'texto_rechazo_horario'        => 'Sin texto de rechazo fuera de horario, una participacion rechazada no tendria motivo visible.',
            'texto_rechazo_duplicado'      => 'Sin texto de rechazo por duplicado, la clienta no sabria por que se rechaza su intento.',
            'texto_rechazo_codigo'         => 'Sin texto de rechazo por codigo, la clienta no sabria por que se rechaza su intento.',
            'texto_rechazo_consentimiento' => 'Sin texto de rechazo por consentimiento, la clienta no sabria por que se rechaza su intento.',
        ] as $campo => $problema) {
            $texto = trim((string) ($entrada[$campo] ?? ''));

            if ($texto !== '' && mb_strlen($texto) > 255) {
                $v->anadirError($campo, 'El texto es demasiado largo.');
                continue;
            }

            $textos[$campo] = $texto;
        }

        // No se exige escribir los textos de rechazo. ReglaParticipacion::leer()
        // pone un texto por defecto para cada uno cuando llega vacio, y es lo
        // que se muestra a la clienta si no se ha personalizado. Obligar a
        // escribir cuatro frases parecidas cada vez que se guarda una campana
        // solo invitaba a escribir cualquier cosa para salir del paso.

        $v->comprobar('Revisa los campos marcados.');

        $this->reglas->guardar($promocionId, [
            'una_por_campana'              => self::interruptor($entrada['una_por_campana'] ?? false),
            'una_por_dia'                  => self::interruptor($entrada['una_por_dia'] ?? false),
            'una_por_ticket'               => self::interruptor($entrada['una_por_ticket'] ?? false),
            'una_por_dni'                  => self::interruptor($entrada['una_por_dni'] ?? false),
            'exigir_codigo'                => $exigirCodigo,
            'campo_identidad'              => $identidad,
            'exigir_consentimiento'        => $consentimiento,
            'texto_consentimiento'         => $textoConsentimiento,
            'verificar_ticket'             => $verificar,
            'texto_rechazo_horario'        => $textos['texto_rechazo_horario'],
            'texto_rechazo_duplicado'      => $textos['texto_rechazo_duplicado'],
            'texto_rechazo_codigo'         => $textos['texto_rechazo_codigo'],
            'texto_rechazo_consentimiento' => $textos['texto_rechazo_consentimiento'],
        ]);

        $this->promociones->tocar($promocionId);
    }

    /**
     * Guarda la apariencia de una campana.
     *
     * @param array<string, mixed> $entrada     Datos tal y como llegan del formulario.
     * @param int                  $promocionId Campana a la que pertenecen.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si algun color o texto no es valido.
     */
    public function guardarApariencia(array $entrada, int $promocionId): void
    {
        $v = new Validador();
        $porDefecto = $this->visual->valoresPorDefecto();
        $limpio = [];

        foreach (self::COLORES as $color) {
            $valor = trim((string) ($entrada[$color] ?? ''));

            if ($valor === '') {
                $valor = (string) $porDefecto[$color];
            }

            if (preg_match('/^#[0-9a-fA-F]{6}$/', $valor) !== 1) {
                $v->anadirError($color, 'El color tiene que tener la forma #RRGGBB.');
                continue;
            }

            $limpio[$color] = strtolower($valor);
        }

        // Los dos banners llevan texto alternativo, y es obligatorio si hay
        // imagen: un banner decorativo no necesita alt, pero estos dos son el
        // encabezado de las pantallas publicas y quien no ve la imagen tiene que
        // saber que dice.
        foreach (['banner_sup', 'banner_pie'] as $prefijo) {
            $ruta = trim((string) ($entrada[$prefijo . '_ruta'] ?? ''));

            // Solo se guardan rutas relativas dentro de uploads. Una ruta
            // absoluta dejaria de funcionar en cuanto la campana se copiase a
            // otra carpeta, que es justo lo que evita el esquema al guardarla
            // como VARCHAR en vez de como algo que dependa del disco.
            if ($ruta !== '' && !$this->esRutaDeImagenValida($ruta)) {
                $v->anadirError($prefijo . '_ruta', 'La imagen no es una ruta de la carpeta de imagenes.');
                continue;
            }

            $alt = trim((string) ($entrada[$prefijo . '_alt'] ?? ''));

            if ($alt !== '' && mb_strlen($alt) > 160) {
                $v->anadirError($prefijo . '_alt', 'El texto alternativo es demasiado largo.');
                continue;
            }

            if ($ruta !== '' && $alt === '') {
                $v->anadirError($prefijo . '_alt', 'La imagen necesita un texto alternativo.');
                continue;
            }

            $limpio[$prefijo . '_ruta'] = $ruta;
            $limpio[$prefijo . '_alt'] = $alt;
        }

        // Las imagenes de resultado llevan su rotulo en la misma columna de
        // texto, porque el esquema guarda la foto y su pie juntos: si se
        // separaran, cambiar el rotulo obligaria a volver a subir la imagen.
        foreach (['resultado_premio', 'resultado_no_premio'] as $prefijo) {
            $ruta = trim((string) ($entrada[$prefijo . '_ruta'] ?? ''));

            if ($ruta !== '' && !$this->esRutaDeImagenValida($ruta)) {
                $v->anadirError($prefijo . '_ruta', 'La imagen no es una ruta de la carpeta de imagenes.');
                continue;
            }

            $limpio[$prefijo . '_ruta'] = $ruta;
            $limpio[$prefijo . '_texto'] = trim((string) ($entrada[$prefijo . '_texto'] ?? ''));
        }

        foreach (['texto_ganador', 'texto_no_ganador'] as $campo) {
            $limpio[$campo] = trim((string) ($entrada[$campo] ?? ''));
        }

        $v->comprobar('Revisa los campos marcados.');

        $this->visual->guardar($promocionId, $limpio);
        $this->promociones->tocar($promocionId);
    }

    /**
     * Devuelve el resumen de una campana para la pantalla de inicio.
     *
     * El apartado 4.4 pide el total por tramo, por tipo y por campana. Los tres
     * salen de aqui para que la pantalla no tenga que calcularlos, y sobre todo
     * para que los tres coincidan: si cada uno lo calculara por su cuenta,
     * acabaria enseñando una cifra distinta en cada sitio.
     *
     * @param int $promocionId Campana que se resume.
     *
     * @return array<string, mixed> Totales y avisos, listos para pintar.
     */
    public function resumen(int $promocionId): array
    {
        $this->promociones->exigirPorId($promocionId, 'admin/promociones/' . $promocionId);

        $tramos = $this->tramos->listarPorPromocion($promocionId);
        $tipos = $this->tipos->listarPorPromocion($promocionId);
        $totales = [];

        foreach ($this->asignaciones->totalesPorTipo($promocionId) as $fila) {
            $totales[(int) $fila['tipo_premio_id']] = (int) $fila['total'];
        }

        $porTipo = [];

        foreach ($tipos as $tipo) {
            $porTipo[] = [
                'id'      => (int) $tipo['id'],
                'nombre'  => (string) $tipo['nombre'],
                'activo'  => (bool) $tipo['activo'],
                'total'   => $totales[(int) $tipo['id']] ?? 0,
            ];
        }

        $reglas = $this->reglas->leer($promocionId);

        return [
            'tramos'            => count($tramos),
            'tipos'             => count($tipos),
            'tipos_activos'     => count(array_filter($tipos, static fn (array $t): bool => (bool) $t['activo'])),
            'campos'            => count($this->campos->listarPorPromocion($promocionId)),
            'total_plan'        => $this->asignaciones->totalPlan($promocionId),
            'por_tipo'          => $porTipo,
            'reglas'            => $reglas,
            'pendientes'        => $this->pendientesDeActivar($promocionId),
            'avisos'            => $this->avisosDeReglas($reglas, $this->campos->claves($promocionId)),
        ];
    }

    /**
     * Compara lo planificado con el calendario ya generado.
     *
     * La comparacion la hace AsignacionTramo porque los dos lados salen de
     * una union de claves; aqui solo se le anaden los nombres de tramo y tipo,
     * que hacen falta para pintar la tabla y que no estan en el modelo. Asi la
     * pantalla no tiene que cruzarla sola con dos listas mas, que es como
     * apareceria cada premio con el nombre de otro.
     *
     * @param int $promocionId Campana que se compara.
     *
     * @return array<int, array<string, mixed>> Filas con «tramo_id»,
     *         «tipo_premio_id», «cantidad_plan», «cantidad_calendario»,
     *         «diferencia», «faltan», «sobran», «tramo_nombre», «tipo_nombre»
     *         y «cambia».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function compararPlanYCalendario(int $promocionId): array
    {
        $this->promociones->exigirPorId($promocionId, 'admin/promociones/' . $promocionId);

        $nombresTramo = [];

        foreach ($this->tramos->listarPorPromocion($promocionId) as $tramo) {
            $nombresTramo[(int) $tramo['id']] = Tramo::etiqueta($tramo);
        }

        $nombresTipo = [];

        foreach ($this->tipos->listarPorPromocion($promocionId) as $tipo) {
            $nombresTipo[(int) $tipo['id']] = (string) $tipo['nombre'];
        }

        $filas = [];

        foreach ($this->asignaciones->compararConCalendario($promocionId) as $fila) {
            $tramoId = (int) $fila['tramo_id'];
            $tipoId = (int) $fila['tipo_premio_id'];
            $diferencia = (int) $fila['diferencia'];

            // «faltan» y «sobran» salen del signo de la diferencia para que la
            // vista no tenga que restar ni decidir con un condicional. La
            // diferencia con signo se conserva porque es lo que hace falta para
            // saber si el calendario se puede sincronizar de una vez.
            $filas[] = $fila + [
                'tramo_nombre' => $nombresTramo[$tramoId] ?? ('Tramo ' . $tramoId),
                'tipo_nombre'  => $nombresTipo[$tipoId] ?? ('Premio ' . $tipoId),
                'faltan'       => $diferencia < 0 ? -$diferencia : 0,
                'sobran'       => $diferencia > 0 ? $diferencia : 0,
                'cambia'       => $diferencia !== 0,
            ];
        }

        return $filas;
    }

    /**
     * Devuelve los tramos de una campana que caen fuera de su periodo.
     *
     * @param int                $promocionId Campana que se revisa.
     * @param array<int, string> $fechas       Fechas de la campana, ya consultadas.
     *
     * @return array<int, array<string, mixed>> Tramos fuera de periodo.
     */
    private function tramosFueraDelPeriodo(int $promocionId, array $fechas): array
    {
        if ($fechas === []) {
            return [];
        }

        $promocion = $this->promociones->buscarPorId($promocionId);
        $inicio = (string) ($promocion['fecha_inicio'] ?? '');
        $fin = (string) ($promocion['fecha_fin'] ?? '');

        if ($inicio === '' && $fin === '') {
            return [];
        }

        $fuera = [];

        foreach ($this->tramos->listarPorPromocion($promocionId) as $tramo) {
            $fecha = (string) $tramo['fecha'];

            if ($inicio !== '' && $fecha < $inicio) {
                $fuera[] = $tramo;
                continue;
            }

            if ($fin !== '' && $fecha > $fin) {
                $fuera[] = $tramo;
            }
        }

        return $fuera;
    }

    /**
     * Indica si las reglas piden comprobar el ticket contra la lista cargada.
     *
     * Solo cuando el administrador ha declarado que el ticket se verifica. La
     * regla de una participacion por ticket no necesita la lista: se cumple
     * rechazando el segundo intento con el mismo numero, y para eso no hace
     * falta saber nada de donde ha salido el ticket. Exigir la lista en ese caso
     * impediria activar cualquier campana con control de duplicados, que es
     * justamente el modo que recomienda la decision D3 cuando el supermercado
     * no tiene integracion con la caja.
     *
     * @param array<string, mixed> $reglas Reglas de la campana.
     *
     * @return bool True si hace falta una lista de tickets.
     */
    private function necesitaListaDeTickets(array $reglas): bool
    {
        return (bool) ($reglas['verificar_ticket'] ?? false);
    }

    /**
     * Indica si las reglas piden comprobar el codigo contra la lista cargada.
     *
     * @param array<string, mixed> $reglas Reglas de la campana.
     *
     * @return bool True si hace falta una lista de codigos.
     */
    private function necesitaListaDeCodigos(array $reglas): bool
    {
        return (bool) ($reglas['exigir_codigo'] ?? false);
    }

    /**
     * Devuelve los avisos de las reglas que no se pueden cumplir.
     *
     * Se separan de pendientesDeActivar() porque estos no son motivo para
     * bloquear la activacion: una campana sin textos de rechazo puede activarse
     * y usar los textos por defecto. Bloquearla seria impedir hacer un sorteo por
     * no haber tocado un texto opcional.
     *
     * @param array<string, mixed> $reglas Reglas ya leidas.
     * @param array<int, string>   $claves Claves del formulario.
     *
     * @return array<int, string> Avisos, vacia si las reglas son coherentes.
     */
    private function avisosDeReglas(array $reglas, array $claves): array
    {
        $avisos = [];

        // El apartado 4.7 prohibe afirmar que un ticket esta verificado si lo
        // unico que se ha comprobado es que no se repite. El aviso va al panel
        // para que el administrador lo vea al configurar, no al cliente.
        if ((bool) ($reglas['una_por_ticket'] ?? false) && !($reglas['verificar_ticket'] ?? false)) {
            $avisos[] = 'Se controla el ticket por duplicados, pero no se ha declarado que el ticket se verifique contra la caja.';
        }

        $identidad = (string) ($reglas['campo_identidad'] ?? '');

        if ($identidad !== '' && !in_array($identidad, $claves, true)) {
            $avisos[] = 'El campo de identidad no existe en el formulario, y la regla no se podra aplicar.';
        }

        if (($reglas['una_por_campana'] ?? false) && $identidad === '') {
            $avisos[] = 'La regla de una participacion por campana no tiene campo de identidad, y se deducira del primer campo de contacto que haya.';
        }

        return $avisos;
    }

    /**
     * Indica si entre los campos visibles hay alguno de contacto.
     *
     * @param array<int, array<string, mixed>> $campos Campos visibles.
     *
     * @return bool True si hay correo o telefono.
     */
    private function tieneCampoDeContacto(array $campos): bool
    {
        foreach ($campos as $campo) {
            if (in_array((string) $campo['tipo'], ['email', 'telefono'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convierte la clave de un campo en algo que se pueda usar en un nombre.
     *
     * Las claves llegan del administrador y pueden traer espacios, acentos o
     * simbolos. Como la clave se usa para leer el valor recibido en la
     * participacion y como indice unico en la base de datos, se normaliza a
     * minusculas y guiones bajos. Sin esto, un campo llamado «Cód. Postal» y otro
     * llamado «codigo postal» serian el mismo campo para quien rellena y dos
     * campos distintos para la base de datos.
     *
     * @param \App\Core\Validador $v     Validador al que anotar los errores.
     * @param string              $clave Clave escrita por el administrador.
     * @param int                 $indice Posicion del campo, para el mensaje.
     *
     * @return string Clave normalizada, o la cadena vacia si no vale.
     */
    private function claveDeCampo(Validador $v, string $clave, int $indice): string
    {
        $normalizada = self::normalizarClave($clave);

        if ($normalizada === '') {
            $v->anadirError('campos', sprintf('El campo %d no tiene clave.', $indice));
            return '';
        }

        if (mb_strlen($normalizada) > 40) {
            $v->anadirError('campos', sprintf('La clave del campo %d es demasiado larga.', $indice));
            return '';
        }

        return $normalizada;
    }

    /**
     * Normaliza una clave de campo a minusculas y guiones bajos.
     *
     * Los acentos se quitan antes que nada porque se van a perder igualmente
     * al convertirlos en guion bajo, y perderlos es peor que cambiarlos por la
     * vocal sola: «Cód. Postal» se convierte en «c_d_postal», que es una clave
     * que no significa nada para quien la lea en el codigo, mientras que con
     * transliteracion sale «cod_postal».
     *
     * @param string $clave Clave sin normalizar.
     *
     * @return string Clave normalizada.
     */
    public static function normalizarClave(string $clave): string
    {
        $minusculas = mb_strtolower(trim($clave), 'UTF-8');
        $guiones = preg_replace('/[^a-z0-9]+/u', '_', self::quitarTildes($minusculas)) ?? '';

        return trim($guiones, '_');
    }

    /**
     * Sustituye las vocales acentuadas por su vocal sin tilde.
     *
     * Se hace con una tabla y no con iconv() porque iconv() no esta en todas las
     * instalaciones de PHP con las que se puede encontrar este proyecto, y una
     * tabla de doce pares cabe en pantalla y se lee de un vistazo. Cubre el
     * castellano y el frances, que son los dos idiomas de los textos de esta
     * aplicacion.
     *
     * @param string $texto Texto de partida.
     *
     * @return string Texto sin tildes.
     */
    public static function quitarTildes(string $texto): string
    {
        $tabla = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ];

        return strtr($texto, $tabla);
    }

    /**
     * Comprueba que los marcadores de un correo sean los controlados.
     *
     * El esquema solo admite la lista de variables controladas, y un marcador
     * desconocido se quedaria en la pantalla como texto literal «{{foo}}». Es
     * mejor avisar al guardar el correo que cuando una clienta lo recibe.
     *
     * @param \App\Core\Validador $v     Validador al que anotar los errores.
     * @param string              $campo Nombre del campo del cuerpo.
     * @param string              $cuerpo Texto del correo.
     *
     * @return void
     */
    private function comprobarMarcadores(Validador $v, string $campo, string $cuerpo): void
    {
        if (preg_match_all('/\{\{([a-z_]+)\}\}/i', $cuerpo, $coincidencias) === 0) {
            return;
        }

        $permitidos = ['nombre', 'premio', 'codigo', 'promocion', 'comercio'];

        foreach (array_unique($coincidencias[1]) as $marcador) {
            if (!in_array(mb_strtolower($marcador), $permitidos, true)) {
                $v->anadirError($campo, sprintf('El marcador {{%s}} no es uno de los que se pueden sustituir.', $marcador));
            }
        }
    }

    /**
     * Lee un interruptor que puede estar apagado sin que eso sea un error.
     *
     * Validador::casillaObligatoria() es para las casillas que hay que marcar
     * obligatoriamente, y anota un error cuando no lo estan. Un interruptor de
     * configuracion —«mandar correo», «controlar el ticket por duplicados»— es
     * justo lo contrario: apagado es una opcion valida y frecuente. Usar la
     * comprobacion de obligatoria para estos hacia que ninguna campana pudiera
     * tenerlos apagados.
     *
     * @param mixed $valor Valor recibido, del tipo que sea.
     *
     * @return bool True si el interruptor esta encendido.
     */
    private static function interruptor($valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        return $valor !== null && $valor !== '' && $valor !== '0' && $valor !== 'false';
    }

    /**
     * Devuelve un CIF y lo normaliza en mayusculas.
     *
     * @param mixed $cif Valor recibido.
     *
     * @return string CIF normalizado, o la cadena vacia si no se ha escrito.
     */
    private function validarCif($cif): string
    {
        $limpio = strtoupper(trim((string) $cif));

        if ($limpio === '') {
            return '';
        }

        if (preg_match('/^[ABCDEFGHJKLMNPQRSUVW][0-9]{7}[0-9A-J]$/', $limpio) !== 1) {
            return $limpio;
        }

        return $limpio;
    }

    /**
     * Valida una fecha en formato ISO y la devuelve, o null si va vacia.
     *
     * @param \App\Core\Validador $v     Validador al que anotar los errores.
     * @param string              $campo Nombre del campo.
     * @param mixed               $valor Valor recibido.
     *
     * @return string|null Fecha ISO, o null si esta vacia.
     */
    private function validarFecha(Validador $v, string $campo, $valor): ?string
    {
        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d', $texto);

        if ($fecha === false || $fecha->format('Y-m-d') !== $texto) {
            $v->anadirError($campo, 'La fecha no es valida.');

            return null;
        }

        return $texto;
    }

    /**
     * Indica si una ruta apunta a una imagen de la carpeta de subidas.
     *
     * No hay ninguna comprobacion aqui: la decide Imagenes::esRutaValida(),
     * que es el servicio que escribe esas rutas. Tenerla en los dos sitios
     * obligaba a mantener dos listas de extensiones, y ya se desincronizaron
     * una vez.
     *
     * @param string $ruta Ruta guardada.
     *
     * @return bool True si la ruta es relativa y no sale de la carpeta.
     */
    private function esRutaDeImagenValida(string $ruta): bool
    {
        return Imagenes::esRutaValida($ruta);
    }

    /**
     * Devuelve una fecha en formato corto para los mensajes de la pantalla.
     *
     * @param string $fecha Fecha en formato ISO.
     *
     * @return string Fecha con guiones en lugar de barras.
     */
    private function fechaLegible(string $fecha): string
    {
        $objeto = \DateTimeImmutable::createFromFormat('Y-m-d', $fecha);

        return $objeto === false ? $fecha : $objeto->format('d/m/Y');
    }
}
