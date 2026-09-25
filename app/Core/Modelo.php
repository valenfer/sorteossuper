<?php

/**
 * Clase base de los modelos.
 *
 * Un modelo es la clase que sabe hablar con una tabla: tiene sus consultas y
 * devuelve filas ya con forma de array. Los controladores no escriben SQL y los
 * servicios tampoco; todo el SQL vive aqui.
 *
 * ============================================================================
 * POR QUE NO HAY UNA CAPA DE MODELO ACTIVA NI UN ORM
 * ============================================================================
 *
 * Por la decision D14, sin dependencias. Un ORM como Eloquent traeria una
 * capa de abstraccion completa para gestionar algo que aqui son quince
 * tablas con consultas directas. Ademas, en el nucleo de la adjudicacion hace
 * falta un control milimetrico sobre la transaccion y sobre el numero de filas
 * afectadas, y una capa de abstraccion siempre interpone algo entre el codigo y
 * el UPDATE que decide si un premio se entrega. Esa es justo la parte que no
 * se puede delegar.
 *
 * ============================================================================
 * POR QUE EL SQL VIVE AQUI Y NO EN EL CONTROLADOR
 * ============================================================================
 *
 * Tres razones, en orden de importancia:
 *
 *   1. Es la unica garantia de que las consultas van parametrizadas. Si cada
 *      controlador pudiera escribir su propio SQL, la seguridad pasaria por la
 *      buena memoria de quien programa.
 *   2. Permite reutilizar. La cola de premios se consulta desde el servicio de
 *      adjudicacion, del panel de seguimiento y de las pruebas, y las tres
 *      necesitan exactamente la misma ordenacion.
 *   3. Facilita cambiar el esquema. Si hay que anadir una columna, se toca el
 *      modelo y las migraciones, no quince controladores.
 *
 * @see \App\Core\Db
 * @see decisiones D11 y D14
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Clase base de acceso a una tabla.
 */
abstract class Modelo
{
    /**
     * Conexion a la base de datos que usa el modelo.
     *
     * @var \App\Core\Db
     */
    protected Db $db;

    /**
     * Nombre de la tabla que representa el modelo.
     *
     * @var string
     */
    protected string $tabla = '';

    /**
     * Construye el modelo con la conexion de la aplicacion.
     */
    public function __construct()
    {
        $this->db = Aplicacion::db();
    }

    /**
     * Devuelve el nombre de la tabla del modelo.
     *
     * @return string Nombre de la tabla en la base de datos.
     */
    public function tabla(): string
    {
        return $this->tabla;
    }

    /**
     * Busca una fila por su clave primaria.
     *
     * @param int $id Identificador de la fila.
     *
     * @return array<string, mixed>|null Fila completa, o null si no existe.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function buscarPorId(int $id): ?array
    {
        // El nombre de la tabla y el de la columna se interpolan en la
        // sentencia, y no se pueden pasar como parametros. No es una inyeccion
        // por si misma, porque los dos valores salen de las propiedades del
        // modelo, que estan escritas en el codigo y no vienen de la peticion.
        // Aun asi, se comprueba que no contengan caracteres sospechosos, para
        // que un cambio futuro en el nombre de la tabla no abra la puerta sin
        // que nadie lo note.
        $this->comprobarNombreDeTabla();

        return $this->db->uno(
            'SELECT * FROM ' . $this->tabla . ' WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Comprueba que el nombre de la tabla es seguro para interpolarlo.
     *
     * Solo se admiten letras, digitos y guiones bajos. Un nombre con un punto o
     * un espacio haria que la consulta fallara, y uno con un parentesis o una
     * coma podria alterar la sentencia.
     *
     * @return void
     *
     * @throws \App\Core\ErrorAplicacion Si el nombre no es valido.
     */
    protected function comprobarNombreDeTabla(): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $this->tabla) !== 1) {
            throw new ErrorAplicacion(
                "El nombre de tabla «{$this->tabla}» del modelo " . static::class . ' no es valido.'
            );
        }
    }
}
