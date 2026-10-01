-- ============================================================================
-- Sorteos de supermercado - esquema de la base de datos
-- ============================================================================
--
-- MOTOR: MariaDB 10.4 (decision D11). Todas las decisiones de diseño que
-- siguen son consecuencia del motor real, no de MySQL idealizado:
--
--   * NO se usa SELECT ... FOR UPDATE SKIP LOCKED: no existe hasta MariaDB
--     10.6. El bloqueo de la adjudicacion se resuelve con GET_LOCK.
--   * El tipo JSON de MariaDB 10.4 es un alias de LONGTEXT con una restriccion
--     CHECK json_valid. Por eso los campos de datos y de instantanea se
--     declaran como LONGTEXT con el CHECK explicito, en lugar de JSON, que
--     comportaria igual pero ocultaria lo que hace.
--   * utf8mb4 en todas las tablas: es la unica codificacion que representa la
--     enye, las tildes y el simbolo de euro que pueden aparecer en un nombre de
--     premio.
--
-- INSTALACION: este fichero lo aplica bin/instalar.php, que solo usa
-- CREATE ... IF NOT EXISTS. Nunca hace DROP, de modo que ejecutarlo dos veces
-- no destruye una campana en curso (decision D12).
--
-- Los identificadores van en espanol para ser coherentes con los nombres de
-- tabla que fija la especificacion.
--
-- @see bin\instalar.php
-- @see decision D11
-- ============================================================================

-- Se desactivan las comprobaciones de claves foráneas durante la creacion. Hay
-- una razon tecnica: promociones y usuarios se referencian mutuamente
-- (usuarios.promocion_id apunta a promociones, y promociones.creado_por
-- apunta a usuarios), de modo que no se pueden crear en un orden que resuelva
-- las dos. Desactivar la comprobacion solo durante la creacion permite crear
-- todas las tablas en cualquier orden; las restricciones quedan definidas y se
-- aplican con normalidad en cuanto la creacion termina y se vuelve a activar.
SET FOREIGN_KEY_CHECKS = 0;

-- Se usan solo tipos conocidos. Un valor desconocido en una restriccion CHECK
-- haria que la tabla no se creara, y un MySQL antiguo no la entendera.
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';


-- ============================================================================
-- 1. usuarios
-- ============================================================================
-- Cuentas de la aplicacion: administradores y azafatas. Los dos roles estan en
-- la misma tabla porque comparten exactamente las mismas columnas y las mismas
-- reglas de contrasena; separarlas duplicaria el codigo sin ganar nada.
--
-- contrasena_hash guarda un hash bcrypt, nunca la contrasena.
-- estado = 0 desactiva la cuenta sin borrarla, para conservar el historial de
-- auditoria y de participaciones de esa persona.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre            VARCHAR(60)     NOT NULL,
    nombre_completo   VARCHAR(120)    NOT NULL DEFAULT '',
    contrasena_hash   VARCHAR(255)    NOT NULL,
    rol               ENUM('administrador', 'azafata') NOT NULL,
    estado            TINYINT(1)      NOT NULL DEFAULT 1,

    -- Promocion a la que esta asignada la azafata. Una azafata ve una sola
    -- promocion a la vez, y esa asignacion es lo que impide que una pantalla
    -- de participacion sirva los premios de otra campana. Es NULL para los
    -- administradores, que pueden ver todas.
    promocion_id      INT UNSIGNED    NULL,

    -- Control de fuerza bruta. El apartado de seguridad propio de esta
    -- aplicacion limita los intentos fallidos y bloquea la cuenta un rato.
    intentos_fallidos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_hasta   DATETIME        NULL,

    ultimo_acceso     DATETIME        NULL,
    creado_por        INT UNSIGNED    NULL,
    creado_en         DATETIME        NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_nombre (nombre),
    KEY ix_usuarios_rol_estado (rol, estado),
    KEY ix_usuarios_promocion (promocion_id),

    CONSTRAINT fk_usuarios_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE SET NULL,
    CONSTRAINT fk_usuarios_creado_por FOREIGN KEY (creado_por)
        REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Cuentas de administradores y azafatas';


-- ============================================================================
-- 2. promociones
-- ============================================================================
-- Una campana. Todos los datos de configuracion que se pueden cambiar durante
-- la vida de la campana viven aqui, y los que son listas de items, en sus
-- propias tablas.
--
-- zona_horaria se guarda aunque la aplicacion use siempre Europe/Madrid, para
-- que quede constancia de con que zona se creo la campana y por si en el
-- futuro se admite otra.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promociones (
    id                 INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    nombre             VARCHAR(120)   NOT NULL,
    descripcion        TEXT           NULL,

    -- Datos del comercio, en texto libre. No hay tabla de tiendas porque se
    -- acordo que una campana es siempre de un solo supermercado (decision
    -- D15); estos campos alimentan el pie de pagina y los correos.
    comercio_nombre    VARCHAR(150)   NOT NULL DEFAULT '',
    comercio_cif       VARCHAR(20)    NOT NULL DEFAULT '',
    comercio_domicilio VARCHAR(200)   NOT NULL DEFAULT '',
    comercio_telefono  VARCHAR(30)    NOT NULL DEFAULT '',

    zona_horaria       VARCHAR(64)    NOT NULL DEFAULT 'Europe/Madrid',
    estado             ENUM('borrador', 'activa', 'finalizada') NOT NULL DEFAULT 'borrador',
    fecha_inicio       DATE           NULL,
    fecha_fin          DATE           NULL,

    -- Modo simulacion: las participaciones se procesan de verdad pero se
    -- marcan como simuladas y no consumen premios reales ni envian correo. Lo
    -- usa el administrador para ensayar la campana antes de abrirla.
    modo_simulacion    TINYINT(1)     NOT NULL DEFAULT 0,

    -- Si los premios no entregados pasan al tramo o dia siguiente (decision
    -- D4). Es configurable porque el promotor todavia no lo ha confirmado.
    loteria_persiste   ENUM('si', 'no') NOT NULL DEFAULT 'si',

    -- Si se envia correo a las ganadoras y a las no ganadoras. Desactivado por
    -- defecto, porque enviar correo a un cliente que no lo ha pedido es el peor
    -- error posible en una campana.
    correo_ganador     TINYINT(1)     NOT NULL DEFAULT 0,
    correo_no_ganador  TINYINT(1)     NOT NULL DEFAULT 0,

    -- Asunto y cuerpo de los correos. Llevan marcadores entre llaves, del tipo
    -- {{nombre}}, {{premio}}, {{codigo}} y {{promocion}}, que se sustituyen al
    -- encolar el mensaje. El apartado 4.9 los llama «variables controladas»:
    -- solo se sustituyen las de esta lista, nunca lo que venga de la base de
    -- datos como nombre de marcador.
    correo_ganador_asunto     VARCHAR(190) NOT NULL DEFAULT '',
    correo_ganador_cuerpo     LONGTEXT     NULL,
    correo_no_ganador_asunto  VARCHAR(190) NOT NULL DEFAULT '',
    correo_no_ganador_cuerpo  LONGTEXT     NULL,

    -- Dias que se conservan los datos personales de las participantes. NULL
    -- significa que se conservan hasta que se borren a mano. Es el parametro
    -- de retencion que pide el apartado 7.
    retencion_dias      SMALLINT UNSIGNED NULL,

    creado_por          INT UNSIGNED NULL,
    creado_en           DATETIME     NOT NULL,
    actualizada_en      DATETIME     NOT NULL,
    cerrada_en          DATETIME     NULL,

    PRIMARY KEY (id),
    KEY ix_promociones_estado (estado, fecha_inicio),

    CONSTRAINT fk_promociones_creado_por FOREIGN KEY (creado_por)
        REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Campanas de sorteos';


-- ============================================================================
-- 3. configuracion_visual
-- ============================================================================
-- Un registro por campana. Se separa de promociones porque son datos que se
-- tocan desde una pantalla de configuracion distinta y no se mezclan con los
-- datos generales de la campana.
--
-- Los colores se guardan en hexadecimal con almohadilla, por ejemplo #AABBCC.
-- La validacion del formato la hace la pantalla que los edita.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS configuracion_visual (
    promocion_id           INT UNSIGNED NOT NULL,

    -- Imagenes. Se guarda la ruta relativa dentro de la carpeta uploads, nunca
    -- una ruta absoluta del disco: una ruta absoluta dejaria de funcionar en
    -- cuanto la campana se copie a otra carpeta o a otro servidor.
    banner_sup_ruta        VARCHAR(255) NOT NULL DEFAULT '',
    banner_sup_alt         VARCHAR(160) NOT NULL DEFAULT '',
    banner_pie_ruta        VARCHAR(255) NOT NULL DEFAULT '',
    banner_pie_alt         VARCHAR(160) NOT NULL DEFAULT '',

    -- Imagen y texto del resultado con premio, y del resultado sin premio
    -- (apartado 4.9).
    resultado_premio_ruta      VARCHAR(255) NOT NULL DEFAULT '',
    resultado_premio_texto     TEXT         NULL,
    resultado_no_premio_ruta   VARCHAR(255) NOT NULL DEFAULT '',
    resultado_no_premio_texto  TEXT         NULL,

    -- Mensajes que se muestran en la pantalla de resultado, ademas del texto
    -- de congratulations y de animo. Se guardan aparte de las imagenes para que
    -- el administrador pueda cambiar el texto sin volver a subir el fichero.
    texto_ganador      TEXT NULL,
    texto_no_ganador   TEXT NULL,

    -- Paleta. Estos valores se escriben como variables CSS en la maquetacion,
    -- de forma que cambiar un color aqui cambia la interfaz entera sin tocar
    -- una sola linea de CSS. Es lo que permite cumplir el apartado 4.9 sin un
    -- framework, que impone su propia escala de colores.
    color_fondo        VARCHAR(7) NOT NULL DEFAULT '#f6f7f9',
    color_texto        VARCHAR(7) NOT NULL DEFAULT '#1a1a1a',
    color_primario     VARCHAR(7) NOT NULL DEFAULT '#14509b',
    color_acento       VARCHAR(7) NOT NULL DEFAULT '#e8a33d',
    color_campos       VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    color_bordes       VARCHAR(7) NOT NULL DEFAULT '#d3d7dd',

    actualizado_en     DATETIME NOT NULL,

    PRIMARY KEY (promocion_id),

    CONSTRAINT fk_configuracion_visual_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Colores, imagenes y textos de la interfaz de cada campana';


-- ============================================================================
-- 4. tramos
-- ============================================================================
-- Un tramo es un periodo de participacion: una fecha y un intervalo de horas.
-- Una campana puede tener muchos, varios al dia, y solapados NO.
--
-- Las horas se guardan como TIME de MySQL, en hora local de la campana, sin
-- zona. El servicio de validaciones comprueba ademas dos cosas que una
-- restriccion CHECK no puede:
--   * que el tramo no se solape con otro de la misma campana,
--   * que el tramo no cruce el cambio de hora de verano.
-- Ver \App\Services\Tramos, en el hito 3.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tramos (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id INT UNSIGNED NOT NULL,
    fecha        DATE        NOT NULL,
    hora_inicio  TIME        NOT NULL,
    hora_fin     TIME        NOT NULL,
    creado_en    DATETIME    NOT NULL,

    PRIMARY KEY (id),

    -- El indice unico impide crear dos tramos que empiecen a la misma hora en
    -- la misma fecha, que es el caso mas obvio de solapamiento.
    UNIQUE KEY uq_tramos_promocion_inicio (promocion_id, fecha, hora_inicio),
    KEY ix_tramos_busqueda (promocion_id, fecha, hora_inicio, hora_fin),

    CONSTRAINT fk_tramos_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,

    -- La hora de fin tiene que ser posterior a la de inicio. Es la unica regla
    -- que se puede expresar aqui; el resto necesita consultar la tabla.
    CONSTRAINT ck_tramos_orden CHECK (hora_fin > hora_inicio)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Periodos de participacion, con fecha y horas';


-- ============================================================================
-- 5. tipos_premio
-- ============================================================================
-- Catalogo de premios: que regalos existen en la campana. Las cantidades de cada
-- uno se asignan despues a los tramos.
--
-- Se puede editar y desactivar un tipo sin alterar el historial: las unidades
-- ya adjudicadas guardan su tipo_premio_id y el nombre se lee de aqui, de modo
-- que renombrar un premio no altera lo que se entrego. Desactivar en lugar de
-- borrar es lo que permite eso: un premio que ya se ha entregado no se puede
-- borrar del catalogo sin perder la referencia.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipos_premio (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id INT UNSIGNED NOT NULL,
    nombre       VARCHAR(120) NOT NULL,
    descripcion  TEXT         NULL,
    imagen_ruta  VARCHAR(255) NOT NULL DEFAULT '',
    activo       TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en    DATETIME     NOT NULL,
    actualizado_en DATETIME   NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_tipos_premio_nombre (promocion_id, nombre),
    KEY ix_tipos_premio_activos (promocion_id, activo),

    CONSTRAINT fk_tipos_premio_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Catalogo de premios de la campana';


-- ============================================================================
-- 6. asignaciones_tramo
-- ============================================================================
-- Cantidad aprobada de cada tipo de premio en cada tramo: el PLAN.
--
-- ============================================================================
-- POR QUE ESTA TABLA NO ES LA VERDAD Y POR QUE NO SE DESTRUYE AL SINONIZAR
-- ============================================================================
-- El apartado 4.6 pide comparar el calendario con las cantidades inicialmente
-- asignadas, mostrar las diferencias y, tras confirmarlas, sincronizar los
-- totales. Si al sincronizar se sobrescribiera esta tabla, se perderia la
-- referencia del plan original y no habria forma de volver a mostrar las
-- diferencias.
--
-- Por eso esta tabla guarda SIEMPRE el plan que se introdujo, y el calendario
-- (unidades_premio) es la realidad. Las diferencias se calculan comparando
-- ambas en cada momento, y «sincronizar» significa crear filas de asignacion
-- que reflejen el calendario mas reciente, anadiendo un nuevo plan sin borrar
-- el anterior.
--
-- El indice de cantidad_permitida se anade en el hito 3, cuando se defina si la
-- tabla lleva el historico de revisiones o una sola fila por tramo y tipo.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS asignaciones_tramo (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tramo_id       INT UNSIGNED NOT NULL,
    tipo_premio_id INT UNSIGNED NOT NULL,
    cantidad       SMALLINT UNSIGNED NOT NULL,
    creado_en      DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_asignaciones_tramo_tipo (tramo_id, tipo_premio_id),
    KEY ix_asignaciones_tipo (tipo_premio_id),

    CONSTRAINT fk_asignaciones_tramo FOREIGN KEY (tramo_id)
        REFERENCES tramos (id) ON DELETE CASCADE,
    CONSTRAINT fk_asignaciones_tipo FOREIGN KEY (tipo_premio_id)
        REFERENCES tipos_premio (id) ON DELETE CASCADE,

    -- Una asignacion de cero unidades no significa nada y solo genera ruido al
    -- comparar el plan con el calendario.
    CONSTRAINT ck_asignaciones_cantidad CHECK (cantidad > 0)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Plan de cantidades por tramo y tipo de premio';


-- ============================================================================
-- 7. unidades_premio
-- ============================================================================
-- UNA FILA POR CADA UNIDAD DE PREMIO. Es la tabla mas importante del
-- proyecto: es la cola de premios.
--
-- ============================================================================
-- POR QUE SOLO UN CAMPO DE FECHA Y HORA
-- ============================================================================
-- Una unidad pertenece a un tramo, y el tramo ya tiene fecha y horas. Guardar
-- ademas la fecha y la hora en la unidad permitiria que quedaran
-- contradictorias: alguien podria mover la fecha de una unidad sin mover su
-- tramo, y el premio se situaria fuera de su periodo. Decision D9: tramo_id es
-- la referencia autoritativa e inicio es la fecha y hora programada
-- concreta. Ambas se validan juntas al escribir, y el panel de edicion impide
-- que se separen.
--
-- inicio se compara directamente con la hora actual, que se calcula en PHP
-- (decision D7), por eso no lleva indice por tipo de fecha especial.
--
-- ============================================================================
-- ESTADOS
-- ============================================================================
--   programada   -> disponible, esperando a que llegue su hora.
--   entregada    -> adjudicada a una participacion. No se vuelve a tocar.
--   anulada      -> retirada por el administrador antes de entregarse. Conserva
--                   la fila y el motivo, para que el historial cuadre.
--   no_entregada -> quedo sin entregar al cerrar la campana. Es el estado que
--                   menciona el apartado 12.2, y no hay adjudicacion retroactiva.
--
-- ============================================================================
-- LOS INDICES IMPORTANTES
-- ============================================================================
-- ix_unidades_cola es el indice de la regla central de adjudicacion. Cubre
-- (promocion_id, estado, inicio, id) justo en el orden en que la consulta de la
-- cola necesita: primero las unidades de esta campana, despues las que siguen
-- pendientes, despues ordenadas por hora programada, y con el id como
-- desempate estable para dos premios de la misma hora. Con este indice la
-- cola se resuelve con una lectura de indice y sin ordenar nada en memoria.
--
-- uq_unidades_participacion es la garantia estructural de que una
-- participacion no puede obtener mas de una unidad: es la unica participacion a
-- la que puede pertenecer la fila. MariaDB permite varios NULL en un indice
-- unico, de modo que las unidades todavia no entregadas pueden convivir, y en
-- cuanto una se asigna, la restriccion impide asignar una segunda.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unidades_premio (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id   INT UNSIGNED NOT NULL,
    tramo_id       INT UNSIGNED NOT NULL,
    tipo_premio_id INT UNSIGNED NOT NULL,

    -- Fecha y hora programada, en hora local de la campana, sin zona. Indica
    -- CUANDO queda disponible el premio para el siguiente participante valido.
    -- No garantiza que se entregue a esa hora exacta: si nadie participa hasta
    -- las 11:20, el premio de las 10:12 sigue esperando.
    inicio         DATETIME NOT NULL,

    estado         ENUM('programada', 'entregada', 'anulada', 'no_entregada') NOT NULL DEFAULT 'programada',

    -- Referencia inmutable a la participacion que se llevo el premio
    -- (apartado 6). Se rellena en el mismo UPDATE que cambia el estado a
    -- 'entregada', de modo que las dos cosas no puedan separarse.
    participacion_id     INT UNSIGNED NULL,
    adjudicada_en        DATETIME     NULL,

    -- Codigo unico de reclamacion, que es lo que recibe la clienta en el correo
    -- y lo que la azafata le entrega. Es lo que resuelve la duda 4 del apartado
    -- 12 sobre que contiene el correo de premio.
    codigo_reclamacion   CHAR(16)     NULL,

    -- Motivo de la anulacion, si la hubo. Se conserva para que el administrador
    -- vea despues por que desaparecio una unidad del plan.
    anulada_motivo       VARCHAR(200) NOT NULL DEFAULT '',

    creado_en      DATETIME NOT NULL,
    modificado_en  DATETIME NOT NULL,

    PRIMARY KEY (id),

    -- Indice de la cola de adjudicacion. Ver la explicacion de arriba.
    KEY ix_unidades_cola (promocion_id, estado, inicio, id),

    -- Busqueda por tramo, para el panel de revision del calendario.
    KEY ix_unidades_tramo (tramo_id, estado),

    -- Recuento por tipo, para el panel de seguimiento.
    KEY ix_unidades_tipo (tipo_premio_id, estado),

    -- Una participacion, como mucho, puede tener una unidad.
    UNIQUE KEY uq_unidades_participacion (participacion_id),

    -- El codigo de reclamacion es unico en toda la tabla. Lleva el
    -- identificador de la campana delante para que el codigo sea legible por
    -- la azafata y ademas unico en el sistema, sin necesidad de otra tabla.
    UNIQUE KEY uq_unidades_codigo (codigo_reclamacion),

    CONSTRAINT fk_unidades_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,
    CONSTRAINT fk_unidades_tramo FOREIGN KEY (tramo_id)
        REFERENCES tramos (id) ON DELETE CASCADE,
    CONSTRAINT fk_unidades_tipo FOREIGN KEY (tipo_premio_id)
        REFERENCES tipos_premio (id) ON DELETE RESTRICT,
    CONSTRAINT fk_unidades_participacion FOREIGN KEY (participacion_id)
        REFERENCES participaciones (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Una fila por unidad de premio. Es la cola de adjudicacion';


-- ============================================================================
-- 8. campos_formulario
-- ============================================================================
-- Que campos se piden en la pantalla de participacion y cuales son
-- obligatorios (apartado 4.8). El administrador puede anadir campos propios.
--
-- clave es un identificador interno estable, en minusculas y sin espacios, del
-- tipo «nombre» o «dni». No es la etiqueta que ve la clienta, que puede
-- cambiarse en cualquier momento sin romper nada.
--
-- tipo limita lo que se acepta. No hay ningun tipo que admita HTML, precisamente
-- para que un campo anadido por el administrador no pueda inyectar marcado.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS campos_formulario (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id  INT UNSIGNED NOT NULL,
    clave         VARCHAR(40)  NOT NULL,
    etiqueta      VARCHAR(80)  NOT NULL,
    tipo          ENUM('texto', 'email', 'telefono', 'entero', 'fecha', 'area') NOT NULL DEFAULT 'texto',
    obligatorio   TINYINT(1)   NOT NULL DEFAULT 0,
    visible       TINYINT(1)   NOT NULL DEFAULT 1,
    orden         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    valor_por_defecto VARCHAR(255) NOT NULL DEFAULT '',
    min_largo      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_largo      SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    creado_en      DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_campos_promocion_clave (promocion_id, clave),
    KEY ix_campos_orden (promocion_id, visible, orden),

    CONSTRAINT fk_campos_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,

    -- El minimo no puede ser mayor que el maximo, o el campo no seria
    -- rellenable nunca.
    CONSTRAINT ck_campos_largos CHECK (min_largo <= max_largo)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Campos configurables del formulario de participacion';


-- ============================================================================
-- 9. reglas_participacion
-- ============================================================================
-- Un registro por campana, con las reglas del apartado 4.7.
--
-- ============================================================================
-- COMO SE COMBINAN LAS REGLAS
-- ============================================================================
-- El supuesto de implementacion del documento dice que cuando hay varias
-- reglas activas se cumplen todas. Aqui cada regla es una casilla
-- independiente, de forma que se pueden activar todas a la vez o solo las que
-- interesen, y la combinacion es «todas las activas se cumplen», que es
-- exactamente ese supuesto.
--
-- ============================================================================
-- POR QUE UN SOLO INDICE UNICO RESUELVE TODAS LAS REGLAS DE DUPLICADO
-- ============================================================================
-- La regla mas engaosa de implementar es «una participacion por persona»,
-- porque «persona» no es un dato: puede ser el DNI, el ticket, el codigo o el
-- correo, segun lo que la campana pida. Y ademas hay reglas con ambito
-- distinto: una por toda la campana, o una por dia.
--
-- La solucion esta en el contenido de la huella, no en el numero de indices.
-- La huella se calcula sobre una cadena que ya incluye el ambito, por ejemplo
-- «campana:7» o «campana:7|dia:2026-03-15» o «ticket:abc123». Como cada ambito
-- produce una huella distinta, un unico indice unico
-- (promocion_id, clave_unicidad) las cubre todas. Anadir una regla nueva en el
-- hito 5 es escribir una linea mas, no crear un indice nuevo.
-- Decision D3.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reglas_participacion (
    promocion_id           INT UNSIGNED NOT NULL,

    una_por_campana       TINYINT(1) NOT NULL DEFAULT 0,
    una_por_dia           TINYINT(1) NOT NULL DEFAULT 0,
    una_por_ticket        TINYINT(1) NOT NULL DEFAULT 0,
    una_por_dni           TINYINT(1) NOT NULL DEFAULT 0,
    exigir_codigo         TINYINT(1) NOT NULL DEFAULT 0,

    -- Campo que identifica a la persona para la regla de una por persona o una
    -- por dia. Vacio significa que se deduce de los campos disponibles, con la
    -- prioridad que fija el servicio de reglas.
    campo_identidad       VARCHAR(40) NOT NULL DEFAULT '',

    -- Aviso de privacidad. El cliente tiene que poder saber para que se usan
    -- sus datos, y el apartado 15 del RGPD obliga a que el consentimiento sea
    -- inequivoco, lo que en la practica significa una casilla que marcar.
    exigir_consentimiento TINYINT(1) NOT NULL DEFAULT 0,
    texto_consentimiento  TEXT         NULL,

    -- Declaracion visible de que el numero de ticket NO se verifica contra la
    -- caja. El apartado 4.7 lo prohibe expresamente: no se puede decir que un
    -- ticket esta verificado si lo unico que se ha comprobado es que no se
    -- repite. Con el interruptor a false, el panel muestra el aviso.
    verificar_ticket       TINYINT(1) NOT NULL DEFAULT 0,

    -- Textos de rechazo, configurables porque los motivos cambian segun la
    -- campana y porque el apartado 4.7 prohibe revelar datos de otro
    -- participante: estos textos no llevan nunca un nombre ni un dato.
    texto_rechazo_horario  VARCHAR(255) NOT NULL DEFAULT '',
    texto_rechazo_duplicado VARCHAR(255) NOT NULL DEFAULT '',
    texto_rechazo_codigo   VARCHAR(255) NOT NULL DEFAULT '',
    texto_rechazo_consentimiento VARCHAR(255) NOT NULL DEFAULT '',

    actualizado_en         DATETIME NOT NULL,

    PRIMARY KEY (promocion_id),

    CONSTRAINT fk_reglas_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Reglas de participacion de cada campana';


-- ============================================================================
-- 10. participaciones
-- ============================================================================
-- Participaciones VALIDAS. Solo se inserta una fila cuando la participacion ha
-- superado todas las comprobaciones.
--
-- ============================================================================
-- POR QUE ESTA TABLA ESTA SEPARADA DE INTENTOS_RECHAZADOS
-- ============================================================================
-- Es la decision D10, y tiene una consecuencia tecnica que obliga a separarlas.
-- Esta tabla tiene un indice unico en (promocion_id, clave_unicidad) que
-- impide que una persona participe dos veces. Si los intentos rechazados se
-- guardaran aqui, un rechazo por estar ya dentro del limite haria fallar la
-- insercion de una segunda participacion valida de otra persona... y sobre
-- todo, un rechazo por un motivo que no es de duplicado (por ejemplo, llegar
-- fuera de horario) podria ocupar el sitio de un indice unico sin motivo,
-- haciendo que una participacion valida posterior fallara por un error
-- imposible de entender.
--
-- Los rechazos van a su propia tabla, que no lleva indice unico. Se siguen
-- contando para el panel de seguimiento y para detectar un intento de abusar
-- del sistema, pero no bloquean nada.
--
-- ============================================================================
-- DATOS PERSONALES
-- ============================================================================
-- datos contiene un JSON con lo que la clienta ha escrito, unicamente en los
-- campos que la campana tiene configurados. Si una campana no pide correo, no
-- se guarda correo. El apartado 4.8 lo exige: «No pedir informacion que no
-- forme parte de la configuracion de la promocion».
--
-- datos_normalizados guarda la forma normalizada de los campos que se usan
-- para comparar, por ejemplo el DNI sin espacios y en mayusculas. Se separa de
-- datos porque sirve para deduplicar y su formato tiene que ser estable, mient
-- que el texto de datos es lo que se muestra.
--
-- reglas_snapshot es una copia de las reglas tal como estaban en el instante
-- de la participacion. Sin ella, meses despues no se podria explicar por que
-- una persona fue rechazada o por que a otra se le rechazo dos veces: las
-- reglas de la campana habrian cambiado y el motivo original se habria
-- perdido. Es la decision de la seccion 13.2 del documento.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS participaciones (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id       INT UNSIGNED NOT NULL,
    tramo_id           INT UNSIGNED NOT NULL,
    usuario_azafata_id INT UNSIGNED NULL,

    -- Identificador del intento, generado por el navegador. Es lo que hace que
    -- un doble clic o una recarga devuelvan el mismo resultado sin crear una
    -- segunda participacion ni consumir otro premio (caso de aceptacion 7).
    clave_idempotencia  CHAR(36) NOT NULL,

    -- Huella HMAC de la identidad, con el ambito de la regla dentro. Es el
    -- indice unico que impide participar dos veces. Puede ser NULL cuando la
    -- campana no tiene ninguna regla de duplicado activa.
    clave_unicidad     CHAR(64) NULL,

    -- Instante de la participacion, en hora local de la campana. Es la hora
    -- REAL a la que se registro, no la hora programada del premio, que se
    -- guarda en unidades_premio. El apartado 6 pide registrar ambos.
    momento            DATETIME NOT NULL,

    resultado          ENUM('premio', 'sin_premio') NOT NULL,

    -- Datos escritos por la clienta, como JSON.
    datos              LONGTEXT NOT NULL,

    -- Formas normalizadas de los campos de deduplicacion.
    datos_normalizados LONGTEXT NULL,

    -- Copia de las reglas vigentes en este instante.
    reglas_snapshot    LONGTEXT NULL,

    -- Marca de participacion de ensayo. El modo simulacion de la campana
    -- permite practicar sin consumir premios reales.
    es_simulacion      TINYINT(1) NOT NULL DEFAULT 0,

    creado_en          DATETIME NOT NULL,

    PRIMARY KEY (id),

    -- Un intento, una participacion. Si el navegador reintenta con la misma
    -- clave, la segunda insercion falla y el servicio devuelve la primera.
    UNIQUE KEY uq_participaciones_idempotencia (promocion_id, clave_idempotencia),

    -- Una persona, una participacion por ambito de regla. NULL si la campana no
    -- tiene reglas de duplicado activas.
    UNIQUE KEY uq_participaciones_unicidad (promocion_id, clave_unicidad),

    -- Recuentos del panel de seguimiento: invalidas por tramo y hora.
    KEY ix_participaciones_tramo (tramo_id, momento),
    KEY ix_participaciones_promocion (promocion_id, resultado, momento),
    KEY ix_participaciones_azafata (usuario_azafata_id),

    CONSTRAINT fk_participaciones_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,
    CONSTRAINT fk_participaciones_tramo FOREIGN KEY (tramo_id)
        REFERENCES tramos (id) ON DELETE RESTRICT,
    CONSTRAINT fk_participaciones_usuario FOREIGN KEY (usuario_azafata_id)
        REFERENCES usuarios (id) ON DELETE SET NULL,

    -- El JSON se valida con la misma comprobacion que usaria el tipo JSON de
    -- MariaDB, escrita a mano para que se vea en el esquema y para que el
    -- fichero se pueda aplicar tambien en un motor sin ese tipo.
    CONSTRAINT ck_participaciones_datos CHECK (datos IS NULL OR JSON_VALID(datos))
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Participaciones validas de la campana';


-- ============================================================================
-- 11. intentos_rechazados
-- ============================================================================
-- Intentos que NO superaron las comprobaciones. Sin indices unicos, porque un
-- rechazo no puede impedir nada (decision D10).
--
-- No se guarda el texto que la clienta ha escrito: solo el motivo y una huella
-- de la identidad. Un intento rechazado no ha dado derecho a nada, asi que no
-- hay razon para conservar sus datos personales mas alla del informe de la
-- campana. Es una decision de minimizacion de datos: el apartado 7 pide evitar
-- exponer datos personales, y no guardado es la forma mas eficaz de no
-- exponerlos.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS intentos_rechazados (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id       INT UNSIGNED NOT NULL,
    tramo_id           INT UNSIGNED NULL,
    usuario_azafata_id INT UNSIGNED NULL,

    -- Mismo nombre que en participaciones. Permite que un reintento del mismo
    -- intento, por doble clic, devuelva el mismo rechazo en lugar de generar
    -- dos filas.
    clave_idempotencia  CHAR(36) NOT NULL,
    clave_identidad    CHAR(64) NULL,

    momento            DATETIME NOT NULL,
    motivo_codigo      VARCHAR(40) NOT NULL,
    motivo_texto       VARCHAR(255) NOT NULL DEFAULT '',

    creado_en          DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_rechazos_idempotencia (promocion_id, clave_idempotencia),

    -- Recuento de rechazos por motivo en el panel de seguimiento. Un volumen
    -- alto de un mismo motivo es la senal de que algo va mal.
    KEY ix_rechazos_motivo (promocion_id, motivo_codigo, momento),
    KEY ix_rechazos_tramo (tramo_id),

    CONSTRAINT fk_rechazos_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,
    CONSTRAINT fk_rechazos_tramo FOREIGN KEY (tramo_id)
        REFERENCES tramos (id) ON DELETE SET NULL,
    CONSTRAINT fk_rechazos_usuario FOREIGN KEY (usuario_azafata_id)
        REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Intentos rechazados, sin datos personales';


-- ============================================================================
-- 12. correos
-- ============================================================================
-- Cola de correo. Un fallo de envio NUNCA altera una adjudicacion (apartado 9
-- y caso de aceptacion 9), asi que el mensaje se encola dentro de la misma
-- transaccion que adjudica el premio y se envia despues, por separado.
--
-- Si la transaccion de la adjudicacion se deshace, el correo tampoco sale: no
-- se puede enviar un mensaje de premio a alguien que al final no lo recibio.
-- Si el envio falla, el mensaje queda con estado 'error' y se puede reintentar
-- sin tocar la adjudicacion.
--
-- El transporte 'log' de la configuracion escribe aqui y no sale a internet,
-- que es lo que permite probar el caso 9 sin depender de un servidor real.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS correos (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id       INT UNSIGNED NOT NULL,
    participacion_id   INT UNSIGNED NULL,

    tipo               ENUM('ganador', 'no_ganador') NOT NULL,
    destinatario       VARCHAR(190) NOT NULL,
    asunto             VARCHAR(255) NOT NULL DEFAULT '',
    cuerpo             LONGTEXT     NOT NULL,

    -- Valores con los que se han sustituido los marcadores del texto, por si
    -- hay que reconstruir el mensaje original al reintentar.
    variables          LONGTEXT     NULL,

    transporte         VARCHAR(20)  NOT NULL DEFAULT 'log',
    estado             ENUM('pendiente', 'enviando', 'enviado', 'error') NOT NULL DEFAULT 'pendiente',
    intentos           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_error       TEXT         NULL,
    enviado_en         DATETIME     NULL,

    -- Momento hasta el que no se reintenta este mensaje, para no repetir un
    -- envio fallido cada vez que se vacie la cola.
    bloqueado_hasta    DATETIME     NULL,
    creado_en          DATETIME     NOT NULL,

    PRIMARY KEY (id),

    -- Indice del vaciado de la cola: primero los pendientes, y entre ellos los
    -- mas antiguos.
    KEY ix_correos_cola (estado, bloqueado_hasta, creado_en),
    KEY ix_correos_participacion (participacion_id),
    KEY ix_correos_promocion (promocion_id, estado),

    CONSTRAINT fk_correos_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,
    CONSTRAINT fk_correos_participacion FOREIGN KEY (participacion_id)
        REFERENCES participaciones (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Cola de correo, con estado e intentos';


-- ============================================================================
-- 13. auditoria
-- ============================================================================
-- Quién hizo qué y cuándo. Cubre los cambios del calendario, los de la
-- configuracion y las adjudicaciones (apartado 7).
--
-- ============================================================================
-- POR QUE SE REGISTRA TAMBIEN LA LECTURA DE DATOS PERSONALES
-- ============================================================================
-- Decision D18: el administrador ve los datos completos porque sin ellos no
-- puede entregar el premio ni resolver una reclamacion, pero cada acceso a
-- esos datos queda registrado. Asi hay constancia de quien ha visto el telefono
-- o el correo de una persona concreta, lo que es un requisito habitual cuando
-- hay datos personales de terceros.
--
-- datos_antes y datos_despues solo se rellenan para cambios de configuracion,
-- nunca para datos de participantes: de eso ya esta la fila de la
-- participacion.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id  INT UNSIGNED NULL,
    usuario_id    INT UNSIGNED NULL,

    -- Se guarda tambien el nombre del usuario, aunque la cuenta se borre o se
    -- desactive despues. Sin una copia, un registro de auditoria que apunta a
    -- un usuario eliminado se queda sin decir quien fue.
    usuario_nombre VARCHAR(60) NOT NULL DEFAULT '',

    entidad        VARCHAR(40)  NOT NULL,
    entidad_id     VARCHAR(40)  NOT NULL DEFAULT '',
    accion         VARCHAR(60)  NOT NULL,

    datos_antes    LONGTEXT     NULL,
    datos_despues  LONGTEXT     NULL,
    ip             VARCHAR(45)  NOT NULL DEFAULT '',

    -- Decision D18 aplicada a las vistas de lista del panel. datos_antes y
    -- datos_despues estan reservados a cambios de configuracion, y el filtro de
    -- una consulta y el numero de filas que ha visto el administrador no son
    -- ninguna de las dos cosas: no se pueden deshacer y no describen el estado
    -- de un objeto. Van en columnas propias para que el panel pueda responder
    -- «¿quien ha mirado esto y con que filtro?» sin tener que interpretar un
    -- documento JSON, y para que un filtro muy largo no se tenga que recortar
    -- para que quepa en datos_despues.
    filtros        VARCHAR(255) NULL,
    filas_mostradas INT UNSIGNED NULL,

    creado_en      DATETIME NOT NULL,

    PRIMARY KEY (id),

    -- Consulta del historial de un elemento concreto, por ejemplo todas las
    -- revisiones de una unidad de premio concreta.
    KEY ix_auditoria_entidad (entidad, entidad_id, creado_en),

    -- Historial de la campana por orden de tiempo, que es como se lee.
    KEY ix_auditoria_campana (promocion_id, creado_en),

    -- Historial de accesos a datos personales, por usuario.
    KEY ix_auditoria_usuario (usuario_id, creado_en),

    CONSTRAINT fk_auditoria_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,
    CONSTRAINT fk_auditoria_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Registro de cambios y de accesos a datos personales';


-- ============================================================================
-- 14. codigos_validos
-- ============================================================================
-- Lista precargada de tickets o codigos que el supermercado considera validos.
--
-- ============================================================================
-- POR QUE LA TABLA EXISTE SI LA COMPROBACION DE TICKET ESTA PENDIENTE
-- ============================================================================
-- El apartado 12.3 deja abierta la pregunta de como se comprueba que un ticket
-- es de una compra valida, y admite cuatro respuestas: lista precargada,
-- integracion con la caja, revision manual o simple control de duplicados.
--
-- Se implementan dos de las cuatro, que son las que no dependen de un sistema
-- externo:
--
--   * Control de duplicados, siempre activo si la campana tiene la regla de
--     una participacion por ticket. No necesita esta tabla.
--   * Lista precargada, con esta tabla. El administrador carga los tickets de
--     la campana y entonces si se puede comprobar que el numero pertenece a una
--     compra de esa campana.
--
-- Se guarda el hash del codigo y no el codigo. La lista de tickets es un
-- conjunto de datos de compras reales, y ahi no hay ninguna razon para que un
-- atacante con acceso de solo lectura a la base de datos pueda ver cuales son.
-- El valor se guarda cifrado con la misma huella HMAC que la identidad.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS codigos_validos (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    promocion_id  INT UNSIGNED NOT NULL,
    tipo          ENUM('ticket', 'codigo') NOT NULL,

    -- Huella HMAC del codigo normalizado. El codigo en claro no se guarda.
    valor_hash    CHAR(64) NOT NULL,

    -- Si el codigo se ha gastado y en que participacion. Sirve para que un
    -- ticket de una compra real no se pueda reutilizar en dos participaciones,
    -- que es el caso de uso real de un codigo de una sola vez.
    usado_por_participacion_id INT UNSIGNED NULL,
    usado_en      DATETIME     NULL,

    creado_en     DATETIME NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_codigos_validos (promocion_id, tipo, valor_hash),
    KEY ix_codigos_libres (promocion_id, tipo, usado_en),

    CONSTRAINT fk_codigos_promocion FOREIGN KEY (promocion_id)
        REFERENCES promociones (id) ON DELETE CASCADE,
    CONSTRAINT fk_codigos_participacion FOREIGN KEY (usado_por_participacion_id)
        REFERENCES participaciones (id) ON DELETE SET NULL
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Lista precargada de tickets y codigos validos, guardada como huella';


-- ============================================================================
-- 15. migraciones
-- ============================================================================
-- Control de que parte del esquema se ha aplicado. bin/instalar.php consulta
-- esta tabla antes de aplicar sql/migraciones/, de modo que un cambio de
-- esquema posterior no obliga a recrear la base de datos ni a perder el
-- calendario de una campana que este en marcha.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migraciones (
    version     VARCHAR(60)  NOT NULL,
    aplicada_en DATETIME     NOT NULL,
    duracion_ms INT UNSIGNED NOT NULL DEFAULT 0,
    comentarios VARCHAR(255) NOT NULL DEFAULT '',

    PRIMARY KEY (version)
) ENGINE = InnoDB
  DEFAULT CHARSET = utf8mb4
  COLLATE = utf8mb4_unicode_ci
  COMMENT = 'Migraciones de esquema ya aplicadas';


-- Se vuelven a activar las comprobaciones. A partir de aqui todas las claves
-- foráneas y las restricciones estan activas.
SET FOREIGN_KEY_CHECKS = 1;
