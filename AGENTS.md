# AGENTS.md

Este fichero es lo primero que hay que leer al abrir el proyecto, y dura treinta
segundos.

**1. Lee `ESTADO.md`, sección «Por dónde continuar», antes de tocar nada.**
Ahí está el punto de partida: qué hito está cerrado, cuál toca y qué dos avisos
dejar escritos para quien venga después. `ESTADO.md` manda sobre cualquier
nota mental que tengas de una sesión anterior, y se actualiza al final de cada
hito en el mismo commit.

**2. Antes de dar algo por terminado, ejecuta las tres comprobaciones.** Las tres
deben salir limpias, y las tres escriben en la base de pruebas, nunca en la de
una campaña real:

```
C:\xampp\php\php.exe bin\verificar_docs.php
C:\xampp\php\php.exe tests\run.php
C:\xampp\php\php.exe bin\instalar.php --diagnostico
```

PHP **no está en el `PATH`**: hay que llamar a `C:\xampp\php\php.exe` con la ruta
completa. Es el error que se comete primero.

**3. No rompas las reglas de `ESTADO.md`**, sobre todo la sección «Reglas que no
hay que romper», que el verificador hace cumplir, y la sección «Trampas
conocidas», que no hace cumplir nadie y por eso está escrita. Un `DROP` en el
instalador, un `exit` en la aplicación web, una salida sin escapar o una
dependencia externa rompen el proyecto, y el verificador falla con código 1.
