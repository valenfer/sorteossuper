<?php

declare(strict_types=1);

use App\Core\Vista;

/** Pantalla de resultado de la participación.
 *
 * Muestra si la clienta ha ganado un premio, si no ha habido premio, o si la
 * participación fue rechazada y el motivo.
 *
 * @var string                                  $titulo       Título de la pantalla.
 * @var array{resultado: string, participacion_id: int|null,
 *            unidad_id: int|null, codigo_reclamacion: string|null,
 *            motivo_codigo: string|null, motivo_texto: string|null,
 *            repetido: bool, correo_id: int|null} $resultado Datos devueltos por Adjudicador.
 * @var array<string, mixed>                    $campana      Datos de la campaña.
 */

?><h1><?php echo htmlspecialchars($titulo); ?></h1>

<p>Gracias por participar. <strong><?php echo htmlspecialchars($campana['nombre']); ?></strong>.</p>

<?php if ($resultado['repetido']): ?>
  <p>Ya tenías una participación registrada con estos datos. No se ha creado una nueva.</p>
<?php elseif ($resultado['resultado'] === 'premio'): ?>
  <p>¡Felicidades! <strong>Has ganado un premio.</strong></p>
  <p><strong><?php echo htmlspecialchars($resultado['codigo_reclamacion'] ?? ''); ?></strong>
    <?php echo $resultado['motivo_texto'] ? ': ' . htmlspecialchars($resultado['motivo_texto']) : ''; ?></p>
  <p>Código de reclamación: <?php echo htmlspecialchars($resultado['codigo_reclamacion'] ?? ''); ?></p>
<?php elseif ($resultado['resultado'] === 'sin_premio'): ?>
  <p>Lamentablemente, en este momento no hay premio disponible para tu participación.</p>
<?php else: ?>
  <p>Tu participación ha sido <strong>rechazada</strong>.</p>
  <p><?php echo htmlspecialchars($resultado['motivo_texto'] ?? 'Motivo no especificado'); ?></p>
<?php endif; ?>

<p><a href="admin/promociones/<?php echo $campana['id']; ?>">Volver a la campaña</a></p>