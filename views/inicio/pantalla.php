<?php

/**
 * Pantalla provisional de destino, comun a los dos roles.
 *
 * ============================================================================
 * POR QUE ES UNA SOLA PLANTILLA Y NO DOS
 * ============================================================================
 *
 * Los dos roles veran exactamente lo mismo durante el hito 1, asi que hacer dos
 * ficheros identicos con una palabra distinta seria duplicar algo que ahora no
 * tiene ninguna razon para estar separado. Cuando el panel del administrador y
 * el del mostrador tengan contenido de verdad, esta plantilla se quedara para el
 * rol que menos cambie y la otra se separara.
 *
 * Lo que si se hace es comprobar el rol dentro de la plantilla, para que el texto
 * cambie segun quien mira. Asi la pagina no dice «panel» a una azafata.
 *
 * ============================================================================
 * POR QUE SE DICE EN VOZ ALTA QUE ESTA INCOMPLETA
 * ============================================================================
 *
 * Una pantalla vacia puede pasar por una pantalla terminada. En una campana de
 * supermercado, donde hay alguien delante mirando, es mejor que se lea que aun
 * no esta hecha. El aviso de arriba no es un adorno: es la informacion que
 * necesita la persona que la esta viendo.
 *
 * @var string     $titulo      Titulo de la pantalla.
 * @var string     $rol         Rol de la persona que la esta viendo.
 * @var bool       $provisional Siempre true durante el hito 1.
 * @var string     $error       Mensaje de error, ya vacio si no hay.
 * @var string     $aviso       Mensaje informativo, ya vacio si no hay.
 * @var string     $exito       Mensaje de confirmacion, ya vacio si no hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Vista;

?>
<section class="panel-provisional">
    <?php if ($provisional): ?>
        <p class="aviso aviso-aviso">
            <strong>Pantalla provisional.</strong>
            El esqueleto de la aplicacion esta en marcha y el acceso funciona, pero
            esta seccion se construye en los hitos siguientes. No hay nada que
            configurar todavia.
        </p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="aviso aviso-error" role="alert"><?= Vista::e($error) ?></p>
    <?php endif; ?>

    <?php if ($aviso !== ''): ?>
        <p class="aviso aviso-aviso" role="status"><?= Vista::e($aviso) ?></p>
    <?php endif; ?>

    <?php if ($exito !== ''): ?>
        <p class="aviso aviso-exito" role="status"><?= Vista::e($exito) ?></p>
    <?php endif; ?>

    <h1><?= Vista::e($titulo) ?></h1>

    <p>
        Esta sesion ha entrado como <strong><?= Vista::e($rol) ?></strong>.
    </p>

    <dl class="lista-datos">
        <dt>Base de datos</dt>
        <dd><?= Vista::e((string) \App\Core\Aplicacion::ajuste('bd.nombre', 'desconocida')) ?></dd>

        <dt>Zona horaria</dt>
        <dd><?= Vista::e((string) \App\Core\Aplicacion::ajuste('app.zona_horaria', '')) ?></dd>

        <dt>Hora de la campana</dt>
        <dd><?= Vista::e(\App\Core\Aplicacion::ahora()) ?></dd>
    </dl>

    <p>
        <a class="boton boton-sutil" href="<?= Vista::e(Aplicacion::url('login')) ?>">
            Volver a la pantalla de acceso
        </a>
    </p>
</section>
