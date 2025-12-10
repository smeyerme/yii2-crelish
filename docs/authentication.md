# Authentication and Authorization

This guide explains how to set up and use authentication and authorization in Crelish CMS.

## Authentication Methods

Crelish CMS supports multiple authentication methods for the API:

1. **Bearer Token Authentication**: Using JWT tokens in the Authorization header
2. **Query Parameter Authentication**: Using access tokens in the query string
3. **Cookie-based Authentication**: For web applications using the same domain

## Setting Up Authentication

### Configuration

Authentication is configured in the Bootstrap.php file:

```php
// modules/api/Module.php
private function setupApiConfiguration(): void
{
    // Configure CORS and authentication behaviors
    Yii::$app->controllerMap['api'] = [
        'class' => 'yii\rest\Controller',
        'behaviors' => [
            'authenticator' => [
                'class' => CompositeAuth::class,
                'authMethods' => [
                    HttpBearerAuth::class,
                    QueryParamAuth::class,
                ],
                'except' => ['options'],
            ],
            // Other behaviors...
        ],
    ];
}
```

### User Identity

Crelish uses Yii2's built-in user identity system. The default user identity class is `giantbits\crelish\components\CrelishUser`.

You can customize the user identity class in your application configuration:

```php
'components' => [
    'user' => [
        'class' => 'yii\web\User',
        'identityClass' => 'giantbits\crelish\components\CrelishUser',
        'enableAutoLogin' => true,
        'loginUrl' => ['crelish/user/login']
    ],
    // Other components...
],
```

## User Management

### Creating Users

Users can be created through the admin interface or programmatically:

```php
use giantbits\crelish\models\User;

$user = new User();
$user->username = 'john.doe';
$user->email = 'john.doe@example.com';
$user->setPassword('password123');
$user->generateAuthKey();
$user->status = User::STATUS_ACTIVE;
$user->save();
```

### User Roles and Permissions

Crelish uses Yii2's RBAC (Role-Based Access Control) system for managing user roles and permissions.

#### Predefined Roles

- **admin**: Full access to all features
- **editor**: Can create, edit, and publish content
- **author**: Can create and edit their own content
- **contributor**: Can create content but not publish it
- **subscriber**: Can view content but not create or edit it

#### Assigning Roles

Roles can be assigned through the admin interface or programmatically:

```php
$auth = Yii::$app->authManager;
$editorRole = $auth->getRole('editor');
$auth->assign($editorRole, $user->id);
```

#### Checking Permissions

You can check permissions in your code:

```php
// Check if the current user can edit content
if (Yii::$app->user->can('editContent')) {
    // Allow editing
}

// Check if the current user can edit a specific content item
if (Yii::$app->user->can('editContent', ['item' => $contentItem])) {
    // Allow editing this specific item
}
```

## API Authentication

### Bearer Token Authentication

To authenticate API requests using Bearer tokens:

1. Obtain a token by logging in:

```
POST /crelish-api/auth/login
Content-Type: application/json

{
  "username": "john.doe",
  "password": "password123"
}
```

Response:

```json
{
  "success": true,
  "code": 200,
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "expires_at": 1672531200
  }
}
```

2. Use the token in subsequent requests:

```
GET /crelish-api/content/page
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Query Parameter Authentication

You can also authenticate by including an access token in the query string:

```
GET /crelish-api/content/page?access-token=YOUR_ACCESS_TOKEN
```

### Token Management

#### Generating Tokens

Tokens can be generated programmatically:

```php
use giantbits\crelish\components\TokenManager;

// Generate a token for a user
$token = Yii::$app->tokenManager->generateToken($user->id, 3600); // Valid for 1 hour
```

#### Validating Tokens

Tokens are automatically validated by the authentication system, but you can also validate them manually:

```php
$isValid = Yii::$app->tokenManager->validateToken($token);
$userId = Yii::$app->tokenManager->getUserIdFromToken($token);
```

#### Revoking Tokens

Tokens can be revoked:

```php
// Revoke a specific token
Yii::$app->tokenManager->revokeToken($token);

// Revoke all tokens for a user
Yii::$app->tokenManager->revokeAllTokens($userId);
```

## Security Best Practices

1. **Use HTTPS**: Always use HTTPS for API requests to protect authentication credentials and tokens.

2. **Set appropriate token expiration**: Balance security and user experience when setting token expiration times.

3. **Implement rate limiting**: Protect against brute force attacks by implementing rate limiting on authentication endpoints.

4. **Use strong passwords**: Enforce strong password policies for users.

5. **Implement two-factor authentication**: For sensitive applications, consider implementing two-factor authentication.

6. **Regularly audit user accounts**: Regularly review user accounts and permissions to ensure they are still appropriate.

7. **Secure token storage**: Store tokens securely on the client side (e.g., in HttpOnly cookies or secure storage).

## Troubleshooting

### Common Issues

#### "Unauthorized" Error

If you receive a 401 Unauthorized error:

- Check that you're including the correct token
- Verify that the token hasn't expired
- Ensure the user account is active and has the necessary permissions

#### CORS Issues

If you're experiencing CORS issues:

- Check the CORS configuration in the API module
- Ensure that the Origin header is properly set in your requests
- Verify that the correct HTTP methods are allowed

#### Token Validation Failures

If token validation is failing:

- Check that the token is correctly formatted
- Verify that the token hasn't been tampered with
- Ensure that the token hasn't been revoked 