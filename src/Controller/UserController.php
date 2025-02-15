<?php

declare(strict_types = 1);

namespace PHPCensor\Controller;

use PHPCensor\Exception\HttpException\ForbiddenException;
use PHPCensor\Exception\HttpException\NotFoundException;
use PHPCensor\Form;
use PHPCensor\Helper\Lang;
use PHPCensor\Http\Response;
use PHPCensor\Http\Response\RedirectResponse;
use PHPCensor\Model\User;
use PHPCensor\Service\UserService;
use PHPCensor\Store\UserStore;
use PHPCensor\View;
use PHPCensor\WebController;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class UserController extends WebController
{
    public string $layoutName = 'layout';

    protected UserStore $userStore;

    protected UserService $userService;

    /**
     * Initialise the controller, set up stores and services.
     */
    public function init(): void
    {
        parent::init();

        $this->userStore   = $this->storeRegistry->get('User');
        $this->userService = new UserService($this->storeRegistry, $this->userStore);
    }

    /**
    * View user list.
    */
    public function index(): string
    {
        $users                   = $this->userStore->getWhere([], 1000, 0, ['email' => 'ASC']);
        $this->view->currentUser = $this->getUser();
        $this->view->users       = $users;

        $this->layout->title     = Lang::get('manage_users');

        return $this->view->render();
    }

    /**
     * Allows the user to edit their profile.
     *
     * @return string
     *
     * @throws \PHPCensor\Common\Exception\RuntimeException
     * @throws \PHPCensor\Exception\HttpException
     */
    public function profile(): string
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($this->request->getMethod() == 'POST') {
            $name     = $this->getParam('name', null);
            $email    = $this->getParam('email', null);
            $password = $this->getParam('password', null);

            $language = $this->getParam('language', null);
            if (!$language) {
                $language = null;
            }

            $perPage  = (int)$this->getParam('per_page', null);
            if (!$perPage) {
                $perPage = null;
            }

            $user = $this->userService->updateUser($user, $name, $email, $password, null, $language, $perPage);

            $this->view->updated = 1;
        }

        $this->layout->title    = $user->getName();
        $this->layout->subtitle = Lang::get('edit_profile');

        $form = new Form();

        $form->setAction(APP_URL . 'user/profile');
        $form->setMethod('POST');

        $form->addField(new Form\Element\Csrf('profile_form'));

        $name = new Form\Element\Text('name');
        $name->setClass('form-control');
        $name->setContainerClass('form-group');
        $name->setLabel(Lang::get('name'));
        $name->setRequired(true);
        $name->setValue($user->getName());
        $form->addField($name);

        $email = new Form\Element\Email('email');
        $email->setClass('form-control');
        $email->setContainerClass('form-group');
        $email->setLabel(Lang::get('email_address'));
        $email->setRequired(true);
        $email->setValue($user->getEmail());
        $form->addField($email);

        $password = new Form\Element\Password('password');
        $password->setClass('form-control');
        $password->setContainerClass('form-group');
        $password->setLabel(Lang::get('password_change'));
        $password->setRequired(false);
        $password->setValue(null);
        $form->addField($password);

        $language = new Form\Element\Select('language');
        $language->setClass('form-control');
        $language->setContainerClass('form-group');
        $language->setLabel(Lang::get('language'));
        $language->setRequired(true);
        $language->setOptions(
            \array_merge(
                [null => Lang::get('default') . ' (' . $this->configuration->get('php-censor.language') .  ')'],
                Lang::getLanguageOptions()
            )
        );
        $language->setValue($user->getLanguage());
        $form->addField($language);

        $perPage = new Form\Element\Select('per_page');
        $perPage->setClass('form-control');
        $perPage->setContainerClass('form-group');
        $perPage->setLabel(Lang::get('per_page'));
        $perPage->setRequired(true);
        $perPage->setOptions([
            null => Lang::get('default') . ' (' . $this->configuration->get('php-censor.per_page') .  ')',
            10    => 10,
            25    => 25,
            50    => 50,
            100   => 100,
        ]);
        $perPage->setValue($user->getPerPage());
        $form->addField($perPage);

        $submit = new Form\Element\Submit();
        $submit->setClass('btn btn-success');
        $submit->setValue(Lang::get('save'));
        $form->addField($submit);

        $this->view->form = $form;

        return $this->view->render();
    }

    /**
     * Add a user - handles both form and processing.
     *
     * @return string|Response
     *
     * @throws \PHPCensor\Exception\HttpException
     */
    public function add()
    {
        $this->requireAdmin();

        $this->layout->title = Lang::get('add_user');

        $method = $this->request->getMethod();

        if ($method === 'POST') {
            $values = $this->getParams();
        } else {
            $values = [];
        }

        $form = $this->userForm($values);

        if ($method !== 'POST' || ($method == 'POST' && !$form->validate())) {
            $view       = new View('User/edit');
            $view->type = 'add';
            $view->user = null;
            $view->form = $form;

            return $view->render();
        }


        $name     = $this->getParam('name', null);
        $email    = $this->getParam('email', null);
        $password = $this->getParam('password', null);
        $isAdmin  = (bool)$this->getParam('is_admin', 0);

        $this->userService->createUser(
            $name,
            $email,
            'internal',
            ['type' => 'internal'],
            $password,
            $isAdmin
        );

        $response = new RedirectResponse();
        $response->setHeader('Location', APP_URL . 'user');

        return $response;
    }

    /**
     * Edit a user - handles both form and processing.
     *
     * @param int $userId
     *
     * @return string|Response
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws \PHPCensor\Common\Exception\RuntimeException
     * @throws \PHPCensor\Exception\HttpException
     */
    public function edit(int $userId)
    {
        $currentUser = $this->getUser();

        $method = $this->request->getMethod();
        $user   = $this->userStore->getById($userId);

        if (!$currentUser->getIsAdmin() && $currentUser !== $user) {
            throw new ForbiddenException('You do not have permission to do that.');
        }

        if (empty($user)) {
            throw new NotFoundException(Lang::get('user_n_not_found', $userId));
        }

        $this->layout->title = $user->getName();
        $this->layout->subtitle = Lang::get('edit_user');

        $values = \array_merge($user->getDataArray(), $this->getParams());
        $form = $this->userForm($values, 'edit/' . $userId);

        if ($method != 'POST' || ($method == 'POST' && !$form->validate())) {
            $view = new View('User/edit');
            $view->type = 'edit';
            $view->user = $user;
            $view->form = $form;

            return $view->render();
        }

        $name     = $this->getParam('name', null);
        $email    = $this->getParam('email', null);
        $password = $this->getParam('password', null);

        // Only admins can promote/demote users.
        $isAdmin = $user->getIsAdmin();
        if ($currentUser->getIsAdmin()) {
            $isAdmin = (bool)$this->getParam('is_admin', 0);
        }

        $this->userService->updateUser($user, $name, $email, $password, $isAdmin);

        $response = new RedirectResponse();
        $response->setHeader('Location', APP_URL . 'user');

        return $response;
    }

    /**
     * Create user add / edit form.
     *
     * @param array $values
     * @param string $type
     *
     * @return Form
     *
     * @throws \PHPCensor\Common\Exception\RuntimeException
     * @throws \PHPCensor\Exception\HttpException
     */
    protected function userForm(array $values, string $type = 'add'): Form
    {
        $currentUser = $this->getUser();

        $form = new Form();

        $form->setMethod('POST');
        $form->setAction(APP_URL . 'user/' . $type);

        $form->addField(new Form\Element\Csrf('user_form'));

        $field = new Form\Element\Email('email');
        $field->setRequired(true);
        $field->setLabel(Lang::get('email_address'));
        $field->setClass('form-control');
        $field->setContainerClass('form-group');
        $form->addField($field);

        $field = new Form\Element\Text('name');
        $field->setRequired(true);
        $field->setLabel(Lang::get('name'));
        $field->setClass('form-control');
        $field->setContainerClass('form-group');
        $form->addField($field);

        $field = new Form\Element\Password('password');

        if ($type == 'add') {
            $field->setRequired(true);
            $field->setLabel(Lang::get('password'));
        } else {
            $field->setRequired(false);
            $field->setLabel(Lang::get('password_change'));
        }

        $field->setClass('form-control');
        $field->setContainerClass('form-group');
        $form->addField($field);

        if ($currentUser->getIsAdmin()) {
            $field = new Form\Element\Checkbox('is_admin');
            $field->setRequired(false);
            $field->setCheckedValue(1);
            $field->setLabel(Lang::get('is_user_admin'));
            $field->setContainerClass('form-group');
            $form->addField($field);
        }

        $field = new Form\Element\Submit();
        $field->setValue(Lang::get('save_user'));
        $field->setClass('btn-success');
        $form->addField($field);

        $form->setValues($values);

        return $form;
    }

    /**
     * Delete a user.
     *
     * @param int $userId
     *
     * @return Response
     *
     * @throws NotFoundException
     * @throws \PHPCensor\Exception\HttpException
     */
    public function delete(int $userId): Response
    {
        $this->requireAdmin();

        $user = $this->userStore->getById($userId);

        if (empty($user)) {
            throw new NotFoundException(Lang::get('user_n_not_found', $userId));
        }

        $this->userService->deleteUser($user);

        $response = new RedirectResponse();
        $response->setHeader('Location', APP_URL . 'user');

        return $response;
    }
}
