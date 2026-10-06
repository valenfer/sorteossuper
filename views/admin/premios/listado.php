<?php

/**
 * Lista de premios de la campana y alta de uno nuevo.
 *
 * ============================================================================
 * POR QUE NO HAY UNA PANTALLA DE EDICION POR PREMIO
 * ============================================================================
 *
 * De momento el alta y el cambio de activo cubren el uso real: se anaden los
 * premios antes de abrir la campana y despues, si alguno sobra, se desactiva en
 * lugar de borrarse. Editar el nombre o la descripcion de un premio que ya ha
 * dado unidades tiene mas consecuencias de las que parece &mdash;los correos ya
 * enviados hablan del nombre viejo&mdash; asi que se deja para cuando se vea que
 * hace falta, en vez de dejar medio construido un formulario que no se usa.
 *
 * ============================================================================
 * POR QUE UN PREMIO NUNCA SE BORRA
 * ============================================================================
 *
 * Porque ya puede tener unidades entregadas. Un DELETE dejaria participaciones
 * apuntando a un premio que no existe y el historial dejaria de cuadrar. Por eso
 * la unica accion es activar o desactivar, y desactivar no desaparece de ningun
 * sitio: sigue saliendo en la lista, marcado como inactivo.
 *
 * @var string                          $titulo  Titulo de la pantalla.
 * @var array<string, mixed>            $campana Fila de la campana.
 * @var array<int, array<string, mixed>> $premios Premios de la campana.
 * @var array<string, string>           $errores Errores por campo, si los hay.
 * @var array<string, mixed>            $entrada Valores enviados, si los hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$errores = $errores ?? [];
$entrada = $entrada ?? [];
$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Premios
</p>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Anadir premio</h2>

    <?php if ($error('imagen') !== ''): ?>
        <p class="aviso aviso-error" role="alert"><?= Vista::e($error('imagen')) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= Vista::e(Aplicacion::url($base . '/premios')) ?>"
          enctype="multipart/form-data" class="formulario">
        <?= Csrf::campo() ?>

        <div class="campo">
            <label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" maxlength="120" required
                   value="<?= Vista::e((string) ($entrada['nombre'] ?? '')) ?>">
            <?php if ($error('nombre') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('nombre')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="descripcion">Descripcion</label>
            <textarea id="descripcion" name="descripcion" rows="2"
                      maxlength="500"><?= Vista::e((string) ($entrada['descripcion'] ?? '')) ?></textarea>
            <span class="campo-ayuda">Opcional. Se enseña en la pantalla de premio.</span>
            <?php if ($error('descripcion') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('descripcion')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="imagen">Foto</label>
            <input type="file" id="imagen" name="imagen" accept="image/jpeg,image/png,image/webp">
            <span class="campo-ayuda">Opcional. JPG, PNG o WebP, hasta 2 MB.</span>
        </div>

        <div class="campo-boton">
            <button type="submit" class="boton boton-principal">Anadir premio</button>
        </div>
    </form>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Premios de la campana</h2>

    <?php if ($premios === []): ?>
        <p class="aviso aviso-aviso">
            Todavia no hay ningun premio. Sin premios no se puede generar el
            calendario.
        </p>
    <?php else: ?>
        <div class="tabla-envoltorio">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Premio</th>
                        <th>Descripcion</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($premios as $premio): ?>
                    <tr>
                        <td>
                            <?php $imagenPremio = (string) ($premio['imagen_ruta'] ?? ''); ?>
                            <?php if ($imagenPremio !== ''): ?>
                                <img class="miniatura" alt=""
                                     src="<?= Vista::e(Aplicacion::subida($imagenPremio)) ?>">
                            <?php endif; ?>
                            <?= Vista::e((string) $premio['nombre']) ?>
                        </td>
                        <td><?= Vista::e((string) ($premio['descripcion'] ?? '')) ?></td>
                        <td>
                            <?php if ((bool) $premio['activo']): ?>
                                <span class="estado estado-activa">Activo</span>
                            <?php else: ?>
                                <span class="estado estado-borrador">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="columna-acciones">
                            <form method="post"
                                  action="<?= Vista::e(Aplicacion::url($base . '/premios/' . (int) $premio['id'] . '/alternar')) ?>">
                                <?= Csrf::campo() ?>
                                <button type="submit" class="boton boton-sutil">
                                    <?= (bool) $premio['activo'] ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="ayuda">
            Desactivar no borra nada: el premio sigue en la lista y conserva las
            unidades que ya se han entregado.
        </p>
    <?php endif; ?>
</section>

<p class="ayuda">
    Las cantidades de cada premio en cada tramo se escriben en
    <a href="<?= Vista::e(Aplicacion::url($base . '/tramos')) ?>">tramos y cantidades</a>.
</p>
