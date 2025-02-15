<?php

namespace PHPCensor\Helper;

use PHPCensor\Model\Project;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class Branch
{
    public static function getDefaultBranchName($projectType)
    {
        switch ($projectType) {
            case Project::TYPE_HG:
            case Project::TYPE_BITBUCKET_HG:
                return 'default';
            case Project::TYPE_SVN:
                return 'trunk';
            default:
                return 'master';
        }
    }
}
