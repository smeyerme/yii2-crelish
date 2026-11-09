<?php

/**
 * The Sentry error handler renders its error view on both paths: Yii's own rendering
 * (debug on, which hands the view only the exception) and the user-friendly one.
 * The default view used to be named without its extension, so it was never found:
 * with debug on a 404 ended as "An Error occurred while handling another error" (500),
 * with debug off the view was skipped for the bare fallback page.
 *
 * Run with:  php tests/SentryErrorViewTest.php
 */

declare(strict_types=1);

require __DIR__ . '/shortlink/bootstrap.php';

use giantbits\crelish\components\CrelishSentryErrorHandler;
use yii\web\NotFoundHttpException;

/**
 * Keeps what would be sent instead of sending it (the test has already written output)
 */
class SentryErrorViewTestResponse extends \yii\web\Response
{
    public int $sentStatus = 0;
    public ?string $sentContent = null;

    public function send()
    {
        $this->sentStatus = $this->getStatusCode();
        $this->sentContent = (string)$this->data;
        $this->isSent = true;
    }
}

shortLinkApp([], [], [
    'components' => [
        'response' => ['class' => SentryErrorViewTestResponse::class],
        'view' => [
            'renderers' => [
                'twig' => ['class' => 'yii\twig\ViewRenderer', 'cachePath' => false],
            ],
        ],
    ],
]);

$handler = new CrelishSentryErrorHandler();

echo "Default error view\n";
check('the default error view is a file that exists', true, is_file(Yii::getAlias($handler->errorView)));

echo "\nYii's rendering (debug on): the view gets only the exception\n";
$html = Yii::$app->view->renderFile($handler->errorView, ['exception' => new NotFoundHttpException('Not here.')]);
check('shows the status code of the exception', true, str_contains($html, '<p class="code">404</p>'));
check('names it', true, str_contains($html, 'Page Not Found'));
check('shows the message of the exception', true, str_contains($html, 'Not here.'));

echo "\nUser-friendly rendering (debug off)\n";
$render = new ReflectionMethod($handler, 'renderErrorView');
$html = $render->invoke($handler, [
    'exception' => new NotFoundHttpException(),
    'statusCode' => 404,
    'userMessage' => 'The page you are looking for could not be found.',
    'errorId' => 'ERR-TEST1234',
    'showDetails' => false,
]);
check('renders the error view, not the bare fallback', true, str_contains($html, 'Page Not Found'));
check('shows the error id', true, str_contains($html, 'ERR-TEST1234'));

echo "\nThe user-friendly page goes out with the status of the exception\n";
$friendly = new ReflectionMethod($handler, 'renderUserFriendlyException');
ob_start();
$friendly->invoke($handler, new NotFoundHttpException());
$echoed = ob_get_clean();
check('sent as 404, not the 500 Yii set when handling began', 404, Yii::$app->response->sentStatus);
check('sent as the response content', true, str_contains((string)Yii::$app->response->sentContent, 'Page Not Found'));
check('not echoed around the response', '', $echoed);

echo "\nOne error id per request, on the page and for Sentry\n";
$fresh = new CrelishSentryErrorHandler();
$errorId = new ReflectionMethod($fresh, 'errorId');
$first = $errorId->invoke($fresh);
check('the id has the ERR- form', 1, preg_match('/^ERR-[0-9A-F]{8}$/', $first));
check('asked again, the same id', $first, $errorId->invoke($fresh));
ob_start();
$friendly->invoke($fresh, new NotFoundHttpException());
ob_get_clean();
check('the page shows the id that went to Sentry', true, str_contains((string)Yii::$app->response->sentContent, $first));

echo "\nThe Crelish logo on admin pages only\n";
$logo = new ReflectionMethod($fresh, 'adminLogo');
Yii::$app->requestedRoute = 'crelish/content/index';
check('admin page: inline SVG', true, str_starts_with($logo->invoke($fresh), '<svg'));
Yii::$app->requestedRoute = 'crelish/frontend/run';
check('site page served by crelish: none', '', $logo->invoke($fresh));
Yii::$app->requestedRoute = 'site/index';
check('other site page: none', '', $logo->invoke($fresh));
$html = $render->invoke($fresh, ['statusCode' => 500, 'userMessage' => 'x', 'errorId' => 'ERR-TEST1234', 'logo' => '<svg id="t"></svg>']);
check('the view shows a given logo', true, str_contains($html, '<div class="logo"><svg id="t"></svg></div>'));
check('and none without one', false, str_contains($render->invoke($fresh, ['statusCode' => 500]), 'class="logo"'));

shortLinkDone();
