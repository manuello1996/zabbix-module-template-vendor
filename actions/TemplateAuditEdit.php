<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2025 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


namespace Modules\TemplateVendor\Actions;

use APP;
use CController;
use CControllerResponseData;
use CWebUser;

class TemplateAuditEdit extends CController {

	protected function init(): void {
		// Allow GET access to settings page without CSRF token.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		$module = APP::ModuleManager()->getModule('template-vendor');
		$audit_enabled = $module !== null ? (int) $module->getOption('audit_enabled', 1) : 1;

		$data = [
			'audit_enabled' => $audit_enabled,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		$this->setResponse(new CControllerResponseData($data));
	}
}
