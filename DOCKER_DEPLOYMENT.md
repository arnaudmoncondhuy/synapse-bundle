# 🐳 Synapse Monorepo - Docker Deployment Guide

## Current Situation
- basile.lab.bray-numerique.fr runs on **Docker**
- Error 500 on `/synapse/` route
- Monorepo needs to be deployed in the Docker container

---

## Quick Fix for Docker

### 1. Access Docker Container
```bash
# List running containers
docker ps | grep basile

# Enter the container
docker exec -it <container_name> bash
# or
docker exec -it <container_id> bash
```

### 2. Check Monorepo in Container
```bash
# Inside container, check project structure
ls -la /app/packages/
# Should show: core/  admin/  chat/

# If NOT found - monorepo not in container!
```

### 3. Copy Monorepo into Container

**Option A: From host to container**
```bash
# From host machine
docker cp /home/ubuntu/stacks/synapse-bundle/packages/core <container>:/app/packages/core
docker cp /home/ubuntu/stacks/synapse-bundle/packages/admin <container>:/app/packages/admin
docker cp /home/ubuntu/stacks/synapse-bundle/packages/chat <container>:/app/packages/chat
```

**Option B: Mount volumes in docker-compose.yml**
```yaml
version: '3.8'
services:
  app:
    image: php:8.2-fpm
    volumes:
      # Mount monorepo
      - /home/ubuntu/stacks/synapse-bundle/packages:/app/packages
      # Or mount entire project
      - /home/ubuntu/stacks/basile:/app
```

**Option C: Rebuild Docker image with monorepo**
```dockerfile
# Dockerfile
FROM php:8.2-fpm

WORKDIR /app

# Copy monorepo packages
COPY packages/ /app/packages/

# Install dependencies
RUN cd /app && composer install --no-dev

RUN chmod -R 775 /app/var
```

### 4. Install Dependencies in Container
```bash
# Inside container
cd /app

# Install composer dependencies
composer install --no-dev --optimize-autoloader

# Verify symlinks
ls -la vendor/arnaudmoncondhuy/
# Should show 3 symlinks
```

### 5. Clear Cache in Container
```bash
# Inside container
rm -rf var/cache/*
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

### 6. Fix Permissions in Container
```bash
# Inside container
chmod -R 775 var/
chmod -R 775 public/
```

### 7. Check Logs in Container
```bash
# Inside container - view application logs
tail -100 var/log/prod.log

# Or from host - see container logs
docker logs <container_name> --tail 100
```

### 8. Verify Routes in Container
```bash
# Inside container
php bin/console debug:router | grep synapse | wc -l
# Should show: 66
```

---

## Docker Compose Example

```yaml
version: '3.8'

services:
  app:
    image: php:8.2-fpm-alpine
    container_name: synapse-app
    working_dir: /app

    volumes:
      # Mount monorepo packages
      - /home/ubuntu/stacks/synapse-bundle/packages:/app/packages:ro
      # Mount app directories
      - /home/ubuntu/stacks/basile:/app
      - /app/vendor  # Cache vendor
      - /app/var     # Cache var directory

    environment:
      APP_ENV: prod
      APP_DEBUG: 0
      DATABASE_URL: "postgresql://user:pass@postgres:5432/synapse"

    depends_on:
      - postgres

  postgres:
    image: postgres:15-alpine
    container_name: synapse-postgres
    environment:
      POSTGRES_DB: synapse
      POSTGRES_USER: synapse
      POSTGRES_PASSWORD: secure_password
    volumes:
      - postgres_data:/var/lib/postgresql/data

  nginx:
    image: nginx:alpine
    container_name: synapse-nginx
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /etc/nginx/conf.d:/etc/nginx/conf.d:ro
      - /home/ubuntu/stacks/basile/public:/app/public:ro
    depends_on:
      - app

volumes:
  postgres_data:
```

---

## Dockerfile Example

```dockerfile
FROM php:8.2-fpm-alpine

WORKDIR /app

# Install extensions
RUN docker-php-ext-install pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy project
COPY . /app/

# Copy monorepo packages
COPY --from=source /home/ubuntu/stacks/synapse-bundle/packages /app/packages

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Fix permissions
RUN chmod -R 775 var/ public/

CMD ["php-fpm"]
```

---

## Troubleshooting Docker

### Error: "packages" directory not found
```bash
# Check what's in /app
docker exec <container> ls -la /app

# Solution: Mount or copy packages directory
docker cp /path/to/packages <container>:/app/packages
```

### Error: "vendor/arnaudmoncondhuy" symlinks broken
```bash
# Inside container
cd /app
composer install --no-dev

# Verify
ls -la vendor/arnaudmoncondhuy/
```

### Error 500 still after deployment
```bash
# Check container logs
docker logs <container> --tail 200

# Check application logs
docker exec <container> tail -100 var/log/prod.log

# Check nginx/php error logs
docker logs <nginx_container> --tail 100
```

### Permissions issues
```bash
# Inside container
chmod -R 775 var/
chmod -R 775 public/

# Or from host
docker exec <container> chmod -R 775 /app/var
```

---

## Health Check

```bash
# Test that monorepo is working in container
docker exec <container> php bin/console debug:router | grep synapse | wc -l
# Should return: 66

# Test that routes are responding
docker exec <container> php bin/console debug:container | grep -i synapse | wc -l
# Should return: 50+
```

---

## Deployment Checklist for Docker

- [ ] Container is running
- [ ] SSH into container or use `docker exec`
- [ ] Verify `/app/packages/` directory exists
- [ ] Run `composer install --no-dev`
- [ ] Verify symlinks in `vendor/arnaudmoncondhuy/`
- [ ] Set permissions: `chmod -R 775 var/`
- [ ] Clear cache: `rm -rf var/cache/*`
- [ ] Warm cache: `php bin/console cache:warmup --env=prod`
- [ ] Check routes: `php bin/console debug:router | grep synapse | wc -l`
- [ ] Check logs: `tail -100 var/log/prod.log`
- [ ] Test URL: `curl https://basile.lab.bray-numerique.fr/synapse/admin-v2`
  (Should return 403, not 500)

---

## Docker Commands Reference

```bash
# List containers
docker ps -a

# Enter container
docker exec -it <container> bash

# Copy files to container
docker cp /local/path <container>:/container/path

# View container logs
docker logs <container> --tail 100 -f

# Rebuild container
docker-compose up --build

# Restart container
docker restart <container>

# Remove and recreate
docker-compose down && docker-compose up -d
```

---

## Expected Result

Once deployed in Docker:

```bash
$ docker exec <container> php bin/console debug:router | grep synapse | wc -l
66

$ curl -I https://basile.lab.bray-numerique.fr/synapse/admin-v2
HTTP/2 403

$ docker logs <container> --tail 20
[info] Handling request: GET /synapse/admin-v2
[info] Route matched: synapse_v2_admin_dashboard
# (No error 500 messages)
```

---

## Next Steps

1. **Access Docker container**
   ```bash
   docker exec -it <container> bash
   ```

2. **Verify monorepo presence**
   ```bash
   ls -la /app/packages/
   ```

3. **Install/update dependencies**
   ```bash
   cd /app && composer install --no-dev
   ```

4. **Clear and warm cache**
   ```bash
   rm -rf var/cache/* && php bin/console cache:warmup --env=prod
   ```

5. **Verify routes**
   ```bash
   php bin/console debug:router | grep synapse | wc -l
   ```

6. **Check logs**
   ```bash
   tail -100 var/log/prod.log
   ```

---

**The error 500 should be resolved once the monorepo packages are properly deployed in the Docker container!**

For more info, share the output of:
- `docker ps` (list containers)
- `docker exec <container> ls -la /app/` (see what's in app directory)
- `docker exec <container> tail -100 var/log/prod.log` (see errors)
