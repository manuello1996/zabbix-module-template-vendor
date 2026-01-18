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

use API;
use CAudit;
use CArrayHelper;
use CController;
use CControllerHostMacrosList;
use CControllerResponseData;
use CRoleHelper;
use CWebUser;

require_once __DIR__ . '/../../../include/forms.inc.php';

class TemplateEdit extends CController {

	protected function init(): void {
		// Popup uses JSON requests, keep CSRF handling compatible with core behavior.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'templateid' =>				'db hosts.hostid',
			'template_name' =>			'db hosts.host',
			'visiblename' =>			'db hosts.name',
			'templates' =>				'array_db hosts.hostid',
			'add_templates' =>			'array_db hosts.hostid',
			'groupids' =>				'array_db hosts_groups.groupid',
			'groups' =>					'array',
			'description' =>			'db hosts.description',
			'vendor_name' =>			'db hosts.vendor_name',
			'vendor_version' =>			'db hosts.vendor_version',
			'tags' =>					'array',
			'macros' =>					'array',
			'show_inherited_macros' =>	'in 0,1',
			'valuemaps' =>				'array',
			'clone' =>					'in 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(
				(new CControllerResponseData(['main_block' => json_encode([
					'error' => [
						'messages' => array_column(get_and_clear_messages(), 'message')
					]
				])]))->disableView()
			);
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		if (!$this->checkAccess(CRoleHelper::UI_CONFIGURATION_TEMPLATES)) {
			return false;
		}

		if ($this->hasInput('templateid')) {
			$templates = API::Template()->get([
				'output' => [],
				'templateids' => $this->getInput('templateid'),
				'editable' => true
			]);

			if (!$templates) {
				return false;
			}
		}

		return true;
	}

	protected function doAction(): void {
		$templateid = $this->hasInput('templateid') ? $this->getInput('templateid') : null;
		$clone = $this->hasInput('clone');
		$templates = [];
		$warnings = [];
		$groupids = [];

		if ($clone) {
			$data = [
				'templateid' => null,
				'clone_templateid' => $templateid,
				'template_name' =>$this->getInput('template_name', ''),
				'visible_name' => $this->getInput('visiblename', ''),
				'linked_templates' => [],
				'templates' => $this->getInput('templates', []),
				'add_templates' => [],
				'original_templates' => [],
				'groups_ms' => [],
				'description' => $this->getInput('description', ''),
				'vendor_name' => $this->getInput('vendor_name', ''),
				'vendor_version' => $this->getInput('vendor_version', ''),
				'tags' => $this->getInput('tags', []),
				'show_inherited_macros' => $this->getInput('show_inherited_macros', 0),
				'valuemaps' => array_values($this->getInput('valuemaps', [])),
				'readonly' => false,
				'vendor_readonly' => false,
				'clone' => true
			];

			// Add already linked and new templates when cloning element.
			$request_add_templates = $this->getInput('add_templates', []);

			if ($data['templates'] || $request_add_templates) {
				$templates = API::Template()->get([
					'output' => ['templateid', 'name'],
					'templateids' => array_merge($data['templates'], $request_add_templates),
					'preservekeys' => true
				]);

				$data['linked_templates'] = array_intersect_key($templates, array_flip($data['templates']));
				CArrayHelper::sort($data['linked_templates'], ['name']);

				$data['add_templates'] = array_intersect_key($templates, array_flip($request_add_templates));

				foreach ($data['add_templates'] as &$template) {
					$template = CArrayHelper::renameKeys($template, ['templateid' => 'id']);
				}
				unset($template);
			}

			// Prepare macros data.
			$macros = cleanInheritedMacros(getRequest('macros', []));

			// Remove empty macro lines.
			$macros = array_filter($macros, function($macro) {
				$keys = array_flip(['hostmacroid', 'macro', 'value', 'description']);

				return (bool) array_filter(array_intersect_key($macro, $keys));
			});

			// Reset macro type and value.
			foreach ($macros as &$macro) {
				if (array_key_exists('allow_revert', $macro) && array_key_exists('value', $macro)) {
					$macro['deny_revert'] = true;

					unset($macro['allow_revert']);
				}
			}
			unset($macro);

			$secret_macro_reset = false;

			foreach ($macros as &$macro) {
				if ($macro['type'] == ZBX_MACRO_TYPE_SECRET && !array_key_exists('value', $macro)) {
					$macro = [
						'type' => ZBX_MACRO_TYPE_TEXT,
						'value' => ''
					] + $macro;

					$secret_macro_reset = true;

					unset($macro['allow_revert']);
				}
			}
			unset($macro);

			if ($secret_macro_reset) {
				$warnings[] = _('The cloned template contains user defined macros with type "Secret text". The value and type of these macros were reset.');
			}

			$macros = array_map(function($macro) {
				return array_diff_key($macro, array_flip(['hostmacroid']));
			}, $macros);

			$data['macros'] = $macros;

			// Prepare groups data.
			$groups = $this->getInput('groups', []);

			// Remove inaccessible groups from request, but leave "new".
			foreach ($groups as $group) {
				if (!is_array($group)) {
					$groupids[] = $group;
				}
			}

			if ($groupids) {
				$groups_allowed = API::TemplateGroup()->get([
					'output' => [],
					'groupids' => $groupids,
					'editable' => true,
					'preservekeys' => true
				]);

				if (count($groupids) != count($groups_allowed)) {
					$warnings[] = _("The template being cloned belongs to a template group you don't have write permissions to. Non-writable group has been removed from the new template.");
				}

				foreach ($groups as $idx => $group) {
					if (!is_array($group) && !array_key_exists($group, $groups_allowed)) {
						unset($groups[$idx]);
					}
				}
			}
		}
		else {
			$data = $this->getDefaultTemplateData();
			$data['templateid'] = $templateid;

			if ($templateid !== null) {
				$dbTemplates = API::Template()->get([
					'output' => ['host', 'name', 'description', 'vendor_name', 'vendor_version'],
					'selectTemplateGroups' => ['groupid'],
					'selectParentTemplates' => ['templateid'],
					'selectMacros' => ['hostmacroid', 'hostid', 'macro', 'value', 'description', 'type', 'automatic'],
					'selectTags' => ['tag', 'value'],
					'selectValueMaps' => ['valuemapid', 'name', 'mappings'],
					'templateids' => $templateid
				]);

				$data['dbTemplate'] = reset($dbTemplates);

				$data['template_name'] = $data['dbTemplate']['host'];
				$data['visible_name'] = $data['dbTemplate']['name'];

				// Display empty visible name if equal to host name.
				if ($data['visible_name'] === $data['template_name']) {
					$data['visible_name'] = '';
				}

				$data['description'] = $data['dbTemplate']['description'];
				$data['tags'] = $data['dbTemplate']['tags'];
				$data['macros'] = $data['dbTemplate']['macros'];
				$data['vendor_name'] = $data['dbTemplate']['vendor_name'];
				$data['vendor_version'] = $data['dbTemplate']['vendor_version'];

				CArrayHelper::sort($data['dbTemplate']['valuemaps'], ['name']);

				$data['valuemaps'] = array_values($data['dbTemplate']['valuemaps']);

				foreach ($data['dbTemplate']['parentTemplates'] as $parentTemplate) {
					$data['original_templates'][$parentTemplate['templateid']] = $parentTemplate['templateid'];
				}

				// Add already linked templates.
				$linked_templates = $data['dbTemplate']['parentTemplates'];

				foreach ($linked_templates as $template) {
					$linked_template_ids[$template['templateid']] = $template['templateid'];
				}

				if ($linked_templates) {
					$templates = API::Template()->get([
						'output' => ['templateid', 'name'],
						'templateids' => $linked_template_ids,
						'preservekeys' => true
					]);

					$data['linked_templates'] = array_intersect_key($templates, array_flip($linked_template_ids));
					CArrayHelper::sort($data['linked_templates'], ['name']);
				}
			}

			// Prepare groups data based on permissions and accessibility.
			if ($templateid === null) {
				$groups = $this->getInput('groupids', []);
			}
			else {
				$groups = array_column($data['dbTemplate']['templategroups'], 'groupid');
			}

			foreach ($groups as $group) {
				if (is_array($group) && array_key_exists('new', $group)) {
					continue;
				}

				$groupids[] = $group;
			}
		}

		// Retrieve writable templates.
		$data['writable_templates'] = API::Template()->get([
			'output' => ['templateid'],
			'templateids' => array_keys($data['linked_templates']),
			'editable' => true,
			'preservekeys' => true
		]);

		// Groups with R and RW permissions.
		$groups_all = $groupids
			? API::TemplateGroup()->get([
				'output' => ['name'],
				'groupids' => $groupids,
				'preservekeys' => true
			])
			: [];

		// Groups with RW permissions.
		$groups_rw = $groupids && (CWebUser::getType() != USER_TYPE_SUPER_ADMIN)
			? API::TemplateGroup()->get([
				'output' => [],
				'groupids' => $groupids,
				'editable' => true,
				'preservekeys' => true
			])
			: [];

		// Prepare data for multiselect.
		foreach ($groups as $group) {
			if (is_array($group) && array_key_exists('new', $group)) {
				$data['groups_ms'][] = [
					'id' => $group['new'],
					'name' => $group['new'].' ('._x('new', 'new element in multiselect').')',
					'isNew' => true
				];
			}
			elseif (array_key_exists($group, $groups_all)) {
				$data['groups_ms'][] = [
					'id' => $group,
					'name' => $groups_all[$group]['name'],
					'disabled' => CWebUser::getType() != USER_TYPE_SUPER_ADMIN && !array_key_exists($group, $groups_rw)
				];
			}
		}

		CArrayHelper::sort($data['groups_ms'], ['name']);

		// Insert empty tag row when no tags are present.
		if (!$data['tags']) {
			$data['tags'][] = ['tag' => '', 'value' => ''];
		}

		CArrayHelper::sort($data['tags'], ['tag', 'value']);

		// Insert empty row when no macros are present.
		if (!$data['macros'] && $data['show_inherited_macros'] == 0) {
			$macro = ['macro' => '', 'value' => '', 'description' => '', 'type' => ZBX_MACRO_TYPE_TEXT];

			if ($data['show_inherited_macros']) {
				$macro['inherited_type'] = ZBX_PROPERTY_OWN;
			}

			$data['macros'][] = $macro;
		}

		// Add inherited macros to template macros.
		if ($data['show_inherited_macros']) {
			$data['macros'] = mergeInheritedMacros($data['macros'], getInheritedMacros(array_keys($templates)));
		}

		$data['macros'] = array_values(order_macros($data['macros'], 'macro'));

		foreach ($data['macros'] as &$macro) {
			$macro['discovery_state'] = CControllerHostMacrosList::DISCOVERY_STATE_MANUAL;
		}
		unset($macro);

		// Enable revert button for secret text macros when editing template.
		foreach ($data['macros'] as &$macro) {
			if ($macro['type'] == ZBX_MACRO_TYPE_SECRET
				&& !array_key_exists('deny_revert', $macro) && !array_key_exists('value', $macro)) {
				$macro['allow_revert'] = true;
			}
		}
		unset($macro);

		$data['warnings'] = $warnings;
		$data['audit_logs'] = [];
		$data['show_audit_logs'] = CWebUser::getType() == USER_TYPE_SUPER_ADMIN && $templateid !== null;
		$data['audit_action_labels'] = [
			CAudit::ACTION_ADD => _('Add'),
			CAudit::ACTION_UPDATE => _('Update'),
			CAudit::ACTION_DELETE => _('Delete'),
			CAudit::ACTION_EXECUTE => _('Execute'),
			CAudit::ACTION_HISTORY_CLEAR => _('History clear'),
			CAudit::ACTION_CONFIG_REFRESH => _('Configuration refresh'),
			CAudit::ACTION_PUSH => _('Push'),
			CAudit::ACTION_LOGIN_SUCCESS => _('Login'),
			CAudit::ACTION_LOGIN_FAILED => _('Failed login'),
			CAudit::ACTION_LOGOUT => _('Logout')
		];
		$data['user'] = ['debug_mode' => $this->getDebugMode()];

		if ($data['show_audit_logs']) {
			// Load recent template audit entries for super admins only.
			$data['audit_logs'] = API::AuditLog()->get([
				'output' => ['auditid', 'action', 'clock', 'username', 'ip', 'details'],
				'filter' => [
					'resourcetype' => CAudit::RESOURCE_TEMPLATE,
					'resourceid' => $templateid,
					'action' => CAudit::ACTION_UPDATE
				],
				'sortfield' => 'auditid',
				'sortorder' => ZBX_SORT_DOWN,
				'limit' => 10
			]);

			$data['audit_logs'] = array_map([$this, 'formatAuditLogDetails'], $data['audit_logs']);
		}

		$this->setResponse(new CControllerResponseData($data));
	}

	private function getDefaultTemplateData(): array {
		return [
			'template_name' => '',
			'visible_name' => '',
			'linked_templates' => [],
			'add_templates' => [],
			'original_templates' => [],
			'templates' => [],
			'groups_ms' => [],
			'description' => '',
			'vendor_name' => '',
			'vendor_version' => '',
			'tags' => [],
			'macros' => [],
			'show_inherited_macros' => 0,
			'valuemaps' => [],
			'audit_logs' => [],
			'show_audit_logs' => false,
			'audit_action_labels' => [],
			'readonly' => false,
			'vendor_readonly' => false,
			'clone' => false
		];
	}

	private function formatAuditLogDetails(array $audit): array {
		$audit['details_text'] = '';
		$audit['diff'] = [
			'added' => [],
			'removed' => [],
			'changed' => []
		];
		$audit['description_diff'] = null;

		if ($audit['details'] === '') {
			return $audit;
		}

		$details = json_decode($audit['details'], true);
		if (!is_array($details) || !$details) {
			return $audit;
		}

		ksort($details);

		foreach ($details as $path => $detail) {
			if (!is_array($detail) || !array_key_exists(0, $detail)) {
				continue;
			}

			switch ($detail[0]) {
				case CAudit::DETAILS_ACTION_ADD:
					$audit['diff']['added'][] = array_key_exists(1, $detail)
						? $path.': '.$detail[1]
						: $path;
					break;

				case CAudit::DETAILS_ACTION_DELETE:
					$audit['diff']['removed'][] = $path;
					break;

				case CAudit::DETAILS_ACTION_UPDATE:
					if ($path === 'template.description' && array_key_exists(1, $detail)
							&& array_key_exists(2, $detail)) {
						$audit['description_diff'] = [
							'old' => $detail[2],
							'new' => $detail[1],
							'old_preview' => $this->truncateText($detail[2]),
							'new_preview' => $this->truncateText($detail[1])
						];
						break;
					}
					$audit['diff']['changed'][] = array_key_exists(1, $detail)
						? sprintf('%s: %s => %s', $path, $detail[2], $detail[1])
						: $path;
					break;
			}
		}

		if ($audit['diff']['added']) {
			$audit['details_text'] .= _('Added').":\n".implode("\n", $audit['diff']['added']);
		}
		if ($audit['diff']['removed']) {
			$audit['details_text'] .= ($audit['details_text'] === '' ? '' : "\n\n").
				_('Removed').":\n".implode("\n", $audit['diff']['removed']);
		}
		if ($audit['diff']['changed']) {
			$audit['details_text'] .= ($audit['details_text'] === '' ? '' : "\n\n").
				_('Changed').":\n".implode("\n", $audit['diff']['changed']);
		}

		return $audit;
	}

	private function truncateText(string $value, int $limit = 120): string {
		if (mb_strlen($value) <= $limit) {
			return $value;
		}

		return mb_substr($value, 0, $limit - 3).'...';
	}
}
