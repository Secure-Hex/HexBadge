# Despliegue a producción

Mismo esquema que Fides: GitHub Actions arma el release con `git archive` y lo envía por SSH a una llave restringida en el servidor a un único comando forzado, `hexbadge-deploy` (`deploy/hexbadge-deploy.sh` es la fuente de verdad).

## Cómo ocurre un despliegue

1. Push a `main`. Corren en paralelo `Tests` (`tests.yml`) y `Release` (`release.yml`).
2. Si hay commits releasables, `release.yml` crea el commit `chore(release): vX.Y.Z`, el tag y la GitHub Release.
3. El job `tests-passed` espera a que `Tests` termine en verde sobre el commit que disparó el release. Si falla o no existe, no hay deploy.
4. El job `deploy` llama a `deploy.yml` (environment `production`, concurrencia `hexbadge-production`): verifica que el tag sea alcanzable desde `main`, arma el artefacto y lo envía a `root@securehex.cl` con la versión como único argumento.

El artefacto contiene solo `apps config database lib scripts src storage VERSION MIGRATION-PROD.sql CHANGELOG.md`. La lista vive en dos lugares que deben coincidir: el paso `Build artifact` de `deploy.yml` y `ALLOWED_TOP` en `deploy/hexbadge-deploy.sh`. `docker/`, `docs/`, `tests/` y `deploy/` nunca llegan al servidor.

## Qué hace `hexbadge-deploy` en el servidor

1. Acepta solo un argumento `vX.Y.Z` (si no, sale con código 2) y toma el lock `/run/hexbadge-deploy.lock`.
2. Valida el tar.gz (máx. 50 MB comprimido, 200 MB descomprimido, 20000 entradas; sin rutas absolutas, `..`, enlaces ni entradas de primer nivel fuera de la lista). El `VERSION` del artefacto debe coincidir con el tag.
3. **Respaldo** en `/root/backups/hexbadge-deploy-<versión>-<fecha>/`: código (`app.tar.gz`, sin las rutas protegidas) y `mariadb-dump` de la base (`db.sql.gz`) desde `securehex-mariadb`, con el usuario de la app leído de `.env`.
4. **Migración antes del cambio de código**: aplica `MIGRATION-PROD.sql` del release solo si difiere del que está en el servidor. Si falla, el despliegue se detiene sin tocar el código.
5. **Sincronización**: `rsync --delete` a `<volumen>/hexbadge`. Rutas protegidas (`PROTECTED` en el script): `.env`, `.env*`, `storage/`, `.git/`, `apps/*/public/uploads/` y `apps/*/public/.well-known/`. Cualquier otra entrada que no venga en el release se elimina. Lo nuevo que traiga el release dentro de `storage/` o `uploads/` (por ejemplo `storage/oidc/`) se agrega sin pisar nada: carpetas a nombre de `www-data`, archivos de `root` en modo 644.
6. `php -l` de los archivos desplegados (fuera de `lib/`) dentro de `securehex-badge` y **health check**: `https://badge.securehex.cl/login` responde 200 y `https://earner.securehex.cl/` responde 200 mostrando `Versión X.Y.Z` (5 intentos, cada 3 s).
7. Si algo falla después de iniciar la sincronización, restaura el código desde el respaldo de esta ejecución y vuelve a revisar la salud con la versión anterior. **Las migraciones no se revierten.**
8. Retención: 10 respaldos de despliegues exitosos y, por separado, 5 de ejecuciones fallidas (archivo `OUTCOME`).

Bitácora: salida del job en GitHub Actions y `/var/log/hexbadge-deploy.log` (cada ejecución termina con `RESULT:`).

## Regla de migraciones

La migración corre antes de cambiar el código, así que durante unos segundos el código anterior funciona sobre el esquema nuevo. Por eso un release solo puede **agregar** (tablas, columnas nullable o con default, índices); eliminar o renombrar va en un release posterior, cuando ningún código desplegado lo use.

`MIGRATION-PROD.sql` es acumulativo e idempotente en esquema, pero incluye rellenos de datos que vuelven a ejecutarse completos cada vez que el archivo cambia. Todo bloque nuevo debe ser seguro de repetir sobre datos actuales de producción.

## Prueba local

`deploy/test-hexbadge-deploy.sh [carpeta]` ejecuta el script contra un árbol de prueba (v1.3.0 como "en vivo", v1.4.0 como release) con las variables `HEXBADGE_DEPLOY_*` (solo para pruebas; sshd no las pasa al comando forzado) y stubs de `docker` y `curl`. Cubre versiones inválidas, artefactos rechazados, rollback por health check y por `php -l`, preservación de `.env` y datos, y la siembra de `storage/oidc`.

## Configuración inicial (una sola vez)

Requisitos en el servidor (ya presentes): `bash`, `flock`, `rsync`, `tar`, `gzip`, `curl`, `docker`.

1. Instalar el comando forzado (desde el repositorio):

   ```bash
   scp deploy/hexbadge-deploy.sh root@securehex.cl:/usr/local/sbin/hexbadge-deploy
   ssh root@securehex.cl 'chown root:root /usr/local/sbin/hexbadge-deploy && chmod 700 /usr/local/sbin/hexbadge-deploy'
   ```

2. Crear una llave dedicada, fuera de cualquier repositorio:

   ```bash
   K=$(mktemp -d)
   ssh-keygen -t ed25519 -N '' -C hexbadge-deploy@github-actions -f "$K/hexbadge-deploy-key"
   ```

3. Agregar a `/root/.ssh/authorized_keys` (reemplazar `AAAA...` por el contenido de `$K/hexbadge-deploy-key.pub`):

   ```text
   restrict,command="/usr/local/sbin/hexbadge-deploy" ssh-ed25519 AAAA... hexbadge-deploy@github-actions
   ```

4. `known_hosts` verificado: la misma línea que usa Fides (`ssh-keygen -F securehex.cl` en el equipo del operador), o `ssh-keyscan -t ed25519 securehex.cl` comparando su huella con `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` en el servidor.
5. Crear el environment `production` restringido a `main`:

   ```bash
   gh api -X PUT repos/Secure-Hex/HexBadge/environments/production \
     -F 'deployment_branch_policy[protected_branches]=false' \
     -F 'deployment_branch_policy[custom_branch_policies]=true'
   gh api -X POST repos/Secure-Hex/HexBadge/environments/production/deployment-branch-policies \
     -f name=main -f type=branch
   ```

6. Secrets del environment:

   ```bash
   gh secret set HEXBADGE_DEPLOY_SSH_KEY --env production --repo Secure-Hex/HexBadge < "$K/hexbadge-deploy-key"
   gh secret set HEXBADGE_DEPLOY_KNOWN_HOSTS --env production --repo Secure-Hex/HexBadge --body "<línea del paso 4>"
   ```

7. Destruir la llave local: `shred -u "$K"/hexbadge-deploy-key* && rmdir "$K"`.
8. Verificar el primer despliegue: job `deploy` en verde, última línea de `/var/log/hexbadge-deploy.log` = `RESULT: ok` y la versión nueva en el pie de `https://earner.securehex.cl/`.

## Rollback

- **Redesplegar un tag anterior**: Actions → Deploy → Run workflow (rama `main`), `version` = `vX.Y.Z`. Usa el mismo respaldo y health check; no vuelve a correr los tests (ese tag ya pasó por ellos). Las migraciones aplicadas siguen aplicadas.
- **Restaurar desde respaldo** (en el servidor, como root). Las exclusiones son las de `PROTECTED` en el script; si cambian allí, cambiarlas aquí:

```bash
B=/root/backups/hexbadge-deploy-vX.Y.Z-AAAAMMDDTHHMMSSZ   # respaldo tomado antes de ese despliegue
A=/var/lib/docker/volumes/securehex_apps/_data/hexbadge
R=/root/restore-hexbadge
exec 9>/run/hexbadge-deploy.lock && flock 9                # nadie más despliega mientras tanto
rm -rf "$R" && mkdir -p "$R" && tar -xzf "$B/app.tar.gz" -C "$R"
rsync -a --delete --chmod=D755,F644 --exclude=/.env --exclude='/.env*' --exclude=/storage/ --exclude=/.git/ \
  --exclude='/apps/*/public/uploads/' --exclude='/apps/*/public/.well-known/' "$R/" "$A/"
# Base de datos, solo si es imprescindible (pisa los datos posteriores al respaldo).
# La contraseña de root vive dentro del contenedor y nunca pasa por la línea de comandos:
# gunzip -c "$B/db.sql.gz" | docker exec -i securehex-mariadb sh -c 'MYSQL_PWD=$MARIADB_ROOT_PASSWORD exec mariadb -uroot <base>'
rm -rf "$R"
curl -fsS -o /dev/null -w '%{http_code}\n' https://badge.securehex.cl/login   # debe responder 200
exec 9>&-
```

Restaurar el código no revierte migraciones.

## Rotación de la llave

1. Generar una llave nueva (paso 2) y agregar su línea a `authorized_keys` junto a la anterior.
2. Actualizar `HEXBADGE_DEPLOY_SSH_KEY`, destruir la copia local y verificar un despliegue (por ejemplo, redesplegando la versión actual).
3. Eliminar la línea antigua. Ante sospecha de filtración, eliminarla de inmediato.
