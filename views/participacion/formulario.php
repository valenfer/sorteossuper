<?php

/**
 * Pantalla 1 del apartado 5: formulario de participacion.
 *
 * ============================================================================
 * POR QUE LOS CAMPOS SE PINTAN EN BUCLE Y NO ESCRITOS UNO A UNO
 * ============================================================================
 *
 * El formulario es lo que el administrador ha configurado en el panel, y puede
 * ser cualquiera de los ocho campos obligatorios del apartado 4.8 mas los que
 * anada. Escribirlos aqui uno a uno obligaria a cambiar esta vista cada vez que
 * se anadiera un campo, y el olvido seria silencioso: la campana pediria un
 * dato en el panel y el formulario no lo pediria, y la participacion pasaria sin
 * el. Con el bucle, lo que se ve es exactamente lo que la campana declara.
 *
 * ============================================================================
 * POR QUE EL ACTION LO PONE EL CONTROLADOR
 * ============================================================================
 *
 * El action se recibe en «destino» y se construye con la ruta de la que se ha
 * llegado. Escribir aqui una ruta fija fue el error que dejo el formulario
 * apuntando a un sitio que no existia, porque una ruta relativa sin barra inicial
 * se resuelve contra el directorio de la pagina actual y no contra la raiz: desde
 * /admin/promociones/7/premios, «admin/promociones/7/participar».seek no existe.
 * Con Aplicacion::url() la ruta es absoluta y no depende de donde se este.
 *
 * ============================================================================
 * EL CAMPO OCULTO DE IDEMPOTENCIA
 * ============================================================================
 *
 * Es la garantia del caso de aceptacion 7. Se genera al pintar el formulario y
 * viaja con cada envio; si la azafata pulsa dos veces o recarga, el motor
 * reconoce que es el mismo intento y devuelve el mismo resultado en vez de
 * entregar un segundo premio. Va oculto porque no es un dato que la azafata
 * tenga que entender, pero no por descuido.
 *
 * @var string                          $titulo       Titulo de la pantalla.
 * @var array<string, mixed>            $campana      Fila de la campana.
 * @var array<int, array<string, mixed>> $campos      Campos visibles, ya filtrados.
 * @var array<string, mixed>|null        $tramo        Tramo vigente, o null.
 * @var array<string, mixed>            $reglas       Reglas de la campana.
 * @var array<string, mixed>            $visual       Apariencia configurada.
 * @var string                          $idempotencia Identificador del intento.
 * @var string                          $csrf         Campo de token CSRF.
 * @var string                          $destino      Ruta a la que se envia.
 * @var string                          $aviso        Aviso de una sola vez, o vacio.
 * @var string                          $error        Error de una sola vez, o vacio.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Vista;

$campos = $campos ?? [];
$tramo = $tramo ?? null;
$reglas = $reglas ?? [];
$visual = $visual ?? [];
$aviso = $aviso ?? '';
$error = $error ?? '';
$accion = Aplicacion::url($destino);
$varios = (int) ($reglas['exigir_codigo'] ?? 0) === 1
    || (int) ($reglas['una_por_campana'] ?? 0) === 1
    || (int) ($reglas['una_por_dia'] ?? 0) === 1
    || (int) ($reglas['una_por_ticket'] ?? 0) === 1
    || (int) ($reglas['una_por_dni'] ?? 0) === 1
    || (int) ($reglas['verificar_ticket'] ?? 0) === 1;
?>

<?php /* Los banners van fuera del «section.panel» a proposito: el cartel de
       arriba es lo primero que ve la clienta al llegar y el del pie es lo
       ultimo que lee al enviar, y ninguno de los dos es parte del formulario.
       Si estuvieran dentro, el h1 dejaria de ser lo primero que aparece en
       pantalla de arriba abajo, que es lo que hace que un lector de pantalla
       salte directamente al contenido. */
$bannerSup = trim((string) ($visual['banner_sup_ruta'] ?? ''));
$bannerSupAlt = trim((string) ($visual['banner_sup_alt'] ?? ''));
$bannerPie = trim((string) ($visual['banner_pie_ruta'] ?? ''));
$bannerPieAlt = trim((string) ($visual['banner_pie_alt'] ?? ''));

// El texto alternativo no puede quedar vacio: una imagen que solo dice
// «imagen» no le sirve a nadie, y si la administratora no lo ha escrito se cae al
// nombre del comercio en vez de dejar el hueco sin rellenar, que es peor.
$comercio = trim((string) ($campana['comercio_nombre'] ?? ''));

if ($bannerSupAlt === '') {
    $bannerSupAlt = $comercio !== '' ? $comercio : 'Promocion';
}

if ($bannerPieAlt === '') {
    $bannerPieAlt = $bannerSupAlt;
}
?>

<?php if ($bannerSup !== ''): ?>
    <img
        class="banner banner-superior"
        src="<?= Vista::e(Aplicacion::subida($bannerSup)) ?>"
        alt="<?= Vista::e($bannerSupAlt) ?>">
<?php endif; ?>

<section class="panel">
    <h1><?= Vista::e($titulo) ?></h1>

    <?php if ($error !== ''): ?>
        <p class="aviso aviso-error" role="alert"><?= Vista::e($error) ?></p>
    <?php endif; ?>

    <?php if ($aviso !== ''): ?>
        <p class="aviso aviso-info"><?= Vista::e($aviso) ?></p>
    <?php endif; ?>

    <?php if ($tramo === null): ?>
        <?php /* Fuera de horario se avisa, pero el formulario se deja pintar: la
               azafata puede estar anotando a alguien que vuelve mas tarde, y
               quitando el boton se leeria «no puede participar» sin explicacion. */ ?>
        <p class="aviso aviso-error" role="alert">
            Ahora mismo no estamos en horario de participacion.
        </p>
    <?php else: ?>
        <p class="ayuda">Turno de hoy, de <?= Vista::e((string) $tramo['hora_inicio']) ?>
            a <?= Vista::e((string) $tramo['hora_fin']) ?>.</p>
    <?php endif; ?>

    <form method="post" action="<?= Vista::e($accion) ?>">
        <?= $csrf ?>

        <input type="hidden" name="idempotencia" value="<?= Vista::e($idempotencia) ?>">

        <?php foreach ($campos as $campo): ?>
            <?php
            $clave = (string) $campo['clave'];
            $tipo = (string) $campo['tipo'];
            $obligatorio = (int) $campo['obligatorio'] === 1;
            $etiqueta = (string) $campo['etiqueta'];
            // El tipo de la columna decide el input de HTML, no como se valida el
            // dato. Es la diferencia entre escribir un correo con el teclado
            // correcto en la tablet y acabar peleandose con el aparecidor de
            // teclado cada vez que se teclea una arroba.
            //
            // «telefono» y «texto» son tipos de la columna, no del HTML: el input
            // que existe para un telefono se llama «tel», y para un texto libre
            // no hay tipo, se deja el que viene por defecto. Un tipo inventado
            // aqui no da error, simplemente el navegador lo ignora y cae al
            // «text» sin avisar.
            $entrada = [
                'email'    => 'email',
                'telefono' => 'tel',
                'entero'   => 'number',
                'fecha'    => 'date',
            ][$tipo] ?? 'text';
            ?>
            <p class="campo">
                <label for="campo-<?= Vista::e($clave) ?>">
                    <?= Vista::e($etiqueta) ?><?= $obligatorio ? ' *' : '' ?>
                </label>
                <?php if ($tipo === 'area'): ?>
                    <textarea
                        id="campo-<?= Vista::e($clave) ?>"
                        name="<?= Vista::e($clave) ?>"
                        <?= $obligatorio ? 'required' : '' ?>
                        maxlength="<?= (int) ($campo['max_largo'] ?? 255) ?>"></textarea>
                <?php else: ?>
                    <input
                        type="<?= Vista::e($entrada) ?>"
                        id="campo-<?= Vista::e($clave) ?>"
                        name="<?= Vista::e($clave) ?>"
                        value="<?= Vista::e((string) ($campo['valor_por_defecto'] ?? '')) ?>"
                        <?= $obligatorio ? 'required' : '' ?><?= (int) ($campo['max_largo'] ?? 0) > 0
                            ? ' maxlength="' . (int) $campo['max_largo'] . '"'
                            : '' ?>>
                <?php endif; ?>
            </p>
        <?php endforeach; ?>

        <?php if ((int) ($reglas['exigir_consentimiento'] ?? 0) === 1): ?>
            <?php /* El texto lo ha escrito la campana y no se sustituye por uno
                   fijo: es el aviso de privacidad que el apartado 15 del RGPD
                   obliga a que la persona pueda leer antes de marcar. */ ?>
            <p class="campo campo-casilla">
                <label for="campo-consentimiento">
                    <input type="checkbox" id="campo-consentimiento" name="consentimiento" value="on" required>
                    <?= Vista::e((string) ($reglas['texto_consentimiento'] ?? 'Acepto el aviso de privacidad.')) ?>
                </label>
            </p>
        <?php endif; ?>

        <?php if ($varios): ?>
            <p class="ayuda">Esta promocion tiene reglas de participacion. Si algo no
                esta bien, se le dira por que, sin mostrar datos de nadie mas.</p>
        <?php endif; ?>

        <p class="acciones">
            <button type="submit" class="boton boton-principal">Participar</button>
        </p>
    </form>
</section>

<?php if ($bannerPie !== ''): ?>
    <img
        class="banner banner-pie"
        src="<?= Vista::e(Aplicacion::subida($bannerPie)) ?>"
        alt="<?= Vista::e($bannerPieAlt) ?>">
<?php endif; ?>
