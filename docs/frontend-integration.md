# Frontend Integration

This guide explains how to integrate Crelish CMS with various frontend frameworks and technologies.

## Overview

As a headless CMS, Crelish is designed to work with any frontend technology. The API provides a standardized way to access content, which can be consumed by any client that can make HTTP requests.

## Integration Approaches

There are several approaches to integrating Crelish with frontend applications:

1. **Direct API Integration**: Make API calls directly from your frontend application
2. **Server-Side Rendering**: Fetch content on the server and render it before sending to the client
3. **Static Site Generation**: Generate static HTML files from content at build time
4. **Hybrid Approaches**: Combine server-side rendering with client-side hydration

## Direct API Integration

### JavaScript/TypeScript Example

```javascript
// Using fetch API
async function getPages() {
  const response = await fetch('https://your-domain.com/api/content/page', {
    headers: {
      'Authorization': 'Bearer YOUR_TOKEN_HERE'
    }
  });
  
  if (!response.ok) {
    throw new Error('Failed to fetch pages');
  }
  
  const data = await response.json();
  return data.data.items;
}

// Using axios
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://your-domain.com/api',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  }
});

async function getPages() {
  const response = await api.get('/content/page');
  return response.data.data.items;
}
```

### React Example

```jsx
import React, { useState, useEffect } from 'react';
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://your-domain.com/api',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  }
});

function PageList() {
  const [pages, setPages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    async function fetchPages() {
      try {
        const response = await api.get('/content/page');
        setPages(response.data.data.items);
        setLoading(false);
      } catch (err) {
        setError('Failed to fetch pages');
        setLoading(false);
      }
    }

    fetchPages();
  }, []);

  if (loading) return <div>Loading...</div>;
  if (error) return <div>{error}</div>;

  return (
    <div>
      <h1>Pages</h1>
      <ul>
        {pages.map(page => (
          <li key={page.id}>
            <a href={`/page/${page.slug}`}>{page.title}</a>
          </li>
        ))}
      </ul>
    </div>
  );
}

export default PageList;
```

### Vue.js Example

```vue
<template>
  <div>
    <h1>Pages</h1>
    <div v-if="loading">Loading...</div>
    <div v-else-if="error">{{ error }}</div>
    <ul v-else>
      <li v-for="page in pages" :key="page.id">
        <router-link :to="`/page/${page.slug}`">{{ page.title }}</router-link>
      </li>
    </ul>
  </div>
</template>

<script>
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://your-domain.com/api',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  }
});

export default {
  data() {
    return {
      pages: [],
      loading: true,
      error: null
    };
  },
  async created() {
    try {
      const response = await api.get('/content/page');
      this.pages = response.data.data.items;
      this.loading = false;
    } catch (err) {
      this.error = 'Failed to fetch pages';
      this.loading = false;
    }
  }
};
</script>
```

### Angular Example

```typescript
// page.service.ts
import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';

@Injectable({
  providedIn: 'root'
})
export class PageService {
  private apiUrl = 'https://your-domain.com/api';
  private headers = new HttpHeaders({
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  });

  constructor(private http: HttpClient) { }

  getPages(): Observable<any[]> {
    return this.http.get(`${this.apiUrl}/content/page`, { headers: this.headers })
      .pipe(
        map((response: any) => response.data.items)
      );
  }

  getPage(slug: string): Observable<any> {
    return this.http.get(`${this.apiUrl}/content/page?filter=slug:eq:${slug}`, { headers: this.headers })
      .pipe(
        map((response: any) => response.data.items[0])
      );
  }
}

// page-list.component.ts
import { Component, OnInit } from '@angular/core';
import { PageService } from './page.service';

@Component({
  selector: 'app-page-list',
  template: `
    <div>
      <h1>Pages</h1>
      <div *ngIf="loading">Loading...</div>
      <div *ngIf="error">{{ error }}</div>
      <ul *ngIf="!loading && !error">
        <li *ngFor="let page of pages">
          <a [routerLink]="['/page', page.slug]">{{ page.title }}</a>
        </li>
      </ul>
    </div>
  `
})
export class PageListComponent implements OnInit {
  pages: any[] = [];
  loading = true;
  error: string | null = null;

  constructor(private pageService: PageService) { }

  ngOnInit(): void {
    this.pageService.getPages().subscribe(
      pages => {
        this.pages = pages;
        this.loading = false;
      },
      err => {
        this.error = 'Failed to fetch pages';
        this.loading = false;
      }
    );
  }
}
```

## Server-Side Rendering

### Node.js/Express Example

```javascript
const express = require('express');
const axios = require('axios');
const app = express();

const api = axios.create({
  baseURL: 'https://your-domain.com/api',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  }
});

app.get('/', async (req, res) => {
  try {
    const response = await api.get('/content/page');
    const pages = response.data.data.items;
    
    res.render('pages', { pages });
  } catch (err) {
    res.status(500).send('Error fetching pages');
  }
});

app.get('/page/:slug', async (req, res) => {
  try {
    const response = await api.get(`/content/page?filter=slug:eq:${req.params.slug}`);
    const page = response.data.data.items[0];
    
    if (!page) {
      return res.status(404).send('Page not found');
    }
    
    res.render('page', { page });
  } catch (err) {
    res.status(500).send('Error fetching page');
  }
});

app.listen(3000, () => {
  console.log('Server running on port 3000');
});
```

### Next.js Example

```javascript
// pages/index.js
import axios from 'axios';

export default function Home({ pages }) {
  return (
    <div>
      <h1>Pages</h1>
      <ul>
        {pages.map(page => (
          <li key={page.id}>
            <a href={`/page/${page.slug}`}>{page.title}</a>
          </li>
        ))}
      </ul>
    </div>
  );
}

export async function getServerSideProps() {
  try {
    const response = await axios.get('https://your-domain.com/api/content/page', {
      headers: {
        'Authorization': 'Bearer YOUR_TOKEN_HERE'
      }
    });
    
    return {
      props: {
        pages: response.data.data.items
      }
    };
  } catch (err) {
    return {
      props: {
        pages: []
      }
    };
  }
}

// pages/page/[slug].js
import axios from 'axios';

export default function Page({ page }) {
  if (!page) {
    return <div>Page not found</div>;
  }
  
  return (
    <div>
      <h1>{page.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: page.content }} />
    </div>
  );
}

export async function getServerSideProps({ params }) {
  try {
    const response = await axios.get(`https://your-domain.com/api/content/page?filter=slug:eq:${params.slug}`, {
      headers: {
        'Authorization': 'Bearer YOUR_TOKEN_HERE'
      }
    });
    
    const page = response.data.data.items[0] || null;
    
    return {
      props: {
        page
      }
    };
  } catch (err) {
    return {
      props: {
        page: null
      }
    };
  }
}
```

## Static Site Generation

### Gatsby Example

```javascript
// gatsby-node.js
const axios = require('axios');

exports.createPages = async ({ actions }) => {
  const { createPage } = actions;
  
  try {
    const response = await axios.get('https://your-domain.com/api/content/page', {
      headers: {
        'Authorization': 'Bearer YOUR_TOKEN_HERE'
      }
    });
    
    const pages = response.data.data.items;
    
    pages.forEach(page => {
      createPage({
        path: `/page/${page.slug}`,
        component: require.resolve('./src/templates/page.js'),
        context: {
          page
        }
      });
    });
  } catch (err) {
    console.error('Error fetching pages:', err);
  }
};

// src/templates/page.js
import React from 'react';

export default function PageTemplate({ pageContext }) {
  const { page } = pageContext;
  
  return (
    <div>
      <h1>{page.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: page.content }} />
    </div>
  );
}
```

### Nuxt.js Example

```javascript
// nuxt.config.js
export default {
  target: 'static',
  // Other config...
};

// pages/index.vue
<template>
  <div>
    <h1>Pages</h1>
    <ul>
      <li v-for="page in pages" :key="page.id">
        <nuxt-link :to="`/page/${page.slug}`">{{ page.title }}</nuxt-link>
      </li>
    </ul>
  </div>
</template>

<script>
export default {
  async asyncData({ $axios }) {
    try {
      const response = await $axios.get('https://your-domain.com/api/content/page', {
        headers: {
          'Authorization': 'Bearer YOUR_TOKEN_HERE'
        }
      });
      
      return {
        pages: response.data.data.items
      };
    } catch (err) {
      return {
        pages: []
      };
    }
  }
};
</script>

// pages/page/_slug.vue
<template>
  <div>
    <h1>{{ page.title }}</h1>
    <div v-html="page.content"></div>
  </div>
</template>

<script>
export default {
  async asyncData({ params, $axios, error }) {
    try {
      const response = await $axios.get(`https://your-domain.com/api/content/page?filter=slug:eq:${params.slug}`, {
        headers: {
          'Authorization': 'Bearer YOUR_TOKEN_HERE'
        }
      });
      
      const page = response.data.data.items[0];
      
      if (!page) {
        return error({ statusCode: 404, message: 'Page not found' });
      }
      
      return {
        page
      };
    } catch (err) {
      return error({ statusCode: 500, message: 'Error fetching page' });
    }
  }
};
</script>
```

## Authentication and Security

### Handling Authentication

For public-facing websites, you may want to use a server-side proxy to handle authentication:

```javascript
// Server-side proxy example (Node.js/Express)
const express = require('express');
const axios = require('axios');
const app = express();

const API_TOKEN = process.env.API_TOKEN; // Store token in environment variable

app.get('/api/content/:type', async (req, res) => {
  try {
    const response = await axios.get(`https://your-domain.com/api/content/${req.params.type}`, {
      headers: {
        'Authorization': `Bearer ${API_TOKEN}`
      },
      params: req.query
    });
    
    res.json(response.data);
  } catch (err) {
    res.status(err.response?.status || 500).json({
      success: false,
      message: err.message
    });
  }
});

app.listen(3000, () => {
  console.log('Server running on port 3000');
});
```

### CORS Configuration

If you're making API requests directly from the browser, you'll need to configure CORS on the server:

```php
// modules/api/Module.php
private function setupApiConfiguration(): void
{
    // Configure CORS
    Yii::$app->controllerMap['api'] = [
        'class' => 'yii\rest\Controller',
        'behaviors' => [
            'corsFilter' => [
                'class' => Cors::class,
                'cors' => [
                    'Origin' => ['https://your-frontend-domain.com'], // Restrict to your frontend domain
                    'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                    'Access-Control-Request-Headers' => ['*'],
                    'Access-Control-Allow-Credentials' => true,
                    'Access-Control-Max-Age' => 86400,
                ],
            ],
            // Other behaviors...
        ],
    ];
}
```

## Performance Optimization

### Caching

Implement caching to improve performance:

```javascript
// Client-side caching example
import { setupCache } from 'axios-cache-adapter';
import axios from 'axios';

const cache = setupCache({
  maxAge: 15 * 60 * 1000, // 15 minutes
  exclude: { query: false }
});

const api = axios.create({
  adapter: cache.adapter,
  baseURL: 'https://your-domain.com/api',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN_HERE'
  }
});

// Server-side caching example (Node.js/Express with Redis)
const express = require('express');
const axios = require('axios');
const redis = require('redis');
const { promisify } = require('util');

const app = express();
const client = redis.createClient();
const getAsync = promisify(client.get).bind(client);
const setAsync = promisify(client.set).bind(client);

app.get('/api/content/:type', async (req, res) => {
  const cacheKey = `content:${req.params.type}:${JSON.stringify(req.query)}`;
  
  try {
    // Check cache first
    const cachedData = await getAsync(cacheKey);
    
    if (cachedData) {
      return res.json(JSON.parse(cachedData));
    }
    
    // If not in cache, fetch from API
    const response = await axios.get(`https://your-domain.com/api/content/${req.params.type}`, {
      headers: {
        'Authorization': `Bearer ${process.env.API_TOKEN}`
      },
      params: req.query
    });
    
    // Store in cache for 15 minutes
    await setAsync(cacheKey, JSON.stringify(response.data), 'EX', 900);
    
    res.json(response.data);
  } catch (err) {
    res.status(err.response?.status || 500).json({
      success: false,
      message: err.message
    });
  }
});

app.listen(3000, () => {
  console.log('Server running on port 3000');
});
```

### Optimizing API Requests

Use query parameters to optimize API requests:

```javascript
// Only fetch required fields
const response = await api.get('/content/page?fields=id,title,slug');

// Limit the number of items
const response = await api.get('/content/page?pageSize=5');

// Sort by a specific field
const response = await api.get('/content/page?sort=created_at&order=desc');

// Filter by a specific field
const response = await api.get('/content/page?filter=status:eq:published');
```

## Best Practices

1. **Use a consistent API client**: Create a reusable API client with proper error handling and authentication.

2. **Implement caching**: Cache API responses to reduce load on the server and improve performance.

3. **Handle loading and error states**: Always provide feedback to users during loading and when errors occur.

4. **Secure your tokens**: Never expose API tokens in client-side code. Use server-side proxies or environment variables.

5. **Optimize API requests**: Only request the data you need using fields, pagination, and filtering.

6. **Implement proper error handling**: Handle API errors gracefully and provide meaningful feedback to users.

7. **Use TypeScript for type safety**: Define interfaces for your content types to catch errors at compile time.

8. **Consider using a state management library**: For complex applications, use Redux, Vuex, or similar libraries to manage state. 