<?php

/**
 * Modelo de la lista de tickets y codigos validos de una campana.
 *
 * ============================================================================
 * QUE RESUELVE Y QUE NO RESUELVE
 * ============================================================================
 *
 * La pregunta 3 del apartado 12 del documento original es «¿como se comprueba que
 * un numero de ticket o codigo pertenece a una compra valida?», y la decision D3
 * la deja configurable: control de duplicados, con esta tabla como lista
 * precargada opcional.
 *
 * Lo que esta tabla permite es que el supermercado decida que un conjunto de
 * tickets se han repartido a mano y los cargue, de modo que la aplicacion pueda
 * rechazar un ticket que no esta en la lista. Lo que NO permite es demostrar que
 * un ticket sea autentico: no hay integracion con la caja. Por eso el panel
 * declara de forma visible que el ticket no esta verificado, y por eso la regla
 * «una por ticket» se cumple igualmente con lista o sin ella, que es lo que dice
 * el apartado 4.7: la mera introduccion de un numero no demuestra nada.
 *
 * ============================================================================
 * POR QUE SE GUARDA UNA HUELLA Y NO EL CODIGO
 * ============================================================================
 *
 * Un ticket de compra real dice cuanta dinero ha gastado una persona y en que
 * supermercado ha comprado. Guardar la lista en claro en la base de datos hace
 * que cualquiera con acceso de solo lectura —una copia de seguridad, un
 * programador, un informe— pueda saber cuales son, y no hay ninguna razon para
 * que eso ocurra.
 *
 * En su lugar se guarda la huella HMAC del codigo normalizado, con el secreto de
 * la configuracion, y la comparacion se hace sobre la huella. La huella es
 * determinista —el mismo ticket produce siempre la misma huella— asi que el
 * indice unico (promocion_id, tipo, valor_hash) sigue impidiendo repetirlo, pero
 * de la base de datos no se puede volver al ticket.
 *
 * La normalizacion es la de \App\Services\Huella, y es la misma que usara la
 * deduplicacion de participaciones del hito 4. Que sea la misma no es casualidad:
 * si el codigo se normalizara de una forma para la lista y de otra para la
 * deduplicacion, un ticket con espacios podria estar en la lista y no contarse
 * como repetido, o al reves.
 *
 * @see \App\Services\Huella
 * @see apartado 4.7 de la especificacion, requisitos de participacion
 * @see decision D3 del documento de especificacion
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;
use App\Services\Huella;

/**
 * Acceso a la tabla de codigos validos.
 */
class CodigoValido extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'codigos_validos';

    /**
     * Tipo de codigo para un numero de ticket.
     *
     * @var string
     */
    public const TIPO_TICKET = 'ticket';

    /**
     * Tipo de codigo para un codigo de participacion.
     *
     * @var string
     */
    public const TIPO_CODIGO = 'codigo';

    /**
     * Anade codigos a la lista de una campana, ignorando los repetidos.
     *
     * El administrador pega la lista en un textarea, una linea por codigo, y aqui
     * cada linea se normaliza, se convierte en huella y se inserta. Los repetidos
     * no son un error: una lista de 500 tickets de los que 40 estan repetidos
     * porque se han repartido en varias cajas es una lista normal, y avisar de
     * cada uno seria ruido. Lo que se devuelve es cuantos se han añadido y cuantos
     * ya estaban, para que la pantalla lo diga con numeros en vez de «ha ido bien».
     *
     * La insercion es fila a fila y no en bloque con un INSERT de varias filas
     * porque el indice unico (promocion_id, tipo, valor_hash) es lo que decide
     * cuales se ignoran, y eso hay que preguntarselo a la base de datos una vez
     * por fila. Con el IGNORE de MySQL haria falta una instruccion por codigo
     * igualmente.
     *
     * @param int                  $promocionId Campana a la que pertenecen.
     * @param string               $tipo        TIPO_TICKET o TIPO_CODIGO.
     * @param array<int, string>   $valores     Codigos tal y como los ha escrito
     *                                          el administrador, sin normalizar.
     *
     * @return array{insertados: int, repetidos: int, vacios: int} Recuento de lo
     *               que ha pasado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function anadir(int $promocionId, string $tipo, array $valores): array
    {
        $ahora = Aplicacion::ahora();
        $insertados = 0;
        $repetidos = 0;
        $vacios = 0;

        foreach ($valores as $valor) {
            $normalizado = Huella::normalizar((string) $valor);

            if ($normalizado === '') {
                $vacios++;
                continue;
            }

            $huella = Huella::de($tipo, (string) $valor);

            // La comprobacion previa es solo para poder contar. La garantia de que
            // no se repita la da el indice unico, y por eso el INSERT se hace
            // con IGNORE: si dos peticiones simultaneas anaden el mismo ticket,
            // una lo gana y la otra recibe el aviso de duplicado en vez de
            // abortar la transaccion entera.
            $existe = $this->db->valor(
                'SELECT 1 FROM codigos_validos
                  WHERE promocion_id = ? AND tipo = ? AND valor_hash = ?
                  LIMIT 1',
                [$promocionId, $tipo, $huella]
            );

            if ($existe !== null) {
                $repetidos++;
                continue;
            }

            $this->db->ejecutar(
                'INSERT IGNORE INTO codigos_validos (promocion_id, tipo, valor_hash, creado_en)
                 VALUES (?, ?, ?, ?)',
                [$promocionId, $tipo, $huella, $ahora]
            );

            $insertados++;
        }

        return ['insertados' => $insertados, 'repetidos' => $repetidos, 'vacios' => $vacios];
    }

    /**
     * Comprueba si un codigo esta en la lista y si sigue sin gastar.
     *
     * @param int    $promocionId Campana en la que se busca.
     * @param string $tipo        TIPO_TICKET o TIPO_CODIGO.
     * @param string $valor       Codigo introducido por la clienta, sin
     *                            normalizar.
     *
     * @return bool True si el codigo existe y no se ha usado todavia.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function estaLibre(int $promocionId, string $tipo, string $valor): bool
    {
        $normalizado = Huella::normalizar($valor);

        if ($normalizado === '') {
            return false;
        }

        $libre = $this->db->valor(
            'SELECT 1 FROM codigos_validos
              WHERE promocion_id = ?
                AND tipo = ?
                AND valor_hash = ?
                AND usado_en IS NULL
              LIMIT 1',
            [$promocionId, $tipo, Huella::de($tipo, $valor)]
        );

        return $libre !== null;
    }

    /**
     * Cuenta los codigos de una campana.
     *
     * @param int         $promocionId Campana que se quiere contar.
     * @param string|null $tipo        Tipo a contar, o null para todos.
     *
     * @return array{total: int, usados: int} Numero de codigos cargados y de los
     *                      que ya se han gastado.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contar(int $promocionId, ?string $tipo = null): array
    {
        $fila = $this->db->uno(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(usado_en IS NOT NULL), 0) AS usados
               FROM codigos_validos
              WHERE promocion_id = ?
                AND tipo >= ?',
            [$promocionId, $tipo ?? '']
        );

        return [
            'total'  => (int) ($fila['total'] ?? 0),
            'usados' => (int) ($fila['usados'] ?? 0),
        ];
    }

    /**
     * Borra los codigos que no se han usado todavia.
     *
     * Es la limpieza de una campana en borrador: si el administrador ha pegado dos
     * veces la lista de tickets y la segunda era la buena, esto le quita de
     * encima la primera sin tocar los que ya han servido para una participacion.
     *
     * @param int $promocionId Campana de la que se limpian.
     *
     * @return int Numero de codigos borrados.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function borrarNoUsados(int $promocionId): int
    {
        return $this->db->ejecutar(
            'DELETE FROM codigos_validos WHERE promocion_id = ? AND usado_en IS NULL',
            [$promocionId]
        );
    }
}
