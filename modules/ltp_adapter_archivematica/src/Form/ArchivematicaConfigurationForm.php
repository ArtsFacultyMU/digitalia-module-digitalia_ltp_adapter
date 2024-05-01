<?php

namespace Drupal\digitalia_ltp_adapter\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Defines a form that configures digitalia_ltp_adapter's settings
 */
class ArchivematicaConfigurationForm extends ConfigFormBase
{
	/**
	 * {@inheritdoc}
	 */
	public function getFormId()
	{
		return 'digitalia_ltp_adapter_archivematica_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getEditableConfigNames()
	{
		return [
			'digitalia_ltp_adapter_archivematica.settings',
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state)
	{
		$config = $this->config('digitalia_ltp_adapter_archivematica.settings');

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

		$form['transfer_field'] = [
			'#type' => 'textfield',
			'#title' => 'Transfer uuid field',
			'#description' => 'Holds uuid of last transfer',
			'#default_value' => $config->get('transfer_field'),
		];

		$form['sip_field'] = [
			'#type' => 'textfield',
			'#title' => 'SIP uuid field',
			'#description' => 'Holds uuid of last sip',
			'#default_value' => $config->get('sip_field'),
		];


		$form['enabled'] = [
			'#type' => 'checkbox',
			'#title' => 'Enable export to Archivematica',
			'#default_value' => $config->get('enabled'),
		];


		return parent::buildForm($form, $form_state);

	}


	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		$this->config('digitalia_ltp_adapter.archivematica_settings')->set('am_host', $form_state->getValue('am_host'))->save();
		$this->config('digitalia_ltp_adapter.archivematica_settings')->set('api_key_username', $form_state->getValue('api_key_username'))->save();
		$this->config('digitalia_ltp_adapter.archivematica_settings')->set('api_key_password', $form_state->getValue('api_key_password'))->save();
		$this->config('digitalia_ltp_adapter.archivematica_settings')->set('base_url', $form_state->getValue('base_url'))->save();
		$this->config('digitalia_ltp_adapter.archivematica_settings')->set('transfer_field', $form_state->getValue('transfer_field'))->save();
		$this->config('digitalia_ltp_adapter.archivematica_settings')->set('sip_field', $form_state->getValue('sip_field'))->save();

		parent::submitForm($form, $form_state);
	}

}
