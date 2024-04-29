<?php

namespace Drupal\digitalia_ltp_adapter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Defines a form that configures digitalia_ltp_adapter's settings
 */
class ModuleConfigurationForm extends ConfigFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId()
	{
		return 'digitalia_ltp_adapter_admin_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getEditableConfigNames()
	{
		return [
			'digitalia_ltp_adapter.admin_settings',
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state)
	{
		$config = $this->config('digitalia_ltp_adapter.admin_settings');

		$form['am_host'] = [
			'#type' => 'textfield',
			'#title' => 'Archivematica host URL',
			'#description' => 'Please omit trailing slash, e.g. https://archivematica.example:8080',
			'#default_value' => $config->get('am_host'),
		];

		$form['api_key_username'] = [
			'#type' => 'textfield',
			'#title' => 'Archivematica username',
			'#default_value' => $config->get('api_key_username'),
		];

		$form['api_key_password'] = [
			'#type' => 'password',
			'#title' => 'Archivematica password',
			'#description' => '<strong>Must be reentered on every save!</strong>',
			'#default_value' => $config->get('api_key_password'),
		];

		$form['base_url'] = [
			'#type' => 'textfield',
			'#title' => 'URL of directory shared with Archivematica',
			'#description' => 'Please omit trailing slash, e.g. public://archivematica',
			'#default_value' => $config->get('base_url'),
		];

		$form['site_name'] = [
			'#type' => 'textfield',
			'#title' => 'Site name',
			'#description' => 'Used as prefix for object ingest. Node with id \'42\' and site name \'my-islandora\' will be ingested to Archivematica as \'my-islandora_42\'',
			'#default_value' => $config->get('site_name'),
		];

		$form['transfer_field'] = [
			'#type' => 'textfield',
			'#title' => 'Transfer field',
			'#description' => 'Holds uuid of last transfer',
			'#default_value' => $config->get('transfer_field'),
		];

		$form['field_configuration'] = [
			'#type' => 'textarea',
			'#size' => '60',
			'#title' => 'List of content types and fields for export',
			'#description' => 'Use tokens for values: \'content_type::metadata_name::[node:token]\', e.g. \'page::dcterms.title::[node:title]\' or \'page::title::[node:title]\'',
			'#default_value' => $config->get('field_configuration'),
		];

		$form['auto_generate_switch'] = [
			'#type' => 'checkbox',
			'#title' => 'Export on save',
			'#description' => 'When enabled, exports only when the entity is published (with media when media AND parent is published)',
			'#default_value' => $config->get('auto_generate_switch'),
		];



		return parent::buildForm($form, $form_state);

	}


	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		$this->config('digitalia_ltp_adapter.admin_settings')->set('test_field', $form_state->getValue('test_field'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('am_host', $form_state->getValue('am_host'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('api_key_username', $form_state->getValue('api_key_username'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('api_key_password', $form_state->getValue('api_key_password'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('base_url', $form_state->getValue('base_url'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('site_name', $form_state->getValue('site_name'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('transfer_field', $form_state->getValue('transfer_field'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('field_configuration', $form_state->getValue('field_configuration'))->save();
		$this->config('digitalia_ltp_adapter.admin_settings')->set('auto_generate_switch', $form_state->getValue('auto_generate_switch'))->save();

		parent::submitForm($form, $form_state);
	}

}
