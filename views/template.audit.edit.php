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


/**
 * @var CView $this
 * @var array $data
 */

$html_page = (new CHtmlPage())
	->setTitle(_('Template audit'))
	->setTitleSubmenu(getAdministrationGeneralSubmenu());

$form_list = (new CFormList())
	->addRow(
		new CLabel(_('Show template audit in popup'), 'audit_enabled'),
		(new CCheckBox('audit_enabled'))
			->setUncheckedValue('0')
			->setChecked($data['audit_enabled'] == 1)
	);

$form = (new CForm())
	->addItem((new CVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('template.audit.update')))->removeId())
	->setId('template-audit-form')
	->setName('templateAuditForm')
	->setAction((new CUrl('zabbix.php'))
		->setArgument('action', 'template.audit.update')
		->getUrl()
	)
	->setAttribute('aria-labelledby', CHtmlPage::PAGE_TITLE_ID)
	->addItem(
		(new CTabView())
			->addTab('template_audit', _('Template audit'), $form_list)
			->setFooter(makeFormFooter(
				new CSubmit('update', _('Update'))
			))
	);

$html_page
	->addItem($form)
	->show();
