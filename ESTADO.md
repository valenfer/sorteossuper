# Estado del proyecto

Este fichero es el punto de entrada para empezar a trabajar sin contexto previo.
Responde a tres preguntas: **dónde está el proyecto**, **cómo se comprueba que
sigue sano** y **qué toca hacer a continuación**.

Se actualiza al final de cada hito, en el mismo commit del hito.

- Requisitos funcionales: `especificacion_sorteos_supermercado.md`
- Instalación, configuración y decisiones: `README.md`

Si algo de este documento contradice a la especificación o al README, mandan
ellos, y este fichero es lo primero que hay que corregir.

**Si estás retomando el proyecto ahora, ve a la sección «Por dónde continuar».**
Ahí está el punto de partida en una pantalla: qué está cerrado, qué toca y qué
dos avisos hay que leer antes de escribir código.

## 1. Qué es y en qué punto está

Aplicación web para repartir premios en un supermercado: un administrador
configura la promoción y el personal de tienda registra las participaciones.

| Hito | Estado | Commit | Qué cubre |
| --- | --- | --- | --- |
| 0. Especificación | Terminado | `fcf6770` | Addendum D1–D19 y las 19 decisiones de implementación. |
| 1. Base y acceso | Terminado | `8c0baf5` | Núcleo, sesiones, rutas, vistas, acceso por roles, instalador, verificador, pruebas, README. |
| 2. Motor de adjudicación | Terminado | `f8844fe` | Cola de premios, transacción con bloqueo, evita adjudicaciones dobles. |
| 3. Configuración de la promoción | Terminado | `9de72c6` | Panel del administrador: días, tramos, tipos de premio, cantidades, calendario. |
| 4. Participaciones y resultados | Terminado | `0505a60` | Registro por la azafata, reglas, identidad, las dos pantallas. |
| 5. Correo | Terminado | `550eaa8` | Transporte `log` y `smtp`, cola de mensajes, reintentos, worker. |
| 6. Panel de seguimiento | Terminado | `53f90be` | Métricas, filtros, auditoría, cierre de promoción. |
| 7. Scripts de línea de comandos | Terminado | `51fec02` | El worker del correo y el purgador de datos por retención. |
| 8. Concurrencia por HTTP | Terminado | `cb9b714` | El caso 19: dos participaciones simultáneas de verdad contra Apache (D6). |

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
| `verificar_docs.php` | `Todo correcto: 75 ficheros, sin problemas` |
| `tests\run.php` | `Todo correcto: 23 casos ejecutados, 681 comprobaciones` |
| `instalar.php --diagnostico` | `Diagnostico terminado`, sin ninguna escritura |
| `instalar.php` | Idempotente: se puede repetir sin romper nada |
| `enviar_correos.php` | Enviados 0, fallidos 0 con la cola vacía, sin error |
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

**Hecho (hito 3).** El panel de promociones: las once pantallas de administración
con sus rutas, el servicio `ConfiguracionPromocion` con las reglas de activación, y
la generación del calendario desde el plan de tramos y cantidades. Ver la sección 5.

**Hecho (hito 5, y cierre del 4).** Las pantallas de participación y de resultado,
las reglas de campaña como validador real (`ReglasCampana` con
`IdentidadCampana`), los dos transportes de correo, la cola con reintentos y el
worker `bin/enviar_correos.php`. Ver el registro de hitos, al final.

**Hecho (hito 6).** El cierre de promoción y el panel de seguimiento del
administrador: el cierre transaccional con bloqueo de campaña, y la pantalla con
métricas, filtros, listados, diferencias entre plan y calendario e historial. Ver el
registro de hitos, al final.

**Las 15 tablas del esquema ya existen** (`sql/schema.sql`), incluida la de
participaciones, la de cola de correos y la de auditoría. El modelo de datos está,
el motor que usa las cuatro tablas centrales, el panel que las configura, la
pantalla que las usa y el worker que manda el correo.

**Hecho (hito 7).** La purga de datos personales: la regla de elegibilidad en un
solo sitio, el vaciado de `participaciones`, `correos` e `intentos_rechazados` sin
borrar la fila, los correos pendientes a salvo y un asiento de auditoría por
campaña.

**Hecho (hito 8).** La prueba de concurrencia por HTTP de verdad, que es el caso 19.

**Hecho (hito 9).** Las imágenes: `Imagenes` probado de punta a punta, `uploads`
sirviendo y sin ejecutar, y los banners pintados en las tres pantallas.

**Hecho (hito 10).** El calendario se revisa a mano: se añade una unidad suelta, se
le cambia la hora o el tramo y se retira, todo por POST con token y con confirmación,
y la tabla avisa de en cuanto el calendario se separa del plan. Ver el registro de
hitos, al final.

**Hecho (hito 11).** El calendario no se pasa del plan y cada revisión deja asiento.
Las dos decisiones de producto que quedaban abiertas están cerradas: añadir o mover
una unidad a un par de tramo y premio que ya tiene todas las del plan se rechaza, y
las cuatro revisiones del calendario —añadir, mover, retirar y generar— escriben una
fila en `auditoria` con quién las hizo y con lo que había antes. Ver el registro de
hitos, al final.

**Lo que no hay todavía.** Nada de los nueve casos de aceptación del apartado 10
está pendiente: el 2 se cerró en el hito 10. Las dos decisiones de producto que
quedaban abiertas se han cerrado en el hito 11, así que no hay ninguna tarea técnica
pendiente ni ninguna decisión abierta. Ver el registro de hitos, al final.

**En la raíz hay un `bbdd.png` con un diagrama de la base de datos hecho a
mano.** Se versiona desde el hito 3, con la autorización del promotor, porque es
la referencia del esquema y sin ella hay que leer quince tablas para entender una
consulta. No borrarlo ni moverlo sin preguntar. Está versionado desde el hito 3,
con autorización del promotor, y así lo dice también la sección «Reglas que no hay
que romper».

### Por dónde continuar

Este es el resumen para retomar el trabajo. Si solo se lee una cosa de todo el
documento, que sea esto.

**Punto exacto en el que está.** Los hitos 0 a 11 están cerrados, las decisiones D4,
D6 y D19 están confirmadas, y los once hitos más `ddd9537` están subidos a
`origin/master`. A eso se suman dos correcciones de fallos reales, sin hito porque no
lo son: la de las imágenes subidas y la del borrado de tramos. El commit de
D19 es `c6e91c4`, el de D4 es `dbf2fdf`, el del hito 7 es `51fec02`, el del hito 8,
que es D6, es `cb9b714`, el del hito 9 es `311b23d`, el del hito 10 es `cf29f14` y el
del hito 11 es `bdb193d`.

**Lo siguiente, por este orden.**

1. **Subir este commit a `origin/master`.** Es lo único que queda.
2. **Nada pendiente después de eso.** El calendario no se pasa del plan al añadir ni
   al mover, y las cuatro revisiones —añadir, mover, retirar y generar— dejan asiento.
   El caso 22 lo prueba y el caso 21 se ha reescrito para el contrato nuevo. Las
   imágenes subidas se sirven con `Aplicacion::subida()` y un tramo sin unidades
   vivas se puede borrar. La suite son 23 casos y 681 comprobaciones.
3. **Los nueve casos de aceptación tienen su camino completo**, y no queda ninguna
   decisión de producto abierta: las dos que quedaban se han cerrado en el hito 11.
   Lo único que puede pedir trabajo a partir de aquí es el promotor, y no hay ninguna
   pregunta esperando respuesta.

**La base de datos real está vacía, a propósito.** Se borraron todas las promociones,
tramos, premios, unidades, participaciones y asientos de auditoría para empezar de
cero, junto con las tres imágenes que había en `uploads/1/`. Las dos cuentas de
usuario se conservaron, porque sin ellas no se puede entrar a crear nada. Quedan las
15 tablas y las migraciones aplicadas, así que hace falta crear campañas, no migrar.

**Antes de escribir código nuevo, diez avisos.**

- La purga vacía correos en estado «pendiente» si alguien la llama mal, y eso
  significa que el worker manda un correo en blanco con el código de reclamación
  perdido. La condición está dentro de `Correo::purgar()`, no en el servicio, y
  por eso no hay ninguna forma de saltársela: si alguna vez hay que tocar esa
  consulta, hay que tocar también la prueba del caso 16 que comprueba que el
  pendiente sobrevive intacto.
- **El caso 19 toca el `.htaccess` de la raíz**, que es un fichero real y versionado
  del proyecto, y lo deja como estaba al terminar. Eso casi salió bien: la primera
  versión convertía los finales de línea de CRLF a LF y dejaba líneas en blanco
  acumuladas, así que después de cada ejecución el `git status` enseñaba un
  `.htaccess` modificado con 121 líneas que nadie había escrito. Si alguna vez hay
  que tocar ese bloque, hay que conservar los finales de línea que ya tiene el
  fichero, y comprobar con `git status` que no se queda nada puesto.
- **`uploads/` no puede volver a la lista de directorios prohibidos de la raíz.** El
  hito 9 la sacó de ahí porque las imágenes se guardaban y no se veían nunca. Lo que
  protege esa carpeta es su propio `.htaccess`, y su barrera principal es un
  `SetHandler none` que hay que escribir de verdad: estaba descrito en el comentario
  del fichero y no existía.
- **Las imágenes de la campaña se sirven con `Aplicacion::subida()`, nunca con
  `Aplicacion::asset()`.** Son seis puntos en tres vistas: los premios del panel, y
  la imagen del resultado y los dos banners de las pantallas de participación. Los
  seis lo hacen con ese método. `asset()` antepone **siempre** la carpeta `assets/`,
  donde están el CSS y el JavaScript, así que llamarla con `'uploads/...'` construía
  `/sorteos/assets/uploads/...`: una carpeta que no existe. **Las imágenes se
  guardaban bien y no se veían nunca**, sin error de PHP, sin nada en el log y sin que
  ninguna prueba lo notara, porque las pruebas buscaban la cadena `uploads/` dentro
  del HTML y esa cadena aparece igual en la URL buena y en la mala. Para comprobar
  una URL hay que mirar el atributo `src` entero, no una cadena suelta.
- **El tramo y el hora viajan por POST, pero el servicio los recibe por argumento.**
  En las rutas antiguas —tramos, cantidades, borrar tramo— el identificador va en la
  URL (`/tramos/{tramo}/borrar`) y por eso se lee con `parametroId()`. En el
  calendario no: el tramo es una opción de un desplegable, así que va en el cuerpo y
  se lee con `recibido()`. **Mezclar las dos formas rompe la acción en el
  navegador**, y no se ve en la consola: `parametroId()` lee los parámetros de la ruta,
  que ahí no existen, y lanza un 404 antes de mirar el POST. El caso 21 es el que lo
  cazó.
- **Un tramo con todas sus unidades anuladas ya se puede borrar, y antes no.** El
  fallo era un callejón sin salida: `Tramo::borrarSiEstaLibre()` contaba cualquier
  unidad, y una unidad anulada no estorba —está muerta y no cuenta en ninguna cifra
  del panel—. El aviso de error decía «retira esas unidades primero», así que quien
  lo leía las retiraba una a una desde el calendario y, al volver a pulsar «Borrar el
  tramo», el tramo seguía sin borrarse porque las unidades seguían ahí, ahora
  anuladas. Retirar no borra la fila, así que no había salida: la única era crear otro
  tramo. Se ve entero en `auditoria` de la campaña 1, con ocho retiradas a mano y
  después un tramo nuevo. **Los tres motivos que puede dar el bloqueo van en
  `obstaculosParaBorrar()`, y solo se promete «retira esas unidades» cuando
  `retirables` iguala a `unidades`**: una unidad entregada no se puede retirar y era
  otro mensaje que mandaba a un sitio donde el botón no hace nada. Y ojo al caso en
  que hay unidades **y** participaciones: no es «o una cosa o la otra», y con la
  primera versión del mensaje no salía ninguna de las dos advertencias. Retirar las
  unidades tampoco desbloquea, así que se dice expresamente que el tramo seguirá sin
  poder borrarse por la participación. Y las unidades que quedan las cuenta como lo
  que son, unidades de premio: llamarlas «sin adjudicar» sería mentira, porque una
  unidad entregada sí está adjudicada.
- **El asiento de auditoría de una retirada lo escribe `Calendario::retirar()`, no
  `UnidadPremio::anular()`.** Son dos puertas distintas al mismo estado, y por la
  corta no hay asiento: una prueba que llame al modelo para «retirar unidades» no
  tiene nada que comprobar cuando dice que la decisión de retirarlas sobrevive al
  borrado del tramo. Hay que tirar por el servicio, que es además el camino que
  recorre el botón.
- **En SQL parametrizado, el orden de los parámetros es el orden de los
  interrogantes**, no el que parece más lógico al leer la consulta. Pasó al
  escribir `obstaculosParaBorrar()`, que encadena subconsultas: se pasó el estado
  antes que el tramo porque parecía más limpio agruparlos por tipo, y MariaDB
  devolvió `Invalid parameter number`. El síntoma —cifras a cero sin error de sintaxis—
  se lee como un problema de lógica, no de parámetros.
- **Probar que algo se puede hacer no es probar que se avisa bien.** Aquí el fallo
  eran las dos mitades: el `DELETE` se negaba sin razón y el mensaje, además,
  prometía una salida que no llevaba a ninguna parte. Comprobar el `0` que devuelve
  el modelo deja pasar la mitad del texto, así que el caso 10 tira del POST de
  verdad y lee el aviso con `Vista::aviso('error')`, que es lo que ve el
  administrador.
- Las secciones «Reglas que no hay que romper» y «Trampas conocidas» de este
  documento son las que más tiempo ahorran. La primera la hace cumplir el
  verificador; la segunda no, y por eso está aquí.

**D4 está confirmada: la cola persiste.** Ya no es una pregunta pendiente. El
promotor ha confirmado que los premios pendientes **no** se reubican al tramo ni al
día siguiente: se quedan en la cola global y salen por orden de `inicio`. Era la
mitad de la decisión que quedaba abierta, y ahora las dos mitades están
implementadas y probadas.

Lo escrito es lo que ya hacía el código. `UnidadPremio::primeraPendiente()` filtra
por campaña, por estado y por `inicio <= momento`, ordena por `inicio, id` y no
mira ni el tramo ni la fecha; por eso un premio del lunes sin reclamar sale antes
que cualquiera de los del martes. El cierre del hito 6 ya convertía las unidades no
entregadas en `no_entregada`, y eso no ha cambiado.

Hay que decirlo porque la especificación dice las dos cosas: su tabla de decisiones
dice que «los premios pendientes pasan al tramo y al día siguiente» y su cuerpo
dice que la cola persiste. Gana el cuerpo, y por razones que se pueden comprobar:

1. Con un filtro por día, un premio del lunes sin reclamar no saldría nunca. Se
   quedaría `programada` para siempre y el cierre lo convertiría en `no_entregada`
   aunque nadie lo rechazara.
2. `tramo_id` es la referencia autoritativa del horario original (D9). Reescribirlo
   haría que un premio del lunes pareciera suyo del martes.
3. `inicio` es contra lo que el panel mide el retraso. Moverlo a hoy haría que un
   premio del lunes reclamado el martes informara de cero minutos de retraso.

Por eso la reubicación no era una mejora pendiente: era lo que habría roto el
retraso y el horario. Si alguna vez hay que reorganizar la cola, el sitio es una
tabla de asignaciones con su propia fecha, nunca `inicio` ni `tramo_id`.

Lo que sí se ha eliminado es `ConfiguracionPromocion::moverPremiosPendientes()`,
un método vacío cuyo nombre prometía la reubicación y cuyo cuerpo no hacía nada.
No lo llamaba nadie. Dejarlo era peor que no tenerlo: el siguiente que lo leyera
podría pensar que el comportamiento estaba resuelto.

## 4. Decisiones que condicionan el trabajo

Están en el apartado 13 de la especificación y resumidas en el README. Las que
más afectan a lo que viene:

| Decisión | Por qué importa a la hora de escribir código |
| --- | --- |
| **D8** Concurrencia | Bloqueo con nombre por promoción, `SELECT ... FOR UPDATE` sobre la unidad y un `UPDATE ... WHERE estado = 'programada'` del que se comprueba el número de filas afectadas. El último es el que garantiza por sí solo que una unidad nunca se adjudica dos veces. No usar `SKIP LOCKED`: no existe en MariaDB 10.4. **Desviación consciente:** el nombre real del bloqueo es `sorteos:adjudicacion:{id}` y no el literal `sorteo:{id}` de la decisión. `{id}` sigue siendo el de la promoción y la espera sigue siendo de 5 segundos, que es lo que D8 fija; lo que se alarga es el nombre, porque `GET_LOCK` usa un espacio de nombres global del servidor y un `sorteo:1` PODría colisionar con el de otra aplicación en el mismo MariaDB. Ver el comentario de `Db::bloquearPromocion()`. |
| **D3** Identidad | Huella `HMAC-SHA256` con secreto de configuración, nunca el dato en claro. La huella lleva dentro su ámbito, así que un único índice cubre «una por campaña», «una por día», «una por ticket» y «una por código». |
| **D10** Separación | `participaciones` solo contiene participaciones válidas y es la única tabla con índices únicos. Los rechazos van a `intentos_rechazados`, sin índice único, para que un rechazo no bloquee a nadie. |
| **D4** Cola y cierre | **Confirmada.** La cola de premios persiste a lo largo de los tramos y de los días: los pendientes **no** se reubican y salen por orden de `inicio`. Al cerrar, las unidades no entregadas pasan a `no_entregada`, sin adjudicación retroactiva. La tabla de decisiones de la especificación dice lo contrario («pasan al tramo y al día siguiente»); gana el cuerpo, por las tres razones de la sección «Por dónde continuar». `UnidadPremio::primeraPendiente()` no filtra por tramo ni por fecha, y el caso 5 lo comprueba con dos días reales. |
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

## 5 bis. El hito 3: panel de promociones

Once pantallas, todas detrás del rol de administrador, y un servicio que valida lo
que llega de ellas.

**Lo que hay construido.**

| Fichero | Qué hace |
| --- | --- |
| `app\Services\ConfiguracionPromocion.php` | Todo lo que el panel guarda: datos generales, campos del formulario, reglas, ajustes, apariencia y la comparación plan/calendario. Concentrarlo aquí es lo que permite probarlo sin pasar por HTTP. |
| `app\Services\Calendario.php` | El generador del reparto. Toma el plan de tramos y cantidades y reparte las unidades por los minutos que quedan, con el diagnóstico previo de D2. |
| `app\Controllers\ControladorCampanas.php` | Listado, ficha y datos generales. |
| `app\Controllers\ControladorFormulario.php` | Premios y campos del formulario. |
| `app\Controllers\ControladorCampana.php` | Reglas, ajustes, apariencia, tramos y calendario. |
| `views\admin\` | Las once vistas, sin JavaScript. |

**Sin JavaScript, a propósito.** Todas las pantallas funcionan con el navegador
tal como sale de la caja. La tabla de campos del formulario trae siempre una
fila vacía al final, y se añade un campo escribiendo en ella y pulsando el mismo
botón de guardar; quitarlos es dejar vacías su clave y su etiqueta. Un botón con
JavaScript que quite filas sería más elegante y dejaría la pantalla inservible en
la tablet del mostrador el día que el JavaScript no cargue.

**Lo que se decidió aquí y conviene conocer.**

- **Activar comprueba que hay calendario.** Un tramo con cantidades no es un
  calendario: sin unidades programadas la campaña se abriría sin nada que
  repartir. `UnidadPremio::contarEntregables()` cuenta solo las `programada`, que
  son las que se pueden entregar.
- **Un `estado` mandado a mano se rechaza, no se ignora.** `guardar()` no acepta
  esa clave. Ignorarla sería más corto, pero dejaría un fallo invisible: quien
  escribiera `estado=activa` creería que la campaña se ha activado y lo que
  pasaría es que se guardaría sin decir nada, en borrador. Un error que no se ve
  es peor que un error que se ve.
- **La fila de campos del formulario no lleva `required`.** Con `required`, el
  navegador no deja pulsar «Guardar» si la fila de abajo está en blanco, que es
  justo lo que su propio texto de ayuda pide. La obligatoriedad la comprueba el
  servidor, que además es el único que puede explicarse.
- **Subir una imagen antes de guardar deja dos cosas que recoger**, y las dos se
  recogen: si el guardado falla se borra la imagen recién subida, y si va bien se
  borra la que ha quedado sustituida. Sin esto la carpeta de cada campaña se
  llenaba de versiones viejas que nadie ve pero que ocupan disco.
- **`redirigir()` no llama a `header()` en consola.** Con el mismo criterio que
  ya usaba `Csrf::token()`: en consola no hay cabeceras que mandar y el aviso de
  PHP no dice nada útil. Lo que se guarda es el aviso, que es lo que miran las
  pruebas.

**Fallos que aparecieron al escribir las pruebas y que no se habrían visto
después.**

- El proceso hijo de la prueba de concurrencia buscaba la campaña por nombre con
  un `SELECT ... LIMIT 1` sin orden. Con dos campañas del mismo nombre —una
  ejecución anterior que se quedó a medias— participaba en la que le tocaba y
  fallaba con un error que no tenía nada que ver. Ahora el padre le pasa el
  identificador, que no admite confusión.
- `CampoFormulario::claves()` sale ordenado por clave, no por el orden en que se
  preguntan. Las dos cosas son distintas y una prueba que las confunde pasa por
  buena: el orden va en `listarPorPromocion()`.
- La fila en blanco del formulario se descartaba en el servidor, pero el
  `required` del navegador impedía siquiera llegar a mandarla. Los dos lados
  tienen que estar de acuerdo, y ahora hay una prueba que manda el POST de
  verdad.

**Cómo se comprobó.** 202 comprobaciones en 11 casos. Los tres últimos son del
panel: el 8 monta una campaña completa y la activa, el 9 pinta las once pantallas
comprobando que cada una enseña lo suyo y que la ficha de una no enseña los
datos de otra, y el 10 prueba lo que el panel no deja hacer. Además, un guion de
humo pinta las once pantallas midiendo bytes, y `bin/verificar_docs.php` revisa
también `views/`, que antes se saltaba.

## 6. Riesgos y limitaciones abiertas

| Asunto | Estado |
| --- | --- |
| **El límite de intentos se puede saltar borrando las cookies.** Vive en la sesión del navegador. | Conocido. Documentado en el README. La mitigación que sirve de verdad es un límite por dirección IP en Apache, que no se ha añadido porque depende de la configuración del servidor y no del proyecto. |
| ~~La suposición sobre la cola está sin confirmar por el promotor.~~ | **Resuelto: D4 confirmada.** Los premios pendientes **no** pasan al tramo ni al día siguiente; se quedan en la cola global y salen por orden de `inicio`. El cierre del hito 6 ya hacía la mitad que faltaba (pasar a `no_entregada` sin adjudicación retroactiva) y el comportamiento de la cola no ha necesitado cambio: `UnidadPremio::primeraPendiente()` nunca filtró por tramo ni por fecha. Se ha quitado el método vacío `moverPremiosPendientes()`, que prometía la reubicación que D4 descarta, y el caso 5 prueba el arrastre entre dos días reales. Ver «Por dónde continuar». |
| **Horas de verano.** Un tramo que cruce el cambio de hora tiene una hora de pared ambigua o inexistente: en marzo las 02:00 no existen, y en octubre ocurren dos veces. | **Resuelto y probado.** `Tramos` rechaza el tramo que cruza la ventana, `minutosValidos()` se salta las horas que no llegaron a existir y `comprobarDentroDelTramo()` no admite un premio en una hora inexistente. El caso 18 cubre las dos ramas por separado: en marzo (29/03/2026) las 02:00 no existen y el tramo de 01:00 a 04:00 da 120 minutos en vez de 180; en octubre (25/10/2026) cada hora ocurre dos veces y el mismo tramo sí da los 180. También comprueba los dos bordes, que son un turno de apertura y uno de mañana que tienen que poder existir. Ver «Por dónde continuar». |
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
- **`bbdd.png` sí se versiona, desde el hito 3 y con autorización del promotor.**
  Aquí decía lo contrario («no se versiona», «que aparezca como `??` en
  `git status` es lo esperado») y era verdad hasta el hito 3. La regla que queda
  escrita es la nueva: está en el repositorio porque es la referencia del
  esquema, y sin ella hay que leer quince tablas para entender una consulta. No
  borrarlo ni moverlo sin preguntar. La contradicción se/coló porque el hito 3
  corrigió la sección 3 y el registro de hitos, y se dejó esta.
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
- **`Tramos` trabaja con precisión de minuto y rechaza los segundos que no sean
  cero**, porque el esquema guarda la hora como `TIME` y el calendario reparte por
  minutos. Pasarle un instante con segundos (`H:i:s`) en vez de `H:i` hace que
  **  no haya tramo vigente 1 segundo de cada 60**. Pasó en
  `ControladorParticipacion::tramoVigente()` y el síntoma eran participaciones que
  morían con «sin tramo activo» sin error ni rastro. Cualquier sitio que corte
  una hora de un instante debe cortar a `H:i`, no a `H:i:s`.
- **Un dato que pinta una vista y no es un campo configurado no pasa por
  `recoger()`.** `recoger()` solo recorre los campos visibles de la campaña, así
  que la casilla de consentimiento, que la pinta el propio formulario, se
  perdía por el camino y las reglas no la veían nunca. La campaña con «exigir
  consentimiento» rechazaba a todo el mundo, marcada la casilla o sin ella, y el
  motivo del rechazo era el correcto, de modo que no señalaba el fallo. Si se
  añade un dato nuevo a un formulario, hay que asegurarte de que entra en `$datos`.
- **`codigo_reclamacion` está en `unidades_premio`, no en `participaciones`.** El
  código es de la unidad adjudicada, que es la que hay que recoger en el
  mostrador. Las pruebas que lo busquen en la participación reciben un error de
  columna inexistente.
- **Una clave de vista llamada `datos` no llega a la plantilla.** `Vista::renderizar()`
  hace `extract($datos, EXTR_SKIP)` sobre un parámetro que ya se llama `$datos`, y
  `EXTR_SKIP` no pisa variables que ya existen: la clave se queda en el aire y la
  plantilla recibe otra cosa. El síntoma es un aviso de «clave indefinida» en una
  línea que no habla de `datos`, que es lo que hace que cueste de encontrar. La
  clave del panel de seguimiento es `$panel`, y el motivo está escrito en el
  controlador.
- **`Autorizacion::usuarioId()` devuelve `0`, no `null`, sin sesión.** Y `0` no
  puede ser un `usuarios.id`, porque la clave empieza en uno. Cualquier escritura
  en `auditoria` con ese identificador revienta con un error de clave foránea. Se
  normaliza en `Auditoria::registrar()`, no en quien llama.
- **`asignarParametros()` solo rellena los marcadores de la ruta.** Los filtros de
  la URL no llegan por ahí: el controlador los lee de `$_GET`. En una prueba, un
  filtro pasado en el array de parámetros se pierde en silencio y la comprobación
  pasa comprobando la pantalla sin filtro.
- **El instalador aplica `sql/schema.sql` y *después* todas las migraciones.** Una
  migración que añada columnas que el esquema ya tiene tiene que llevar
  `ADD COLUMN IF NOT EXISTS`, o la instalación desde cero falla con «columna
  duplicada» justo en el escenario donde no hay datos que perder.
- **`borrarEscenarioDePanel()` borra en orden de dependencia, y ese orden cambia.**
  En cuanto un caso crea participaciones, `participaciones` e
  `intentos_rechazados` tienen que ir antes que `tramos`. Si no, la limpieza falla
  con un error de integridad **después** de que el caso haya pasado todas sus
  comprobaciones, y el fallo señala la limpieza, no lo que falló.
- **`php -S` no sirve para nada que necesite concurrencia en Windows.** Es de un
  solo hilo y el `PHP_CLI_SERVER_WORKERS` responde con «forking is not supported on
  this platform». Atiende una petición y las demás esperan. Para probar dos
  peticiones a la vez hay que usar Apache.
- **Una configuración PHP con BOM hace que Apache devuelva 192 bytes de error.** Ni
  un 500, ni un 403, ni una pista: la respuesta es del servidor web y no del
  código. `Set-Content -Encoding UTF8` en PowerShell pone el BOM; el fichero
  temporal del caso 19 se tiene que escribir sin él.
- **La cookie de sesión es `SORTEOSSID` y el valor no vale entero para
  `peticionWeb()`.** `valorDeCookie()` devuelve solo lo que va después del `=`. Si se
  manda eso como cabecera `Cookie:` tal cual, el navegador simulado no tiene sesión,
  el primer POST responde 403 con «la sesión ha caducado» y da la impresión de que
  Apache no guarda sesiones. No las guarda: es que la cabecera no tenía nombre. Va
  con `cabeceraDeCookie()`.
- **Las dos peticiones del caso 19 tienen que llevar el prefijo de la URL.** La
  función `peticionWeb()` lo pone, pero el caso abre los sockets a mano para no leer
  la respuesta antes de tiempo, y ahí hay que escribir `$servidor['prefijo']` a
  mano. Sin él, Apache devuelve 404 y parece un fallo de rutas de la aplicación.
- **`ipDeLaPeticion()` está copiada en cuatro servicios.** `Adjudicador`,
  `CierrePromocion`, `Seguimiento` y `Calendario` tienen el mismo método privado de
  seis líneas, y el del hito 11 es el cuarto. No es un descuido de este hito: es el
  patrón que ya había, y tocar los otros tres dentro del hito 11 habría sido
  revisar código que funcionaba. Si algún día se unifica, el sitio es un estático en
  `Aplicacion`, y el motivo de que viva en el servicio y no en `Core` es que cada
  uno escribe en una tabla distinta con su propia firma de `registrar()`.

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

| Hito | Commit | Estado |
| --- | --- | --- |
| 0. Especificación | `fcf6770` | Cerrado |
| 1. Base y acceso | `8c0baf5` | Cerrado |
| 2. Motor de adjudicación | `f8844fe` | Cerrado |
| 3. Panel de promociones | `9de72c6` | Cerrado |
| 4. Participación y resultados | `0505a60`, cerrado en `550eaa8` | Cerrado |
| 5. Correo | `550eaa8` | Cerrado |
| 6. Panel de seguimiento | `53f90be` | Cerrado |
| 7. Purga de datos | `51fec02` | Cerrado, el worker del correo venía del hito 5 |
| 8. Concurrencia por HTTP de verdad | `cb9b714` | Cerrado, es la confirmación de D6 |
| 9. Imágenes | `311b23d` | Cerrado |
| 10. Revisión manual del calendario | `cf29f14` | Cerrado, cierra el caso de aceptación 2 |
| 11. Tope del plan y auditoría de la revisión | `bdb193d` | Cerrado, cierra las dos decisiones de producto que quedaban |

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

### Hito 3 — Panel de promociones (`9de72c6`)

Once pantallas de administración y el servicio que las valida. Todo el detalle
está en la sección 5 bis; aquí solo lo que no está allí.

**Decisiones tomadas aquí.**

- **Sin JavaScript.** Ver la sección 5 bis. Es la decisión que más condiciona al
  resto, porque se nota en cada vista.
- **Activar exige calendario generado.** Un tramo con cantidades no es un
  calendario.
- **`bbdd.png` pasa a versionarse**, con autorización del promotor. `ESTADO.md`
  decía lo contrario desde el hito 1, y ahora dice lo que se hizo.
- **`bin/verificar_docs.php` ahora recorre `views/`.** Antes se saltaba la
  mitad del código del hito. Al añadirlo aparecieron dos errores de verdad: cinco
  caracteres cirílicos dentro de la palabra «generador» en un comentario de
  `app/Models/TipoPremio.php`, y un `U+FFFD` en `tests/run.php`. Los dos
  encontrados por la máquina, no por la lectura.

**Cómo se comprobó.** 202 comprobaciones en 11 casos de la suite oficial, más
siete guiones de prueba por servicio (imágenes, modelos, comparación, tramos,
calendario, reparto y configuración). `bin/verificar_docs.php` sobre 58 ficheros
sin un solo problema. Y un guion de humo que pinta las once pantallas midiendo
los bytes que escribe, porque una vista que revienta en el navegador no la detecta
ninguna prueba de las anteriores.

### Hito 4 — Participación y resultados (`0505a60`, cerrado en `550eaa8`)

**Qué hay que construir.**

- **Pantalla de participación.** Desde la ficha de una campaña activa (o un botón
  «Participar» en la zona de la azafata), un formulario que pide los datos de
  contacto (DNI, número de ticket, correo opcional) y un campo de código de
  participación si la regla así lo requiere. El envío se hace por POST con token
  CSRF; el servidor llama a `Adjudicador::registrarParticipacion()` y devuelve
  un resultado (`premio`, `sin_premio` o `rechazada`). El aviso al usuario es
  legible y no muestra datos de otras clientas.

- **Pantalla de resultado.** Después de participar, se muestra la consecuencia:
  si le corresponde un premio, los datos del mismo (nombre, descripción); si no,
  un mensaje de consuelo. En modo simulación el resultado se muestra al instante;
  en modo real el premio se adjudica en la misma transacción.

**Decisiones y detalles.**

- **CSRF obligatorio.** Al igual que en el panel, cada petición de participación
  debe llevar un token CSRF válido; sin él se devuelve un error 403.
- **Validación del lado del servidor.** Los campos obligatorios los impone el
  servidor (`ConfiguracionPromocion::CAMPOS_OBLIGATORIOS`); el navegador `required`
  se usa solo como ayuda visual, igual que en el formulario de campana.
- **Idempotencia por clave.** Si la misma clienta envía dos veces con la misma
  clave idempotencia, la segunda se ignora y se devuelve la misma participación
  ya registrada. El doble clic no duplica nada.
- **Sin JavaScript.** El formulario y la vista de resultado funcionan sin
  JavaScript; el cambio de una pantalla a otra se hace con enlaces o redirección
  HTTP puro.
- **Resultados distintos según modo.** En modo simulación el premio se entrega
  al momento; en modo real la adjudicación sigue el mismo bloqueo de promoción
  y cola de correos que el motor ya tiene.

**Fallos que damos por resueltos.**

- El aviso de “participación rechazada” no es silencioso: el servidor devuelve un
  mensaje legible explicando por qué, con el texto que escribió la campaña y no
  un código interno.
- La participación quedada sin unidad (por ejemplo, después de deshacer una
  adjudicación) se muestra como “sin premio” y no como error.

**Cómo se comprueba.** El caso 11 cubre las reglas de duplicado y, sobre todo, el
ámbito de la huella guardada. El caso 13 cubre el flujo de la pantalla de punta a
punta, con su POST y su token. Los dos juntos cubren lo que se pedía aquí más una
cosa que no estaba en el plan: la casilla de consentimiento.

**Lo que encontró el caso 13 al escribirse.** No es un detalle, así que queda
escrito. La casilla de consentimiento la pinta `views/participacion/formulario.php`
y **no** es uno de los campos que configura el administrador, así que no pasaba
por `ControladorParticipacion::recoger()`, que solo recorre los campos visibles.
Las reglas buscan `consentimiento` entre los datos y no lo encontraban nunca: una
campaña con «exigir consentimiento» rechazaba a **todo el mundo**, marcada la
casilla o sin ella, y el motivo era el correcto, de modo que no daba ninguna pista
de que el fallo estuviera antes. Los dos sitios estaban bien escritos por
separado; lo que faltaba era el dato que los une. Ahora el controlador lo pasa a
mano y el caso 13 lo comprueba en los dos sentidos, sin casilla y con casilla, con
el mismo DNI.

**Otro fallo del mismo tipo, y peor.** `tramoVigente()` pasaba a `Tramos` el
instante entero, con segundos. `Tramos::esHora()` rechaza los segundos que no
sean cero, porque el esquema guarda la hora con precisión de minuto, así que
había tramo vigente **1 segundo de cada 60**. Fuera de ese segundo, toda
participación moría con «se ha validado una participación sin tramo activo». No
daba error, no dejaba rastro y solo se notaba por el reloj: una campaña en la que
el 98 % de las participaciones fallaban y el 2 % pasaban, sin explicación. Ahora se
pasa con precisión de minuto, y el caso 13 lo comprueba con las sesenta
posiciones del reloj en lugar de con la hora del momento, porque si no la
comprobación solo valdría 1 de cada 60 veces.

**Los dos fallos eran del mismo tipo**: una frontera entre dos módulos escrita dos
veces, en dos sitios, y que solo se rompe cuando algo pasa por los dos. Por eso el
caso 13 prueba la pantalla y no solo los servicios. Un servicio que se prueba
solo no se cruza nunca con la vista, y el hueco queda sin mirar.

**D4 — Premio pendiente en cola.** Los premios cuyo estado es `pendiente`
permanecen en la cola global de `unidades_premio` y no se filtran por tramo,
tal como establece la decisión D4. Esto evita que el pool de premios de cada
tramo se pierda al cambiar de turno (ver `app\Models\UnidadPremio`, apartado
"Por qué la cola no se filtra por tramo").

**D4 — Corregido al confirmar la decisión.** Este párrafo mencionaba un método
`ConfiguracionPromocion::moverPremiosPendientes()` «previsto para futuras
expansiones». Se ha borrado. El método estaba vacío, no lo llamaba nadie y su
nombre prometía justo lo que D4 dice que **no** se hace: reubicar las unidades al
tramo o al día siguiente. Confirmada la decisión, prometerlo era peor que no
tenerlo, porque el siguiente que lo leyera podía dar por hecho que el
comportamiento estaba resuelto. Si alguna vez hace falta reorganizar la cola, el
sitio es una tabla de asignaciones con su propia fecha, no `inicio` ni `tramo_id`.

**D19 — La ruleta decorativa, dentro de la pantalla de resultado** (`c6e91c4`).
Está hecha, en
`views/participacion/resultado.php` y en `assets/css/estilos.css`. La decisión D19
pide una ruleta «con los colores y el logotipo configurados en el panel, y sin
nombres de premios en los sectores».

**La ruleta no está en una pantalla aparte, y esa es la parte que conviene saber.**
Va en la misma página que el resultado. Lo otro obligaría a guardar el resultado
adjudicado en la sesión entre el POST y la ruleta, porque un GET no puede volver a
adjudicar: eso es estado que se puede quedar colgado, que se pierde al cerrar la
pestaña y que obliga a decidir qué hacer si la azafata recarga durante el giro. Con
una sola página no hay nada que decidir, y recargar vuelve a pintar el resultado.

**El resultado no depende de la ruleta, y esto es lo que hay que defender.** El
servidor ya adjudicó antes de que llegue a la vista, así que el texto del resultado
va escrito en el HTML desde el principio y el CSS es lo único que lo deja con
opacidad cero durante los 3,2 segundos del giro. Sin CSS, con el CSS sin cargar, con
las animaciones desactivadas o con `prefers-reduced-motion`, el texto se ve entero.
Lo contrario —esconderlo en la plantilla y revelarlo con un temporizador de
JavaScript— dejaría a la clienta mirando una pantalla sin resultado cada vez que el
script no llegara a ejecutarse. Por eso `assets/js/aplicacion.js` no toca la ruleta.

**Sin nombres de premios en los sectores.** Los sectores son solo color, en un
degradado cónico de ocho tramos que alterna `--acento` y `--primario`. El reparto es
simétrico a propósito: si un color ocupara más sectors que el otro, parecería que el
color decide el premio. El único texto es el nombre del comercio en el centro, que
es el logotipo que pide D19, y sale por `Vista::e()` como todo lo demás.

**Una ruleta sin alto no se ve.** El `div` de los sectores va vacío a propósito, y un
`div` vacío sin alto propio mide cero píxeles. El cuadrado lo reserva un
pseudoelemento con `padding-top: 100%` y no `aspect-ratio`, porque `aspect-ratio` es
de 2020 y las tablets del mostrador son más antiguas; los cuatro lados van escritos
uno a uno y no con `inset` por lo mismo.

**El rechazo no gira ruleta.** No hay nada que sortear en una participación que no se
ha registrado, y hacer esperar tres segundos para terminar con un «su participación
no se ha registrado» sería peor que decirlo de frente. El rechazo lleva la clase
`resultado-revelado`, que anula el retardo; el resultado adjudicado no la lleva.

**Cómo se comprueba.** El caso 17 (`caso17()`) pinta la vista con un resultado real y
mira lo que sale, no lo que se quería. Comprueba que el bloque de sectores no
contiene texto ninguno —con un nombre de premio reconocible en la base de datos, que
es lo que un sector rotulado llevaría—, que el centro trae el nombre del comercio,
que el texto del resultado está en el HTML antes de que ejecute nada el navegador, y
que un rechazo no trae ruleta ni retardo. También mira el CSS: que el giro y la
aparición estén declarados ahí y no en un temporizador, y que la ruleta no cargue
ninguna imagen. Lo que no se comprueba es dónde para la rueda, porque eso lo decide
el navegador y una prueba de PHP no puede medirlo.

### Hito 5 — Correo (`550eaa8`)

**Qué hay que construir.** Los dos transportes de la decisión D1, la cola de
mensajes y el worker que la vacía.

**Lo que hay.**

- `app/Services/Mailer.php` es la interfaz. `MailerLog` (el de por defecto) no
  sale del servidor: marca el mensaje como enviado y lo deja escrito en `correos`.
  `MailerSmtp` habla SMTP en PHP puro: EHLO, STARTTLS cuando el servidor lo pide,
  TLS oportunista, AUTH LOGIN, MAIL FROM, RCPT TO, DATA con el *dot-stuffing* y
  QUIT.
- `Correo::encolar()` usa `correo.transporte` de la configuración. Antes fijaba
  `log` siempre, así que encender el SMTP en la configuración no cambiaba nada y
  el correo se quedaba en la base de datos sin avisar.
- `ProcesadorCorreo` reserva cada mensaje con un `UPDATE ... WHERE estado =
  'pendiente'` y comprueba el número de filas afectadas antes de enviarlo. Es lo
  mismo que impide que dos unidades se adjudiquen dos veces, aplicado a la cola:
  dos workers a la vez no mandan el mismo correo dos veces.
- Un fallo de envío devuelve el mensaje a la cola con el error anotado y un
  contador de intentos, y para de reintentarlo al llegar al límite. **Nunca
  revierte la adjudicación**: la clienta ya tiene el premio aunque el mensaje no
  llegue, y por eso el aviso al usuario y el reintento del worker son cosas
  separadas.
- `bin/enviar_correos.php` es el worker. Con la cola vacía no hace nada y sale
  bien, así que se puede dejar en el planificador.

**Lo que encontró el caso 12 al escribirse.** El `MailerSmtp` no mandaba nada
porque no enviaba `MAIL FROM`, `RCPT TO` ni `DATA`. Se escribía el sobre a mano,
se saltaba el comando y se saltaba el final del mensaje, así que el servidor se
quedaba esperando y la conexión se caía. Solo se vio porque el caso 12 manda
contra un servidor SMTP falso de verdad, en otro proceso, y ese servidor responde
a lo que le llega. Un doble de transporte habría dado verde.

**Cómo se comprueba.** El caso 12 cubre el encolado, los dos transportes, el
fallo que no revierte, el bloqueo de un mensaje ya enviado, el límite de
reintentos, un transporte desconocido y la cola vacía.

**Lo que falta.** Nada de lo anterior. La purga de datos por retención, del hito 7,
está hecha.

### Hito 6 — Cierre y panel de seguimiento (`53f90be`)

**Qué hay que construir.** El cierre de una promoción y la pantalla de
seguimiento del administrador: métricas, filtros, listados y auditoría.

**Lo que hay.**

- `app/Services/CierrePromocion.php` cierra una promoción en una transacción con
  `GET_LOCK` sobre la campaña, igual que el motor se bloquea a sí mismo para
  adjudicaciones. Es la misma defensa con otro objetivo: que dos cierres
  simultáneos no cuentan dos veces las unidades pendientes.
- Al cerrar, las unidades que estaban `programada` pasan a `no_entregada` con
  `UnidadPremio::noEntregarProgramadas()`, la promoción pasa a `finalizada` con su
  `cerrada_en`, y todo queda en una fila de `auditoria` con el recuento de antes y
  de después. **No hay adjudicación retroactiva**: un premio que nadie llegó a
  entregar no se le da a nadie a posteriori.
- Cerrar dos veces no es idempotente a propósito: la segunda llamada lanza
  `ErrorAplicacion` con un mensaje claro. El efecto sí lo es —no queda nada a
  medias— y la segunda llamada es un error de quien la hizo, no algo que deba
  tragarse en silencio.
- `app/Services/Seguimiento.php` agrega lo que la pantalla necesita: los cinco
  estados de unidad más las pendientes, participaciones válidas y sin premio,
  intentos rechazados por motivo, correos por estado, el tramo en curso, el
  historial de auditoría y los dos listados con filtro.
- Filtros por fecha, tramo y tipo de premio. Los nombres que no estén en la lista
  se ignoran y una fecha mal escrita se descarta: un filtro que llega a la
  consulta tiene que ser un filtro, no texto de la URL.
- `views/admin/seguimiento/panel.php` pinta todo eso, avisa de las diferencias
  entre el plan y el calendario, y ofrece el cierre con CSRF.
- `Auditoria` gana dos columnas propias, `filtros` y `filas_mostradas`, en vez de
  meterse en el JSON de `datos_despues`. La decisión D18 necesita poder consultar
  «quién miró qué lista y cuántas filas vio» con una consulta normal, no con
  funciones de MySQL sobre un documento.

**Lo que encontró el caso 14 al escribirse.** Dos cosas que ninguna revisión de
lectura habría visto.

La primera, `Autorizacion::usuarioId()` devuelve `0` cuando no hay sesión, y `0`
no puede existir en `usuarios`, cuya clave empieza en uno. Mirar el panel desde
consola —o desde una prueba— reventaba con un error de clave foránea en la
auditoría. Se normaliza en `Auditoria::registrar()`, en el modelo, y no en el
servicio que llama: cualquier llamante futuro hereda la corrección.

La segunda, y más silenciosa: la vista recibía sus datos bajo la clave `datos`, y
`Vista::renderizar()` llama a `extract($datos, EXTR_SKIP)` sobre un parámetro que
ya se llama `$datos`. `EXTR_SKIP` no pisa variables que existen, así que la clave
`datos` no llegaba nunca a la plantilla. La plantilla recibía el array equivocado
y avisaba de una clave que faltaba, en un sitio que no tiene nada que ver con la
clave `datos`. La variable se llama `$panel` a propósito, y está escrito por qué en
`ControladorSeguimiento`.

**Lo que encontró el caso 15 al escribirse.** El campo de fecha del panel salía
relleno con la fecha de hoy aunque no hubiera filtro. No era un error de cuentas,
era una mentira pequeña: la pantalla enseñaba la campaña entera con un filtro
aparente, y pulsar «Aplicar» sin querer vaciaba el listado. Ahora el campo se
pinta vacío y el filtro solo existe si alguien lo ha puesto.

**Dos avisos para quien siga.**

- `sql/schema.sql` trae ya las dos columnas de la auditoría y
  `sql/migraciones/0001_auditoria_filtros.sql` las añade. El instalador hace las dos
  cosas, en ese orden, así que **la migración tiene que ser idempotente**:
  `ADD COLUMN IF NOT EXISTS`. Sin eso, una instalación desde cero —que es cuando
  no hay datos que perder y cuando más fácil sería probar— peta con «columna
  duplicada». Que el esquema y las migraciones digan lo mismo no es redundancia,
  es el requisito para que las dos cosas funcionen.
- `borrarEscenarioDePanel()` borra en orden de dependencia, y ahora que los casos
  crean participaciones, `participaciones` e `intentos_rechazados` tienen que irse
  **antes** que `tramos`. Si no, el fallo aparece al final de un caso que en
  realidad había pasado todo, como un error de integridad que no señala el sitio
  donde está el problema.

**Cómo se comprueba.** El caso 14 cubre el cierre entero, con su auditoría y con
la pantalla. El caso 15 cubre el panel, los tres filtros, las fechas inválidas, la
auditoría de la D18, el campo de fecha vacío y el aviso de desajuste del
calendario. La suite de este hito son 16 casos y 371 comprobaciones.

### Hito 7 — Purga de datos por retención (`51fec02`)

**Qué hay que construir.** El trabajo de consola que vacía los datos personales de
las campañas cuyo plazo de retención ha vencido.

**Lo que hay.**

- `app/Services/Purgador.php` es el servicio. `purgar()` da una pasada por las
  campañas elegibles y `purgarCampana()` purga una sola, que es la que usa
  `--campana=7`.
- La regla de elegibilidad vive **una sola vez**, en
  `Promocion::campanasParaPurgar()`: cerrada, con `retencion_dias` no nulo, y
  `cerrada_en + INTERVAL retencion_dias DAY <= ahora`. La cuenta empieza en el
  cierre, no en la creación ni en la participación.
- `Promocion::exigirPurgaPermitida()` repite ese mismo filtro en SQL para el caso
  de una campaña concreta, y **rechaza con un error que dice el motivo**: dentro de
  plazo, sin plazo, o sin cerrar. Comprobar después de vaciar sería una puerta
  abierta: `--campana` equivocado borraría una campaña que aún está dentro de su
  plazo, y una purga no se puede deshacer.
- Se **vacían tres tablas**, no dos: `participaciones` (`datos` queda en `{}` por el
  `NOT NULL` y el `CHECK JSON_VALID`, `datos_normalizados` y `clave_unicidad` a
  `NULL`), `correos` (`destinatario` y `cuerpo` a cadena vacía, `variables` a
  `NULL`) e `intentos_rechazados` (`clave_identidad` a `NULL`). La tercera no tiene
  datos personales en el sentido estricto, pero guarda un HMAC de la identidad, y
  mientras la campaña lo conserve no es cierto que sus datos estén borrados.
- Las **filas no se borran**. Se quedan con lo que describe el sorteo —momento,
  tramo, resultado, transporte, estado, intentos, motivo del fallo— y pierden lo
  que identifica a una persona. Es la diferencia entre «no saber quién ganó» y «no
  saber que se entregó un premio».
- Los correos `pendiente` y `enviando` **no se purgan**. Vaciarlos dejaría al worker
  un mensaje sin cuerpo y sin destinatario, que saldría y se llevaría por delante el
  código de reclamación. La condición está en la consulta de `Correo::purgar()`, no
  en el servicio, para que no haya camino que la esquive.
- Todo en una transacción y con `bloquearPromocion()` primero, igual que el cierre.
  Un fallo a la mitad dejaría participaciones sin datos pero correos con el nombre
  dentro, y eso no se puede arreglar después.
- Un solo asiento de auditoría por campaña, con los tres recuentos en
  `datos_despues`. Veinte mil participaciones serían veinte mil asientos ilegibles.
- `intentos_rechazados` **no lleva `purgada_en`**: vaciarla es su propia marca
  (`clave_identidad IS NOT NULL`). Se paga con no poder decir qué día se purgó, y se
  acepta para no añadir una columna que solo serviría para eso.
- `bin/purgar_datos.php` **simula por defecto**. Hay que escribir `--real` para
  purgar de verdad, al revés de lo normal y a propósito: la operación es
  irreversible y el primer trabajo de las tres de la mañana debe enseñar lo que
  haría. Un argumento con valor por defecto copiado y pegado es el accidente más
  probable de toda esta parte.

**Lo que encontró el caso 16 al escribirse.** Al principio se comprobaba que
`purgarCampana()` devolvía `null` cuando no purgaba, y la prueba pasaba mientras el
código tiraba una excepción. Devolver `null` para una campaña pedida a mano era, de
hecho, la respuesta equivocada: quien pide purgar la campaña 7 no puede saber si ya
estaba purgada, si no tenía datos o si el guion está roto. Ahora lanza
`ErrorAplicacion` con el motivo, y el caso comprueba que el motivo sea el correcto.

**Dos avisos para quien siga.**

- El plazo se cuenta desde `cerrada_en`, y `retencion_dias` en `NULL` significa «no
  purgar nunca». Con cero días —lo que pondría el formulario si alguien deja el
  campo vacío— se borraría todo en cuanto la campaña se cerrara. El servicio de
  configuración trata el campo vacío como `NULL`, y esa es la línea que lo sostiene.
- Las columnas `purgada_en` de `participaciones` y `correos`, y sus índices, están
  en `sql/schema.sql` y en `sql/migraciones/0002_purga_datos.sql`. Por lo mismo que
  en el hito 6, **la migración tiene que ser idempotente**: `ADD COLUMN IF NOT
  EXISTS` y `CREATE INDEX IF NOT EXISTS` (MariaDB 10.4.32 lo acepta).

**Cómo se comprueba.** El caso 16 cubre los tres rechazos (dentro de plazo, sin
plazo, sin cerrar), la simulación sin escribir nada, el vaciado de las tres tablas,
lo que sobrevive, el correo pendiente intacto, un único asiento de auditoría y la
idempotencia. La suite son 17 casos y 415 comprobaciones.

### Hito 8 - Concurrencia por HTTP de verdad (`cb9b714`)

**La decisión que había que tomar antes de escribir nada.** D6 pide «dos peticiones
HTTP simultáneas contra Apache», y la suite tenía que seguir pasando en una máquina
sin servidor. El promotor eligió que **el caso se salte cuando no hay Apache**, y
que se diga en la salida: un caso que se salta en silencio es peor que uno que no
existe, así que `caso19()` imprime `[OMITIDO]` con el motivo y la cuenta como
correcta. Se puede apuntar a otro servidor con `SORTEOS_URL`.

**Por qué hizo falta tocar el núcleo.** Las pruebas no pueden llamar a
`usarBaseDePruebas()` desde una petición web —eso lo prohibe `Aplicacion`, y con
razón—, y sin base de pruebas el caso 19 habría estado adjudicando premios de
verdad. La salida es una variable de entorno, `SORTEOS_CONFIG`, que
`Aplicacion::config()` lee **al arrancar el proceso**: Apache la recibe por `SetEnv`
y elige el fichero antes de que haya conexión con la base. Con eso, la aplicación
sigue sin tener forma de cambiar de base desde una petición, pero el servidor se
arranca en la base de pruebas.

La variable se resuelve contra la raíz del proyecto y acepta ruta absoluta o
relativa. Si apunta a algo que no es un fichero, `ErrorConfiguracion` lo dice y
**no hay vuelta atrás**: es preferible un error ruidoso a una suite que parece
haber probado `sorteos_test` contra `sorteos`.

**Lo que hace el caso 19, en orden.**

- Dos azafatas reales, cada una con su login por HTTP y **su propia cookie**. No es
  un detalle: PHP serializa las peticiones de una misma sesión, así que dos
  peticiones con la misma cookie no se solaparían nunca y la prueba no probaría nada.
- La consola retiene `bloquearPromocion()` y **no responde a nada** hasta que ha
  visto dos conexiones esperando en `information_schema.PROCESSLIST` con estado
  `User lock`. Solo entonces suelta el cerrojo y lee las dos respuestas.
- Con eso ya se puede contestar la pregunta que el caso 7 no podía: que las dos
  peticiones estuvieran dentro **a la vez** y no encoladas.

**Las dos mutaciones que se hicieron para comprobar que la prueba muerde.** Una
prueba de concurrencia que no falla cuando quitas la concurrencia no está probando
la concurrencia, así que se comprobó las dos veces:

- **Sin cerrojo retenido**, la comprobación de solapamiento falla con cero
  esperas… y las otras 16 comprobaciones **siguen pasando**. El reparto de premios se
  cumple igual encolado que en paralelo: por eso la comprobación de solapamiento es
  la que vale, y las demás no sirven de prueba de nada.
- **Con una sola cookie para las dos peticiones**, el solapamiento vuelve a fallar y
  la segunda ni siquiera responde 200. Es el fallo que tendría el caso 19 sin dos
  sesiones, y existe.

**Avisos para quien siga.**

- El caso 19 toca el `.htaccess` de la raíz para añadir el `SetEnv`, y lo deja como
  estaba al terminar. Si el proceso muere a tiros (`taskkill`), la línea se queda
  puesta y **el sitio entero cae** hasta la siguiente ejecución, que la quita al
  empezar. Eso es intencionado: si el fichero de configuración temporal desapareciera
  sin quitar el `SetEnv`, Apache arrancaría contra la base de pruebas y una campaña
  real escribiría sus datos en `sorteos_test`. Un sitio que se cae es un sitio que
  avisa; uno que escribe donde no debe, no avisa nunca.
- La configuración temporal se escribe **sin BOM**. Con BOM, Apache responde con una
  página de error de 192 bytes y el caso falla con un 403 que no explica nada.
- **Si el caso 19 se cae, la limpieza tiene que cerrar los sockets antes de borrar.**
  Se comprobó con un fallo a propósito y sin eso pasaba: las dos peticiones seguían
  adjudicando mientras el `DELETE` iba borrando la campaña, y el borrado se comía la
  clave foránea de `fk_participaciones_tramo` con una participación que acababa de
  aparecer. El síntoma es un error de integridad en la limpieza, después de que el
  caso ya haya pasado todas sus comprobaciones, así que parece un fallo de otra cosa
  que no es la que hay que arreglar.
- El `.htaccess` del repositorio está en CRLF. Reescribirlo con LF no rompe Apache
  pero ensucia el estado del git con 121 líneas que nadie ha escrito, así que el
  helper reutiliza los finales de línea que encuentra.
- `tests/_http.php` empieza por `_` a propósito, y el verificador de documentación
  se salta los ficheros así. No es una excusa para no documentarlos: los tiene todos,
  con `@param`, `@return` y `@throws`.

**Cómo se comprueba.** El caso 19 son 18 comprobaciones. La suite son 20 casos y 487
comprobaciones, y el caso necesita Apache arrancado; sin él, 18 comprobaciones menos
y un `[OMITIDO]` en la salida.

### Hito 9 — Imágenes: validar, servir y no ejecutar

**El fallo que había debajo.** `Imagenes` llevaba el árbol sin una sola prueba, pero
escribir el caso 20 destapó un fallo real: **el `.htaccess` de la raíz tenía `uploads`
dentro de la lista de directorios prohibidos**, así que cualquier imagen que subiera
el panel se guardaba, se pintaba en un `<img>` y Apache respondía 403. El panel
aceptaba ficheros, el disco los guardaba y ninguna clienta los veía nunca. Nadie se
daba cuenta sin abrir la página con el servidor delante, que es exactamente lo que
hacen las pruebas por sockets.

La corrección está en los dos ficheros, y los dos hacen falta:

- `.htaccess` de la raíz: `uploads` sale de `(app|config|sql|tests|bin)/` y gana una
  regla propia, `RewriteRule ^uploads/ - [L]`, que corta antes del front controller.
- `uploads/.htaccess`: **el `SetHandler none` que el comentario prometía no existía**.
  Estaba descrito en el fichero, no escrito. Sin él, un `.php` subido a esa carpeta
  lo ejecutaba mod_php, y el `FilesMatch` de más abajo nunca llegaba a aplicarse.
  También se han añadido `Options -ExecCGI -Indexes -Includes`, que cortan CGI e
  `mod_include` sin depender de que el módulo que toque esté cargado.

**Lo que hace el caso 20, en orden.**

- **Rutas que no pueden existir.** Traversal, ruta absoluta de Windows y de Unix,
  contrabarra, byte nulo, `.svg`, `.php`, extensión en mayúsculas, y rutas que no
  llevan el prefijo que pone el servicio. La lista va primero por lo mismo que en el
  caso 18: si alguna pasa por un motivo equivocado, las demás no valen.
- **Ficheros que no son imágenes.** Vacío, un script disfrazado de PNG, y un PNG de
  verdad. La extensión se decide por el contenido con `finfo` y `getimagesize()`, y
  hay un PNG con nombre `.jpg` que se comprueba que se guarda como `.png`.
- **El ciclo completo.** `guardar()` mueve el origen —el temporal desaparece, no se
  copia—, la ruta devuelta lleva el prefijo de la configuración y es distinta cada
  vez, `sustituir()` borra la anterior y `borrarCampana()` no sale de `uploads`.
- **Apache, y solo con servidor.** La imagen guardada contesta 200 con un MIME de
  imagen, y un `.php` real plantado en esa carpeta con una marca dentro no se ejecuta
  y contesta 403. Se pide con POST a propósito: si Apache lo ejecutara, el método no
  cambiaría nada, pero mandarlo como POST deja claro que no se está comprobando solo
  que un GET no lo ejecuta. Sin servidor, esta parte se omite con `[OMITIDO]` y todo lo
  demás sigue igual.
- **Las tres pantallas.** La de resultado con premio y sin premio, y el formulario.
  Aquí salió el segundo fallo real, y era de la vista y no del servicio: **`banner_sup_ruta`
  y `banner_pie_ruta` se guardaban, se editaban en el panel y no se pintaban en ninguna
  parte.** Se podían subir los dos carteles, verlos en la vista previa de la
  apariencia y no aparecer nunca en la campaña. El caso 8 pide que lo configurado se
  vea en las tres pantallas, así que las dos vistas de participación los pintan ya,
  con su texto alternativo y con CSS en `estilos.css`.

**Las dos mutaciones que se hicieron para comprobar que la prueba muerde.**

- **Volviendo a meter `uploads` en la lista de la raíz**, el caso falla con 2
  comprobaciones: la imagen da 403 en lugar de 200 y no llega con MIME de imagen. Las
  otras 57 siguen pasando, porque la validación del servicio no depende de Apache.
- **Quitando el `SetHandler none`**, el caso falla también. Es la comprobación que
  importa más: es la que distingue «no se ejecuta» de «da error por casualidad».

**Avisos para quien siga.**

- `peticionWeb()` **exige barra inicial** en la ruta (`/uploads/...`), porque él
  concatena el prefijo del proyecto delante. Sin ella sale un 404 que parece un
  problema de Apache y no lo es: el fichero está ahí y se sirve con `file_get_contents`.
  Esta es la razón por la que `escribirPeticionWeb()` acepta ahora un método aparte:
  un POST sin cuerpo hay que poder pedirlo, y antes se deducía solo de si había campos.
- `Vista::renderizar()` **no siempre recibe `titulo`**, así que ninguna vista de
  participación puede usar `$titulo` sin el `??` de cortesía. Referenciarla a pelo
  rompe la pantalla entera a mitad del renderizado, y solo en el camino que llama a
  `renderizar()` en vez de `mostrar()`.
- La pantalla de resultado se pinta **sin insertar una participación**: se le pasa la
  forma de `$resultado` que devuelve el motor y ya está. Un `INSERT` directo en
  `participaciones` por pintar una pantalla llenaba la base de datos de filas falsas
  y, además, usaba columnas que el esquema no tiene.
- Las dos imágenes del `.htaccess` están en **CRLF**, y las dos se reescribieron a mano
  durante este hito. Si se tocan, conservar los finales de línea y comprobar `git status`.

**Cómo se comprueba.** El caso 20 son 59 comprobaciones. La suite son 21 casos y 546
comprobaciones, y la parte de Apache necesita el servidor; sin él, 9 comprobaciones
menos y un `[OMITIDO]` en la salida.

### Hito 10 — El calendario se revisa a mano (`cf29f14`)

**El fallo que había debajo.** `Calendario::crear()` y `Calendario::mover()` estaban
escritos, documentados y probados por su propio código desde el hito 3, y **no los
llamaba nadie**. No había ruta, ni acción de controlador, ni botón. El caso de
aceptación 2 del apartado 10 pide tres revisiones —añadir una unidad, cambiarle la
hora, retirar— y la pantalla del calendario solo tenía una, la de retirar. El
servicio estaba perfecto y era código muerto.

Lo segundo que salió al escribir las pruebas es que **el fallo real estaba en la
acción que ya existía**: `retirarUnidad()` guardaba el aviso «Unidad retirada»
después del `catch`, así que un administrador que intentaba retirar una unidad ya
entregada leía «Unidad retirada» encima del error que decía que no se podía. El
`return` que faltaba después de redirigir es de este hito.

**Lo que se ha conectado.**

- Dos rutas nuevas en `index.php`, con POST y token como las de retirar:
  `POST admin/promociones/{id}/calendario/unidad` y
  `POST admin/promociones/{id}/calendario/{unidad}/mover`.
- `crearUnidad()` y `moverUnidad()` en `ControladorCampana`, que no llevan reglas:
  cargan el tramo con `exigirTramoDeLaCampana()`, se lo pasan al servicio y, si el
  servicio dice que no, repintan la pantalla con el error al lado del campo y lo que
  se escribió. Los errores no son un aviso que desaparece al recargar.
- El formulario de añadir, con tramo y hora pero **sin fecha**: la fecha la pone el
  tramo, porque pedir las dos cosas abre la combinación imposible de un tramo del
  martes con la fecha del jueves.
- El formulario de mover por fila, en su propio formulario y no en el de retirar,
  con un desplegable de tramo, un campo de hora y confirmación.
- `.mover-unidad` en `estilos.css`, porque tres controles no caben en los 9 rem de
  `.columna-acciones`.

**El detalle que hay que tener presente.** El tramo se elige en un desplegable, así
que va **en el POST**, y se lee con `recibido()`. Las acciones antiguas de tramos
leen el suyo con `parametroId()`, que viene de la URL, porque allí el tramo es parte
de la ruta (`/tramos/{tramo}/borrar`). Leer un campo de formulario con `parametroId()`
funciona en el navegador y falla en la consola, o al revés; en este caso lanzaba un
404 en el navegador, porque en la ruta `/calendario/unidad` no hay `{tramo_id}`. El
caso 21 lo cazó a la primera, y por eso está aquí.

**Lo que el caso 21 comprueba (41 comprobaciones).**

- Las tres revisiones están conectadas: la pantalla ofrece el formulario de añadir,
  el botón se llama «Anadir unidad», y hay **exactamente un formulario de mover por
  cada unidad programada**, ni uno más ni uno menos. Es la comprobación que detecta el
  error en los dos sentidos, y en el que de verdad importa: un botón de mover en una
  fila ya entregada es un error que el usuario ve y que el servicio no puede evitar.
- La fecha la pone el tramo aunque el POST mande otra: se manda `2001-01-01` a
  propósito y se comprueba que la unidad cae en el día del tramo.
- El desplegable de premios no ofrece los desactivados, y vuelve a ofrecerlos al
  reactivarlos —las dos mitades, porque con una sola la comprobación podría pasar
  siempre.
- Ni una hora antes del tramo ni una después, ni un tramo o un premio de otra campaña,
  ni un premio desactivado, ni una unidad de otra campaña: en todos los casos se
  rechaza y no queda ninguna fila a medio escribir.
- Mover no entrega ni adjudica: la unidad sigue `programada` después de movida, y un
  movimiento que el servicio rechaza devuelve la fila **con la hora que se escribió**,
  no con la que tenía antes.
- Una unidad anulada ya no se puede mover, y su fila no ofrece ni el desplegable ni el
  botón de mover ni el de retirar.
- El fallo del `return`: retirar una unidad en `no_entregada` avisa del error y **no**
  dice «Unidad retirada» encima.

**Avisos para quien siga.**

- `retirar()` **admite a propósito** una unidad ya `anulada` y la vuelve a anular: es
  idempotente. La prueba del `return` se hace con `no_entregada`, que sí lanza.
- La fila del desplegable de premios se comprueba con una expresión regular sobre el
  `<select>`, no con el nombre del premio en la página entera: el nombre aparece
  legítimamente en la tabla de unidades de más abajo, y mirarlo ahí hacía pasar la
  prueba siempre.
- `crearEscenarioDePanel()` monta tramos que empiezan a las `1N:00:00` y acaban a las
  `23:00:00`, así que **una hora antes del tramo es `10:30`**, no medianoche. La
  primera versión del caso usaba esa hora creyendo que era válida.
- Desde la consola `Controlador::redirigir()` **no lanza `Redirigir`**: guarda un aviso
  y vuelve, porque `header()` ahí no hace nada útil. Los `try`/`catch` alrededor de
  `htmlDeAccion()` son código muerto en las pruebas de consola.

**Cómo se comprueba.** El caso 21 son 41 comprobaciones. La suite son 22 casos y 587
comprobaciones.

### Hito 11 — El calendario no se pasa del plan y cada revisión deja asiento (`bdb193d`)

**Lo que había debajo.** Quedaban dos decisiones de producto sin cerrar, y las dos
eran del mismo sitio: el calendario se podía revisar a mano —añadir, mover y retirar— y
esa revisión no tenía ni regla ni rastro. El aviso de «el calendario se ha separado
del plan» existía desde el hito 10, pero era informativo, y eso dejaba abierta la
dirección mala: **sobrar**. Faltar es legítimo, porque retirar una unidad a mano es una
decisión del administrador y el aviso sale precisamente cuando eso ha pasado; sobrar
no lo es nunca, porque una unidad de más no es un premio repartido, es un compromiso
que el plan no contiene y que alguien tendrá que cumplir en el mostrador. Y por otro
lado, las tres revisiones se hacían sin escribir nada en `auditoria`: la columna
`modificado_en` decía cuándo, no quién. Un plan y un calendario que no cuadran son una
avería, y una avería sin historial no tiene causa.

**Lo que se ha decidido.**

- El tope se comprueba al **añadir y al mover**, que son las dos formas de añadir. No
  al retirar, porque retirar es justamente lo que deja el hueco por el que esto se
  arregla. Es **por par de tramo y premio**, la misma unidad de cuenta que usa
  `AsignacionTramo::compararConCalendario()`, y contando solo las unidades no anuladas.
- Un par que el plan no reparte —sin fila en `asignaciones_tramo`, o con cantidad `0`—
  no admite unidades, porque si no el otro camino de pasarse del plan sería borrar la
  cantidad por debajo del calendario.
- Las cuatro revisiones —**añadir, mover, retirar y generar**— dejan un asiento cada
  una. Generar deja **uno solo** con el recuento, como la purga y el cierre, y no uno
  por unidad.

**Lo que se ha escrito.**

- `UnidadPremio::contarEnTramoYTipo()`, que cuenta las unidades vivas de un par y
  admite excluir una. La exclusión es lo que permite que mover una unidad dentro de su
  propio tramo funcione con el par lleno: sin ella, cambiar una unidad de las 12:10 a
  las 12:15 se rechazaría porque el par ya tiene dos de dos, cuando no ha añadido nada.
- En `Calendario`: `cuentasDelPlan()`, `mensajeDeExceso()`, `anotar()` e
  `ipDeLaPeticion()`, el tope en `crear()` y en `mover()`, y los cuatro asientos.
- `Auditoria::ACCION_RETIRADA` y `Auditoria::ACCION_GENERACION`. `ACCION_ALTA` y
  `ACCION_CONFIGURACION` ya existían desde el hito 1 sin que nada las usara, y eso es lo
  que estaba mal: una constante que promete una clase de cambio que no existe es una
  promesa que alguien va a cumplir sin saber que la cumple mal.
- `Auditoria::descripcionDe()` y una columna «Que se hizo» en el historial del panel. La
  columna guarda el nombre corto porque así se puede filtrar y agrupar, que es lo que
  exigía D18, pero el historial se lee a ojo: buscar «retirada» entre cuatrocientas
  filas de «adjudicacion» no es buscar, es pasar la hoja entera. Una acción que el
  código ya no conoce se devuelve tal cual, sin inventarle una explicación.
- `ControladorCampana` pasa `Autorizacion::usuarioId()` a las cuatro acciones.

**Los detalles que hay que tener presentes.**

- La unidad y su asiento van en la **misma transacción**, y por eso el movimiento que
  el motor no puede hacer —`UPDATE` de cero filas porque otra pantalla entregó la
  unidad entre la lectura y el `UPDATE`— lanza dentro de la transacción y **no** deja
  asiento. No se puede haber movido una unidad que ya no era programada.
- El asiento de **generar va fuera** de la transacción del reparto, y a propósito: si
  llegara a fallar, el calendario ya está escrito y perder el asiento por eso sería
  peor que perder el asiento por no haberlo escrito.
- **Generar sin problema no escribe** nada: un asiento de «se ha generado el
  calendario» acompañado de un informe de cero unidades sería una forma de mentir en la
  fila que más se lee. Generar y que se salte todos los tramos por tener unidades
  **sí** escribe, con `creadas: 0`, porque alguien ha pulsado algo.
- `retirar()` sobre una unidad ya anulada **también escribe asiento**. Retirar es
  idempotente a propósito —la pantalla no ofrece el botón, pero el servicio no puede
  depender de eso— y un asiento que dice «ya estaba anulada» responde a la pregunta que
  se le hace a un historial, que es si alguien ha tocado esto.
- Los números `plan` y `calendario` del asiento de un alta se **cuentan después** de
  insertar, no se reutilizan de la comprobación: lo que queda anotado es lo que hay, no
  lo que había cuando se comprobó que había hueco.

**El caso 21 ha tenido que reescribirse.** Añadía una novena unidad a un escenario
generado, que es exactamente lo que este hito prohíbe, así que las comprobaciones
tenían sentido con el contrato viejo. Ahora **retira una unidad primero**, comprueba
que se rechaza la unidad de más, que el hueco hace que la pantalla avise «Faltan 1», y
que al rellenar el hueco el aviso desaparece: el desajuste que se avisa es el que
existe, no el que se ha arreglado. La parte de mover pasa a moverse **dentro del mismo
tramo**, que es el movimiento que tiene que seguir funcionando con el par lleno.

**Lo que comprueba el caso 22 (46 comprobaciones).** Que generar ocho unidades deja un
asiento y no ocho, con el nombre y el identificador del usuario copiados en el momento
del cambio; que un plan que no cabe no genera ni deja asiento; que añadir a un par
completo se rechaza con los dos números en el mensaje y no crea nada; que un par que el
plan no reparte se rechaza con otro mensaje; que retirar abre el hueco, que añadir lo
rellena y que los dos dejan un asiento cada uno; que mover dentro del par funciona y
mover a un par lleno no; y que la pantalla cuenta la historia en palabras.

**Avisos para quien siga.**

- `capturarFalla()` es `comprobarFalla()` con una cosa más: **devuelve la excepción**.
  Hace falta cuando lo que se comprueba no es que la operación falle, sino **qué dice**
  el fallo. «No cabe en el plan» y «el tramo no existe» fallan los dos, y un mensaje
  equivocado en el campo equivocado es un fallo que `comprobarFalla()` declara bueno.
- Para provocar el «par que el plan no reparte» el caso 22 **borra la fila de
  `asignaciones_tramo`** por debajo del calendario, que es como se queda un par cuando
  se toca el plan desde la pantalla de cantidades. Para provocar el «plan que no cabe»
  sube la cantidad a 800 en un tramo de 720 minutos. Los dos son `UPDATE`/`DELETE`
  directos a propósito, igual que el `UPDATE tipos_premio SET activo = 0` del caso 21.
- `listarPorCampana()` **no trae `usuario_id`**, solo `usuario_nombre`. Para comprobar
  que la fila apunta a un usuario de verdad y no al cero que devuelve
  `Autorizacion::usuarioId()` sin sesión, hay que ir a la tabla.
- `caso13()` no hace `echo 'Caso 13: ...'` y por eso el resumen de la suite imprime un
  caso sin título. Es del hito 13, no de este, y no se ha tocado.

**Cómo se comprueba.** El caso 22 son 46 comprobaciones y el 21 son 51. La suite son
23 casos y 681 comprobaciones.
