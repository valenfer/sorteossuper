<?php

/**
 * Pantalla 3 del apartado 5: resultado de la participacion.
 *
 * ============================================================================
 * POR QUE UN RECHAZO NO ES UN ERROR
 * ============================================================================
 *
 * Un rechazo por regla es una respuesta esperada, no un fallo: la persona ha
 * escrito algo que la campana no admite, y hay que explicarselo sin asustarla.
 * Por eso esta pantalla tiene tres ramas y ninguna es un error de PHP, y por eso
 * el motivo que se enseña es el que ha escrito la campana y no el codigo interno.
 * La diferencia se nota en el mostrador: «su participacion no es valida» deja a
 * la azafata sin nada que decir, y «este cupon no es de esta promocion» le da la
 * frase exacta.
 *
 * ============================================================================
 * POR QUE NO SE ENSENA NADA DE OTRA PARTICIPACION
 * ============================================================================
 *
 * El apartado 4.7 prohibe expresamente revelar datos de otro participante. Por eso
 * aqui no sale ni el nombre, ni el DNI, ni nada que venga de una participacion
 * anterior: solo el resultado de ESTA, que es lo unico que la propia persona ya
 * sabe. Los textos de la campana se han escrito tambien pensando en esto, y por
 * eso el motivo de un duplicado no dice «ese DNI ya participa» sino «ya ha
 * participado en esta promocion».
 *
 * ============================================================================
 * POR QUE NO HAY RUTINA DE PREMIO AQUI
 * ============================================================================
 *
 * El codigo de reclamacion se enseña solo si la campana no manda el correo, o
 * si el correo esta apagado. Si se manda, ponerlo tambien en pantalla no aporta
 * nada a la clienta y multiplica los sitios por los que se puede leer el codigo de
 * alguien. El valor sale de la fila de la unidad adjudicada y se muestra tal cual,
 * que es lo que la persona tiene que decir en el mostrador para recogerlo.
 *
 * @var string                $titulo    Titulo de la pantalla.
 * @var array<string, mixed>  $campana   Fila de la campana.
 * @var array<string, mixed>  $resultado Resultado del motor, con la misma forma
 *                                        siempre: resultado, motivo, unidad...
 * @var array<string, mixed>  $visual    Apariencia configurada de la campana.
 * @var string                $destino   Ruta de la que se venia.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Vista;

$resultado = $resultado ?? [];
$visual = $visual ?? [];

$premio = (string) ($resultado['resultado'] ?? '') === 'premio';
$rechazada = (string) ($resultado['resultado'] ?? '') === 'rechazada';
// El codigo de reclamacion se enseña solo cuando la campana NO manda el correo
// de premio. Si lo manda, el codigo va a llegar por correo y ponerlo tambien aqui
// no aporta nada a la clienta, solo multiplica los sitios por los que se puede
// leer. Cuando el correo esta apagado, en cambio, el codigo es la unica via para
// que la persona pueda recoger el premio, asi que tiene que verse en pantalla.
$hayCorreo = (int) ($campana['correo_ganador'] ?? 0) === 1;
$codigo = !$premio || $hayCorreo ? '' : (string) ($resultado['codigo_reclamacion'] ?? '');

// El texto sale de la campana y se usa el del resultado que corresponde. Si la
// campana no ha escrito ninguno, se dice lo minimo indispensable, porque una
// pantalla de premio sin texto parece una pantalla que no ha cargado.
$texto = (string) ($visual[$premio ? 'resultado_premio_texto' : 'resultado_no_premio_texto'] ?? '');
$imagen = (string) ($visual[$premio ? 'resultado_premio_ruta' : 'resultado_no_premio_ruta'] ?? '');

if ($texto === '') {
    $texto = $premio
        ? 'Su participacion ha sido registrada.'
        : 'Gracias por participar. Esta vez no ha habido suerte.';
}
?>

<section class="panel resultado resultado-<?= $rechazada ? 'rechazada' : ($premio ? 'premio' : 'sin-premio') ?>">

    <?php if ($imagen !== ''): ?>
        <img
            class="resultado-imagen"
            src="<?= Vista::e(Aplicacion::asset('uploads/' . ltrim($imagen, '/'))) ?>"
            alt="">
    <?php endif; ?>

    <?php if ($rechazada): ?>
        <h1>Su participacion no se ha registrado</h1>

        <?php /* El motivo va en su propio parrafo y no dentro del texto de la
               campana, porque el texto de la campana es para el resultado y el
               motivo es siempre la causa concreta del rechazo. */ ?>
        <p class="resultado-motivo"><?= Vista::e((string) ($resultado['motivo_texto'] ?? '')) ?></p>

        <p class="ayuda">Se ha guardado el intento sin consumir ningun premio, por si
            la clienta repite o se revisa mas adelante.</p>

    <?php elseif ($premio): ?>
        <h1>Enhorabuena</h1>

        <p class="resultado-texto"><?= Vista::e($texto) ?></p>

        <?php if ($codigo !== ''): ?>
            <p class="resultado-codigo">
                Su codigo de reclamacion: <strong><?= Vista::e($codigo) ?></strong>
            </p>
        <?php endif; ?>

    <?php else: ?>
        <h1>Gracias por participar</h1>

        <p class="resultado-texto"><?= Vista::e($texto) ?></p>
    <?php endif; ?>

    <p class="acciones">
        <a class="boton" href="<?= Vista::e(Aplicacion::url($destino)) ?>">Participar con otra persona</a>
    </p>
</section>
