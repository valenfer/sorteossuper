<?php

/**
 * Formulario de datos generales de una campana.
 *
 * @var string                   $titulo   Titulo de la pantalla.
 * @var int|null                 $id       Campana a editar, o null si es nueva.
 * @var array<string, mixed>     $campana  Fila de la campana, vacia si es nueva.
 * @var array<string, mixed>     $entrada  Valores enviados, para no perderlos.
 * @var array<string, string>    $errores  Errores por campo.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;

/**
 * Devuelve lo que se ha escrito, o lo que ya habia, o un valor por defecto.
 *
 * Se escribe como funcion suelta y no con el operador ?? en linea porque el
 * orden de las fuentes importa: primero lo que se acaba de enviar, para que un
 * formulario con errores no pierda nada; despues lo que hay en la base de
 * datos; y al final un valor por defecto para una campana nueva.
 *
 * @param array<string, mixed> $entrada  Valores enviados.
 * @param array<string, mixed> $campana  Fila de la campana.
 * @param string               $clave    Nombre del campo.
 * @param string               $defecto  Valor si no hay nada de donde sacarlo.
 *
 * @return string Valor a pintar en el campo.
 */
$valor = static function (string $clave, string $defecto = '') use ($entrada, $campana): string {
    if (array_key_exists($clave, $entrada)) {
        return (string) $entrada[$clave];
    }

    if (array_key_exists($clave, $campana) && $campana[$clave] !== null) {
        return (string) $campana[$clave];
    }

    return $defecto;
};

$error = static fn (string $campo): string => (string) ($errores[$campo] ?? '');
$accion = $id === null
    ? Aplicacion::url('admin/campanas/nueva')
    : Aplicacion::url('admin/promociones/' . $id . '/editar');
?>

<h1><?= Vista::e($titulo) ?></h1>

<?php if ($id === null): ?>
    <p class="ruta-migas">
        <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
        <span aria-hidden="true">&rsaquo;</span>
        Nueva
    </p>
<?php else: ?>
    <p class="ruta-migas">
        <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Campanas</a>
        <span aria-hidden="true">&rsaquo;</span>
        <a href="<?= Vista::e(Aplicacion::url('admin/promociones/' . $id)) ?>">
            <?= Vista::e((string) $campana['nombre']) ?>
        </a>
        <span aria-hidden="true">&rsaquo;</span>
        Datos
    </p>
<?php endif; ?>

<?php if ($errores !== []): ?>
    <p class="aviso aviso-error" role="alert">
        Hay <?= count($errores) ?> <?= count($errores) === 1 ? 'cosa que' : 'cosas que' ?> revisar.
        Lo que habias escrito se ha quedado.
    </p>
<?php endif; ?>

<form method="post" action="<?= Vista::e($accion) ?>" class="formulario">
    <?= Csrf::campo() ?>

    <div class="campo">
        <label for="nombre">Nombre de la campana</label>
        <input type="text" id="nombre" name="nombre" maxlength="120" required
               value="<?= Vista::e($valor('nombre')) ?>"
               <?= $error('nombre') !== '' ? 'aria-invalid="true"' : '' ?>>
        <?php if ($error('nombre') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('nombre')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label for="descripcion">Descripcion</label>
        <textarea id="descripcion" name="descripcion" rows="3" maxlength="2000"><?= Vista::e($valor('descripcion')) ?></textarea>
        <span class="campo-ayuda">Opcional. Se usa en la pantalla de la azafata.</span>
        <?php if ($error('descripcion') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('descripcion')) ?></span>
        <?php endif; ?>
    </div>

    <h2 class="titulo-seccion">Comercio</h2>

    <div class="campo">
        <label for="comercio_nombre">Nombre</label>
        <input type="text" id="comercio_nombre" name="comercio_nombre" maxlength="150"
               value="<?= Vista::e($valor('comercio_nombre')) ?>">
        <?php if ($error('comercio_nombre') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('comercio_nombre')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label for="comercio_cif">CIF</label>
        <input type="text" id="comercio_cif" name="comercio_cif" maxlength="20"
               value="<?= Vista::e($valor('comercio_cif')) ?>">
        <span class="campo-ayuda">Se guarda en mayusculas.</span>
        <?php if ($error('comercio_cif') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('comercio_cif')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label for="comercio_domicilio">Domicilio</label>
        <input type="text" id="comercio_domicilio" name="comercio_domicilio" maxlength="200"
               value="<?= Vista::e($valor('comercio_domicilio')) ?>">
        <?php if ($error('comercio_domicilio') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('comercio_domicilio')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label for="comercio_telefono">Telefono</label>
        <input type="tel" id="comercio_telefono" name="comercio_telefono" maxlength="30"
               value="<?= Vista::e($valor('comercio_telefono')) ?>">
        <?php if ($error('comercio_telefono') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('comercio_telefono')) ?></span>
        <?php endif; ?>
    </div>

    <h2 class="titulo-seccion">Fechas y zona horaria</h2>

    <div class="campo">
        <label for="fecha_inicio">Fecha de inicio</label>
        <input type="date" id="fecha_inicio" name="fecha_inicio" required
               value="<?= Vista::e($valor('fecha_inicio')) ?>">
        <?php if ($error('fecha_inicio') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('fecha_inicio')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label for="fecha_fin">Fecha de fin</label>
        <input type="date" id="fecha_fin" name="fecha_fin"
               value="<?= Vista::e($valor('fecha_fin')) ?>">
        <span class="campo-ayuda">Opcional. Si se deja vacia, la campana no caduca.</span>
        <?php if ($error('fecha_fin') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('fecha_fin')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo">
        <label for="zona_horaria">Zona horaria</label>
        <input type="text" id="zona_horaria" name="zona_horaria" list="zonas"
               value="<?= Vista::e($valor('zona_horaria', 'Europe/Madrid')) ?>">
        <datalist id="zonas">
            <option value="Europe/Madrid"></option>
            <option value="Europe/Lisbon"></option>
            <option value="Europe/Paris"></option>
            <option value="America/Bogota"></option>
        </datalist>
        <span class="campo-ayuda">
            Los tramos se cuentan en esta zona. Cambiarla despues de generar el
            calendario no lo recoloca.
        </span>
        <?php if ($error('zona_horaria') !== ''): ?>
            <span class="campo-error"><?= Vista::e($error('zona_horaria')) ?></span>
        <?php endif; ?>
    </div>

    <div class="campo-boton">
        <button type="submit" class="boton boton-principal boton-ancho">
            <?= $id === null ? 'Crear la campana' : 'Guardar' ?>
        </button>
    </div>
</form>

<?php if ($id !== null): ?>
    <p class="ayuda">
        Guardar aqui no cambia el estado de la campana. Si esta activa, se queda
        activa; si es borrador, sigue siendo borrador.
    </p>
<?php endif; ?>
