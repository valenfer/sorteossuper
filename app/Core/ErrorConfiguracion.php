<?php

/**
 * Excepcion de configuracion incorrecta o ausente.
 *
 * Se lanza en el arranque, antes de que la aplicacion atienda ninguna peticion,
 * cuando falta config/config.php, cuando le falta una clave imprescindible o
 * cuando un valor tiene un tipo o un rango que no sirve.
 *
 * El mensaje de esta excepcion SI puede mencionar rutas del proyecto y nombres
 * de claves: se muestra unicamente a quien instala, en la consola o en la
 * pantalla de arranque, nunca a una clienta del supermercado. Aun asi, no
 * incluye nunca el valor de una credencial, solo el nombre de la clave que
 * falta o que no es valida.
 *
 * @see bin\instalar.php
 * @see config\config.example.php
 */

declare(strict_types=1);

namespace App\Core;

/**
 * Error de configuracion, detectado durante el arranque.
 */
class ErrorConfiguracion extends ErrorAplicacion
{
    /**
     * Muestra un mensaje que indica que falta config/config.php e incluye los
     * pasos exactos para crearlo.
     *
     * @return self Excepcion preparada para mostrar al instalador.
     */
    public static function faltaArchivoConfig(): self
    {
        return new self(
            "No se encuentra el fichero config/config.php.\n"
            . "La aplicacion no puede arrancar sin el. Para crearlo, desde la raiz del proyecto:\n\n"
            . "    php bin\\instalar.php --crear-config\n\n"
            . "Tambien puede copiarse a mano la plantilla y ajustar las credenciales:\n\n"
            . "    copy config\\config.example.php config\\config.php\n"
        );
    }

    /**
     * Muestra un mensaje que indica que la variable de entorno que apunta al
     * fichero de configuracion no sirve.
     *
     * El mensaje es deliberadamente ruidoso. Si aqui se cayera en config/config.php
     * sin decir nada, una prueba creeria estar corriendo contra la base de
     * pruebas mientras escribe en la de la campana real, que es el peor fallo
     * posible aqui.
     *
     * @param string $indicada Ruta que venia en la variable de entorno.
     *
     * @return self Excepcion preparada para mostrar al instalador.
     */
    public static function rutaConfigInvalida(string $indicada): self
    {
        return new self(
            "La variable de entorno SORTEOS_CONFIG apunta a un sitio que no es un fichero:\n\n"
            . "    {$indicada}\n\n"
            . "La aplicacion no continua con la configuracion de config/config.php a proposito,\n"
            . "porque podria estar escribiendo donde no cree.\n"
        );
    }

    /**
     * Muestra un mensaje que indica que falta una clave de configuracion.
     *
     * @param string $ruta Ruta de la clave ausente, en notacion de puntos. Por
     *                      ejemplo 'bd.nombre'.
     *
     * @return self Excepcion preparada para mostrar al instalador.
     */
    public static function faltaClave(string $ruta): self
    {
        return new self(
            "Falta la clave de configuracion «{$ruta}».\n"
            . "Anadela a config/config.php o al final de config/config.example.php."
        );
    }
}
