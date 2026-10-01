<?php

/**
 * crelish-translations/stale-defaults: translation rows stored for the default
 * content language (written by the pre-0.23.4 bug) are listed and, with --apply, deleted.
 *
 * Run with:  php tests/TranslationStaleDefaultsTest.php
 */

declare(strict_types=1);

require __DIR__ . '/menu/bootstrap.php';

use giantbits\crelish\commands\TranslationsController;
use yii\console\ExitCode;

class CapturingStaleTranslationsController extends TranslationsController
{
    public string $captured = '';

    public function stdout($string)
    {
        $this->captured .= $string;
        return strlen($string);
    }
}

function staleRow(string $uuid, string $model, string $language): void
{
    Yii::$app->db->createCommand()->insert('translation', [
        'uuid' => $uuid, 'source_model' => $model, 'source_model_uuid' => 'rec-' . $uuid,
        'language' => $language, 'source_model_attribute' => 'label', 'translation' => 'x',
    ])->execute();
}

function staleRun(bool $apply): array
{
    $c = new CapturingStaleTranslationsController('crelish-translations', Yii::$app);
    $c->apply = $apply;
    $code = $c->actionStaleDefaults();

    return [$code, $c->captured];
}

function staleLanguages(): array
{
    $languages = Yii::$app->db->createCommand('SELECT language FROM translation ORDER BY uuid')->queryColumn();

    return $languages;
}

menuApp(['languages' => ['de-CH', 'en']]);
staleRow('a1', 'menu_item', 'de');
staleRow('a2', 'menu_item', 'de-CH');
staleRow('a3', 'menu_item', 'en');
staleRow('a4', 'page', 'de');
staleRow('a5', 'page', 'de_AT');
staleRow('a6', 'page', 'en-US');
staleRow('a7', 'page', 'fr');

echo "dry run\n";
[$code, $out] = staleRun(false);
check('exit 0', ExitCode::OK, $code);
check('menu_item count listed', 1, preg_match('/^menu_item: 2$/m', $out));
check('page count listed', 1, preg_match('/^page: 2$/m', $out));
check('total listed', 1, preg_match("/4 translation row\\(s\\) for the default content language 'de-CH'/", $out));
check('dry run notice', 1, preg_match('/Dry run, nothing changed\. Re-run with --apply/', $out));
check('nothing deleted', 7, count(staleLanguages()));

echo "\n--apply\n";
[$code, $out] = staleRun(true);
check('exit 0', ExitCode::OK, $code);
check('deleted message', 1, preg_match('/Deleted 4 row\(s\)\./', $out));
check('only other languages remain', ['en', 'en-US', 'fr'], staleLanguages());

echo "\nnothing left\n";
[$code, $out] = staleRun(false);
check('exit 0', ExitCode::OK, $code);
check('zero reported', 1, preg_match("/0 translation row\\(s\\) for the default content language 'de-CH'/", $out));
check('no dry run notice when nothing to do', 0, preg_match('/Dry run/', $out));

echo "\nno default content language configured\n";
Yii::$app->params['crelish']['languages'] = [];
staleRow('b1', 'menu_item', 'de');
[$code, $out] = staleRun(true);
check('exit 0', ExitCode::OK, $code);
check('message', 1, preg_match('/No default content language configured/', $out));
check('nothing deleted', 4, count(staleLanguages()));

shortLinkDone();
