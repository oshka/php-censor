<?php

declare(strict_types = 1);

namespace PHPCensor\Controller;

use PHPCensor\Exception\HttpException;
use PHPCensor\Form;
use PHPCensor\Form\Element\Checkbox;
use PHPCensor\Form\Element\Csrf;
use PHPCensor\Form\Element\Password;
use PHPCensor\Form\Element\Submit;
use PHPCensor\Form\Element\Text;
use PHPCensor\Helper\Email;
use PHPCensor\Helper\Lang;
use PHPCensor\Http\Response;
use PHPCensor\Http\Response\RedirectResponse;
use PHPCensor\Security\Authentication\Service;
use PHPCensor\Store\UserStore;
use PHPCensor\WebController;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class SessionController extends WebController
{
    public string $layoutName = 'layoutSession';

    protected UserStore $userStore;

    protected Service $authentication;

    /**
     * Initialise the controller, set up stores and services.
     */
    public function init(): void
    {
        parent::init();

        $this->userStore      = $this->storeRegistry->get('User');
        $this->authentication = new Service($this->configuration, $this->storeRegistry);
    }

    protected function loginForm(array $values): Form
    {
        $form = new Form();
        $form->setMethod('POST');
        $form->setAction(APP_URL . 'session/login');

        $form->addField(new Csrf('login_form'));

        $email = new Text('email');
        $email->setLabel(Lang::get('login'));
        $email->setRequired(true);
        $email->setContainerClass('form-group');
        $email->setClass('form-control');
        $form->addField($email);

        $pwd = new Password('password');
        $pwd->setLabel(Lang::get('password'));
        $pwd->setRequired(true);
        $pwd->setContainerClass('form-group');
        $pwd->setClass('form-control');
        $form->addField($pwd);

        $remember = Checkbox::create('remember_me', Lang::get('remember_me'), false);
        $remember->setContainerClass('form-group');
        $remember->setCheckedValue(1);
        $remember->setValue(0);
        $form->addField($remember);

        $pwd = new Submit();
        $pwd->setValue(Lang::get('log_in'));
        $pwd->setClass('btn-success');
        $form->addField($pwd);

        $form->setValues($values);

        return $form;
    }

    /**
     * Handles user login (form and processing)
     *
     * @return string|Response
     *
     * @throws HttpException
     * @throws \PHPCensor\Common\Exception\InvalidArgumentException
     */
    public function login()
    {
        if (!empty($_COOKIE['remember_key'])) {
            $user = $this->userStore->getByRememberKey($_COOKIE['remember_key']);
            if ($user) {
                $_SESSION['php-censor-user-id'] = $user->getId();

                $response = new RedirectResponse();
                $response->setHeader('Location', $this->getLoginRedirect());

                return $response;
            }
        }

        $method = $this->request->getMethod();

        if ($method === 'POST') {
            $values = $this->getParams();
        } else {
            $values = [];
        }

        $form = $this->loginForm($values);

        $isLoginFailure = false;

        if ($this->request->getMethod() === 'POST') {
            if (!$form->getChild('login_form')->validate()) {
                $isLoginFailure = true;
            } else {
                $email          = $this->getParam('email');
                $password       = $this->getParam('password', '');
                $rememberMe     = (bool)$this->getParam('remember_me', 0);
                $isLoginFailure = true;

                $user      = $this->userStore->getByEmailOrName($email);
                $providers = $this->authentication->getLoginPasswordProviders();

                if (null !== $user) {
                    // Delegate password verification to the user provider, if found
                    $key = $user->getProviderKey();
                    $isLoginFailure = !isset($providers[$key]) || !$providers[$key]->verifyPassword($user, $password);
                } else {
                    // Ask each providers to provision the user
                    foreach ($providers as $provider) {
                        $user = $provider->provisionUser($email);
                        if ($user && $provider->verifyPassword($user, $password)) {
                            $this->userStore->save($user);
                            $isLoginFailure = false;
                            break;
                        }
                    }
                }

                if (!$isLoginFailure) {
                    $_SESSION['php-censor-user-id'] = $user->getId();

                    if ($rememberMe) {
                        $rememberKey = \md5((string)\microtime(true));

                        $user->setRememberKey($rememberKey);
                        $this->userStore->save($user);

                        \setcookie(
                            'remember_key',
                            $rememberKey,
                            (\time() + 60 * 60 * 24 * 30),
                            '',
                            '',
                            false,
                            true
                        );
                    }

                    $response = new RedirectResponse();
                    $response->setHeader('Location', $this->getLoginRedirect());

                    return $response;
                }
            }
        }

        $this->view->form   = $form->render();
        $this->view->failed = $isLoginFailure;

        return $this->view->render();
    }

    /**
     * Handles user logout.
     */
    public function logout(): Response
    {
        unset($_SESSION['php-censor-user-id']);

        \session_destroy();

        \setcookie(
            'remember_key',
            null,
            (\time() - 1),
            null,
            null,
            null,
            true
        );

        $response = new RedirectResponse();
        $response->setHeader('Location', APP_URL);

        return $response;
    }

    /**
     * Allows the user to request a password reset email.
     *
     * @return string
     *
     * @throws HttpException
     */
    public function forgotPassword(): string
    {
        if ($this->request->getMethod() == 'POST') {
            $email = $this->getParam('email', null);
            $user  = $this->userStore->getByEmail($email);

            if (empty($user)) {
                $this->view->error = Lang::get('reset_no_user_exists');
                return $this->view->render();
            }

            $key     = \md5(\date('Y-m-d') . $user->getHash());
            $message = Lang::get('reset_email_body', $user->getName(), APP_URL, $user->getId(), $key);

            $email = new Email($this->configuration);
            $email->setEmailTo($user->getEmail(), $user->getName());
            $email->setSubject(Lang::get('reset_email_title', $user->getName()));
            $email->setBody($message);
            $email->send();

            $this->view->emailed = true;
        }

        return $this->view->render();
    }

    /**
     * Allows the user to change their password after a password reset email.
     *
     * @param int $userId
     * @param string $key
     *
     * @return string|Response
     *
     * @throws HttpException
     * @throws \PHPCensor\Common\Exception\InvalidArgumentException
     */
    public function resetPassword(int $userId, string $key)
    {
        $user = $this->userStore->getById($userId);
        $userKey = \md5(\date('Y-m-d') . $user->getHash());

        if (empty($user) || $key != $userKey) {
            $this->view->error = Lang::get('reset_invalid');
            return $this->view->render();
        }

        if ($this->request->getMethod() == 'POST') {
            $hash = \password_hash($this->getParam('password'), PASSWORD_DEFAULT);
            $user->setHash($hash);

            $this->userStore->save($user);

            $_SESSION['php-censor-user-id'] = $user->getId();

            $response = new RedirectResponse();
            $response->setHeader('Location', APP_URL);

            return $response;
        }

        $this->view->id = $userId;
        $this->view->key = $key;

        return $this->view->render();
    }

    /**
     * Get the URL the user was trying to go to prior to being asked to log in.
     *
     * @return string
     */
    protected function getLoginRedirect(): string
    {
        $rtn = APP_URL;

        if (!empty($_SESSION['php-censor-login-redirect'])) {
            $rtn .= $_SESSION['php-censor-login-redirect'];
            $_SESSION['php-censor-login-redirect'] = null;
        }

        return $rtn;
    }
}
