# Crelish CMS API Documentation

This document provides information about the Crelish CMS RESTful API.

## Authentication

The API supports two authentication methods:

1. **Bearer Token Authentication**: Send an `Authorization` header with a bearer token.
   ```
   Authorization: Bearer YOUR_TOKEN_HERE
   ```

2. **Query Parameter Authentication**: Include an access token in the query string.
   ```
   ?access-token=YOUR_TOKEN_HERE
   ```

## Content API

The Content API allows you to manage content items of different types.

### Base URL

All API endpoints are relative to the base URL of your Crelish CMS installation:

```
https://your-domain.com/api/
```

### Content Endpoints

#### List Content Items

```
GET /api/content/{type}
```

Retrieves a list of content items of the specified type.

**Parameters:**

- `type` (path parameter): Content type name (e.g., "page", "article", "product")
- `page` (query parameter, optional): Page number (default: 1)
- `pageSize` (query parameter, optional): Number of items per page (default: 20)
- `sort` (query parameter, optional): Field to sort by
- `order` (query parameter, optional): Sort order ("asc" or "desc", default: "asc")
- `filter` (query parameter, optional): Filter string in format "field:operator:value,field2:operator2:value2"

**Supported filter operators:**

- `eq`: Equal to
- `neq`: Not equal to
- `gt`: Greater than
- `gte`: Greater than or equal to
- `lt`: Less than
- `lte`: Less than or equal to
- `like`: Contains (SQL LIKE)
- `in`: In a list of values (separated by "|")

**Example Request:**

```
GET /api/content/page?page=1&pageSize=10&sort=created_at&order=desc&filter=status:eq:published
```

**Example Response:**

```json
{
  "success": true,
  "code": 200,
  "data": {
    "items": [
      {
        "id": "1234-5678-90ab-cdef",
        "title": "Home Page",
        "slug": "home",
        "content": "<p>Welcome to our website!</p>",
        "status": "published",
        "created_at": "2023-01-01 12:00:00",
        "updated_at": "2023-01-02 14:30:00"
      },
      // More items...
    ],
    "pagination": {
      "totalItems": 25,
      "pageSize": 10,
      "currentPage": 1,
      "totalPages": 3
    }
  }
}
```

#### Get Content Item

```
GET /api/content/{type}/{id}
```

Retrieves a single content item by ID.

**Parameters:**

- `type` (path parameter): Content type name
- `id` (path parameter): Content item ID

**Example Request:**

```
GET /api/content/page/1234-5678-90ab-cdef
```

**Example Response:**

```json
{
  "success": true,
  "code": 200,
  "data": {
    "id": "1234-5678-90ab-cdef",
    "title": "Home Page",
    "slug": "home",
    "content": "<p>Welcome to our website!</p>",
    "status": "published",
    "created_at": "2023-01-01 12:00:00",
    "updated_at": "2023-01-02 14:30:00"
  }
}
```

#### Create Content Item

```
POST /api/content/{type}
```

Creates a new content item.

**Parameters:**

- `type` (path parameter): Content type name
- Request body: JSON object with content item data

**Example Request:**

```
POST /api/content/page
Content-Type: application/json

{
  "title": "About Us",
  "slug": "about-us",
  "content": "<p>This is the about us page.</p>",
  "status": "published"
}
```

**Example Response:**

```json
{
  "success": true,
  "code": 201,
  "message": "Content item created successfully",
  "data": {
    "id": "abcd-efgh-ijkl-mnop",
    "title": "About Us",
    "slug": "about-us",
    "content": "<p>This is the about us page.</p>",
    "status": "published",
    "created_at": "2023-03-15 10:45:00",
    "updated_at": "2023-03-15 10:45:00"
  }
}
```

#### Update Content Item

```
PUT /api/content/{type}/{id}
```

Updates an existing content item.

**Parameters:**

- `type` (path parameter): Content type name
- `id` (path parameter): Content item ID
- Request body: JSON object with content item data to update

**Example Request:**

```
PUT /api/content/page/abcd-efgh-ijkl-mnop
Content-Type: application/json

{
  "title": "About Our Company",
  "content": "<p>This is the updated about us page.</p>"
}
```

**Example Response:**

```json
{
  "success": true,
  "code": 200,
  "message": "Content item updated successfully",
  "data": {
    "id": "abcd-efgh-ijkl-mnop",
    "title": "About Our Company",
    "slug": "about-us",
    "content": "<p>This is the updated about us page.</p>",
    "status": "published",
    "created_at": "2023-03-15 10:45:00",
    "updated_at": "2023-03-15 11:30:00"
  }
}
```

#### Delete Content Item

```
DELETE /api/content/{type}/{id}
```

Deletes a content item.

**Parameters:**

- `type` (path parameter): Content type name
- `id` (path parameter): Content item ID

**Example Request:**

```
DELETE /api/content/page/abcd-efgh-ijkl-mnop
```

**Example Response:**

```json
{
  "success": true,
  "code": 204,
  "message": "Content item deleted successfully"
}
```

## Error Handling

All API endpoints return a standardized error response format:

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

### Common Error Codes

- `400`: Bad Request - The request was malformed or contains invalid data
- `401`: Unauthorized - Authentication is required
- `403`: Forbidden - The authenticated user does not have permission
- `404`: Not Found - The requested resource does not exist
- `422`: Unprocessable Entity - Validation errors
- `500`: Internal Server Error - An unexpected error occurred

## Content Types

Content types are defined in JSON files located in the `config/content-types` directory. Each content type has a schema that defines its fields and validation rules.

Example content type definition:

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

The API implements rate limiting to prevent abuse. If you exceed the rate limit, you will receive a `429 Too Many Requests` response.

The rate limit headers are included in the response:

- `X-Rate-Limit-Limit`: The maximum number of requests allowed in the current time period
- `X-Rate-Limit-Remaining`: The number of remaining requests in the current time period
- `X-Rate-Limit-Reset`: The time at which the current rate limit window resets (Unix timestamp) 