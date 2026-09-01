<?php

namespace giantbits\crelish\widgets;

use giantbits\crelish\helpers\CrelishAnalyticsPeriod;
use kartik\daterange\DateRangePicker;
use Yii;
use yii\base\Widget;
use yii\helpers\Json;
use yii\web\JsExpression;

/**
 * Reporting period picker shared by the analytics dashboards.
 *
 * Renders a Krajee DateRangePicker preloaded with the named presets from
 * {@see CrelishAnalyticsPeriod} plus a free custom range. Selecting a preset
 * keeps the historic `period=<key>` query parameter; a custom range submits
 * `period=custom&start_date=Y-m-d&end_date=Y-m-d`.
 *
 * The picker exposes its state on `window.crelishPeriodPicker` and raises a
 * `crelish:periodchange` event on the input, so dashboards can reload their
 * data without knowing anything about the widget internals.
 */
class CrelishAnalyticsPeriodPicker extends Widget
{
    /**
     * @var string Currently selected period key.
     */
    public $period = CrelishAnalyticsPeriod::FALLBACK;

    /**
     * @var string|null Resolved range start (Y-m-d).
     */
    public $startDate;

    /**
     * @var string|null Resolved range end (Y-m-d).
     */
    public $endDate;

    /**
     * @var string DOM id of the rendered input. Kept as the historic id so
     *      existing dashboard scripts keep resolving the element.
     */
    public $inputId = 'period-filter';

    /**
     * @var array Extra HTML options merged into the input.
     */
    public $options = [];

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        [$startDate, $endDate, $period] = CrelishAnalyticsPeriod::resolve(
            $this->period,
            $this->startDate,
            $this->endDate
        );

        $options = array_merge([
            'id' => $this->inputId,
            'class' => 'form-control',
            'autocomplete' => 'off',
        ], $this->options);

        $html = DateRangePicker::widget([
            'name' => 'period_range',
            'value' => CrelishAnalyticsPeriod::formatDisplay($startDate)
                . ' - ' . CrelishAnalyticsPeriod::formatDisplay($endDate),
            'options' => $options,
            'pluginOptions' => [
                'locale' => $this->localeOptions(),
                'ranges' => $this->pluginRanges(),
                'startDate' => CrelishAnalyticsPeriod::formatDisplay($startDate),
                'endDate' => CrelishAnalyticsPeriod::formatDisplay($endDate),
                'minDate' => CrelishAnalyticsPeriod::formatDisplay(CrelishAnalyticsPeriod::FLOOR),
                'maxDate' => CrelishAnalyticsPeriod::formatDisplay(date('Y-m-d')),
                'opens' => 'left',
                'alwaysShowCalendars' => true,
            ],
        ]);

        $this->registerScript($period, $startDate, $endDate);

        return $html;
    }

    /**
     * Preset ranges for the picker, keyed by their translated label.
     *
     * The picker matches the active range against these to decide which preset
     * to highlight, so the dates must mirror {@see CrelishAnalyticsPeriod}.
     *
     * Values are emitted as raw JavaScript by the widget (see its
     * `initRangeExpr`), so each bound must be a moment expression rather than a
     * plain date string.
     *
     * @return array<string, array{0: JsExpression, 1: JsExpression}>
     */
    private function pluginRanges(): array
    {
        $ranges = [];

        foreach (array_keys(CrelishAnalyticsPeriod::presets()) as $key) {
            [$start, $end] = CrelishAnalyticsPeriod::resolve($key);
            $label = CrelishAnalyticsPeriod::label($key);

            $ranges[$label] = [
                self::momentExpression($start),
                self::momentExpression($end),
            ];
        }

        return $ranges;
    }

    /**
     * Wrap a Y-m-d date in a moment() expression for the picker.
     *
     * @param string $date Y-m-d
     * @return JsExpression
     */
    private static function momentExpression(string $date): JsExpression
    {
        return new JsExpression('moment(' . Json::encode($date) . ", 'YYYY-MM-DD')");
    }

    /**
     * Picker locale options, using the German display format.
     *
     * @return array
     */
    private function localeOptions(): array
    {
        return [
            'format' => 'DD.MM.YYYY',
            'separator' => ' - ',
            'applyLabel' => Yii::t('crelish', 'Apply'),
            'cancelLabel' => Yii::t('crelish', 'Cancel'),
            'customRangeLabel' => Yii::t('crelish', 'Custom Range'),
            'firstDay' => 1,
        ];
    }

    /**
     * Register the glue that maps picker selections back onto period keys.
     *
     * @param string $period
     * @param string $startDate
     * @param string $endDate
     * @return void
     */
    private function registerScript(string $period, string $startDate, string $endDate): void
    {
        $labelToKey = [];

        foreach (array_keys(CrelishAnalyticsPeriod::presets()) as $key) {
            $labelToKey[CrelishAnalyticsPeriod::label($key)] = $key;
        }

        $config = Json::encode([
            'inputId' => $this->inputId,
            'period' => $period,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'labelToKey' => $labelToKey,
            'customKey' => CrelishAnalyticsPeriod::CUSTOM,
            'customLabel' => Yii::t('crelish', 'Custom Range'),
        ]);

        $js = <<<JS
(function () {
    var config = {$config};
    var input = document.getElementById(config.inputId);

    if (!input) {
        return;
    }

    var state = {
        period: config.period,
        startDate: config.startDate,
        endDate: config.endDate
    };

    window.crelishPeriodPicker = {
        /**
         * Current selection, ready to be merged into a request query string.
         */
        params: function () {
            var params = { period: state.period };

            if (state.period === config.customKey) {
                params.start_date = state.startDate;
                params.end_date = state.endDate;
            }

            return params;
        },
        /**
         * Current selection as a query string fragment (no leading separator).
         */
        query: function () {
            var params = this.params();
            var pairs = [];

            for (var key in params) {
                if (Object.prototype.hasOwnProperty.call(params, key)) {
                    pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
                }
            }

            return pairs.join('&');
        },
        period: function () {
            return state.period;
        },
        startDate: function () {
            return state.startDate;
        },
        endDate: function () {
            return state.endDate;
        }
    };

    jQuery(input).on('apply.daterangepicker', function (event, picker) {
        var label = picker.chosenLabel;

        state.startDate = picker.startDate.format('YYYY-MM-DD');
        state.endDate = picker.endDate.format('YYYY-MM-DD');
        state.period = Object.prototype.hasOwnProperty.call(config.labelToKey, label)
            ? config.labelToKey[label]
            : config.customKey;

        input.dispatchEvent(new CustomEvent('crelish:periodchange', {
            bubbles: true,
            detail: window.crelishPeriodPicker.params()
        }));
    });
})();
JS;

        $this->view->registerJs($js);
    }
}
