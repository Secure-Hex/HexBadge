#!/usr/bin/env bash
#
# Versionado semántico automático desde Conventional Commits.
#
#   scripts/release.sh              bump automático según los commits:
#                                     feat! / BREAKING CHANGE -> major
#                                     feat                    -> minor
#                                     el resto (fix, etc.)    -> patch
#   scripts/release.sh minor        fuerza el tipo de bump (major|minor|patch)
#   scripts/release.sh v2.3.0       fuerza una versión exacta
#   scripts/release.sh --dry-run    muestra qué haría, sin tocar nada
#
# Genera/actualiza CHANGELOG.md, sube APP_VERSION en config/app.php, crea el
# commit "chore(release): vX.Y.Z" y el tag anotado vX.Y.Z.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

DRY=0; FORCE_BUMP=""; FORCE_VER=""
for a in "$@"; do
  case "$a" in
    --dry-run)          DRY=1 ;;
    major|minor|patch)  FORCE_BUMP="$a" ;;
    v[0-9]*.[0-9]*)     FORCE_VER="${a#v}" ;;
    *) echo "arg desconocido: $a" >&2; exit 1 ;;
  esac
done

if [ "$DRY" -eq 0 ] && [ -n "$(git status --porcelain)" ]; then
  echo "✗ Hay cambios sin commitear. Commiteá o descartá antes de la release." >&2; exit 1
fi

LAST_TAG=$(git describe --tags --abbrev=0 2>/dev/null || echo "")
RANGE="${LAST_TAG:+$LAST_TAG..}HEAD"
if [ -z "$(git log $RANGE --format='%H' --no-merges)" ]; then
  echo "No hay commits nuevos desde ${LAST_TAG:-el inicio}. Nada que releasear."; exit 0
fi

# --- Calcular la nueva versión --------------------------------------------
BASE="${LAST_TAG#v}"; BASE="${BASE:-0.0.0}"
IFS='.' read -r MA MI PA <<< "$(printf '%s' "$BASE" | sed 's/[^0-9.].*//')"
MA=${MA:-0}; MI=${MI:-0}; PA=${PA:-0}

if [ -n "$FORCE_VER" ]; then
  NEW="$FORCE_VER"; BUMP="exacto"
else
  BUMP="$FORCE_BUMP"
  if [ -z "$BUMP" ]; then
    if git log $RANGE --format='%s%n%b' | grep -qE '(^|[[:space:]])BREAKING CHANGE|^[a-z]+(\([^)]+\))?!:'; then
      BUMP=major
    elif git log $RANGE --format='%s' | grep -qE '^feat(\([^)]+\))?!?:'; then
      BUMP=minor
    elif git log $RANGE --format='%s' | grep -qE '^(fix|perf|refactor)(\([^)]+\))?!?:'; then
      BUMP=patch
    else
      # Solo docs/chore/style/test/ci/build: no amerita una release.
      echo "Nada releasable desde ${LAST_TAG:-el inicio} (solo docs/chore/etc.)."
      exit 0
    fi
  fi
  case "$BUMP" in
    major) MA=$((MA+1)); MI=0; PA=0 ;;
    minor) MI=$((MI+1)); PA=0 ;;
    patch) PA=$((PA+1)) ;;
  esac
  NEW="$MA.$MI.$PA"
fi
TAG="v$NEW"

# --- Construir la entrada del changelog ------------------------------------
sec() { # $1 = regex de tipo, $2 = título
  local body
  body=$(git log $RANGE --format='%s%x09%h' --no-merges \
    | grep -E "$1" \
    | sed -E 's/^[a-z]+(\([^)]+\))?!?:[[:space:]]*/- /; s/\t/ (/; s/$/)/') || true
  if [ -n "$body" ]; then printf '\n### %s\n%s\n' "$2" "$body"; fi
}
others() {
  local body
  body=$(git log $RANGE --format='%s%x09%h' --no-merges \
    | grep -vE '^(feat|fix|perf|refactor|docs)(\(|!|:)' \
    | grep -vE '^chore\(release\)' \
    | sed -E 's/^[a-z]+(\([^)]+\))?!?:[[:space:]]*/- /; s/^([^-])/- \1/; s/\t/ (/; s/$/)/') || true
  if [ -n "$body" ]; then printf '\n### Otros\n%s\n' "$body"; fi
}

ENTRY="## [$NEW] - $(date +%Y-%m-%d)"
ENTRY+="$(sec '^feat(\(|!|:)'     'Nuevas funcionalidades')"
ENTRY+="$(sec '^fix(\(|!|:)'      'Correcciones')"
ENTRY+="$(sec '^perf(\(|!|:)'     'Rendimiento')"
ENTRY+="$(sec '^refactor(\(|!|:)' 'Refactor')"
ENTRY+="$(sec '^docs(\(|!|:)'     'Documentación')"
ENTRY+="$(others)"

echo "Última: ${LAST_TAG:-(ninguna)}   →   Nueva: $TAG   (bump: $BUMP)"

if [ "$DRY" -eq 1 ]; then
  echo "--- CHANGELOG (previsualización) ---"
  printf '%s\n' "$ENTRY"
  echo "--- (dry-run: no se tocó nada) ---"
  exit 0
fi

# --- Escribir CHANGELOG.md (prepend), versión, commit y tag ---------------
[ -f CHANGELOG.md ] || echo "# Changelog" > CHANGELOG.md
{ echo "# Changelog"; printf '\n%s\n' "$ENTRY"; tail -n +2 CHANGELOG.md; } > CHANGELOG.tmp
mv CHANGELOG.tmp CHANGELOG.md

sed -i -E "s/(env\('APP_VERSION', ')[^']*(')/\1${NEW}\2/" config/app.php

git add CHANGELOG.md config/app.php
git commit -q -m "chore(release): $TAG"
git tag -a "$TAG" -m "Release $TAG"

echo "✓ Release $TAG creada (commit + tag + CHANGELOG + APP_VERSION)."
echo "  Subí con:  git push --follow-tags origin main"
