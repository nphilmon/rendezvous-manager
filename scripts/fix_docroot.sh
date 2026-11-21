#!/usr/bin/env bash
# Réparation de la racine web d'un (sous-)domaine cPanel : la faire pointer sur public/ du dépôt.
# Usage : scripts/fix_docroot.sh [domaine] [dossier-public-relatif-au-home]
#   ex. : scripts/fix_docroot.sh agenda.nicolasphilmon.fr repositories/rendezvous-manager/public
# Étapes : 1) interface cPanel (uapi, plusieurs syntaxes selon la version) ; 2) si la racine enregistrée n'a pas changé, repli SANS rien supprimer :
# l'ancien dossier du domaine est RENOMMÉ (…​.old-AAAAMMJJHHMMSS) et remplacé par un lien symbolique vers public/ ; 3) contrôle HTTP.
set -u
DOMAIN="${1:-agenda.nicolasphilmon.fr}"
REL="${2:-repositories/rendezvous-manager/public}"
TARGET="$HOME/$REL"
DOCROOT_DIR="$HOME/$DOMAIN"
say() { printf '%s\n' "$*"; }

[ -f "$TARGET/index.php" ] || { say "ERREUR : $TARGET/index.php introuvable : mauvais chemin du dépôt."; exit 2; }

current() {  # racine enregistrée pour le domaine
  uapi DomainInfo domains_data format=json 2>/dev/null | grep -i -A12 "$DOMAIN" | grep -i documentroot | head -1 | sed 's/.*documentroot[": ]*//; s/[",]//g' | tr -d ' '
}
ok() { case "$(current)" in */"$REL") return 0;; *) return 1;; esac; }

if ! command -v uapi >/dev/null 2>&1; then say "uapi absent : passage direct au repli."; else
  say "== Racine actuelle : $(current)"
  if ok; then say "Déjà correcte."; else
    say "== Essai 1 : domain= + dir="
    uapi SubDomain changedocroot domain="$DOMAIN" dir="$REL" 2>&1 | grep -E "status|errors|-  " | head -5
    if ! ok; then
      say "== Essai 2 : subdomain= + rootdomain= + dir="
      uapi SubDomain changedocroot subdomain="${DOMAIN%%.*}" rootdomain="${DOMAIN#*.}" dir="$REL" 2>&1 | grep -E "status|errors|-  " | head -5
    fi
    if ! ok; then
      say "== Essai 3 : chemin absolu"
      uapi SubDomain changedocroot domain="$DOMAIN" dir="$TARGET" 2>&1 | grep -E "status|errors|-  " | head -5
    fi
    say "== Racine après essais : $(current)"
  fi
fi

if uapi DomainInfo domains_data format=json >/dev/null 2>&1 && ok; then
  say "RÉSULTAT : racine enregistrée par cPanel = $(current)"
else
  say "== Repli : lien symbolique (l'interface cPanel n'a pas pris la modification)"
  if [ -L "$DOCROOT_DIR" ]; then
    say "$DOCROOT_DIR est déjà un lien symbolique vers $(readlink "$DOCROOT_DIR")"
  elif [ -e "$DOCROOT_DIR" ]; then
    OLD="$DOCROOT_DIR.old-$(date +%Y%m%d%H%M%S)"
    mv "$DOCROOT_DIR" "$OLD" && ln -s "$TARGET" "$DOCROOT_DIR" && say "Ancien dossier conservé dans $OLD ; $DOCROOT_DIR -> $TARGET"
  else
    ln -s "$TARGET" "$DOCROOT_DIR" && say "$DOCROOT_DIR -> $TARGET"
  fi
fi

say "== Contrôle HTTP"
for p in /assets/css/app.css /login; do
  for scheme in https http; do
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$scheme://$DOMAIN$p" 2>/dev/null) || code=000
    printf '%s://%s%s -> %s\n' "$scheme" "$DOMAIN" "$p" "$code"
  done
done
say "Attendu : 200 pour le CSS ; /login : 200 ou 302 (vers /install tant que l'application n'est pas installée)."
