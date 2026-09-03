# Production Auto Deploy

WC Manager automatically deploys `main` to production through GitHub Actions once the SSH deployment secrets are configured.

Workflow:

```text
.github/workflows/deploy-production.yml
```

## Deployment behavior

Every push/merge to `main`:

1. Checks out the exact commit.
2. Runs `php -l` on every PHP file.
3. Verifies SSH connectivity and the deployment directory.
4. Uses `rsync` to upload the repository to production.
5. Preserves server-owned/sensitive data:
   - `config/config.php`
   - `public/uploads/`
   - `.deploy-backups/`
6. Backs up every replaced/deleted file under:

```text
<DEPLOY_PATH>/.deploy-backups/<UTC timestamp>/
```

7. Runs PHP lint again against the deployed `includes/` and `public/` trees.
8. Checks `https://manage.bajistyle.ir/login.php` (or `DEPLOY_HEALTHCHECK_URL` when configured).

The deployment job is skipped safely while required secrets are missing.

## Required GitHub Actions secrets

Repository → Settings → Secrets and variables → Actions → New repository secret

| Secret | Required | Description |
|---|---:|---|
| `DEPLOY_HOST` | yes | Production SSH hostname or IP |
| `DEPLOY_USER` | yes | Restricted SSH deployment user |
| `DEPLOY_PATH` | yes | Absolute WC Manager repository root on production |
| `DEPLOY_SSH_PRIVATE_KEY` | yes | Private key used only by GitHub Actions |
| `DEPLOY_SSH_KNOWN_HOSTS` | yes | Pinned SSH host key line(s) |
| `DEPLOY_PORT` | no | SSH port; defaults to `22` |
| `DEPLOY_HEALTHCHECK_URL` | no | Defaults to `https://manage.bajistyle.ir/login.php` |

Never commit any secret to this repository.

## Recommended server setup

Create a dedicated deployment key locally or on a trusted admin machine:

```bash
ssh-keygen -t ed25519 -C "github-actions-wc-manager" -f ./wc-manager-deploy-key
```

- Put the content of `wc-manager-deploy-key` in `DEPLOY_SSH_PRIVATE_KEY`.
- Add `wc-manager-deploy-key.pub` to the deployment user's `~/.ssh/authorized_keys` on production.
- Delete the local private key copy after the GitHub secret has been stored securely, if your key-management policy permits.

Generate the pinned host-key entry from a trusted network and verify its fingerprint against the server before storing it:

```bash
ssh-keyscan -p 22 -H YOUR_SERVER_HOST
```

Store the verified output in `DEPLOY_SSH_KNOWN_HOSTS`.

## Deployment user permissions

The deployment user only needs:

- SSH login with its deployment key.
- Read/write access to `DEPLOY_PATH`.
- Permission to create `DEPLOY_PATH/.deploy-backups/`.
- Access to the PHP CLI executable used by the remote lint check.

Root access is not required and is not recommended.

## Important path rule

`DEPLOY_PATH` must be the WC Manager repository root — the directory containing:

```text
config/
includes/
public/
sql/
```

Do not set it to only the `public/` directory.

## First production activation

After adding the secrets, open GitHub Actions → `Deploy production` and run `workflow_dispatch` once manually. Verify the run succeeds before relying on automatic deploys.

After the first successful run, every merge to `main` deploys automatically.
