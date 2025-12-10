# Crelish CMS API Documentation

This document provides information about the Crelish CMS API, including authentication methods, endpoints, and examples.

## Authentication

The API supports two authentication methods:

### Bearer Token Authentication

To authenticate using Bearer tokens, you need to:

1. Obtain a token by logging in:

```
POST /crelish-api/auth/login
Content-Type: application/json

{
  "username": "your_username",
  "password": "your_password"
}
```

Response:

```json
{
  "success": true,
  "code": 200,
  "message": "Success",
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

Tokens expire after 1 hour by default.

### Query Parameter Authentication

You can also authenticate by including an access token in the query string:

```
GET /crelish-api/content/page?access-token=YOUR_ACCESS_TOKEN
```

## Content API

The Content API allows you to manage content items in the CMS.

### Base URL

```
/crelish-api/content/{type}
```

Where `{type}` is the content type (e.g., `page`, `article`, `product`).

### Endpoints

#### List Content Items

```
GET /crelish-api/content/{type}
```

Query parameters:
- `filter`: Filter content items (format: `field:operator:value,field2:operator2:value2`)
- `sort`: Field to sort by (default: `id`)
- `order`: Sort order (`asc` or `desc`, default: `asc`)
- `page`: Page number (default: `1`)
- `pageSize`: Number of items per page (default: `20`)
- `fields`: Comma-separated list of fields to include in the response

Example:

```
GET /crelish-api/content/page?filter=status:eq:published&sort=created&order=desc&page=1&pageSize=10
```

Response:

```json
{
  "success": true,
  "code": 200,
  "message": "Success",
  "data": {
    "items": [
      {
        "id": "123",
        "title": "Sample Page",
        "content": "<p>This is a sample page.</p>",
        "status": "published",
        "created": "2023-01-01 12:00:00",
        "updated": "2023-01-02 14:30:00"
      },
      // More items...
    ],
    "pagination": {
      "totalItems": 45,
      "pageSize": 10,
      "currentPage": 1,
      "totalPages": 5
    }
  }
}
```

#### Get a Single Content Item

```
GET /crelish-api/content/{type}/{id}
```

Example:

```
GET /crelish-api/content/page/123
```

Response:

```json
{
  "success": true,
  "code": 200,
  "message": "Success",
  "data": {
    "id": "123",
    "title": "Sample Page",
    "content": "<p>This is a sample page.</p>",
    "status": "published",
    "created": "2023-01-01 12:00:00",
    "updated": "2023-01-02 14:30:00"
  }
}
```

#### Create a Content Item

```
POST /crelish-api/content/{type}
Content-Type: application/json

{
  "title": "New Page",
  "content": "<p>This is a new page.</p>",
  "status": "draft"
}
```

Response:

```json
{
  "success": true,
  "code": 200,
  "message": "Content item created successfully",
  "data": {
    "id": "456",
    "title": "New Page",
    "content": "<p>This is a new page.</p>",
    "status": "draft",
    "created": "2023-01-03 10:15:00",
    "updated": "2023-01-03 10:15:00"
  }
}
```

#### Update a Content Item

```
PUT /crelish-api/content/{type}/{id}
Content-Type: application/json

{
  "title": "Updated Page Title",
  "status": "published"
}
```

Response:

```json
{
  "success": true,
  "code": 200,
  "message": "Content item updated successfully",
  "data": {
    "id": "123",
    "title": "Updated Page Title",
    "content": "<p>This is a sample page.</p>",
    "status": "published",
    "created": "2023-01-01 12:00:00",
    "updated": "2023-01-03 15:45:00"
  }
}
```

#### Delete a Content Item

```
DELETE /crelish-api/content/{type}/{id}
```

Response:

```json
{
  "success": true,
  "code": 204,
  "message": "Content item deleted successfully",
  "data": null
}
```

## Error Handling

The API returns standardized error responses:

```json
{
  "success": false,
  "code": 400,
  "message": "Error message",
  "data": {
    "errors": {
      "field1": ["Error message for field1"],
      "field2": ["Error message for field2"]
    }
  }
}
```

Common error codes:
- `400`: Bad Request - Invalid input data
- `401`: Unauthorized - Authentication required
- `403`: Forbidden - Insufficient permissions
- `404`: Not Found - Resource not found
- `500`: Internal Server Error - Server-side error

## Content Types

Content types are defined in JSON files in the `config/content-types` directory. Each content type has a schema that defines its fields and validation rules.

Example schema for a "page" content type:

```json
{
  "name": "page",
  "label": "Page",
  "description": "Basic page content type",
  "fields": {
    "title": {
      "type": "string",
      "label": "Title",
      "description": "Page title",
      "required": true,
      "minLength": 3,
      "maxLength": 255
    },
    "slug": {
      "type": "string",
      "label": "Slug",
      "description": "URL-friendly version of the title",
      "required": true,
      "minLength": 3,
      "maxLength": 255
    },
    "content": {
      "type": "string",
      "label": "Content",
      "description": "Page content in HTML format",
      "required": true
    },
    "status": {
      "type": "enum",
      "label": "Status",
      "description": "Publication status",
      "required": true,
      "values": ["draft", "published", "archived"],
      "default": "draft"
    }
  }
}
```

## Rate Limiting

The API implements rate limiting to prevent abuse. The following headers are included in API responses:

- `X-Rate-Limit-Limit`: The maximum number of requests allowed in a period
- `X-Rate-Limit-Remaining`: The number of remaining requests in the current period
- `X-Rate-Limit-Reset`: The time at which the current rate limit window resets

If you exceed the rate limit, you will receive a `429 Too Many Requests` response. 