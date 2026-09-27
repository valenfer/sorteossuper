<?php

/**
 * Transporte «smtp»: entrega el mensaje a un servidor SMTP real.
 *
 * ============================================================================
 * POR QUE HAY QUE ESCRIBIR UN CLIENTE SMTP ENTERO
 * ============================================================================
 *
 * Porque la instalacion de XAMPP de este entorno no tiene la extension mail()
 * (D1), y porque aunque la tuviera, mail() no sirve: no permite STARTTLS con
 * autenticacion, que es lo que exigen los buzones de salida de cualquier
 * proveedor de correo que no tenga un relay abierto, y no devuelve un motivo
 * cuando falla. Adjudicar y que el correo salga son dos cosas distintas (apartado
 * 5.4 de la especificacion), y para tener la segunda con un motivo que valga hace
 * falta hablar el protocolo.
 *
 * Por eso no se usa ninguna libreria externa: D14 prohibe las dependencias, y
 * ademas el unico cliente SMTP de PHP puro que hay ya trae sockets que hacen mas
 * de lo necesario y una configuracion que habria que envolver igual.
 *
 * ============================================================================
 * LOS TRES MODOS DE CONEXION, Y POR QUE NO SE PUEDE FIAR UNO
 * ============================================================================
 *
 *   - ''  : conexion a pelo. Solo vale contra un relay de la red local, y sin
 *           credenciales. Es el modo por defecto en la configuracion de ejemplo
 *           porque es el unico que se puede probar sin tener un buzon de verdad,
 *           y por eso lleva un aviso al lado.
 *   - 'tls': puerto 587 y STARTTLS. El cifrado se negocia DESPUES de conectar,
 *           con el comando STARTTLS, porque el puerto 587 esta pensado para
 *           recibir texto plano al principio y cifrar en cuanto se puede. Si se
 *           abriera un socket cifrado directamente, el servidor responderia con
 *           una cadena de bytes que no es una respuesta SMTP y la negociacion
 *           fallaria.
 *   - 'ssl': puerto 465 y TLS implicito. Ahi el cifrado se negocia al abrir el
 *           socket, y por eso el esquema del socket es «ssl://» y no «tcp://».
 *
 * Se elige con «correo.smtp.seguridad» y el unico valor que debe aparecer con
 * credenciales es uno de los dos cifrados. Poner '' con usuario y contrasena
 * manda la contrasena en claro por la red, y por eso hay un aviso en la
 * configuracion de ejemplo.
 *
 * ============================================================================
 * POR QUE EL ERROR SE DEVUELVE Y NO SE LANZA
 * ============================================================================
 *
 * Un buzon de correo que se cae es un resultado normal de la cola, no un fallo
 * del programa. Si este metodo lanzara, el processor pararia en el primer fallo y
 * los mensajes siguientes no se intentarian, con lo que se quedarian a la espera
 * hasta el dia siguiente, aunque estieran listos para salir. Ademas, la
 * especificacion pide que un fallo de correo no altere el resultado ya
 * adjudicado: eso se cumple escribiendo el motivo en la fila y siguiendo.
 *
 * ============================================================================
 * @see \App\Services\Mailer
 * @see \App\Services\MailerLog
 * @see \App\Services\ProcesadorCorreo
 */

declare(strict_types=1);

namespace App\Services;

use App\Core\Aplicacion;

/**
 * Envia el mensaje a un servidor SMTP hablando el protocolo a mano.
 */
final class MailerSmtp implements Mailer
{
    private string $host;
    private int $puerto;
    private string $seguridad;
    private string $usuario;
    private string $contrasena;
    private int $timeout;
    private string $remate;
    private string $remitente;
    private string $ultimoError = '';

    /**
     * @param string $remitente Direccion From de la que salen los mensajes.
     *
     * @throws \App\Core\ErrorConfiguracion Si no hay ninguna direccion de la
     *                                      que mandar correo.
     */
    public function __construct(string $remitente = '')
    {
        $correo = Aplicacion::config()['correo'] ?? [];
        $smtp = is_array($correo) ? ($correo['smtp'] ?? []) : [];

        $this->host = (string) ($smtp['host'] ?? 'localhost');
        $this->puerto = (int) ($smtp['puerto'] ?? 587);
        $this->seguridad = (string) ($smtp['seguridad'] ?? '');
        $this->usuario = (string) ($smtp['usuario'] ?? '');
        $this->contrasena = (string) ($smtp['contrasena'] ?? '');
        $this->timeout = max(1, (int) ($smtp['timeout'] ?? 10));
        $this->remate = (string) ($smtp['remate'] ?? "\r\n");
        $this->remitente = $remitente !== ''
            ? $remitente
            : (string) (is_array($correo) ? ($correo['remitente'] ?? '') : '');

        if (trim($this->remitente) === '') {
            // Sin una direccion de la que mandar no se puede construir la orden
            // MAIL FROM, y el fallo apareceria mas tarde, en mitad de la
            // conversacion con el servidor, como un error de SMTP cualquiera.
            // Aqui se sabe que el problema es la configuracion.
            throw new \App\Core\ErrorConfiguracion(
                'No hay direccion de remitente: rellena correo.remitente en la configuracion.'
            );
        }
    }

    /**
     * Envia el mensaje al servidor SMTP.
     *
     * @param string                $destinatario Direccion de correo.
     * @param string                $asunto       Asunto ya sustituido.
     * @param string                $cuerpo       Cuerpo ya sustituido.
     * @param array<string, string> $cabeceras    Cabeceras adicionales.
     *
     * @return bool True si el servidor ha aceptado el mensaje.
     */
    public function enviar(
        string $destinatario,
        string $asunto,
        string $cuerpo,
        array $cabeceras = []
    ): bool {
        $socket = null;
        $this->ultimoError = '';

        try {
            $socket = $this->abrir();
            $this->exigir($this->leer($socket), 220, 'el servidor no saluda');

            if (!$this->saludar($socket)) {
                throw new \RuntimeException('el servidor no acepta EHLO');
            }

            $this->autenticar($socket);

            // El protocolo es de peticion y respuesta, y manda una y lee su
            // respuesta antes de seguir: mandar la RCPT TO sin haber leido el 250
            // del MAIL FROM deja al servidor con un comando encima que no
            // esperaba, y a partir de ahi las respuestas dejan de corresponder
            // con lo que se ha pedido y el fallo aparece en un sitio
            // incomprensible.
            $this->escribir($socket, 'MAIL FROM:<' . $this->remitente . '>' . $this->remate);
            $this->exigir(
                $this->leer($socket),
                250,
                'el servidor rechaza el remitente',
                $this->remitente
            );

            $this->escribir($socket, 'RCPT TO:<' . $destinatario . '>' . $this->remate);
            $this->exigir(
                $this->leer($socket),
                250,
                'el servidor rechaza al destinatario',
                $destinatario
            );

            $this->escribir($socket, 'DATA' . $this->remate);
            $this->exigir($this->leer($socket), 354, 'el servidor no pide el cuerpo');

            $this->escribir($socket, $this->componer($asunto, $cuerpo, $cabeceras));
            $this->escribir($socket, '.' . $this->remate);

            $this->exigir($this->leer($socket), 250, 'el servidor no acepta el mensaje');

            $this->escribir($socket, 'QUIT' . $this->remate);
        } catch (\Throwable $e) {
            // El motivo se guarda para que el processor lo escriba en la columna
            // ultimo_error, y se deja tambien en el log. No se propaga: el
            // processor tiene que poder seguir con los mensajes siguientes, y la
            // adjudicacion que ya esta hecha no se toca porque falle el correo.
            $this->ultimoError = $e->getMessage();
            error_log('[correo:smtp] ' . $this->ultimoError);
            $this->cerrar($socket);

            return false;
        }

        $this->cerrar($socket);

        return true;
    }

    /**
     * @return string Siempre «smtp».
     */
    public function nombre(): string
    {
        return 'smtp';
    }

    /**
     * Motivo del ultimo fallo de envio.
     *
     * @return string Texto del error, o cadena vacia si el ultimo envio fue bien.
     */
    public function ultimoError(): string
    {
        return $this->ultimoError;
    }

    /**
     * Abre el socket al servidor, con TLS implicito si toca.
     *
     * Aqui solo se abre y, en el modo 'ssl', se cifra. El saludo 220 y el STARTTLS
     * se hacen despues, al hablar el protocolo, porque para el STARTTLS hace
     * falta haber leido la lista de capacidades del servidor. Abrir el socket es
     * una cosa de red y hablar SMTP es otra, y separarlas es lo que deja este
     * metodo con una unica responsabilidad.
     *
     * @return resource Socket abierto.
     *
     * @throws \RuntimeException Si no se puede conectar.
     */
    private function abrir()
    {
        // El esquema decide si el TLS se negocia al abrir o despues con
        // STARTTLS. Con 'ssl://' se cifra desde el primer byte; con 'tcp://' se
        // entra en claro y se sube con STARTTLS despues.
        $esquema = $this->seguridad === 'ssl' ? 'ssl://' : 'tcp://';

        $socket = @stream_socket_client(
            $esquema . $this->host . ':' . $this->puerto,
            $codigoError,
            $mensajeError,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf(
                'no se pudo conectar con %s:%d (%d %s)',
                $this->host,
                $this->puerto,
                $codigoError,
                $mensajeError
            ));
        }

        // El tiempo de espera tambien para leer y escribir, no solo para
        // conectar. Un servidor que acepta la conexion y luego se queda callado
        // es el caso habitual de un buzon saturado, y sin esto el processor se
        // quedaria colgado para siempre en una lectura.
        stream_set_timeout($socket, $this->timeout);

        if ($this->seguridad === 'ssl' && !$this->cifrar($socket)) {
            fclose($socket);

            throw new \RuntimeException('no se pudo cifrar el canal con TLS');
        }

        return $socket;
    }

    /**
     * Pasa el socket a modo cifrado.
     *
     * @param resource $socket Socket ya conectado.
     *
     * @return bool True si el socket ha quedado cifrado.
     *
     * @throws \RuntimeException Si la negociacion TLS falla.
     */
    private function cifrar($socket): bool
    {
        if (@stream_socket_enable_crypto(
            $socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        ) !== true) {
            throw new \RuntimeException('no se pudo cifrar el canal con TLS');
        }

        return true;
    }

    /**
     * Presenta el cliente con EHLO y sube a cifrado si toca.
     *
     * Se hace EHLO y no HELO porque EHLO es el que devuelve la lista de lo que el
     * servidor soporta, y sin ella no se puede saber si admite STARTTLS. Un
     * HELO no da esa lista, y por eso se dejaria el STARTLS sin hacer: el
     * servidor recibiria «STARTTLS» sin haber anunciado que lo soporta, y
     * contestaria con un 502, que es el fallo mas dificil de leer de todo SMTP.
     *
     * @param resource $socket Socket ya conectado, con el 220 ya leido.
     *
     * @return bool True si el servidor ha aceptado el saludo.
     *
     * @throws \RuntimeException Si no lo acepta o si no admite STARTTLS.
     */
    private function saludar($socket): bool
    {
        $nombre = $this->nombreDeServidor();

        $this->escribir($socket, 'EHLO ' . $nombre . $this->remate);
        $respuesta = $this->leer($socket);

        if ($this->seguridad === 'tls') {
            $this->exigir($respuesta, 250, 'el servidor no acepta EHLO');

            if (stripos($respuesta, 'STARTTLS') === false) {
                throw new \RuntimeException(
                    'el servidor no anuncia STARTTLS; no se mandara la contrasena en claro'
                );
            }

            $this->escribir($socket, 'STARTTLS' . $this->remate);
            $this->exigir($this->leer($socket), 220, 'el servidor no acepta STARTTLS');
            $this->cifrar($socket);

            // Hay que VOLVER a saludar: el servidor ha cambiado de estado y
            // obliga a repetir el EHLO sobre el canal cifrado, con el mismo
            // nombre. Sin este segundo saludo, casi todos los servidores cierran
            // la conexion en el AUTH, porque AUTH solo se admite despues de
            // STARTTLS y en una sesion nueva.
            $this->escribir($socket, 'EHLO ' . $nombre . $this->remate);
            $respuesta = $this->leer($socket);
        }

        return $this->exigir($respuesta, 250, 'el servidor no acepta EHLO');
    }

    /**
     * Se autentica con AUTH LOGIN si hay credenciales.
     *
     * Se usa LOGIN y no PLAIN porque PLAIN manda la contrasena en una sola linea
     * en Base64, que es Base64 y no nada: viaja en claro hasta que TLS este
     * activo. LOGIN la manda en dos pasos, lo que hace menos vergonzoso un fallo de
     * seguridad, aunque el motivo de peso es que es lo que aceptan practicamente
     * todos los buzones de salida. Sin usuario, no se hace nada, y es lo
     * correcto contra un relay de la red local.
     *
     * @param resource $socket Socket ya conectado y saludado.
     *
     * @return void
     *
     * @throws \RuntimeException Si las credenciales son rechazadas.
     */
    private function autenticar($socket): void
    {
        if ($this->usuario === '') {
            return;
        }

        $this->exigir($this->leer($socket), 334, 'el servidor no pide usuario');

        $this->escribir($socket, base64_encode($this->usuario) . $this->remate);
        $this->exigir($this->leer($socket), 334, 'el servidor no pide contrasena');

        $this->escribir($socket, base64_encode($this->contrasena) . $this->remate);
        $this->exigir($this->leer($socket), 235, 'credenciales rechazadas');
    }

    /**
     * Monta el mensaje completo con sus cabeceras y el cuerpo.
     *
     * @param string                $asunto    Asunto ya sustituido.
     * @param string                $cuerpo    Cuerpo ya sustituido.
     * @param array<string, string> $cabeceras Cabeceras adicionales.
     *
     * @return string Mensaje listo para enviar, con los puntos ya escapados.
     */
    private function componer(string $asunto, string $cuerpo, array $cabeceras): string
    {
        $cabeceras['From'] = $cabeceras['From'] ?? $this->remitente;
        $cabeceras['Subject'] = $cabeceras['Subject'] ?? $asunto;
        $cabeceras['MIME-Version'] = $cabeceras['MIME-Version'] ?? '1.0';
        $cabeceras['Content-Type'] = $cabeceras['Content-Type']
            ?? 'text/plain; charset=UTF-8';

        // El cuerpo va con 8bit, sin Base64, y solo es correcto porque ya se esta
        // en un canal cifrado. El asunto, en cambio, se codifica en Base64 si
        // lleva algo que no sea ASCII, porque los valores de cabecera no
        // admiten UTF-8 tal cual: el servidor lo recorta o lo cambia y el correo
        // llega con el asunto roto.
        $cabeceras['Content-Transfer-Encoding'] = $cabeceras['Content-Transfer-Encoding']
            ?? '8bit';

        $lineas = '';
        foreach ($cabeceras as $clave => $valor) {
            $lineas .= $clave . ': ' . $this->codificarCabecera((string) $valor) . $this->remate;
        }

        return $lineas . $this->remate . $this->escaparPuntos($cuerpo) . $this->remate;
    }

    /**
     * Codifica un valor de cabecera en Base64 si tiene caracteres no ASCII.
     *
     * @param string $valor Valor de la cabecera.
     *
     * @return string Valor apto para una cabecera SMTP.
     */
    private function codificarCabecera(string $valor): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $valor) === 1) {
            return $valor;
        }

        return '=?UTF-8?B?' . base64_encode($valor) . '?=';
    }

    /**
     * Anade un punto a las lineas que empiezan por punto.
     *
     * Es la regla que hace que SMTP no se rompa con un cuerpo que tenga una linea
     * «. . » o que termine en un punto. Un correo de premio con un texto que
     * empieza por «.» daria por terminado el mensaje ahi, y lo que fuera despues
     * se interpretaria como ordenes SMTP.
     *
     * @param string $cuerpo Cuerpo del mensaje.
     *
     * @return string Cuerpo con los puntos escapados.
     */
    private function escaparPuntos(string $cuerpo): string
    {
        $cuerpo = str_replace(["\r\n", "\r"], "\n", $cuerpo);
        $lineas = explode("\n", $cuerpo);

        foreach ($lineas as &$linea) {
            if (str_starts_with($linea, '.')) {
                $linea = '.' . $linea;
            }
        }

        return implode("\r\n", $lineas);
    }

    /**
     * Lee una respuesta SMTP completa, con sus lineas de continuacion.
     *
     * Una respuesta multilinea tiene un guion en la cuarta posicion de todas las
     * lineas menos la ultima. Si solo se leyera una linea, «250-STARTTLS» y
     * «250 SIZE» se tomarian por dos respuestas y el comando siguiente se
     * mandaria antes de tiempo, con el servidor esperando todavia.
     *
     * @param resource $socket Socket de lectura.
     *
     * @return string Texto de la respuesta, con el codigo de la primera linea.
     *
     * @throws \RuntimeException Si la conexion se cierra o expira el tiempo.
     */
    private function leer($socket): string
    {
        $respuesta = '';

        while (true) {
            $linea = fgets($socket, 1024);

            if ($linea === false) {
                $informacion = stream_get_meta_data($socket);

                throw new \RuntimeException(
                    !empty($informacion['timed_out'])
                        ? 'el servidor no ha contestado dentro del tiempo'
                        : 'el servidor ha cerrado la conexion'
                );
            }

            $respuesta .= $linea;

            if (strlen($linea) < 4 || $linea[3] !== '-') {
                return $respuesta;
            }
        }
    }

    /**
     * Escribe una orden en el socket.
     *
     * @param resource $socket  Socket de escritura.
     * @param string   $mensaje Texto a enviar.
     *
     * @return void
     *
     * @throws \RuntimeException Si no se puede escribir.
     */
    private function escribir($socket, string $mensaje): void
    {
        if (@fwrite($socket, $mensaje) === false) {
            throw new \RuntimeException('no se pudo escribir en el socket');
        }
    }

    /**
     * Comprueba que una respuesta tiene el codigo esperado.
     *
     * @param string $respuesta Texto devuelto por leer().
     * @param int    $codigo    Codigo que se espera, o 250 para aceptar tambien
     *                          el 251, que significa «destinatario reenviado».
     * @param string $contexto  Que se estaba haciendo, para el mensaje de error.
     * @param string $dato      Dato implicito, como la direccion.
     *
     * @return bool True, si llego hasta aqui.
     *
     * @throws \RuntimeException Si el codigo no es el esperado.
     */
    private function exigir(string $respuesta, int $codigo, string $contexto, string $dato = ''): bool
    {
        $enviado = (int) substr(trim($respuesta), 0, 3);

        // 251 es «usuario reenviado a otra direccion». Sigue siendo un exito: el
        // mensaje va a llegar, solo que a una direccion distinta, y tratarlo como
        // fallo dejaria correos en la cola sin motivo.
        if ($enviado === $codigo || ($codigo === 250 && $enviado === 251)) {
            return true;
        }

        throw new \RuntimeException(sprintf(
            '%s: el servidor respondio %d %s%s',
            $contexto,
            $enviado,
            trim(substr(trim($respuesta), 3)),
            $dato === '' ? '' : ' (' . $dato . ')'
        ));
    }

    /**
     * Nombre con el que se presenta el cliente en el EHLO.
     *
     * @return string Nombre de servidor local.
     */
    private function nombreDeServidor(): string
    {
        $servidor = gethostname();

        return $servidor === false || $servidor === '' ? 'localhost' : $servidor;
    }

    /**
     * Cierra el socket si sigue abierto.
     *
     * @param resource|null $socket Socket a cerrar.
     *
     * @return void
     */
    private function cerrar($socket): void
    {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }
}
