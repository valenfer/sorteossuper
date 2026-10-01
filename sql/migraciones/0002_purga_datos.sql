-- ============================================================================
-- 0002_purga_datos
-- ============================================================================
-- Anade a participaciones y a correos la marca de purga de retencion, con los
-- indices que la purga necesita para no recorrer la tabla entera. Es lo que
-- permite al hito 7 vaciar los datos personales sin borrar filas.
--
-- ============================================================================
-- POR QUE HACE FALTA UNA COLUMNA Y NO BASTA CON RECONOCER EL JSON
-- ============================================================================
-- La purga deja la fila puesta y vacia lo que identifica a una persona. Lo
-- natural seria reconocer una participacion ya purgada por su contenido, y eso
-- tiene dos fallos:
--
--   1. Habria que decidir que JSON significa «purgado» y cual es un formulario
--     de verdad, y un dia un formulario legitimo podria parecerse.
--   2. En correos no se podria ni intentarse: vaciar el cuerpo deja cadena
--     vacia, y hay que distinguirla de un mensaje que salio vacio.
--
-- Con una columna, la pregunta «¿esta fila purgada?» es un «WHERE purgada_en IS
-- NULL», que es ademas lo que puede aprovechar un indice. Y de regalo la purga
-- queda fechada, con lo que el panel puede decir cuando se hizo en vez de no
-- saber nada.
--
-- ============================================================================
-- POR QUE TODO LLEVA «IF NOT EXISTS»
-- ============================================================================
-- El instalador aplica sql/schema.sql entero y despues todas las migraciones, en
-- ese orden. En una base ya montada el paso 2 no hace nada con este fichero,
-- porque la tabla «migraciones» recuerda que 0002 ya se aplico. En una base nueva
-- el paso 1 crea las dos tablas ya con columna e indice, y el paso 2 se encuentra
-- con que este fichero los anade otra vez. Sin el «IF NOT EXISTS» la instalacion
-- desde cero —que es cuando no hay datos que perder— peta con «columna
-- duplicada».
--
-- Los indices tambien lo llevan. Es el unico caso en que hace falta: MySQL no
-- admite CREATE INDEX IF NOT EXISTS, MariaDB si, y aqui manda MariaDB (decision
-- D11). Si algun dia se cambiara de motor, estos dos CREATE INDEX seria lo
-- primero que habria que reescribir.
--
-- El fichero no lleva DROP, ni TRUNCATE, ni ninguna sentencia que borre datos,
-- asi que se puede aplicar sin parar el servicio.
--
-- ============================================================================
-- CUANDO APLICARLO EN UNA BASE QUE YA TIENE DATOS
-- ============================================================================
-- Anadir una columna es instantaneo en MariaDB 10.4, pero anadir un indice no:
-- hay que reconstruir la tabla, y eso bloquea las escrituras mientras dura. En una
-- base de desarrollo dura nada. En una campana de verdad con participaciones de
-- verdad, aplicar este fichero en un momento tranquilo. La alternativa, si el
-- volumen lo hace necesario, es aplicar solo los ALTER de columna en caliente y
-- dejar los CREATE INDEX para una ventana de mantenimiento.
-- ----------------------------------------------------------------------------

ALTER TABLE participaciones
    ADD COLUMN IF NOT EXISTS purgada_en DATETIME NULL AFTER es_simulacion;

CREATE INDEX IF NOT EXISTS ix_participaciones_purga ON participaciones (promocion_id, purgada_en);

ALTER TABLE correos
    ADD COLUMN IF NOT EXISTS purgada_en DATETIME NULL AFTER bloqueado_hasta;

CREATE INDEX IF NOT EXISTS ix_correos_purga ON correos (promocion_id, purgada_en);