<?php

/**
 * Reglas de participacion de la campana.
 *
 * ============================================================================
 * POR QUE ESTA PANTALLA ESTA SEPARADA DE LOS DATOS GENERALES
 * ============================================================================
 *
 * Porque son cosas distintas y las escribe gente distinta en momentos distintos.
 * El nombre y las fechas se ponen al crear la campana; las reglas se negocian
 * con el comercio, se revisan cuando la campana ya esta montada y a menudo hay
 * que volver a ellas sin tocar nada de lo demas. Meterlas en el formulario de
 * datos obligaria a dar dos vueltas por la ficha para cambiar un interruptor.
 *
 * ============================================================================
 * POR QUE ALGUNAS REGLAS PIDEN OTRO CAMPO
 * ============================================================================
 *
 * Una regla que se puede activar y que no hace nada es peor que no tenerla: la
 * gente la activa, cree que esta protegida y no lo esta. Por eso la pantalla
 * enseña de que campo depende cada regla y solo deja activarla si ese campo
 * existe en el formulario. Si el campo se borra despues, la regla avisa en vez
 * de desaparecer en silencio, que es lo que haria una casilla normal.
 *
 * @var string                          $titulo  Titulo de la pantalla.
 * @var array<string, mixed>            $campana Fila de la campana.
 * @var array<string, mixed>            $reglas  Reglas, con lo guardado.
 * @var array<int, string>              $claves  Claves del formulario.
 * @var array<string, string>           $errores Errores por campo, si los hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$errores = $errores ?? [];
$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
$regla = static fn (string $clave, $defecto = '') => $reglas[$clave] ?? $defecto;
$interruptor = static fn (string $clave): bool => (bool) ($reglas[$clave] ?? false);

/**
 * Una regla que necesita un campo del formulario.
 *
 * @var string $clave     Clave de la regla.
 * @var string $etiqueta  Nombre legible de la regla.
 * @var string $campo     Clave del campo del formulario que necesita.
 * @var string $ayuda     Explicacion de para que sirve.
 */
$conCampo = static function (string $clave, string $etiqueta, string $campo, string $ayuda) use ($interruptor, $claves, $error): void {
    $existe = in_array($campo, $claves, true);
    $marcada = $interruptor($clave);
    ?>
    <div class="regla">
        <div class="campo-casilla">
            <input type="checkbox" id="<?= Vista::e($clave) ?>" name="<?= Vista::e($clave) ?>" value="1"
                   <?= $marcada ? 'checked' : '' ?>
                   <?= $marcada && !$existe ? 'aria-describedby="aviso-' . Vista::e($clave) . '"' : '' ?>>
            <label for="<?= Vista::e($clave) ?>"><?= Vista::e($etiqueta) ?></label>
        </div>
        <p class="campo-ayuda">
            Necesita el campo <code><?= Vista::e($campo) ?></code>
            <?php if ($existe): ?>
                del formulario.
            <?php else: ?>
                del formulario, <strong>que ahora mismo no existe</strong>.
            <?php endif; ?>
        </p>
        <p class="ayuda"><?= Vista::e($ayuda) ?></p>
        <?php if ($marcada && !$existe): ?>
            <p class="aviso aviso-aviso" id="aviso-<?= Vista::e($clave) ?>">
                Activada pero sin campo: no va a tener ningun efecto.
            </p>
        <?php endif; ?>
        <?php if ($error($clave) !== ''): ?>
            <span class="campo-error"><?= Vista::e($error($clave)) ?></span>
        <?php endif; ?>
    </div>
    <?php
};

/**
 * Una regla que no depende de ningun campo del formulario.
 *
 * @param string $clave    Clave de la regla.
 * @param string $etiqueta Nombre legible de la regla.
 * @param string $ayuda    Explicacion de para que sirve.
 */
$simple = static function (string $clave, string $etiqueta, string $ayuda) use ($interruptor, $error): void {
    ?>
    <div class="regla">
        <div class="campo-casilla">
            <input type="checkbox" id="<?= Vista::e($clave) ?>" name="<?= Vista::e($clave) ?>" value="1"
                   <?= $interruptor($clave) ? 'checked' : '' ?>>
            <label for="<?= Vista::e($clave) ?>"><?= Vista::e($etiqueta) ?></label>
        </div>
        <p class="ayuda"><?= Vista::e($ayuda) ?></p>
        <?php if ($error($clave) !== ''): ?>
            <span class="campo-error"><?= Vista::e($error($clave)) ?></span>
        <?php endif; ?>
    </div>
    <?php
};
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?= Vista::e(Aplicacion::url($base)) ?>"><?= Vista::e((string) $campana['nombre']) ?></a>
    <span aria-hidden="true">&rsaquo;</span>
    Reglas
</p>

<form method="post" action="<?= Vista::e(Aplicacion::url($base . '/reglas')) ?>" class="formulario">
    <?= Csrf::campo() ?>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Cuantas veces se puede participar</h2>

        <?php $simple(
            'una_por_ticket',
            'Una participacion por ticket',
            'Con el mismo ticket no se puede jugar dos veces. El ticket tiene que estar'
            . ' dado de alta en el sistema.'
        ); ?>

        <?php $conCampo(
            'una_por_persona',
            'Una participacion por persona',
            'dni',
            'Dos participaciones con el mismo DNI cuentan como una. Si no hay campo DNI en el'
            . ' formulario, esta regla no hace nada.'
        ); ?>

        <?php $simple(
            'sin_ticket',
            'Permitir participar sin ticket',
            'Si esta apagada, sin un ticket de los dados de alta no se puede participar.'
            . ' Con las dos reglas de ticket activadas a la vez, esta manda.'
        ); ?>

        <div class="regla">
            <div class="campo-casilla">
                <input type="checkbox" id="verificar_ticket" name="verificar_ticket" value="1"
                       <?= $interruptor('verificar_ticket') ? 'checked' : '' ?>>
                <label for="verificar_ticket">Verificar el ticket en el momento</label>
            </div>
            <p class="ayuda">
                Se comprueba que el ticket existe al apuntar, y no solo cuando se
                recoge el premio. Es la forma de detectar pronto un ticket falso.
            </p>
            <?php if ($interruptor('verificar_ticket') && !$interruptor('sin_ticket')): ?>
                <div class="campo">
                    <label for="tickets_validos">Tickets que valen</label>
                    <input type="text" id="tickets_validos" name="tickets_validos"
                           value="<?= Vista::e((string) $regla('tickets_validos', '')) ?>"
                           placeholder="TICKET-A, TICKET-B">
                    <span class="campo-ayuda">
                        Separados por comas. Opcional: si se deja vacio, vale
                        cualquier ticket dado de alta.
                    </span>
                    <?php if ($error('tickets_validos') !== ''): ?>
                        <span class="campo-error"><?= Vista::e($error('tickets_validos')) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Codigos de acceso</h2>

        <?php $simple(
            'codigo_obligatorio',
            'Pedir un codigo para participar',
            'Sin el codigo correcto no se puede apuntar. Se escribe en la'
            . ' pantalla de la azafata y se enseña al público.'
        ); ?>

        <div class="campo">
            <label for="codigo_validez">Dias que dura el codigo</label>
            <input type="number" id="codigo_validez" name="codigo_validez" min="0" max="365"
                   value="<?= Vista::e((string) $regla('codigo_validez', '0')) ?>">
            <span class="campo-ayuda">0 significa que no caduca.</span>
            <?php if ($error('codigo_validez') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('codigo_validez')) ?></span>
            <?php endif; ?>
        </div>

        <?php $conCampo(
            'codigo_por_campo',
            'Un codigo distinto por codigo postal',
            'cp',
            'Cada zona tiene su codigo. Util cuando el premio va a Sortea Suya y no a la'
            . ' tienda de al lado.'
        ); ?>

        <div class="campo">
            <label for="codigos_postales">Codigos por codigo postal</label>
            <textarea id="codigos_postales" name="codigos_postales" rows="4"
                      placeholder="28001=MADRID-2026&#10;28002=MADRID-2026"><?= Vista::e((string) $regla('codigos_postales', '')) ?></textarea>
            <span class="campo-ayuda">
                Una linea por codigo postal: <code>CP=CODIGO</code>.
            </span>
            <?php if ($error('codigos_postales') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('codigos_postales')) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">Avisos al público</h2>

        <?php $simple(
            'aviso_sin_premio',
            'Avisar cuando ya no queda ningun premio',
            'En vez de dejar apuntar sin premio, se avisa y la participacion no se'
            . ' registra. Util al final de una campana.'
        ); ?>

        <div class="campo">
            <label for="mensaje_sin_premio">Texto del aviso</label>
            <input type="text" id="mensaje_sin_premio" name="mensaje_sin_premio" maxlength="200"
                   value="<?= Vista::e((string) $regla('mensaje_sin_premio', '')) ?>">
            <?php if ($error('mensaje_sin_premio') !== ''): ?>
                <span class="campo-error"><?= Vista::e($error('mensaje_sin_premio')) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <div class="campo-boton">
        <button type="submit" class="boton boton-principal boton-ancho">Guardar las reglas</button>
    </div>
</form>
