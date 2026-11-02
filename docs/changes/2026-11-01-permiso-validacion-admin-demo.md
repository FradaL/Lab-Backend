# Validación de resultados con el administrador demo

`AdminDemoPermissionSeeder` concede también `results.validate` directamente a
`admin@donqerlab.test`, únicamente en el laboratorio demo principal (NIT
`000000000001`). No cambia la matriz general del rol `administrator`.

Con el conjunto demo ya instalado, ejecutar desde `laravel/`:

```sh
php artisan db:seed --class=AdminDemoPermissionSeeder
```

El seeder ya forma parte de `DatabaseSeeder`, por lo que la carga completa con
`php artisan db:seed` también incluye este permiso. Repetirlo no duplica
asignaciones ni elimina otros permisos. Solo admite `local` y `testing`, y exige
que el usuario y el laboratorio estén registrados mediante `DemoDataset`, con
membresía activa.

Para reproducir el flujo completo, también concede a esa cuenta los permisos
`orders.clinical_finalization.view`, `orders.exams.clinical_finalize`,
`orders.clinical_finalize`, `reports.issue`, `reports.view_history` y
`reports.download`, siempre en el laboratorio demo principal. Esto habilita la
consulta del cierre clínico, finalización de exámenes, cierre de orden, emisión,
historial y descarga de informes. No cambia la política clínica ni concede estos
permisos al resto de administradores.

Esta asignación resuelve el bloqueante «Se requiere el permiso de validación».
Conserva la política clínica del laboratorio: `permission_based` permite validar
con ese permiso; `professional_required` exige además una identidad profesional
autorizada. La política predeterminada para laboratorios nuevos sigue siendo
`professional_required`.

Verificación: `AdminDemoPermissionSeederTest` cubre
repetición, aislamiento entre usuarios y laboratorios, autorización efectiva,
conservación de roles y permisos previos, procedencia demo y rechazo en producción.
