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
| `verificar_docs.php` | `Todo correcto: 68 ficheros, sin problemas` |
| `tests\run.php` | `Todo correcto: 14 casos ejecutados, 294 comprobaciones` |
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

**Lo que no hay todavía.** La ruleta decorativa del mostrador (D19), que no hace
falta para que la campaña funcione y que se puede dejar para el final. Sigue sin
haber HTTP en la prueba de concurrencia, por lo que se dice en la sección 4.

**En la raíz hay un `bbdd.png` con un diagrama de la base de datos hecho a
mano.** Se versiona desde el hito 3, con la autorización del promotor, porque es
la referencia del esquema y sin ella hay que leer quince tablas para entender una
consulta. No borrarlo ni moverlo sin preguntar. Está versionado desde el hito 3,
con autorización del promotor, y así lo dice también la sección «Reglas que no hay
que romper».

### Por dónde continuar

Este es el resumen para retomar el trabajo. Si solo se lee una cosa de todo el
documento, que sea esto.

**Punto exacto en el que está.** Los hitos 0 a 7 están cerrados y subidos a
`origin/master`, y D4 está confirmada. No hay nada a medias: el árbol de trabajo
está limpio y las tres comprobaciones pasan. El commit del hito 7 es `51fec02`; el
que viene detrás solo apunta este documento a ese hash, como se hizo con los hitos
5 y 6.

**Lo siguiente, por este orden.**

1. **La ruleta de D19, si se quiere.** Decorativa, sin premios en los sectores.
2. Los hitos del apartado 10 de la especificación que aún no han empezado.

**Antes de escribir código nuevo, dos avisos.**

- La purga vacía correos en estado «pendiente» si alguien la llama mal, y eso
  significa que el worker manda un correo en blanco con el código de reclamación
  perdido. La condición está dentro de `Correo::purgar()`, no en el servicio, y
  por eso no hay ninguna forma de saltársela: si alguna vez hay que tocar esa
  consulta, hay que tocar también la prueba del caso 16 que comprueba que el
  pendiente sobrevive intacto.
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

**Lo que falta.** Nada de lo anterior. Queda la purga de datos por retención, que
es del hito 7, y la ruleta decorativa de D19, que no está hecha y no es
necesaria para que la campaña funcione.

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
