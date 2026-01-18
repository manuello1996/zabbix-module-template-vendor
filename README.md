# Template Vendor Editor Module

This module overrides the standard template edit popup to let users with read-write permission edit
or define the template vendor and version fields.

## Installation
1) Copy this folder into `ui/modules/zabbix-module-template-vendor`.
2) In Zabbix UI, open `Administration -> General -> Modules` and enable "Template Vendor Editor".

## Usage
- Open any template edit popup.
- If you have read-write access to the template, the Vendor and Version fields are editable.
- If you have read-only access, the fields are disabled.
- Vendor and Version must be either both filled or both empty.

## Notes
- The module does not add new menu entries or pages.
- It only overrides `template.edit`, `template.update`, and `template.create` actions to provide
  editable vendor/version fields and a clearer validation error message.
