<?php

namespace App\Controller;

use App\Controller\Admin\BaseAdminController;

class AdminController extends BaseAdminController
{
    public function __construct(
        \App\Service\I18nService $i18n,
        \App\Service\Settings $config,
        \PDO $pdo,
        \App\Service\AppSettingRepository $appSettings
    ) {
        parent::__construct($i18n, $config, $pdo, $appSettings);
    }
}
