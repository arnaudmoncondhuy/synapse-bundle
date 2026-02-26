# 🚀 Synapse Monorepo - Production Deployment on OVH VPS

## Status
🔴 **Error 500 detected on basile.lab.bray-numerique.fr**

## Quick Fix

### 1. SSH into OVH Server
```bash
ssh user@basile.lab.bray-numerique.fr
# or
ssh user@51.83.77.98
```

### 2. Find Project Directory
```bash
# Check where the project is hosted
find /var/www -name "basile" -o -name "synapse-bundle" 2>/dev/null

# Common locations:
ls -la /var/www/
ls -la /home/*/basile/
ls -la /home/*/synapse-bundle/
```

### 3. Verify Monorepo Structure
```bash
# Navigate to project
cd /path/to/basile  # or /path/to/synapse-app

# Check if packages exist
ls -la packages/core packages/admin packages/chat 2>/dev/null

# If NOT found - the monorepo wasn't deployed!
# Solution: Git clone or copy from /home/ubuntu/stacks/synapse-bundle
```

### 4. Install/Update Dependencies
```bash
cd /path/to/project

# Composer install with symlinks
composer install --no-dev --optimize-autoloader

# Verify symlinks
ls -la vendor/arnaudmoncondhuy/
# Should show 3 symlinks to packages/
```

### 5. Check Error Logs
```bash
# Application logs
tail -100 var/log/prod.log 2>/dev/null || tail -100 var/log/dev.log

# Nginx error log
tail -100 /var/log/nginx/error.log | grep basile

# PHP-FPM error log
tail -100 /var/log/php-fpm.log
```

### 6. Fix Permissions
```bash
# Set correct ownership
sudo chown -R www-data:www-data /path/to/project

# Set correct permissions
sudo chmod -R 755 /path/to/project
sudo chmod -R 775 /path/to/project/var
sudo chmod -R 775 /path/to/project/public
```

### 7. Clear Cache
```bash
cd /path/to/project

# Clear all cache
rm -rf var/cache/*

# Warm up cache
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

### 8. Verify Installation
```bash
# Check bundles
php bin/console debug:container | grep -i synapse | head -10

# Check routes
php bin/console debug:router | grep synapse | wc -l
# Should show: 20+
```

## Full Deployment Checklist

- [ ] SSH into OVH server
- [ ] Find project directory
- [ ] Verify `packages/core`, `packages/admin`, `packages/chat` exist
- [ ] Run `composer install --no-dev`
- [ ] Verify symlinks in `vendor/arnaudmoncondhuy/`
- [ ] Set ownership to www-data
- [ ] Set permissions (755 for files, 775 for var/)
- [ ] Clear cache
- [ ] Check error logs
- [ ] Run diagnostic:
  ```bash
  cd /path/to/project
  php bin/console debug:router | grep synapse | wc -l
  ```
  (Should return 20+)
- [ ] Test URL: `https://basile.lab.bray-numerique.fr/synapse/admin-v2`
  (Should return 403, not 500)

## Troubleshooting

### Error 500 Persists

1. **Check detailed error logs**:
```bash
tail -200 var/log/prod.log | grep -A 20 "ERROR\|CRITICAL"
```

2. **Test autoloader directly**:
```bash
php -r "require 'vendor/autoload.php'; echo 'OK';"
```

3. **Check if monorepo was deployed**:
```bash
ls -la packages/
# If "No such file" - DEPLOY THE MONOREPO FIRST
```

4. **Verify Nginx configuration**:
```bash
grep -A 20 "basile.lab.bray-numerique.fr" /etc/nginx/sites-enabled/*
# Root should point to /path/to/project/public
```

### Symlinks Not Working

```bash
# Delete and recreate vendor
rm -rf vendor composer.lock
composer install --no-dev

# Verify
ls -la vendor/arnaudmoncondhuy/
```

### Permission Denied

```bash
# Fix permissions
sudo chown -R www-data:www-data /path/to/project
sudo find /path/to/project -type f -exec chmod 644 {} \;
sudo find /path/to/project -type d -exec chmod 755 {} \;
sudo chmod -R 775 /path/to/project/var
```

## Expected Result

After completing deployment:

```bash
$ php bin/console debug:router | grep synapse | wc -l
66

$ curl -I https://basile.lab.bray-numerique.fr/synapse/admin-v2
HTTP/2 403
# 403 = Permission denied (good, not 500!)

$ tail var/log/prod.log
# No PHP errors, only routing errors (which are expected)
```

## Next Steps

Once deployment is verified:

1. **Database Setup**:
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
```

2. **Configure LLM Providers**:
```bash
# Set credentials via admin panel
# https://basile.lab.bray-numerique.fr/synapse/admin-v2/intelligence/modeles
```

3. **Test Chat API**:
```bash
curl -X POST https://basile.lab.bray-numerique.fr/chat/message \
  -H "Content-Type: application/json" \
  -d '{"prompt": "Hello"}'
```

## Support

If error 500 persists after deployment:

1. Share output of:
```bash
tail -100 var/log/prod.log
php bin/console debug:router | grep synapse | head -5
ls -la vendor/arnaudmoncondhuy/
```

2. Verify project directory structure
3. Check Nginx error logs

---

**Remember**: The monorepo MUST be deployed (with `packages/core/`, `packages/admin/`, `packages/chat/` directories) for this to work!
