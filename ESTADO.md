# Estado del proyecto

Este fichero es el punto de entrada para empezar a trabajar sin contexto previo.
Responde a tres preguntas: **dónde está el proyecto**, **cómo se comprueba que
sigue sano** y **qué toca hacer a continuación**.

Se actualiza al final de cada hito, en el mismo commit del hito.

- Requisitos funcionales: `especificacion_sorteos_supermercado.md`
- Instalación, configuración y decisiones: `README.md`

Si algo de este documento contradice a la especificación o al README, mandan
ellos, y este fichero es lo primero que hay que corregir.

## 1. Qué es y en qué punto está

Aplicación web para repartir premios en un supermercado: un administrador
configura la promoción y el personal de tienda registra las participaciones.

| Hito | Estado | Commit | Qué cubre |
| --- | --- | --- | --- |
| 0. Especificación | Terminado | `fcf6770` | Addendum D1–D19 y las 19 decisiones de implementación. |
| 1. Base y acceso | Terminado | `8c0baf5` | Núcleo, sesiones, rutas, vistas, acceso por roles, instalador, verificador, pruebas, README. |
| 2. Motor de adjudicación | Terminado | `f8844fe` | Cola de premios, transacción con bloqueo, evita adjudicaciones dobles. |
| 3. Configuración de la promoción | Pendiente | — | Panel del administrador: días, tramos, tipos de premio, cantidades, calendario. |
| 4. Participaciones | Pendiente | — | Registro por la azafata, reglas, identidad, las tres pantallas. |
| 5. Correo | Pendiente | — | Transporte `log` y `smtp`, cola de mensajes, reintentos. |
| 6. Panel de seguimiento | Pendiente | — | Métricas, filtros, auditoría, cierre de promoción. |
| 7. Scripts de línea de comandos | Pendiente | — | Procesar la cola de correo, purgar datos. |

**El orden importa.** El hito 2 va antes que el 3 y el 4 a propósito: es el
único punto donde un error no se ve en la pantalla y se manifiesta días
después, cuando ya hay una campaña en marcha. Ver la sección 5.

## 2. Cómo comprobar que está sano

Con MySQL y Apache arrancados. Los tres comandos deben terminar bien:

```
php bin\verificar_docs.php
php tests\run.php
php bin\instalar.php --diagnostico
```

Y después, entrando en `http://localhost/sorteos/login`.

Lo que se espera ahora mismo, exactamente:

| Comprobación | Resultado esperado |
| --- | --- |
| `verificar_docs.php` | `Todo correcto: 29 ficheros, sin problemas` |
| `tests\run.php` | `Todo correcto: 8 casos ejecutados, 141 comprobaciones` |
| `instalar.php --diagnostico` | `Diagnostico terminado`, sin ninguna escritura |
| `instalar.php` | Idempotente: se puede repetir sin romper nada |
| `/login` | 200 |
| `/admin` sin sesión | 303 a la pantalla de acceso |
| `/admin` como administrador | 200 |
| `/azafata` como administrador | 404 |

La suite escribe un aviso en la salida de error al cambiar a la base de
pruebas. **Es intencionado**: si aparece fuera de `tests/run.php`, significa
que alguien ha cambiado de base de datos en otro sitio.

## 3. Qué está hecho y qué no

**Hecho (hito 1).** Autocargador sin dependencias, configuración por
anulaciones, sesiones con cookie `httponly` y cierre por inactividad,
enrutador con URLs limpias, motor de vistas con escapado obligatorio, acceso con
dos roles, bloqueo por intentos fallidos, CSRF, instalador idempotente,
verificador de documentación, suite de pruebas y documentación.

**Hecho (hito 2).** El motor de adjudicación: la cola de premios, la transacción
con las tres defensas contra la adjudicación doble, la idempotencia del intento,
el rechazo de intentos que incumplen una regla, y el encolado del correo. Ver la
sección 5.

**Las 15 tablas del esquema ya existen** (`sql/schema.sql`), incluida la de
participaciones, la de cola de correos y la de auditoría. El modelo de datos
está, y el motor que usa las cuatro tablas centrales también.

**Las pantallas de destino son provisionales** y lo dicen en pantalla. No hay
todavía ninguna pantalla de configuración, de participaciones ni de resultados:
lo único que hay es la de acceso del hito 1.

**En la raíz hay un `bbdd.png` con un diagrama de la base de datos hecho a
mano.** Es una referencia personal y **no se versiona a propósito**, así que
`git status` lo seguirá mostrando como `?? bbdd.png` mientras siga ahí. No
borrarlo, moverlo ni modificarlo sin preguntar.

**Las pantallas de destino son provisionales** y lo dicen en pantalla. No hay
todavía ninguna pantalla de configuración, de participaciones ni de resultados.

## 4. Decisiones que condicionan el trabajo

Están en el apartado 13 de la especificación y resumidas en el README. Las que
más afectan a lo que viene:

| Decisión | Por qué importa a la hora de escribir código |
| --- | --- |
| **D8** Concurrencia | Bloqueo con nombre por promoción, `SELECT ... FOR UPDATE` sobre la unidad y un `UPDATE ... WHERE estado = 'programada'` del que se comprueba el número de filas afectadas. El último es el que garantiza por sí solo que una unidad nunca se adjudica dos veces. No usar `SKIP LOCKED`: no existe en MariaDB 10.4. **Desviación consciente:** el nombre real del bloqueo es `sorteos:adjudicacion:{id}` y no el literal `sorteo:{id}` de la decisión. `{id}` sigue siendo el de la promoción y la espera sigue siendo de 5 segundos, que es lo que D8 fija; lo que se alarga es el nombre, porque `GET_LOCK` usa un espacio de nombres global del servidor y un `sorteo:1` PODría colisionar con el de otra aplicación en el mismo MariaDB. Ver el comentario de `Db::bloquearPromocion()`. |
| **D3** Identidad | Huella `HMAC-SHA256` con secreto de configuración, nunca el dato en claro. La huella lleva dentro su ámbito, así que un único índice cubre «una por campaña», «una por día», «una por ticket» y «una por código». |
| **D10** Separación | `participaciones` solo contiene participaciones válidas y es la única tabla con índices únicos. Los rechazos van a `intentos_rechazados`, sin índice único, para que un rechazo no bloquee a nadie. |
| **D4** Cola y cierre | Los premios pendientes pasan al tramo y al día siguiente. Al cerrar, las unidades no entregadas pasan a `no_entregada`, sin adjudicación retroactiva. |
| **D7** Zona horaria | `Europe/Madrid` en el arranque, fechas naive. **No usar `CONVERT_TZ`**: depende de tablas de zona horaria que no siempre están cargadas. |
| **D2** Calendario | Si hay más unidades que minutos libres, el generador aborta y lo dice. Nunca genera horas idénticas en silencio. |
| **D9** Modelo de datos | `unidades_premio` guarda `tramo_id` como referencia autoritativa y un único `inicio`. La fecha, la hora y la etiqueta del tramo se derivan al mostrar. |
| **D1** Correo | Interfaz `Mailer` con transportes `log` y `smtp`. Los mensajes se encolan dentro de la transacción de adjudicación y se envían después: un fallo de envío nunca revierte la adjudicación. |
| **D14** Cero dependencias | Ni Composer, ni PHPUnit, ni jQuery, ni CDN. El verificador falla si aparece una URL externa, un `require` fuera del proyecto o un `composer.json`. |
| **D6** Pruebas | Runner propio en PHP CLI, sin Composer ni PHPUnit (D14). El caso de concurrencia **se ha resuelto de otra manera en el hito 2**: la especificación pide dos peticiones HTTP simultáneas contra Apache, y hasta el hito 4 no hay ninguna ruta que llame al motor. Ahora lanza dos procesos PHP de consola con conexiones propias. Se mide lo mismo: dos conexiones compitiendo por la misma unidad. La forma HTTP se añade en el hito 4 y solo añade la capa del servidor web. |
| **D13** Documentación | PHPDoc en toda clase y método, con las once etiquetas en inglés y la prosa en castellano. El verificador lo comprueba. |

## 5. El hito 2: motor de adjudicación

Es el más delicado del proyecto y se ha hecho antes que nada de interfaz,
porque es el único cuyo error no se ve en la pantalla y se descubre más tarde.

**Cubre** el apartado 6 de la especificación y los casos de aceptación 3, 4, 5,
6 y 7 del apartado 10.

**Lo que hay construido.**

| Fichero | Qué hace |
| --- | --- |
| `app\Models\UnidadPremio.php` | La cola. Lee la primera unidad pendiente con `inicio <= momento` ordenada por `inicio, id`, y la entrega con un `UPDATE` condicional del que devuelve el número de filas afectadas. Sortea además el código de reclamación. |
| `app\Models\Participacion.php` | Participaciones válidas, incluida la búsqueda por clave de idempotencia que convierte un doble clic en una no-operación. |
| `app\Models\IntentoRechazado.php` | Intentos rechazados, sin datos de la clienta y sin ningún índice único que pueda bloquear a nadie (D10). |
| `app\Models\Correo.php` | Encola el mensaje dentro de la transacción con sustitución de marcadores por lista blanca. Encolar, no enviar: el envío es del hito 5. |
| `app\Services\ValidadorReglas.php` | La interfaz del validador. La implementa de verdad el hito 4. |
| `app\Services\Adjudicador.php` | El motor. Bloquea la promoción, abre la transacción y aplica el algoritmo del apartado 6 paso a paso. |

**El algoritmo, en orden.** Lo primero es mirar si el intento ya se resolvió, para
que un doble clic no consuma un segundo premio. Después se consulta el validador
inyectado, y si rechaza se escribe el rechazo y se sale sin haber tocado la cola.
Solo si acepta se mira la cola, y solo entonces se toca `unidades_premio`. La
comparación de la hora es «menor o igual», no «menor»: en la hora exacta en que
un premio queda disponible, ese premio ya se puede repartir.

**Las tres defensas contra la adjudicación doble, y por qué están las tres.**
La primera es el bloqueo con nombre de la promoción. La segunda, el `FOR UPDATE`
sobre la fila de la unidad. La tercera, el `UPDATE ... WHERE estado = 'programada'`
con comprobación de filas afectadas, que es la que sigue valiendo aunque alguien
olvide las otras dos. El motor reintenta con la siguiente unidad si la entrega sale
con cero filas, y si tampoco lo consigue lanza un error en vez de fingir que no
había premio: decir «sin premio» cuando había uno y no se ha podido entregar es
como se pierde un premio sin que nadie lo sepa.

**Decisión cerrada: el motor valida, con la regla inyectada.** El motor recibe un
validador de reglas y es el único que decide. Así la garantía de que **ninguna
participación inválida consume un premio** queda dentro del motor y la comprueba
una prueba, en vez de depender de que el controlador llame a la operación
correcta. En el hito 2 el validador es un doble que permite o rechaza a voluntad,
y el hito 4 solo tiene que implementar la interfaz: es puramente aditivo, no hay
que tocar el motor.

**Los casos de aceptación que lo cierran y dónde están cubiertos** (apartado 10):

| Caso | Qué exige | Dónde se comprueba |
| --- | --- | --- |
| 3 | Participar antes de la hora del primer premio | Caso 5 |
| 4 | Tres unidades vencidas se reparten por orden de hora | Caso 5 |
| 5 | Una regla incumplida se rechaza sin consumir unidad | Caso 6 |
| 6 | Dos participaciones simultáneas, una sola unidad | Caso 7 |
| 7 | Repetir un mismo intento no duplica nada | Caso 6 |

**El caso 6 necesita dos procesos de verdad, y no es un detalle.** Con una sola
conexión no hay competencia, el bloqueo se concede siempre a la primera llamada y
la prueba pasaría sin comprobar nada. Por eso `tests/_proceso.php` se lanza dos
veces como proceso independiente, con conexiones propias. Cada uno repite la
comprobación de que la base de pruebas no se llama igual que la de la campaña, que
en `tests/run.php` es la comprobación principal: es el único punto del proyecto
donde una prueba podría escribir en la base de un supermercado real.

**Dos cosas que se encontraron al escribir las pruebas y que conviene no
olvidar.** La primera, que el esquema **no permite borrar una promoción con un
solo `DELETE`**: `fk_unidades_tipo` y `fk_participaciones_tramo` son
`ON DELETE RESTRICT`, así que la cascada se bloquea a mitad. La limpieza de las
pruebas borra tabla por tabla en el orden que imponen esas dos restricciones, y el
comentario de `tests/_escenario.php` explica por qué. La segunda, que
`asignaciones_tramo` no tiene `promocion_id`: se llega a ella por el tramo.

**Queda pendiente de confirmar por el promotor** (no bloquea el hito 2): la
política de que los premios pendientes pasen al tramo y al día siguiente es un
supuesto de la especificación, y el apartado 6 pide confirmarla antes de una
campaña real. Ver la sección 6.

## 6. Riesgos y limitaciones abiertas

| Asunto | Estado |
| --- | --- |
| **El límite de intentos se puede saltar borrando las cookies.** Vive en la sesión del navegador. | Conocido. Documentado en el README. La mitigación que sirve de verdad es un límite por dirección IP en Apache, que no se ha añadido porque depende de la configuración del servidor y no del proyecto. |
| **La suposición sobre la cola está sin confirmar por el promotor.** Que los premios pendientes pasen al tramo y al día siguiente es un supuesto de implementación, no una regla confirmada. | Pendiente de confirmar antes de una campaña real, como avisa el apartado 6. |
| **Horas de verano.** Un tramo que cruce el cambio de hora de octubre tiene una hora de pared ambigua o inexistente. | El apartado 13 pide validarlo. Está pendiente de implementar en el generador de calendario. |
| **La instalación de XAMPP no tiene `mail()` ni GD.** | Resuelto por diseño (D1 y validación con `finfo`). |
| **La base `sorteos` tiene filas de auditoría de las pruebas manuales.** | Sin consecuencias: el instalador nunca borra datos y el repositorio no contiene la base. |

## 7. Reglas que no hay que romper

Estas están fijadas y comprobadas a propósito. Romperlas reintroduce fallos ya
encontrados:

- **Nunca `DROP` en el instalador.** `CREATE ... IF NOT EXISTS` y listo, para
  que se pueda repetir sin romper una campaña en marcha (D12).
- **Nunca `exit` dentro de la aplicación web.** Se corta la petición lanzando
  una excepción; el verificador lo falla. Para redirigir existe
  `App\Core\Redirigir`.
- **Todas las salidas HTML se escapan** con `Vista::e()`. Las consultas van
  parametrizadas. El verificador busca `var_dump`, `print_r` y restos de
  depuración.
- **`Aplicacion::usarBaseDePruebas()` solo desde `tests/run.php`.** Rechaza
  las peticiones web y registra un aviso en stderr si se llama de otro sitio.
- **`config/config.php` no se versiona.** Va con credenciales y con el secreto
  de HMAC. Está en el `.gitignore`.
- **`bbdd.png` no se versiona.** Es un diagrama personal del usuario, no
  documentación del proyecto. Que aparezca como `?? bbdd.png` en `git status`
  es lo esperado, no un descuido pendiente de limpiar.
- **Una retirada por commit y un commit por hito.** El mensaje cita las
  decisiones afectadas, como el del hito 0.
- **Cero dependencias externas** (D14). El verificador falla si aparece una URL
  externa o un `composer.json`.

## 8. Trampas conocidas

Cosas que ya han costado tiempo y que conviene no volver a cruzar.

- **`validador::telefono()` acepta 11 dígitos para números españoles.** El
  prefijo `+34` son 2 dígitos más 9. La prueba lo cubre.
- **`Vista::renderizar()` y `Vista::mostrar()` gestionan el título de forma
  distinta.** El bug de que `renderizar()` ignoraba el `titulo` ya está
  corregido y cubierto por la prueba del caso 3.
- **En la consola `Autorizacion::usuario()` siempre devuelve null**, porque
  `esPeticionWeb()` mira `PHP_SAPI`. Es lo correcto: los scripts de línea de
  comandos se ejecutan sin usuario. Pero significa que **la autorización por rol
  no se puede probar desde la suite**; hay que probarla contra el servidor.
- **Un contador en `$_SESSION` que se limpia al leer.** `bloqueoDe()` limpia los
  intentos cuando el bloqueo ya ha pasado, y si esa limpieza se ejecuta también
  cuando nunca hubo bloqueo, el contador se borra antes de incrementarse y
  nunca llega al límite. Solo se limpia si había una marca de bloqueo.
- **PHP guarda en caché el resultado de las llamadas al sistema de ficheros.**
  Tras escribir un fichero, `filesize()` puede devolver el valor anterior. La
  prueba del caso 4 llama a `clearstatcache()` por eso.
- **Para comprobar que una ruta de código no escribe nada**, se activa el log
  general de consultas de MariaDB y se busca `CREATE`, `INSERT`, `UPDATE`,
  `DELETE` o `DROP`. Es como se verificó que `--diagnostico` es de solo lectura.

## 9. Entorno

| Componente | Versión |
| --- | --- |
| PHP | 8.0.30 |
| MariaDB | 10.4.32 |
| Apache | 2.4 (XAMPP) |
| Proyecto | `C:\xampp\htdocs\sorteos` |
| URL | `http://localhost/sorteos/` |

**Las credenciales no están en este fichero.** Viven en `config/config.php`, que
no se versiona, y en `config/config.example.php` está la plantilla con
marcadores de posición. La contraseña de la cuenta de administrador se imprime
una sola vez, al instalar, y no se puede recuperar desde la aplicación.

`php.ini` de la línea de comandos tiene `Europe/Berlin`, y no es un problema:
la aplicación fija `Europe/Madrid` en el arranque (D7). El diagnóstico avisa si
no coincide.

## 10. Cómo seguir leyendo

Según la duda, el orden es:

1. La sección correspondiente de `especificacion_sorteos_supermercado.md`, que
   define **qué** hay que hacer.
2. El apartado 13 de la especificación, que fija **cómo** y por qué.
3. Este documento, para saber en qué punto del desarrollo está esa parte.
4. El comentario del método, que cita la decisión y el apartado que implementa.

## 11. Registro de hitos

Un commit por hito. Cada entrada dice qué se ha cerrado y qué se ha decidido,
para que no haga falta releer el código.

### Hito 0 — Especificación (`fcf6770`)

Addendum D1–D19 escrito antes del código, como se acordó en D16. Se conservan
las seis preguntas originales del promotor por trazabilidad, marcadas como
resueltas. Se inicializa el repositorio con el `.gitignore` de D17.

### Hito 1 — Base y acceso (`8c0baf5`)

Andamiaje completo y acceso funcionando. Durante la aceptación por HTTP se
encontraron y corrigieron cinco fallos que ninguna prueba automática detectaba,
y son la razón de varias reglas de la sección 7:

- El contador de intentos fallidos nunca llegaba al límite, porque la limpieza
  que se ejecuta al vencer un bloqueo se ejecutaba también cuando no había
  bloqueo. El contador se borraba antes de incrementarse.
- El nombre de usuario no se normalizaba antes de contar, así que `admin`,
  `Admin` y `ADMIN` compartían cuenta pero no contador. Bastaba alternar
  mayúsculas para no llegar nunca al límite.
- El mensaje de bloqueo mostraba segundos como si fueran minutos («900
  minutos»).
- El plural de «intento» era incorrecto («Te quedan 1 intentos»).
- `Autorizacion::exigir()` trataba igual a quien no ha entrado y a quien tiene el
  rol equivocado, y para cortar la petición usaba `exit`, que el verificador
  prohíbe. De ahí salió `App\Core\Redirigir`.

También se corrigió una condición invertida en `index.php` que dejaba vacío el
mensaje de sesión caducada, y se hizo que `--diagnostico` no modificase nada de
verdad, verificado con el log general de consultas.

**Cómo se comprobó.** Pruebas por HTTP con `curl` contra el servidor: cinco
intentos fallidos y el bloqueo posterior, rechazo de la contraseña correcta
durante el bloqueo, variantes de mayúsculas, usuario inexistente, cierre de
sesión con y sin token CSRF, y las cuatro combinaciones de rol y ruta.

### Hito 2 — Motor de adjudicación (`f8844fe`)

La pieza más delicada del proyecto, hecha antes que ninguna pantalla. Cuatro
modelos, un servicio y una interfaz de validador.

**Decisiones tomadas aquí.**

- **El motor valida y el validador se inyecta.** Lo pidió el promotor y evita
  que la garantía de «ninguna participación inválida consume un premio» dependa
  de que el controlador llame a la operación correcta. El hito 4 solo tiene que
  implementar `ValidadorReglas`.
- **El nombre del bloqueo se ha alargado** a `sorteos:adjudicacion:{id}` en lugar
  del literal `sorteo:{id}` de D8. `{id}` sigue siendo el identificador de la
  promoción y la espera sigue siendo de cinco segundos; lo que cambia es el
  nombre, porque `GET_LOCK` es global al servidor. Queda anotado como desviación
  consciente en la sección 4.
- **El correo se encola aquí, no en el hito 5.** Es lo que pide D1, y la razón
  para separarlo está en el comentario de `app\Models\Correo.php`.
- **El caso de concurrencia no va por HTTP.** D6 lo pide, y no se ha hecho así:
  hasta el hito 4 no existe ninguna ruta que llame al motor, así que no hay nada
  a lo que lanzar dos peticiones. Se lanza dos procesos PHP de consola con
  conexiones propias, que es donde está la propiedad que importa. Queda
  anotado como desviación en la sección 4 y en el README.

**Fallos que aparecieron al escribir las pruebas y que no se habrían visto
después.**

- `valoresPara()` no pasaba la dirección de correo al modelo de correo, que la
  busca para saber a quién escribir. Con el correo activado, `ErrorValidacion`
  salía de dentro de la transacción y la adjudicación entera se deshacía: ni
  premio, ni participación, ni correo. Ahora la dirección viaja en el array de
  valores pero **no** entra en la lista de marcadores, así que nunca aparece en
  el texto.
- El esquema **no permite borrar una promoción con un solo `DELETE`**, porque
  `fk_unidades_tipo` y `fk_participaciones_tramo` son `RESTRICT`. La limpieza de
  las pruebas lo hace tabla por tabla respetando ese orden.
- `bin\verificar_docs.php --caso 7` ejecutaba el caso **1**: el parser solo
  aceptaba `--caso=7`, y sin el igual la opción valía `true`, que convertido a
  entero es 1. Ahora se rechaza y se dice por qué.

**Cómo se comprobó.** 141 comprobaciones en 8 casos. El caso 5 cubre los casos de
aceptación 3 y 4 con las tres horas del ejemplo del apartado 6; el caso 6, el 5 y
el 7; y el caso 7 lanza dos procesos PHP independientes con conexiones propias
sobre una sola unidad, y comprueba que uno gana, el otro no, y que en la tabla
queda exactamente una unidad entregada. También se comprobó a mano que el proceso
hijo **se niega a arrancar** si se le pasa el nombre de la base de la campaña.
