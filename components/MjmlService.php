<?php
namespace giantbits\crelish\components;

use Exception;
use Yii;
use yii\base\Component;

/**
 * Service to process MJML into HTML
 */
class MjmlService extends Component
{
  /**
   * Path to Node.js executable
   * @var string
   */
  public $nodePath = '/Users/smyr/.nvm/versions/node/v18.19.0/bin/node';

  /**
   * Path to MJML executable (npm binary)
   * @var string
   */
  public $mjmlPath = '/Users/smyr/Sites/gbits/giantbits/yii2-crelish/resources/newsletter/node_modules/mjml/bin/mjml';


  public function init()
  {
    parent::init();

    $this->mjmlPath =  $_ENV['MJML_PATH'];
    $this->nodePath =  $_ENV['NODE_PATH'];
  }

  /**
   * Render MJML content to HTML
   *
   * @param string $mjmlContent The MJML content to render
   * @return string The rendered HTML
   * @throws Exception If rendering fails
   */
  public function renderMjml($mjmlContent): string
  {
    // Create temporary files for input and output
    $tempDir = Yii::getAlias('@runtime/mjml');
    if (!is_dir($tempDir)) {
      mkdir($tempDir, 0777, true);
    }

    $inputFile = tempnam($tempDir, 'mjml_input_');
    $outputFile = $inputFile . '.html';

    // Write MJML content to the input file
    file_put_contents($inputFile, $mjmlContent);

    try {
      // Option 1: Execute MJML directly if installed globally
      if ($this->isDirectMjmlAvailable()) {
        return $this->executeDirectMjml($inputFile, $outputFile);
      }

      // Option 2: Use Node.js script with locally installed MJML
      return $this->executeNodeMjml($inputFile, $outputFile);
    } finally {
      // Clean up temporary files
      @unlink($inputFile);
      @unlink($outputFile);
    }
  }

  /**
   * Check if MJML is directly available
   *
   * @return bool True if MJML is available
   */
  protected function isDirectMjmlAvailable()
  {
    $command = $this->nodePath . ' ' . $this->mjmlPath . ' --version';
    $output = [];
    $returnCode = 0;

    exec($command . ' 2>&1', $output, $returnCode);

    return $returnCode === 0;
  }

  /**
   * Execute MJML directly using the command line
   *
   * @param string $inputFile Path to the input file
   * @param string $outputFile Path to the output file
   * @return string The rendered HTML
   * @throws Exception If rendering fails
   */
  protected function executeDirectMjml($inputFile, $outputFile)
  {
    $command = sprintf(
      '%s %s -o %s',
      escapeshellcmd($this->nodePath . ' ' . $this->mjmlPath),
      escapeshellarg($inputFile),
      escapeshellarg($outputFile)
    );

    $output = [];
    $returnCode = 0;

    exec($command . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0) {
      throw new Exception('MJML rendering failed: ' . implode("\n", $output));
    }

    if (!file_exists($outputFile)) {
      throw new Exception('MJML output file was not created');
    }

    return file_get_contents($outputFile);
  }

  /**
   * Execute MJML using a Node.js script
   *
   * @param string $inputFile Path to the input file
   * @param string $outputFile Path to the output file
   * @return string The rendered HTML
   * @throws Exception If rendering fails
   */
  protected function executeNodeMjml($inputFile, $outputFile)
  {
    // Create a simple Node.js script to render MJML
    $error = '';
    $nodeScript = <<<JS
const mjml2html = require('mjml');
const fs = require('fs');

// Read input file
const mjmlContent = fs.readFileSync(process.argv[2], 'utf8');

try {
    // Convert MJML to HTML
    const result = mjml2html(mjmlContent, {
        minify: true,
        validationLevel: 'soft'
    });
    
    if (result.errors && result.errors.length > 0) {
        console.error('MJML validation errors:');
        result.errors.forEach(error => {
            console.error('- Line ' + error.line + ': ' +  error.message);
        });
    }
    
    // Write output to file
    fs.writeFileSync(process.argv[3], result.html);
    process.exit(0);
} catch (error) {
    console.error('Error rendering MJML:', error.message);
    process.exit(1);
}
JS;

    $scriptFile = Yii::getAlias('@runtime/mjml/mjml_renderer.js');
    file_put_contents($scriptFile, $nodeScript);

    $command = sprintf(
      '%s %s %s %s',
      escapeshellcmd($this->nodePath),
      escapeshellarg($scriptFile),
      escapeshellarg($inputFile),
      escapeshellarg($outputFile)
    );

    $output = [];
    $returnCode = 0;

    exec($command . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0) {
      throw new Exception('MJML rendering failed: ' . implode("\n", $output));
    }

    if (!file_exists($outputFile)) {
      throw new Exception('MJML output file was not created');
    }

    return file_get_contents($outputFile);
  }

  /**
   * Alternative method: Use Laravel MJML API endpoint
   *
   * This method requires a Laravel service to be running that exposes an API endpoint
   * for MJML rendering. You can implement this if you want to use Laravel's MJML package.
   *
   * @param string $mjmlContent The MJML content to render
   * @return string The rendered HTML
   * @throws Exception If the API call fails
   */
  public function renderMjmlViaApi($mjmlContent)
  {
    $apiUrl = Yii::$app->params['laravelMjmlApiUrl'] ?? 'http://localhost:8000/api/mjml/render';

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['mjml' => $mjmlContent]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-Type: application/json',
      'Accept: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
      throw new Exception('MJML API rendering failed with HTTP code ' . $httpCode);
    }

    $data = json_decode($response, true);

    if (!isset($data['html'])) {
      throw new Exception('MJML API response did not contain HTML');
    }

    return $data['html'];
  }
}