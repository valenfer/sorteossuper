<?php

/**
 * Controlador de la participacion de una campana.
 *
 * ============================================================================
 * QUE HACE ESTE CONTROLADOR
 * ============================================================================
 *
 * Es la pantalla 1 y la pantalla 3 del apartado 5, el flujo de la azafata:
 *
 *   1. formulario() pinta el formulario con los campos que el administrador ha
 *      configurado, ni uno mas.
 *   2. registrar() recoge lo escrito, lo pasa por las reglas de la campana y
 *      muestra el resultado.
 *
 * La pantalla 2, la animacion de la ruleta, no esta aqui porque no tiene nada
 * que decidir: el apartado 5 pide explicitamente que el servidor haya adjudicado
 * antes de enseñarla, de modo que aqui ya se sabe si hay premio o no y la
 * animacion es solo decorado.
 *
 * ============================================================================
 * POR QUE EL VALIDADOR SE CONSTRUYE AQUI Y NO DENTRO DEL MOTOR
 * ============================================================================
 *
 * \App\Services\Adjudicador recibe el validador por el constructor, y se le pasa
 * \App\Services\ReglasCampana, que es la implementacion real de las reglas del
 * apartado 4.7. Este es el unico sitio del programa donde se decide que validador
 * se usa en produccion: las pruebas inyectan el suyo, y asi pueden comprobar que
 * el motor respeta el contrato sin depender de como esten configuradas las reglas.
 *
 * ============================================================================
 * LA CLAVE DE IDEMPOTENCIA SE GENERA EN EL FORMULARIO, NO EN EL SERVIDOR
 * ============================================================================
 *
 * El motor exige un identificador con forma de UUID y por eso no puede
 * inventarse al registrar: si el servidor generara uno nuevo en cada peticion, un
 * doble clic crearia dos participaciones, que es justo el caso de aceptacion 7.
 * El identificador se genera al pintar el formulario, viaja como campo oculto y
 * llega igual en el segundo envio, con lo que el motor reconoce el reintento y
 * devuelve el resultado que ya dio la primera vez.
 *
 * ============================================================================
 * POR QUE EL ROL NO SE COMPRUEBA AQUI
 * ============================================================================
 *
 * Estas rutas admiten a la azafata y al administrador, y el control se hace en
 * \App\Core\Router antes de que se llame a este controlador. Escribir una
 * comprobacion de rol aqui seria duplicar lo que ya se ha comprobado, y
 * probablemente dejarla pasar por un camino.
 *
 * @see \App\Services\Adjudicador
 * @see \App\Services\ReglasCampana
 * @see \App\Services\Huella
 * @see \App\Models\CampoFormulario
 * @see apartado 5 de la especificacion, flujo de participacion
 * @see casos de aceptacion 5 y 7
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Aplicacion;
use App\Core\Autorizacion;
use App\Core\Controlador;
use App\Core\Csrf;
use App\Core\Vista;
use App\Models\CampoFormulario;
use App\Models\ConfiguracionVisual;
use App\Models\Promocion;
use App\Models\ReglaParticipacion;
use App\Models\Tramo;
use App\Services\Adjudicador;
use App\Services\IdentidadCampana;
use App\Services\ReglasCampana;
use App\Services\Tramos;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Participacion de una clienta en una campana.
 */
class ControladorParticipacion extends Controlador
{
    /**
     * Muestra el formulario de participacion.
     *
     * @return void
     */
    public function formulario(): void
    {
        $promocion = $this->exigirCampanaActiva();
        $promocionId = (int) $promocion['id'];

        $this->vista('participacion/formulario', [
            'titulo'       => 'Participar en ' . $promocion['nombre'],
            'campana'      => $promocion,
            'campos'       => (new CampoFormulario())->listarPorPromocion($promocionId, true),
            'tramo'        => $this->tramoVigente($promocion),
            'reglas'       => (new ReglaParticipacion())->leer($promocionId),
            'visual'       => (new ConfiguracionVisual())->leer($promocionId),
            'idempotencia' => self::nuevaClave(),
            'csrf'         => Csrf::campo(),
            'destino'      => $this->rutaDeParticipacion($promocionId),
            'aviso'        => Vista::aviso('aviso'),
            'error'        => Vista::aviso('error'),
        ]);
    }

    /**
     * Recoge lo escrito, lo registra y muestra el resultado.
     *
     * @return void
     *
     * @throws \App\Core\ErrorValidacion Si el token CSRF no es valido.
     */
    public function registrar(): void
    {
        $this->exigirCsrf();

        $promocion = $this->exigirCampanaActiva();
        $promocionId = (int) $promocion['id'];

        $campos = (new CampoFormulario())->listarPorPromocion($promocionId, true);
        $datos = $this->recoger($campos);
        $errores = $this->comprobarCampos($campos, $datos);

        // La casilla de consentimiento la pinta la vista, no es uno de los campos
        // que el administrador ha configurado, asi que no sale de recoger() y hay
        // que traerla a mano. Sin esto, una campana que exige consentimiento
        // rechazaria TODAS las participaciones, incluso las que llegan con la
        // casilla marcada: las reglas buscan «consentimiento» entre los datos y no
        // lo encuentran nunca. Es un fallo que no se ve leyendo los dos sitios por
        // separado, porque cada uno de los dos esta bien escrito.
        $reglas = (new ReglaParticipacion())->leer($promocionId);

        if ((int) ($reglas['exigir_consentimiento'] ?? 0) === 1) {
            $datos['consentimiento'] = (string) $this->recibido('consentimiento', '');
        }

        // Los errores se devuelven al formulario y no a la pantalla de resultado.
        // Enseñar «su participacion es incorrecta» cuando lo que ha fallado es
        // que falta el DNI no ayuda a nadie: la azafata tendria que recordar lo
        // que se habia escrito para corregirlo, y el unico sitio donde se sabe
        // es el formulario.
        if ($errores !== []) {
            Vista::guardarAviso(implode(' ', $errores), 'error');
            $this->redirigir($this->rutaDeParticipacion($promocionId));
            return;
        }

        $tramo = $this->tramoVigente($promocion);
        $usuarioId = Autorizacion::usuarioId();

        // El momento se calcula una vez y se usa para las dos cosas que lo
        // necesitan: la huella, que lleva la fecha dentro cuando la campana ha
        // pedido una participacion por dia, y la hora que se registra en la
        // participacion. Si se calculara dos veces, podrian ser dos instants
        // distintos, y en el borde de medianoche la huella seria de un dia y la
        // participacion de otro.
        $momento = $this->momentoDeLaCampana($promocion);

        $resultado = (new Adjudicador(new ReglasCampana()))->registrar(
            $promocionId,
            (string) $this->recibido('idempotencia', ''),
            $tramo === null ? null : (int) $tramo['id'],
            $datos,
            $this->claveDeUnicidad($promocionId, $reglas, $datos, $momento),
            $usuarioId > 0 ? $usuarioId : null,
            $momento
        );

        $this->vista('participacion/resultado', [
            'titulo'    => 'Resultado de su participacion',
            'campana'   => $promocion,
            'resultado' => $resultado,
            'visual'    => (new ConfiguracionVisual())->leer($promocionId),
            'destino'   => $this->rutaDeParticipacion($promocionId),
        ]);
    }

    /**
     * Devuelve la campana de la ruta y corta si no admite participaciones.
     *
     * Una campana en borrador o archivada se rechaza con un aviso, y no con la
     * pantalla de resultado, porque no hay participacion que explicar: no se ha
     * llegado a registrar nada. La comprobacion va aqui y no en el motor para que
     * el formulario no llegue a pintarse nunca contra una campana cerrada, que
     * es lo que hace que el estado sea una puerta de verdad y no un dato
     * decorativo que se puede saltar escribiendo la URL.
     *
     * @return array<string, mixed> Fila de la campana.
     */
    private function exigirCampanaActiva(): array
    {
        $promocion = (new Promocion())->exigirPorId($this->parametroId('id', 'azafata'), 'azafata');

        if ((string) $promocion['estado'] !== 'activa') {
            Vista::guardarAviso('Esta promocion no admite participaciones ahora mismo.', 'aviso');
            $this->redirigir(Autorizacion::esAdministrador() ? 'admin' : 'azafata');
        }

        return $promocion;
    }

    /**
     * Recoge del POST los valores de los campos configurados.
     *
     * Se recogen SOLO los campos que la campana declara como visibles. Un POST
     * puede traer las claves que quiera, y si se copiaran tal cual, alguien
     * podria escribir en la participacion un campo «resultado» o «premio» que no
     * existe en el formulario. La lista blanca la pone la campana, no el
     * navegador.
     *
     * @param array<int, array<string, mixed>> $campos Campos visibles.
     *
     * @return array<string, mixed> Datos escritos, por clave de campo.
     */
    private function recoger(array $campos): array
    {
        $datos = [];

        foreach ($campos as $campo) {
            $valor = trim((string) $this->recibido((string) $campo['clave'], ''));

            // Un campo con valor por defecto se rellena solo si la azafata no ha
            // escrito nada. Es lo que permite dejar un campo prellenado sin que
            // eso pise lo que la clienta haya corregido.
            if ($valor === '') {
                $valor = trim((string) ($campo['valor_por_defecto'] ?? ''));
            }

            $datos[(string) $campo['clave']] = $valor;
        }

        return $datos;
    }

    /**
     * Comprueba los campos contra lo que la campana ha declarado de cada uno.
     *
     * Las reglas de longitud y de obligatoriedad son las de cada campo, y no una
     * comprobacion generica, porque el administrador las escribe campo a campo.
     * Lo que NO se comprueba aqui es si la participacion es valida, que es cosa
     * de \App\Services\ReglasCampana: aqui solo se decide si el formulario esta
     * bien escrito, y alli si la persona puede participar.
     *
     * @param array<int, array<string, mixed>> $campos Campos visibles.
     * @param array<string, mixed>               $datos  Valores recogidos.
     *
     * @return array<int, string> Errores, vacia si todo esta bien.
     */
    private function comprobarCampos(array $campos, array $datos): array
    {
        $errores = [];

        foreach ($campos as $campo) {
            $etiqueta = (string) $campo['etiqueta'];
            $valor = (string) ($datos[(string) $campo['clave']] ?? '');

            if ((int) $campo['obligatorio'] === 1 && $valor === '') {
                $errores[] = $etiqueta . ' es obligatorio.';
                continue;
            }

            if ($valor === '') {
                continue;
            }

            $largo = mb_strlen($valor, 'UTF-8');
            $minimo = (int) ($campo['min_largo'] ?? 0);
            $maximo = (int) ($campo['max_largo'] ?? 0);

            if ($minimo > 0 && $largo < $minimo) {
                $errores[] = $etiqueta . ' necesita al menos ' . $minimo . ' caracteres.';
                continue;
            }

            if ($maximo > 0 && $largo > $maximo) {
                $errores[] = $etiqueta . ' no puede pasar de ' . $maximo . ' caracteres.';
                continue;
            }

            if ((string) $campo['tipo'] === 'entero' && preg_match('/^-?\d+$/', $valor) !== 1) {
                $errores[] = $etiqueta . ' tiene que ser un numero entero.';
            }
        }

        return $errores;
    }

    /**
     * Busca el tramo que cubre este momento en la campana.
     *
     * La comparacion la hace \App\Services\Tramos, que es el mismo metodo que
     * revisa que un tramo no solape con otro al configurarlo, y no un «si la
     * hora esta entre estas dos» escrito otra vez. Importa porque los bordes
     * importan: un tramo que acaba a las 14:00 no incluye las 14:00, y un
     * tramo que cruza el cambio de hora tiene minutos que no existen. Decidirlo
     * en dos sitios distintos garantiza que algun dia no coinciden.
     *
     * Devuelve null cuando no hay ningun tramo activo, y null NO es un error: el
     * motor lo convierte en un rechazo con el texto de «no estamos en horario»,
     * que es lo que debe ver la clienta si llega fuera de horario, en vez de un
     * error de servidor.
     *
     * @param array<string, mixed> $promocion Campana en la que se busca.
     *
     * @return array<string, mixed>|null Tramo vigente, o null si no hay ninguno.
     */
    private function tramoVigente(array $promocion): ?array
    {
        $momento = $this->momentoDeLaCampana($promocion);
        $fecha = substr($momento, 0, 10);

        // Solo «H:i», y no los segundos que trae el instante. Tramos trabaja con
        // precision de minuto: el esquema guarda la hora como TIME, el calendario
        // reparte por minutos y esHora() rechaza los segundos que no sean cero,
        // porque admitir «10:00:30» crearia tramos que luego el calendario no
        // podria repartir. Si aqui se pasara el instante entero,59 de cada 60
        // segundos no habria ningun tramo vigente y toda participacion reventaria
        // con «sin tramo activo». Solo se nota si se prueba a la hora que sea.
        $hora = substr($momento, 11, 5);
        $tramos = new Tramos();

        foreach ((new Tramo())->listarPorPromocion((int) $promocion['id']) as $tramo) {
            $errores = $tramos->comprobarDentroDelTramo(
                (string) $tramo['fecha'],
                (string) $tramo['hora_inicio'],
                (string) $tramo['hora_fin'],
                $fecha,
                $hora
            );

            if ($errores === []) {
                return $tramo;
            }
        }

        return null;
    }

    /**
     * Devuelve el instante actual en el reloj de la campana.
     *
     * Se usa el reloj de la campana y no el del servidor porque una promocion
     * puede vivir en una zona horaria distinta, y en un supermercado de la costa
     * un premio que se activa a las doce del mediodia del servidor puede ser una
     * hora antes o una hora despues de lo que pone el reloj de la tienda.
     *
     * @param array<string, mixed> $promocion Campana cuyo reloj se usa.
     *
     * @return string Instante en formato «A-n-j H:i:s».
     */
    private function momentoDeLaCampana(array $promocion): string
    {
        $zona = (string) ($promocion['zona_horaria'] ?? Aplicacion::ajuste('app.zona_horaria', 'Europe/Madrid'));

        try {
            $ahora = new DateTimeImmutable('now', new DateTimeZone($zona));
        } catch (Exception $e) {
            // Una zona horaria mal escrita en la configuracion no debe dejar sin
            // participaciones a la tienda entera. Se avisa en el log y se usa la
            // del servidor, que es lo unico que se puede hacer sin la campana.
            error_log('Zona horaria no valida en la campana: ' . $zona);

            $ahora = new DateTimeImmutable('now');
        }

        return $ahora->format('Y-m-d H:i:s');
    }

    /**
     * Calcula la huella de identidad que el motor guarda como clave unica.
     *
     * No decide nada: se lo pide a \App\Services\IdentidadCampana, que es el
     * mismo sitio donde \App\Services\ReglasCampana pregunta por el campo de
     * identidad. La indireccion es deliberada y es lo que mantiene el sistema
     * coherente. Si esta pantalla eligiera el campo por su cuenta, con «dni» fijo,
     * y el validador usara el campo que eligio el administrador, se guardarian
     * dos huellas distintas para la misma persona y el indice unico no
     * protegeria nada, sin que hubiera ningun error visible: las dos mitades
     * serian correctas por separado.
     *
     * El ambito tambien lo decide ese servicio, y no es un detalle: el ambito de
     * la huella es el que vigila el indice unico, asi que guardar siempre el de
     * campana haria que una campana con «una por dia» rechazara a todos los dias
     * siguientes al primero.
     *
     * @param int                  $promocionId Campana del intento.
     * @param array<string, mixed> $reglas      Reglas ya leidas de la campana.
     * @param array<string, mixed> $datos       Datos escritos.
     * @param string               $momento     Instanto de la participacion.
     *
     * @return string|null Huella de identidad, o null si la campana no deduplica
     *                     o no hay ningun valor de identidad con el que hacerlo.
     */
    private function claveDeUnicidad(int $promocionId, array $reglas, array $datos, string $momento): ?string
    {
        return (new IdentidadCampana())->huellaCanonica($promocionId, $reglas, $datos, $momento);
    }

    /**
     * Devuelve la ruta de participacion de la campana.
     *
     * Se devuelve en lugar de escribirla en la vista porque el formulario y el
     * boton de volver tienen que apuntar al mismo sitio del que se salio: la
     * azafata vuelve a /azafata y el administrador a /admin. Escribir la ruta a
     * mano en la vista fue justo lo que dejo el action del formulario apuntando a
     * un sitio que no existia.
     *
     * @param int $promocionId Campana cuya ruta se quiere.
     *
     * @return string Ruta sin la base, como la que esperan redirigir() y url().
     */
    private function rutaDeParticipacion(int $promocionId): string
    {
        $prefijo = Autorizacion::esAdministrador() ? 'admin' : 'azafata';

        return $prefijo . '/promociones/' . $promocionId . '/participar';
    }

    /**
     * Genera un identificador de intento con forma de UUID.
     *
     * Se construye con bytes aleatorios porque random_bytes lanza una excepcion
     * si el sistema no puede darlos, y prefiero que el formulario falle a
     * enseñar una clave previsible que se pudiera adivinar desde otra peticion.
     * No se usa uniqid porque su resultado es el reloj con un contador, que dos
     * peticiones seguidas en la misma microsegundo llegan a compartir.
     *
     * @return string Identificador en formato 8-4-4-4-12.
     */
    private static function nuevaClave(): string
    {
        $bytes = random_bytes(16);

        // Los bits de version y de variante se fijan a los valores de un UUID
        // version 4, que es lo que el motor admite. Sin esto, el identificador
        // seria hexadecimal correcto pero no un UUID, y la comprobacion de
        // formato del motor es mas estricta de lo que parece a proposito.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
