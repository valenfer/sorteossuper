<?php

/**
 * Modelo de la configuracion visual de una campana.
 *
 * ============================================================================
 * POR QUE ESTA SEPARADA DE promociones
 * ============================================================================
 *
 * El comentario del esquema lo explica: son «datos que se tocan desde una pantalla
 * de configuracion distinta y no se mezclan con los datos generales». La
 * separacion no es puramente estetica. Los datos generales —nombre, estado,
 * comercio, fechas— los escribe una persona al abrir la campana; los colores y
 * las imagenes los escribe otra al final, cuando ya ha visto como queda. Si
 * estuvieran en la misma tabla, cada cambio de un color guardaria el nombre del
 * comercio y las fechas con el valor que tuvieran hace una hora, y dos
 * administradores trabajando a la vez se pisarian.
 *
 * ============================================================================
 * POR QUE LOS COLORES SON UN TEXTO Y NO UNA TABLA
 * ============================================================================
 *
 * Son seis valores de un patron fijo, y se guardan como columnas VARCHAR(7) con
 * la almohadilla, del tipo «#AABBCC». Una tabla de colores seria mas
 * «correcta» en un modelo relacional, pero obligaria a seccionar y a reensamblar
 * en cada lectura, y no da ninguna ventaja real: no hay consultas sobre el color,
 * no hay unicidad que comprobar y no hay historial que conservar.
 *
 * La validacion del formato la hace la pantalla que los edita, y no una
 * restriccion, porque un patron como «^#([0-9a-fA-F]{6})$» no se puede expresar
 * con el CHECK de MariaDB 10.4 sin recurrir a una expresion regular, que si
 * existe pero con sintaxis distinta de la de MySQL. Se valida en PHP, que es donde
 * estan el resto de las reglas de la pantalla.
 *
 * ============================================================================
 * POR QUE LAS RUTAS DE IMAGEN SON RELATIVAS
 * ============================================================================
 *
 * «uploads/img_20260315_ab12cd.jpg» y nunca «C:\xampp\htdocs\sorteos\uploads\...».
 * Una ruta absoluta dejaria de funcionar en cuanto la campana se copiara a otra
 * carpeta o a otro servidor, que es exactamente como se despliega esto: copiando
 * una carpeta. Ademas, una ruta absoluta en la base de datos dice donde esta el
 * disco del servidor a cualquiera que pueda leer una copia de la base, que no es
 * informacion que deba aparecer por ahi.
 *
 * @see \App\Services\Imagenes
 * @see apartado 4.9 de la especificacion, aspecto visual y mensajes
 * @see decision D14 del documento de especificacion, custom properties en CSS
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\Modelo;

/**
 * Acceso a la tabla de configuracion visual.
 */
class ConfiguracionVisual extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'configuracion_visual';

    /**
     * Devuelve la configuracion visual de una campana, o los valores por defecto.
     *
     * Igual que en las reglas, la ausencia de fila no es un problema: una campana
     * recien creada se ve con la paleta por defecto, que es la que esta en
     * :root de la hoja de estilos, y por eso los valores de aqui coinciden con
     * los del CSS. Duplicarlos es lo que hace posible que un supermercado con su
     * propia marca pueda cambiar un color sin que el resto de la aplicacion se
     * entere de nada.
     *
     * @param int $promocionId Campana cuya configuracion se quiere.
     *
     * @return array<string, mixed> Configuracion completa, con la clave
     *                            «promocion_id» incluida.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function leer(int $promocionId): array
    {
        $visual = $this->db->uno(
            'SELECT * FROM configuracion_visual WHERE promocion_id = ?',
            [$promocionId]
        );

        if ($visual === null) {
            $visual = $this->valoresPorDefecto();
            $visual['promocion_id'] = $promocionId;

            return $visual;
        }

        return $visual;
    }

    /**
     * Guarda la configuracion visual de una campana, creandola si no existe.
     *
     * @param int                  $promocionId Campana a la que pertenece.
     * @param array<string, mixed> $datos       Colores, rutas de imagen y textos,
     *                                          ya validados.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function guardar(int $promocionId, array $datos): void
    {
        $this->db->ejecutar(
            'INSERT INTO configuracion_visual (
                 promocion_id,
                 banner_sup_ruta, banner_sup_alt,
                 banner_pie_ruta, banner_pie_alt,
                 resultado_premio_ruta, resultado_premio_texto,
                 resultado_no_premio_ruta, resultado_no_premio_texto,
                 texto_ganador, texto_no_ganador,
                 color_fondo, color_texto, color_primario, color_acento,
                 color_campos, color_bordes,
                 actualizado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 banner_sup_ruta = VALUES(banner_sup_ruta),
                 banner_sup_alt = VALUES(banner_sup_alt),
                 banner_pie_ruta = VALUES(banner_pie_ruta),
                 banner_pie_alt = VALUES(banner_pie_alt),
                 resultado_premio_ruta = VALUES(resultado_premio_ruta),
                 resultado_premio_texto = VALUES(resultado_premio_texto),
                 resultado_no_premio_ruta = VALUES(resultado_no_premio_ruta),
                 resultado_no_premio_texto = VALUES(resultado_no_premio_texto),
                 texto_ganador = VALUES(texto_ganador),
                 texto_no_ganador = VALUES(texto_no_ganador),
                 color_fondo = VALUES(color_fondo),
                 color_texto = VALUES(color_texto),
                 color_primario = VALUES(color_primario),
                 color_acento = VALUES(color_acento),
                 color_campos = VALUES(color_campos),
                 color_bordes = VALUES(color_bordes),
                 actualizado_en = VALUES(actualizado_en)',
            [
                $promocionId,
                $datos['banner_sup_ruta'],
                $datos['banner_sup_alt'],
                $datos['banner_pie_ruta'],
                $datos['banner_pie_alt'],
                $datos['resultado_premio_ruta'],
                $datos['resultado_premio_texto'] !== '' ? $datos['resultado_premio_texto'] : null,
                $datos['resultado_no_premio_ruta'],
                $datos['resultado_no_premio_texto'] !== '' ? $datos['resultado_no_premio_texto'] : null,
                $datos['texto_ganador'] !== '' ? $datos['texto_ganador'] : null,
                $datos['texto_no_ganador'] !== '' ? $datos['texto_no_ganador'] : null,
                $datos['color_fondo'],
                $datos['color_texto'],
                $datos['color_primario'],
                $datos['color_acento'],
                $datos['color_campos'],
                $datos['color_bordes'],
                Aplicacion::ahora(),
            ]
        );
    }

    /**
     * Devuelve la paleta y los textos por defecto.
     *
     * Los colores son los mismos que estan en :root de assets/css/estilos.css. No
     * se leen de ahi en tiempo de ejecucion porque hacerlo exigiria leer y
     * analizar la hoja de estilos en cada peticion, y porque el CSS es la fuente
     * que vale para las pantallas que no son de una campana concreta —el acceso,
     * por ejemplo—, que no tienen configuracion visual que leer.
     *
     * @return array<string, mixed> Valores por defecto de todas las columnas.
     */
    public function valoresPorDefecto(): array
    {
        return [
            'promocion_id'              => 0,
            'banner_sup_ruta'           => '',
            'banner_sup_alt'            => '',
            'banner_pie_ruta'           => '',
            'banner_pie_alt'            => '',
            'resultado_premio_ruta'     => '',
            'resultado_premio_texto'    => '',
            'resultado_no_premio_ruta'  => '',
            'resultado_no_premio_texto' => '',
            'texto_ganador'             => '',
            'texto_no_ganador'          => '',
            'color_fondo'               => '#f6f7f9',
            'color_texto'               => '#1a1a1a',
            'color_primario'            => '#14509b',
            'color_acento'              => '#e8a33d',
            'color_campos'              => '#ffffff',
            'color_bordes'              => '#d3d7dd',
            'actualizado_en'            => null,
        ];
    }

    /**
     * Devuelve la paleta como variables CSS.
     *
     * Es lo que permite cumplir el apartado 4.9 sin framework: cambiar un color en
     * el panel cambia una custom property, y todas las hojas de estilos la usan.
     * Escribir los valores con comillas dobles y comprobarlos con el mismo
     * patron que valida la pantalla es lo que evita que un color mal escrito
     * llegue al estilo y tumbe toda la pagina en lugar de una parte.
     *
     * @param array<string, mixed> $visual Configuracion visual de la campana.
     *
     * @return array<string, string> Variables CSS con el nombre y el valor.
     */
    public function variablesCss(array $visual): array
    {
        return [
            '--fondo'    => (string) $visual['color_fondo'],
            '--texto'    => (string) $visual['color_texto'],
            '--primario' => (string) $visual['color_primario'],
            '--acento'   => (string) $visual['color_acento'],
            '--campos'   => (string) $visual['color_campos'],
            '--bordes'   => (string) $visual['color_bordes'],
        ];
    }
}
