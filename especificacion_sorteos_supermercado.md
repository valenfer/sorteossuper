# Especificación funcional para una aplicación de sorteos en supermercados

## Instrucciones para OpenCode

Desarrolla una aplicación web funcional para gestionar promociones de un supermercado en las que los clientes participan para ganar regalos. Usa esta especificación como fuente de requisitos. Implementa el flujo completo, no solo pantallas de ejemplo. Si una decisión figura como «supuesto de implementación», úsala como valor inicial configurable o déjala documentada; no la confundas con una regla confirmada por el promotor.

Entrega el código fuente, el esquema y las migraciones SQL, instrucciones de instalación y configuración, y una forma de probar localmente el recorrido completo. Mantén separadas la interfaz de administración y la interfaz que utilizará la azafata con los clientes.

## 1. Objetivo y alcance

El supermercado configura una promoción con días y horarios de participación, tipos de premios y cantidades. Cada unidad de premio recibe una hora programada. Durante la promoción, una azafata registra los datos de cada cliente, el sistema comprueba sus requisitos de participación y determina si recibe el primer premio pendiente cuya hora ya haya llegado. Muestra el resultado y envía un correo según corresponda.

Debe existir un panel de administración para preparar y gestionar el sorteo, y una interfaz visual para la participación presencial. La animación o ruleta de la interfaz es una representación del resultado calculado por el servidor: no debe decidirlo en el navegador.

## 2. Tecnología obligatoria

- Frontend: HTML5, CSS y JavaScript; se permite jQuery.
- Backend: PHP puro, sin frameworks.
- Base de datos: MySQL.
- No utilizar frameworks de frontend ni de backend.
- La aplicación debe poder instalarse en un entorno habitual de PHP y MySQL, con parámetros sensibles en configuración no publicada.
- Usar la zona horaria `Europe/Madrid` para interpretar las fechas y horas de la promoción. Guardar las fechas de forma inequívoca y mostrar siempre la hora local de la campaña.

## 3. Roles y acceso

### Pantalla de entrada

Mostrar un acceso para dos roles:

1. **Administrador:** inicia sesión y entra al dashboard de configuración y seguimiento.
2. **Azafata:** inicia sesión y entra en la interfaz de registro y participación de clientes.

Cada rol solo puede acceder a sus funciones. La comprobación del permiso debe hacerse también en el servidor. Las cuentas deben tener contraseñas almacenadas mediante hash seguro y sesión protegida.

## 4. Configuración de la promoción en el dashboard

### 4.1. Datos generales

Permitir crear y editar una promoción con un nombre interno, estado (borrador, activa, finalizada) y la información necesaria para identificarla. El administrador debe poder revisar la promoción antes de activarla. Estos campos de control se proponen para poder gestionar una o más promociones sin mezclar sus datos.

### 4.2. Días y jornadas de participación

El administrador indica cada fecha y sus tramos horarios. Un día puede tener varios tramos. Por ejemplo:

| Fecha | Inicio | Fin |
| --- | --- | --- |
| Lunes 15 | 10:00 | 14:00 |
| Lunes 15 | 16:00 | 21:00 |
| Martes 16 | 10:00 | 14:00 |
| Martes 16 | 16:00 | 21:00 |

Validar que cada tramo tenga inicio anterior al fin, que no se solape con otro de la misma promoción y que la fecha y la hora sean válidas. Solo se aceptan participaciones dentro de un tramo activo.

### 4.3. Catálogo de tipos de premio

El administrador crea tipos de premio con nombre, descripción e imagen opcional. Por ejemplo: microondas, auriculares, frigorífico y batidora. El catálogo define *qué* regalos existen; las cantidades se asignan a cada tramo en el siguiente paso. Permitir editar el catálogo sin alterar el historial de premios ya adjudicados.

### 4.4. Asignación de cantidades por tramo

Para cada tramo, seleccionar los tipos de premio y la cantidad de unidades de cada uno. Ejemplo ilustrativo para un día: 2 microondas, 10 auriculares, 1 frigorífico y 3 batidoras, distribuidos entre sus tramos según indique el administrador. Mostrar el total por tramo, por tipo y por promoción.

### 4.5. Generación automática del calendario de premios

Después de definir tramos y cantidades, generar una fila independiente por **cada unidad** de premio, con fecha, hora, tramo y tipo. Repartir las horas de manera aproximadamente equitativa dentro de cada tramo, evitando acumulaciones a la misma hora y, en lo posible, premios consecutivos del mismo tipo.

La distribución debe ser reproducible o explicable y editable: ninguna unidad puede desaparecer, duplicarse ni situarse fuera de su tramo por un fallo del generador. Si hay más unidades que minutos disponibles con precisión de minuto, informar del problema y permitir ajustar tramo o precisión, en vez de generar silenciosamente horas idénticas.

### 4.6. Revisión y edición del calendario

Mostrar una tabla de unidades de premio, ordenada cronológicamente, con al menos:

| Fecha | Hora | Tramo | Imagen | Premio | Estado | Acciones |
| --- | --- | --- | --- | --- | --- | --- |
| 15/03/2026 | 10:12 | 10:00–14:00 | Miniatura | Microondas | Programado | Editar / Borrar |
| 15/03/2026 | 12:00 | 10:00–14:00 | Miniatura | Frigorífico | Programado | Editar / Borrar |

Funciones requeridas:

- Editar fecha y hora de una unidad. Al cambiar de tramo, comprobar que el nuevo tramo existe y que la hora queda dentro de él.
- Botón **Añadir**: abrir un modal con fecha, hora y un desplegable de tipos de premio existentes; crear una nueva unidad.
- Botón **Borrar**: retirar una unidad programada previa confirmación.
- Botón **Actualizar/Guardar**: validar y persistir todos los cambios de forma consistente.
- Comparar las unidades del calendario con las cantidades inicialmente asignadas en cada tramo y tipo. Si difieren, mostrar las diferencias exactas y pedir confirmación explícita antes de guardar. Tras confirmar, sincronizar las cantidades previstas con el calendario definitivo para que ambos totales vuelvan a coincidir.
- No permitir borrar, reprogramar ni reasignar sin un procedimiento explícito una unidad que ya se haya entregado. Mostrar su estado y conservar el historial.

### 4.7. Requisitos de participación

El administrador puede configurar restricciones como:

- Una participación por persona en toda la promoción.
- Una participación por persona y día.
- Una participación por ticket.
- Una participación por DNI.
- Exigir un código de participación.

Definir qué reglas están activas y si se combinan. Aplicar las comprobaciones en el servidor antes de adjudicar un premio. Mostrar un mensaje claro cuando una participación no cumple las reglas, sin mostrar datos de otro participante.

**Supuesto de implementación:** cuando varias restricciones estén activas, se deben cumplir todas. Normalizar DNI, ticket y códigos para evitar duplicados por mayúsculas, espacios o formato. La mera introducción de un número de ticket o de un código no demuestra que sea auténtico: si no existe integración con la caja o una lista de códigos válidos, dejarlo explícito en el dashboard. No afirmar que el ticket está verificado si solo se ha comprobado que no se repite.

### 4.8. Campos del formulario de participación

El administrador elige qué campos se muestran y cuáles son obligatorios entre, como mínimo: nombre, DNI, teléfono, dirección, código postal, número de ticket, código de participación y correo electrónico. Permitir añadir otros campos de texto configurables si se necesita el «etc.» del documento original, con nombre, obligatoriedad y límites razonables.

Si se configuran correos automáticos al participante, exigir un correo electrónico válido en el formulario. Si alguna restricción usa DNI, ticket o código, exigir también ese campo. No pedir información que no forme parte de la configuración de la promoción.

### 4.9. Aspecto visual y mensajes

Permitir configurar desde el dashboard:

- Imagen del banner superior de la promoción.
- Imagen o banner del pie con información del supermercado.
- Colores de fondo, de campos y de bordes de los inputs, además de otros colores básicos para adaptar la interfaz a la marca.
- Imagen y texto para el resultado **con premio**.
- Imagen y texto para el resultado **sin premio**.
- Textos de los correos de ganador y no ganador, con variables controladas para nombre, promoción y premio cuando corresponda.

Previsualizar el aspecto para que el administrador vea cómo quedará antes de publicar. Validar tamaño y tipo de los archivos de imagen y evitar que se ejecuten como código.

## 5. Flujo de participación de la azafata

### Pantalla 1: registro

Mostrar el banner superior de la promoción; en el cuerpo, el formulario configurado por el administrador; y en el pie, el banner informativo del supermercado. La azafata introduce los datos del cliente y pulsa **Participar**. Validar campos, condiciones y horario activo. Impedir el doble envío accidental.

### Pantalla 2: animación

Mantener el banner y el pie. Mostrar una ruleta o animación vistosa que simule la selección. El servidor ya debe haber realizado la adjudicación de forma segura antes de presentar el resultado final. Una recarga o repetición de la petición no puede generar una segunda participación ni otro premio para el mismo intento.

### Pantalla 3: resultado

Mantener el banner y el pie. Si gana, mostrar felicitación, premio obtenido, su imagen y las instrucciones configuradas para reclamarlo. Si no gana, mostrar el mensaje e imagen correspondientes e invitarle a participar de nuevo **solo si las reglas de la promoción lo permiten**. Ofrecer una acción clara para comenzar con el siguiente cliente, sin dejar visibles los datos personales del anterior.

### Correo

- Ganador: enviar información del premio y lo necesario para reclamarlo.
- No ganador: enviar el mensaje configurado, respetando las reglas de participación.
- Guardar el estado del envío e informar de un fallo de correo sin alterar el resultado ya adjudicado. No adjudicar otra unidad al reintentar el correo.

## 6. Regla central de adjudicación

Cada unidad de premio tiene una fecha y una hora programadas. La hora indica **cuándo queda disponible para el siguiente participante válido**; no garantiza que se entregue exactamente a esa hora. Los premios cuya hora ya pasó y aún no se han entregado forman una cola ordenada por fecha y hora programadas. Si hay empate, usar un identificador estable para establecer el orden.

Al registrar una participación válida dentro de un tramo activo:

1. Buscar la primera unidad pendiente de la promoción cuya hora programada sea anterior o igual al instante de la participación.
2. Si existe, adjudicar **una sola unidad** a esa participación y marcarla como entregada; mostrar «con premio».
3. Si no existe, registrar la participación como «sin premio»; mostrar ese resultado.
4. Si hay varios premios pendientes, los restantes permanecen en cola y se asignan, por orden, a futuras participaciones válidas.

Ejemplo: premios previstos a las 10:12, 10:30 y 11:00; no participa nadie hasta las 11:20. La primera participación válida de las 11:20 recibe el de las 10:12, la siguiente el de las 10:30 y la tercera el de las 11:00. Un intento inválido no consume premios.

**Supuesto de implementación:** la cola de premios pendientes se mantiene a lo largo de los tramos y días de una misma promoción; fuera del horario activo no se admiten participaciones. Al finalizar la promoción, las unidades pendientes quedan registradas como no entregadas. Confirmar esta política antes de utilizar la aplicación en una campaña real.

La operación de validar la participación y adjudicar debe ejecutarse en una transacción de MySQL con bloqueo adecuado para que dos dispositivos simultáneos no reciban la misma unidad ni superen el límite de participación. El frontend nunca elige el premio. Guardar una referencia inmutable entre la participación ganadora y la unidad adjudicada y registrar fecha y hora reales.

## 7. Modelo de datos orientativo

Diseñar tablas relacionales, claves foráneas, índices y restricciones para cubrir, como mínimo:

- `usuarios`: cuenta, hash de contraseña, rol y estado.
- `promociones`: datos generales, zona horaria, estado, configuración visual y mensajes.
- `tramos`: promoción, fecha, inicio y fin.
- `tipos_premio`: promoción, nombre, descripción y ruta de imagen.
- `asignaciones_tramo`: tramo, tipo de premio y cantidad aprobada.
- `unidades_premio`: una fila por unidad; tramo, tipo, hora programada, estado y, si se adjudica, participación ganadora.
- `campos_formulario` y `reglas_participacion`: configuración por promoción.
- `participaciones`: promoción, tramo, momento, datos y resultado; índice para prevenir duplicados según las reglas configuradas.
- `correos`: tipo de mensaje, destinatario, estado, intentos y error resumido.
- `auditoria`: cambios relevantes del calendario, configuración y adjudicaciones, con usuario y fecha.

Evitar exponer DNI y demás datos personales en listados públicos o registros de errores. Diseñar la retención y eliminación de datos de la campaña como parámetros administrativos y limitar el acceso a sus datos.

## 8. Panel de seguimiento

Incluir una vista para el administrador con: promoción y tramo actual, unidades programadas, pendientes, entregadas y no entregadas; participaciones válidas e intentos rechazados; premios adjudicados con su hora real; estado de los correos; y las diferencias detectadas al modificar el calendario. Permitir filtrar por fecha, tramo y tipo de premio.

## 9. Validaciones y funcionamiento esperado

- El servidor valida todos los datos, aunque el navegador ya los haya validado.
- Las sesiones distinguen roles y bloquean rutas no autorizadas.
- Los formularios que modifican datos tienen protección contra CSRF; las salidas HTML se escapan y las consultas MySQL son parametrizadas.
- Los archivos subidos se limitan a formatos de imagen permitidos, tamaño máximo configurable y nombres generados por el servidor.
- Una participación nunca obtiene más de una unidad; una unidad nunca se adjudica dos veces.
- Un premio futuro no se entrega antes de su hora.
- Los premios pendientes anteriores tienen prioridad, aunque ya haya llegado la hora de premios posteriores.
- Las ediciones del calendario preservan las adjudicaciones existentes y registran quién hizo el cambio.
- Fallar al enviar un email no revierte la participación ni consume otro premio.
- La interfaz debe ser clara en una tablet o portátil del supermercado y usar mensajes legibles en español de España.

## 10. Casos de aceptación mínimos

1. Crear un día con dos tramos, varios tipos y cantidades; generar el calendario y comprobar que existe exactamente una fila por unidad prevista, dentro de su tramo.
2. Editar la hora de una unidad, añadir otra y borrar una; mostrar las diferencias con las cantidades originales y pedir confirmación antes de actualizar los totales.
3. Registrar un cliente antes de la hora del primer premio: resultado sin premio.
4. Registrar clientes cuando hay varias unidades vencidas: una unidad para cada participación, empezando por la más antigua.
5. Registrar una persona que infringe una regla activa: rechazarla sin consumir una unidad.
6. Simular dos participaciones simultáneas con una sola unidad disponible: solo una recibe el premio.
7. Repetir la petición de un mismo intento por doble clic o recarga: obtener el mismo resultado sin duplicar participación ni premio.
8. Configurar el formulario y las imágenes desde el dashboard; comprobar que aparecen en las tres pantallas.
9. Simular un fallo de envío de correo: conservar la adjudicación, registrar el error y permitir reintentar únicamente el mensaje.

## 11. Entregables solicitados al agente de código

1. Código completo y estructura del proyecto en PHP, HTML, CSS y JavaScript, sin frameworks.
2. SQL para crear la base de datos MySQL y, si procede, datos de demostración.
3. Configuración de ejemplo sin credenciales reales, e instrucciones para instalarlo y crear las cuentas iniciales.
4. Pruebas automatizadas o un procedimiento de prueba ejecutable para las reglas de adjudicación, duplicados, horarios y concurrencia.
5. README en español con la puesta en marcha, el flujo del administrador, el de la azafata y las decisiones tomadas para los puntos pendientes.

## 12. Decisiones del promotor pendientes de confirmar

Estas cuestiones no están resueltas de forma inequívoca en el Word. Mantener los supuestos indicados o hacerlas configurables; documentar la decisión aplicada.

**Estado de estas seis decisiones: todas resueltas y documentadas.** La respuesta a cada una está en el apartado 13, que las recoge ya como decisiones cerradas (D1 a D19) y no como supuestos pendientes. Se conservan aquí las seis preguntas originales por trazabilidad, con la respuesta ya incorporada:

1. ¿Los premios pendientes pasan al tramo o día siguiente de la misma promoción? Este documento propone que sí.
2. ¿Qué sucede con los premios que nunca se entregaron al terminar la promoción? Este documento propone conservarlos como «no entregados», sin adjudicación retroactiva.
3. ¿Cómo se comprueba que un número de ticket o código pertenece a una compra válida: lista precargada, integración con caja, revisión manual o simple control de duplicados?
4. ¿Qué condiciones exactas debe incluir el correo para reclamar un premio (código único, lugar, plazo, documento a presentar)?
5. ¿Qué restricciones de participación se combinan y qué campo identifica a «la misma persona» si no se solicita DNI?
6. ¿Se permite editar horarios o cantidades de una campaña ya iniciada? Si se permite, aplicar cambios solo a unidades no adjudicadas y dejar auditoría.

**Respuestas:** 1) Sí, la cola persiste entre tramos y días, y es configurable por promoción (D4). 2) Se conservan como «no entregados» en el estado `no_entregada`, sin adjudicación retroactiva, mediante una acción explícita de cierre (D4, D9). 3) Se implementa control de duplicados, con tabla opcional `codigos_validos` para listas precargadas; el panel declara de forma visible que el ticket no está verificado contra la caja (D3). 4) El correo incluye un código único de reclamación generado por unidad (`codigo_reclamacion`), y el plazo y el lugar son textos configurables (D1, D3). 5) La clave de identidad es un campo configurable cuya huella HMAC se indexa; si no hay DNI, se usa código, ticket, correo o teléfono según se configure (D3). 6) Sí se permite: los cambios solo afectan a unidades no adjudicadas y quedan en `auditoria` con usuario y fecha.

Prioriza un MVP funcional del recorrido completo y un comportamiento verificable de la cola de premios. No presentes como aleatorio un resultado que depende de horarios y del orden de participación.

## 13. Addendum: decisiones de implementación

Este apartado se añadió antes de escribir la primera línea de código y recoge las decisiones tomadas al revisar la especificación frente al entorno real de instalación. **Cada decisión tiene un identificador estable (D1 a D19) que se cita en el código, en los comentarios y en el README**, de modo que se pueda auditar en cualquier momento por qué una parte concreta está escrita de una forma y no de otra.

Las decisiones de los apartados 1 a 12 no se modifican: este addendum solo las concreta, y suprime ambigüedades detectadas. La revisión detectó las siguientes.

### 13.1. Decisiones cerradas

| # | Decisión | Valor aplicado |
| --- | --- | --- |
| D1 | Transporte de correo | Interfaz `Mailer` con dos transportes: `log`, que guarda el mensaje en la tabla `correos` y es el valor por defecto en local, y `smtp`, implementado en PHP puro con `openssl`. Los mensajes se encolan dentro de la transacción de adjudicación y se envían después, de modo que la latencia del correo nunca bloquea a la cliente y un fallo de envío nunca revierte la adjudicación. |
| D2 | Más unidades que minutos disponibles | El generador **aborta** y muestra un diagnóstico exacto (unidades que sobran, minutos libres, precisión actual) con tres salidas: ampliar el tramo, cambiar la precisión, o aceptar la coincidencia de horas de forma explícita. Nunca genera horas idénticas en silencio. |
| D3 | Identidad de la persona | Campo clave configurable por promoción (DNI, código, ticket, correo o teléfono). Se indexa una huella `HMAC-SHA256` con secreto de configuración, nunca el dato en claro. La misma huella lleva dentro su ámbito, de modo que un único índice único cubre a la vez la regla «una por campaña», «una por día», «una por ticket» y «una por código». Cada ganador recibe un `codigo_reclamacion` único. |
| D4 | Cola entre días y cierre | Los premios pendientes pasan al tramo y al día siguiente. Al cerrar la promoción, las unidades no entregadas pasan a `no_entregada` y no hay adjudicación retroactiva. Ambas cosas quedan registradas en `auditoria`. |
| D5 | Estructura del proyecto | Front controller en `index.php` con URLs limpias mediante `.htaccess`, que además bloquea el acceso directo a `app/`, `config/`, `sql/`, `tests/`, `bin/` y `uploads/`. No requiere modificar la configuración de Apache. |
| D6 | Estrategia de pruebas | Runner propio en PHP CLI, sin Composer ni PHPUnit, con aserciones y contadores. Incluye un caso de concurrencia que lanza dos peticiones HTTP simultáneas contra Apache. |
| D7 | Zona horaria | `date_default_timezone_set('Europe/Madrid')` en el arranque. Las fechas y horas se guardan en hora local de la campaña, de forma naive, y el instante actual se calcula en PHP con esa zona. **No se usa `CONVERT_TZ`**, que depende de las tablas de zona horaria de MySQL y no siempre están cargadas. |
| D8 | Control de concurrencia | Obligatorio en el motor real: `GET_LOCK('sorteo:{id}', 5)` para serializar la adjudicación de una promoción, `SELECT ... FOR UPDATE` sobre la fila de la unidad, y un `UPDATE ... WHERE estado = 'programada'` del que se comprueba el número de filas afectadas. Ese último es el que garantiza por sí solo que **una unidad nunca se adjudica dos veces**. |
| D9 | Modelo de datos | `unidades_premio` guarda `tramo_id` como referencia autoritativa y un único campo `inicio` (`DATETIME`). La fecha, la hora y la etiqueta del tramo se derivan al mostrar, de modo que no puede haber datos contradictorios. Faltan por tanto la acción de cierre, el estado `no_entregada`, y la validación de qué ocurre si se edita un tramo que ya tiene unidades dentro. |
| D10 | Separación de datos | `participaciones` contiene únicamente participaciones válidas y es la única tabla con índices únicos. Los intentos rechazados van a `intentos_rechazados`, sin índice único, para que un rechazo no bloquee a nadie. |
| D11 | Motor de base de datos | **MariaDB 10.4.32**, que es lo instalado en el entorno, no MySQL. Consecuencias aplicadas: `utf8mb4` con `utf8mb4_unicode_ci`; columnas indexadas de tipo `CHAR(36)` y `CHAR(64)` para no acercarse al límite de 3072 bytes de clave de InnoDB; el tipo `JSON` de MariaDB es un `LONGTEXT` con `CHECK json_valid`, por lo que el esquema lo declara de forma explícita. `SELECT ... FOR UPDATE SKIP LOCKED` **no existe** en 10.4, lo que confirma D8 como obligatorio y no como preferencia. |
| D12 | Instalador | `CREATE DATABASE IF NOT EXISTS` y `CREATE TABLE IF NOT EXISTS`. **Nunca `DROP`**, para que ejecutar el instalador dos veces no destruya una campaña en curso. La contraseña del administrador se genera al azar, se hashea y se imprime una sola vez por consola. |
| D13 | Documentación del código | Documentación exhaustiva: PHPDoc en toda clase y todo método, incluidos los triviales, con `@return void` explícito, y comentario en cada línea cuyo motivo no sea evidente. La prosa va en español de España; las once etiquetas PHPDoc van en inglés, porque phpDocumentor no reconoce etiquetas en español y generaría documentación sin parámetros ni tipos de retorno. Un verificador comprueba esto, además de buscar posibles credenciales en el código. |
| D14 | Cero dependencias | Ni Bootstrap, ni Tailwind, ni jQuery, ni Composer, ni jQuery local, ni ningún recurso externo. El CSS se escribe a mano usando *custom properties*, lo que además permite que el administrador configure los colores del apartado 4.9 sin pelearse con la escala de un framework. El autoloader de clases se escribe a mano con `spl_autoload_register`. El verificador falla si aparece cualquier URL externa, un `require` fuera del proyecto, o un `composer.json`. |
| D15 | Número de tiendas | Una promoción pertenece a un único supermercado, como supone el documento. No se crea la tabla `tiendas`; los datos del comercio (nombre, CIF, dirección) se guardan como campos de texto en `promociones` para el pie de página. |
| D16 | Orden de entrega de la documentación | Este addendum se escribe **antes** del código, de modo que la implementación sale conforme a lo acordado. |
| D17 | Control de versiones | Repositorio git inicializado en la raíz del proyecto, con un commit por hito y un `.gitignore` que excluye `config/config.php`, `uploads/` y cualquier clave. |
| D18 | Visibilidad de datos personales en el panel | El administrador ve los datos completos de participantes y adjudicados, porque sin ellos no puede entregar el premio ni resolver una reclamación, pero **cada acceso a esos datos se registra en `auditoria`**. |
| D19 | Animación de la pantalla 2 | Ruleta decorativa con los colores y el logotipo configurados en el panel, y sin nombres de premios en los sectores. Enseñar nombres sugeriría que el azar decide el resultado, y eso genera reclamaciones cuando el giro no coincide con el premio obtenido. |

### 13.2. Controles de seguridad añadidos

Se incorporan además, por decisión propia durante la revisión:

- **Transporte de correo propio.** La instalación de XAMPP de este entorno no tiene la extensión `mail()`, por lo que el envío se resuelve con SMTP en PHP puro o con la bandeja de salida local, según se configure.
- **Validación de imágenes sin GD.** La instalación no tiene GD ni Imagick, así que no se pueden redimensionar ni recodificar las imágenes. Se valida el tipo real con `finfo` y `getimagesize()`, se admite solo una lista blanca de extensiones y el directorio `uploads/` lleva su propio `.htaccess` para que Apache nunca ejecute esos ficheros.
- **Cero peticiones a CDN.** En un supermercado la conexión wireless falla con facilidad. La aplicación debe funcionar sin conexión a internet, y el verificador lo garantiza.
- **Protección de subida de ficheros.** Lista blanca de extensiones, tamaño máximo configurable, nombre de fichero generado por el servidor y comprobación de que la imagen es realmente una imagen.
- **Horas de verano.** Se documenta y se valida que ningún tramo cruce el cambio de hora de Europa/Madrid, que ocurre el último domingo de marzo y el último domingo de octubre, porque en ese día una hora de pared como las 02:30 es ambigua o no existe.
- **Prevención de fuerza bruta.** Límite de intentos en el acceso y cierre de sesión por inactividad en la tablet.
- **Instantánea de reglas.** Cada participación guarda una copia de las reglas que estaban activas en ese momento, para poder justificar meses después por qué se aceptó o se rechazó.

### 13.3. Cómo leer este addendum frente al documento original

Ante cualquier duda sobre una parte concreta del código, la secuencia de comprobación es:

1. El apartado correspondiente del documento original (1 a 12), que define **qué** hay que hacer.
2. Este apartado 13, que fija **cómo** se hace y por qué.
3. El comentario del método, que cita la decisión (D1 a D19) y el apartado del documento que implementa.

Si alguna vez se contradicen, prevalece este apartado, y la divergencia se corrige aquí.
