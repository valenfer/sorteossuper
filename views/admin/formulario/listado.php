<?php

/**
 * Campos del formulario de participacion de la campana.
 *
 * ============================================================================
 * POR QUE SE EDITA LA LISTA ENTERA Y NO UN CAMPO SUELTO
 * ============================================================================
 *
 * Porque el orden de los campos y cuales se ven forman parte del formulario
 * &mdash;es el papel que rellena la clienta&mdash;. Editar campo a campo
 * obligaria a confirmar cada cambio y a volver a entrar para seguir, y a mitad
 * de camino la pantalla ya no se pareceria en nada a lo que se va a imprimir.
 * Aqui se ven los siete a la vez, con su orden y su obligatoriedad, y hay un
 * solo boton.
 *
 * ============================================================================
 * POR QUE SE PUEDEN QUITAR CAMPOS
 * ============================================================================
 *
 * Porque no tienen historial. La participacion guarda una copia de lo que se
 * escribio en una columna propia, de modo que quitar un campo del formulario no
 * cambia lo que ya se recogio. Eso es lo que hace posible dejar de pedir el DNI
 * a mitad de campana sin romper nada de lo anterior.
 *
 * @var string                          $titulo  Titulo de la pantalla.
 * @var array<string, mixed>            $campana Fila de la campana.
 * @var array<int, array<string, mixed>> $campos  Campos del formulario.
 * @var array<string, string>           $errores Errores por campo, si los hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;
use App\Models\CampoFormulario;
use App\Services\ConfiguracionPromocion;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$errores = $errores ?? [];
$campoError = static fn (string $campo): string => (string) ($errores[$campo] ?? '');

// Los siete campos que el apartado 4.7 da por obligatorios en toda campana.
$obligatorios = ConfiguracionPromocion::CAMPOS_OBLIGATORIOS;
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Formulario
</p>

<?php if ($campoError('campos') !== ''): ?>
    <p class="aviso aviso-error" role="alert"><?= Vista::e($campoError('campos')) ?></p>
<?php endif; ?>

<p class="ayuda">
    Guarda con un solo boton. El orden de la lista es el orden en el que se
    preguntan las cosas. La campana no se puede activar sin estos campos:
    <?= Vista::e(implode(', ', $obligatorios)) ?>.
</p>

<p class="ayuda">
    Para anadir un campo, escribelo en la fila de abajo. Para quitar uno, deja
    vacias su clave y su etiqueta y guarda. No hay nada que confirmar ni que
    marcar: una fila con clave o etiqueta a medias no se guarda, y la pantalla
    explica cual es el problema.
</p>

<form method="post" action="<?= Vista::e(Aplicacion::url($base . '/formulario')) ?>" class="formulario">
    <?= Csrf::campo() ?>

    <div class="tabla-envoltorio">
        <table class="tabla tabla-campos">
            <thead>
                <tr>
                    <th>Clave</th>
                    <th>Etiqueta</th>
                    <th>Tipo</th>
                    <th>Oblig.</th>
                    <th>Visible</th>
                    <th>Min</th>
                    <th>Max</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campos as $indice => $campo): ?>
                <?php $n = (int) $indice; ?>
            <?php
            $libre = (int) $indice === count($campos) - 1;
            $numero = $n + 1;
            ?>
                <tr class="<?= $libre ? 'fila-nueva' : '' ?>">
                    <td>
                        <input type="text" name="campo[<?= $n ?>][clave]" maxlength="40"
                               value="<?= Vista::e((string) $campo['clave']) ?>"
                               placeholder="<?= $libre ? 'nueva_clave' : '' ?>"
                               aria-label="Clave del campo <?= $numero ?>">
                    </td>
                    <td>
                        <input type="text" name="campo[<?= $n ?>][etiqueta]" maxlength="80"
                               value="<?= Vista::e((string) $campo['etiqueta']) ?>"
                               placeholder="<?= $libre ? 'Como se pregunta' : '' ?>"
                               aria-label="Etiqueta del campo <?= $numero ?>">
                    </td>
                    <td>
                        <select name="campo[<?= $n ?>][tipo]"
                                aria-label="Tipo del campo <?= $numero ?>">
                            <?php foreach (CampoFormulario::TIPOS as $tipo): ?>
                                <option value="<?= Vista::e($tipo) ?>"
                                    <?= (string) $campo['tipo'] === $tipo ? 'selected' : '' ?>>
                                    <?= Vista::e($tipo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="columna-casilla">
                        <input type="checkbox" name="campo[<?= $n ?>][obligatorio]" value="1"
                               <?= !empty($campo['obligatorio']) ? 'checked' : '' ?>
                               aria-label="Obligatorio del campo <?= $numero ?>">
                    </td>
                    <td class="columna-casilla">
                        <input type="checkbox" name="campo[<?= $n ?>][visible]" value="1"
                               <?= !empty($campo['visible']) ? 'checked' : '' ?>
                               aria-label="Visible del campo <?= $numero ?>">
                    </td>
                    <td>
                        <input type="number" name="campo[<?= $n ?>][min_largo]" min="0" max="255"
                               value="<?= Vista::e((string) ($campo['min_largo'] ?? 0)) ?>"
                               aria-label="Longitud minima del campo <?= $numero ?>">
                    </td>
                    <td>
                        <input type="number" name="campo[<?= $n ?>][max_largo]" min="1" max="255"
                               value="<?= Vista::e((string) ($campo['max_largo'] ?? 200)) ?>"
                               aria-label="Longitud maxima del campo <?= $numero ?>">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="campo-boton">
        <button type="submit" class="boton boton-principal">Guardar el formulario</button>
    </div>
</form>

<p class="ayuda">
    La ultima fila esta vacia a proposito: escribe ahi un campo nuevo y guarda.
    Si se deja en blanco no pasa nada, no se crea un campo sin nombre. Para quitar
    un campo, borra lo que haya escrito y guarda.
</p>
