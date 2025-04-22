<?php

namespace giantbits\crelish\modules\api\controllers;

use Yii;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use yii\data\Pagination;
use yii\helpers\ArrayHelper;

/**
 * Content API controller for Crelish CMS
 */
class ContentController extends BaseController
{
  /**
   * @inheritdoc
   */
  public function behaviors(): array
  {
    $behaviors = parent::behaviors();

    // Add specific access control to content endpoints
    $behaviors['access'] = [
      'class' => 'yii\filters\AccessControl',
      'rules' => [
        [
          'allow' => true,
          'actions' => ['index', 'view'],
          'roles' => ['?', '@'], // Allow both guest and authenticated users for read operations
        ],
        [
          'allow' => true,
          'actions' => ['create', 'update', 'delete'],
          'roles' => ['@'], // Only authenticated users for write operations
        ],
      ],
    ];

    return $behaviors;
  }

  /**
   * Get a list of content items by type
   *
   * @param string $type Content type
   * @param int $page Page number (default: 1)
   * @param int $pageSize Number of items per page (default: 20)
   * @param string|null $sort Sort field (default: null)
   * @param string $order Sort order (asc or desc, default: asc)
   * @param string|null $filter Filter query (default: null)
   * @return array
   */
  public function actionIndex(
    string  $type,
    int     $page = 1,
    int     $pageSize = 20,
    ?string $sort = null,
    string  $order = 'asc',
    ?string $filter = null
  ): array
  {
    try {
      // Get content service
      $contentService = Yii::$app->get('contentService');

      // Validate content type
      if (!$contentService->contentTypeExists($type)) {
        Yii::error("Content type '{$type}' does not exist", __METHOD__);
        return $this->createResponse(
          null,
          false,
          "Content type '{$type}' does not exist",
          404
        );
      }

      // Use CrelishDataManager directly for better performance
      $settings = [
        'pageSize' => $pageSize,
      ];

      // Add sort settings if provided
      if ($sort !== null) {
        $settings['sort'] = [$sort => $order === 'asc' ? SORT_ASC : SORT_DESC];
      }

      // Add filter settings if provided
      if ($filter !== null) {
        $filterArray = [];
        $filterParts = explode(',', $filter);

        foreach ($filterParts as $part) {
          $criteria = explode(':', $part);

          if (count($criteria) === 3) {
            [$field, $operator, $value] = $criteria;

            if ($operator === 'eq') {
              $filterArray[$field] = $value;
            }
          } elseif (count($criteria) === 2){
            [$field, $value] = $criteria;
            $filterArray[$field] = $value;
          }
        }

        if (!empty($filterArray)) {
          $settings['filter'] = $filterArray;
        }

        if(isset($settings['filter']['systitle']) && count($settings['filter']) === 1 && !empty($settings['filter']['systitle'])) {
          $settings['filter']['freesearch'] = $settings['filter']['systitle'];
          unset($settings['filter']['systitle']);
        }
      }

      // Create data manager
      $dataManager = new \giantbits\crelish\components\CrelishDataManager($type, $settings, null,true);

      // Get data
      $result = $dataManager->all();

      // Prepare response
      $response = [
        'items' => $result['models'],
        'pagination' => [
          'totalItems' => $result['pagination']->totalCount,
          'pageSize' => $result['pagination']->pageSize,
          'currentPage' => $result['pagination']->page + 1, // Convert to 1-based
          'totalPages' => ceil($result['pagination']->totalCount / $result['pagination']->pageSize),
        ],
      ];

      return $this->createResponse($response);
    } catch (\Exception $e) {
      Yii::error("Error in ContentController::actionIndex: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
      return $this->createResponse(
        null,
        false,
        'An error occurred while fetching content items: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * Get a single content item by ID
   *
   * @param string $type Content type
   * @param string $id Content item ID
   * @return array
   * @throws NotFoundHttpException
   */
  public function actionView(string $type, string $id): array
  {
    try {
      // Get content service
      $contentService = Yii::$app->get('contentService');

      // Validate content type
      if (!$contentService->contentTypeExists($type)) {
        Yii::error("Content type '{$type}' does not exist", __METHOD__);
        return $this->createResponse(
          null,
          false,
          "Content type '{$type}' does not exist",
          404
        );
      }

      // Use CrelishDataManager directly
      $dataManager = new \giantbits\crelish\components\CrelishDataManager($type, [], $id);

      // Get content item
      $item = $dataManager->one();

      if ($item === null) {
        Yii::error("Content item with ID '{$id}' not found", __METHOD__);
        return $this->createResponse(
          null,
          false,
          "Content item with ID '{$id}' not found",
          404
        );
      }

      return $this->createResponse($item);
    } catch (\Exception $e) {
      Yii::error("Error in ContentController::actionView: " . $e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
      return $this->createResponse(
        null,
        false,
        'An error occurred while fetching the content item: ' . $e->getMessage(),
        500
      );
    }
  }

  /**
   * Create a new content item
   *
   * @param string $type Content type
   * @return array
   */
  public function actionCreate(string $type): array
  {
    try {
      // Get content service
      $contentService = Yii::$app->get('contentService');

      // Validate content type
      if (!$contentService->contentTypeExists($type)) {
        return $this->createResponse(
          null,
          false,
          "Content type '{$type}' does not exist",
          404
        );
      }

      // Get request data
      $data = Yii::$app->request->getBodyParams();

      if (empty($data)) {
        return $this->createResponse(
          null,
          false,
          'No data provided',
          400
        );
      }

      // Create content item
      $result = $contentService->createContent($type, $data);

      if (!$result['success']) {
        return $this->createResponse(
          $result['errors'] ?? null,
          false,
          $result['message'] ?? 'Failed to create content item',
          400
        );
      }

      return $this->createResponse(
        $result['item'],
        true,
        'Content item created successfully',
        201
      );
    } catch (\Exception $e) {
      Yii::error($e->getMessage(), __METHOD__);
      return $this->createResponse(
        null,
        false,
        'An error occurred while creating the content item',
        500
      );
    }
  }

  /**
   * Update an existing content item
   *
   * @param string $type Content type
   * @param string $id Content item ID
   * @return array
   */
  public function actionUpdate(string $type, string $id): array
  {
    try {
      // Get content service
      $contentService = Yii::$app->get('contentService');

      // Validate content type
      if (!$contentService->contentTypeExists($type)) {
        return $this->createResponse(
          null,
          false,
          "Content type '{$type}' does not exist",
          404
        );
      }

      // Check if content item exists
      $item = $contentService->getContentById($type, $id);

      if ($item === null) {
        return $this->createResponse(
          null,
          false,
          "Content item with ID '{$id}' not found",
          404
        );
      }

      // Get request data
      $data = Yii::$app->request->getBodyParams();

      if (empty($data)) {
        return $this->createResponse(
          null,
          false,
          'No data provided',
          400
        );
      }

      // Update content item
      $result = $contentService->updateContent($type, $id, $data);

      if (!$result['success']) {
        return $this->createResponse(
          $result['errors'] ?? null,
          false,
          $result['message'] ?? 'Failed to update content item',
          400
        );
      }

      return $this->createResponse(
        $result['item'],
        true,
        'Content item updated successfully'
      );
    } catch (\Exception $e) {
      Yii::error($e->getMessage(), __METHOD__);
      return $this->createResponse(
        null,
        false,
        'An error occurred while updating the content item',
        500
      );
    }
  }

  /**
   * Delete a content item
   *
   * @param string $type Content type
   * @param string $id Content item ID
   * @return array
   */
  public function actionDelete(string $type, string $id): array
  {
    try {
      // Get content service
      $contentService = Yii::$app->get('contentService');

      // Validate content type
      if (!$contentService->contentTypeExists($type)) {
        return $this->createResponse(
          null,
          false,
          "Content type '{$type}' does not exist",
          404
        );
      }

      // Check if content item exists
      $item = $contentService->getContentById($type, $id);

      if ($item === null) {
        return $this->createResponse(
          null,
          false,
          "Content item with ID '{$id}' not found",
          404
        );
      }

      // Delete content item
      $result = $contentService->deleteContent($type, $id);

      if (!$result) {
        return $this->createResponse(
          null,
          false,
          'Failed to delete content item',
          400
        );
      }

      return $this->createResponse(
        null,
        true,
        'Content item deleted successfully',
        204
      );
    } catch (\Exception $e) {
      Yii::error($e->getMessage(), __METHOD__);
      return $this->createResponse(
        null,
        false,
        'An error occurred while deleting the content item',
        500
      );
    }
  }
} 