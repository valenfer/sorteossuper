# Sorteos de supermercado

Aplicación web para repartir premios de una promoción entre los clientes de un
supermercado, con un panel de administración y un mostrador para el personal de
tienda.

El proyecto se construye por hitos, con un commit por hito. **En este momento
está terminado el hito 1**: el esqueleto de la aplicación, el acceso con
roles, el instalador, la documentación y la suite de pruebas. Las pantallas de
gestión todavía son provisionales y lo dicen en pantalla, para que nadie las
tome por terminadas.

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

### Verificador de documentación

```
php bin\verificar_docs.php
```

Decisión D13. Comprueba que todas las clases
y métodos tienen PHPDoc, que las once etiquetas están en inglés, que la prosa no
contiene alfabetos fuera del castellano, que no hay llamadas a `exit` en la
aplicación web, que no quedan llamadas de depuración en el código, y que no hay
credenciales ni dependencias prohibidas. Sale con código 1 si encuentra algo, de
modo que sirve como paso de integración continua.

### Suite de pruebas

```
php tests\run.php                    # los cinco casos
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

La suite no necesita PHPUnit (decisión D6) y
funciona contra la base de pruebas, nunca contra la de la campaña. Escriben un
aviso en la salida de error al cambiar de base, y eso es intencionado: si
aparece fuera de `tests/run.php`, significa que alguien ha cambiado de base en
otro sitio y hay que investigar.

El caso 4 solo puede comprobar la rama de «no hay sesión»: el núcleo da por
hecho que en la consola no hay nadie, porque `esPeticionWeb()` mira `PHP_SAPI`.
La rama del rol equivocado necesita una petición web de verdad y se ha
comprobado a mano contra el servidor.

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
app/Models/             Modelos
views/                  Plantillas PHP
assets/                 CSS y JavaScript escritos a mano
sql/                    Esquema y migraciones
bin/                    Instalador y verificador
tests/run.php           Suite de pruebas
config/                 config.example.php versionado; config.php local
storage/logs/           Log de errores y log de acceso
uploads/                Imágenes subidas por el administrador
```

`app/`, `config/`, `sql/`, `tests/`, `bin/` y `uploads/` están bloqueados por el
`.htaccess` de la raíz. No hace falta tocar la configuración de Apache
(D5).

## El flujo del administrador

**Lo que hay hoy.** El administrador entra en `/login` con su cuenta y llega a
`/admin`. Puede cerrar sesión con el botón «Salir», que envía un formulario con
token CSRF: si se envía sin token, la respuesta es un 403 y la sesión sigue
abierta.

**Lo que se añadirá.** Configurar la promoción y su calendario de premios,
subir el logotipo, definir qué dato identifica a una persona, consultar
participaciones e intentos rechazados, y cerrar la promoción.

## El flujo de la azafata

**Lo que hay hoy.** La azafata entra en `/login` con su cuenta y llega a
`/azafata`. Su sesión se cierra sola tras 30 minutos de inactividad, porque la
tablet se queda encima del mostrador.

**Lo que se añadirá.** Registrar participaciones y ver el premio adjudicado en
el momento en que el servidor lo decide.

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
| D4 | **Cola entre días y cierre.** Los premios pendientes pasan al tramo y al día siguiente. Al cerrar la promoción, las unidades no entregadas pasan a `no_entregada`, sin adjudicación retroactiva. Ambas cosas quedan registradas en `auditoria`. |
| D5 | **Estructura del proyecto.** Front controller en `index.php` con URLs limpias mediante `.htaccess`, que además bloquea el acceso directo a los directorios internos. No requiere modificar la configuración de Apache. |
| D6 | **Estrategia de pruebas.** Runner propio en PHP CLI, sin Composer ni PHPUnit, con aserciones y contadores. El caso de concurrencia llega en los hitos del motor de adjudicación. |
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

**Pendiente.** El motor de adjudicación, el panel de administración, el
mostrador de la azafata, el envío de correo y los scripts de línea de comandos
para procesar la cola de mensajes y purgar datos. Las pantallas de destino son
provisionales y lo indican en pantalla.

**Cómo saber si está sano.** Con el servidor arrancado:

```
php bin\verificar_docs.php
php tests\run.php
php bin\instalar.php --diagnostico
```

Y entrando en `http://localhost/sorteos/login` con la cuenta de
administrador.
