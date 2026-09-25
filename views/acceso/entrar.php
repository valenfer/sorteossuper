<?php

/**
 * Pantalla de entrada al sistema.
 *
 * ============================================================================
 * POR QUE ESTA PANTALLA ESTA TAN DESNUDA
 * ============================================================================
 *
 * Se ve al entrar, y solo al entrar. No hay panel detras, ni enlaces, ni
 * informacion sobre la campana. Es deliberado, y por dos motivos que el
 * apartado 3 de la especificacion trata por separado:
 *
 *   - No sirve para enumerar usuarios. Si la pantalla dijera «ese nombre no
 *     existe», un atacante podria construir una lista de cuentas validas sin
 *     intentar ninguna contrasena. Por eso el mensaje de error es siempre el
 *     mismo, tanto si el nombre no existe como si la contrasena no cuadra.
 *
 *   - No ensena a un desconocido como se maneja la campana. Esta aplicacion
 *     maneja datos personales de clientes de un supermercado, y la pantalla de
 *     acceso no es el sitio mas adecuado para hacer publicidad del sistema.
 *
 * ============================================================================
 * EL FORMULARIO
 * ============================================================================
 *
 * Es un POST a la misma pantalla. Va con token CSRF, que es lo que impide que
 * otra pagina envie este formulario sola. El campo se llama con Csrf::CAMPO, en
 * lugar de escribir «csrf_token» a mano, para que el nombre no se pueda
 * desincronizar del que comprueba \App\Core\Csrf.
 *
 * ============================================================================
 * LOS ATRIBUTOS autocomplete
 * ============================================================================
 *
 * «username» y «current-password» estan a proposito. En un mostrador con una
 * tablet compartida, el gestor de contrasenas del navegador es lo unico que
 * evita teclear la contrasena a mano delante de la clienta, y estos valores son
 * los que hacen que aparezca la sugerencia. En «nombre» no se usa
 * autocomplete="off", porque el tipo «off» es el que algunos navegadores
 * ignoran y el nombre es preciso, de modo que no hay nada que ocultar.
 *
 * @var string $csrf   Token CSRF para el formulario.
 * @var string $error  Mensaje de error, ya vacio si no hay.
 * @var string $aviso  Mensaje informativo, ya vacio si no hay.
 * @var string $exito  Mensaje de confirmacion, ya vacio si no hay.
 */

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;

?>
<section class="pantalla-acceso">
    <div class="tarjeta">
        <h1 class="tarjeta-titulo">Entrar</h1>

        <?php if ($error !== ''): ?>
            <p class="aviso aviso-error" role="alert"><?= Vista::e($error) ?></p>
        <?php endif; ?>

        <?php if ($aviso !== ''): ?>
            <p class="aviso aviso-aviso" role="status"><?= Vista::e($aviso) ?></p>
        <?php endif; ?>

        <?php if ($exito !== ''): ?>
            <p class="aviso aviso-exito" role="status"><?= Vista::e($exito) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= Vista::e(Aplicacion::url('login')) ?>" class="formulario">
            <input type="hidden" name="<?= Vista::e(Csrf::CAMPO) ?>" value="<?= Vista::e($csrf) ?>">

            <p class="campo">
                <label for="nombre">Usuario</label>
                <input type="text" id="nombre" name="nombre" required autofocus
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       maxlength="100">
            </p>

            <p class="campo">
                <label for="contrasena">Contrasena</label>
                <input type="password" id="contrasena" name="contrasena" required
                       autocomplete="current-password" maxlength="200">
            </p>

            <p class="campo-boton">
                <button type="submit" class="boton boton-principal">Entrar</button>
            </p>
        </form>
    </div>
</section>
