<?php
namespace giantbits\crelish\widgets;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\Link;

class AssetConnector extends Widget
{
  private $data;

  public function __construct($config = [])
  {

    if (!empty($config['data'])) {
      $this->processData($config['data']);
    }

    parent::__construct();
  }

  private function processData($data)
  {
    $processedData = [];

    $this->data = Json::encode($processedData);
  }

  public function run()
  {

    $postUrl = Url::to('/crelish/asset/upload.html');

    $modelProvider = new CrelishJsonDataProvider('asset', [
      'sort' => ['by' => 'systitle', 'dir' => 'desc']
    ], null);

    $out = <<<EOT
    <button type="button" class="btn btn-primary" data-toggle="modal" data-target=".bs-example-modal-lg">Large modal</button>
    
    <div class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="myLargeModalLabel" id="asset-modal">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModalLabel">Modal title</h4>
          </div>
          <div class="modal-body">
EOT;

    $out .= $this->render('assets.twig', ['dataProvider' => $modelProvider->raw()]);

    $out .= <<<EOT
          </div>
        </div>
      </div>
    </div>
    
    
    <script type="text/javascript">
      
      Dropzone.options.crelishDropZone = {
        
      };
      
      $("#assetList").on("pjax:end", function() {
        $("img.lazy").lazyload({
          container:$("#asset-modal")
        });
      });
      
      $('#asset-modal').on('shown.bs.modal', function (e) {
        
        $("#dropZone").dropzone({
          url: '$postUrl',
          paramName: "file", // The name that will be used to transfer the file
          maxFilesize: 250, // MB
          dictDefaultMessage: "<span class=\"c-badge c-badge--secondary gc-shadow__soft\">Click or drag files here to upload.</span>",
          init: function() {
            var myDropzone = this;
    
            this.on("complete", function(file) {
              setTimeout(function() {
                $.pjax.reload({container:'#assetList'});
                myDropzone.removeFile(file);
              }, 250);
            });
    
          },
          accept: function(file, done) {
            if (file.name == "justinbieber.jpg") {
              done("Naha, you don't.");
            } else { done(); }
          }
        });
        
        $("img.lazy").lazyload({
          container:$("#asset-modal"),
          event : "doIt"
        });
        
        var timeout = setTimeout(function() { $("img.lazy").trigger("doIt") }, 2500);
             
      });
     
      
    </script>

EOT;

    return $out;
  }
}
