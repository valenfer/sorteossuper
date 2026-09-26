<?php

/**
 * Apariencia de la campana: imagenes, textos y colores.
 *
 * ============================================================================
 * POR QUE LOS COLORES SON UN CAMPO DE COLOR Y NO UN SELECTOR
 * ============================================================================
 *
 * Porque el commerce tiene un color de marca y no es uno de los que elegimos
 * nosotros. Obligarle a elegir entre diez colores seria elegir por el cliente
 * lo que el cliente ya tiene decidido. El campo de color le deja poner el suyo,
 * y el boton de «como se ve» enseña el resultado sin tener que activar nada.
 *
 * ============================================================================
 * POR QUE LAS IMAGENES DE RESULTADO NO TIENEN ALT EDITABLE
 * ============================================================================
 *
 * Porque el texto alternativo de esas dos lo dice la pantalla, no la imagen: la
 * que se ve al ganar esta debajo del texto «enhorabuena, has ganado» y la de no
 * premio debajo del animo. Preguntar otra vez lo mismo que ya esta escrito
 * arriba obligaria a mantener dos cosas que tienen que decir lo mismo y que
 * acabarian contradicting. Los banners de la campana, en cambio, no tienen
 * texto alrededor, y por eso si lo necesitan y por eso es obligatorio.
 *
 * @var string                   $titulo  Titulo de la pantalla.
 * @var array<string, mixed>     $campana Fila de la campana.
 * @var array<string, mixed>     $visual  Apariencia, con lo guardado.
 * @var array<string, string>    $errores Errores por campo, si los hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$errores = $errores ?? [];
$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
$valor = static fn (string $clave, string $defecto = '') => (string) ($visual[$clave] ?? $defecto);
$vacio = static fn (string $clave): bool => (string) ($visual[$clave] ?? '') === '';

/**
 * Una imagen, con su vista previa de lo que hay ahora.
 *
 * @param string $ruta   Clave de la ruta guardada.
 * @param string $alt    Clave del texto alternativo, o '' si no la tiene.
 * @param string $titulo Nombre legible de la imagen.
 * @param string $ayuda  Explicacion de para que sirve.
 * @param string $altFijo Texto alternativo para las imagenes que no lo guardan.
 */
$imagen = static function (
    string $ruta,
    string $alt,
    string $titulo,
    string $ayuda,
    string $altFijo = ''
) use ($id, $base, $valor, $vacio, $error): void {
    $textoAlt = $alt === '' ? $altFijo : $valor($alt);
    ?>
    <fieldset class="grupo-imagen">
        <legend><?= Vista::e($titulo) ?></legend>
        <p class="campo-ayuda"><?= Vista::e($ayuda) ?></p>

        <?php if ($alt !== ''): ?>
            <div class="campo">
                <label for="<?= Vista::e($ruta) ?>_alt">Texto alternativo</label>
                <input type="text" id="<?= Vista::e($ruta) ?>_alt" name="<?= Vista::e($alt) ?>" maxlength="150"
                       value="<?= Vista::e($valor($alt)) ?>"
                       aria-describedby="<?= Vista::e($ruta) ?>_ayuda">
                <span class="campo-ayuda" id="<?= Vista::e($ruta) ?>_ayuda">
                    Lo que lee en voz alta quien no ve la imagen. No puede quedar
                    vacio si subes una imagen.
                </span>
                <?php if ($error($alt) !== ''): ?>
                    <span class="campo-error"><?= Vista::e($error($alt)) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$vacio($ruta)): ?>
            <div class="previa">
                <img alt="<?= Vista::e($textoAlt) ?>"
                     src="<?= Vista::e(Aplicacion::asset('uploads/' . $valor($ruta))) ?>">
                <span class="campo-ayuda">Esta es la imagen que hay ahora.</span>
            </div>
        <?php endif; ?>

        <div class="campo">
            <label for="<?= Vista::e($ruta) ?>">Cambiarla</label>
            <input type="file" id="<?= Vista::e($ruta) ?>" name="<?= Vista::e($ruta) ?>"
                   accept="image/jpeg,image/png,image/webp">
            <span class="campo-ayuda">
                <?php $vacio($ruta) ? 'Dejala vacio si no quieres ninguna.' : 'Dejala vacia para dejar la que hay.' ?>
                Al subir una nueva se sustituye la anterior.
            </span>
            <?php if ($error($ruta) !== ''): ?>
                <span class="campo-error"><?= Vista::e($error($ruta)) ?></span>
            <?php endif; ?>
        </div>
    </fieldset>
    <?php
};
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Apariencia
</p>

<form method="post" action="<?= Vista::e(Aplicacion::url($base . '/apariencia')) ?>"
      enctype="multipart/form-data" class="formulario">
    <?= Csrf::campo() ?>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Imagenes</h2>

        <?php
        $imagen(
            'banner_sup_ruta',
            'banner_sup_alt',
            'Banner de arriba',
            'Sale en la parte de arriba de la pantalla del publico.'
        );
        $imagen(
            'banner_pie_ruta',
            'banner_pie_alt',
            'Banner de abajo',
            'Sale al final, encima de los datos del comercio.'
        );
        $imagen(
            'resultado_premio_ruta',
            '',
            'Imagen de premio',
            'La que aparece en la pantalla de resultado cuando alguien gana.',
            'Premio conseguido'
        );
        $imagen(
            'resultado_no_premio_ruta',
            '',
            'Imagen de no premio',
            'La que aparece en la pantalla de resultado cuando no gana nada.',
            'Esta vez no ha habido suerte'
        );
        ?>
    </section>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Textos de resultado</h2>

        <div class="campo">
            <label for="texto_ganador">Cuando gana</label>
            <input type="text" id="texto_ganador" name="texto_ganador" maxlength="200"
                   value="<?= Vista::e($valor('texto_ganador')) ?>"
                   placeholder="Enhorabuena, has ganado">
            <?php if ($error('texto_ganador') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('texto_ganador')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="texto_no_ganador">Cuando no gana</label>
            <input type="text" id="texto_no_ganador" name="texto_no_ganador" maxlength="200"
                   value="<?= Vista::e($valor('texto_no_ganador')) ?>"
                   placeholder="Esta vez no ha habido suerte">
            <?php if ($error('texto_no_ganador') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('texto_no_ganador')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="resultado_premio_texto">Pie de la imagen de premio</label>
            <input type="text" id="resultado_premio_texto" name="resultado_premio_texto" maxlength="200"
                   value="<?= Vista::e($valor('resultado_premio_texto')) ?>">
            <?php if ($error('resultado_premio_texto') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('resultado_premio_texto')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="resultado_no_premio_texto">Pie de la imagen de no premio</label>
            <input type="text" id="resultado_no_premio_texto" name="resultado_no_premio_texto" maxlength="200"
                   value="<?= Vista::e($valor('resultado_no_premio_texto')) ?>">
            <?php if ($error('resultado_no_premio_texto') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('resultado_no_premio_texto')) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Colores</h2>

        <p class="ayuda">
            Se escriben en hexadecimal, con la almohadilla delante: <code>#14509b</code>.
            Si se cambian, se ven en la pantalla publica de la campana; el panel
            de administracion siempre mantiene su propio color para que la
            configuracion se siga viendo.
        </p>

        <div class="rejilla-colores">
            <?php
            $colores = [
                'color_fondo'    => 'Fondo',
                'color_texto'    => 'Texto',
                'color_primario' => 'Primario',
                'color_acento'   => 'Acento',
                'color_campos'   => 'Campos',
                'color_bordes'   => 'Bordes',
            ];

            foreach ($colores as $clave => $nombre):
                ?>
                <div class="campo">
                    <label for="<?= Vista::e($clave) ?>"><?= Vista::e($nombre) ?></label>
                    <input type="color" id="<?= Vista::e($clave) ?>" name="<?= Vista::e($clave) ?>"
                           value="<?= Vista::e($valor($clave, '#000000')) ?>"
                           aria-label="Color: <?= Vista::e($nombre) ?>">
                    <?php if ($error($clave) !== ''): ?>
                        <span class="campo-error"><?= Vista::e($error($clave)) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="campo-boton">
        <button type="submit" class="boton boton-principal boton-ancho">Guardar la apariencia</button>
    </div>
</form>
