<?php

namespace giantbits\crelish\actions;

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
     * @return Response
     * @throws NotFoundHttpException
     */
  public function run()
  {
    // Get the asset UUID
    $uuid = Yii::$app->request->get('uuid');
    if (empty($uuid)) {
      throw new NotFoundHttpException('No asset specified.');
    }

    // Get the asset
    $asset = \app\workspace\models\Asset::findOne($uuid);
    if (!$asset) {
      throw new NotFoundHttpException('Asset not found.');
    }

    // Build the file path
    $pathName = $asset->pathName;
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
    header('Content-Type: ' . ($asset->mimeType ?? mime_content_type($filePath)));
    $disposition = Yii::$app->request->get('inline', false) ? 'inline' : 'attachment';
    $quoted = '"' . str_replace('"', '\\"', $fileName) . '"';
    header('Content-Disposition: ' . $disposition . '; filename=' . $quoted);
    header('Content-Length: ' . filesize($filePath));

    // LiteSpeed specific
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    // Send file in chunks
    $handle = fopen($filePath, 'rb');
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
     * @param \app\workspace\models\Asset $asset
     * @return bool
     */
    private function trackDownload($asset)
    {
        // Skip if analytics component isn't available
        if (!isset(Yii::$app->crelishAnalytics) || !Yii::$app->crelishAnalytics->enabled) {
            return false;
        }
        
        // Get page UUID from referrer or fallback to entry point
        $pageUuid = isset(Yii::$app->controller->entryPoint['uuid']) 
            ? Yii::$app->controller->entryPoint['uuid'] 
            : 'direct';
            
        // Track using the existing trackElementView method with type="download"
        return Yii::$app->crelishAnalytics->trackElementView(
            $asset->uuid,
            'asset',
            $pageUuid,
            'download'
        );
    }
    
    /**
     * Get MIME type of a file
     * 
     * @param string $filePath
     * @return string
     */
    private function getMimeType($filePath)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        return $mimeType ?: 'application/octet-stream';
    }
}