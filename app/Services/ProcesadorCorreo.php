<?php

/**
 * Procesador de la cola de correo.
 *
 * ============================================================================
 * POR QUE ESTE NO ES UN LLAMADO A ENCOLAR
 * ============================================================================
 *
 * Porque el encolado ocurre dentro de la transaccion de adjudicacion y el envio
 * no puede (D1). Son dos momentos distintos y por dos razones distintas.
 *
 * El encolado va dentro de la transaccion porque un mensaje encolado y una
 * adjudicacion han de ser la misma cosa o no ser nada: si se confirmara la
 * adjudicacion y fallara el INSERT del mensaje, quedaria un premio entregado sin
 * aviso a la clienta, que es el fallo que la especificacion prohíbe expresamente
 * en el apartado 5.4. Encolar por detras, en cambio, deja la posibilidad de que
 * la adjudicacion se guarde y luego el proceso muera antes de encolar.
 *
 * El envio va fuera de la transaccion porque es lento y no se puede deshacer. Si
 * el correo se mandara antes de confirmar, un servidor de correo que tardase
 * veinte segundos en responder tendria a la azafata mirando una pantalla
 * congelada veinte segundos por cada participacion, y si el correo saliera y la
 * adjudicacion no llegara a confirmarse, se habria repartido un premio que el
 * sistema no tiene registrado. La latencia del correo nunca puede decidir si
 * una adjudicacion existe.
 *
 * ============================================================================
 * EL ORDEN DE LAS OPERACIONES, QUE ES LO QUE EVITA LOS CORREOS DOBLES
 * ============================================================================
 *
 * Por cada mensaje, en este orden y no en otro:
 *
 *   1. Marcarlo como «enviando» con un UPDATE condicionado al estado. Si el
 *      UPDATE no toca ninguna fila, otro proceso se ha adelantado y este mensaje
 *      se deja en paz. Es el unico sitio donde se decide que un mensaje es de
 *      este proceso, y por eso va ANTES de enviar: al reves, dos procesos
 *      podrian leer la misma fila pendiente, los dos mandar el correo, y la
 *      clienta recibiria dos veces el mensaje de su premio.
 *
 *   2. Enviar con el transporte que dice la fila.
 *
 *   3. Anotar el resultado, «enviado» con su fecha, o «error» con su motivo y
 *      hasta cuando no se reintenta.
 *
 * Que el paso 1 sea un UPDATE condicionado y no un simple «ponle el estado» es
 * justo lo que hace que esto aguante dos procesos a la vez sin bloqueos ni
 * tablas de reparto. No es una optimizacion: es la garantia.
 *
 * ============================================================================
 * POR QUE UN FALLO NO SE PROPAGA NI SE REINTENTA EN EL MISMO ACTO
 * ============================================================================
 *
 * Cuando un envio falla, el mensaje pasa a «error» y no vuelve a «pendiente» en
 * esta misma pasada. Volverlo encolarlo en bucle convertiria un buzon de correo
 * caido en un processor que se pasa la vida fallando lo mismo, y con el campo
 * de la lista en primer plano. Ademas, casi todos los fallos de SMTP son
 * permanentes mientras duren: una direccion que no existe no empieza a existir
 * porque se reintente veinte veces en el mismo segundo. El mensaje vuelve a la
 * cola cuando un proceso posterior lo reinicie, y para eso esta
 * \App\Models\Correo::marcarError(), que ademas retrasa el siguiente intento
 * segun los intentos que lleva.
 *
 * ============================================================================
 * @see \App\Models\Correo
 * @see \App\Services\Mailer
 * @see \App\Services\MailerLog
 * @see \App\Services\MailerSmtp
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;
use App\Models\Correo;

/**
 * Vacia la cola de correos salientes.
 */
final class ProcesadorCorreo
{
    private Correo $correos;
    private Mailer $log;
    private ?Mailer $smtp;

    /**
     * @param Correo|null $correos Modelo de la cola. Por defecto se construye
     *                              uno, que es lo normal en produccion.
     * @param Mailer|null $log     Transporte de registro.
     * @param Mailer|null $smtp    Transporte SMTP, si ya se quiere pasar uno.
     */
    public function __construct(
        ?Correo $correos = null,
        ?Mailer $log = null,
        ?Mailer $smtp = null
    ) {
        $this->correos = $correos ?? new Correo();
        $this->log = $log ?? new MailerLog();

        // El transporte SMTP se deja sin construir y se arma la primera vez que
        // hace falta, y no en el constructor. Construirlo aqui haria que el
        // processor no se pudiera crear en una instalacion sin direccion de
        // remitente, ni siquiera para mandar al log, que es justo lo que se
        // necesita cuando SMTP no esta configurado. Asi se puede vaciar la cola
        // con el transporte de log sin tocar nada mas de la configuracion.
        $this->smtp = $smtp;
    }
    /**
     * Envia los mensajes pendientes de una pasada.
     *
     * No hay bucle: una pasada envia hasta «limite» mensajes y se termina. Quien
     * la llama decide si repite, y normalmente es un proceso que se ejecuta cada
     * pocos minutos. Recorrer la cola entera sin parar desde dentro seria un
     * problema: si el servidor de correo acepta mensajes despacio, el proceso
     * duraria horas sin descanso y no atenderia a nada mas, y si la cola no
     * llegara nunca a vaciarse del todo, ese proceso no terminaria nunca.
     *
     * @param int $limite Maximo de mensajes que se intentan en esta pasada.
     *
     * @return array<string, int> Recuento de lo que ha pasado: enviados,
     *                           fallidos y omitidos, mas los totales.
     *
     * @throws \App\Core\ErrorBaseDeDatos Si la cola no se puede leer o escribir.
     */
    public function procesar(int $limite = 50): array
    {
        $recuento = [
            'enviados' => 0,
            'fallidos' => 0,
            'omitidos' => 0,
            'vistos' => 0,
        ];

        foreach ($this->correos->pendientes($limite) as $mensaje) {
            $recuento['vistos']++;
            $id = (int) $mensaje['id'];

            // El paso que decide la propiedad. Si el UPDATE no afecta a ninguna
            // fila, otro proceso ya tiene este mensaje y enviarlo aqui seria
            // mandar el correo dos veces.
            if (!$this->correos->marcarEnviando($id)) {
                $recuento['omitidos']++;
                continue;
            }

            $intentos = (int) $mensaje['intentos'] + 1;
            $transporte = (string) $mensaje['transporte'];
            $enviado = false;
            $motivo = '';

            try {
                $mailer = $this->transportePara($transporte);

                $enviado = $mailer->enviar(
                    (string) $mensaje['destinatario'],
                    (string) $mensaje['asunto'],
                    (string) $mensaje['cuerpo']
                );
                $motivo = trim($mailer->ultimoError());

                if ($enviado) {
                    $this->correos->marcarEnviado($id, $mailer->nombre());
                    $recuento['enviados']++;
                    continue;
                }
            } catch (\Throwable $e) {
                // Esto no deberia pasar: los transportes devuelven false en vez
                // de lanzar, y armar el SMTP sin direccion de remitente es el
                // unico fallo que se espera aqui. Se recoge igualmente porque el
                // mensaje ya esta en estado «enviando» y sin este borde se
                // quedaria ahi para siempre, sin reintentarse nunca y sin que
                // nadie se entere, que es la peor forma de perder un aviso de
                // premio. Es mas grave perderlo que avisar del fallo con otro
                // formato.
                $motivo = $e->getMessage();
            }

            $this->correos->marcarError(
                $id,
                $motivo === ''
                    ? 'el transporte ' . ($transporte === 'smtp' ? 'smtp' : 'log') . ' no pudo enviarlo'
                    : $motivo,
                $intentos
            );
            $recuento['fallidos']++;
        }

        return $recuento;
    }

    /**
     * Devuelve el transporte que pide un mensaje.
     *
     * @param string $nombre Nombre guardado en la fila, «log» o «smtp».
     *
     * @return Mailer Transporte correspondiente.
     */
    private function transportePara(string $nombre): Mailer
    {
        // Lo que no sea «smtp» cae en «log», y no al reves. Un mensaje con un
        // transporte desconocido, o con uno que se haya escrito mal, se envia al
        // log en lugar de quedarse sin hacer: mandarlo al log siempre funciona y
        // deja el texto a la vista, mientras que descartarlo perderia un aviso de
        // premio que ya se adjudico. Y no se manda a SMTP «por si acaso», porque
        // si la configuracion de SMTP esta mal seria justo cuando mas dano
        // haria intentarlo.
        if ($nombre !== 'smtp') {
            return $this->log;
        }

        // Se construye la primera vez y se guarda en la propiedad, para no
        // construirlo en cada mensaje. Si la configuracion de SMTP no sirve,
        // falla aqui y el processor sigue, que es lo que se quiere: es mejor
        // dejar el mensaje en error con su motivo que parar la cola entera.
        if ($this->smtp === null) {
            $this->smtp = new MailerSmtp();
        }

        return $this->smtp;
    }

    /**
     * Transporte configurado ahora mismo, para el guion de linea de comandos.
     *
     * @return string «log» o «smtp».
     */
    public static function transporteConfigurado(): string
    {
        $valor = (string) (Aplicacion::config()['correo']['transporte'] ?? 'log');

        return $valor === 'smtp' ? 'smtp' : 'log';
    }
}
