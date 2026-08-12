# Checklist de validación post-despliegue — ATHENEA

> Documento operacional complementario a
> [`CHECKLIST_DESPLIEGUE_PRODUCCION.md`](CHECKLIST_DESPLIEGUE_PRODUCCION.md).
> Ese documento cubre la **instalación** (infraestructura, `.env`,
> migraciones, Nginx, Supervisor). Este documento cubre la **validación
> funcional** inmediatamente después de que el despliegue quedó "arriba" —
> es la pasada de humo (*smoke test*) módulo por módulo para detectar
> regresiones antes de que las reporte un usuario real.
>
> Correr en orden. Cada punto trae: **qué hacer** → **qué se espera ver** →
> **señal de alerta** (indica que algo falló). Marcar ☑ solo si el
> resultado observado coincide con lo esperado.
>
> Usuario de prueba sugerido: `admin@esalud.cl` (roles `Superadmin,
> Administrador`) para cubrir el 100% de los módulos; repetir puntos clave
> con un usuario `Analista` ("Estadística APS") para confirmar las
> restricciones de permisos (sección 2).

---

## 1. Login

- [ ] Cargar la URL de producción → aparece la pantalla ATENEA (fondo
      degradado azul, tarjeta blanca centrada, "Bienvenido / Inicia sesión
      para continuar").
- [ ] Login con credenciales correctas (`admin@esalud.cl`) → redirige al
      Dashboard, sin quedarse en blanco ni recargar al login.
- [ ] Login con contraseña incorrecta → mensaje de error visible, no
      redirige, no expone detalle técnico (sin trazas de Laravel en
      pantalla).
- [ ] Ícono mostrar/ocultar contraseña funciona.
- [ ] **Prueba crítica de sesión:** login exitoso → cerrar la pestaña →
      abrir de nuevo la URL → la sesión debe seguir activa (no vuelve al
      login). Si vuelve al login, es el síntoma típico de
      `SESSION_SECURE_COOKIE`/`APP_URL` mal configurado para el esquema
      HTTP/HTTPS real del servidor (ver `CHECKLIST_DESPLIEGUE_PRODUCCION.md`
      punto 16).
- [ ] Logout → vuelve al login y bloquea el acceso directo a rutas internas
      por URL (ej. `/dashboard`) sin sesión.

**⚠️ Señal de alerta:** login "responde 200" pero el usuario aparece
deslogueado al primer refresh → revisar cookies/CORS antes que el código de
autenticación.

---

## 2. Permisos por rol

Matriz real vigente en el código (`app/Policies/*.php`) — usar para
verificar, no asumir:

| Módulo | Superadmin | Administrador | Auditor | Revisor | Analista (Estadística APS) |
|---|---|---|---|---|---|
| Usuarios (CRUD) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Centros de Salud (CRUD) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Auditoría (ver) | ✅ | ✅ | ❌ | ❌ | ❌ |
| Motor de Reglas → Configuración (editar) | ❌ | ✅ | ❌ | ❌ | ❌ |
| Plantillas REM (CRUD) | ❌ | ✅ | ❌ | ❌ | ❌ |
| Cargas REM (ver/crear) | ✅ | ❌ | ❌ | ✅ | ✅ |
| Cargas REM (aprobar/rechazar) | ✅ | ❌ | ❌ | ❌ | ❌ |
| Calibración REM (ver) | ✅ | ❌ | ✅ | ✅ | ✅ |
| Calibración REM (calibrar/decidir) | ✅ | ❌ | ❌ | ❌ | ✅ |
| Calibración REM (aprobar definitivo) | ✅ | ❌ | ❌ | ❌ | ❌ |

- [ ] Con `admin@esalud.cl`: los 10 ítems del menú lateral son visibles
      (Dashboard, Usuarios, Centros de Salud, Cargas REM, Calibración REM,
      Criterios funcionales, Auditoría, Motor de Reglas y sus submódulos).
- [ ] Con un usuario `Analista`: el menú **no** muestra Usuarios, Centros de
      Salud, Auditoría ni Motor de Reglas → Configuración. Intentar acceder
      por URL directa a `/users` o `/audit` debe devolver 403 o redirigir,
      nunca mostrar el contenido.
- [ ] Con un usuario `Auditor`: puede ver Calibración REM pero no puede
      calibrar/decidir filas (solo lectura).
- [ ] Con un usuario `Revisor`: puede ver y crear Cargas REM pero no
      aprobar/rechazar ni acceder a Calibración con permisos de edición.

**⚠️ Señal de alerta:** un rol no-admin ve un módulo restringido en el menú,
o accede sin error a una URL que su Policy debería bloquear — indica que el
seeder de roles no se corrió igual que en desarrollo, o que el rol del
usuario de prueba quedó mal asignado.

---

## 3. Dashboard

- [ ] Carga sin error tras el login; header con identidad ATENEA
      (`BarChart3`, "Estadística APS").
- [ ] Tarjeta de bienvenida muestra el nombre y badge de rol del usuario
      logueado con el color correcto (Superadmin=violeta, Administrador=azul,
      Analista/Estadística APS=índigo).
- [ ] Accesos rápidos visibles coinciden con los módulos permitidos para el
      rol actual (ver matriz de la sección 2) — un `Analista` no debe ver
      accesos directos a Usuarios o Auditoría.
- [ ] Ningún acceso rápido apunta a una ruta 404 (probar cada botón al
      menos una vez tras el despliegue, ya que rutas nuevas del build
      pueden no coincidir con el `dist/` servido si el build fue parcial).

---

## 4. Usuarios

*(Solo Superadmin/Administrador — ver sección 2)*

- [ ] Listado carga con paginación y filtros funcionando (rol, estado).
- [ ] Crear usuario nuevo con rol asignado → aparece en el listado
      inmediatamente, puede iniciar sesión con la contraseña definida.
- [ ] Editar usuario existente (cambiar rol, activar/desactivar) → cambios
      persisten tras recargar la página.
- [ ] Desactivar un usuario → ese usuario no puede volver a iniciar sesión
      (probar login real, no solo el toggle visual).
- [ ] Intentar eliminar/desactivar al propio usuario admin logueado → debe
      estar bloqueado o advertido (evitar que el operador se bloquee a sí
      mismo).

---

## 5. Cargas REM

- [ ] Listado carga con filtros por centro de salud, serie, período y
      estado — confirmar que un usuario ve **solo** las cargas de su(s)
      centro(s) de salud asignado(s), no las de toda la red (filtrado por
      centro de salud reciente — verificar explícitamente, es una regla de
      negocio nueva).
- [ ] Subir un archivo `.xlsx`/`.xlsm` real (no de prueba sintética) desde
      la interfaz → el estado avanza de `pending` → `validating` → un
      estado final (`success`, `with_errors` o `rejected`) **sin quedarse
      indefinidamente en `validating`**.
- [ ] Si el archivo tiene errores esperados, el estado final es
      `with_errors` (no `success` falso-positivo) y el detalle de
      validación lista errores coherentes con el contenido real del
      archivo.
- [ ] "Ver resultado" abre el resumen de validación con cumplimiento (%),
      desglose por formulario y enlace a errores — sin pantalla en blanco.
- [ ] Badge de estado y cumplimiento usan los colores esperados (verde
      éxito, ámbar con observaciones, rojo rechazado).

**⚠️ Señal de alerta:** toda carga nueva se queda en `validating` para
siempre → el worker de colas no está corriendo (ver sección 11). Toda carga
nueva cae en `rejected` inmediatamente → no hay estructura REM `active`
para esa serie/período (ver sección 12).

---

## 6. Calibración REM

- [ ] Dashboard de calibración lista las plantillas/series disponibles con
      métricas (sin tarjetas vacías o en blanco por el token `bg-card`
      roto — deben verse con fondo blanco y borde visible).
- [ ] Navegar plantilla → serie → hoja → sección sin error 404 en ningún
      nivel del drill-down.
- [ ] Dentro de una sección: la matriz de calibración carga filas/columnas
      reales (no vacía) para al menos una sección con datos certificados.
- [ ] Cambiar el estado de calibración de una fila/celda (Superadmin o
      Analista, según sección 2) → persiste al recargar.
- [ ] Exportar (si aplica en la sección) genera el archivo sin error.

---

## 7. Criterios funcionales

- [ ] Catálogo de reglas funcionales carga con filtros por hoja/tipo/estado.
- [ ] Entrar a una sección con reglas pendientes de revisión funcional →
      "Asistente de Revisión" abre y permite completar el flujo de 2 pasos
      (identificación de fila + decisión) sin perder el estado al navegar
      entre pasos.
- [ ] Guardar una decisión funcional → el estado de la regla cambia
      (Pendiente → Certificada/Requiere revisión) y persiste.
- [ ] Historial de decisiones (log dentro del asistente) muestra el cambio
      recién guardado con fecha/usuario correctos.

---

## 8. Auditoría

*(Solo Superadmin/Administrador — ver sección 2)*

- [ ] Listado de actividad carga con filtros (evento, entidad, rango de
      fechas).
- [ ] Realizar una acción trazable en otro módulo durante esta validación
      (ej. crear un centro de salud) → aparece en Auditoría en segundos,
      con el evento (`Creado`), entidad y usuario correctos.
- [ ] Abrir el detalle de un registro → muestra el diff (`old`/`new`) de
      forma legible, sin error en el modal.
- [ ] Filtro por rango de fechas acota correctamente los resultados.

---

## 9. Centros de Salud

*(Solo Superadmin/Administrador — ver sección 2)*

- [ ] Listado carga con todos los centros restaurados desde el dump de
      producción (comparar cantidad con lo esperado, no debe verse vacío
      ni con solo 1-2 registros de seeder).
- [ ] Crear centro nuevo → aparece de inmediato en el listado y queda
      disponible como opción de filtro en Cargas REM.
- [ ] Editar centro (nombre, tipo, dirección) → persiste.
- [ ] Alternar Activo/Inactivo → un centro Inactivo deja de ofrecerse como
      opción al crear una nueva carga REM (validar la regla de negocio, no
      solo el toggle visual).

---

## 10. Motor de Reglas

- [ ] **Dashboard del motor:** métricas de estado (habilitado/deshabilitado,
      modo), reglas activas, estructuras, ejecuciones — números coherentes
      con **764 reglas / 859 bindings** restaurados (no 529, que indicaría
      que solo corrió el seeder base sin el dump de producción — ver
      `CHECKLIST_DESPLIEGUE_PRODUCCION.md` punto 8).
- [ ] **Reglas:** listado con filtros (tipo, estado, severidad, fuente)
      funciona; abrir el detalle de una regla muestra versiones,
      relaciones (bindings) e historial de ejecución sin error.
- [ ] **Relaciones de reglas (bindings):** listado y filtros funcionan;
      detalle de un binding enlaza correctamente a su regla y estructura.
- [ ] **Estructuras REM:** listado muestra la estructura `active` (id
      esperado según el dump restaurado); detalle muestra árbol de
      formularios/secciones/campos expandible.
- [ ] **Historial de ejecución (logs):** cada carga REM procesada durante
      esta validación (sección 5) genera entradas nuevas aquí, con estado
      y tiempos de ejecución coherentes.
- [ ] **Comparación de resultados:** ejecutar una comparación real
      (estructura + archivo ya procesado) devuelve porcentaje de precisión
      y diferencias sin error 500.
- [ ] **Catálogo de certificación de reglas:** stats (pendientes,
      certificadas, requieren revisión) no están en 0/0/0/0 si ya se migró
      el catálogo certificado.
- [ ] **Configuración (Feature Flags):** solo editable por `Administrador`;
      valor de "Motor habilitado" coincide con lo que efectivamente se
      observa en el comportamiento de las cargas (si está deshabilitado,
      las cargas no deberían mostrar validación de reglas).

---

## 11. Queue jobs

- [ ] `sudo supervisorctl status esalud-queue-worker:*` → `RUNNING`, con
      `uptime` posterior al último `queue:restart` ejecutado tras el
      deploy.
- [ ] Confirmar que **no** se agregó `--tries` global al comando del
      worker (cada Job define el suyo: `ProcessRemUploadJob`=1,
      `ValidateRemUploadJob`=2, `ValidateWithEngineJob`=2).
- [ ] Subir un archivo real (sección 5) y observar en
      `storage/logs/worker.log` que las 3 fases del pipeline se ejecutan en
      orden sin excepciones no controladas:
      `ProcessRemUploadJob` → `ValidateRemUploadJob` → `ValidateWithEngineJob`.
- [ ] Forzar un job fallido (ej. archivo corrupto) → el estado de la carga
      refleja el fallo (`failed`/`rejected`) en vez de quedar colgado, y el
      error queda registrado en el log sin tumbar el worker completo.
- [ ] `php artisan queue:failed` no acumula jobs fallidos inesperados de
      cargas que en la interfaz aparecen como exitosas (indicaría un
      job duplicado corriendo en background sin reflejarse en la UI).

**⚠️ Señal de alerta:** worker `STOPPED`/`FATAL` en Supervisor → ninguna
carga avanzará nunca de `pending`; reiniciar con `supervisorctl restart` y
revisar `worker.log` para la causa raíz antes de continuar.

---

## 12. Base de datos

- [ ] `php artisan migrate:status` → todas las migraciones en `Ran`,
      ninguna pendiente.
- [ ] Estructura REM activa:
      ```bash
      php artisan tinker --execute="echo App\Domain\RemParser\Models\RemTemplateStructure::where('status','active')->first()->id;"
      ```
      coincide con el id esperado del dump restaurado (referencia: `36` en
      el checklist de despliegue).
- [ ] Conteo de reglas/bindings:
      ```bash
      php artisan tinker --execute="echo App\Domain\RuleEngine\Models\Rule::count() . ' reglas, ' . App\Domain\RuleEngine\Models\RuleBinding::count() . ' bindings';"
      ```
      → `764 reglas, 859 bindings` (no un número menor).
- [ ] Rol del admin:
      ```bash
      php artisan tinker --execute="echo App\Models\User::where('email','admin@esalud.cl')->first()->roles->pluck('name')->implode(', ');"
      ```
      → `Superadmin, Administrador`.
- [ ] Centros de salud restaurados: conteo coherente con producción real
      (no solo los 1-2 de seeder de desarrollo).
- [ ] `storage/app/private/certificacion/cell-data/` contiene `381`
      archivos (integridad del dump de certificación).
- [ ] Ninguna tabla clave queda vacía inesperadamente:
      ```bash
      php artisan tinker --execute="foreach(['rem_uploads','rem_templates','rem_template_structures','rem_rules','rem_rule_bindings','health_centers','users'] as $t) { echo $t.': '.DB::table($t)->count().PHP_EOL; }"
      ```

**⚠️ Señal de alerta:** cualquier conteo en 0 en una tabla que debería
tener datos migrados desde desarrollo (`rem_rules`, `health_centers`) →
el dump no se restauró o se restauró sobre la base equivocada.

---

## 13. Archivos REM reales

Esta sección es la prueba de aceptación final — valida el pipeline
completo contra infraestructura real, con datos reales, no sintéticos.

- [ ] Conseguir **al menos 2** archivos REM reales de distintas series
      (ej. una serie `A` y una `P` o `BS`) ya conocidos en desarrollo (con
      resultado esperado documentado: cumplimiento aproximado, cantidad de
      errores esperados).
- [ ] Subir el primero → confirmar que el resultado final (`success` /
      `with_errors`, % de cumplimiento, cantidad de errores) coincide
      razonablemente con lo observado en desarrollo para el mismo archivo
      (una discrepancia grande indica reglas/estructura desincronizadas
      entre ambientes).
- [ ] Subir el segundo (serie distinta) → mismo criterio.
- [ ] Confirmar que el archivo subido queda accesible/descargable desde la
      interfaz después de procesado (si esa función existe en Cargas REM).
- [ ] Revisar `storage/app/rem-uploads/` en el servidor → el archivo físico
      existe con el nombre/hash esperado (confirma permisos de escritura
      correctos, sección 15 del checklist de despliegue).
- [ ] Repetir la subida del **mismo archivo** una segunda vez (si el
      sistema lo permite) → verificar que no genera datos duplicados o
      inconsistentes en Auditoría/Motor de Reglas.

**⚠️ Señal de alerta:** el archivo se "procesa" pero el cumplimiento da
100% sospechosamente sobre un archivo que en desarrollo tenía errores
conocidos → ver sección 10 (reglas/bindings no restaurados correctamente).

---

## Resumen ejecutivo — semáforo de aceptación

| # | Módulo | Bloqueante si falla |
|---|---|---|
| 1 | Login | Sí — nada más puede validarse sin esto |
| 2 | Permisos por rol | Sí — riesgo de seguridad/acceso indebido |
| 3 | Dashboard | No — degradado aceptable si el resto funciona |
| 4 | Usuarios | Sí (si Administrador necesita gestionar personal ese día) |
| 5 | Cargas REM | Sí — es el flujo core del sistema |
| 6 | Calibración REM | Depende del ciclo de trabajo vigente |
| 7 | Criterios funcionales | Depende del ciclo de trabajo vigente |
| 8 | Auditoría | No bloqueante inmediato, sí para compliance |
| 9 | Centros de Salud | Sí si se necesita dar de alta un centro ese día |
| 10 | Motor de Reglas | Sí — sin esto, Cargas REM valida sin criterio real |
| 11 | Queue jobs | Sí — sin esto, ninguna carga REM progresa |
| 12 | Base de datos | Sí — condición previa a todo lo demás |
| 13 | Archivos REM reales | Sí — es la única prueba de punta a punta con datos reales |

**El despliegue se da por validado solo cuando las secciones 1, 2, 5, 10,
11, 12 y 13 están en verde.** Las demás (3, 4, 6, 7, 8, 9) deben revisarse
igual, pero un hallazgo aislado ahí no bloquea la operación del día si el
core (carga → validación → motor de reglas) funciona.

---

## Referencia rápida — síntoma → sección a revisar primero

| Síntoma | Sección |
|---|---|
| Login "funciona" pero la sesión no persiste | 1 |
| Un rol ve/accede a un módulo que no debería | 2 |
| Carga REM se queda en `validating` para siempre | 11 |
| Carga REM cae en `rejected` para toda serie/período | 12 |
| Compliance siempre 100%, ningún error detectado | 10, 12 |
| Auditoría no registra una acción recién hecha | 8 |
| Centro de salud inactivo sigue apareciendo como opción | 9 |
| Números de reglas/bindings menores a los esperados (764/859) | 12 |
| Resultado de un archivo real muy distinto al de desarrollo | 13 |
