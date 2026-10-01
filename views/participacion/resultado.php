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
 * ============================================================================
 * QUE ESTA PANTALLA ES TAMBIEN LA PANTALLA 2
 * ============================================================================
 *
 * El apartado 5 describe tres pantallas y la segunda es la animacion de la
 * ruleta. Aqui estan juntas, y el bloque que la pinta lleva su propio apartado
 * mas abajo, porque D19 tiene dos condiciones que se han de cumplir a la vista
 * y no basta con que la pagina las cumpla.
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

// D19 prohibe los nombres de premios en los sectores de la ruleta, y por eso la
// vista no busca el nombre del premio en ninguna parte. No es una prudencia
// inutil: si el nombre del premio llega a estar disponible aqui, algun dia
// alguien lo pondra en un sector «para que se vea de que va la cosa», y la
// pantalla empezara a sugerir que el giro decide el premio. Lo que hay en el
// centro es el nombre del comercio, que es el logotipo, y no un premio.
?>

<section class="panel resultado resultado-<?= $rechazada ? 'rechazada' : ($premio ? 'premio' : 'sin-premio') ?>">

    <?php if (!$rechazada): ?>
        <?php
        /* --------------------------------------------------------------------
         * PANTALLA 2: LA RULETA DECORATIVA (D19)
         * --------------------------------------------------------------------
         *
         * Esta es la animacion que pide el apartado 5, y va dentro de la misma
         * pantalla que el resultado en vez de en una pagina aparte. Es una
         * decision, y el motivo es que la ruleta no tiene nada que decidir:
         *
         *   - El servidor YA ha adjudicado antes de que llegue aqui. La ruleta no
         *     elige premio, lo anima. Por eso el resultado va escrito en el HTML
         *     desde el principio y la animacion solo lo deja tapado unos
         *     segundos.
         *
         *   - Si fuera una pagina aparte, el resultado tendria que guardarse en
         *     la sesion entre el POST y la pantalla de la ruleta, porque un GET
         *     no puede volver a adjudicar. Eso anade estado que puede quedarse
         *     colgado, que se pierde al cerrar la pestana, y que obliga a
         *     decidir que hacer si la azafata recarga durante el giro. Con una
         *     sola pagina no hay nada que decidir: recargar vuelve a pintar el
         *     resultado, que es justo lo que se quiere.
         *
         *   - El resultado NO depende de JavaScript. Va escrito en el HTML desde
          *     el principio y el CSS lo deja con opacidad cero durante el giro y
          *     lo revela despues, con un retardo de animacion. Sin CSS —o con el CSS
         *     desactivado en el navegador— el texto se ve entero desde el
         *     principio, porque el escondido lo pone el CSS y no la plantilla.
         *     Por eso el bloque del resultado lleva su propia clase y el
         *     retardo solo se aplica cuando hay ruleta, que es cuando importa.
         *
         * NO HAY NOMBRES DE PREMIOS EN LOS SECTORES, y no es una decision
         * estetica: D19 lo prohibe porque un sector rotulado «Voucher de 20
         * euros» hace pensar que el premio depende de donde pare el dedo. Si la
         * ruleta se parase en un sector que no es el del premio, la clienta
         * tendria razon para reclamar y el mostrador tendria que explicar que el
         * azar no decide nada. Por eso los sectores van solo con los colores de
         * la campana, y el unico texto que hay es el nombre del comercio en el
         * centro, que es el logotipo de D19.
         *
         * Los colores salen de la configuracion visual, que ya se recibe en
         * «visual» y que el layout convierte en custom properties. La ruleta se
         * pinta con las mismas variables que el resto de la pantalla, asi que
         * cambiar un color en el panel cambia tambien la ruleta, sin tocar una
         * linea de CSS.
         *
         * La ruleta no se pinta en un rechazo. Girar una ruleta sobre una
         * participacion que no se ha registrado solo haria esperar a la clienta
         * para recibir un «su participacion no se ha registrado», que ya se le
         * puede decir de frente.
         *
         * @see \App\Core\Vista::e()
         * @see decision D19 del documento de especificacion
         * @see apartado 5, pantalla 2
         */
        ?>
        <div class="ruleta" aria-hidden="true">
            <div class="ruleta-sectores"></div>
            <div class="ruleta-centro">
                <?php /* El centro lleva el nombre del comercio, que es el logotipo que
                   D19 pide. La columna se llama «comercio_nombre» y no
                   «nombre_comercio»: es el nombre de la tienda, no el de la
                   campaña. Si el administrador no lo ha rellenado, se usa el
                   nombre de la campaña, que siempre existe. */ ?>
                <?php $comercio = trim((string) ($campana['comercio_nombre'] ?? '')); ?>
                <?php if ($comercio !== ''): ?>
                    <span class="ruleta-logo"><?= Vista::e($comercio) ?></span>
                <?php else: ?>
                    <span class="ruleta-logo"><?= Vista::e((string) ($campana['nombre'] ?? '')) ?></span>
                <?php endif; ?>
            </div>
            <div class="ruleta-aguja"></div>
        </div>
    <?php endif; ?>

    <?php /* El bloque del resultado va DESPUES de la ruleta y no la envuelve, y por
           eso el retraso no puede esconderse a si mismo: si el bloque animado
           contuviera la ruleta, la animacion de opacidad la taparia durante los
           tres segundos que deberia estar girando, y lo unico que se veria seria
           un hueco con el borde de la rueda.

           La clase «resultado-revelado» va en el rechazo, que es el caso
           contrario: no hay ruleta, no hay nada que esperar y el motivo se dice
           de frente. Sin esa clase, el rechazo tambien esperaria tres segundos a
           que girara una ruleta que no esta pintada, para acabar con un «su
           participacion no se ha registrado». */ ?>
    <div class="resultado-bloque<?= $rechazada ? ' resultado-revelado' : '' ?>">

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

</div>
</section>
