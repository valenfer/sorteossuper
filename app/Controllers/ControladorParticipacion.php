<?php

declare(strict_types=1);

use App\Core\Aplicacion;
use App\Core\Csrf;
use App\Core\Vista;
use App\Services\Adjudicador;

/** Página de participación de una campaña.
 *
 * Muestra el formulario de datos de contacto y, tras enviar, muestra el
 * resultado (premio, sin premio o rechazada).
 *
 * @param int $id Identificador de la campaña.
 * @return void
 */
public function formulario(): void
{
    $id = (int) Aplicacion::parametro('id');
    $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

    $this->vista('participacion/formulario', [
        'titulo' => 'Participar en ' . $promocion['nombre'],
        'campana' => $promocion,
    ]);
}

/** Procesa el formulario de participación.
 *
 * Recoge los datos de contacto, llama a Adjudicador::registrarParticipacion()
 * y muestra la vista de resultado.
 *
 * @return void
 */
public function registrar(): void
{
    Csrf::verificar();

    $id = (int) Aplicacion::parametro('id');
    $promocion = (new Promocion())->exigirPorId($id, 'admin/promociones/' . $id);

    // Datos mínimos: DNI y número de ticket. El código de participación es
    // opcional y se incluye si la configuración lo requiere.
    $datos = [
        'dni' => Aplicacion::post('dni') ?? '',
        'ticket' => Aplicacion::post('ticket') ?? '',
        'email' => Aplicacion::post('email') ?? '',
    ];

    $adjudicador = new Adjudicador();
    $resultado = $adjudicador->registrarParticipacion($id, $datos);

    $this->vista('participacion/resultado', [
        'titulo' => 'Resultado de tu participación',
        'campana' => $promocion,
        'resultado' => $resultado,
    ]);
}