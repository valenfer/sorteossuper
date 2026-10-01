# Sorteos de supermercado

Aplicación web para repartir premios de una promoción entre los clientes de un
supermercado, con un panel de administración y un mostrador para el personal de
tienda.

El proyecto se construye por hitos, con un commit por hito. **En este momento
están terminados los hitos 1 y 2**: el esqueleto de la aplicación, el acceso con
roles, el instalador, la documentación, la suite de pruebas y el motor de
adjudicación de premios, que decide quién se lleva cada premio y garantiza que
ninguna unidad se reparte dos veces. Las pantallas de gestión todavía son
provisionales y lo dicen en pantalla, para que nadie las tome por terminadas.

**Para trabajar en el proyecto, empieza por [`ESTADO.md`](ESTADO.md)**: dice en
qué punto está, cómo comprobar que sigue sano, qué hito toca a continuación y
qué reglas hay que no romper.

## Índice

- [Requisitos](#requisitos)
- [Puesta en marcha](#puesta-en-marcha)
- [Comandos de consola](#comandos-de-consola)
- [Configuración](#configuración)
- [Estructura del proyecto](#estructura-del-proyecto)
- [El flujo del administrador](#el-flujo-del-administrador)
- [El flujo de la azafata](#el-flujo-de-la-azafata)
- [Seguridad](#seguridad)
- [Decisiones tomadas](#decisiones-tomadas)
- [Estado actual](#estado-actual)

## Requisitos

| Componente | Versión | Notas |
| --- | --- | --- |
| PHP | 8.0 o superior | Extensiones `pdo_mysql`, `mbstring`, `fileinfo` y `openssl`. |
| MariaDB | 10.4 o superior | La 10.4.32 es la del entorno de referencia. Decisión D11. |
| Apache | 2.4 | Con `mod_php` y `AllowOverride` que permita leer el `.htaccess` de la raíz. |

No hace falta nada más. No hay Composer, ni PHPUnit, ni jQuery, ni Bootstrap,
ni ninguna petición a una CDN: la aplicación funciona sin conexión a internet,
que es una condición necesaria en un supermercado. Decisión
D14.

## Puesta en marcha

### 1. Colocar el proyecto

Copiar la carpeta dentro del *htdocs* de Apache. A partir de aquí se asume que
está en `C:\xampp\htdocs\sorteos`, así que la URL de la aplicación será
`http://localhost/sorteos/`. Si se instala en otro sitio, todo lo de abajo sigue
funcionando: las rutas se resuelven con `Aplicacion::url()` y no hay ninguna
ruta absoluta escrita en el código.

### 2. Crear la configuración

```
php bin\instalar.php --crear-config
```

Esto escribe `config/config.php` con un secreto HMAC generado al azar. Ese
fichero **no se versiona**: está en el `.gitignore` porque contiene las
credenciales reales de la base de datos y el secreto.

### 3. Instalar la base de datos

```
php bin\instalar.php
```

El instalador crea la base y las 15 tablas si no existen, y no borra nada de lo
que ya haya. **Se puede ejecutar tantas veces como haga falta** sin miedo a
destruir una campaña en curso: usa `CREATE DATABASE IF NOT EXISTS` y
`CREATE TABLE IF NOT EXISTS`, y nunca `DROP`. Decisión
D12.

Al terminar imprime la contraseña de la cuenta de administrador, **una sola
vez**. Anotarla: no se vuelve a mostrar y no hay ninguna forma de recuperarla
desde la aplicación. Si se pierde, la única salida es regenerar el hash de la
cuenta con una consola MySQL.

### 4. Instalar también la base de datos de pruebas

```
php bin\instalar.php --test
```

Crea una segunda base, `<nombre>_test`, con el mismo esquema. Es la que usa la
suite de pruebas, y así las pruebas nunca tocan los datos de una campaña real.

### 5. Comprobar la instalación

```
php bin\instalar.php --diagnostico
```

Comprueba extensiones, configuración, conexión, motor y la disponibilidad de
`GET_LOCK`, que es la función de la que depende la adjudicación sin duplicados.
**No modifica absolutamente nada**: no crea la base si falta, no aplica el esquema y
no cambia el secreto, aunque se le pase `--forzar` junto. Se puede ejecutar
sobre una instalación en producción sin ningún riesgo.

### 6. Arrancar y entrar

Arrancar MySQL y Apache desde el panel de control de XAMPP y abrir
`http://localhost/sorteos/login`.

## Comandos de consola

### Instalador

```
php bin\instalar.php [--crear-config] [--forzar] [--test] [--diagnostico] [--ayuda]
```

| Opción | Qué hace |
| --- | --- |
| `--crear-config` | Crea `config/config.php` si no existe, con un secreto HMAC aleatorio. |
| `--forzar` | Regenera `config/config.php` aunque ya exista. **Cambia el secreto**, y con él invalida las huellas de las participaciones guardadas. Solo con `--crear-config`. |
| `--test` | Instala también la base de datos de pruebas. |
| `--diagnostico` | Comprueba el entorno. No cambia nada, nunca. |
| `--ayuda` | Muestra la ayuda. |

### Worker del correo

```
php bin\enviar_correos.php [--limite=N]
```

Procesa la cola de `correos`: reserva cada mensaje con un `UPDATE` condicional,
lo envía con el transporte configurado y marca el resultado. Un envío fallido no
tira el mensaje: lo devuelve a la cola con el error anotado y un contador de
intentos, y para de reintentarlo cuando llega al límite. Es idempotente:
ejecutarlo dos veces seguidas no manda dos veces lo mismo.

El fallo de correo **no** revierte la adjudicación. La clienta ya tiene su premio
asignado aunque el mensaje no llegue, y por eso el aviso al usuario y el reintento
del worker son dos cosas separadas. Con la cola vacía el comando no hace nada y
termina sin error, así que se puede poner en el planificador sin miedo.

### Purgador de datos personales

```
php bin\purgar_datos.php [--campana=ID] [--limite=N] [--real]
```

Vacía los datos personales de las campañas cerradas cuyo plazo de retención ya ha
vencido, contando los días **desde el cierre**, que es lo que se le explica a quien
pide sus datos. Una campaña con `retencion_dias` en `NULL` no se purga nunca, ni
aunque lleve cerrada años.

**Simula por defecto.** Hay que escribir `--real` para purgar de verdad. No es un
detalle: una purga no se puede deshacer, así que el primer trabajo de la noche debe
enseñar qué haría y el que borra tiene que haber escrito la palabra que lo pide.
`--campana=7` limita la pasada a una campaña, y avisa con el motivo si no se puede
purgar (dentro de plazo, sin plazo o sin cerrar).

Qué se vacía y qué no:

| Tabla | Se vacía | Se conserva |
| --- | --- | --- |
| `participaciones` | `datos` queda en `{}`, `datos_normalizados` y `clave_unicidad` a `NULL` | La fila entera: momento, tramo, resultado y su enlace al premio |
| `correos` | `destinatario` y `cuerpo` a vacío, `variables` a `NULL` | La fila: tipo, transporte, estado, intentos y motivo del fallo |
| `intentos_rechazados` | `clave_identidad` a `NULL` | La fila y su `motivo_codigo`, que es lo que se cuenta |

Las **filas no se borran**, porque sin ellas no se podría demostrar a quién se
entregó cada premio ni cuadraría el panel de seguimiento. Los correos `pendiente` y
`enviando` **no se purgan**: todavía pueden salir, y vaciarlos dejaría al worker un
mensaje en blanco con el código de reclamación perdido.

Cada campaña purgada deja **un** asiento de auditoría con los tres recuentos. Volver
a ejecutar el comando no hace nada: es idempotente, y está pensado para ir en cron
una vez al día (`30 3 * * *`).

### Verificador de documentación

```
php bin\verificar_docs.php
```

Decisión D13. Recorre `app/`, `bin/`, `tests/` y `views/`, y comprueba que todas
las clases y métodos tienen PHPDoc, que las once etiquetas están en inglés, que la
prosa no contiene alfabetos fuera del castellano, que no hay llamadas a `exit` en
la aplicación web, que no quedan llamadas de depuración en el código, y que no hay
credenciales ni dependencias prohibidas. Sale con código 1 si encuentra algo, de
modo que sirve como paso de integración continua.

### Suite de pruebas

```
php tests\run.php                    # los diecisiete casos
php tests\run.php --caso 0           # solo uno
php tests\run.php --caso=2 --verbose
php tests\run.php --ayuda
```

| Caso | Qué cubre |
| --- | --- |
| 0 | Arranque del núcleo, configuración, conexión y esquema completo en la base de pruebas. |
| 1 | Validadores: correo, teléfono, DNI, obligatorios, límites. |
| 2 | Escapado de salidas, JSON y token CSRF. |
| 3 | Motor de plantillas, incluida la maquetación anidada. |
| 4 | Control de acceso por rol. |
| 5 | Cola de premios: reparto por orden y por hora, y premios que no se entregan antes de su hora. |
| 6 | Rechazo sin consumir premio, idempotencia del intento y encolado del correo. |
| 7 | Dos participaciones simultáneas con una sola unidad, en procesos PHP separados. |
| 8 | El panel de punta a punta: campaña con tramo, premio, cantidades y los ocho campos obligatorios; sin calendario no se puede activar, y con él sí. |
| 9 | Las once pantallas del panel pintan su contenido y no se confunden entre sí. |
| 10 | Lo que el panel no deja hacer: un estado a mano, un tramo solapado, un tramo que no cabe, un tramo con unidades y una fila de formulario a medias. |
| 11 | Reglas de duplicado y su ámbito: una por campaña, por día, por ticket y por DNI, la combinación de dos reglas, el caso de no tener ninguna, y que la huella guardada sea la del ámbito que toca y no siempre la de campaña. |
| 12 | El envío de correo: encolado en la transacción, transporte `log` y `smtp` contra un servidor SMTP falso, un fallo que no revierte la adjudicación, el bloqueo de un mensaje ya enviado, el límite de reintentos y la cola vacía. |
| 13 | La pantalla de participación de punta a punta: el formulario y el POST con su token CSRF y su identificador de intento, el resultado, el rechazo con el texto de la campaña, la casilla de consentimiento y el código de reclamación según se mande correo o no. |

La suite no necesita PHPUnit (decisión D6) y
funciona contra la base de pruebas, nunca contra la de la campaña. Escriben un
aviso en la salida de error al cambiar de base, y eso es intencionado: si
aparece fuera de `tests/run.php`, significa que alguien ha cambiado de base en
otro sitio y hay que investigar.

El caso 4 solo puede comprobar la rama de «no hay sesión»: el núcleo da por
hecho que en la consola no hay nadie, porque `esPeticionWeb()` mira `PHP_SAPI`.
La rama del rol equivocado necesita una petición web de verdad y se ha
comprobado a mano contra el servidor.

El caso 7 es el único que lanza otros procesos. Cada uno repite la comprobación
de que la base de pruebas no se llama igual que la de la campaña, y ambos
escriben su resultado en un fichero aparte para que no se mezclen: es el único
sitio del proyecto donde una prueba podría tocar datos de una campaña real, y
por eso la protección está en los dos lados y no solo en el padre.

El caso 12 lanza un servidor SMTP propio en un proceso aparte, que acepta una
conexión, registra lo que recibe y se cierra. Un doble de transporte habría sido
más corto, pero no habría comprobado que lo que sale por el socket es SMTP de
verdad: lo que se quiere probar aquí es el cable, no la intención.

El caso 13 no comprueba el control de rol, y es a propósito. Lo aplica el
enrutador, no el controlador, y en la consola no hay sesión de navegador, así que
`Autorizacion` no ve a nadie y una prueba de rol ahí solo mediría que no hay
sesión. El rol se comprueba en el caso 4.

## Configuración

`config/config.php` es una lista de anulaciones sobre
`config/config.example.php`, que es el fichero versionado y documentado. Para
cambiar algo, se anula solo esa clave.

```php
<?php

$plantilla = require __DIR__ . '/config.example.php';

$local = [
    'bd' => [
        'nombre' => 'sorteos',
    ],
];

return array_replace_recursive($plantilla, $local);
```

| Bloque | Claves | Para qué |
| --- | --- | --- |
| `bd` | `host`, `puerto`, `nombre`, `nombre_pruebas`, `usuario`, `contrasena`, `charset`, `collation` | Conexión. `nombre_pruebas` la usa la suite. |
| `app` | `entorno`, `zona_horaria`, `depurar` | `entorno` es `local` o `produccion`. `depurar` solo enseña trazas en `local`: en una campaña real nunca, porque llevan rutas del servidor y datos de la petición. |
| `seguridad` | `secreto_hmac`, `intentos_maximos`, `minutos_bloqueo`, `longitud_minima_contrasena` | El secreto es la base de las huellas de identidad (D3). Los otros dos son el límite de intentos de acceso, 5 y 15 minutos por defecto. |
| `sesion` | `vida_minutos`, `inactividad_minutos`, `httponly_secure` | Vida de la cookie y cierre por inactividad en la tablet. |
| `correo` | `transporte`, `remitente`, `remitente_nombre`, `smtp.*` | Transporte `log` o `smtp` (D1). |
| `archivos` | `max_bytes`, `extensiones`, `tipos_mime`, `directorio`, `prefijo` | Subida de imágenes. |

## Estructura del proyecto

```
index.php               Front controller único y manejador de excepciones
app/inicio.php          Arranque previo al autocargador
app/Core/               Núcleo: configuración, sesiones, rutas, vistas, seguridad
app/Controllers/        Controladores
app/Models/             Modelos de las tablas
app/Services/           Servicios: el motor, la configuracion del panel y el generador de reparto
views/                  Plantillas PHP (admin/ es el panel de promociones)
assets/                 CSS y JavaScript escritos a mano
sql/                    Esquema y migraciones
bin/                    Instalador y verificador
tests/run.php           Suite de pruebas
tests/_escenario.php    Utilidades de prueba: escenarios y dobles de validador
tests/_proceso.php      Proceso hijo del caso de concurrencia
config/                 config.example.php versionado; config.php local
storage/logs/           Log de errores y log de acceso
uploads/                Imágenes subidas por el administrador
```

Los ficheros de prueba que empiezan por guion bajo están a mano y los salta el
verificador de documentación a propósito: son andamiaje, no código de la
aplicación, y el caso de concurrencia necesita su propio proceso porque con una
sola conexión no habría competencia que medir.

`app/`, `config/`, `sql/`, `tests/`, `bin/` y `uploads/` están bloqueados por el
`.htaccess` de la raíz. No hace falta tocar la configuración de Apache
(D5).

## El flujo del administrador

**Lo que hay hoy.** El administrador entra en `/login` con su cuenta y llega a
`/admin`, que es el listado de campañas. Desde ahí:

| Pantalla | Qué se configura en ella |
| --- | --- |
| `/admin/campanas/nueva` | Nombre, comercio, fechas y zona horaria. Una campaña nueva nace siempre en borrador. |
| `/admin/promociones/{id}` | La ficha: el resumen, lo que falta para poder abrirla, el botón de activar y el menú de todo lo demás. |
| `/admin/promociones/{id}/editar` | Los datos generales, que no cambian el estado. |
| `/admin/promociones/{id}/premios` | El catálogo de premios, con su foto. |
| `/admin/promociones/{id}/tramos` | Los tramos con su hora y su fecha, y cuántas unidades de cada premio van en cada uno. |
| `/admin/promociones/{id}/calendario` | Generar el reparto y ver el plan frente a lo generado. |
| `/admin/promociones/{id}/formulario` | Los campos que rellena la clienta, con su orden. |
| `/admin/promociones/{id}/reglas` | Una por persona, por ticket, códigos de acceso y códigos postales. |
| `/admin/promociones/{id}/ajustes` | Correo, modo simulación y días de retención. |
| `/admin/promociones/{id}/apariencia` | Colores, banners y los textos de resultado. |
| `/admin/promociones/{id}/seguimiento` | Cómo va la campaña en directo, con filtros, y el botón de cerrarla. |

**Ninguna pantalla necesita JavaScript.** En la del formulario, la última fila de
la tabla está siempre vacía: se añade un campo escribiendo su clave y su etiqueta
en ella y pulsando el mismo botón de guardar, y se quita dejando las dos en
blanco. Las imágenes se cambian con un `<input type="file">` de toda la vida.

**Lo que se añadirá.** Consultar participaciones e intentos rechazados, y cerrar
la promoción.

**El botón de activar solo aparece cuando se puede.** La ficha lista lo que
falta, y el aviso «el calendario no está generado» aparece aunque haya tramos y
cantidades: un tramo con cantidades no es un calendario, y abrir la campaña sin
unidades programadas sería abrirla sin nada que repartir.

## El flujo de la azafata

**Lo que hay hoy.** La azafata entra en `/login` con su cuenta y llega a
`/azafata`, el mostrador. Su sesión se cierra sola tras 30 minutos de inactividad,
porque la tablet se queda encima del mostrador.

Desde una campaña abierta, la ruta de participación es
`/azafata/promociones/{id}/participar`, y el administrador llega a la misma
pantalla por `/admin/promociones/{id}/participar`. La diferencia entre las dos
rutas es solo el sitio al que vuelve el botón de «participar con otra persona», y
el rol lo comprueba el enrutador.

| Pantalla | Qué hace |
| --- | --- |
| `…/participar` | Pinta el formulario con los campos visibles de la campaña, en su orden, con su obligatoriedad, y la casilla de consentimiento si la campaña la pide. |
| `…/participar` (POST) | Valida, adjudica en el servidor y pinta el resultado: premio, sin premio o rechazo con el motivo. |

Tres detalles de este flujo que no son evidentes:

- **El resultado se decide en el servidor.** La azafata no ve si ha ganado antes
  de que el motor responda, y el navegador no participa en la decisión.
- **La casilla de consentimiento se recoge aparte.** No es uno de los campos que
  configura el administrador, la pinta la propia vista, así que el controlador la
  pasa a las reglas a mano. Si se olvidara, una campaña que exige consentimiento
  rechazaría a todo el mundo, marcada la casilla o sin ella.
- **El código de reclamación se enseña según el correo.** Si la campaña manda el
  correo de premio, el código no aparece en pantalla, porque ya va a llegar por
  correo y ponerlo también ahí solo multiplica los sitios por los que se puede
  leer el de alguien. Si no lo manda, es la única vía que le queda a la clienta
  para recoger el premio, y se enseña.

Ningún dato de una persona se ve en una pantalla que no sea la suya. La
aplicación no lleva cuenta de «una participación por persona» comparando el
DNI en el navegador: se resuelve en el servidor, con una huella HMAC
(D3).

## Seguridad

- **Contraseñas** con `password_hash()` en bcrypt. El coste se sube si el
  servidor lo permite.
- **Enumeración de usuarios.** Un nombre inexistente se responde con el mismo
  mensaje y con un hash señuelo, para que el tiempo de respuesta no delate si la
  cuenta existe.
- **Límite de intentos.** Cinco intentos fallidos bloquean la cuenta quince
  minutos. El bloqueo se comprueba **antes** de validar la contraseña, y el
  nombre se normaliza a minúsculas antes de contar, porque la base de datos no
  distingue mayúsculas: si no, bastaría alternar `admin`, `Admin` y `ADMIN` para
  que el contador nunca llegara al límite.
- **CSRF.** Todas las peticiones que modifican datos llevan un token de un solo
  uso por sesión, incluido el cierre de sesión.
- **Separación de respuestas.** Un usuario sin sesión que abre una pantalla
  privada recibe un 303 a la pantalla de acceso: no hay nada que esconderle,
  porque no tiene forma de saber que la pantalla existe. En cambio, un usuario
  que sí ha entrado pero con el rol equivocado recibe un 404, no un 403, para no
  confirmar que esa ruta existe.
- **Datos personales.** El administrador ve los datos completos porque sin ellos
  no puede entregar el premio, pero **cada acceso queda registrado** en
  `auditoria` (D18).
- **Registro de intentos denegados** en `storage/logs/acceso.log`, aparte del
  log de errores del sistema.

**Limitación conocida.** El límite de intentos se guarda en la sesión del
navegador. Un atacante que borre las cookies empieza de cero, así que frena el
error de teclear la contraseña pero no un ataque automatizado con paciencia. La
mitigación que de verdad hace falta en un servidor real es un límite por
dirección IP en Apache, que no se ha añadido porque dependería de la
configuración del servidor y no del proyecto.

## Decisiones tomadas

Las decisiones están numeradas y son estables: se citan en el código, en los
comentarios y aquí, de modo que se pueda auditar por qué una parte concreta
está escrita de una forma y no de otra. El detalle completo está en el apartado
13 de `especificacion_sorteos_supermercado.md`.

| # | Decisión |
| --- | --- |
| D1 | **Transporte de correo.** Interfaz `Mailer` con dos transportes: `log`, que guarda el mensaje en la tabla `correos` y es el valor por defecto en local, y `smtp`, en PHP puro con `openssl`. Los mensajes se encolan dentro de la transacción de adjudicación y se envían después, para que la latencia del correo nunca bloquee a la clienta ni revierta la adjudicación. |
| D2 | **Más unidades que minutos disponibles.** El generador aborta y muestra cuántas unidades sobran y cuántos minutos libres hay, con tres salidas: ampliar el tramo, cambiar la precisión o aceptar la coincidencia de horas de forma explícita. Nunca genera horas idénticas en silencio. |
| D3 | **Identidad de la persona.** Campo clave configurable (DNI, código, ticket, correo o teléfono). Se indexa una huella HMAC-SHA256 con secreto de configuración, nunca el dato en claro. La huella lleva dentro su ámbito, así que un solo índice único cubre «una por campaña», «una por día», «una por ticket» y «una por código». |
| D4 | **Cola entre días y cierre. Confirmada.** Los premios pendientes **no** se reubican: se quedan en la cola global de la promoción y salen por orden de `inicio`, aunque el tramo y el día hayan cambiado. Un premio del lunes sin reclamar sale antes que cualquiera del martes, y ni su `inicio` ni su `tramo_id` se reescriben, de modo que el panel sigue informando del retraso real. Al cerrar la promoción, las unidades no entregadas pasan a `no_entregada`, sin adjudicación retroactiva, y ambas cosas quedan registradas en `auditoria`. La tabla de decisiones de la especificación dice lo contrario; manda el cuerpo, y el motivo está en `ESTADO.md`. |
| D5 | **Estructura del proyecto.** Front controller en `index.php` con URLs limpias mediante `.htaccess`, que además bloquea el acceso directo a los directorios internos. No requiere modificar la configuración de Apache. |
| D6 | **Estrategia de pruebas.** Runner propio en PHP CLI, sin Composer ni PHPUnit, con aserciones y contadores. El caso de concurrencia llega en los hitos del motor de adjudicación. **Desviación en el hito 2:** el caso de concurrencia lanza dos procesos PHP de consola con conexiones propias, no dos peticiones HTTP contra Apache, porque hasta el hito 4 no existe ninguna ruta que llame al motor. Lo que se mide, que es la propiedad que importa, es la de la base de datos: dos conexiones compitiendo por la misma unidad. La forma HTTP se añade en el hito 4, cuando la pantalla de participación exista, y solo aporta la capa del servidor web por delante. |
| D7 | **Zona horaria.** `date_default_timezone_set('Europe/Madrid')` en el arranque, fechas naive en hora local de campaña. No se usa `CONVERT_TZ`, que depende de las tablas de zona horaria de MySQL y no siempre están cargadas. |
| D8 | **Control de concurrencia.** `GET_LOCK` para serializar la adjudicación de una promoción, `SELECT ... FOR UPDATE` sobre la fila de la unidad y un `UPDATE ... WHERE estado = 'programada'` del que se comprueba el número de filas afectadas. Ese último es el que garantiza por sí solo que una unidad nunca se adjudica dos veces. |
| D9 | **Modelo de datos.** `unidades_premio` guarda `tramo_id` como referencia autoritativa y un único campo `inicio`. La fecha, la hora y la etiqueta del tramo se derivan al mostrar, de modo que no puede haber datos contradictorios. |
| D10 | **Separación de datos.** `participaciones` contiene solo participaciones válidas y es la única tabla con índices únicos. Los intentos rechazados van a `intentos_rechazados`, sin índice único, para que un rechazo no bloquee a nadie. |
| D11 | **Motor de base de datos.** MariaDB 10.4.32, que es lo instalado. Consecuencias: `utf8mb4` con `utf8mb4_unicode_ci`; columnas indexadas de tipo `CHAR(36)` y `CHAR(64)` para no acercarse al límite de 3072 bytes de clave de InnoDB; el tipo `JSON` se declara como `LONGTEXT` con `CHECK json_valid`. `FOR UPDATE SKIP LOCKED` no existe en 10.4, lo que confirma D8 como obligatorio. |
| D12 | **Instalador.** `CREATE DATABASE IF NOT EXISTS` y `CREATE TABLE IF NOT EXISTS`, nunca `DROP`. La contraseña del administrador se genera al azar, se hashea y se imprime una sola vez. |
| D13 | **Documentación del código.** PHPDoc en toda clase y todo método, incluidas las líneas triviales, con las once etiquetas en inglés y la prosa en castellano de España, porque phpDocumentor no reconoce etiquetas en español. Un verificador lo comprueba. |
| D14 | **Cero dependencias.** Ni Bootstrap, ni Tailwind, ni jQuery, ni Composer, ni recursos externos. El CSS usa *custom properties*, lo que permite configurar los colores desde el panel. El autocargador está escrito a mano. |
| D15 | **Número de tiendas.** Una promoción pertenece a un único supermercado. No se crea la tabla `tiendas`; los datos del comercio son campos de texto en `promociones`. |
| D16 | **Orden de entrega de la documentación.** El addendum se escribió antes que el código, para que la implementación saliera conforme a lo acordado. |
| D17 | **Control de versiones.** Repositorio git en la raíz, un commit por hito y un `.gitignore` que excluye `config/config.php`, `uploads/` y cualquier clave. |
| D18 | **Visibilidad de datos personales.** El administrador ve los datos completos porque no puede hacer su trabajo sin ellos, pero cada acceso se registra en `auditoria`. |
| D19 | **Animación de la pantalla del mostrador.** Ruleta decorativa con los colores y el logotipo configurados, y sin nombres de premios en los sectores: enseñarlos sugeriría que el azar decide el resultado, y eso genera reclamaciones cuando el giro no coincide con el premio obtenido. |

Además se añadieron, por decisión propia durante la revisión: transporte de
correo propio (la instalación no tiene `mail()`), validación de imágenes sin GD
con `finfo` y `getimagesize()`, cero peticiones a CDN, lista blanca de
extensiones y nombre de fichero generado por el servidor, validación de que
ningún tramo cruce el cambio de hora de verano, límite de intentos de acceso y
cierre de sesión por inactividad, y una copia de las reglas en cada
participación para poder justificar meses después por qué se aceptó o se
rechazó.

## Estado actual

**Terminado (hito 1).** Núcleo de la aplicación, autocargador, configuración,
sesiones, enrutador con URLs limpias, vistas y escapado, acceso con dos roles y
protección contra fuerza bruta, CSRF, autorización por rol, instalador
idempotente y con diagnóstico de solo lectura, verificador de documentación,
suite de pruebas y esta documentación.

**Terminado (hito 2).** El motor de adjudicación: la cola de premios y su orden
por fecha y hora, la transacción con bloqueo de promoción, la comprobación del
número de filas afectadas que hace imposible adjudicar dos veces la misma
unidad, la idempotencia del intento para que un doble clic no duplique nada, el
rechazo de los intentos que incumplen una regla sin consumir premio, y el
encolado del correo de premio y de «no ha salido premio» dentro de la misma
transacción.

**Terminado (hito 3).** El panel de promociones: las once pantallas de
administración, con sus rutas y su control de rol, y el servicio que valida todo
lo que se guarda en ellas. Los datos generales, el catálogo de premios, los
campos del formulario, las reglas de participación, los ajustes, la apariencia, los
tramos con sus cantidades y la generación del calendario de premios con su
diagnóstico previo. La ficha de la campaña resume si se puede abrir y por qué no.
Ninguna pantalla necesita JavaScript.

**Terminado (hito 5).** Las pantallas de participación y de resultado, las reglas
de campaña como validador real, y el envío de correo.

- **Reglas.** `ReglasCampana` implementa una por campaña, por día, por ticket y
  por DNI, la verificación de tickets contra lista, el consentimiento y los
  códigos de acceso, con el texto de rechazo que haya escrito la campaña. El
  ámbito de la huella lo elige `IdentidadCampana`, el mismo sitio que usa la
  pantalla, para que no puedan separarse. La huella guardada lleva el ámbito de la
  regla más restrictiva, no siempre el de campaña: con eso, «una por día» no
  rechaza a la misma persona para siempre, solo el primer día.
- **Correo.** `Mailer` con transporte `log` (el de por defecto, que no sale del
  servidor) y `smtp` en PHP puro, con EHLO, STARTTLS, AUTH LOGIN y TLS
  opportunistic. `ProcesadorCorreo` reserva cada mensaje con un `UPDATE`
  condicional antes de mandarlo, para que dos workers a la vez no envíen el
  mismo correo dos veces, y `bin\enviar_correos.php` lo ejecuta.
- **El fallo de correo no revierte la adjudicación.** Un envío fallido devuelve
  el mensaje a la cola con su error y reintenta hasta un límite. La clienta ya
  tiene el premio adjudicado aunque el mensaje no llegue.

**Terminado (hito 6).** El cierre de campaña y el panel de seguimiento.

- **El cierre.** `CierrePromocion` cierra una campaña dentro de una transacción y
  con `GET_LOCK` sobre esa campaña, que es la misma defensa que usa el motor para
  que dos adjudicaciones simultáneas no repartan el mismo premio. Las unidades
  que estaban programadas y nadie llegó a recoger pasan a `no_entregada`; no se
  adjudican a posteriori a nadie. Todo queda anotado en `auditoria` con el recuento
  de antes y el de después.
- **El panel.** `Seguimiento` agrega lo que la pantalla enseña: los estados de las
  unidades, las participaciones válidas, los intentos rechazados por motivo, los
  correos, el tramo en curso, el historial de auditoría y las diferencias entre el
  plan y el calendario. Filtra por fecha, tramo y tipo de premio, y cada visita
  queda anotada con qué filtros se usó y cuántas filas se vieron, como exige D18.

**Terminado (hito 7).** El trabajo de consola que purga los datos personales.

- **La regla de elegibilidad vive en un solo sitio.** `Promocion::campanasParaPurgar()`
  decide qué campañas se pueden purgar —cerradas, con plazo y con el plazo vencido
  contando desde el cierre— y esa misma condición se repite en SQL para el caso de
  una campaña concreta, que rechaza con un mensaje que dice el motivo. Comprobar el
  plazo *después* de vaciar sería una puerta abierta: `--campana` equivocado borraría
  una campaña que aún está dentro de su plazo, y eso no tiene vuelta atrás.
- **Vacía, no borra.** Se vacían `participaciones`, `correos` e
  `intentos_rechazados`, y las filas se quedan con todo lo que describe el sorteo.
  Se conservan el motivo de un rechazo y el error de un envío fallido, porque sin
  ellos la auditoría y el panel dejan de cuadrar.
- **No se purgan los correos pendientes.** Vaciar un mensaje que todavía puede
  dejaría al worker enviando un correo en blanco y perdería el código de reclamación.
- **Un asiento de auditoría por campaña**, con los tres recuentos. Es idempotente y
  va pensado para cron.

**D19 está confirmada y ya no está pendiente.** La ruleta decorativa va dentro de la
misma pantalla que el resultado, no en una página aparte, y no hay nada pendiente que
se sepa. Los sectores son solo color, sin nombres de premios: el nombre del comercio
va en el centro. Y el resultado no depende de la ruleta: el servidor ya adjudicó, el
texto va escrito en el HTML desde el principio y lo único que lo tapa durante el giro
es el CSS. Sin CSS, o con las animaciones desactivadas, se ve igual; por eso no hay
ningún temporizador en JavaScript. Un rechazo no hace girar la ruleta.

**D4 está confirmada y ya no está pendiente.** La cola de premios se mantiene a lo
largo de los tramos y de los días, y el cierre no reubica las unidades no
entregadas: las pasa a `no_entregada` y anota el recuento. No queda ningún método
«para mover premios» sin usar; el que había estaba vacío y se ha eliminado, porque
su nombre describía justo lo que D4 decide no hacer.

**Cómo saber si está sano.** Con el servidor arrancado:

```
php bin\verificar_docs.php
php tests\run.php
php bin\instalar.php --diagnostico
```

Y entrando en `http://localhost/sorteos/login` con la cuenta de
administrador.
