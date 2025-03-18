# Documentation Viewer

Crelish CMS includes a built-in documentation viewer that allows you to access the documentation directly from the admin interface. This guide explains how to use and customize the documentation viewer.

## Accessing the Documentation

You can access the documentation in two ways:

1. **Via the Version Number**: Click on the version number at the bottom of the sidebar to open the documentation viewer.
2. **Via the Documentation Menu**: If configured, a "Documentation" item will appear in the main navigation sidebar.

## Features

The documentation viewer offers the following features:

- Markdown rendering with syntax highlighting
- Navigation between documentation pages
- Responsive design for desktop and mobile viewing
- Automatic table of contents generation from markdown headings

## Customizing the Documentation

### Adding Custom Documentation

You can add your own documentation pages by placing markdown files in the `docs` directory of your application.

1. Create a new markdown file in the `docs` directory
2. Add content using standard markdown syntax
3. Ensure the file has a title (level 1 heading) at the top

Example custom documentation file (`docs/custom-module.md`):

```markdown
# Custom Module Documentation

This documentation covers the custom module functionality.

## Installation

To install the custom module, follow these steps...

## Configuration

The module can be configured with the following options...
```

### Updating Existing Documentation

To update the existing documentation:

1. Locate the relevant markdown file in the `docs` directory
2. Edit the content as needed
3. Save the file
4. Access the documentation viewer to see your changes

### Linking Between Documentation Pages

You can create links between documentation pages using standard markdown link syntax with relative paths:

```markdown
See the [Getting Started Guide](./getting-started.md) for more information.
```

## Documentation Structure Best Practices

For the best user experience, follow these guidelines when creating documentation:

1. **Start with a Clear Title**: Begin each document with a level 1 heading (`# Title`)
2. **Use Proper Heading Hierarchy**: Follow a logical heading structure (H1 → H2 → H3)
3. **Include Code Examples**: Use code blocks with language specifiers for syntax highlighting:
   ````markdown
   ```php
   $config = [
       'param' => 'value',
   ];
   ```
   ````
4. **Use Tables for Structured Data**: Present options, parameters, or configurations in tables
5. **Add Screenshots**: Include screenshots for UI-related instructions (place images in a `docs/images` folder)
6. **Link Related Content**: Cross-reference related documentation pages

## Technical Details

The documentation viewer is implemented using the following components:

- **DocumentationController**: Handles requests for documentation pages
- **Parsedown**: Converts markdown to HTML
- **Custom sidebar**: Shows available documentation pages

## Troubleshooting

If you encounter issues with the documentation viewer:

1. **Missing Documentation**: Ensure the markdown files exist in the correct location
2. **Formatting Issues**: Verify your markdown syntax is correct
3. **Navigation Problems**: Check that file references in links are correct
4. **Display Problems**: Clear your browser cache and reload the page

For more complex issues, refer to the [Troubleshooting Guide](./troubleshooting.md).

## Extending the Documentation Viewer

Advanced users can extend the documentation viewer functionality:

### Custom Styling

Add custom CSS to style the documentation viewer by editing the view templates in `views/documentation/`.

### Adding Search Functionality

To implement documentation search:

1. Create a custom controller action for search
2. Add a search form to the documentation template
3. Implement search logic to scan markdown files for keywords

### PDF Export

To enable PDF export of documentation:

1. Add a PDF export button to the template
2. Create a controller action that uses mPDF or a similar library
3. Convert the markdown to PDF format when requested

## Contributing to Documentation

We welcome contributions to improve the documentation. Please follow these steps:

1. Fork the repository
2. Make your changes to the documentation files
3. Submit a pull request with a clear description of your changes

Your contributions help make Crelish better for everyone! 