<?php

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;
use App\Models\CampoFormulario;
use App\Services\ConfiguracionPromocion;

/** Formulario de participación de la campaña.
 *
 * Solicita los datos de contacto necesarios para registrar una participación.
 * Los campos obligatorios vienen definidos en la configuración de la promoción.
 *
 * @var string                          $titulo  Título de la pantalla.
 * @var array<string, mixed>            $campana Datos de la campaña.
 * @var array<int, array<string, mixed>> $campos  Campos del formulario (opcional).
 */

?><h1><?php echo htmlspecialchars($titulo); ?></h1>

<form method="post" action="admin/promociones/<?php echo $campana['id']; ?>/participar">
  <?php Csrf::oculto(); ?>

  <div>
    <label for="dni">DNI</label>
    <input type="text" name="dni" id="dni" required
      value="<?php echo htmlspecialchars(Aplicacion::post('dni') ?? ''); ?>"
      maxlength="9" />
  </div>

  <div>
    <label for="ticket">Número de ticket</label>
    <input type="text" name="ticket" id="ticket" required
      value="<?php echo htmlspecialchars(Aplicacion::post('ticket') ?? ''); ?>"
      maxlength="20" />
  </div>

  <div>
    <label for="email">Correo electrónico (opcional)</label>
    <input type="email" name="email" id="email"
      value="<?php echo htmlspecialchars(Aplicacion::post('email') ?? ''); ?>" />
  </div>

  <button type="submit">Participar</button>
</form>