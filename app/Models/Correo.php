<?php

/**
 * Modelo de la cola de correo.
 *
 * ============================================================================
 * POR QUE ESTA CLASE ESTA EN EL HITO 2 Y NO EN EL HITO 5
 * ============================================================================
 *
 * El hito 5 es el del transporte de correo, con los dos transportes 'log' y
 * 'smtp' y los reintentos. Lo que se construye aqui es otra cosa, y la decision
 * D1 la separa de forma expresa: el mensaje se ENCOLA dentro de la misma
 * transaccion que adjudica el premio, y se ENVIA despues, por separado.
 *
 * La distincion no es academica. Si el envio ocurriera dentro de la transaccion,
 * dos cosas irian mal. Una, la latencia: una tablet esperando a que un servidor
 * de correo conteste ve congelada la pantalla de una clienta, y el apartado 4
 * pide una interfaz de supermercado que no dé esa impresion. Dos, y grave: si la
 * transaccion se deshiciera por un fallo posterior, la clienta habria recibido
 * un correo de premio que despues no es cierto.
 *
 * Encolando aqui y enviando en el hito 5, un fallo de envio nunca revierte la
 * adjudicacion, que es lo que exigen el apartado 9 y el caso de aceptacion 9.
 *
 * ============================================================================
 * LOS MARCADORES Y POR QUE NO SE SUSTITUYE NADA ARBITRARIO
 * ============================================================================
 *
 * Los textos del correo vienen configurados en la campana y llevan marcadores
 * entre llaves: {{nombre}}, {{codigo}}, {{premio}}, {{promocion}}. Solo se
 * sustituyen los que esten en la lista de permitidos.
 *
 * No es una comodidad. Si se sustituyera cualquier patron con llaves, el nombre
 * de una clienta podria contener «{{otro}}» y el texto se deformaria, y peor,
 * un valor con saltos de linea y cabeceras podria inyectar un destinatario o
 * un asunto. La lista blanca convierte la sustitucion en una operacion que no
 * puede hacer nada inesperado. El apartado 4.9 los llama «variables
 * controladas», y este es el sitio donde esa palabra se cumple.
 *
 * @see \App\Services\Adjudicador
 * @see apartado 4.9 de la especificacion, mensajes y variables controladas
 * @see apartado 6 de la especificacion, correo y adjudicacion
 * @see decisiones D1 y D10
 */

declare(strict_types=1);

namespace App\Models;

use App\Core\Aplicacion;
use App\Core\ErrorValidacion;
use App\Core\Modelo;

/**
 * Acceso a la cola de correo y encolado de mensajes.
 */
class Correo extends Modelo
{
    /**
     * Nombre de la tabla en la base de datos.
     *
     * @var string
     */
    protected string $tabla = 'correos';

    /**
     * Mensaje para la persona que se ha llevado un premio.
     *
     * @var string
     */
    public const TIPO_GANADOR = 'ganador';

    /**
     * Mensaje para la persona que ha participado sin premio.
     *
     * @var string
     */
    public const TIPO_NO_GANADOR = 'no_ganador';

    /**
     * Los marcadores que se pueden sustituir en los textos de la campana.
     *
     * La lista es cerrada a proposito. Ver el comentario de la cabecera del
     * fichero.
     *
     * @var array<int, string>
     */
    private const MARCADORES = ['nombre', 'codigo', 'premio', 'promocion'];

    /**
     * Encola un mensaje de la cola de correo.
     *
     * No hace NADA si la campana no pide ese tipo de correo. Es una decision
     * deliberada del esquema, no un descuido: correo_ganador y correo_no_ganador
     * estan apagados por defecto porque enviar correo a una clienta que no lo ha
     * pedido es de las peores cosas que puede hacer un supermercado, y el valor
     * por defecto tiene que ser el prudente.
     *
     * La llamada va SIEMPRE dentro de la transaccion de adjudicacion. Si esa
     * transaccion se deshace, el mensaje tampoco sale, y esa es justamente la
     * garantia de D1: no se puede enviar un correo de premio a alguien que al
     * final no lo recibio.
     *
     * @param int                   $promocionId    Campana del mensaje.
     * @param int                   $participacionId Participacion a la que
     *                                               corresponde el mensaje.
     * @param string                $tipo           TIPO_GANADOR o
     *                                               TIPO_NO_GANADOR.
     * @param array<string, mixed>  $plantilla      Fila de promociones, de la
     *                                               que se leen el asunto, el
     *                                               cuerpo y los interruptores.
     * @param array<string, string> $valores        Valores a sustituir en los
     *                                               marcadores.
     *
     * @return int|null Identificador del mensaje encolado, o null si la campana
     *                  no pide ese tipo de correo.
     *
     * @throws \App\Core\ErrorValidacion   Si no hay ningun campo de correo en
     *                                     los datos de la participacion.
     * @throws \App\Core\ErrorBaseDeDatos Si la insercion falla.
     */
    public function encolar(
        int $promocionId,
        int $participacionId,
        string $tipo,
        array $plantilla,
        array $valores
    ): ?int {
        $interruptor = $tipo === self::TIPO_GANADOR
            ? 'correo_ganador'
            : 'correo_no_ganador';

        // Se comprueba el interruptor con un === 1 y no con un if directo sobre
        // el valor: en la base puede venir como cadena «1» segun como lo haya
        // escrito quien lo guardo, y «1» es cierto en PHP, pero «0» tambien es
        // cierto si llega como cadena. La comparacion estricta evita el fallo.
        if ((int) ($plantilla[$interruptor] ?? 0) !== 1) {
            return null;
        }

        $prefijoAsunto = $tipo === self::TIPO_GANADOR ? 'correo_ganador' : 'correo_no_ganador';
        $asunto = (string) ($plantilla[$prefijoAsunto . '_asunto'] ?? '');
        $cuerpo = (string) ($plantilla[$prefijoAsunto . '_cuerpo'] ?? '');

        if (trim($cuerpo) === '') {
            // Un mensaje sin cuerpo no se encola. Encolar un cuerpo vacio
            // crearia una fila que el procesador del hito 5 no podria enviar y
            // que apareceria como un fallo de envio mas adelante, cuando el
            // problema real es que al administrador no le ha dado tiempo a
            // escribir el texto.
            return null;
        }

        $destinatario = $this->destinatarioDe($valores);

        return $this->db->insertar(
            'INSERT INTO correos (
                 promocion_id,
                 participacion_id,
                 tipo,
                 destinatario,
                 asunto,
                 cuerpo,
                 variables,
                 transporte,
                 estado,
                 creado_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $promocionId,
                $participacionId,
                $tipo,
                $destinatario,
                $this->sustituir($asunto, $valores),
                $this->sustituir($cuerpo, $valores),
                json_encode($valores, JSON_UNESCAPED_UNICODE),
                $this->transporteConfigurado(),
                'pendiente',
                \App\Core\Aplicacion::ahora(),
            ]
        );
    }

    /**
     * Transporte con el que se encolan los mensajes nuevos.
     *
     * Se lee de la configuracion y no se escribe 'log' a pelo, porque si el
     * mensaje quedara con el transporte puesto a mano cambiar «correo.transporte»
     * a 'smtp' en config.php no serviria de nada: la cola seguiria llena de
     * mensajes marcados como 'log' y el procesador los escribiria en el log como
     * si nada. Guardando el transporte en la fila, cada mensaje recuerda por que
     * camino iba cuando se encolo, y cambiar de 'log' a 'smtp' se nota en los
     * mensajes siguientes sin tocar los que ya estaban en la cola.
     *
     * @return string «log» o «smtp», o 'log' si la configuracion dice otra cosa.
     */
    private function transporteConfigurado(): string
    {
        $valor = (string) (Aplicacion::config()['correo']['transporte'] ?? 'log');

        return $valor === 'smtp' ? 'smtp' : 'log';
    }

    /**
     * Cuenta los mensajes de una campana por estado.
     *
     * Lo usan las pruebas, para comprobar que una adjudicacion ha encolado el
     * mensaje y no lo ha enviado, y lo usara el panel de seguimiento.
     *
     * @param int $promocionId Campana que se quiere contar.
     *
     * @return array<string, int> Estados como claves y recuentos como valores.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function contarPorEstado(int $promocionId): array
    {
        $filas = $this->db->todos(
            'SELECT estado, COUNT(*) AS total
               FROM correos
              WHERE promocion_id = ?
              GROUP BY estado',
            [$promocionId]
        );

        $recuento = [];

        foreach ($filas as $fila) {
            $recuento[(string) $fila['estado']] = (int) $fila['total'];
        }

        return $recuento;
    }

    /**
     * Saca de la cola los mensajes pendientes que se pueden intentar ya.
     *
     * La consulta usa el indice ix_correos_cola (estado, bloqueado_hasta,
     * creado_en), que es exactamente el orden en que se pide: primero los
     * pendientes, y entre ellos los mas antiguos. Ordenarlos por creado_en no es
     * cosmetico, es lo que hace que un correo de premio salga antes que un «gracias
     * por participar» que se encolo despues, y no al reves.
     *
     * Se excluyen los que tienen bloqueado_hasta en el futuro. Sin esa condicion,
     * un mensaje cuyo envio fallo volveria a intentarse en cada pasada del
     * procesador, y una direccion de correo que no existe —el error mas comun—
     * haria que cada pasada tardara lo mismo en fallar otra vez, sin que nadie
     * llegue a ver el mensaje de los que si funcionan.
     *
     * El paso a «enviando» NO se hace aqui. Se hace justo antes de enviar, con
     * una actualizacion condicionada al estado, y por el motivo que explica
     * \App\Services\ProcesadorCorreo: dos procesos que se solapan no pueden
     * enviar el mismo mensaje dos veces.
     *
     * @param int $limite Maximo de mensajes que se devuelven.
     *
     * @return array<int, array<string, mixed>> Mensajes, del mas antiguo al mas
     *                                       reciente.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function pendientes(int $limite = 50): array
    {
        return $this->db->todos(
            'SELECT id, promocion_id, destinatario, asunto, cuerpo, transporte,
                    intentos, creado_en
               FROM correos
              WHERE estado = ?
                AND (bloqueado_hasta IS NULL OR bloqueado_hasta <= ?)
              ORDER BY creado_en ASC
              LIMIT ' . max(1, $limite),
            ['pendiente', Aplicacion::ahora()]
        );
    }

    /**
     * Pasa un mensaje a «enviando», y solo si seguia en «pendiente».
     *
     * El «y solo si» es la parte importante y la razon de que devuelva un
     * booleano en vez de dar por hecho el paso. Dos procesos pueden leer la misma
     * fila pendiente en el mismo instante si la cola se vacia a la vez desde dos
     * lado; sin esta condicion, los dos pasarian el estado a «enviando» y los dos
     * enviarian el correo, con lo que la clienta recibiria el mensaje de premio
     * dos veces. La condicion hace que el segundo reciba false y se lo deje al
     * primero, que ya lo esta enviando.
     *
     * @param int $id Identificador del mensaje.
     *
     * @return bool True si este proceso se ha quedado con el mensaje.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la consulta falla.
     */
    public function marcarEnviando(int $id): bool
    {
        return $this->db->ejecutar(
            "UPDATE correos
                SET estado = 'enviando', intentos = intentos + 1
              WHERE id = ? AND estado = 'pendiente'",
            [$id]
        ) === 1;
    }

    /**
     * Anota que un mensaje se ha enviado.
     *
     * @param int    $id   Identificador del mensaje.
     * @param string $tipo Transporte por el que ha salido, para poder distinguir
     *                     un envio real de uno que solo se ha escrito en el log.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function marcarEnviado(int $id, string $tipo = 'log'): void
    {
        $this->db->ejecutar(
            "UPDATE correos
                SET estado = 'enviado',
                    enviado_en = ?,
                    ultimo_error = NULL,
                    transporte = ?
              WHERE id = ?",
            [Aplicacion::ahora(), $tipo, $id]
        );
    }

    /**
     * Anota que un mensaje no se ha podido enviar y hasta cuando no se reintenta.
     *
     * El estado pasa a «error» y no vuelve a «pendiente» por el mismo camino, que
     * es a proposito: un mensaje que ha fallado no se debe reenviar en bucle dentro
     * de la misma pasada. Vuelve a la cola cuando un proceso posterior lo
     * reinicie, y mientras tanto se puede ver que fallo y por que.
     *
     * El bloqueo se retrasa cada vez mas segun los intentos que lleva, para que un
     * destino que esta caido no se reintente en cada pasada mientras se decide que
     * hacer con el. El primer fallo espera poco, porque puede haber sido un fallo
     * puntual; a partir del cuarto, espera un dia entero, porque a partir de ahi lo
     * probable es que la direccion no exista.
     *
     * @param int    $id     Identificador del mensaje.
     * @param string $motivo Texto del fallo, que se guarda recortado.
     * @param int    $intentos Numero de intentos que lleva el mensaje.
     *
     * @return void
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la escritura falla.
     */
    public function marcarError(int $id, string $motivo, int $intentos = 1): void
    {
        $minutos = min(1440, 2 ** min(6, max(0, $intentos)) * 5);
        $hasta = (new \DateTimeImmutable('now'))->modify('+' . $minutos . ' minutes');

        $this->db->ejecutar(
            "UPDATE correos
                SET estado = 'error',
                    ultimo_error = ?,
                    bloqueado_hasta = ?
              WHERE id = ?",
            [mb_substr($motivo, 0, 500), $hasta->format('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Saca de los valores el campo que hace de direccion de correo.
     *
     * Se busca una clave llamada «correo» o «email», con cualquiera de las dos
     * grafias, porque el nombre del campo lo decide el administrador al
     * configurar el formulario y no hay una obligacion de que se llame siempre
     * igual.
     *
     * @param array<string, string> $valores Valores de la participacion.
     *
     * @return string Direccion de correo de la destinataria.
     *
     * @throws \App\Core\ErrorValidacion Si no hay ningun campo de correo, que
     *                                    significa que la campana no pide
     *                                    correo y no deberia encolar nada.
     */
    private function destinatarioDe(array $valores): string
    {
        foreach (['correo', 'email', 'e_mail'] as $clave) {
            if (isset($valores[$clave]) && trim($valores[$clave]) !== '') {
                return trim($valores[$clave]);
            }
        }

        throw new ErrorValidacion(
            'La campana encola correo pero los datos de la participacion no tienen ningun '
            . 'campo de correo. Revisa la configuracion del formulario.'
        );
    }

    /**
     * Sustituye los marcadores permitidos de un texto.
     *
     * Solo se tocan los marcadores de la lista blanca. Un «{{cualquiera}}» que
     * venga en un valor de la participacion se deja tal cual, y no se intenta
     * sustituir de forma recursiva, de modo que un valor no puede inyectar
     * marcadores en el texto que se acaba de escribir.
     *
     * @param string               $texto   Texto de la campana, con marcadores.
     * @param array<string, string> $valores Valores disponibles.
     *
     * @return string Texto con los marcadores sustituidos.
     */
    private function sustituir(string $texto, array $valores): string
    {
        foreach (self::MARCADORES as $marcador) {
            if (!array_key_exists($marcador, $valores)) {
                continue;
            }

            $texto = str_replace('{{' . $marcador . '}}', $valores[$marcador], $texto);
        }

        return $texto;
    }
}
