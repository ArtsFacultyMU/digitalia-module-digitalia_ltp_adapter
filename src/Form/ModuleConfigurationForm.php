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
		$form['test_field'] = [
			'#type' => 'textfield',
			'#title' => 'Test config field',
			'#default_value' => $config->get('test_field'),
		];

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
			'#default_value' => $config->get('api_key_password'),
		];

		$form['base_url'] = [
			'#type' => 'textfield',
			'#title' => 'URL of directory shared with Archivematica',
			'#description' => 'Please omit trailing slash, e.g. public://archivematica',
			'#default_value' => $config->get('base_url'),
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

		parent::submitForm($form, $form_state);
	}

}
