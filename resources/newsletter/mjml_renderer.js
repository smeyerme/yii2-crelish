const mjml2html = require('mjml');
const fs = require('fs');

// Process arguments
const inputFile = process.argv[2];
const outputFile = process.argv[3];

if (!inputFile || !outputFile) {
  console.error('Usage: node mjml_renderer.js <input_file> <output_file>');
  process.exit(1);
}

try {
  // Read MJML content
  const mjmlContent = fs.readFileSync(inputFile, 'utf8');

  // Convert to HTML
  const result = mjml2html(mjmlContent, {
    minify: true,
    validationLevel: 'soft'
  });

  // Output any validation warnings
  if (result.errors && result.errors.length > 0) {
    console.warn('MJML validation issues:');
    result.errors.forEach(err => console.warn(`- ${err.formattedMessage}`));
  }

  // Write HTML output
  fs.writeFileSync(outputFile, result.html);
  console.log('MJML successfully converted to HTML');
  process.exit(0);
} catch (error) {
  console.error('Error processing MJML:', error.message);
  process.exit(1);
}