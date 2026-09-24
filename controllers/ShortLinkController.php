<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishModelResolver;
use giantbits\crelish\components\ElementTitleResolver;
use giantbits\crelish\components\shortlinks\QrBundleService;
use giantbits\crelish\components\shortlinks\ShortLinkCode;
use giantbits\crelish\components\shortlinks\ShortLinkConfig;
use giantbits\crelish\components\shortlinks\ShortLinkResolver;
use giantbits\crelish\components\shortlinks\ShortLinkStats;
use giantbits\crelish\helpers\CrelishAnalyticsPeriod;
use giantbits\crelish\models\ShortLink;
use giantbits\crelish\plugins\assetconnector\AssetConnector;
use giantbits\crelish\widgets\CrelishAnalyticsPeriodPicker;
use Yii;
use yii\data\ActiveDataProvider;
use yii\filters\AccessControl;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Admin for short links: list, edit, QR export and statistics.
 */
class ShortLinkController extends CrelishBaseController
{
  public $layout = 'crelish.twig';

  private const BADGES = [
    ShortLink::STATUS_ONLINE => 'success',
    ShortLink::STATUS_SCHEDULED => 'info',
    ShortLink::STATUS_EXPIRED => 'secondary',
    'broken' => 'danger',
    ShortLink::STATUS_DRAFT => 'warning',
    ShortLink::STATUS_OFFLINE => 'secondary',
    ShortLink::STATUS_ARCHIVED => 'dark',
  ];

  public function behaviors(): array
  {
    return [
      'access' => [
        'class' => AccessControl::class,
        'rules' => [['allow' => true, 'roles' => ['@']]],
      ],
    ];
  }

  public function beforeAction($action): bool
  {
    if (!ShortLinkConfig::isEnabled()) {
      throw new NotFoundHttpException();
    }

    return parent::beforeAction($action);
  }

  protected function setupHeaderBar()
  {
    parent::setupHeaderBar();

    // The generic bulk delete posts to the content controller; single delete lives on the edit view
    if ($this->action && $this->action->id === 'index') {
      $this->view->params['headerBarRight'] = ['create'];
    }
  }

  public static function statusLabels(): array
  {
    return [
      ShortLink::STATUS_ONLINE => Yii::t('crelish', 'Online'),
      ShortLink::STATUS_SCHEDULED => Yii::t('crelish', 'Scheduled'),
      ShortLink::STATUS_EXPIRED => Yii::t('crelish', 'Expired'),
      'broken' => Yii::t('crelish', 'Broken target'),
      ShortLink::STATUS_DRAFT => Yii::t('crelish', 'Draft'),
      ShortLink::STATUS_OFFLINE => Yii::t('crelish', 'Offline'),
      ShortLink::STATUS_ARCHIVED => Yii::t('crelish', 'Archived'),
    ];
  }

  public function actionIndex(): string
  {
    $this->view->title = Yii::t('crelish', 'Short Links');
    $request = Yii::$app->request;
    $search = trim((string)$request->get('cr_content_filter', ''));
    $status = (string)$request->get('cr_status_filter', '');
    $now = time();

    $query = ShortLink::find()->orderBy(['updated' => SORT_DESC]);

    if ($search !== '') {
      $query->andWhere(['or', ['like', 'systitle', $search], ['like', 'code', $search]]);
    }

    $byState = [ShortLink::STATUS_OFFLINE => 0, ShortLink::STATUS_DRAFT => 1, ShortLink::STATUS_ARCHIVED => 3];

    if (isset($byState[$status])) {
      $query->andWhere(['state' => $byState[$status]]);
    } elseif ($status === ShortLink::STATUS_SCHEDULED) {
      $query->andWhere(['state' => ShortLink::STATE_ONLINE])->andWhere(['>', 'valid_from', $now]);
    } elseif ($status === ShortLink::STATUS_EXPIRED) {
      $query->andWhere(['state' => ShortLink::STATE_ONLINE])->andWhere(['<', 'valid_until', $now]);
    } elseif ($status === ShortLink::STATUS_ONLINE || $status === 'broken') {
      $query->andWhere(['state' => ShortLink::STATE_ONLINE])
        ->andWhere(['or', ['valid_from' => null], ['<=', 'valid_from', $now]])
        ->andWhere(['or', ['valid_until' => null], ['>=', 'valid_until', $now]]);
    }

    $dataProvider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 25]]);
    $links = $dataProvider->getModels();
    $summaries = (new ShortLinkStats())->summaries(array_map(fn(ShortLink $link) => $link->uuid, $links));
    $resolver = new ShortLinkResolver();
    $rows = [];

    foreach ($links as $link) {
      $linkStatus = $link->getStatus($now);

      if ($linkStatus === ShortLink::STATUS_ONLINE && $link->target_type === ShortLink::TARGET_CONTENT && $resolver->resolveContent($link) === null) {
        $linkStatus = 'broken';
      }

      $rows[$link->uuid] = [
        'status' => $linkStatus,
        'badge' => self::BADGES[$linkStatus],
        'target' => $this->targetLabel($link),
        'stats' => $summaries[$link->uuid],
      ];
    }

    if ($status === 'broken') {
      $rows = array_filter($rows, fn(array $row) => $row['status'] === 'broken');
    }

    return $this->render('index.twig', [
      'dataProvider' => $dataProvider,
      'rows' => $rows,
      'status' => $status,
      'statuses' => self::statusLabels(),
    ]);
  }

  public function actionCreate()
  {
    $link = new ShortLink();
    $link->state = ShortLink::STATE_ONLINE;
    $link->target_type = ShortLink::TARGET_URL;
    $link->qr_size_mm = 30;
    $link->qr_color = '#000000';
    $link->qr_quiet_zone = 4;
    $link->code = ShortLinkCode::generate(fn(string $code) => ShortLink::find()->where(['code' => $code])->exists());

    return $this->edit($link);
  }

  public function actionUpdate(string $uuid)
  {
    return $this->edit($this->findLink($uuid));
  }

  public function actionDelete(string $uuid): Response
  {
    if ($this->findLink($uuid)->delete()) {
      Yii::$app->session->setFlash('success', Yii::t('crelish', 'Short link deleted.'));
    } else {
      Yii::$app->session->setFlash('warning', Yii::t('crelish', 'Short link could not be deleted.'));
    }

    return $this->redirect(['index']);
  }

  /**
   * Record search for the target picker
   */
  public function actionTargets(string $ctype, string $q = ''): array
  {
    Yii::$app->response->format = Response::FORMAT_JSON;
    $q = trim($q);

    if (mb_strlen($q) < 2 || !in_array($ctype, ShortLinkResolver::resolvableTypes(), true)) {
      return [];
    }

    try {
      $class = CrelishModelResolver::getModelClass($ctype);

      $records = $class::find()
        ->select(['uuid', 'systitle'])
        ->where(['like', 'systitle', $q])
        ->orderBy(['systitle' => SORT_ASC])
        ->limit(20)
        ->asArray()
        ->all();
    } catch (\Throwable $e) {
      Yii::warning('Short links: target search failed for ctype "' . $ctype . '": ' . $e->getMessage(), 'shortlink');

      return [];
    }

    return array_map(fn(array $record) => ['uuid' => $record['uuid'], 'title' => (string)$record['systitle']], $records);
  }

  private function edit(ShortLink $link)
  {
    $request = Yii::$app->request;

    if ($request->isPost && $link->loadForm($request->post())) {
      if ($link->save()) {
        Yii::$app->session->setFlash('success', Yii::t('crelish', 'Short link saved.'));

        return $request->post('save_n_return') === '1'
          ? $this->redirect(['index'])
          : $this->redirect(['update', 'uuid' => $link->uuid]);
      }

      Yii::$app->session->setFlash('error', Yii::t('crelish', 'Please correct the highlighted fields.'));
    }

    $this->view->title = $link->isNewRecord ? Yii::t('crelish', 'New short link') : $link->systitle;

    return $this->render('edit.twig', array_merge([
      'link' => $link,
      'targetTypes' => ShortLinkResolver::resolvableTypes(),
      'targetTitle' => $link->target_uuid ? ElementTitleResolver::resolve($link->target_uuid, (string)$link->target_ctype) : '',
      'languages' => Yii::$app->params['crelish']['languages'] ?? [],
      'siteFallback' => ShortLinkConfig::siteFallbackUrl(),
      'resolvesTo' => $link->isNewRecord ? null : (new ShortLinkResolver())->resolve($link),
      'csrfParam' => $request->csrfParam,
      'csrfToken' => $request->csrfToken,
    ], $this->qrAndStatsParams($link)));
  }

  /**
   * View parameters for the QR block and statistics tab
   */
  protected function qrAndStatsParams(ShortLink $link): array
  {
    if ($link->isNewRecord) {
      return ['hasLogo' => false, 'qrFiles' => [], 'logoWidget' => '', 'stats' => null, 'periodPicker' => '', 'logoMinMm' => QrBundleService::LOGO_MIN_MM];
    }

    $request = Yii::$app->request;
    [$startDate, $endDate, $period] = CrelishAnalyticsPeriod::resolve(
      $request->get('period', CrelishAnalyticsPeriod::FALLBACK),
      $request->get('start_date'),
      $request->get('end_date')
    );
    $hasLogo = QrBundleService::logoPathFor($link) !== null;

    $this->view->registerJsFile('https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', ['position' => \yii\web\View::POS_HEAD]);

    return [
      'hasLogo' => $hasLogo,
      'qrFiles' => QrBundleService::fileNames($link, $hasLogo),
      'logoWidget' => $this->logoWidget($link),
      'logoMinMm' => QrBundleService::LOGO_MIN_MM,
      'stats' => (new ShortLinkStats())->forLink($link->uuid, $startDate, $endDate),
      'periodPicker' => CrelishAnalyticsPeriodPicker::widget(['period' => $period, 'startDate' => $startDate, 'endDate' => $endDate]),
    ];
  }

  /**
   * Live SVG preview; size, colour and quiet zone can be overridden from the unsaved form
   */
  public function actionQrPreview(string $uuid, string $variant = 'plain'): Response
  {
    $link = $this->findLink($uuid);
    $request = Yii::$app->request;
    $overrides = array_filter([
      'qr_size_mm' => $request->get('size'),
      'qr_color' => $request->get('color'),
      'qr_quiet_zone' => $request->get('quiet'),
    ], fn($value) => $value !== null && $value !== '');

    $link->setAttributes($overrides);
    if ($overrides !== [] && !$link->validate(array_keys($overrides))) {
      $link->refresh();
    }

    $response = Yii::$app->response;
    $response->format = Response::FORMAT_RAW;
    $response->headers->set('Content-Type', 'image/svg+xml');
    $response->headers->set('Cache-Control', 'no-store');

    try {
      $response->data = QrBundleService::forLink($link)->renderer($link, $variant)->svg();
    } catch (\RuntimeException $e) {
      $response->statusCode = 404;
      $response->data = '';
    }

    return $response;
  }

  public function actionQrDownload(string $uuid, ?string $file = null): Response
  {
    $link = $this->findLink($uuid);
    $bundle = QrBundleService::forLink($link);
    $mimeTypes = ['svg' => 'image/svg+xml', 'eps' => 'application/postscript', 'pdf' => 'application/pdf', 'png' => 'image/png'];

    try {
      if ($file === null) {
        $path = $bundle->zip($link);

        if ($bundle->logoError !== null) {
          Yii::$app->session->setFlash('warning', Yii::t('crelish', 'The logo variant was skipped: {error}', ['error' => $bundle->logoError]));
        }

        $response = Yii::$app->response->sendFile($path, $link->code . '-qr.zip', ['mimeType' => 'application/zip']);
        $response->on(Response::EVENT_AFTER_SEND, static function () use ($path) {
          @unlink($path);
        });

        return $response;
      }

      $files = $bundle->files($link);
      $extension = pathinfo($file, PATHINFO_EXTENSION);

      if (!isset($files[$file], $mimeTypes[$extension])) {
        throw new NotFoundHttpException();
      }

      return Yii::$app->response->sendContentAsFile($files[$file], str_replace('/', '-', $file), ['mimeType' => $mimeTypes[$extension]]);
    } catch (\RuntimeException $e) {
      Yii::error("QR export failed for short link {$link->uuid}: " . $e->getMessage(), 'shortlink');
      Yii::$app->session->setFlash('error', Yii::t('crelish', 'The QR code could not be generated: {error}', ['error' => $e->getMessage()]));

      return $this->redirect(['update', 'uuid' => $link->uuid]);
    }
  }

  private function logoWidget(ShortLink $link): string
  {
    return AssetConnector::widget([
      'formKey' => 'logo_asset_uuid',
      'field' => (object)[
        'key' => 'logo_asset_uuid',
        'label' => Yii::t('crelish', 'QR logo'),
        'config' => (object)[],
        'rules' => [],
      ],
      'data' => $link->logo_asset_uuid,
      'model' => $link,
    ]);
  }

  private function targetLabel(ShortLink $link): string
  {
    if ($link->target_type === ShortLink::TARGET_URL) {
      return mb_strimwidth((string)$link->target_url, 0, 60, '…');
    }

    $title = ElementTitleResolver::resolve((string)$link->target_uuid, (string)$link->target_ctype);

    return $link->target_ctype . ': ' . ($title ?? $link->target_uuid);
  }

  private function findLink(string $uuid): ShortLink
  {
    $link = ShortLink::findOne($uuid);

    if ($link === null) {
      throw new NotFoundHttpException(Yii::t('crelish', 'Short link not found.'));
    }

    return $link;
  }
}
