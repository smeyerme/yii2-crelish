<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\CrelishBaseHelper;
use yii\console\Controller;
use yii\console\ExitCode;

class CrelishController extends Controller
{
    /**
     * @var string The User model class to use
     */
    public $userClass = 'app\workspace\models\User';

    /**
     * Creates a default admin user for the Crelish CMS system
     *
     * This action creates a user with:
     * - Email: admin@local.host
     * - Password: basta!
     * - Role: 9 (admin)
     * - State: 2 (active)
     *
     * @return int Exit code
     */
    public function actionCreateDefaultAdmin()
    {
        if (!class_exists($this->userClass)) {
            $this->stdout("Error: User class '{$this->userClass}' not found.\n");
            return ExitCode::CONFIG;
        }

        $userClass = $this->userClass;

        // Check if admin user already exists
        $existingUser = $userClass::findOne(['email' => 'admin@local.host']);
        if ($existingUser) {
            $this->stdout("Admin user with email 'admin@local.host' already exists.\n");
            return ExitCode::OK;
        }

        // Create new admin user
        $user = new $userClass();
        $user->email = 'admin@local.host';
        $user->password = \Yii::$app->getSecurity()->generatePasswordHash('basta!');
        $user->role = 9;
        $user->state = 2;
        $user->authKey = \Yii::$app->security->generateRandomString();
        $user->uuid = CrelishBaseHelper::GUIDv4();

        // Set default required fields if they exist
        if ($user->hasAttribute('nameFirst')) {
            $user->nameFirst = 'Admin';
        }
        if ($user->hasAttribute('nameLast')) {
            $user->nameLast = 'User';
        }
        if ($user->hasAttribute('salutation')) {
            $user->salutation = 'Herr';
        }

        // Save without validation to bypass required field checks
        if ($user->save(false)) {
            $this->stdout("Successfully created admin user:\n");
            $this->stdout("  Email: admin@local.host\n");
            $this->stdout("  Password: basta!\n");
            $this->stdout("  Role: 9 (admin)\n");
            $this->stdout("  State: 2 (active)\n");
            $this->stdout("  UUID: {$user->uuid}\n");
            return ExitCode::OK;
        } else {
            $this->stdout("Error: Failed to create admin user.\n");
            if ($user->hasErrors()) {
                foreach ($user->getErrors() as $attribute => $errors) {
                    $this->stdout("  $attribute: " . implode(', ', $errors) . "\n");
                }
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}