<?php

/**
 * Calendario de la campana: el reparto por minuto.
 *
 * ============================================================================
 * POR QUE EL GENERADOR ESTA ARRIBA Y EL LISTADO ABAJO
 * ============================================================================
 *
 * Porque generar un calendario cambia la campana, y ver el calendario no. Si el
 * boton de generar estuviera al final, debajo de tres mil filas de premios, no
 * se encontraria nunca y acabaria sin usarse. Y si estuviera arriba del todo,
 * un clic de mas reharia el reparto entero. Aqui esta en su propia tarjeta, con
 * lo que va a pasar escrito debajo, y el listado debajo de ella.
 *
 * ============================================================================
 * POR QUE GENERAR ES UN POST CON DOS CONFIRMACIONES
 * ============================================================================
 *
 * Porque no es un filtro ni una busqueda: reparte premios de verdad, y en una
 * campana activa significa correo a la gente y premios que salen de la caja. Por eso
 * el boton pide confirmacion, y por eso «reemplazar» y «repetir unidades» son
 * casillas que se marcan a proposito y no interruptores que estan apagados por
 * defecto y que alguien activa sin querer.
 *
 * ============================================================================
 * POR QUE SE MUESTRA EL DIAGNOSTICO ANTES DE GENERAR
 * ============================================================================
 *
 * Porque hay tramos que no caben en su numero de minutos, y eso no se ve hasta
 * que se intenta. Enseñar el problema antes de que se pulse el boton convierte
 * un error en una decision informada: se ajusta el tramo, se sube la cantidad, o
 * se acepta el reparto con horas coincidentes, que es justo lo que la segunda
 * casilla propone.
 *
 * @var string                          $titulo      Titulo de la pantalla.
 * @var array<string, mixed>            $campana     Fila de la campana.
 * @var array<int, array<string, mixed>> $unidades    Unidades del calendario.
 * @var int                             $total       Total de unidades, sin limite.
 * @var array<string, mixed>            $filtros     Filtros aplicados.
 * @var array<int, array<string, mixed>> $tramos      Tramos de la campana.
 * @var array<int, array<string, mixed>> $premios     Premios de la campana.
 * @var array<int, array<string, mixed>> $diagnostico Tramos que no caben.
 * @var array<int, array<string, mixed>> $comparacion Plan frente a calendario.
 * @var array<string, string>           $estados     Estados de una unidad.
 * @var array<string, string>           $errores     Errores por campo, si los hay.
 * @var array<string, mixed>            $entrada     Valores enviados, si los hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;
use App\Models\Tramo;
use App\Models\UnidadPremio;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$hayFiltros = $filtros !== [];
$limite = 500;

// Que el listado este recortado se dice siempre, no solo cuando pasa. Un
// administrador que ve 500 filas y 3000 en el total tiene que saber que la
// pantalla no es el calendario entero, o dejara de fiarse de ella.
$recortado = $total > $limite;
$mostrar = static fn (string $clave, string $defecto = ''): string => (string) ($filtros[$clave] ?? $defecto);

// Los formularios de anadir y de mover se repintan con lo que se escribio y con
// el error al lado del campo, no como un aviso que desaparece al recargar.
$errores = $errores ?? [];
$entrada = $entrada ?? [];
$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
$campo = static fn (string $clave, string $defecto = ''): string => (string) ($entrada[$clave] ?? $defecto);

// Un premio desactivado no admite unidades nuevas, asi que no se ofrece en el
// desplegable. Retirarlo de la lista es mejor que ofrecerlo y dejar que el
// servicio lo rechace: el error llegaria despues de haber escrito la hora.
$premiosActivos = array_values(array_filter(
    $premios,
    static fn (array $premio): bool => (bool) $premio['activo']
));
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Calendario
</p>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Generar el reparto</h2>

    <?php if ($diagnostico !== []): ?>
        <p class="aviso aviso-aviso">
            <strong>Hay <?= count($diagnostico) ?> <?= count($diagnostico) === 1 ? 'tramo que' : 'tramos que' ?>
            no caben en su tiempo.</strong>
            Con la cantidad que tienen, no hay minutos suficientes para colocar
            todos los premios sin repetir. Puedes alargar el tramo, bajar la
            cantidad, o marcar la casilla de abajo para aceptar horas
            coincidentes.
        </p>

        <div class="tabla-envoltorio">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Tramo</th>
                        <th>Horario</th>
                        <th>Premios</th>
                        <th>Minutos</th>
                        <th>Como quedaria</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($diagnostico as $problema): ?>
                    <tr class="fila-alerta">
                        <td><?= Vista::e((string) $problema['fecha']) ?></td>
                        <td><?= Vista::e((string) $problema['horario']) ?></td>
                        <td><?= (int) $problema['unidades'] ?></td>
                        <td><?= (int) $problema['minutos'] ?></td>
                        <td><?= Vista::e((string) $problema['precision']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <form method="post"
          action="<?= Vista::e(Aplicacion::url($base . '/calendario/generar')) ?>"
          onsubmit="return confirm('Generar el reparto de premios ahora?')">
        <?= Csrf::campo() ?>

        <div class="regla">
            <div class="campo-casilla">
                <input type="checkbox" id="reemplazar" name="reemplazar" value="1">
                <label for="reemplazar">Reemplazar el calendario que ya hay</label>
            </div>
            <p class="ayuda">
                Borra las unidades que no se han entregado y las vuelve a
                colocar. Las que ya estan entregadas no se tocan. Si no se marca,
                y ya hay unidades, el generador se niega a continuar en vez de
                anadir encima.
            </p>
        </div>

        <div class="regla">
            <div class="campo-casilla">
                <input type="checkbox" id="permitir_repetir" name="permitir_repetir" value="1">
                <label for="permitir_repetir">Admitir horas coincidentes</label>
            </div>
            <p class="ayuda">
                Cuando no caben todos los premios en los minutos del tramo, se
                reparte en las horas que tocan, de manera que dos premios pueden
                salir en el mismo minuto. Es una salida &mdash;la ultima del
                apartado 4.6&mdash; y solo tiene sentido si se ha decidido que
               la que se prefiere es repartir antes que no repartir.
            </p>
        </div>

        <div class="campo-boton">
            <button type="submit" class="boton boton-principal">Generar el reparto</button>
        </div>
    </form>
</section>

<?php if ($tramos !== [] && $premiosActivos !== []): ?>
<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Anadir o quitar unidades a mano</h2>

    <p class="ayuda">
        Para cuando el reparto generado no es el que quieres: se anade una unidad
        suelta, se le cambia la hora, o se retira. La fecha la pone el tramo que
        elijas, no se escribe aparte, porque un tramo del martes con la fecha del
        jueves no es una unidad: es un error.
    </p>

    <h3>Anadir una unidad</h3>

    <form method="post" action="<?= Vista::e(Aplicacion::url($base . '/calendario/unidad')) ?>"
          class="formulario">
        <?= Csrf::campo() ?>

        <div class="campo">
            <label for="anadir-tramo">Tramo</label>
            <select id="anadir-tramo" name="tramo_id" required>
                <?php foreach ($tramos as $opcion): ?>
                    <option value="<?= (int) $opcion['id'] ?>"
                        <?= (int) ($entrada['tramo_id'] ?? 0) === (int) $opcion['id'] ? 'selected' : '' ?>>
                        <?= Vista::e(Tramo::etiqueta($opcion)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($error('tramo_id') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('tramo_id')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="anadir-premio">Premio</label>
            <select id="anadir-premio" name="premio_id" required>
                <?php foreach ($premiosActivos as $opcion): ?>
                    <option value="<?= (int) $opcion['id'] ?>"
                        <?= (int) ($entrada['premio_id'] ?? 0) === (int) $opcion['id'] ? 'selected' : '' ?>>
                        <?= Vista::e((string) $opcion['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($error('premio_id') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('premio_id')) ?></span>
            <?php endif; ?>
            <?php if ($error('tipo_premio_id') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('tipo_premio_id')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="anadir-hora">Hora</label>
            <input type="time" id="anadir-hora" name="hora" required step="60"
                   value="<?= Vista::e($campo('hora')) ?>">
            <?php if ($error('inicio') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('inicio')) ?></span>
            <?php endif; ?>
            <?php if ($error('hora') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('hora')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo-boton">
            <button type="submit" class="boton boton-principal">Anadir unidad</button>
        </div>
    </form>
</section>
<?php elseif ($tramos === []): ?>
    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Anadir unidades a mano</h2>
        <p class="aviso aviso-aviso">
            No hay ningun tramo, y una unidad siempre cae dentro de un tramo. Anade
            primero un tramo.
        </p>
    </section>
<?php else: ?>
    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Anadir unidades a mano</h2>
        <p class="aviso aviso-aviso">
            No hay ningun premio activo. Un premio desactivado no admite unidades
            nuevas.
        </p>
    </section>
<?php endif; ?>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Plan frente a calendario</h2>

    <?php if ($comparacion === []): ?>
        <p class="aviso aviso-aviso">
            No hay nada que comparar todavia. Escribe las cantidades en
            <a href="<?= Vista::e(Aplicacion::url($base . '/tramos')) ?>">tramos y cantidades</a>
            y luego genera el reparto.
        </p>
    <?php else: ?>
        <div class="tabla-envoltorio">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Tramo</th>
                        <th>Premio</th>
                        <th>Plan</th>
                        <th>Calendario</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($comparacion as $fila): ?>
                    <tr class="<?= $fila['cambia'] ? 'fila-alerta' : '' ?>">
                        <td><?= Vista::e((string) $fila['tramo_nombre']) ?></td>
                        <td><?= Vista::e((string) $fila['tipo_nombre']) ?></td>
                        <td><?= (int) $fila['cantidad_plan'] ?></td>
                        <td><?= (int) $fila['cantidad_calendario'] ?></td>
                        <td>
                            <?php if (!$fila['cambia']): ?>
                                <span class="estado estado-activa">Igual</span>
                            <?php else: ?>
                                <?php if ((int) $fila['faltan'] > 0): ?>
                                    <span class="estado estado-borrador">Faltan <?= (int) $fila['faltan'] ?></span>
                                <?php endif; ?>
                                <?php if ((int) $fila['sobran'] > 0): ?>
                                    <span class="estado estado-borrador">Sobran <?= (int) $fila['sobran'] ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Unidades</h2>

    <form method="get" action="<?= Vista::e(Aplicacion::url($base . '/calendario')) ?>" class="filtros">
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

        <div class="campo">
            <label for="f_estado">Estado</label>
            <select id="f_estado" name="estado">
                <option value="">Todos</option>
                <?php foreach ($estados as $clave => $nombre): ?>
                    <option value="<?= Vista::e($clave) ?>"
                            <?= $mostrar('estado') === $clave ? 'selected' : '' ?>>
                        <?= Vista::e($nombre) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo-boton">
            <button type="submit" class="boton boton-principal">Filtrar</button>
            <?php if ($hayFiltros): ?>
                <a class="boton boton-sutil" href="<?= Vista::e(Aplicacion::url($base . '/calendario')) ?>">
                    Quitar filtros
                </a>
            <?php endif; ?>
        </div>
    </form>

    <p>
        Mostrando <strong><?= count($unidades) ?></strong> de
        <strong><?= $total ?></strong> unidades.
    </p>

    <?php if ($recortado): ?>
        <p class="aviso aviso-aviso">
            Se enseñan solo las <?= $limite ?> primeras. Filtra por tramo o por
            premio para ver el resto.
        </p>
    <?php endif; ?>

    <?php if ($unidades === []): ?>
        <p class="aviso aviso-aviso">
            <?= $hayFiltros
                ? 'Ninguna unidad cumple esos filtros.'
                : 'Todavia no hay calendario. Genera el reparto de arriba.' ?>
        </p>
    <?php else: ?>
        <div class="tabla-envoltorio">
            <table class="tabla tabla-calendario">
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Premio</th>
                        <th>Estado</th>
                        <th>Codigo</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($unidades as $unidad): ?>
                    <?php $estado = (string) $unidad['estado']; ?>
                    <tr>
                        <td><?= Vista::e(substr((string) $unidad['inicio'], 11, 8)) ?></td>
                        <td><?= Vista::e((string) $unidad['premio']) ?></td>
                        <td><?= Vista::e($estados[$estado] ?? $estado) ?></td>
                        <td>
                            <?= Vista::e((string) ($unidad['codigo_reclamacion'] ?? '')) ?>
                        </td>
                        <td class="columna-acciones">
                            <?php if ($estado === UnidadPremio::ESTADO_PROGRAMADA): ?>
                                <form method="post"
                                      action="<?= Vista::e(Aplicacion::url($base . '/calendario/' . (int) $unidad['id'] . '/retirar')) ?>"
                                      onsubmit="return confirm('Retirar esta unidad del calendario?')">
                                    <?= Csrf::campo() ?>
                                    <button type="submit" class="boton boton-peligro">Retirar</button>
                                </form>

                                <?php if ($tramos !== []): ?>
                                    <?php /* Mover va en su propio formulario y no en un
                                       desplegable dentro del de retirar: son dos acciones
                                       distintas con dos consecuencias distintas, y un
                                       administrador que quiere mover una hora no deberia
                                       tener que decidir si la quiere retirar. */ ?>
                                    <?php
                                    // Cuando el servicio rechaza el movimiento, esta fila
                                    // se repinta con lo que se escribio, no con la hora
                                    // que tenia antes. Perder el valor escrito obliga a
                                    // repetir el trabajo y a volver a fallar.
                                    $esEsta = (int) ($entrada['unidad_id'] ?? 0) === (int) $unidad['id'];
                                    $tramoElegido = (int) ($esEsta ? ($entrada['tramo_id'] ?? 0) : $unidad['tramo_id']);
                                    $horaElegida = $esEsta
                                        ? (string) ($entrada['hora'] ?? '')
                                        : substr((string) $unidad['inicio'], 11, 5);
                                    ?>
                                    <form method="post"
                                          action="<?= Vista::e(Aplicacion::url($base . '/calendario/' . (int) $unidad['id'] . '/mover')) ?>"
                                          class="mover-unidad">
                                        <?= Csrf::campo() ?>

                                        <label class="visualmente-oculto"
                                               for="mover-<?= (int) $unidad['id'] ?>">
                                            Tramo al que se mueve la unidad de las
                                            <?= Vista::e(substr((string) $unidad['inicio'], 11, 8)) ?>
                                        </label>

                                        <select id="mover-<?= (int) $unidad['id'] ?>"
                                                name="tramo_id">
                                            <?php foreach ($tramos as $opcion): ?>
                                                <option value="<?= (int) $opcion['id'] ?>"
                                                    <?= $tramoElegido === (int) $opcion['id'] ? 'selected' : '' ?>>
                                                    <?= Vista::e(Tramo::etiqueta($opcion)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label class="visualmente-oculto"
                                               for="mover-hora-<?= (int) $unidad['id'] ?>">
                                            Hora a la que se mueve
                                        </label>
                                        <input type="time" id="mover-hora-<?= (int) $unidad['id'] ?>"
                                               name="hora" step="60" required
                                               value="<?= Vista::e($horaElegida) ?>">

                                        <button type="submit" class="boton boton-sutil"
                                                onclick="return confirm('Mover esta unidad a la hora de al lado?')">
                                            Mover
                                        </button>

                                        <?php if ($esEsta && ($error('tramo_id') !== '' || $error('inicio') !== '')): ?>
                                            <span class="campo-error">
                                                <?= Vista::e($error('tramo_id') !== '' ? $error('tramo_id') : $error('inicio')) ?>
                                            </span>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="ayuda">
                                    <?= (string) ($unidad['anulada_motivo'] ?? '') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="ayuda">
            Una unidad que ya esta entregada o anulada no se retira: lo que paso
            forma parte del historial y no se puede deshacer desde el panel.
        </p>
    <?php endif; ?>
</section>
