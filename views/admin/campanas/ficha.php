<?php

/**
 * Ficha de la campana: resumen, lo que falta y menu de configuracion.
 *
 * ============================================================================
 * POR QUE ESTA PANTALLA ES EL CENTRO DEL PANEL
 * ============================================================================
 *
 * Configurar una campana son ocho formularios. Si el punto de partida fuese el
 * listado, en cada paso habria que acordarse de cual era el siguiente, y el
 * resultado seria una campana a medio montar sin que nada lo diga. Aqui se ve
 * de un vistazo cuanto hay de cada cosa, que falta para poder activarla, y que
 * decisiones quedan por tomar.
 *
 * La lista de pendientes no es decorativa: es la misma lista que devuelve
 * \App\Services\ConfiguracionPromocion::pendientesDeActivar(), la que bloquea
 * el boton de activar. Que coincidan no es casualidad, es el mismo metodo, y por
 * eso el boton nunca se puede pulsar en una campana que todavia no esta lista:
 * no hay forma de saltarse la revision escribiendo la URL.
 *
 * @var string                          $titulo      Nombre de la campana.
 * @var array<string, mixed>            $campana     Fila de la campana.
 * @var array<string, mixed>            $resumen     Totales y reglas.
 * @var array<int, string>              $pendientes  Lo que impide activar.
 * @var array<int, string>              $avisos      Lo que hay que saber, pero
 *                                                  no bloquea.
 * @var array<int, array<string,mixed>> $comparacion Plan frente a calendario.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;
use App\Models\Promocion;

$id = (int) $campana['id'];
$base = 'admin/promociones/' . $id;
$desajustes = array_values(array_filter($comparacion, static fn (array $f): bool => (bool) $f['cambia']));
?>

<h1><?= Vista::e((string) $campana['nombre']) ?></h1>

<p class="ruta-migas">
    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
    <span aria-hidden="true">&rsaquo;</span>
    <?= Vista::e((string) $campana['nombre']) ?>
</p>

<?php
$error = Vista::aviso('error');
$aviso = Vista::aviso('aviso');
$exito = Vista::aviso('exito');
?>

<?php if ($error !== ''): ?>
    <p class="aviso aviso-error" role="alert"><?= Vista::e($error) ?></p>
<?php endif; ?>

<?php if ($aviso !== ''): ?>
    <p class="aviso aviso-aviso" role="status"><?= Vista::e($aviso) ?></p>
<?php endif; ?>

<?php if ($exito !== ''): ?>
    <p class="aviso aviso-exito" role="status"><?= Vista::e($exito) ?></p>
<?php endif; ?>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Resumen</h2>

    <dl class="lista-datos lista-datos-compacta">
        <dt>Estado</dt>
        <dd><?= Vista::e((string) $campana['estado']) ?></dd>

        <dt>Comercio</dt>
        <dd><?= Vista::e((string) ($campana['comercio_nombre'] ?? '')) ?></dd>

        <dt>Fechas</dt>
        <dd>
            <?= Vista::e((string) $campana['fecha_inicio']) ?>
            <?php if ((string) $campana['fecha_fin'] !== ''): ?>
                &ndash; <?= Vista::e((string) $campana['fecha_fin']) ?>
            <?php endif; ?>
        </dd>

        <dt>Zona horaria</dt>
        <dd><?= Vista::e((string) $campana['zona_horaria']) ?></dd>

        <dt>Tramos</dt>
        <dd><?= (int) ($resumen['tramos'] ?? 0) ?></dd>

        <dt>Premios activos</dt>
        <dd><?= (int) ($resumen['tipos_activos'] ?? 0) ?> de <?= (int) ($resumen['tipos'] ?? 0) ?></dd>

        <dt>Campos del formulario</dt>
        <dd><?= (int) ($resumen['campos'] ?? 0) ?></dd>

        <dt>Premios en el plan</dt>
        <dd><?= (int) ($resumen['total_plan'] ?? 0) ?></dd>
    </dl>

    <?php if ((bool) ($campana['modo_simulacion'] ?? false)): ?>
        <p class="aviso aviso-aviso">
            <strong>Modo simulacion.</strong>
            No se manda ningun correo y los premios no salen de la caja. Esta
            campana no puede sortear de verdad mientras siga asi.
        </p>
    <?php endif; ?>
</section>

<section class="tarjeta-panel">
    <h2 class="tarjeta-panel-titulo">Antes de activar</h2>

    <?php if ($pendientes === []): ?>
        <p class="aviso aviso-exito">
            No falta nada. La campana esta lista para activar.
        </p>

        <?php if ((string) $campana['estado'] !== Promocion::ESTADO_ACTIVA): ?>
            <form method="post" action="<?= Vista::e(Aplicacion::url($base . '/activar')) ?>">
                <?= Csrf::campo() ?>
                <button type="submit" class="boton boton-principal boton-ancho">
                    Activar la campana
                </button>
            </form>
        <?php else: ?>
            <p class="aviso aviso-exito">La campana esta activa.</p>
        <?php endif; ?>
    <?php else: ?>
        <p>Todavia falta esto:</p>
        <ul class="lista-problemas">
            <?php foreach ($pendientes as $pendiente): ?>
                <li><?= Vista::e($pendiente) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="ayuda">
            El boton de activar no aparece hasta que la lista este vacia. No se
            puede saltar: la revision se hace en el servidor, no en la pantalla.
        </p>
    <?php endif; ?>

    <?php if ($avisos !== []): ?>
        <p class="ayuda">Y esto conviene saberlo, aunque no impide activar:</p>
        <ul class="lista-avisos">
            <?php foreach ($avisos as $aviso): ?>
                <li><?= Vista::e($aviso) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($desajustes !== []): ?>
    <section class="tarjeta-panel">
        <h2 class="tarjeta-panel-titulo">El calendario ya no es el que se planeo</h2>

        <p>
            Estas cantidades no cuadran con el plan. Puede que se haya retirado un
            premio, o que se haya anadido a mano. Se deja como esta, porque el
            historial manda: lo que se ha retirado no vuelve.
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
                <?php foreach ($desajustes as $fila): ?>
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
        </p>
    </section>
<?php endif; ?>

<nav class="menu-panel" aria-label="Configuracion de la campana">
    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/editar')) ?>">
        <span class="tarjeta-menu-titulo">Datos</span>
        <span class="tarjeta-menu-texto">Nombre, comercio, fechas</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/premios')) ?>">
        <span class="tarjeta-menu-titulo">Premios</span>
        <span class="tarjeta-menu-texto">Que se reparte</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/tramos')) ?>">
        <span class="tarjeta-menu-titulo">Tramos y cantidades</span>
        <span class="tarjeta-menu-texto">Cuando y cuantos</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/calendario')) ?>">
        <span class="tarjeta-menu-titulo">Calendario</span>
        <span class="tarjeta-menu-texto">Reparto por minuto</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/formulario')) ?>">
        <span class="tarjeta-menu-titulo">Formulario</span>
        <span class="tarjeta-menu-texto">Lo que rellena la clienta</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/reglas')) ?>">
        <span class="tarjeta-menu-titulo">Reglas</span>
        <span class="tarjeta-menu-texto">Una por persona, ticket, codigo</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/apariencia')) ?>">
        <span class="tarjeta-menu-titulo">Apariencia</span>
        <span class="tarjeta-menu-texto">Colores, banners, textos</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/ajustes')) ?>">
        <span class="tarjeta-menu-titulo">Ajustes</span>
        <span class="tarjeta-menu-texto">Correo, simulacion, retencion</span>
    </a>

    <a class="tarjeta-menu" href="<?= Vista::e(Aplicacion::url($base . '/seguimiento')) ?>">
        <span class="tarjeta-menu-titulo">Seguimiento</span>
        <span class="tarjeta-menu-texto">Como va y cerrar la campana</span>
    </a>
</nav>
