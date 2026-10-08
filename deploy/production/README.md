# Donqer Lab production deployment

This deployment is intentionally isolated from the existing `educational-api`
Compose project. It adds its own PHP-FPM app, scheduler, Nginx web container,
PostgreSQL database, and persistent volumes. Only the web container joins the
existing `educational-api_backend` network so the edge Nginx can proxy requests
for `api-lab.donqer.com`.

## First deployment

```bash
cp .env.example .env
# Fill APP_KEY and DB_PASSWORD with generated secrets.
chmod 600 .env
SEED_DEMO_DATA=1 ./deploy.sh
```

The demo seed is opt-in because it recreates some demo relationships. It uses a
separate, non-running tools image containing Faker and the other development
dependencies required by the factories. The web app image remains free of
development dependencies. Normal deployments should run `./deploy.sh` without
`SEED_DEMO_DATA`.

`edge-nginx-default.conf` is the current edge configuration with the original
catch-all server kept first and the Lab server added after it. Keeping that
order is required so unknown and existing hosts continue to use
`educational-api`. Back up
`/opt/backend/docker/nginx/default.conf`, install this file in its place, rebuild
only the existing Nginx image, validate it with `nginx -t`, and then recreate
that one container. `edge-nginx-server.conf` contains only the additive server
block; append it after the existing catch-all server when merging into a future
edge configuration.

## Verification

```bash
docker compose ps
docker compose exec -T web wget -qO- http://127.0.0.1/up
curl -H 'Host: api-lab.donqer.com' http://127.0.0.1/up
```

The public DNS/TLS layer must forward `api-lab.donqer.com` to the same load
balancer/origin used by the edge Nginx. The current AWS setup only needs:

1. A Route 53 Alias `A` record named `api-lab.donqer.com` targeting the same
   Application Load Balancer used by `api.codextracker.com`.
2. An ACM public certificate for `api-lab.donqer.com`, DNS-validated in the
   `donqer.com` hosted zone and attached to the existing HTTPS listener that
   serves `api.codextracker.com`.

The listener already forwards the new host to this server; no new target group
or listener rule is required. PostgreSQL is not published to the host.

## Automatic deployments with GitHub Actions

`.github/workflows/deploy-production.yml` deploys every push to `main` and can
also be started manually with `workflow_dispatch`. Deployments are serialized,
uploaded as checksum-verified Git archives, and installed as immutable release
directories. The production `.env`, PostgreSQL volume, and application storage
volume remain only on the server.

The server uses a dedicated SSH key with a forced command. That key cannot open
an interactive shell or run arbitrary SSH commands. Install the trusted scripts
and runtime configuration once:

```bash
install -d -m 0755 /opt/backend-lab/bin
install -d -m 0755 /opt/backend-lab/runtime
install -m 0755 deploy-release.sh /opt/backend-lab/bin/deploy-release
install -m 0755 github-actions-entrypoint.sh /opt/backend-lab/bin/github-actions-entrypoint
install -m 0644 compose.yaml /opt/backend-lab/runtime/compose.yaml
install -m 0600 .env /opt/backend-lab/runtime/.env
```

Generate a dedicated Ed25519 key and add its public key to
`/home/ubuntu/.ssh/authorized_keys` with this restriction:

```text
restrict,command="/opt/backend-lab/bin/github-actions-entrypoint" ssh-ed25519 PUBLIC_KEY github-actions-lab-backend-deploy
```

Create a GitHub environment named `production` in the backend repository and
add these environment secrets:

- `PRODUCTION_SSH_HOST`: the server hostname or IP.
- `PRODUCTION_SSH_USER`: `ubuntu`.
- `PRODUCTION_SSH_KEY`: the complete dedicated private key.
- `PRODUCTION_SSH_KNOWN_HOSTS`: hashed `ssh-keyscan` output for the server.

The trusted deployment script builds the new images, starts PostgreSQL without
recreating its volume, runs `php artisan migrate --force`, replaces only the Lab
application containers, and verifies both the internal container and public
edge route. If a later step fails, it restores the previous application images.
Database migrations are intentionally not reversed automatically, so production
migrations must remain backward-compatible during a deployment.
