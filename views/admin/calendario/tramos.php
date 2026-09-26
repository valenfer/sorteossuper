<?php

/**
 * Tramos de la campana y cantidades de cada premio en cada tramo.
 *
 * ============================================================================
 * POR QUE EL ALTA DE TRAMOS Y LAS CANTIDADES ESTAN EN LA MISMA PANTALLA
 * ============================================================================
 *
 * Por la misma razon que en el resto del panel: un tramo sin cantidades no
 * reparte nada, asi que anadir un tramo y decidir cuanto se reparte en el son
 * dos mitades de la misma operacion. Si estuvieran en dos sitios, el alta
 * acabaria con tramos vacios por el camino y nadie sabria si es que aun no se
 * ha decidido cuanto o si es que se ha olvidado.
 *
 * ============================================================================
 * POR QUE LAS CANTIDADES SE ESCRIBEN EN UNA CAJA, EN UNA TABLA, Y NO EN UNA
 * TABLA CON UNA CAJA POR PREMIO
 * ============================================================================
 *
 * Porque la tabla es de solo lectura: es el resumen de lo guardado, y pinta lo
 * que hay que volver a mirar. Escribir ahi meteria numeros en un sitio donde la
 * gente va a leer, no a escribir, y el error mas probable &mdash;confundir el
 * total con la cantidad de un premio&mdash; se evita. Ademas, con muchos
 * premios, la tabla se vuelve imposible de alinear a ojo; una caja por debajo de
 * cada fila, en el orden de los premios, no.
 *
 * @var string                          $titulo      Titulo de la pantalla.
 * @var array<string, mixed>            $campana     Fila de la campana.
 * @var array<int, array<string, mixed>> $filas      Tramos con sus cantidades.
 * @var array<int, array<string, mixed>> $premios     Premios de la campana.
 * @var array<int, array<string, mixed>> $cambiosHora Dias del periodo con salto
 *                                                       de hora, si los hay.
 * @var array<string, string>           $errores     Errores por campo, si los hay.
 * @var array<string, mixed>            $entrada     Valores enviados, si los hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$errores = $errores ?? [];
$entrada = $entrada ?? [];
$cambiosHora = $cambiosHora ?? [];
$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
$campo = static fn (string $clave, string $defecto = '') => (string) ($entrada[$clave] ?? $defecto);
$premiosActivos = array_values(array_filter(
    $premios,
    static fn (array $premio): bool => (bool) $premio['activo']
));

// Los dias con salto de hora se ofrecen como opciones del calendario, y el que
// coincide con la fecha escrita se avisa aparte, que es justo cuando hace falta.
$fechaEscrita = (string) ($entrada['fecha'] ?? '');
$cambioDeHoy = null;

foreach ($cambiosHora as $cambio) {
    if ((string) $cambio['fecha'] === $fechaEscrita) {
        $cambioDeHoy = $cambio;
    }
}
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Tramos
</p>

<?php if ($filas === []): ?>
    <p class="aviso aviso-aviso">
        No hay ningun tramo. Sin tramos no hay calendario, y sin calendario no
        se puede activar la campana.
    </p>
<?php endif; ?>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Anadir tramo</h2>

    <p class="ayuda">
        Un tramo es un rato con premio: una fecha y la hora de principio y la de
        fin. Un tramo que toca con otro de punta no se solapa y es valido, porque
        alguien puede ganar justo en el momento en que empieza el siguiente.
    </p>

    <?php if ($cambioDeHoy !== null): ?>
        <p class="aviso aviso-aviso">
            <strong>Ojo, ese dia cambia la hora.</strong>
            <?= Vista::e((string) $cambioDeHoy['motivo']) ?>. Un tramo que cruce
            ese momento tiene un minuto mas o un minuto menos de los que parece, y
            el reparto lo tiene en cuenta, pero conviene saberlo antes de escribir
            las horas.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= Vista::e(Aplicacion::url($base . '/tramos')) ?>" class="formulario">
        <?= Csrf::campo() ?>

        <div class="campo">
            <label for="fecha">Fecha</label>
            <input type="date" id="fecha" name="fecha" required
                   list="fechas-cambio-hora"
                   value="<?= Vista::e($campo('fecha')) ?>">
            <?php if ($cambiosHora !== []): ?>
                <datalist id="fechas-cambio-hora">
                    <?php foreach ($cambiosHora as $cambio): ?>
                        <option value="<?= Vista::e((string) $cambio['fecha']) ?>">
                            cambia la hora
                        </option>
                    <?php endforeach; ?>
                </datalist>
                <span class="campo-ayuda">
                    <?= count($cambiosHora) === 1 ? 'Este dia' : 'Estos dias' ?>
                    cambia la hora dentro del periodo de la campana:
                    <?php
                    $motivos = [];

                    foreach ($cambiosHora as $cambio) {
                        $motivos[] = (string) $cambio['fecha'] . ': ' . (string) $cambio['motivo'];
                    }

                    echo Vista::e(implode('; ', $motivos) . '.');
                    ?>
                </span>
            <?php endif; ?>
            <?php if ($error('fecha') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('fecha')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="hora_inicio">Hora de inicio</label>
            <input type="time" id="hora_inicio" name="hora_inicio" required step="60"
                   value="<?= Vista::e($campo('hora_inicio')) ?>">
            <?php if ($error('hora_inicio') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('hora_inicio')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="hora_fin">Hora de fin</label>
            <input type="time" id="hora_fin" name="hora_fin" required step="60"
                   value="<?= Vista::e($campo('hora_fin')) ?>">
            <?php if ($error('hora_fin') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('hora_fin')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo-boton">
            <button type="submit" class="boton boton-principal">Anadir tramo</button>
        </div>
    </form>
</section>

<?php foreach ($filas as $fila): ?>
    <?php
    $tramo = $fila['tramo'];
    $tramoId = (int) $tramo['id'];
    $cantidades = $fila['cantidades'];
    ?>
    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">
            <?= Vista::e((string) $fila['etiqueta']) ?>
        </h2>

        <p class="ruta-migas">
            <?= Vista::e((string) $tramo['fecha']) ?>
            &ndash;
            <?= Vista::e(substr((string) $tramo['hora_inicio'], 0, 5)) ?>
            a
            <?= Vista::e(substr((string) $tramo['hora_fin'], 0, 5)) ?>
        </p>

        <?php if ($premiosActivos === []): ?>
            <p class="aviso aviso-aviso">
                No hay premios activos en esta campana, asi que este tramo no
                reparte nada aunque tenga cantidades.
            </p>
        <?php else: ?>
            <div class="tabla-envoltorio">
                <table class="tabla">
                    <caption class="visualmente-oculto">
                        Cantidades del tramo <?= Vista::e((string) $fila['etiqueta']) ?>
                    </caption>
                    <thead>
                        <tr>
                            <th>Premio</th>
                            <th>Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($premiosActivos as $premio): ?>
                        <?php $premioId = (int) $premio['id']; ?>
                        <tr>
                            <td><?= Vista::e((string) $premio['nombre']) ?></td>
                            <td>
                                <?= isset($cantidades[$premioId])
                                    ? (int) $cantidades[$premioId]
                                    : 0 ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th>
                                <?php
                                $total = 0;

                                foreach ($premiosActivos as $premio) {
                                    $total += (int) ($cantidades[(int) $premio['id']] ?? 0);
                                }

                                echo $total;
                                ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>

        <form method="post"
              action="<?= Vista::e(Aplicacion::url($base . '/tramos/' . $tramoId . '/cantidades')) ?>"
              class="formulario">
            <?= Csrf::campo() ?>

            <h3 class="titulo-seccion">Cambiar las cantidades</h3>

            <?php foreach ($premiosActivos as $premio): ?>
                <?php $premioId = (int) $premio['id']; ?>
                <div class="campo">
                    <label for="cantidad_<?= $premioId ?>">
                        <?= Vista::e((string) $premio['nombre']) ?>
                    </label>
                    <input type="number" id="cantidad_<?= $premioId ?>"
                           name="cantidad[<?= $premioId ?>]" min="0" max="100000" step="1"
                           value="<?= (int) ($cantidades[$premioId] ?? 0) ?>">
                </div>
            <?php endforeach; ?>

            <div class="campo-boton">
                <button type="submit" class="boton boton-principal">Guardar cantidades</button>
            </div>
        </form>

        <form method="post"
              action="<?= Vista::e(Aplicacion::url($base . '/tramos/' . $tramoId . '/borrar')) ?>"
              onsubmit="return confirm('Borrar este tramo y sus cantidades?')">
            <?= Csrf::campo() ?>
            <button type="submit" class="boton boton-peligro">Borrar el tramo</button>
        </form>

        <p class="ayuda">
            Borrar el tramo se lleva por delante sus cantidades y las unidades del
            calendario que tenia asignadas. Si ya se ha entregado algo, el sistema
            no deja borrarlo.
        </p>
    </section>
<?php endforeach; ?>

<p class="ayuda">
    Con las cantidades escritas, el reparto por minuto se genera en
    <a href="<?= Vista::e(Aplicacion::url($base . '/calendario')) ?>">el calendario</a>.
</p>
