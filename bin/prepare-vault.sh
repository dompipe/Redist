#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

# Create Redist's isolated staging vault outside the checked-out application tree.
# Run this from a trusted deployment shell as the dedicated vault/publisher user.
# The PHP request account should be a read-only member of the vault's reader group,
# not the owner of this tree.

if [[ "${REDIST_VAULT_ROOT:-}" != /* ]]; then
  echo "ERROR: Set REDIST_VAULT_ROOT to an absolute path outside the Redist checkout." >&2
  exit 2
fi

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
VAULT_ROOT="$REDIST_VAULT_ROOT"

# Resolve the parent before creating the target so relative components and symlinks
# cannot make an apparently external path land inside the checkout.
mkdir -p -- "$(dirname -- "$VAULT_ROOT")"
PARENT_REAL="$(cd -- "$(dirname -- "$VAULT_ROOT")" && pwd -P)"
VAULT_ROOT="$PARENT_REAL/$(basename -- "$VAULT_ROOT")"

case "$VAULT_ROOT/" in
  "$REPO_ROOT/"|"$REPO_ROOT/"*) 
    echo "ERROR: Vault must be outside the Redist checkout/document root: $REPO_ROOT" >&2
    exit 2
    ;;
esac

if [[ "$VAULT_ROOT" == "/" || "$VAULT_ROOT" == "$HOME" ]]; then
  echo "ERROR: Refusing unsafe vault root: $VAULT_ROOT" >&2
  exit 2
fi

mkdir -p -- "$VAULT_ROOT"
VAULT_REAL="$(cd -- "$VAULT_ROOT" && pwd -P)"
case "$VAULT_REAL/" in
  "$REPO_ROOT/"|"$REPO_ROOT/"*)
    echo "ERROR: Resolved vault path is inside the Redist checkout: $VAULT_REAL" >&2
    exit 2
    ;;
esac

# The owner of these directories is the only writer. The web/PHP account should
# receive read/traverse access through a dedicated group for verified/published
# material only. This script does not change ownership or group membership.
for dir in incoming verified published quarantine index logs; do
  mkdir -p -- "$VAULT_REAL/$dir"
done

chmod 0750 "$VAULT_REAL"
chmod 0700 "$VAULT_REAL/incoming"
chmod 0750 "$VAULT_REAL/verified"
chmod 0750 "$VAULT_REAL/published"
chmod 0700 "$VAULT_REAL/quarantine"
chmod 0750 "$VAULT_REAL/index"
chmod 0750 "$VAULT_REAL/logs"

printf 'Redist vault prepared: %s\n' "$VAULT_REAL"
printf '\nDirectory modes:\n'
stat -c '%a %U:%G %n' "$VAULT_REAL" "$VAULT_REAL"/*

cat <<'NOTICE'

NEXT STEPS (required):
  1. Ensure this tree is outside the web document root and owned by a dedicated
     publisher/verifier account.
  2. Grant the PHP/request account read + directory-traverse access only to
     verified/, published/, and the minimum required index metadata.
  3. Do NOT add the PHP/request account to the owner/writer group. Do not grant
     it write permission on the vault root or any parent directory.
  4. Keep GPG private keys outside this vault and outside the web root. GPG
     verification should use a separately controlled public-key/keyring path.
  5. Publish by copying/recreating validated artifacts; never modify source files
     in place. Use no-overwrite installation for immutable/versioned artifacts.
  6. If your host cannot separate the publisher and PHP Unix users/groups, this
     layout alone does not provide a strong privilege boundary. Use a separate
     account/service or hosting boundary rather than relying on chmod alone.

This script prepares directories; it does not configure ownership, ACLs, GPG,
web-server aliases, token validation, or artifact publication.
NOTICE
