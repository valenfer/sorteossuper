<?php

/**
 * Ajustes de la campana: correo, simulacion, loteria y retencion.
 *
 * ============================================================================
 * POR QUE ESTOS CAMPOS NO ESTAN EN LOS DATOS GENERALES
 * ============================================================================
 *
 * Por la misma razon que las reglas: son cosas que se editan sueltas, en otro
 * momento, y no tiene sentido arrastrarlas por el formulario de datos. Y porque
 * son las que mas se tocan durante una campana: el modo simulacion se enciende
 * para probar, la retencion se cambia cuando alguien pregunta por sus datos, y
 * el texto del correo se retoca cuando el commerce protesta por una palabra.
 *
 * @var string                   $titulo  Titulo de la pantalla.
 * @var array<string, mixed>     $campana Fila de la campana.
 * @var array<string, mixed>     $ajustes Ajustes, con lo guardado.
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
$valor = static fn (string $clave, string $defecto = '') => (string) ($ajustes[$clave] ?? $defecto);
$interruptor = static fn (string $clave): bool => (bool) ($ajustes[$clave] ?? false);
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Ajustes
</p>

<form method="post" action="<?= Vista::e(Aplicacion::url($base . '/ajustes')) ?>" class="formulario">
    <?= Csrf::campo() ?>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Como se comporta la campana</h2>

        <div class="regla">
            <div class="campo-casilla">
                <input type="checkbox" id="modo_simulacion" name="modo_simulacion" value="1"
                       <?= $interruptor('modo_simulacion') ? 'checked' : '' ?>>
                <label for="modo_simulacion">Modo simulacion</label>
            </div>
            <p class="ayuda">
                No se manda ningun correo, los premios no salen de la caja y nada
                aparece en el historico de participaciones. Es lo que hay que
                tener puesto al montar la campana antes de abrirla al publico.
            </p>
            <?php if ($error('modo_simulacion') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('modo_simulacion')) ?></span>
            <?php endif; ?>
        </div>

        <div class="regla">
            <div class="campo-casilla">
                <input type="checkbox" id="loteria_persiste" name="loteria_persiste" value="1"
                       <?= $interruptor('loteria_persiste') ? 'checked' : '' ?>>
                <label for="loteria_persiste">El sorteo se queda aunque se corte la luz</label>
            </div>
            <p class="ayuda">
                Se guarda en cuanto hay un sorteo bueno, antes de avisar a nadie.
                Si un apagón deja el sorteo a medias, al volver se continúa con el
                guardado y no se vuelve a sortear. Si esta apagada y se corta la
                luz, se empieza otra vez desde el principio.
            </p>
            <?php if ($error('loteria_persiste') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('loteria_persiste')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="retencion_dias">Dias que se guardan los datos de quien participa</label>
            <input type="number" id="retencion_dias" name="retencion_dias" min="0" max="3650"
                   value="<?= Vista::e($valor('retencion_dias', '0')) ?>">
            <span class="campo-ayuda">
                Pasados estos dias, los datos se pueden borrar. Vacio o 0 significa
                que no se borran nunca.
            </span>
            <?php if ($error('retencion_dias') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('retencion_dias')) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Correo a quien gana</h2>

        <div class="campo">
            <label for="correo_ganador_asunto">Asunto</label>
            <input type="text" id="correo_ganador_asunto" name="correo_ganador_asunto" maxlength="150"
                   value="<?= Vista::e($valor('correo_ganador_asunto')) ?>">
            <?php if ($error('correo_ganador_asunto') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('correo_ganador_asunto')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="correo_ganador_cuerpo">Cuerpo</label>
            <textarea id="correo_ganador_cuerpo" name="correo_ganador_cuerpo"
                      rows="6"><?= Vista::e($valor('correo_ganador_cuerpo')) ?></textarea>
            <span class="campo-ayuda">
                Se pueden poner estas marcas, que se sustituyen solas:
                <code>{{nombre}}</code>, <code>{{premio}}</code>, <code>{{comercio}}</code>,
                <code>{{codigo}}</code>.
            </span>
            <?php if ($error('correo_ganador_cuerpo') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('correo_ganador_cuerpo')) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Correo a quien no gana</h2>

        <div class="campo">
            <label for="correo_no_ganador_asunto">Asunto</label>
            <input type="text" id="correo_no_ganador_asunto" name="correo_no_ganador_asunto" maxlength="150"
                   value="<?= Vista::e($valor('correo_no_ganador_asunto')) ?>">
            <?php if ($error('correo_no_ganador_asunto') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('correo_no_ganador_asunto')) ?></span>
            <?php endif; ?>
        </div>

        <div class="campo">
            <label for="correo_no_ganador_cuerpo">Cuerpo</label>
            <textarea id="correo_no_ganador_cuerpo" name="correo_no_ganador_cuerpo"
                      rows="6"><?= Vista::e($valor('correo_no_ganador_cuerpo')) ?></textarea>
            <span class="campo-ayuda">
                Las mismas marcas que en el correo de ganadoras.
            </span>
            <?php if ($error('correo_no_ganador_cuerpo') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('correo_no_ganador_cuerpo')) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <div class="campo-boton">
        <button type="submit" class="boton boton-principal boton-ancho">Guardar los ajustes</button>
    </div>
</form>
