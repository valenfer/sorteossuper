<?php

/**
 * Modelo de los intentos que no han superado las comprobaciones.
 *
 * ============================================================================
 * QUE SE GUARDA AQUI Y QUE NO
 * ============================================================================
 *
 * Se guarda el motivo del rechazo y una huella de la identidad. NO se guarda el
 * texto que la persona habia escrito, ni su nombre, ni su correo, ni su telefono.
 *
 * La razon es de minimizacion de datos, y es la parte de la decision D10 que
 * suele pasarse por alto: un intento rechazado no ha dado derecho a nada, asi que
 * no hay motivo para conservar sus datos personales mas alla del informe de la
 * campana. Es lo que hace que la tabla que mas filas va a tener, porque incluye
 * los intentos de duplicado y los de fuera de horario, sea ademas la que menos
 * datos personales contiene.
 *
 * ============================================================================
 * POR QUE NO LLEVA NINGUN INDICE UNIQUE
 * ============================================================================
 *
 * Es la razon de que esta tabla este separada de participaciones, y la que
 * impide que un rechazo bloquee a nadie. Un intento rechazado tiene un indice
 * unico, (promocion_id, clave_idempotencia), pero ninguna otra restriccion: se
 * puede rechazar al mismo DNI cien veces seguidas, y cada rechazo ocupa su sitio
 * sin estorbar a la siguiente participacion valida de otra persona.
 *
 * Si estos rechazos vivieran en participaciones, su indice unico de
 * (promocion_id, clave_unicidad) haria que el rechazo de una persona bloqueara
 * el hueco de otra. Ver el comentario de \App\Models\Participacion.
 *
 * ============================================================================
 * LA HUELLA DE IDENTIDAD TAMBIEN ES UNA HUELLA
 * ============================================================================
 *
 * clave_identidad no es el dato en claro, sino el mismo HMAC-SHA256 que se
 * indexa en las participaciones, calculado con el secreto de configuracion. Es
 * lo que permite contar «cuantas veces ha intentado participar esta misma
 * persona» sin guardar el dato, y lo que permite al administrador ver un
 * historial de intentos de una persona concreta sin que ese dato llegue a estar
 * almacenado en ningun sitio legible.
 *
 * @see \App\Models\Participacion
 * @see \App\Services\Adjudicador
 * @see \App\Services\ValidadorReglas
 * @see apartado 7 de la especificacion, evitar exponer datos personales
 * @see decisiones D3, D10 y D18
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Modelo;

/**
 * Acceso a la tabla de intentos rechazados.
 */
class IntentoRechazado extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'intentos_rechazados';

    /**
     * Busca el rechazo de un intento anterior con la misma clave.
     *
     * Hace falta por el mismo motivo que en participaciones, y con el mismo
     * resultado: un doble clic sobre un intento que va a ser rechazado devuelve
     * el mismo rechazo en lugar de generar dos filas. El recuento de rechazos
     * del panel de seguimiento no se distortiona con cada recarga de la pagina.
     *
     * @param int    $promocionId       Campana del intento.
     * @param string $claveIdempotencia Clave del intento.
     *
     * @return array<string, mixed>|null Fila del rechazo, o null si este
     *                                   intento es nuevo.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function porClaveIdempotencia(int $promocionId, string $claveIdempotencia): ?array
    {
        return $this->db->uno(
            'SELECT id, motivo_codigo, motivo_texto, momento
               FROM intentos_rechazados
              WHERE promocion_id = ?
                AND clave_idempotencia = ?
              LIMIT 1',
            [$promocionId, $claveIdempotencia]
        );
    }

    /**
     * Registra un intento rechazado.
     *
     * No recibe ni devuelve nada de la cola de premios, y esa es la garantia que
     * el caso de aceptacion 5 exige: rechazar sin consumir una unidad. Como el
     * metodo no toca unidades_premio en absoluto, es imposible que lo haga.
     *
     * La llamada va dentro de la transaccion del motor, por coherencia con el
     * resto, aunque un INSERT aislado no lo necesita.
     *
     * @param int                   $promocionId       Campana del intento.
     * @param string                $claveIdempotencia Clave del intento.
     * @param string                $momento          Instante del intento.
     * @param string                $motivoCodigo     Codigo corto del motivo,
     *                                                 para agrupar en el panel.
     * @param string                $motivoTexto      Texto que se leera en
     *                                                 pantalla. Sin datos
     *                                                 personales de la persona.
     * @param int|null              $tramoId          Tramo en el que se ha
     *                                                 intentado participar, o
     *                                                 null si no habia ninguno
     *                                                 activo.
     * @param string|null           $claveIdentidad   Huella HMAC de la
     *                                                 identidad, o null si no se
     *                                                 pudo calcular.
     * @param int|null              $usuarioAzafataId Azafata que estaba en la
     *                                                 pantalla.
     *
     * @return int Identificador del rechazo creado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    public function crear(
        int $promocionId,
        string $claveIdempotencia,
        string $momento,
        string $motivoCodigo,
        string $motivoTexto,
        ?int $tramoId = null,
        ?string $claveIdentidad = null,
        ?int $usuarioAzafataId = null
    ): int {
        return $this->db->insertar(
            'INSERT INTO intentos_rechazados (
                 promocion_id,
                 tramo_id,
                 usuario_azafata_id,
                 clave_idempotencia,
                 clave_identidad,
                 momento,
                 motivo_codigo,
                 motivo_texto,
                 creado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $promocionId,
                $tramoId,
                $usuarioAzafataId,
                $claveIdempotencia,
                $claveIdentidad,
                $momento,
                $motivoCodigo,
                mb_substr($motivoTexto, 0, 255, 'UTF-8'),
                $momento,
            ]
        );
    }

    /**
     * Cuenta los rechazos de una campana agrupados por motivo.
     *
     * Un volumen alto de un mismo motivo es la senal de que algo va mal, y es lo
     * que el panel de seguimiento mostrara en el hito 6.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return array<string, int> Motivos como claves y recuentos como valores.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPorMotivo(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT motivo_codigo, COUNT(*) AS total
               FROM intentos_rechazados
              WHERE promocion_id = ?
              GROUP BY motivo_codigo',
            [$promocionId]
        );

        $recuento = [];

        foreach ($filas as $fila) {
            $recuento[(string) $fila['motivo_codigo']] = (int) $fila['total'];
        }

        return $recuento;
    }
}
