<?php

/**
 * Modelo de usuario.
 *
 * ============================================================================
 * CONTRASENAS
 * ============================================================================
 *
 * Nunca se guarda la contrasena. Se guarda un hash calculado con
 * password_hash() y el algoritmo bcrypt, que es lento a proposito: hace que
 * probar millones de contrasenas por segundo sea inviable. En la comparacion se
 * usa password_verify(), que ademas comprueba de forma automatica si el hash
 * necesita recalcularse porque los valores por defecto de PHP han cambiado.
 *
 * ============================================================================
 * POR QUE NO SE USA MD5 NI SHA
 * ============================================================================
 *
 * Son funciones de resumen, no de contrasena: estan disenadas para ser rapidas, y eso
 * es exactamente lo que un atacante quiere. Un SHA-256 de una contrasena
 * comunita se rompe en milisegundos con una tarjeta grafica. Un MD5 de un DNI
 * se rompe en menos. Con bcrypt, el mismo ataque tarda años por contrasena.
 *
 * Este dato importa mas de lo que parece aqui: el listado de usuarios guarda
 * hashes de contrasena de cuentas que dan acceso al panel de una promocion en
 * la que hay datos personales de clientes.
 *
 * ============================================================================
 * CREACION DE LA CUENTA ADMINISTRADOR
 * ============================================================================
 *
 * La crea bin/instalar.php, con una contrasena aleatoria de 32 bytes que se
 * genera con random_bytes, se hashea y se imprime una sola vez por consola
 * (decision D12). No hay ninguna contrasena por defecto, ni una contrasena en el
 * codigo, ni una forma de entrar sin haberla cambiado.
 *
 * @see \App\Core\Autorizacion
 * @see \App\Core\Modelo
 * @see apartado 3 de la especificacion, contrasenas con hash seguro
 * @see decision D12
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Autorizacion;
use App\Core\Modelo;

/**
 * Acceso a la tabla de usuarios y calculo de hashes de contrasena.
 */
class User extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'usuarios';

    /**
     * Busca un usuario por su nombre de acceso.
     *
     * La comparacion no distingue mayusculas porque la columna esta creada con
     * utf8mb4_unicode_ci, que en MySQL no distingue mayusculas en las
     * comparaciones de texto. Eso significa que «admin» y «Admin» son el mismo
     * usuario, y por tanto el indice unico de la tabla tambien los considera
     * duplicados.
     *
     * @param string $nombre Nombre de acceso tecleado.
     *
     * @return array<string, mixed>|null Fila del usuario, o null si no existe.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function porNombre(string $nombre): ?array
    {
        $nombre = trim($nombre);

        if ($nombre === '') {
            return null;
        }

        return $this->db->uno(
            'SELECT * FROM usuarios WHERE nombre = ? LIMIT 1',
            [$nombre]
        );
    }

    /**
     * Devuelve la lista de usuarios, con el filtro de rol opcional.
     *
     * @param string|null $rol       Rol por el que se filtra, o null para
     *                               devolverlos todos.
     * @param bool        $soloActivos Si true, excluye las cuentas desactivadas.
     *
     * @return array<int, array<string, mixed>> Filas de usuarios, ordenadas por
     *                                        nombre.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function listar(?string $rol = null, bool $soloActivos = true): array
    {
        $where = [];
        $parametros = [];

        // Los filtros se construyen con partes fijas y los valores se mandan
        // como parametros. Nunca se concatena un valor de la peticion.
        if ($rol !== null) {
            $where[] = 'rol = ?';
            $parametros[] = $rol;
        }

        if ($soloActivos) {
            $where[] = 'estado = 1';
        }

        $sql = 'SELECT id, nombre, nombre_completo, rol, estado, promocion_id, creado_en, ultimo_acceso
                FROM usuarios';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY nombre';

        return $this->db->todos($sql, $parametros);
    }

    /**
     * Crea un usuario con su contrasena ya hasheada.
     *
     * @param string      $nombre        Nombre de acceso. Se normaliza a
     *                                   minusculas y sin espacios sobrantes.
     * @param string      $contrasena    Contrasena en claro. Se hashea aqui y
     *                                   no se guarda ni se registra en ningun
     *                                   sitio mas que este haseado.
     * @param string      $rol           Rol asignado.
     * @param string      $nombreCompleto Nombre visible, para la auditoria.
     * @param int|null    $promocionId   Promocion a la que se asigna la
     *                                   azafata, o null si no se asigna todavia.
     * @param int|null    $creadoPor     Usuario que crea la cuenta, o null si
     *                                   la crea el instalador.
     *
     * @return int Identificador del usuario creado.
     *
     * @throws \App\Core\ErrorValidacion    Si falta algun dato obligatorio.
     * @throws \App\Core\ErrorBaseDeDatos  Si el nombre ya esta en uso.
     */
    public function crear(
        string $nombre,
        string $contrasena,
        string $rol,
        string $nombreCompleto = '',
        ?int $promocionId = null,
        ?int $creadoPor = null
    ): int {
        $nombre = $this->normalizarNombre($nombre);

        if ($nombre === '') {
            throw new \App\Core\ErrorValidacion('El nombre de acceso es obligatorio.');
        }

        if (!in_array($rol, Autorizacion::ROLES, true)) {
            throw new \App\Core\ErrorValidacion("El rol «{$rol}» no es valido.");
        }

        if ($contrasena === '') {
            throw new \App\Core\ErrorValidacion('La contrasena es obligatoria.');
        }

        return $this->db->insertar(
            'INSERT INTO usuarios (nombre, nombre_completo, contrasena_hash, rol, estado,
                                   promocion_id, creado_por, creado_en)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
            [
                $nombre,
                $nombreCompleto,
                self::hashear($contrasena),
                $rol,
                $promocionId,
                $creadoPor,
                \App\Core\Aplicacion::ahora(),
            ]
        );
    }

    /**
     * Calcula el hash de una contrasena.
     *
     * Se separa del INSERT para que se pueda usar en un cambio de contrasena sin
     * tocar el resto de la consulta, y para que quede claro en un solo sitio
     * que el hash se calcula SIEMPRE con los valores que decide PHP.
     *
     * @param string $contrasena Contrasena en claro.
     *
     * @return string Hash en formato bcrypt, listo para guardar.
     *
     * @throws \App\Core\ErrorValidacion Si la contrasena no cumple la longitud
     *                                   minima configurada.
     */
    public static function hashear(string $contrasena): string
    {
        // La longitud minima sale de la configuracion. Con menos de 12
        // caracteres, un atacante que consiga el hash tiene margen de sobra
        // para probar combinaciones.
        $minima = (int) \App\Core\Aplicacion::ajuste('seguridad.longitud_minima_contrasena', 12);

        if (mb_strlen($contrasena, 'UTF-8') < $minima) {
            throw new \App\Core\ErrorValidacion(
                "La contrasena debe tener al menos {$minima} caracteres."
            );
        }

        // Se dejan los valores por defecto de PHP, que actualmente usan bcrypt
        // con coste 12. Fijar el coste aqui seria un error: si en el futuro PHP
        // sube el valor recomendado, un coste fijo en el codigo dejaria la
        // base de datos con hashes debiles durante años.
        return password_hash($contrasena, PASSWORD_DEFAULT);
    }

    /**
     * Comprueba que una contrasena corresponde a un hash.
     *
     * @param string $contrasena Contrasena tecleada por la persona.
     * @param string $hash       Hash guardado en la base de datos.
     *
     * @return bool True si la contrasena es la correcta.
     */
    public static function verificar(string $contrasena, string $hash): bool
    {
        // Si el hash esta vacio o malformado, password_verify devuelve false
        // sin avisar, que es justo lo que se quiere: una cuenta sin hash
        // utilizable no puede entrar nunca.
        return password_verify($contrasena, $hash);
    }

    /**
     * Indica si un hash necesita recalcularse.
     *
     * @param string $hash Hash guardado en la base de datos.
     *
     * @return bool True si convendria volver a hashear la contrasena con los
     *              valores actuales de PHP.
     */
    public static function necesitaRecalculo(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Registra el acceso de un usuario a la aplicacion.
     *
     * @param int $usuarioId Identificador del usuario.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la actualizacion falla.
     */
    public function registrarAcceso(int $usuarioId): void
    {
        $this->db->ejecutar(
            'UPDATE usuarios SET ultimo_acceso = ? WHERE id = ?',
            [\App\Core\Aplicacion::ahora(), $usuarioId]
        );
    }

    /**
     * Normaliza un nombre de acceso.
     *
     * Se pasa a minusculas y se quitan los espacios de los extremos para que
     * «Admin» y «admin» sean la misma cuenta y no se creen dos cuentas que la
     * persona usuaria no distingue.
     *
     * @param string $nombre Nombre de acceso sin normalizar.
     *
     * @return string Nombre normalizado.
     */
    private function normalizarNombre(string $nombre): string
    {
        return mb_strtolower(trim($nombre), 'UTF-8');
    }
}
