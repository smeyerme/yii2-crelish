<?php

namespace giantbits\crelish\controllers;

use giantbits\crelish\components\CrelishBaseController;
use giantbits\crelish\components\CrelishBaseHelper;
use giantbits\crelish\components\CrelishModelResolver;
use kartik\select2\Select2Asset;
use kartik\select2\ThemeKrajeeBs5Asset;
use Yii;
use yii\web\Response;
use yii\db\Query;
use yii\db\Expression;

/**
 * Company Analytics Controller
 *
 * Provides company-specific analytics reports showing content performance
 * data for a selected company with PDF export capability.
 */
class CompanyAnalyticsController extends CrelishBaseController
{
    /**
     * Content types that have a company relationship
     */
    private const CONTENT_TYPES = ['product', 'news', 'event', 'download', 'reference', 'productcatalog'];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        Yii::$app->view->title = 'Company Analytics';

        // Register Select2 assets
        Select2Asset::register($this->view);
        ThemeKrajeeBs5Asset::register($this->view);

        // Make sure Chart.js is loaded
        $this->view->registerJsFile('https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', ['position' => \yii\web\View::POS_HEAD]);

        // Register dashboard-specific CSS
        $this->view->registerCss($this->getDashboardCss());
    }

    /**
     * Override the setupHeaderBar method for dashboard-specific components
     */
    protected function setupHeaderBar()
    {
        $this->view->params['headerBarLeft'] = ['toggle-sidebar'];
        $this->view->params['headerBarRight'] = [];

        $action = $this->action ? $this->action->id : null;

        switch ($action) {
            case 'index':
                $this->view->params['headerBarLeft'][] = ['title', Yii::t('crelish', 'Company Analytics')];
                break;
            default:
                break;
        }
    }

    /**
     * Dashboard index - company analytics overview
     */
    public function actionIndex()
    {
        $period = Yii::$app->request->get('period', 'month');
        $companyUuid = Yii::$app->request->get('company_uuid', '');
        $companyName = '';

        // Fetch company name if UUID is provided
        if (!empty($companyUuid)) {
            $company = (new Query())
                ->select(['systitle'])
                ->from('{{%company}}')
                ->where(['uuid' => $companyUuid])
                ->one();

            if ($company) {
                $companyName = $company['systitle'] ?: 'Unnamed Company';
            }
        }

        return $this->render('index.twig', [
            'period' => $period,
            'companyUuid' => $companyUuid,
            'companyName' => $companyName
        ]);
    }

    /**
     * Get list of companies for dropdown
     */
    public function actionCompanies()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $search = Yii::$app->request->get('search', '');

        $query = (new Query())
            ->select(['uuid', 'systitle', 'logo'])
            ->from('{{%company}}')
            ->where(['state' => 2]) // state 2 = published
            ->orderBy(['systitle' => SORT_ASC]);

        if (!empty($search)) {
            $query->andWhere(['like', 'systitle', $search]);
        }

        $companies = $query->limit(100)->all();

        // Format for Select2
        $results = [];
        foreach ($companies as $company) {
            $results[] = [
                'id' => $company['uuid'],
                'text' => $company['systitle'] ?: 'Unnamed Company',
                'logo' => $company['logo']
            ];
        }

        return ['results' => $results];
    }

    /**
     * Get overview stats (KPIs) for selected company
     */
    public function actionOverviewStats()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyUuid = Yii::$app->request->get('company_uuid');
        $period = Yii::$app->request->get('period', 'month');

        if (empty($companyUuid)) {
            return ['error' => 'Company UUID is required'];
        }

        list($startDate, $endDate) = $this->getPeriodDates($period);
        $elementUuids = $this->getCompanyElementUuids($companyUuid);

        // Get company profile stats (the company itself, not its content)
        $profileStats = (new Query())
            ->select([
                'profile_list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'profile_detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'profile_clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $companyUuid])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->one();

        if (empty($elementUuids)) {
            return [
                'total_views' => 0,
                'unique_sessions' => 0,
                'list_views' => 0,
                'detail_views' => 0,
                'clicks' => 0,
                'downloads' => 0,
                'content_count' => 0,
                'profile_list_views' => (int)($profileStats['profile_list_views'] ?? 0),
                'profile_detail_views' => (int)($profileStats['profile_detail_views'] ?? 0),
                'profile_clicks' => (int)($profileStats['profile_clicks'] ?? 0)
            ];
        }

        $stats = (new Query())
            ->select([
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->one();

        $stats['content_count'] = count($elementUuids);

        // Add company profile stats
        $stats['profile_list_views'] = (int)($profileStats['profile_list_views'] ?? 0);
        $stats['profile_detail_views'] = (int)($profileStats['profile_detail_views'] ?? 0);
        $stats['profile_clicks'] = (int)($profileStats['profile_clicks'] ?? 0);

        return $stats;
    }

    /**
     * Get stats grouped by content type
     */
    public function actionContentTypeStats()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyUuid = Yii::$app->request->get('company_uuid');
        $period = Yii::$app->request->get('period', 'month');

        if (empty($companyUuid)) {
            return ['error' => 'Company UUID is required'];
        }

        list($startDate, $endDate) = $this->getPeriodDates($period);
        $elementUuids = $this->getCompanyElementUuids($companyUuid);

        $stats = [];

        // Get stats for company's own content (products, news, events, etc.)
        if (!empty($elementUuids)) {
            $stats = (new Query())
                ->select([
                    'element_type',
                    'total_views' => 'SUM(total_views)',
                    'unique_sessions' => 'SUM(unique_sessions)',
                    'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                    'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                    'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                    'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)",
                    'unique_elements' => 'COUNT(DISTINCT element_uuid)'
                ])
                ->from('{{%analytics_element_daily}}')
                ->where(['element_uuid' => $elementUuids])
                ->andWhere(['>=', 'date', $startDate])
                ->andWhere(['<=', 'date', $endDate])
                ->groupBy(['element_type'])
                ->all();
        }

        // Get stats for jobs (external content tracked with page_uuid = companyUuid)
        $jobStats = (new Query())
            ->select([
                'element_type',
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)",
                'unique_elements' => 'COUNT(DISTINCT element_uuid)'
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['page_uuid' => $companyUuid])
            ->andWhere(['element_type' => 'job'])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->groupBy(['element_type'])
            ->one();

        // Add job stats if found
        if ($jobStats && (int)$jobStats['total_views'] > 0) {
            $stats[] = $jobStats;
        }

        // Sort by total views
        usort($stats, function($a, $b) {
            return (int)$b['total_views'] - (int)$a['total_views'];
        });

        return $stats;
    }

    /**
     * Get time series trend data
     */
    public function actionTrends()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyUuid = Yii::$app->request->get('company_uuid');
        $period = Yii::$app->request->get('period', 'month');

        if (empty($companyUuid)) {
            return ['error' => 'Company UUID is required'];
        }

        list($startDate, $endDate) = $this->getPeriodDates($period);
        $elementUuids = $this->getCompanyElementUuids($companyUuid);

        if (empty($elementUuids)) {
            return [];
        }

        $data = (new Query())
            ->select([
                'date',
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->groupBy(['date'])
            ->orderBy(['date' => SORT_ASC])
            ->all();

        return $data;
    }

    /**
     * Get top performing content items
     */
    public function actionTopContent()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyUuid = Yii::$app->request->get('company_uuid');
        $period = Yii::$app->request->get('period', 'month');
        $limit = Yii::$app->request->get('limit', 20);

        if (empty($companyUuid)) {
            return ['error' => 'Company UUID is required'];
        }

        list($startDate, $endDate) = $this->getPeriodDates($period);
        $elementUuids = $this->getCompanyElementUuids($companyUuid);

        if (empty($elementUuids)) {
            return [];
        }

        $elements = (new Query())
            ->select([
                'element_uuid',
                'element_type',
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->groupBy(['element_uuid', 'element_type'])
            ->orderBy(['total_views' => SORT_DESC])
            ->limit($limit)
            ->all();

        // Enrich with titles
        foreach ($elements as &$element) {
            $element['title'] = $this->getElementTitle($element['element_uuid'], $element['element_type'])
                ?? ucfirst($element['element_type']) . ': ' . substr($element['element_uuid'], 0, 8);

            // Calculate conversion rate
            $listViews = (int)$element['list_views'];
            $detailViews = (int)$element['detail_views'];
            $element['conversion_rate'] = $listViews > 0
                ? round(($detailViews / $listViews) * 100, 2)
                : 0;
        }

        return $elements;
    }

    /**
     * Get event type distribution for selected company
     */
    public function actionEventTypeStats()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $companyUuid = Yii::$app->request->get('company_uuid');
        $period = Yii::$app->request->get('period', 'month');

        if (empty($companyUuid)) {
            return ['error' => 'Company UUID is required'];
        }

        list($startDate, $endDate) = $this->getPeriodDates($period);
        $elementUuids = $this->getCompanyElementUuids($companyUuid);

        if (empty($elementUuids)) {
            return [];
        }

        $stats = (new Query())
            ->select([
                'event_type',
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)'
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->groupBy(['event_type'])
            ->orderBy(['total_views' => SORT_DESC])
            ->all();

        return $stats;
    }

    /**
     * Export company analytics report as PDF
     */
    public function actionExportPdf()
    {
        $companyUuid = Yii::$app->request->get('company_uuid');
        $period = Yii::$app->request->get('period', 'month');

        if (empty($companyUuid)) {
            Yii::$app->session->setFlash('error', 'Company UUID is required');
            return $this->redirect(['index']);
        }

        // Get company data
        $company = (new Query())
            ->select(['uuid', 'systitle', 'logo'])
            ->from('{{%company}}')
            ->where(['uuid' => $companyUuid])
            ->one();

        if (!$company) {
            Yii::$app->session->setFlash('error', 'Company not found');
            return $this->redirect(['index']);
        }

        // Resolve logo to absolute file path for mPDF
        $logoPath = null;
        if (!empty($company['logo'])) {
            $logoPath = $this->resolveAssetPath($company['logo']);
        }

        list($startDate, $endDate) = $this->getPeriodDates($period);
        $elementUuids = $this->getCompanyElementUuids($companyUuid);

        // Get company profile stats (the company itself, not its content)
        $profileStats = (new Query())
            ->select([
                'profile_list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'profile_detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'profile_clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $companyUuid])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->one();

        // Gather all data for PDF
        $overviewStats = $this->getOverviewStatsData($elementUuids, $startDate, $endDate);
        $contentTypeStats = $this->getContentTypeStatsData($elementUuids, $startDate, $endDate);
        $topContent = $this->getTopContentData($elementUuids, $startDate, $endDate, 15);

        // Render PDF
        $html = $this->renderPartial('_pdf-report.twig', [
            'company' => $company,
            'logoPath' => $logoPath,
            'period' => $period,
            'periodLabel' => $this->getPeriodLabel($period),
            'startDate' => $startDate,
            'endDate' => $endDate,
            'profileStats' => $profileStats,
            'overviewStats' => $overviewStats,
            'contentTypeStats' => $contentTypeStats,
            'topContent' => $topContent,
            'generatedAt' => date('Y-m-d H:i:s')
        ]);

        $css = $this->getPdfCss();

        // Generate PDF using mPDF (A4 landscape for better table display)
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15
        ]);

        $mpdf->SetTitle('Company Analytics Report - ' . $company['systitle']);
        $mpdf->SetAuthor('Crelish CMS');

        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

        $filename = 'company-analytics-' . preg_replace('/[^a-z0-9]+/i', '-', $company['systitle']) . '-' . date('Y-m-d') . '.pdf';

        return $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
    }

    /**
     * Get all element UUIDs belonging to a company
     *
     * @param string $companyUuid
     * @return array Array of element UUIDs
     */
    private function getCompanyElementUuids(string $companyUuid): array
    {
        $uuids = [];

        foreach (self::CONTENT_TYPES as $type) {
            try {
                if (!CrelishModelResolver::modelExists($type)) {
                    continue;
                }

                $modelClass = CrelishModelResolver::getModelClass($type);
                $tableName = $modelClass::tableName();

                // Check if table has company column
                $schema = Yii::$app->db->getTableSchema($tableName);
                if ($schema === null || !isset($schema->columns['company'])) {
                    continue;
                }

                $typeUuids = (new Query())
                    ->select(['uuid'])
                    ->from($tableName)
                    ->where(['company' => $companyUuid])
                    ->column();

                $uuids = array_merge($uuids, $typeUuids);
            } catch (\Exception $e) {
                Yii::warning("Failed to get elements for type $type: " . $e->getMessage(), __METHOD__);
                continue;
            }
        }

        return array_unique($uuids);
    }

    /**
     * Get overview stats data (used for both JSON endpoint and PDF)
     */
    private function getOverviewStatsData(array $elementUuids, string $startDate, string $endDate): array
    {
        if (empty($elementUuids)) {
            return [
                'total_views' => 0,
                'unique_sessions' => 0,
                'list_views' => 0,
                'detail_views' => 0,
                'clicks' => 0,
                'downloads' => 0,
                'content_count' => 0
            ];
        }

        $stats = (new Query())
            ->select([
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->one();

        $stats['content_count'] = count($elementUuids);

        return $stats;
    }

    /**
     * Get content type stats data
     */
    private function getContentTypeStatsData(array $elementUuids, string $startDate, string $endDate): array
    {
        if (empty($elementUuids)) {
            return [];
        }

        return (new Query())
            ->select([
                'element_type',
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)",
                'unique_elements' => 'COUNT(DISTINCT element_uuid)'
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->groupBy(['element_type'])
            ->orderBy(['total_views' => SORT_DESC])
            ->all();
    }

    /**
     * Get top content data
     */
    private function getTopContentData(array $elementUuids, string $startDate, string $endDate, int $limit = 15): array
    {
        if (empty($elementUuids)) {
            return [];
        }

        $elements = (new Query())
            ->select([
                'element_uuid',
                'element_type',
                'total_views' => 'SUM(total_views)',
                'unique_sessions' => 'SUM(unique_sessions)',
                'list_views' => "SUM(CASE WHEN event_type = 'list' THEN total_views ELSE 0 END)",
                'detail_views' => "SUM(CASE WHEN event_type = 'detail' THEN total_views ELSE 0 END)",
                'clicks' => "SUM(CASE WHEN event_type = 'click' THEN total_views ELSE 0 END)",
                'downloads' => "SUM(CASE WHEN event_type = 'download' THEN total_views ELSE 0 END)"
            ])
            ->from('{{%analytics_element_daily}}')
            ->where(['element_uuid' => $elementUuids])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->groupBy(['element_uuid', 'element_type'])
            ->orderBy(['total_views' => SORT_DESC])
            ->limit($limit)
            ->all();

        // Enrich with titles
        foreach ($elements as &$element) {
            $element['title'] = $this->getElementTitle($element['element_uuid'], $element['element_type'])
                ?? ucfirst($element['element_type']) . ': ' . substr($element['element_uuid'], 0, 8);

            $listViews = (int)$element['list_views'];
            $detailViews = (int)$element['detail_views'];
            $element['conversion_rate'] = $listViews > 0
                ? round(($detailViews / $listViews) * 100, 2)
                : 0;
        }

        return $elements;
    }

    /**
     * Get element title from database
     */
    private function getElementTitle(string $elementUuid, string $elementType): ?string
    {
        try {
            if (!CrelishModelResolver::modelExists($elementType)) {
                return null;
            }

            $modelClass = CrelishModelResolver::getModelClass($elementType);
            $element = $modelClass::find()
                ->where(['uuid' => $elementUuid])
                ->one();

            if ($element) {
                if (isset($element['systitle']) && !empty($element['systitle'])) {
                    return $element['systitle'];
                }
                if (isset($element['title']) && !empty($element['title'])) {
                    return $element['title'];
                }
                if (isset($element['name']) && !empty($element['name'])) {
                    return $element['name'];
                }
            }
        } catch (\Exception $e) {
            Yii::warning('Failed to load element title: ' . $e->getMessage(), __METHOD__);
        }

        return null;
    }

    /**
     * Get period dates
     */
    private function getPeriodDates(string $period): array
    {
        switch ($period) {
            case 'today':
                return [date('Y-m-d'), date('Y-m-d')];
            case 'yesterday':
                return [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))];
            case 'week':
                return [date('Y-m-d', strtotime('-7 days')), date('Y-m-d')];
            case 'month':
                return [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')];
            case 'quarter':
                return [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')];
            case 'year':
                return [date('Y-m-d', strtotime('-365 days')), date('Y-m-d')];
            case 'all':
                return ['2000-01-01', date('Y-m-d')];
            default:
                return [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')];
        }
    }

    /**
     * Get period label for display
     */
    private function getPeriodLabel(string $period): string
    {
        $labels = [
            'today' => Yii::t('crelish', 'Today'),
            'yesterday' => Yii::t('crelish', 'Yesterday'),
            'week' => Yii::t('crelish', 'Last 7 Days'),
            'month' => Yii::t('crelish', 'Last 30 Days'),
            'quarter' => Yii::t('crelish', 'Last 90 Days'),
            'year' => Yii::t('crelish', 'Last Year'),
            'all' => Yii::t('crelish', 'All Time')
        ];

        return $labels[$period] ?? $labels['month'];
    }

    /**
     * Get CSS for dashboard
     */
    private function getDashboardCss(): string
    {
        return <<<CSS
/* Company Analytics specific styles */
.company-analytics-dashboard .insights-card {
    position: relative;
    margin-bottom: 1.5rem;
}

.company-analytics-dashboard .kpi-card {
    text-align: center;
    padding: 1.5rem;
}

.company-analytics-dashboard .kpi-value {
    font-size: 2.5rem;
    font-weight: bold;
    color: var(--bs-primary);
}

.company-analytics-dashboard .kpi-label {
    font-size: 0.9rem;
    color: var(--bs-secondary);
    margin-top: 0.5rem;
}

.company-analytics-dashboard .chart-container {
    position: relative;
    width: 100%;
    min-height: 300px;
}

.company-analytics-dashboard .insights-table {
    font-size: 0.9rem;
}

.company-analytics-dashboard .insights-table th {
    font-weight: 600;
    border-bottom: 2px solid var(--bs-border-color);
}

.company-analytics-dashboard .badge-metric {
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}

.company-selector-card {
    border: 2px solid var(--bs-primary);
    background: var(--bs-light);
}

.company-header {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.company-logo {
    width: 60px;
    height: 60px;
    object-fit: contain;
    border-radius: 4px;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--bs-secondary);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
}

/* Dark mode compatibility */
html[data-bs-theme="dark"] .company-analytics-dashboard .kpi-value {
    color: var(--bs-info);
}

html[data-bs-theme="dark"] .company-selector-card {
    background: var(--bs-dark);
}
CSS;
    }

    /**
     * Get CSS for PDF report
     */
    private function getPdfCss(): string
    {
        return <<<CSS
body {
    font-family: 'DejaVu Sans', sans-serif;
    font-size: 10pt;
    line-height: 1.4;
    color: #333;
}

h1 {
    font-size: 18pt;
    color: #2c3e50;
    margin-bottom: 5mm;
}

h2 {
    font-size: 14pt;
    color: #34495e;
    margin-top: 8mm;
    margin-bottom: 4mm;
    border-bottom: 1px solid #eee;
    padding-bottom: 2mm;
}

h3 {
    font-size: 12pt;
    color: #34495e;
    margin-top: 6mm;
    margin-bottom: 3mm;
}

.header {
    margin-bottom: 10mm;
    padding-bottom: 5mm;
    border-bottom: 2px solid #3498db;
}

.company-info {
    display: flex;
    align-items: center;
}

.company-logo {
    width: 15mm;
    height: 15mm;
    margin-right: 5mm;
}

.kpi-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 8mm;
}

.kpi-grid td {
    width: 25%;
    text-align: center;
    padding: 4mm;
    border: 1px solid #ddd;
    background: #f8f9fa;
}

.kpi-value {
    font-size: 16pt;
    font-weight: bold;
    color: #3498db;
}

.kpi-label {
    font-size: 8pt;
    color: #666;
    margin-top: 1mm;
}

table.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6mm;
    font-size: 9pt;
}

table.data-table th {
    background: #3498db;
    color: white;
    padding: 2mm 3mm;
    text-align: left;
    font-weight: bold;
}

table.data-table td {
    padding: 2mm 3mm;
    border-bottom: 1px solid #eee;
}

table.data-table tr:nth-child(even) {
    background: #f8f9fa;
}

table.data-table .text-right {
    text-align: right;
}

.footer {
    position: absolute;
    bottom: 10mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8pt;
    color: #999;
}

.period-info {
    font-size: 10pt;
    color: #666;
    margin-bottom: 5mm;
}

.badge {
    display: inline-block;
    padding: 1mm 2mm;
    border-radius: 2mm;
    font-size: 8pt;
    font-weight: bold;
}

.badge-primary {
    background: #3498db;
    color: white;
}

.badge-success {
    background: #27ae60;
    color: white;
}

.badge-warning {
    background: #f39c12;
    color: white;
}
CSS;
    }

    /**
     * Resolve an asset UUID to an absolute file system path for mPDF
     *
     * @param string $assetUuid The UUID of the asset
     * @return string|null Absolute file path or null if not found/invalid
     */
    private function resolveAssetPath(string $assetUuid): ?string
    {
        try {
            // Use CrelishModelResolver to get the Asset model class
            if (!CrelishModelResolver::modelExists('asset')) {
                return null;
            }

            $assetClass = CrelishModelResolver::getModelClass('asset');
            $asset = $assetClass::findOne(['uuid' => $assetUuid]);



            if (!$asset || empty($asset->pathName) || empty($asset->fileName)) {
                return null;
            }

            // Build the relative path
            $relativePath = $asset->pathName;
            if (!str_starts_with($relativePath, '/')) {
                $relativePath = '/' . $relativePath;
            }
            if (!str_ends_with($relativePath, '/')) {
                $relativePath .= '/';
            }
            $relativePath .= $asset->fileName;

            // Convert to absolute file path
            $absolutePath = Yii::getAlias('@webroot') . $relativePath;

            // Verify the file exists
            if (!file_exists($absolutePath)) {
                Yii::warning("Asset file not found: $absolutePath", __METHOD__);
                return null;
            }

            return $absolutePath;
        } catch (\Exception $e) {
            Yii::warning('Failed to resolve asset path: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }
}
