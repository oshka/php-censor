<?php

declare(strict_types = 1);

namespace PHPCensor\Controller;

use PHPCensor\Helper\Lang;
use PHPCensor\WebController;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class HomeController extends WebController
{
    public string $layoutName = 'layout';

    /**
    * Display dashboard:
    */
    public function index(): string
    {
        $this->layout->title = Lang::get('dashboard');

        $widgets = [
            'left'  => [],
            'right' => [],
        ];

        $widgetsConfig = $this->configuration->get('php-censor.dashboard_widgets', [
            'all_projects' => [
                'side' => 'left',
            ],
            'last_builds' => [
                'side' => 'right',
            ],
        ]);

        foreach ($widgetsConfig as $name => $params) {
            $side = (isset($params['side']) && 'right' === $params['side'])
                ? 'right'
                : 'left';
            $widgets[$side][$name] = $params;
        }

        $this->view->widgets = $widgets;

        return $this->view->render();
    }
}
