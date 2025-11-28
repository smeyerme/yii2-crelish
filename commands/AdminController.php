<?php

namespace giantbits\crelish\commands;

use giantbits\crelish\components\CrelishBaseHelper;
use yii\console\Controller;
use yii\console\ExitCode;

class AdminController extends Controller
{
    /**
     * @var string The User model class to use
     */
    public $userClass = 'app\workspace\models\User';

    /**
     * Creates an admin user for the Crelish CMS system
     *
     * This action prompts for user information and creates an admin user with:
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

        $this->stdout("\n=== Create Admin User ===\n\n");

        // Prompt for user information
        $email = $this->prompt('Email:', ['required' => true]);

        // Check if user with this email already exists
        $existingUser = $userClass::findOne(['email' => $email]);
        if ($existingUser) {
            $this->stdout("\nError: User with email '$email' already exists.\n");
            return ExitCode::DATAERR;
        }

        $password = $this->prompt('Password:', ['required' => true]);
        $nameFirst = $this->prompt('First Name:', ['required' => true]);
        $nameLast = $this->prompt('Last Name:', ['required' => true]);
        $salutation = $this->prompt('Salutation:', ['required' => true]);

        // Create new admin user
        $user = new $userClass();
        $user->email = $email;
        $user->password = \Yii::$app->getSecurity()->generatePasswordHash($password);
        $user->role = 9;
        $user->state = 2;
        $user->authKey = \Yii::$app->security->generateRandomString();
        $user->uuid = CrelishBaseHelper::GUIDv4();

        // Set the provided user information
        if ($user->hasAttribute('nameFirst')) {
            $user->nameFirst = $nameFirst;
        }
        if ($user->hasAttribute('nameLast')) {
            $user->nameLast = $nameLast;
        }
        if ($user->hasAttribute('salutation')) {
            $user->salutation = $salutation;
        }

        // Save without validation to bypass required field checks
        if ($user->save(false)) {
            $this->stdout("\n=== Success! ===\n");
            $this->stdout("Admin user created successfully:\n");
            $this->stdout("  Email: $email\n");
            $this->stdout("  Password: $password\n");
            $this->stdout("  First Name: $nameFirst\n");
            $this->stdout("  Last Name: $nameLast\n");
            $this->stdout("  Salutation: $salutation\n");
            $this->stdout("  Role: 9 (admin)\n");
            $this->stdout("  State: 2 (active)\n");
            $this->stdout("  UUID: {$user->uuid}\n\n");
            return ExitCode::OK;
        } else {
            $this->stdout("\nError: Failed to create admin user.\n");
            if ($user->hasErrors()) {
                foreach ($user->getErrors() as $attribute => $errors) {
                    $this->stdout("  $attribute: " . implode(', ', $errors) . "\n");
                }
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }
    }
}