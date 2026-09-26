<?php

/**
 * Listado de campanas del administrador.
 *
 * @var string                          $titulo    Titulo de la pantalla.
 * @var array<int, array<string,mixed>> $campanas  Campanas, con sus estados.
 * @var array<string, int>              $porEstado Recuento por estado.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Vista;
use App\Models\Promocion;

$estados = [
    Promocion::ESTADO_BORRADOR   => 'Borrador',
    Promocion::ESTADO_ACTIVA     => 'Activa',
    Promocion::ESTADO_FINALIZADA => 'Finalizada',
];
?>

<h1><?= Vista::e($titulo) ?></h1>

<p class="ayuda">
    Una campana nace en borrador y hay que activarla cuando esta completa. Activar
    manda el correo de aviso y saca los premios de la caja, asi que la pantalla
    revisa antes de dejar hacerlo.
</p>

<?php if ($campanas === []): ?>
    <p class="aviso aviso-aviso">
        No hay ninguna campana todavia.
    </p>
<?php endif; ?>

<p>
    <a class="boton boton-principal boton-ancho" href="<?= Vista::e(Aplicacion::url('admin/campanas/nueva')) ?>">
        Crear la primera campana
    </a>
</p>

<?php if ($campanas !== []): ?>
    <div class="tabla-envoltorio">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Campana</th>
                    <th>Estado</th>
                    <th>Fechas</th>
                    <th>Comercio</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campanas as $campana): ?>
                <tr>
                    <td>
                        <a href="<?= Vista::e(Aplicacion::url('admin/promociones/' . (int) $campana['id'])) ?>">
                            <?= Vista::e((string) $campana['nombre']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="estado estado-<?= Vista::e((string) $campana['estado']) ?>">
                            <?= Vista::e($estados[(string) $campana['estado']] ?? (string) $campana['estado']) ?>
                        </span>
                    </td>
                    <td>
                        <?= Vista::e((string) $campana['fecha_inicio']) ?>
                        <?php if ((string) $campana['fecha_fin'] !== ''): ?>
                            &ndash; <?= Vista::e((string) $campana['fecha_fin']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= Vista::e((string) ($campana['comercio_nombre'] ?? '')) ?></td>
                    <td class="columna-acciones">
                        <a href="<?= Vista::e(Aplicacion::url('admin/promociones/' . (int) $campana['id'])) ?>">
                            Abrir
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
