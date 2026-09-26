<?php

/**
 * Modelo de las reglas de participacion de una campana.
 *
 * ============================================================================
 * UNA FILA POR CAMPANA, CON VARIAS CASILLAS
 * ============================================================================
 *
 * El apartado 4.7 de la especificacion pide que se puedan activar varias
 * restricciones a la vez, y el «supuesto de implementacion» concreta que deben
 * cumplirse todas. El esquema resuelve eso con una fila por campana y una casilla
 * por regla: activar «una por campana» y «una por ticket» a la vez no es una
 * combinacion nueva que haya que inventar, es simplemente las dos casillas
 * marcadas, y la combinacion «todas las activas se cumplen» es la que se cumple
 * sin escribir una sola regla mas.
 *
 * ============================================================================
 * POR QUE `campo_identidad` ES UN DATO Y NO UNA DECISION
 * ============================================================================
 *
 * «Una participacion por persona» necesita saber que es una persona. Y «persona»
 * no es un dato: puede ser el DNI, el ticket, el codigo o el correo, segun lo
 * que la campana pida. Por eso el campo es configurable y se guarda vacio cuando
 * no hace falta: vacio significa que el servicio de reglas deduce cual usar de
 * los campos disponibles, con la prioridad que fija el apartado 4.8.
 *
 * La huella que sale de ahi es la que resuelve las cuatro reglas de duplicado
 * con un solo indice unico, porque lleva dentro su ambito. Eso es la decision D3
 * y lo cuenta \App\Services\Huella, que es donde se calcula.
 *
 * ============================================================================
 * POR QUE LOS TEXTOS DE RECHAZO SON CONFIGURABLES
 * ============================================================================
 *
 * Dos razones, y la segunda es la importante. La primera es que los motivos
 * cambian segun la campana. La segunda es que el apartado 4.7 prohibe expresamente
 * revelar datos de otro participante: un texto por defecto que dijera «ese DNI ya
 * participa» confirmaria a la segunda persona de que la primera existe. Por eso
 * los textos que se guardan aqui son genericos y por eso la validacion real, que
 * es la que puede revelar algo, esta en el servicio de reglas del hito 4, no en
 * la base de datos.
 *
 * @see \App\Services\Huella
 * @see \App\Services\ValidadorReglas
 * @see apartado 4.7 de la especificacion, requisitos de participacion
 * @see decision D3 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;

/**
 * Acceso a la tabla de reglas de participacion.
 */
class ReglaParticipacion extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'reglas_participacion';

    /**
     * Devuelve las reglas de una campana, o los valores por defecto.
     *
     * Que devuelva los valores por defecto en vez de null cuando la campana
     * todavia no tiene fila es lo que permite que el resto del programa no tenga
     * que comprobar en ningun sitio si las reglas existen. Una campana nueva
     * significa «sin reglas», y eso es exactamente lo que dice una fila con todas
     * las casillas a false.
     *
     * @param int $promocionId Campana cuyas reglas se quieren.
     *
     * @return array<string, mixed> Reglas con la clave de cada casilla mas
     *                            «actualizado_en».
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function leer(int $promocionId): array
    {
        $reglas = $this->db->uno(
            'SELECT * FROM reglas_participacion WHERE promocion_id = ?',
            [$promocionId]
        );

        if ($reglas === null) {
            return $this->valoresPorDefecto();
        }

        return $reglas;
    }

    /**
     * Guarda las reglas de una campana, creandolas si no existen.
     *
     * Se usa INSERT ... ON DUPLICATE KEY UPDATE porque la clave primaria de esta
     * tabla es la propia promocion_id: guardar dos veces no es un error de clave
     * duplicada, es la misma campana actualizada. Un SELECT previo para decidir
     * entre las dos sentencias seria una consulta de mas que ademas dejaria pasar
     * dos peticiones simultaneas con el mismo resultado.
     *
     * @param int                  $promocionId Campana a la que pertenecen.
     * @param array<string, mixed> $datos       Casillas ya validadas.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardar(int $promocionId, array $datos): void
    {
        $ahora = Aplicacion::ahora();

        $this->db->ejecutar(
            'INSERT INTO reglas_participacion (
                 promocion_id,
                 una_por_campana, una_por_dia, una_por_ticket, una_por_dni, exigir_codigo,
                 campo_identidad, exigir_consentimiento, texto_consentimiento, verificar_ticket,
                 texto_rechazo_horario, texto_rechazo_duplicado,
                 texto_rechazo_codigo, texto_rechazo_consentimiento,
                 actualizado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 una_por_campana = VALUES(una_por_campana),
                 una_por_dia = VALUES(una_por_dia),
                 una_por_ticket = VALUES(una_por_ticket),
                 una_por_dni = VALUES(una_por_dni),
                 exigir_codigo = VALUES(exigir_codigo),
                 campo_identidad = VALUES(campo_identidad),
                 exigir_consentimiento = VALUES(exigir_consentimiento),
                 texto_consentimiento = VALUES(texto_consentimiento),
                 verificar_ticket = VALUES(verificar_ticket),
                 texto_rechazo_horario = VALUES(texto_rechazo_horario),
                 texto_rechazo_duplicado = VALUES(texto_rechazo_duplicado),
                 texto_rechazo_codigo = VALUES(texto_rechazo_codigo),
                 texto_rechazo_consentimiento = VALUES(texto_rechazo_consentimiento),
                 actualizado_en = VALUES(actualizado_en)',
            [
                $promocionId,
                $datos['una_por_campana'] ? 1 : 0,
                $datos['una_por_dia'] ? 1 : 0,
                $datos['una_por_ticket'] ? 1 : 0,
                $datos['una_por_dni'] ? 1 : 0,
                $datos['exigir_codigo'] ? 1 : 0,
                $datos['campo_identidad'],
                $datos['exigir_consentimiento'] ? 1 : 0,
                $datos['texto_consentimiento'] !== '' ? $datos['texto_consentimiento'] : null,
                $datos['verificar_ticket'] ? 1 : 0,
                $datos['texto_rechazo_horario'],
                $datos['texto_rechazo_duplicado'],
                $datos['texto_rechazo_codigo'],
                $datos['texto_rechazo_consentimiento'],
                $ahora,
            ]
        );
    }

    /**
     * Devuelve las reglas por defecto de una campana que todavia no tiene ninguna.
     *
     * Los textos de rechazo vienen puestos a proposito. Una regla activa con el
     * texto en blanco haria que la pantalla mostrase un rechazo sin explicar nada,
     * que es justo lo que el apartado 4.7 pide evitar: el mensaje tiene que ser
     * claro y no puede revelar datos de otra persona.
     *
     * @return array<string, mixed> Reglas por defecto, con los mismos nombres de
     *                            clave que las filas reales.
     */
    public function valoresPorDefecto(): array
    {
        return [
            'promocion_id'                  => 0,
            'una_por_campana'               => 0,
            'una_por_dia'                   => 0,
            'una_por_ticket'                => 0,
            'una_por_dni'                   => 0,
            'exigir_codigo'                 => 0,
            'campo_identidad'               => '',
            'exigir_consentimiento'         => 0,
            'texto_consentimiento'          => null,
            'verificar_ticket'              => 0,
            'texto_rechazo_horario'         => 'Ahora mismo no estamos en horario de participación.',
            'texto_rechazo_duplicado'       => 'Ya has participado en esta promoción.',
            'texto_rechazo_codigo'          => 'El código introducido no es válido.',
            'texto_rechazo_consentimiento'  => 'Es necesario aceptar el aviso de privacidad.',
            'actualizado_en'                => null,
        ];
    }
}
