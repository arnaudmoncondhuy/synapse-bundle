# 🚀 Synapse Monorepo - Production Deployment Guide

## Prerequisites

```bash
PHP 8.2+
Composer 2.0+
PostgreSQL 12+ (or MySQL 8+)
Nginx or Apache
```

## Step 1: Clone the Monorepo

```bash
cd /var/www
git clone <your-repo-url> synapse-app
cd synapse-app
```

## Step 2: Install Dependencies

```bash
# Install Composer dependencies
composer install --no-dev --optimize-autoloader

# Verify monorepo structure
ls -la packages/*/
# Should show: core/  admin/  chat/
```

## Step 3: Verify Symlinks

```bash
# Check vendor symlinks were created correctly
ls -la vendor/arnaudmoncondhuy/
# Should show:
#   synapse-core → ../../../synapse-bundle/packages/core
#   synapse-admin → ../../../synapse-bundle/packages/admin
#   synapse-chat → ../../../synapse-bundle/packages/chat
```

## Step 4: Environment Configuration

Create `.env.local`:

```bash
APP_ENV=prod
APP_SECRET=$(php -r 'echo bin2hex(random_bytes(16));')
DATABASE_URL="postgresql://user:password@localhost:5432/synapse_prod"
TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR
```

## Step 5: Database Setup

```bash
# Create database
php bin/console doctrine:database:create

# Run migrations
php bin/console doctrine:migrations:migrate -n

# Load fixtures (optional)
php bin/console doctrine:fixtures:load -n
```

## Step 6: Clear Cache

```bash
rm -rf var/cache/*
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

## Step 7: Configure Nginx

```nginx
server {
    listen 80;
    server_name basile.lab.bray-numerique.fr;
    
    root /var/www/synapse-app/public;
    
    location / {
        try_files $uri /index.php$is_args$args;
    }
    
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/var/run/php-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }
    
    location ~ \.php$ {
        return 404;
    }
}
```

## Step 8: Set Permissions

```bash
# Set ownership
chown -R www-data:www-data /var/www/synapse-app

# Set permissions
chmod -R 755 /var/www/synapse-app
chmod -R 775 /var/www/synapse-app/var
chmod -R 775 /var/www/synapse-app/public
```

## Step 9: Verify Installation

### Test Bundle Loading
```bash
php bin/console debug:container | grep synapse
# Should show synapse services
```

### Test Routes
```bash
php bin/console debug:router | grep synapse
# Should show 20+ synapse routes
```

### Test Application
```bash
curl https://basile.lab.bray-numerique.fr/synapse/admin-v2
# Should return 403 (permission denied, not 500)
```

## Troubleshooting

### 500 Error

1. **Check logs**:
```bash
tail -100 var/log/prod.log
tail -100 /var/log/php-fpm.log
tail -100 /var/log/nginx/error.log
```

2. **Verify file permissions**:
```bash
ls -la var/cache/
ls -la var/log/
# Should be writable by www-data
```

3. **Check autoloader**:
```bash
php -r "require 'vendor/autoload.php'; echo 'Autoloader OK';"
```

4. **Verify namespaces**:
```bash
php -r "require 'vendor/autoload.php'; 
echo class_exists('ArnaudMoncondhuy\\SynapseCore\\SynapseCoreBundle') ? 'Core OK' : 'Core FAIL';
echo ' ';
echo class_exists('ArnaudMoncondhuy\\SynapseAdmin\\SynapseAdminBundle') ? 'Admin OK' : 'Admin FAIL';
echo ' ';
echo class_exists('ArnaudMoncondhuy\\SynapseChat\\SynapseChatBundle') ? 'Chat OK' : 'Chat FAIL';"
```

### 404 on Routes

1. **Clear cache**:
```bash
rm -rf var/cache/*
php bin/console cache:clear --env=prod
```

2. **Verify routes**:
```bash
php bin/console debug:router | grep -i synapse
```

### Database Connection Error

1. **Test connection**:
```bash
php bin/console doctrine:database:create --if-not-exists
```

2. **Run migrations**:
```bash
php bin/console doctrine:migrations:migrate -n
```

## Production Checklist

- [ ] All packages installed via Composer
- [ ] Symlinks verified in vendor/arnaudmoncondhuy/
- [ ] Database migrations completed
- [ ] Cache cleared and warmed
- [ ] Permissions set correctly (www-data ownership)
- [ ] Nginx/Apache configured with correct root
- [ ] Environment variables configured in .env.local
- [ ] Logs are accessible for debugging
- [ ] Admin dashboard returns 403 (not 500/404)
- [ ] Routes discovered (20+ synapse routes)

## Performance Optimization

```bash
# Clear APCu cache if enabled
php bin/console cache:pool:clear cache.global_clearer

# Optimize autoloader
composer dump-autoload --optimize --no-dev

# Generate class map cache
php bin/console cache:clear --no-warmup
php bin/console cache:warmup
```

## Monitoring

Monitor these log files for issues:

```bash
# PHP errors
tail -f var/log/prod.log

# Webserver errors
tail -f /var/log/nginx/error.log  # or apache2/error.log

# PHP-FPM status
php-fpm -T  # Test configuration
```

## Support

If you encounter issues:

1. Check the logs in `var/log/prod.log`
2. Run `php bin/console debug:router` to verify routes
3. Verify the symlinks in `vendor/arnaudmoncondhuy/`
4. Check file permissions on `var/` and `public/`

---

**The Synapse Monorepo is production-ready when:**
- ✅ All 3 packages symlinked in vendor
- ✅ All routes discovered
- ✅ Database connected
- ✅ Cache warmed up
- ✅ Permissions correct
