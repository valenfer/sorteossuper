<?php

/**
 * Decide que dato identifica a cada persona y en que ambito se comprueba.
 *
 * ============================================================================
 * POR QUE ESTO ES UN SERVICIO Y NO ESTA REPARTIDO EN DOS SITIOS
 * ============================================================================
 *
 * Porque hacen falta las dos mitades a la vez y tienen que decir lo mismo. La
 * pantalla de participacion calcula la huella que se guarda en
 * `participaciones.clave_unicidad`, y \App\Services\ReglasCampana decide si esa
 * participacion es un duplicado. Si cada uno eligiera por su cuenta el campo de
 * identidad o el ambito, habria dos caminos que podrian dar resultados distintos,
 * y el sintoma seria desconcertante: la pantalla dejaria pasar a alguien y el
 * motor lo rechazaria con «ya has participado», o al reves.
 *
 * Ese desajuste no es hipotetico. Con el campo de identidad, el caso es facil de
 * tener: si la pantalla usa «dni» porque no ha mirado la configuracion y el
 * validador usa el campo que eligio el administrador, se guardan dos huellas
 * distintas para la misma persona y el indice unico no protege nada. Con el
 * ambito es mas sutil, y es el que importa: si se guardara siempre la huella de
 * ambito «campana», una campana que solo ha pedido «una por dia» rechazaria a
 * todos los dias siguientes al primero, porque el indice unico (que no sabe de
 * dias, solo ve la columna) veria la misma huella y la rechazaria. Una regla de
 * dia bien entendida seria de dia y nada mas, y en pantalla, y el problema
 * apareceria el segundo dia, cuando ya no hay quien recuerde el primer rechazo.
 *
 * ============================================================================
 * QUE AMBITO GUARDA LA HUELLA Y POR QUE
 * ============================================================================
 *
 * D3 dice que un unico indice unico cubre las cuatro reglas de duplicado porque
 * cada huella lleva dentro su ambito. Eso es cierto, y es la forma mas limpia de
 * tener un solo indice. Pero el indice es sobre una sola columna, asi que en
 * cada fila solo puede haber una huella, y de las reglas activas solo una puede
 * ser la que ese indice vigila. El reparto es:
 *
 *   - Si hay «una por campana», manda ella. Es la mas restrictiva de todas:
 *     mientras este activa, las demas son redundantes, porque una sola
 *     participacion por campana ya cumple cualquier otra combinacion.
 *   - Si no, pero hay «una por dia», la huella va con ambito de dia. Con eso el
 *     indice permite una participacion distinta cada dia, que es justo lo que la
 *     regla pide, y la clave unica sigue sirviendo para que el motor detecte el
 *     duplicado sin consultar antes.
 *   - Si no hay ninguna de las dos pero hay «una por ticket», la huella lleva el
 *     ambito del ticket, y el indice pasa a ser el que impide reutilizar un
 *     ticket.
 *   - Si no hay ninguna regla de duplicado, no se guarda huella. Un indice
 *     unico sobre una huella de la cadena vacia rechazaria a la segunda
 *     participacion de cualquiera, que es lo contrario de lo que quiere una
 *     campana sin reglas.
 *
 * Lo que queda se comprueba de forma explicita en ReglasCampana, sobre el valor
 * de identidad y no sobre la huella. «Una por DNI» va siempre por esa via, y no
 * por el indice, por un motivo concreto: la regla habla del DNI, pero el campo de
 * identidad de la campana puede ser el correo. Si «una por DNI» compartiera el
 * indice con el resto, la huella se calcularia sobre el correo y dos personas con
 * el DNI distinto y el correo distinto —o al reves— no chocarian nunca, y la
 * regla no se estaria cumpliendo. Comprobarla sobre el campo `dni` es la unica
 * forma de que signifique lo que dice.
 *
 * ============================================================================
 * EL ORDEN DE PREFERENCIA DEL CAMPO DE IDENTIDAD
 * ============================================================================
 *
 * Si el administrador ha configurado uno, ese gana siempre: es su decision y el
 * panel ya le avisa cuando el campo no existe. Si no ha configurado ninguno, se
 * deduce de lo que la persona ha rellenado, con el orden que fija D3:
 *
 *   dni, codigo_participacion, num_ticket, email, telefono
 *
 * De mas a menos identificativo, y el telefono va el ultimo a proposito: dos
 * personas de una misma familia pueden compartir un numero, y una campana que
 * bloquea por telefono dejaria fuera a una de ellas. El nombre no esta en la
 * lista porque no identifica a nadie.
 *
 * Se busca en el orden, no en la configuracion de campos: si la campana pide
 * un correo, y la persona escribe su correo, el correo es su identidad aunque la
 * campana tenga un campo de DNI que se dejo en blanco. Exigir el DNI cuando el
 * campo de identidad no lo es seria inventar una regla que nadie ha pedido.
 *
 * ============================================================================
 * @see \App\Services\Huella
 * @see \App\Services\ReglasCampana
 * @see \App\Controllers\ControladorParticipacion
 * @see decision D3 del documento de especificacion, identidad de la persona
 */

declare(strict_types=1);

namespace App\Services;

/**
 * Resuelve el campo de identidad de un intento y su ambito de unicidad.
 */
class IdentidadCampana
{
    /**
     * Campos por los que se puede buscar identidad, en orden de preferencia.
     *
     * El orden es el de D3 y no se cambia en caliente: es la decision de que un
     * DNI vale mas que un correo, y un correo mas que un telefono. Cambiarlo
     * haria que dos participaciones de la misma persona, escritas con los mismos
     * datos, pudieran acabar con ambitos distintos.
     *
     * @var array<int, string>
     */
    public const ORDEN = ['dni', 'codigo_participacion', 'num_ticket', 'email', 'telefono'];

    /**
     * Devuelve el campo que identifica a la persona en este intento.
     *
     * @param array<string, mixed> $reglas Reglas ya leidas de la campana.
     * @param array<string, mixed> $datos  Datos del intento.
     *
     * @return string Clave del campo, o cadena vacia si no hay ninguno utilizable.
     */
    public function campoDe(array $reglas, array $datos): string
    {
        $configurado = trim((string) ($reglas['campo_identidad'] ?? ''));

        if ($configurado !== '') {
            return $configurado;
        }

        foreach (self::ORDEN as $campo) {
            if (trim((string) ($datos[$campo] ?? '')) !== '') {
                return $campo;
            }
        }

        return '';
    }

    /**
     * Saca el valor de identidad de un intento.
     *
     * @param string               $campo Clave del campo de identidad.
     * @param array<string, mixed> $datos Datos del intento.
     *
     * @return string Valor tal cual lo escribio la persona, o cadena vacia.
     */
    public function valorDe(string $campo, array $datos): string
    {
        if ($campo === '') {
            return '';
        }

        return trim((string) ($datos[$campo] ?? ''));
    }

    /**
     * Calcula la huella que se guarda en la columna de unicidad.
     *
     * @param int                  $promocionId Campana del intento.
     * @param array<string, mixed> $reglas      Reglas ya leidas.
     * @param array<string, mixed> $datos       Datos del intento.
     * @param string               $momento     Instanto de la participacion, en
     *                                        «A-n-j H:i:s».
     *
     * @return string|null Huella de 64 caracteres, o null si la campana no tiene
     *                     ninguna regla de duplicado que la huella pueda vigilar.
     */
    public function huellaCanonica(
        int $promocionId,
        array $reglas,
        array $datos,
        string $momento
    ): ?string {
        $campana = Huella::ambitoCampana($promocionId);
        $porCampana = (int) ($reglas['una_por_campana'] ?? 0) === 1;
        $porDia = (int) ($reglas['una_por_dia'] ?? 0) === 1;
        $porTicket = (int) ($reglas['una_por_ticket'] ?? 0) === 1;

        if ($porCampana) {
            $huella = Huella::de($campana, $this->valorDe($this->campoDe($reglas, $datos), $datos));

            return $huella === '' ? null : $huella;
        }

        if ($porDia) {
            $fecha = substr($momento, 0, 10);
            $huella = Huella::de(
                Huella::ambitoDia($promocionId, $fecha),
                $this->valorDe($this->campoDe($reglas, $datos), $datos)
            );

            return $huella === '' ? null : $huella;
        }

        if ($porTicket) {
            $ticket = trim((string) ($datos['num_ticket'] ?? ''));

            if ($ticket === '') {
                return null;
            }

            $huella = Huella::de(Huella::ambitoTicket($promocionId, $ticket), $ticket);

            return $huella === '' ? null : $huella;
        }

        // Sin reglas de duplicado no se guarda huella. Se podria guardar la de la
        // cadena vacia, que es siempre la misma, y el indice unico rechazaria a
        // la segunda participacion de cualquiera.
        return null;
    }
}
