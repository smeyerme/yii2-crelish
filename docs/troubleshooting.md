# Troubleshooting Crelish CMS

This guide covers common issues you might encounter when working with Crelish CMS and how to resolve them.

## Installation Issues

### Composer Dependencies

**Issue:** Installation fails with dependency conflicts.

**Solution:**
1. Make sure your PHP version matches the requirements (PHP 7.4+)
2. Update Composer: `composer self-update`
3. Clear Composer cache: `composer clear-cache`
4. Try installing with the `--ignore-platform-reqs` flag if you're sure your environment is compatible

### Database Migration Errors

**Issue:** Database migrations fail to run.

**Solution:**
1. Check your database connection settings in `config/db.php`
2. Ensure your database user has sufficient privileges
3. Run migrations with verbose output to identify specific errors:
   ```bash
   ./yii migrate --migrationPath=@vendor/giantbits/yii2-crelish/migrations --interactive=0 --verbose
   ```

## Admin Panel Access Issues

### Login Problems

**Issue:** Cannot log in to the admin panel.

**Solution:**
1. Verify you're using the correct credentials
2. Reset the admin password using the Yii2 console:
   ```bash
   ./yii crelish/user/reset-password admin newpassword
   ```
3. Check browser cookies and clear cache if necessary

### Blank Page or 500 Error

**Issue:** Admin panel shows a blank page or 500 error.

**Solution:**
1. Check your web server error logs for specific errors
2. Ensure `debug` mode is enabled in your `web.php` configuration:
   ```php
   'bootstrap' => ['debug'],
   'modules' => [
       'debug' => [
           'class' => 'yii\debug\Module',
           'allowedIPs' => ['127.0.0.1', '::1', '192.168.0.*']
       ],
   ],
   ```
3. Verify file permissions on storage directories

## Content Management Issues

### Content Not Saving

**Issue:** Content changes don't save or return validation errors.

**Solution:**
1. Check if all required fields are filled correctly
2. Look for validation errors in the response
3. Verify that your content type definition is valid
4. Check for field length or format restrictions

### Media Upload Problems

**Issue:** Cannot upload media files.

**Solution:**
1. Check file size limits in your PHP configuration (`upload_max_filesize` and `post_max_size` in php.ini)
2. Verify the upload directory permissions
3. Ensure the file type is allowed in your configuration
4. Check for disk space issues

## API Issues

### Authentication Errors

**Issue:** API requests return authentication errors.

**Solution:**
1. Verify your API key or token is valid
2. Check if the token has expired
3. Ensure you're sending the authentication header correctly:
   ```
   Authorization: Bearer your-token-here
   ```
4. Verify CORS settings if making requests from a different domain

### Rate Limiting

**Issue:** API requests are being rate limited.

**Solution:**
1. Reduce the frequency of requests
2. Implement caching for API responses
3. Request increased rate limits if necessary (for enterprise users)

## Performance Issues

### Slow Admin Interface

**Issue:** Admin interface is slow to load or navigate.

**Solution:**
1. Enable caching in your configuration:
   ```php
   'components' => [
       'cache' => [
           'class' => 'yii\caching\FileCache',
       ],
   ],
   ```
2. Optimize database queries (consider adding indexes)
3. Reduce the number of records displayed per page
4. Enable asset compression

### High Memory Usage

**Issue:** Application crashes with memory limit errors.

**Solution:**
1. Increase PHP memory limit in php.ini: `memory_limit = 256M`
2. Optimize large database queries
3. Implement pagination for content listings
4. Use asset compression and minification

## Development Issues

### Custom Field Type Issues

**Issue:** Custom field types not working correctly.

**Solution:**
1. Check that your field type class implements all required methods
2. Verify the field type is registered correctly in the configuration
3. Clear application caches
4. Check for JavaScript errors in the browser console

### Theme Customization Problems

**Issue:** Theme customizations not applying.

**Solution:**
1. Clear the asset cache: `./yii asset/flush-all`
2. Verify your custom assets are registered correctly
3. Check file paths and asset bundle configuration
4. Ensure CSS selectors are specific enough to override defaults

## Security Issues

### CSRF Protection

**Issue:** Form submissions fail with CSRF errors.

**Solution:**
1. Ensure your forms include the CSRF token:
   ```php
   <?= Html::csrfMetaTags() ?>
   ```
   or for AJAX:
   ```js
   $.ajax({
       url: 'your-url',
       type: 'POST',
       data: data,
       headers: {
           'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content')
       }
   });
   ```
2. Check if your session is expiring too quickly

### Permission Issues

**Issue:** Users cannot access certain features despite having the correct role.

**Solution:**
1. Verify role assignments in the user management section
2. Check your RBAC configuration
3. Clear the auth cache: `./yii cache/flush auth`
4. Review permission checks in your custom code

## Integration Issues

### Frontend Framework Integration

**Issue:** API data not displaying correctly in frontend applications.

**Solution:**
1. Check the network tab in browser developer tools for API errors
2. Verify data structure matches what your frontend expects
3. Implement proper error handling in your frontend code
4. Check for CORS issues and add appropriate headers:
   ```php
   'response' => [
       'class' => 'yii\web\Response',
       'on beforeSend' => function ($event) {
           $response = $event->sender;
           $response->headers->set('Access-Control-Allow-Origin', '*');
           $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
           $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');
       },
   ],
   ```

## Contacting Support

If you've tried these troubleshooting steps and still have issues, you can get help through:

- [GitHub Issues](https://github.com/giantbits/yii2-crelish/issues)
- [Community Forums](http://www.crelish.io/forum/)
- Email support at support@crelish.io (for enterprise customers) 