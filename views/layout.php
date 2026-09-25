<?php

/**
 * Maquetacion comun de todas las pantallas.
 *
 * ============================================================================
 * QUE ES Y COMO LA USA EL NUCLEO
 * ============================================================================
 *
 * Esta plantilla no se pide nunca desde un controlador. La pinta
 * \App\Core\Vista::renderizar() al final, ya con el contenido de la pantalla
 * metido en Vista::$contenido. Por eso el unico dato que recibe es el titulo.
 *
 * Lo que hace aqui es lo mismo que haria cualquier «layout» de un framework de
 * plantillas, solo que escrito a mano y con dos variables publicas estaticas en
 * lugar de una sintaxis propia.
 *
 * ============================================================================
 * REGLAS DE ESCAPADO QUE RESPETA ESTE FICHERO
 * ============================================================================
 *
 * El apartado 9 de la especificacion exige que todo lo que se imprima antes de
 * enviarse al navegador pase por una funcion de escapado. Aqui hay dos casos:
 *
 *   - Todo lo que viene de la base de datos o de un formulario se imprime con
 *     Vista::e(), que escapa ampersands, comillas y etiquetas. Si una clienta
 *     teclea «<b>hola</b>» en su nombre, en pantalla sale el texto, no una
 *     negrita ni una etiqueta que rompa la pagina.
 *
 *   - Lo unico que se imprime sin escapar es Vista::$contenido, que es HTML
 *     que ha construido la propia aplicacion. Escribirlo sin escapar a proposito
 *     es lo unico que lo hace funcionar, y no es una puerta abierta: lo que se
 *     imprime sin escapar es siempre el resultado de una plantilla nuestra, que
 *     ya ha escapado sus datos por dentro.
 *
 * @see \App\Core\Vista::e()
 * @see \App\Core\Vista::mostrar()
 * @see apartado 9 de la especificacion, las salidas HTML se escapan
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Autorizacion;
use App\Core\Csrf;
use App\Core\Vista;

/**
 * @var string     $titulo    Titulo de la pagina, ya preparado por la vista.
 * @var string     $contenido HTML de la pantalla, construido por la aplicacion.
 * @var string     $vista     Ruta de la vista que se ha pinto, sin carpeta
 *                            y sin extension.
 * @var array|null $usuario   Identificador y rol de quien esta dentro, o null
 *                            si la pantalla es la de acceso.
 */

?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= Vista::e($titulo) ?> &middot; Sorteos</title>
    <link rel="stylesheet" href="<?= Vista::e(Aplicacion::asset('css/estilos.css')) ?>">
</head>
<body class="<?= Autorizacion::haySesion() ? 'con-sesion' : 'sin-sesion' ?>">

<a class="salto-contenido" href="#contenido">Saltar al contenido</a>

<header class="cabecera">
    <div class="cabecera-interior">
        <a class="marca" href="<?= Vista::e(Aplicacion::url('')) ?>">
            <span class="marca-icono" aria-hidden="true">S</span>
            <span class="marca-texto">Sorteos</span>
        </a>

        <?php if (Autorizacion::haySesion()): ?>
            <nav class="menu" aria-label="Menu principal">
                <?php if (Autorizacion::esAdministrador()): ?>
                    <a href="<?= Vista::e(Aplicacion::url('admin')) ?>">Panel</a>
                <?php else: ?>
                    <a href="<?= Vista::e(Aplicacion::url('azafata')) ?>">Mostrador</a>
                <?php endif; ?>
            </nav>

            <div class="sesion">
                <span class="sesion-usuario">
                    <?= Vista::e(Autorizacion::nombreUsuario()) ?>
                    <span class="etiqueta-rol"><?= Vista::e($usuario['rol']) ?></span>
                </span>

                <?php
                // El cierre de sesion es un formulario POST y no un enlace, y el
                // motivo no es estetico. Un enlace a «salir» se podria activar
                // con una peticion GET desde el enlace de otra persona o desde
                // una imagen en un correo, y dejaria a la azafata fuera de la
                // campana en mitad de una cola. Con formulario y token CSRF, el
                // cierre solo ocurre si la propia persona pulsa el boton.
                ?>
                <form method="post" action="<?= Vista::e(Aplicacion::url('salir')) ?>">
                    <input type="hidden" name="<?= Vista::e(Csrf::CAMPO) ?>"
                           value="<?= Vista::e(Csrf::token()) ?>">
                    <button type="submit" class="boton boton-sutil">Salir</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</header>

<main id="contenido" class="contenido">
    <?= $contenido ?>
</main>

<footer class="pie">
    <p>
        Aplicacion de sorteos para supermercado. Las participaciones se registran
        con la persona presente y su consentimiento.
    </p>
</footer>

<script src="<?= Vista::e(Aplicacion::asset('js/aplicacion.js')) ?>" defer></script>
</body>
</html>
