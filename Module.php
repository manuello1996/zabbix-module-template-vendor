<?php declare(strict_types = 0);
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


namespace Modules\TemplateVendor;

use APP;
use CMenuItem;
use Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		// Register administration menu item for module settings.
		$administration = APP::Component()->get('menu.main')->find(_('Administration'));
		if ($administration === null) {
			return;
		}

		$general = $administration->getSubMenu()->find(_('General'));
		if ($general === null || !$general->hasSubMenu()) {
			return;
		}

		$general->getSubMenu()->insertAfter(_('Other'),
			(new CMenuItem(_('Template audit')))->setAction('template.audit.edit')
		);
	}
}
