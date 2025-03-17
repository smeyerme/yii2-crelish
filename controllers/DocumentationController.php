<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use Yii;
use yii\helpers\FileHelper;
use yii\web\NotFoundHttpException;
use Parsedown;

/**
 * DocumentationController handles displaying markdown documentation.
 */
class DocumentationController extends CrelishBaseController
{
    /**
     * @var string The documentation directory
     */
    private $docsDir;
    
    /**
     * @var Parsedown The markdown parser
     */
    private $parsedown;
    
    /**
     * @var array List of documentation files
     */
    private $docFiles = [];
    
    /**
     * @var string Security nonce for CSP
     */
    public $nonce;
    
    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        $this->docsDir = Yii::getAlias('@giantbits/crelish/docs');
        
        // Initialize Parsedown
        $this->parsedown = new Parsedown();
        $this->parsedown->setSafeMode(true);
        
        // Generate nonce for CSP
        $this->nonce = Yii::$app->security->generateRandomString(16);
        
        // Set layout parameters for documentation mode
        $this->view->params['isDocumentationMode'] = true;
        // We don't need the standard sidebar toggle for documentation
        $this->view->params['headerBarLeft'] = [];
        
        // Get documentation files and make them available in the view
        $this->docFiles = $this->getDocFiles();
        $this->view->params['documentationFiles'] = $this->docFiles;
    }
    
    /**
     * Displays the documentation index page
     * 
     * @return string
     */
    public function actionIndex()
    {
        $content = '';
        
        // If README.md exists, use it as the index content
        if (file_exists($this->docsDir . '/README.md')) {
            $content = $this->parsedown->text(file_get_contents($this->docsDir . '/README.md'));
        }
        
        // Set the current page for highlighting in the sidebar
        $this->view->params['currentDocPage'] = 'README';
        
        return $this->render('index.twig', [
            'files' => $this->docFiles,
            'content' => $content
        ]);
    }
    
    /**
     * Displays a specific documentation page
     * 
     * @param string $page The documentation page to display
     * @return string
     * @throws NotFoundHttpException if the page doesn't exist
     */
    public function actionRead($page)
    {
        $filePath = $this->docsDir . '/' . $page . '.md';
        
        if (!file_exists($filePath)) {
            throw new NotFoundHttpException('The requested documentation page does not exist.');
        }
        
        // Get the content for the requested page
        $content = $this->parsedown->text(file_get_contents($filePath));
        
        // Set the current page for highlighting in the sidebar
        $this->view->params['currentDocPage'] = $page;
        
        // Important: Use the same template as index but with different content
        return $this->render('index.twig', [
            'files' => $this->docFiles, 
            'content' => $content,
            'currentPage' => $page
        ]);
    }
    
    /**
     * Gets a list of available documentation files
     * 
     * @return array
     */
    private function getDocFiles()
    {
        $files = FileHelper::findFiles($this->docsDir, [
            'only' => ['*.md'],
            'recursive' => false
        ]);
        
        $result = [];
        foreach ($files as $file) {
            $filename = basename($file, '.md');
            $title = $this->getDocTitle($file);
            
            $result[] = [
                'filename' => $filename,
                'title' => $title ?: $filename
            ];
        }
        
        // Sort files, but put README.md first if it exists
        usort($result, function($a, $b) {
            if ($a['filename'] === 'README') return -1;
            if ($b['filename'] === 'README') return 1;
            return strcmp($a['title'], $b['title']);
        });
        
        return $result;
    }
    
    /**
     * Extract the title from a markdown file
     * 
     * @param string $filePath Path to the markdown file
     * @return string|null
     */
    private function getDocTitle($filePath)
    {
        $content = file_get_contents($filePath);
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }
} 