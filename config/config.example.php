<?php

/**
 * Configuracion de ejemplo de la aplicacion de sorteos.
 *
 * Este fichero SI se versiona en el repositorio y NO contiene credenciales
 * reales. Para instalar la aplicacion hay que copiarlo como config.php y
 * ajustar los valores:
 *
 *     copy config\config.example.php config\config.php
 *
 * o, preferiblemente, dejar que lo haga el instalador:
 *
 *     php bin\instalar.php --crear-config
 *
 * El fichero config.php resultante esta en .gitignore y no debe subirse nunca
 * a un repositorio ni copiarse a un servidor compartido.
 *
 * Todas las claves usan snake_case en espanol para ser coherentes con los
 * nombres de tabla y de columna del esquema SQL, que tambien van en espanol.
 *
 * @see \App\Core\Aplicacion::config()
 * @see seccion 13 del documento de especificacion, decisiones D1 a D19
 */

declare(strict_types=1);

return [

    // =========================================================================
    // Base de datos (D11)
    // =========================================================================
    // El motor de este proyecto es MariaDB 10.4, que es lo instalado en el
    // entorno de trabajo. La configuracion se mantiene deliberadamente
    // compatible con MySQL 5.7 en adelante, de modo que la aplicacion tambien
    // funciona en un servidor MySQL si alguna vez hace falta.
    //
    // En MariaDB 10.4 el tipo JSON es un alias de LONGTEXT con una restriccion
    // CHECK json_valid, por eso el esquema lo declara de forma explicita.
    'bd' => [
        // Usar '127.0.0.1' y no 'localhost': con 'localhost' el cliente de
        // MariaDB intenta la conexion por socket named pipe, que en Windows
        // falla o se cuelga de forma confusa. Con la direccion IP se usa TCP.
        'host'      => '127.0.0.1',

        // Puerto de MySQL/MariaDB. 3306 es el de XAMPP.
        'puerto'    => 3306,

        // Nombre de la base de datos de la aplicacion. Debe existir o crearse
        // con el instalador, que la crea si falta y nunca la borra (D12).
        'nombre'    => 'sorteos',

        // Nombre de la base de datos usada por la suite de pruebas. El runner
        // tests/run.php trabaja sobre esta base y NUNCA sobre la anterior, de
        // modo que ejecutar las pruebas no puede destruir una campana real.
        'nombre_pruebas' => 'sorteos_test',

        // Credenciales. En la instalacion local de XAMPP el usuario root no
        // tiene contrasena, que es el valor por defecto de este ejemplo.
        // ESTOS VALORES SON DE EJEMPLO: sustituyelos antes de usar la
        // aplicacion en una campana real.
        'usuario'   => 'root',
        'contrasena'=> '',

        // utf8mb4 es la unica opcion que representa todos los caracteres, y es
        // necesaria para la enye, las tildes y los simbolos de euro en los
        // nombres de los premios. utf8 de MySQL no los admitiria.
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],

    // =========================================================================
    // Aplicacion
    // =========================================================================
    'app' => [

        // Nombre interno de la aplicacion. Aparece en el titulo del navegador
        // y en los correos.
        'nombre' => 'Sorteos de supermercado',

        // Entorno de ejecucion. Valores admitidos: 'local' y 'produccion'.
        //
        // En 'local' los errores se muestran por pantalla, para poder
        // diagnosticar durante el desarrollo. En 'produccion' nunca se
        // muestra un error al usuario: se registra en el log y se muestra un
        // mensaje generico. Esta distincion es la que evita que un cliente de
        // un supermercado vea una traza con rutas del servidor.
        //
        // Dato relevante para este proyecto: el mensaje de error NUNCA
        // incluye la cadena de conexion ni la contrasena, ni siquiera en
        // 'local'. Ver \App\Core\ErrorBaseDeDatos.
        'entorno' => 'local',

        // Zona horaria de TODA la aplicacion (D7).
        //
        // Se fija aqui de forma explicita y no se hereda de php.ini porque la
        // instalacion de XAMPP de este entorno tiene 'Europe/Berlin', que es
        // una hora mas en invierno y dos en verano respecto a Madrid. Ese
        // desfase moveria los horarios de los tramos y, con ello, el resultado
        // de la adjudicacion.
        //
        // Todas las fechas y horas de la campana se guardan de forma naive, en
        // esta misma zona, y el instante actual se calcula tambien aqui. No se
        // usa la funcion CONVERT_TZ de MySQL, que depende de que las tablas de
        // zona horaria esten cargadas y no siempre lo estan.
        'zona_horaria' => 'Europe/Madrid',

        // Modo depuracion. Cuando esta a false, no se muestra informacion
        // interna por pantalla en ningun caso, ni en 'local'.
        'depurar' => true,
    ],

    // =========================================================================
    // Seguridad (D3, D12, D13)
    // =========================================================================
    'seguridad' => [

        // Secreto de las huellas HMAC-SHA256.
        //
        // Se usa para convertir el dato identificativo de una persona (DNI,
        // ticket, codigo, correo o telefono) en una huella que se puede
        // indexar y comparar en la base de datos sin guardar el dato en claro
        // para esa finalidad.
        //
        // VALOR DE EJEMPLO. El instalador genera uno aleatorio de 64
        // caracteres y lo escribe en config/config.php. Si este valor se
        // cambia despues de haber recogido participaciones, las huellas
        // antigas dejaran de coincidir con las nuevas y las reglas de «una
        // participacion por persona» empezaran a fallar de forma silenciosa.
        'secreto_hmac' => 'CAMBIAR-ESTE-VALOR-POR-UNO-ALEATORIO-DE-64-CARACTERES',

        // Numero de intentos de acceso fallidos antes de bloquear la cuenta
        // de forma temporal. Es la proteccion basica contra fuerza bruta de
        // la pantalla de acceso, que esta expuesta en la red del supermercado.
        'intentos_maximos' => 5,

        // Minutos de bloqueo tras superar el limite de intentos.
        'minutos_bloqueo' => 15,

        // Longitud minima de las contrasenas de las cuentas de la aplicacion.
        'longitud_minima_contrasena' => 12,
    ],

    // =========================================================================
    // Sesion (apartado 3 de la especificacion)
    // =========================================================================
    'sesion' => [

        // Nombre de la cookie de sesion. Se elige un nombre propio para que la
        // aplicacion conviva con las demas que comparten el dominio localhost
        // de XAMPP, como /huerto o /phpMyAdmin.
        'nombre' => 'SORTEOSSID',

        // Minutos de duracion total de la sesion.
        'vida_minutos' => 240,

        // Minutos de inactividad maxima. La tablet de la azafata puede
        // quedarse encendida y desatendida entre clientes, asi que se cierra
        // la sesion por inactividad para no dejar datos de una persona
        // visibles en la pantalla.
        'inactividad_minutos' => 30,

        // Marca la cookie de sesion como exclusiva de https.
        //
        // Se deja en false porque en el supermercado la aplicacion se sirve
        // por la red local sin TLS, y activar esta opcion haria que la cookie
        // no se enviara nunca y nadie pudiera entrar. En una instalacion
        // publica con https, hay que ponerla en true.
        'httponly_secure' => false,
    ],

    // =========================================================================
    // Correo (D1)
    // =========================================================================
    // La instalacion de XAMPP de este entorno NO tiene la extension mail(), por
    // lo que el envio de correo no puede apoyarse en la funcion mail() de PHP.
    // La aplicacion define su propia interfaz de transporte con dos opciones.
    'correo' => [

        // Transporte de correo. Valores admitidos:
        //
        //   'log'  -> el mensaje se guarda en la tabla 'correos' y queda
        //             visible en la bandeja de salida del panel. No sale nada
        //             a internet. Es el valor por defecto y el recomendado para
        //             desarrollo y para las pruebas de los casos de aceptacion.
        //
        //   'smtp' -> el mensaje se entrega a un servidor SMTP real mediante
        //             una implementacion propia en PHP puro, con soporte de
        //             STARTTLS y autenticacion. Exige credenciales de un
        //             buzon de correo saliente.
        //
        // El transporte de prueba 'log' es el que permite verificar el caso de
        // aceptacion 9 (simular un fallo de envio conservando la adjudicacion)
        // sin necesidad de tocar un servidor real.
        'transporte' => 'log',

        // Direccion de la que salen los correos. Debe ser un buzon real del
        // supermercado: es lo primero que ve la clienta si algo falla.
        'remitente' => 'sorteos@supermercado.local',

        // Nombre que aparece como remitente visible.
        'remitente_nombre' => 'Sorteos del supermercado',

        // Parametros del servidor SMTP, usados solo si transporte = 'smtp'.
        // Estos valores son DE EJEMPLO.
        'smtp' => [
            'host'       => 'smtp.supermercado.local',
            'puerto'     => 587,
            // 'tls' para STARTTLS en el puerto 587, 'ssl' para TLS implicito
            // en el puerto 465, o '' para conectar sin cifrar. Nunca usar ''
            // con credenciales reales.
            'seguridad'  => 'tls',
            'usuario'    => '',
            'contrasena' => '',
            // Tiempo maximo de espera de la conexion y de la respuesta, en
            // segundos. Un supermercado tiene una red inalambrica: una espera
            // larga dejaria la pantalla de la azafata congelada.
            'timeout'    => 10,
            // Rematar con un salto de linea, exigido por algunos servidores.
            'remate'     => "\r\n",
        ],
    ],

    // =========================================================================
    // Archivos subidos (apartado 4.9 de la especificacion)
    // =========================================================================
    'archivos' => [

        // Tamano maximo en bytes de una imagen subida (2 MB). Es un limite
        // holgado para banners y logotipos y evita que alguien cuelgue una
        // imagen de 30 MB que haria la pagina lenta en la tablet.
        'max_bytes' => 2 * 1024 * 1024,

        // Lista blanca de extensiones. Cualquier otra se rechaza, con
        // independencia del tipo MIME real del contenido.
        'extensiones' => ['jpg', 'jpeg', 'png', 'webp'],

        // Tipos MIME aceptados, comprobados ademas con finfo.
        'tipos_mime' => ['image/jpeg', 'image/png', 'image/webp'],

        // Directorio de destino en disco, relativo a la raiz del proyecto.
        'directorio' => 'uploads',

        // Prefijo del nombre de fichero generado por el servidor. El nombre
        // original que envia el administrador no se conserva nunca: se
        // descarta por completo para evitar recorrido de directorios y los
        // nombres con acentos o espacios que dan problemas en algunos
        // navegadores y sistemas de ficheros.
        'prefijo' => 'img_',
    ],
];
