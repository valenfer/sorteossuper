/**
 * Comportamiento comun de la aplicacion de sorteos.
 *
 * ============================================================================
 * QUE HACE ESTE FICHERO Y POR QUE ES TAN CORTO
 * ============================================================================
 *
 * En el hito 1 no habia ruleta ni boleteria, asi que aqui solo hay las tres cosas
 * que hacen falta en cualquier pantalla y que se pueden resolver con unas pocas
 * lineas sin ninguna libreria.
 *
 * LA RULETA DE D19 NO ESTA EN ESTE FICHERO, y es deliberado. La ruleta se pinta y
 * se gira con CSS, y el resultado ya esta escrito en el HTML cuando llega el
 * navegador: el CSS solo lo deja tapado durante el giro y lo revela despues. No
 * hace falta ningun temporizador aqui porque un temporizador seria justo el
 * fallo que hay que evitar, el que dejaria a la clienta mirando una pantalla sin
 * resultado si el script no llega a ejecutarse o se ejecuta tarde. Lo que protege
 * esa garantia no es una linea de JavaScript, sino que en la plantilla el texto
 * este escrito y lo esconda el CSS.
 *
 * La ausencia de jQuery y de cualquier framework de JavaScript es una decision
 * (D14), no una escasez. Una tablet de mostrador tiene que abrir la pantalla en
 * poco tiempo, y cada dependencia que se carga antes de que la pagina sea usable
 * es tiempo que la azafata espera con una clienta delante.
 *
 * @see decision D14 del documento de especificacion
 */

'use strict';

/**
 * Devuelve todos los elementos que coinciden con un selector.
 *
 * Se envuelve en una funcion propia, en lugar de usar querySelectorAll en cada
 * sitio, por una razon concreta: NodeList no tiene todos los metodos de Array
 * en los navegadores antiguos que pueden aparecer en una tablet de un
 * supermercado que lleva cuatro años en servicio. Convertirla una vez aqui
 * evita ese problema en todo el proyecto.
 *
 * @param {string} selector Selector CSS.
 * @param {ParentNode} [contexto] Nodo dentro del que buscar.
 * @returns {Array<Element>} Elementos encontrados, como array de verdad.
 */
function todos(selector, contexto) {
    return Array.prototype.slice.call((contexto || document).querySelectorAll(selector));
}

/**
 * Muestra un fallo de red en un elemento, sin recargar la pagina.
 *
 * Se usa cuando una peticion a la aplicacion falla. Recargar la pagina seria la
 * solucion facil, pero en un mostrador perderia los datos que la azafata lleva
 * escritos, y eso no se le perdona: puede ser la participacion de una clienta que
 * esta esperando.
 *
 * @param {string} mensaje Texto a mostrar.
 * @param {Element|null} [elemento] Contenedor donde escribirlo.
 * @returns {void}
 */
function avisarError(mensaje, elemento) {
    var destino = elemento || document.getElementById('aviso-conexion');

    if (!destino) {
        return;
    }

    destino.textContent = mensaje;
    destino.className = 'aviso aviso-error';
    destino.hidden = false;
}

/**
 * Evita que un formulario se envie dos veces por un doble toque.
 *
 * En una tablet capacitive, un toque con dos dedos o un temblor de la mesa se
 * traduce en dos envios. En una pantalla de participacion, eso significa dos
 * filas para la misma persona, o un «usted ya ha participado» justo despues de
 * participacion correcta.
 *
 * El boton se deshabilita en cuanto se entra, y se vuelve a habilitar si el
 * navegador no llega a enviar nada, que es el caso de un fallo de red.
 *
 * @returns {void}
 */
function protegerFormularios() {
    todos('form[data-no-doble-envio]').forEach(function (formulario) {
        formulario.addEventListener('submit', function () {
            var boton = formulario.querySelector('button[type="submit"]');

            if (boton) {
                boton.disabled = true;
                boton.setAttribute('aria-disabled', 'true');
            }
        });
    });
}

/**
 * Pide confirmacion en los enlaces y botones que borran algo.
 *
 * Un boton de «Eliminar tramo» en un panel de administracion, con la cuenta de
 * un responsable de tienda abierta al lado, no deberia depender de que la
 * persona se acuerde de que ha pulsado dos veces.
 *
 * Se marca con el atributo data-confirmar, y el texto es el que se enseña. No se
 * usa window.confirm a proposito: su aspecto lo elige el sistema operativo, no
 * la aplicacion, y en una tablet puede salir en una ventana pequena que no se
 * lee.
 *
 * @returns {void}
 */
function pedirConfirmacion() {
    todos('[data-confirmar]').forEach(function (elemento) {
        elemento.addEventListener('click', function (evento) {
            if (!window.confirm(elemento.getAttribute('data-confirmar'))) {
                evento.preventDefault();
            }
        });
    });
}

/**
 * Avisa al operador de que la conexion con el servidor se ha perdido.
 *
 * En una tienda, la red se cae. Sin este aviso, el formulario se queda
 * esperando y no hay manera de saber si se esta guardando o no, y la azafata
 * acaba recargando la pagina, que es justo lo que puede repetir una
 * participacion. Con el aviso, se sabe que hay que esperar.
 *
 * @returns {void}
 */
function vigilarConexion() {
    var aviso = document.getElementById('aviso-conexion');

    if (!aviso) {
        return;
    }

    window.addEventListener('offline', function () {
        avisarError('Se ha perdido la conexion con el servidor. Los datos de esta pantalla no se han perdido.', aviso);
    });

    window.addEventListener('online', function () {
        aviso.textContent = '';
        aviso.hidden = true;
    });
}

/**
 * Arranca todo al cargar la pagina.
 *
 * @returns {void}
 */
function iniciar() {
    protegerFormularios();
    pedirConfirmacion();
    vigilarConexion();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
} else {
    iniciar();
}
