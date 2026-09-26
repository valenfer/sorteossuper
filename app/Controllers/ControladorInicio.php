<?php

/**
 * Pantallas de destino de cada rol.
 *
 * ============================================================================
 * QUE HACE ESTE CONTROLADOR Y POR QUE ES TAN PEQUENO
 * ============================================================================
 *
 * Cuando alguien entra, el sistema tiene que llevarlo a alguna parte segun su
 * rol: la azafata al mostrador, y el administrador al panel de campanas.
 *
 * El panel del administrador ya no sale de aqui. Lo lleva
 * \App\Controllers\ControladorCampanas, desde el hito 3, porque en cuanto la
 * campana tiene tramos, premios y calendario, la pantalla de destino del
 * administrador y el listado de campanas son la misma cosa. Este controlador se
 * queda con la pantalla del mostrador, que sigue siendo provisional.
 *
 * Lo que hace la pantalla provisional del mostrador es cumplir tres funciones:
 *
 *   1. Que el redireccionado tras entrar funcione y se pueda probar.
 *   2. Que se vea quien esta dentro y con que rol, que es la comprobacion mas
 *      basica de que la sesion y el control de acceso hacen su trabajo.
 *   3. Que quede escrito, a la vista, que la pantalla no esta terminada, para
 *      que nadie la tome por una pantalla en produccion.
 *
 * El enrutador ya exige el rol antes de llegar aqui, asi que en estas pantallas
 * no hay que comprobar nada. Aun asi, el rol se vuelve a mirar para mostrar el
 * contenido correcto, porque asi las pantallas se pueden reutilizar tal cual
 * cuando su contenido crezca.
 *
 * @see \App\Core\Router
 * @see \App\Core\Autorizacion
 * @see \App\Controllers\ControladorCampanas
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Autorizacion;
use App\Core\Controlador;
use App\Core\Vista;

/**
 * Paginas a las que se llega despues de entrar.
 */
class ControladorInicio extends Controlador
{
    /**
     * Pantalla de la azafata.
     *
     * @return void
     */
    public function mostrador(): void
    {
        $this->comun([
            'titulo' => 'Mostrador',
            'rol'    => Autorizacion::ROL_AZAFATA,
        ]);
    }

    /**
     * Renderiza la pantalla provisional que comparten los dos roles.
     *
     * @param array<string, mixed> $datos Titulo y rol de la pantalla.
     *
     * @return void
     */
    private function comun(array $datos): void
    {
        // El aviso de «pantalla provisional» se pasa siempre. Es preferible que
        // alguien que entre al panel vea que la parte que busca aun no esta,
        // antes de que piense que la campana esta configurada y vacia.
        $datos['provisional'] = true;
        $datos['error'] = Vista::aviso('error');
        $datos['aviso'] = Vista::aviso('aviso');
        $datos['exito'] = Vista::aviso('exito');

        $this->vista('inicio/pantalla', $datos);
    }
}
