<?php

use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Website Analytics';

// Pre-define URLs for JavaScript
$pageViewStatsUrl = Url::to(['page-view-stats']);
$topPagesUrl = Url::to(['top-pages']);
$topElementsUrl = Url::to(['top-elements']);
$exportPageViewsUrl = Url::to(['export', 'type' => 'page_views']);
$exportElementsUrl = Url::to(['export', 'type' => 'elements']);
$exportSessionsUrl = Url::to(['export', 'type' => 'sessions']);
?>

    <div class="analytics-dashboard">
        <div class="row">
            <div class="col-md-12">
                <div class="card" style="margin-bottom: 0;">
                    <div class="card-header">
                        <div class="analytics-filters">
                            <div class="form-group">
                                <label><?= Yii::t('crelish', 'Time Period:') ?></label>
                              <?= Html::dropDownList('period', $period, [
                                'day' => Yii::t('crelish', 'Last 24 Hours'),
                                'week' => Yii::t('crelish', 'Last 7 Days'),
                                'month' => Yii::t('crelish', 'Last 30 Days'),
                                'year' => Yii::t('crelish', 'Last Year'),
                                'all' => Yii::t('crelish', 'All Time')
                              ], ['class' => 'form-control', 'id' => 'period-filter']) ?>
                            </div>
                            <div class="form-group">
                                <label>
                                  <?= Html::checkbox('exclude_bots', $excludeBots, ['id' => 'exclude-bots']) ?>
                                  <?= Yii::t('crelish', 'Exclude Bot Traffic') ?>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3><?= Yii::t('crelish', 'Page Views Over Time') ?></h3>
                    </div>
                    <div class="card-body chart-container" style="position: relative; height: 400px;">
                        <canvas id="page-views-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h3><?= Yii::t('crelish', 'Traffic Summary') ?></h3>
                    </div>
                    <div class="card-body">
                        <div id="traffic-summary">
                            <div class="spinner-border" role="status">
                                <span class="sr-only"><?= Yii::t('crelish', 'Loading...') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h3><?= Yii::t('crelish', 'Top Pages') ?></h3>
                    </div>
                    <div class="card-body">
                        <div id="top-pages">
                            <div class="spinner-border" role="status">
                                <span class="sr-only"><?= Yii::t('crelish', 'Loading...') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h3><?= Yii::t('crelish', 'Top Content Elements') ?></h3>
                        <div class="element-type-filter">
                            <select id="element-type-filter" class="form-control form-control-sm">
                                <option value=""><?= Yii::t('crelish', 'All Types') ?></option>
                                <option value="download"><?= Yii::t('crelish', 'Downloads') ?></option>
                                <option value="list"><?= Yii::t('crelish', 'List Views') ?></option>
                                <option value="detail"><?= Yii::t('crelish', 'Detail Views') ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div id="top-elements">
                            <div class="spinner-border" role="status">
                                <span class="sr-only"><?= Yii::t('crelish', 'Loading...') ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3><?= Yii::t('crelish', 'Export Data') ?></h3>
                    </div>
                    <div class="card-body">
                        <div class="btn-group" role="group">
                          <?= Html::a(Yii::t('crelish', 'Export Page Views'), '#', ['class' => 'btn btn-primary', 'id' => 'export-page-views']) ?>
                          <?= Html::a(Yii::t('crelish', 'Export Content Elements'), '#', ['class' => 'btn btn-primary', 'id' => 'export-elements']) ?>
                          <?= Html::a(Yii::t('crelish', 'Export Sessions'), '#', ['class' => 'btn btn-primary', 'id' => 'export-sessions']) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
// Register necessary scripts
$this->registerJsFile('https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js', ['position' => \yii\web\View::POS_HEAD]);

// Prepare translated strings
$totalViewsText = Yii::t('crelish', 'Total Views');
$avgViewsPerDayText = Yii::t('crelish', 'Average Views Per Day');
$pageText = Yii::t('crelish', 'Page');
$elementText = Yii::t('crelish', 'Element');
$typeText = Yii::t('crelish', 'Type');
$viewsText = Yii::t('crelish', 'Views');
$noDataText = Yii::t('crelish', 'No data available for the selected period');
$pageViewsText = Yii::t('crelish', 'Page Views');

// JavaScript with proper URL handling
$js = <<<JS
// URLs from PHP variables
var pageViewStatsUrl = '$pageViewStatsUrl';
var topPagesUrl = '$topPagesUrl';
var topElementsUrl = '$topElementsUrl';
var exportPageViewsBaseUrl = '$exportPageViewsUrl';
var exportElementsBaseUrl = '$exportElementsUrl';
var exportSessionsBaseUrl = '$exportSessionsUrl';

// Chart objects
var pageViewsChart = null;
var dataLoadInterval = null;

// Load data on page load
$(document).ready(function() {
    // Initial data load
    loadData();
    
    // Handle period change
    $('#period-filter').change(function() {
        loadData();
        updateExportLinks();
    });
    
    // Handle bot exclusion change
    $('#exclude-bots').change(function() {
        loadData();
        updateExportLinks();
    });
    
    // Handle element type filter change
    $('#element-type-filter').change(function() {
        loadTopElements();
    });
    
    // Init export links
    updateExportLinks();
    
    // Check for theme changes by watching data-theme attribute
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && 
                mutation.attributeName === 'data-theme') {
                // Theme has changed, reload the chart
                loadPageViewStats();
            }
        });
    });
    
    // Watch for attribute changes on html element only
    observer.observe(document.documentElement, { attributes: true });
    
    // Stop auto-refresh when navigating away from the page
    $(window).on('beforeunload', function() {
        if (dataLoadInterval) {
            clearInterval(dataLoadInterval);
        }
        observer.disconnect();
    });
});

// Load all data
function loadData() {
    // Clear any existing interval to prevent multiple simultaneous requests
    if (dataLoadInterval) {
        clearInterval(dataLoadInterval);
        dataLoadInterval = null;
    }
    
    // Load data once immediately
    loadPageViewStats();
    loadTrafficSummary();
    loadTopPages();
    loadTopElements();
}

// Update export links with current filters
function updateExportLinks() {
    var period = $('#period-filter').val();
    var excludeBots = $('#exclude-bots').is(':checked') ? 1 : 0;
    
    $('#export-page-views').attr('href', 
        exportPageViewsBaseUrl + '&period=' + period + '&exclude_bots=' + excludeBots);
    $('#export-elements').attr('href', 
        exportElementsBaseUrl + '&period=' + period);
    $('#export-sessions').attr('href', 
        exportSessionsBaseUrl + '&period=' + period);
}

// Load page view statistics
function loadPageViewStats() {
    var period = $('#period-filter').val();
    var excludeBots = $('#exclude-bots').is(':checked') ? 1 : 0;
    
    $.ajax({
        url: pageViewStatsUrl,
        data: { period: period, exclude_bots: excludeBots },
        dataType: 'json',
        success: function(data) {
            renderPageViewsChart(data);
        }
    });
}

// Load traffic summary
function loadTrafficSummary() {
    var period = $('#period-filter').val();
    var excludeBots = $('#exclude-bots').is(':checked') ? 1 : 0;
    
    $.ajax({
        url: pageViewStatsUrl,
        data: { period: period, exclude_bots: excludeBots },
        dataType: 'json',
        success: function(data) {
            // Calculate total views
            var totalViews = 0;
            data.forEach(function(item) {
                totalViews += parseInt(item.views);
            });
            
            // Calculate average views per day
            var avgViews = Math.round(totalViews / data.length) || 0;
            
            var html = '<div class="summary-stat">';
            html += '<h4>' + "$totalViewsText" + '</h4>';
            html += '<div class="stat-value">' + totalViews.toLocaleString() + '</div>';
            html += '</div>';
            
            html += '<div class="summary-stat">';
            html += '<h4>' + "$avgViewsPerDayText" + '</h4>';
            html += '<div class="stat-value">' + avgViews.toLocaleString() + '</div>';
            html += '</div>';
            
            $('#traffic-summary').html(html);
        }
    });
}

// Load top pages
function loadTopPages() {
    var period = $('#period-filter').val();
    var excludeBots = $('#exclude-bots').is(':checked') ? 1 : 0;
    
    $.ajax({
        url: topPagesUrl,
        data: { period: period, exclude_bots: excludeBots, limit: 10 },
        dataType: 'json',
        success: function(data) {
            var html = '<table class="table table-striped">';
            html += '<thead><tr><th>' + "$pageText" + '</th><th>' + "$typeText" + '</th><th>' + "$viewsText" + '</th></tr></thead>';
            html += '<tbody>';
            
            if (data.length === 0) {
                html += '<tr><td colspan="3">' + "$noDataText" + '</td></tr>';
            } else {
                data.forEach(function(page) {
                    html += '<tr>';
                    html += '<td>' + (page.title || page.url) + '</td>';
                    html += '<td>' + page.page_type + '</td>';
                    html += '<td>' + page.views + '</td>';
                    html += '</tr>';
                });
            }
            
            html += '</tbody></table>';
            $('#top-pages').html(html);
        }
    });
}

// Load top content elements
function loadTopElements() {
    var period = $('#period-filter').val();
    var elementType = $('#element-type-filter').val();
    
    // Prepare data object for the AJAX request
    var requestData = { 
        period: period, 
        limit: 10
    };
    
    // Only add the type parameter if a specific type is selected
    if (elementType !== '') {
        requestData.type = elementType;
    }
    
    $.ajax({
        url: topElementsUrl,
        data: requestData,
        dataType: 'json',
        success: function(data) {
            var html = '<table class="table table-striped">';
            html += '<thead><tr>';
            html += '<th>' + "$elementText" + '</th>';
            html += '<th>' + "$typeText" + '</th>';
            
            // Add file type column for downloads
            if (elementType === 'download') {
                html += '<th>File Type</th>';
            }
            
            // Add view type column if showing all types
            if (elementType === '') {
                html += '<th>View Type</th>';
            }
            
            html += '<th>' + "$viewsText" + '</th>';
            html += '</tr></thead>';
            html += '<tbody>';
            
            if (data.length === 0) {
                var colSpan = elementType === 'download' ? 4 : (elementType !== '' ? 3 : 4);
                html += '<tr><td colspan="' + colSpan + '">' + "$noDataText" + '</td></tr>';
            } else {
                data.forEach(function(element) {
                    html += '<tr>';
                    html += '<td>' + (element.title || element.element_uuid) + '</td>';
                    html += '<td>' + element.element_type + '</td>';
                    
                    // Add file type column for downloads
                    if (elementType === 'download') {
                        html += '<td>' + (element.file_type || 'Unknown') + '</td>';
                    }
                    
                    // Add view type column if showing all types
                    if (elementType === '') {
                        html += '<td>' + (element.view_type || 'view') + '</td>';
                    }
                    
                    html += '<td>' + element.views + '</td>';
                    html += '</tr>';
                });
            }
            
            html += '</tbody></table>';
            $('#top-elements').html(html);
        }
    });
}

// Render page views chart
function renderPageViewsChart(data) {
    var canvas = document.getElementById('page-views-chart');
    var container = canvas.parentElement;
    
    // Check if element exists
    if (!canvas) {
        console.error('Chart canvas element not found');
        return;
    }

    // Completely destroy the previous chart to prevent memory leaks
    if (pageViewsChart) {
        pageViewsChart.destroy();
    }
    
    // Create a fresh canvas to prevent any potential issues
    $(canvas).remove();
    $(container).append('<canvas id="page-views-chart"></canvas>');
    canvas = document.getElementById('page-views-chart');
    
    var ctx = canvas.getContext('2d');
    
    // Prepare data for Chart.js
    var labels = [];
    var viewCounts = [];
    
    if (data && data.length > 0) {
        data.forEach(function(item) {
            labels.push(item.date);
            viewCounts.push(parseInt(item.views));
        });
    }
    
    // Detect dark mode based on data-theme attribute
    var isDarkMode = document.documentElement.getAttribute('data-theme') === 'dark';
    
    // Set colors based on theme
    var fontColor = isDarkMode ? '#fff' : '#666';
    var gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    var backgroundColor = isDarkMode ? 'rgba(54, 162, 235, 0.2)' : 'rgba(54, 162, 235, 0.2)';
    var borderColor = isDarkMode ? '#63a7ff' : '#007bff';
    
    // Create new chart with fixed size and theme-aware options
    pageViewsChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: "$pageViewsText",
                data: viewCounts,
                backgroundColor: backgroundColor,
                borderColor: borderColor,
                borderWidth: 1,
                tension: 0.1
            }]
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: fontColor
                    }
                },
                x: {
                    grid: {
                        color: gridColor
                    },
                    ticks: {
                        color: fontColor
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: fontColor
                    }
                },
                tooltip: {
                    backgroundColor: isDarkMode ? '#151e2d' : '#fff',
                    titleColor: isDarkMode ? '#fff' : '#000',
                    bodyColor: isDarkMode ? '#fff' : '#000',
                    borderColor: isDarkMode ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.1)',
                    borderWidth: 1,
                    padding: 10
                }
            }
        }
    });
}
JS;

$this->registerJs($js);

// Directly set styles based on theme attribute
$style = <<<CSS
/* Light theme (default) */
:root {
    --dashboard-text: #212529;
    --dashboard-bg: #fff;
    --dashboard-card-bg: #fff;
    --dashboard-card-border: rgba(0,0,0,.125);
    --dashboard-card-header-bg: rgba(0,0,0,.03);
    --dashboard-table-stripe: rgba(0,0,0,.05);
    --dashboard-link-color: #007bff;
}

/* Dark theme */
html[data-theme="dark"] {
    --dashboard-text: #f8f9fa;
    --dashboard-bg: #1e1e1e;
    --dashboard-card-bg: #151e2d;
    --dashboard-card-border: rgba(255,255,255,.125);
    --dashboard-card-header-bg: #0f1623;
    --dashboard-table-stripe: rgba(255,255,255,.05);
    --dashboard-link-color: #63a7ff;
}

.analytics-filters {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}

.btn-primary {
    background-color: var(--dashboard-link-color);
    border-color: var(--dashboard-link-color);
    color: #fff;
}

.btn-primary:hover {
    background-color: var(--dashboard-link-color);
    filter: brightness(90%);
}

.analytics-filters .form-group {
    min-width: 200px;
}

/* Form controls should respect dark theme */
.form-control {
    background-color: var(--dashboard-bg);
    color: var(--dashboard-text);
    border-color: var(--dashboard-card-border);
}

.form-control:focus {
    background-color: var(--dashboard-bg);
    color: var(--dashboard-text);
}

/* Custom checkbox styling */
.form-check-input {
    background-color: var(--dashboard-bg);
    border-color: var(--dashboard-card-border);
}

.form-check-input:checked {
    background-color: var(--dashboard-link-color);
    border-color: var(--dashboard-link-color);
}

.summary-stat {
    margin-bottom: 20px;
    text-align: center;
}

.summary-stat h4 {
    color: var(--dashboard-text);
    margin-bottom: 0.5rem;
}

.stat-value {
    font-size: 2.5rem;
    font-weight: bold;
    color: var(--dashboard-link-color);
}

.card {
    margin-bottom: 20px;
    background-color: var(--dashboard-card-bg);
    color: var(--dashboard-text);
    border: 1px solid var(--dashboard-card-border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.card-header {
    background-color: var(--dashboard-card-header-bg);
    border-bottom: 1px solid var(--dashboard-card-border);
    padding: 0.75rem 1.25rem;
}

.card-header h3 {
    color: var(--bs-heading-color);
    margin-bottom: 0;
    font-size: 1.25rem;
}

.card-body {
    padding: 1.25rem;
}

.chart-container {
    width: 100%;
    height: 400px;
}

/* Ensure table text is readable in dark mode */
.table {
    color: var(--dashboard-text);
    background-color: transparent;
    width: 100%;
    margin-bottom: 1rem;
    border-collapse: collapse;
}

.table th,
.table td {
    padding: 0.75rem;
    vertical-align: top;
    border-top: 1px solid var(--dashboard-card-border);
}

.table thead tr th  {
    vertical-align: bottom;
    border-bottom: 2px solid var(--dashboard-card-border);
    color: var(--dashboard-text) !important;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: var(--dashboard-table-stripe);
}

/* Ensure chart has proper background */
canvas {
    background-color: var(--dashboard-card-bg);
}
CSS;

$this->registerCss($style);
?>