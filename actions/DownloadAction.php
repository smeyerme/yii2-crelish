<?php

namespace giantbits\crelish\actions;

use app\workspace\models\Asset;
use Yii;
use yii\base\Action;
use yii\web\Response;
use yii\web\NotFoundHttpException;

/**
 * Action for handling file downloads with tracking
 */
class DownloadAction extends Action
{
    /**
     * Handles file download requests with asset tracking
     * 
     * @return Response
     * @throws NotFoundHttpException When asset UUID is missing, asset not found, or file not found
     */
    public function run(): Response
    {
    // Get the asset UUID
    /** @var string|null $uuid */
    $uuid = Yii::$app->request->get('uuid');
    if (empty($uuid)) {
      throw new NotFoundHttpException('No asset specified.');
    }

    // Get the asset
    /** @var Asset|null $asset */
    $asset = Asset::findOne($uuid);
    if (!$asset) {
      throw new NotFoundHttpException('Asset not found.');
    }

    // Build the file path
    /** @phpstan-ignore-next-line */
    $pathName = $asset->pathName;
    /** @phpstan-ignore-next-line */
    $fileName = $asset->fileName;
    $filePath = Yii::getAlias('@app/web') . (str_starts_with($pathName, '/') ? $pathName : '/' . $pathName);
    $filePath .= (str_ends_with($filePath, '/') ? '' : '/') . $fileName;

    // Check if file exists
    if (!file_exists($filePath)) {
      throw new NotFoundHttpException('File not found.');
    }

    // Track the download using the existing analytics component
    $this->trackDownload($asset);

    // Clear any output buffers
    while (ob_get_level()) {
      ob_end_clean();
    }

    // Basic headers
    /** @phpstan-ignore-next-line */
    header('Content-Type: ' . ($asset->mimeType ?? mime_content_type($filePath)));
    $inline = (bool) Yii::$app->request->get('inline', false);
    $disposition = $inline ? 'inline' : 'attachment';
    $quoted = '"' . str_replace('"', '\\"', $fileName) . '"';
    header('Content-Disposition: ' . $disposition . '; filename=' . $quoted);
    header('Content-Length: ' . filesize($filePath));

    // LiteSpeed specific
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    // Send file in chunks
    /** @var resource|false $handle */
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new NotFoundHttpException('Unable to open file.');
    }
    $chunkSize = 8192; // Smaller chunks sometimes work better with LiteSpeed

    while (!feof($handle)) {
      echo fread($handle, $chunkSize);
      flush();
    }

    fclose($handle);
    exit; // Direct exit instead of Yii::$app->end()
    }
    
    /**
     * Track the download in analytics_element_views with type="download"
     * 
     * @param Asset $asset
     * @return bool
     */
    private function trackDownload(Asset $asset): bool
    {
        // Skip if analytics component isn't available
        if (!isset(Yii::$app->crelishAnalytics) || !Yii::$app->crelishAnalytics->enabled) {
            return false;
        }
        
        // Get page UUID from referrer or fallback to entry point
        /** @var array{uuid?: string}|null $entryPoint */
        $entryPoint = Yii::$app->controller->entryPoint ?? null;
        /** @var string $pageUuid */
        $pageUuid = $entryPoint['uuid'] ?? 'direct';
            
        // Track using the existing trackElementView method with type="download"
        /** @phpstan-ignore-next-line */
        return Yii::$app->crelishAnalytics->trackElementView(
            /** @phpstan-ignore-next-line */
            $asset->uuid,
            'asset',
            $pageUuid,
            'download'
        );
    }
    
}