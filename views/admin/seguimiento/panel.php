<?php

/**
 * Panel de seguimiento de una campana.
 *
 * ============================================================================
 * POR QUE LOS NUMEROS VAN ARRIBA Y LOS LISTADOS ABAJO
 * ============================================================================
 *
 * Porque hay dos preguntas distintas y van en dos momentos distintos. La primera
 * es «¿como va la campana?», que se responde mirando cinco cifras sin interactuar
 * con nada, y es la que un administrador se hace al llegar por la manana. La
 * segunda es «¿este premio concreto por que no salio?», y esa se responde
 * bajando hasta el listado y filtrando. Si las dos estuvieran en el mismo sitio,
 * la segunda taparia a la primera.
 *
 * ============================================================================
 * POR QUE EL FILTRO ESTA UNA SOLA VEZ Y NO EN CADA LISTADO
 * ============================================================================
 *
 * Porque los dos listados se miran con la misma intencion —acotar a un tramo, a un
 * dia o a un tipo de premio— y tener dos juegos de filtros obligaria a repetirse
 * y a que uno se olvide. El apartado 8 pide los tres filtros, una vez, no dos.
 *
 * ============================================================================
 * POR QUE SE DICE SIEMPRE SI EL LISTADO ESTA RECORTADO
 * ============================================================================
 *
 * Por lo mismo que en el calendario: un administrador que ve 500 filas y 3000 en el
 * total, sin que se le diga que el listado esta recortado, puede pensar que solo
 * hay 500. Y con el filtro puesto el total ya no es el de la campana, sino el de lo
 * que se ha pedido, y esa cifra se escribe al lado para que no haya que deducirla.
 *
 * @var string                            $titulo     Titulo de la pantalla.
 * @var array<string, mixed>              $panel      Datos del panel, con la
 *                                                   forma que describe
 *                                                   \App\Services\Seguimiento::panel().
 * @var array<string, string>             $estados    Estados de una unidad.
 * @var bool                              $puedeCerrar Si la campana se puede
 *                                                   cerrar ahora mismo.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;
use App\Models\Auditoria;
use App\Models\Tramo;
use App\Models\UnidadPremio;
use App\Services\Seguimiento;

$campana = $panel['campana'];
$unidades = $panel['unidades'];
$participaciones = $panel['participaciones'];
$rechazos = $panel['rechazos'];
$correos = $panel['correos'];
$auditoria = $panel['auditoria'];
$listado = $panel['listado'];
$filtros = $panel['filtros'];
$tramos = $panel['tramos'];
$premios = $panel['premios'];
$tramoActual = $panel['tramo_actual'];
$comparacion = $panel['comparacion'];

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$hayFiltros = $filtros !== [];
$mostrar = static fn (string $clave, string $defecto = ''): string => (string) ($filtros[$clave] ?? $defecto);
$comparacion = $panel['comparacion'];

// El campo de fecha se deja vacio cuando no hay filtro, y no con la fecha de hoy.
//
// Ponerla ya elegida miente: el listado de abajo se pinta entero y sin filtrar,
// pero el campo dice una fecha, y quien lo mire —o quien pulse «Aplicar» sin
// querer— se encuentra con un listado vacio sin haber pedido nada. Un campo que
// se rellena solo tiene que significar que ese filtro esta puesto. Para pedir el
// listado de un dia concreto se elige ese dia, que es un clic.
$fechaFiltro = $mostrar('fecha');
$recortadoUnidades = $listado['total_unidades'] > Seguimiento::LIMITE_UNIDADES;
$recortadoAdjudicadas = $listado['total_adjudicadas'] > Seguimiento::LIMITE_ADJUDICACIONES;
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Seguimiento
</p>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Como va la campana</h2>

    <dl class="lista-datos lista-datos-compacta">
        <dt>Estado</dt>
        <dd>
            <?= Vista::e((string) $campana['estado']) ?>
            <?php if ((string) ($campana['cerrada_en'] ?? '') !== ''): ?>
                &mdash; cerrada el <?= Vista::e(substr((string) $campana['cerrada_en'], 0, 10)) ?>
                a las <?= Vista::e(substr((string) $campana['cerrada_en'], 11, 5)) ?>
            <?php endif; ?>
        </dd>

        <dt>Tramo actual</dt>
        <dd>
            <?php if ($tramoActual === null): ?>
                <span class="estado estado-borrador">Fuera de horario</span>
            <?php else: ?>
                <?= Vista::e(Tramo::etiqueta($tramoActual)) ?>
            <?php endif; ?>
        </dd>

        <dt>Hora de esta pantalla</dt>
        <dd><?= Vista::e(substr((string) $panel['momento'], 11, 5)) ?></dd>
    </dl>

    <h3 class="titulo-seccion">Premios</h3>

    <dl class="lista-datos lista-datos-compacta">
        <dt>Programadas</dt>
        <dd><?= (int) $unidades['programadas'] ?></dd>

        <dt>Ya disponibles sin entregar</dt>
        <dd><?= (int) $unidades['pendientes'] ?></dd>

        <dt>Entregadas</dt>
        <dd><?= (int) $unidades['entregadas'] ?></dd>

        <dt>No entregadas</dt>
        <dd><?= (int) $unidades['no_entregadas'] ?></dd>

        <dt>Anuladas</dt>
        <dd><?= (int) $unidades['anuladas'] ?></dd>

        <dt>Total del plan</dt>
        <dd><?= (int) $unidades['total'] ?></dd>
    </dl>

    <h3 class="titulo-seccion">Participaciones</h3>

    <dl class="lista-datos lista-datos-compacta">
        <dt>Validas</dt>
        <dd><?= (int) $participaciones['total'] ?></dd>

        <dt>Con premio</dt>
        <dd><?= (int) $participaciones['con_premio'] ?></dd>

        <dt>Sin premio</dt>
        <dd><?= (int) $participaciones['sin_premio'] ?></dd>

        <dt>Rechazadas</dt>
        <dd><?= (int) $rechazos['total'] ?></dd>
    </dl>

    <h3 class="titulo-seccion">Correos</h3>

    <?php if ($correos === []): ?>
        <p class="ayuda">
            No se ha encolado ningun correo. En una campana sin correos activados
            el codigo de reclamacion se enseña en pantalla, y con los correos
            activados se manda al correo de la persona.
        </p>
    <?php else: ?>
        <dl class="lista-datos lista-datos-compacta">
            <?php foreach ($correos as $clave => $total): ?>
                <dt><?= Vista::e($clave) ?></dt>
                <dd><?= (int) $total ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>

    <h3 class="titulo-seccion">Auditoria</h3>

    <dl class="lista-datos lista-datos-compacta">
        <dt>Anotaciones</dt>
        <dd><?= (int) $auditoria['total'] ?></dd>

        <?php foreach ($auditoria['por_accion'] as $accion => $total): ?>
            <dt><?= Vista::e($accion) ?></dt>
            <dd><?= (int) $total ?></dd>
        <?php endforeach; ?>
    </dl>

    <p class="ayuda">
        Mirar esta pantalla deja anotado quien ha sido, con que filtro y cuantas
        filas ha visto. Es lo que exige la decision D18.
    </p>
</section>

<?php if ($comparacion !== []): ?>
    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">El calendario ya no es el que se planeo</h2>

        <p class="aviso aviso-aviso">
            <?= count($comparacion) ?>
            <?= count($comparacion) === 1 ? 'premio no' : 'premios no' ?>
            cuadran con el plan. Puede que se haya retirado alguno, o que se haya
            anadido a mano. Se deja como esta, porque el historial manda: lo que se
            ha retirado no vuelve.
        </p>

        <div class="tabla-envoltorio">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Tramo</th>
                        <th>Premio</th>
                        <th>Plan</th>
                        <th>Calendario</th>
                        <th>Diferencia</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comparacion as $fila): ?>
                    <tr class="fila-alerta">
                        <td><?= Vista::e((string) $fila['tramo_nombre']) ?></td>
                        <td><?= Vista::e((string) $fila['tipo_nombre']) ?></td>
                        <td><?= (int) $fila['cantidad_plan'] ?></td>
                        <td><?= (int) $fila['cantidad_calendario'] ?></td>
                        <td>
                            <?php if ((int) $fila['faltan'] > 0): ?>
                                faltan <?= (int) $fila['faltan'] ?>
                            <?php endif; ?>
                            <?php if ((int) $fila['sobran'] > 0): ?>
                                sobran <?= (int) $fila['sobran'] ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="ayuda">
            Se puede volver a igualar desde
            <a href="<?= Vista::e(Aplicacion::url($base . '/calendario')) ?>">el calendario</a>.
            El apartado 8 pide ver estas diferencias aqui, y no solo en la ficha,
            porque a mitad de campana es cuando se cambia el calendario.
        </p>
    </section>
<?php endif; ?>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Cerrar la campana</h2>

    <?php if (!$puedeCerrar): ?>
        <p class="aviso aviso-aviso">
            <?= (string) $campana['estado'] === \App\Models\Promocion::ESTADO_ACTIVA
                ? 'No se puede cerrar ahora mismo.'
                : 'Esta campana no esta activa, asi que ya no se puede cerrar.' ?>
        </p>
    <?php else: ?>
        <p>
            Al cerrar la campana, los
            <strong><?= (int) $unidades['programadas'] ?></strong> premios que queden
            <?= (int) $unidades['programadas'] === 1 ? 'sin entregar' : 'programados sin entregar' ?>
            pasan a «no entregados». No se adjudican a nadie: son premios que
            nadie ha llegado a reclamar.
        </p>

        <?php if ((int) $unidades['pendientes'] > 0): ?>
            <p class="aviso aviso-aviso">
                Hay <strong><?= (int) $unidades['pendientes'] ?></strong>
                <?= (int) $unidades['pendientes'] === 1 ? 'premio' : 'premios' ?>
                que ya han llegado a su hora y que todavia no se han entregado.
                Si quedan personas por participar, cierra mas tarde.
            </p>
        <?php endif; ?>

        <form method="post"
              action="<?= Vista::e(Aplicacion::url($base . '/seguimiento/cerrar')) ?>"
              onsubmit="return confirm('Cerrar la campana? Los premios sin entregar no se podran adjudicar despues.')">
            <?= Csrf::campo() ?>
            <div class="campo-boton">
                <button type="submit" class="boton boton-peligro">Cerrar la campana</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Filtros</h2>

    <form method="get" action="<?= Vista::e(Aplicacion::url($base . '/seguimiento')) ?>" class="filtros">
        <div class="campo">
            <label for="f_fecha">Fecha</label>
            <input type="date" id="f_fecha" name="fecha" value="<?= Vista::e($fechaFiltro) ?>">
            <p class="campo-ayuda">Deja la fecha en el dia de hoy para no filtrar.</p>
        </div>

        <div class="campo">
            <label for="f_tramo">Tramo</label>
            <select id="f_tramo" name="tramo">
                <option value="">Todos</option>
                <?php foreach ($tramos as $tramo): ?>
                    <option value="<?= (int) $tramo['id'] ?>"
                            <?= $mostrar('tramo_id') === (string) $tramo['id'] ? 'selected' : '' ?>>
                        <?= Vista::e(Tramo::etiqueta($tramo)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="f_premio">Premio</label>
            <select id="f_premio" name="premio">
                <option value="">Todos</option>
                <?php foreach ($premios as $premio): ?>
                    <option value="<?= (int) $premio['id'] ?>"
                            <?= $mostrar('tipo_premio_id') === (string) $premio['id'] ? 'selected' : '' ?>>
                        <?= Vista::e((string) $premio['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo-boton">
            <button type="submit" class="boton boton-principal">Filtrar</button>
            <?php if ($hayFiltros): ?>
                <a class="boton boton-sutil" href="<?= Vista::e(Aplicacion::url($base . '/seguimiento')) ?>">
                    Quitar filtros
                </a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Unidades de premio</h2>

    <p>
        Mostrando <strong><?= count($listado['unidades']) ?></strong> de
        <strong><?= (int) $listado['total_unidades'] ?></strong> unidades
        <?= $hayFiltros ? 'con el filtro puesto' : 'en toda la campana' ?>.
    </p>

    <?php if ($recortadoUnidades): ?>
        <p class="aviso aviso-aviso">
            Se enseñan solo las <?= Seguimiento::LIMITE_UNIDADES ?> primeras. Acota
            con un filtro para ver el resto.
        </p>
    <?php endif; ?>

    <?php if ($listado['unidades'] === []): ?>
        <p class="aviso aviso-aviso">
            <?= $hayFiltros
                ? 'Ninguna unidad cumple esos filtros.'
                : 'Esta campana no tiene calendario. Generalo desde la pantalla de calendario.' ?>
        </p>
    <?php else: ?>
        <div class="tabla-envoltorio">
            <table class="tabla tabla-calendario">
                <thead>
                    <tr>
                        <th>Hora prevista</th>
                        <th>Tramo</th>
                        <th>Premio</th>
                        <th>Estado</th>
                        <th>Codigo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($listado['unidades'] as $unidad): ?>
                    <?php $estado = (string) $unidad['estado']; ?>
                    <tr>
                        <td><?= Vista::e(substr((string) $unidad['inicio'], 11, 8)) ?></td>
                        <td><?= Vista::e(substr((string) $unidad['fecha'], 5) . ' ' . substr((string) $unidad['hora_inicio'], 0, 5)) ?></td>
                        <td><?= Vista::e((string) $unidad['premio']) ?></td>
                        <td><?= Vista::e($estados[$estado] ?? $estado) ?></td>
                        <td><?= Vista::e((string) ($unidad['codigo_reclamacion'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Premios entregados, con su hora real</h2>

    <p>
        Mostrando <strong><?= count($listado['adjudicadas']) ?></strong> de
        <strong><?= (int) $listado['total_adjudicadas'] ?></strong> entregas.
    </p>

    <?php if ($recortadoAdjudicadas): ?>
        <p class="aviso aviso-aviso">
            Se enseñan solo las <?= Seguimiento::LIMITE_ADJUDICACIONES ?> ultimas.
            Acota con un filtro para ver el resto.
        </p>
    <?php endif; ?>

    <?php if ($listado['adjudicadas'] === []): ?>
        <p class="aviso aviso-aviso">
            Todavia no se ha entregado ningun premio
            <?= $hayFiltros ? 'con esos filtros' : 'en esta campana' ?>.
        </p>
    <?php else: ?>
        <div class="tabla-envoltorio">
            <table class="tabla tabla-calendario">
                <thead>
                    <tr>
                        <th>Prevista</th>
                        <th>Real</th>
                        <th>Espera</th>
                        <th>Premio</th>
                        <th>Codigo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($listado['adjudicadas'] as $premio): ?>
                    <?php $espera = (int) $premio['retraso_minutos']; ?>
                    <tr>
                        <td><?= Vista::e(substr((string) $premio['inicio'], 11, 5)) ?></td>
                        <td><?= Vista::e(substr((string) $premio['adjudicada_en'], 11, 5)) ?></td>
                        <td>
                            <?php if ($espera > 0): ?>
                                <span class="estado estado-borrador">
                                    <?= $espera ?> min
                                </span>
                            <?php else: ?>
                                <span class="estado estado-activa">A la hora</span>
                            <?php endif; ?>
                        </td>
                        <td><?= Vista::e((string) $premio['premio']) ?></td>
                        <td><?= Vista::e((string) ($premio['codigo_reclamacion'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="ayuda">
            Un premio se entrega cuando llega la siguiente participacion valida, no
            a la hora prevista. Una espera larga y repetida suele querer decir que
            no hay azafatas en el mostrador o que ha entrado mucha gente a la vez.
        </p>
    <?php endif; ?>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Historial de auditoria</h2>

    <?php if (($auditoria['historial'] ?? []) === []): ?>
        <p class="aviso aviso-aviso">Todavia no hay nada anotado en esta campana.</p>
    <?php else: ?>
        <div class="tabla-envoltorio">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Cuando</th>
                        <th>Quien</th>
                        <th>Accion</th>
                        <th>Que se hizo</th>
                        <th>Filtro</th>
                        <th>Filas</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($auditoria['historial'] as $linea): ?>
                    <?php $queSeHizo = Auditoria::descripcionDe((string) $linea['accion']); ?>
                    <tr>
                        <td><?= Vista::e((string) $linea['creado_en']) ?></td>
                        <td><?= Vista::e((string) $linea['usuario_nombre']) ?></td>
                        <td><?= Vista::e((string) $linea['accion']) ?></td>
                        <td><?= Vista::e($queSeHizo) ?></td>
                        <td><?= Vista::e((string) ($linea['filtros'] ?? '')) ?></td>
                        <td>
                            <?= $linea['filas_mostradas'] === null
                                ? '&mdash;'
                                : (int) $linea['filas_mostradas'] ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="ayuda">
            La columna «Que se hizo» traduce el nombre corto de la accion, porque el
            historial se lee a ojo y buscar «retirada» entre cuatrocientas filas de
            «adjudicacion» es trabajo de nadie. Las anotaciones de tipo
            <?= Vista::e(Auditoria::ACCION_VISUALIZACION) ?> son las consultas de
            esta pantalla, y las de tipo <?= Vista::e(Auditoria::ACCION_CIERRE) ?>
            son los cierres de campana.
        </p>
    <?php endif; ?>
</section>
