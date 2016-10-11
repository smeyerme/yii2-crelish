<?php
namespace giantbits\crelish\plugins\assetconnector;

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
    $imgWrapper = $stringValue ="";

    if(is_array($this->data)) {
      $data = (object) $this->data;
      $stringValue = Json::encode($this->data);

      // Load asset.
      $asset = new CrelishJsonDataProvider($data->ctype, [], $data->uuid);
      $asset = (object) $asset->one();

      $imgWrapper = <<<EOT
      <div style="width: 200px;">
          <div class="c-card c-card--high gc-m--lbr-1 gc-bc--palette-white">
            <div class="c-card__content c-heading dz-filename gc-bc--palette-silver gc-to--ellipsis" id="asset-title">
              $asset->title
            </div>
            <div class="c-card__content">
              <div class="image gc-o--hidden" style="background-color: $asset->colormain_hex;">
                <img class="lazy" data-original="$asset->src" height="140" src="$asset->src" id="asset-path" />
              </div>
              <div class="description gc-box--h-60 gc-to--ellipsis" id="asset-description">
                $asset->description
              </div>
            </div>
          </div>
        </div>
EOT;

    } else {
      $data = new \stdClass();

      $imgWrapper = <<<EOT
      <div style="width: 200px;">
          <div class="c-card c-card--high gc-m--lbr-1 gc-bc--palette-white">
            <div class="c-card__content c-heading dz-filename gc-bc--palette-silver gc-to--ellipsis" id="asset-title">

            </div>
            <div class="c-card__content">
              <div class="image gc-o--hidden">
                <img class="lazy" data-original="" height="140" src="" id="asset-path" />
              </div>
              <div class="description gc-box--h-60 gc-to--ellipsis" id="asset-description">

              </div>
            </div>
          </div>
        </div>
EOT;
    }

    $postUrl = Url::to('/crelish/asset/upload.html');

    $modelProvider = new CrelishJsonDataProvider('asset', [
      'sort' => ['by' => 'systitle', 'dir' => 'desc']
    ], null);

    $out = <<<EOT
    <div class="form-group field-crelishdynamicmodel-body required">
      <label class="control-label col-sm-3" for="crelishdynamicmodel-body">Asset</label>
      <div class="col-sm-6">

        $imgWrapper

        <input type="hidden" name="CrelishDynamicJsonModel[$formKey]" id="CrelishDynamicJsonModel_$formKey" value='$stringValue' />
        <button type="button" class="btn btn-primary" data-toggle="modal" data-target=".bs-example-modal-lg">Select asset</button>
        <div class="help-block help-block-error "></div>
      </div>
    </div>

    <div class="modal fade bs-example-modal-lg" tabindex="-1" role="dialog" aria-labelledby="myLargeModalLabel" id="asset-modal">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <h4 class="modal-title" id="myModalLabel">Asset selection</h4>
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
            var asset = $(this).data("asset");
            $("#CrelishDynamicJsonModel_$this->formKey").val(JSON.stringify({ ctype: "asset", uuid: asset.uuid }));
            $("#asset-path").attr("src", asset.src);
            $("#asset-title").text(asset.title);
            $("#asset-description").text(asset.description);
            $("#asset-modal").modal('hide');
          });
        });
      };

      $("#assetList").on("pjax:end", function() {
        $("img.lazy").lazyload({
          container:$(".modal-body"),
          placeholder: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII='
        });
        activateAssetAssignment();
      });

      $('#asset-modal').on('shown.bs.modal', function (e) {
        // Enable scrolling.
        $('#asset-modal').perfectScrollbar();

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
          placeholder: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAAA1BMVEUAAACnej3aAAAAAXRSTlMAQObYZgAAAApJREFUCNdjYAAAAAIAAeIhvDMAAAAASUVORK5CYII=',
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
