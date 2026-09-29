<?php

/**
 * CrelishMessageSource: package translations are the base, project overrides.
 * Also covers the crelish-translations/prune command.
 *
 * Run: php tests/MessageSourceTest.php
 */

declare(strict_types=1);

require_once __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\commands\TranslationsController;
use giantbits\crelish\components\CrelishMessageSource;
use yii\i18n\MissingTranslationEvent;

class CapturingTranslationsController extends TranslationsController
{
    public string $captured = '';

    public function stdout($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }
}

function msTmp(): string
{
    $dir = sys_get_temp_dir() . '/crelish-ms-' . bin2hex(random_bytes(4));
    mkdir($dir . '/pkg/de', 0755, true);
    mkdir($dir . '/proj/de', 0755, true);
    return $dir;
}

function msWrite(string $file, array $messages): void
{
    file_put_contents($file, "<?php\nreturn " . var_export($messages, true) . ";\n");
}

function msRm(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function msApp(string $dir, array &$missing): \yii\web\Application
{
    $missing = [];
    return shortLinkApp([], [], [
        'components' => [
            'i18n' => [
                'translations' => [
                    'crelish*' => [
                        'class' => CrelishMessageSource::class,
                        'basePath' => $dir . '/proj',
                        'packageBasePath' => $dir . '/pkg',
                        'sourceLanguage' => 'en',
                        'fileMap' => ['crelish' => 'crelish.php'],
                        'on missingTranslation' => static function (MissingTranslationEvent $e) use (&$missing): void {
                            $missing[] = $e->message;
                        },
                    ],
                ],
            ],
        ],
    ]);
}

$dir = msTmp();
msWrite($dir . '/pkg/de/crelish.php', ['Items' => 'Elemente', 'Apply' => 'Anwenden', 'Save' => 'Speichern']);
msWrite($dir . '/proj/de/crelish.php', ['Apply' => 'Bewerben', 'Save' => '', 'Custom' => 'Eigen', 'Items' => null]);
$missing = [];
msApp($dir, $missing);

echo "message source\n";
check('package value used when project lacks key', 'Elemente', Yii::t('crelish', 'Items', [], 'de'));
check('project value wins', 'Bewerben', Yii::t('crelish', 'Apply', [], 'de'));
check('empty project value falls back to package', 'Speichern', Yii::t('crelish', 'Save', [], 'de'));
check('project-only key works', 'Eigen', Yii::t('crelish', 'Custom', [], 'de'));
check('no missing event so far', [], $missing);
check('key in neither returns message', 'Nowhere', Yii::t('crelish', 'Nowhere', [], 'de'));
check('missing event fired for key in neither', ['Nowhere'], $missing);
$missing = [];
check('de-CH falls back to de (package)', 'Elemente', Yii::t('crelish', 'Items', [], 'de-CH'));
check('de-CH falls back to de (project)', 'Bewerben', Yii::t('crelish', 'Apply', [], 'de-CH'));
check('de-CH no missing events', [], $missing);

// Package-only language with project de-CH override
mkdir($dir . '/proj/de-CH', 0755, true);
msWrite($dir . '/proj/de-CH/crelish.php', ['Apply' => 'Bewerbe']);
msApp($dir, $missing);
check('specific project file overrides fallback', 'Bewerbe', Yii::t('crelish', 'Apply', [], 'de-CH'));
check('specific project file still gets package base', 'Elemente', Yii::t('crelish', 'Items', [], 'de-CH'));

echo "log noise\n";
mkdir($dir . '/proj/fr', 0755, true);
msWrite($dir . '/proj/de/crelishAnalytics.php', ['Views' => 'Aufrufe']);
msApp($dir, $missing);
Yii::$app->i18n->translations['crelish*'] = ['class' => CrelishMessageSource::class, 'basePath' => $dir . '/proj', 'packageBasePath' => $dir . '/pkg', 'sourceLanguage' => 'en'];
Yii::getLogger()->messages = [];
check('category without package file: project messages only', 'Aufrufe', Yii::t('crelishAnalytics', 'Views', [], 'de'));
check('language package does not ship: source message', 'Items', Yii::t('crelish', 'Items', [], 'fr'));
$noise = array_filter(Yii::getLogger()->messages, static fn($m) => $m[1] <= \yii\log\Logger::LEVEL_WARNING && str_contains((string)$m[0], '/pkg/'));
check('no error/warning log for absent package files (project-side log is Yii own)', [], array_values($noise));

echo "prune\n";
$run = static function (bool $apply) use ($dir): array {
    $c = new CapturingTranslationsController('crelish-translations', Yii::$app);
    $c->apply = $apply;
    $c->packageBasePath = $dir . '/pkg';
    $c->messagesPath = $dir . '/proj';
    $code = $c->actionPrune('de');
    return [$code, $c->captured];
};

$file = $dir . '/proj/de/crelish.php';
$before = file_get_contents($file);
[$code, $out] = $run(false);
check('dry run exit 0', 0, $code);
check('dry run file unchanged', $before, file_get_contents($file));
check('dry run lists Apply', true, str_contains($out, 'Apply: Bewerben → Anwenden'));
check('dry run lists empty project value (Save)', true, str_contains($out, 'Save:  → Speichern'));
check('dry run lists null project value (Items)', true, str_contains($out, 'Items:  → Elemente'));
check('dry run summary count', true, str_contains($out, '3 project key(s)'));
check('dry run skips project-only key', false, str_contains($out, 'Custom'));

[$code, $out] = $run(true);
check('apply exit 0', 0, $code);
check('apply keeps only non-shadowing keys', ['Custom' => 'Eigen'], include $file);
[$code, $out] = $run(true);
check('second apply is a no-op', 0, $code);

[$code, $out] = (function () use ($dir) {
    $c = new CapturingTranslationsController('crelish-translations', Yii::$app);
    $c->packageBasePath = $dir . '/pkg';
    $c->messagesPath = $dir . '/proj';
    $code = $c->actionPrune('fr');
    return [$code, $c->captured];
})();
check('missing project file exit 0', 0, $code);
check('missing project file message', true, str_contains($out, 'No project translation file'));

msRm($dir);
shortLinkDone();
