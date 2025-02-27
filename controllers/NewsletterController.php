<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\MjmlGenerator;
use giantbits\crelish\components\MjmlService;
use giantbits\crelish\models\Bulletin;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use function _\map;

class NewsletterController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  public function behaviors(): array
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'only' => ['create', 'index', 'delete', 'update', 'export', 'draft', 'clone'],
        'rules' => [
          [
            'allow' => true,
            'roles' => ['@'],
          ],
        ],
      ],
    ];
  }

  /**
   * @throws BadRequestHttpException
   */
  public function beforeAction($action): bool
  {
    $this->enableCsrfValidation = false;

    return parent::beforeAction($action);
  }

  public function actionIndex(): string
  {
    $this->view->title = 'Newsletters';

    $dataProvider = new ActiveDataProvider([
      'query' => Bulletin::find(),
      'pagination' => [
        'pageSize' => 20,
      ],
    ]);

    $checkCol = [
      [
        'class' => 'giantbits\crelish\components\CrelishCheckboxColumn',
      ]
    ];

    $columns = [
      [
        'attribute' => 'title',
      ],
      [
        'attribute' => 'date',
        'format' => 'date',
      ],
      [
        'attribute' => 'status'
      ]
    ];

    $columns = array_merge($checkCol, $columns);

    $columns = map($columns, function ($item) {

      if (key_exists('attribute', $item) && $item['attribute'] === 'status') {
        $item['format'] = 'raw';
        $item['label'] = 'Status';
        $item['value'] = function ($data) {
          switch ($data['status']) {
            case 1:
              $state = 'Published';
              break;
            default:
              $state = 'Draft';
          };

          return $state;
        };
      }

      return $item;
    });

    $rowOptions = function ($model, $key, $index, $grid) {
      return ['onclick' => 'location.href="create?ctype=bulletin&uuid=' . $model['uuid'] . '";'];
    };

    return $this->render('index.twig',[
      'dataProvider' => $dataProvider,
      'columns' => $columns,
      'rowOptions' => $rowOptions,
    ]);
  }

  public function actionCreateTable()
  {
    $db = Yii::$app->db;
    $transaction = $db->beginTransaction();

    try {
      $command = $db->createCommand();
      $command->createTable('{{%bulletin}}', [
        'uuid' => $db->schema->createColumnSchemaBuilder('string', 36)->notNull()->append('PRIMARY KEY'),
        'title' => $db->schema->createColumnSchemaBuilder('string')->notNull(),
        'date' => $db->schema->createColumnSchemaBuilder('date')->notNull(),
        'content' => $db->schema->createColumnSchemaBuilder('text')->notNull(),
        'status' => $db->schema->createColumnSchemaBuilder('smallint')->notNull()->defaultValue(0),
        'published_url' => $db->schema->createColumnSchemaBuilder('string'),
        'created_at' => $db->schema->createColumnSchemaBuilder('integer'),
        'updated_at' => $db->schema->createColumnSchemaBuilder('integer'),
      ])->execute();

      $transaction->commit();
      return 'Table created successfully';
    } catch (\Exception $e) {
      $transaction->rollBack();
      return 'Error creating table: ' . $e->getMessage();
    }
  }

  public function actionCreate(): string
  {
    // Get the AssetManager instance
    $assetManager = Yii::$app->assetManager;

    // Define the source path of your JS file
    $sourcePath = Yii::getAlias('@vendor/giantbits/yii2-crelish/resources/newsletter/dist/newsletter-builder.js');

    // Publish the file and get the published URL
    $publishedUrl = $assetManager->publish($sourcePath, [
      'forceCopy' => YII_DEBUG,
      'appendTimestamp' => true,
    ])[1];

    // Register the script in the view
    $this->view->registerJsFile($publishedUrl);


    return $this->render('editor.twig');
  }

  public function actionDraft()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $data = Yii::$app->request->getBodyParams();

    if(!empty($data['id'])) {
      $newsletter = Bulletin::findOne($data['id']);
    } else {
      $newsletter = new Bulletin();
    }

    $newsletter->title = $data['title'];
    $newsletter->date = $data['date'];
    $newsletter->content = Json::encode($data['sections']);
    $newsletter->status = 0;

    if ($newsletter->save()) {
      return [
        'id' => $newsletter->uuid,
        'status' => 'success',
        'message' => 'Newsletter created successfully'
      ];
    } else {
      return $this->asError('Failed to create newsletter: ' . implode(', ', $newsletter->getErrorSummary(true)));
    }
  }

  /**
   * Update an existing newsletter
   */
  public function actionUpdate()
  {
    Yii::$app->response->format = Response::FORMAT_JSON;

    $data = Yii::$app->request->getBodyParams();

    $newsletter = Bulletin::findOne($data['id']);
    if (!$newsletter) {
      return $this->asError('Newsletter not found', 404);
    }

    $newsletter->title = $data['title'];
    $newsletter->date = $data['date'];
    $newsletter->content = Json::encode($data['sections']);

    if ($newsletter->save()) {
      return [
        'status' => 'success',
        'message' => 'Newsletter updated successfully'
      ];
    } else {
      return $this->asError('Failed to update newsletter: ' . implode(', ', $newsletter->getErrorSummary(true)));
    }
  }

  /**
   * Clone an existing newsletter
   * Creates a copy with status=0 and prefix "COPY:" in the title
   */
  public function actionClone(): array
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    $data = Yii::$app->request->getBodyParams();

    // Find the source newsletter to clone
    $sourceNewsletter = Bulletin::findOne($data['id']);
    if (!$sourceNewsletter) {
      return $this->asError('Source newsletter not found', 404);
    }

    // Create a new newsletter as a clone
    $clonedNewsletter = new Bulletin();
    $clonedNewsletter->title = 'COPY: ' . $sourceNewsletter->title;
    $clonedNewsletter->date = $sourceNewsletter->date;
    $clonedNewsletter->content = $sourceNewsletter->content; // Copy the JSON content
    $clonedNewsletter->status = 0; // Set as draft

    if ($clonedNewsletter->save()) {
      return [
        'status' => 'success',
        'message' => 'Newsletter cloned successfully',
        'id' => $clonedNewsletter->uuid
      ];
    } else {
      return $this->asError('Failed to clone newsletter: ' . implode(', ', $clonedNewsletter->getErrorSummary(true)));
    }
  }

  public function actionPreview(): array
  {

    Yii::$app->response->format = Response::FORMAT_JSON;
    $data = Yii::$app->request->getBodyParams();

    // Create temporary newsletter structure for preview
    $newsletter = [
      'title' => $data['title'],
      'date' => $data['date'],
      'sections' => $data['sections'],
    ];

    try {
      // Generate MJML code
      $mjmlGenerator = new MjmlGenerator(
        Yii::$app->assetManager,
        Url::base(true)
      );
      $mjml = $mjmlGenerator->generateMjml($newsletter);

      // Convert MJML to HTML
      $mjmlService = new MjmlService();
      $html = $mjmlService->renderMjml($mjml);

      return [
        'html' => $html
      ];
    } catch (\Exception $e) {
      return $this->asError('Failed to generate preview: ' . $e->getMessage());
    }
  }

  public function actionLoad()
  {

    Yii::$app->response->format = Response::FORMAT_JSON;
    $data = Yii::$app->request->getBodyParams();

    // Create temporary newsletter structure for preview
    $newsletter = Bulletin::findOne($data['id']);
    if (!$newsletter) {
      return $this->asError('Newsletter not found', 404);
    }

    return [
      'id' => $newsletter->uuid,
      'title' => $newsletter->title,
      'date' => $newsletter->date,
      'sections' => Json::decode($newsletter->content),
      'status' => $newsletter->status,
      'publishedUrl' => $newsletter->published_url
    ];

  }

  /**
   * Publish a newsletter
   */
  public function actionPublish(): array
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    $data = Yii::$app->request->getBodyParams();
    $newsletter = Bulletin::findOne($data['id']);

    if (!$newsletter) {
      return $this->asError('Newsletter not found', 404);
    }

    try {
      // Generate MJML code
      $mjmlGenerator = new MjmlGenerator(
        Yii::$app->assetManager,
        Url::base(true)
    );

      $newsletterData = [
        'title' => $newsletter->title,
        'date' => $newsletter->date,
        'sections' => Json::decode($newsletter->content),
      ];
      $mjml = $mjmlGenerator->generateMjml($newsletterData);

      // Convert MJML to HTML
      $mjmlService = new MjmlService();
      $html = $mjmlService->renderMjml($mjml);

      // Save HTML file
      $filename = 'newsletter_' . $newsletter->uuid . '_' . date('Ymd') . '.html';
      $filePath = Yii::getAlias('@webroot/newsletters/') . $filename;
      $fileUrl = Url::base(true) . '/newsletters/' . $filename;

      if (!is_dir(dirname($filePath))) {
        mkdir(dirname($filePath), 0755, true);
      }

      file_put_contents($filePath, $html);

      // Update newsletter status
      $newsletter->status = 1;
      $newsletter->published_url = $fileUrl;
      $newsletter->save();

      return [
        'status' => 'success',
        'message' => 'Newsletter published successfully',
        'downloadUrl' => $fileUrl
      ];
    } catch (\Exception $e) {
      return $this->asError('Failed to publish newsletter: ' . $e->getMessage());
    }
  }

  /**
   * Format error response
   */
  protected function asError($message, $statusCode = 400): array
  {
    Yii::$app->response->statusCode = $statusCode;
    return [
      'status' => 'error',
      'message' => $message
    ];
  }
}