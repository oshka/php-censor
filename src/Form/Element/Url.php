<?php

declare(strict_types = 1);

namespace PHPCensor\Form\Element;

use PHPCensor\View;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class Url extends Text
{
    /**
     * @param string $viewFile
     *
     * @return string
     */
    public function render(?string $viewFile = null): string
    {
        return parent::render(($viewFile ? $viewFile : 'Text'));
    }

    /**
     * @param View $view
     */
    protected function onPreRender(View &$view): void
    {
        parent::onPreRender($view);

        $view->type = 'url';
    }
}
