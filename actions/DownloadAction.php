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
        
        // Prepare the response
        $response = Yii::$app->response;
        $response->format = Response::FORMAT_RAW;
        
        // Set headers for download
        $disposition = Yii::$app->request->get('inline', false) ? 'inline' : 'attachment';
        $response->headers->add('Content-Type', $asset->mimeType ?? $this->getMimeType($filePath));
        $response->headers->add('Content-Disposition', $disposition . '; filename="' . $fileName . '"');
        $response->headers->add('Content-Length', filesize($filePath));
        
        // Set the file as the response stream
        $response->stream = fopen($filePath, 'rb');
        
        return $response;
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