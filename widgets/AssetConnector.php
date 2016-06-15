<?php
namespace giantbits\crelish\widgets;

use giantbits\crelish\components\CrelishJsonDataProvider;
use yii\base\Widget;
use yii\helpers\Json;
use yii\helpers\Url;

class AssetConnector extends Widget
{
  public $data;
  public $formKey;

  public function init()
  {
    parent::init();
  }

  public function run()
  {
    $formKey = $this->formKey;
    $data = $this->data;
    $postUrl = Url::to('/crelish/asset/upload.html');

    $modelProvider = new CrelishJsonDataProvider('asset', [
      'sort' => ['by' => 'systitle', 'dir' => 'desc']
    ], null);

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label col-sm-3" for="crelishdynamicmodel-body">Asset</label>
      <div class="col-sm-6">
        <input type="text" name="CrelishDynamicJsonModel[$formKey]" id="CrelishDynamicJsonModel_$formKey" value="$data" />
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target=".bs-example-modal-lg">Large modal</button>
        <div class="help-block help-block-error "></div>
      </div>
    </div>
    
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
         
      var activateAssetAssignment = function() {
        $("#asset-modal a.asset-item").each(function() {
          $(this).on('click', function(e) {
            e.preventDefault();
            $("#CrelishDynamicJsonModel_$this->formKey").val($(this).data("asset"));
            $("#asset-modal").modal('hide');
          });
        });
      };      
         
      $("#assetList").on("pjax:end", function() {
        $("img.lazy").lazyload({
          container:$("#asset-modal")
        });
        activateAssetAssignment();
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
        
        var timeout = setTimeout(function() { 
          $("img.lazy").trigger("doIt");
          activateAssetAssignment();
        }, 2500);
             
      });
     
      
    </script>

EOT;

    return $out;
  }
}
