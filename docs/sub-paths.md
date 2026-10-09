# Pages below a page (`subPaths`)

Whatever follows a page's name in the path is handed to that page:
`/news/6c2d7e1e/a-title` calls the page `news` with `['6c2d7e1e', 'a-title']`
(`Yii::$app->request->get()[0]`). Detail pages use this: a job, a news article, a company.

Every other page ignored what followed its name. `/contact/anything` showed the contact
page again, under an address of its own: a duplicate for search engines, and a way to
make up endless addresses of a site.

## The setting

A project names the pages that have pages below them:

```php
'params' => [
    'crelish' => [
        'subPaths' => ['news', 'stellendetail'],
    ],
],
```

With the setting in place:

- A named page is called as before, with whatever follows its name.
- Any other page called with something after its name is answered like a page that does
  not exist: the project's `404slug` page with status 404.
- An address ending in a slash (`/contact/`) redirects permanently to the one without it
  (`GET` and `HEAD` only), on every page.

**Without the setting nothing changes.** crelish cannot know which pages read the rest of
the path, so nothing is refused until a project has said which pages do. An empty list
(`[]`) is a setting: no page has pages below it.

Page names are compared without regard to case. With `langprefix`, the language is not
part of the name: `/de/news/…` is the page `news`.

## Finding the pages

```
php yii crelish/sub-paths/report
```

lists the pages that were called with something after their name in the stored visits,
with the number of views, of views not marked as bots, and of views a browser confirmed.
Pages with visitors are the candidates for the setting. Pages with bot views only are
what the setting will answer with 404.

Check the list against the code as well: search the project's widgets and templates for
`request->get()[0]`. A detail page nobody visited in the stored period does not show up
in the report.

## Notes

- The check is `giantbits\crelish\components\SubPaths`, applied in
  `CrelishFrontendController` before the page is loaded for rendering.
- Routes that are not pages (the admin area, `api/`, short links, the page-state
  endpoint, a project's own controllers) are not affected.
- A page that is named in the setting still has to answer an unknown id itself, as the
  detail widgets do.
