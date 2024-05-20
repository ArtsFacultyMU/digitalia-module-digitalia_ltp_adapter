<?php

namespace Drupal\digitalia_ltp_adapter_archivematica\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Defines a form that configures digitalia_ltp_adapter's settings
 */
class ConfigurationForm extends ConfigFormBase
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
			'#title' => $this->t('Archivematica host URL'),
			'#description' => $this->t('Please omit trailing slash, e.g. https://archivematica.example:8080'),
			'#default_value' => $config->get('am_host'),
		];

		$form['api_key_username'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Username'),
			'#default_value' => $config->get('api_key_username'),
		];

		$form['api_key_password'] = [
			'#type' => 'password',
			'#title' => $this->t('Api key'),
			'#description' => $this->t('<strong>Must be reentered on every save!</strong>'),
			'#default_value' => $config->get('api_key_password'),
		];

		$form['base_url'] = [
			'#type' => 'textfield',
			'#title' => $this->t('URL of directory shared with Archivematica'),
			'#description' => $this->t('Please omit trailing slash, e.g. public://archivematica'),
			'#default_value' => $config->get('base_url'),
		];

		$form['am_shared_path'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Path to shared directory on Archivematica'),
			'#description' => $this->t('Please omit trailing slash, e.g. /archivematica'),
			'#default_value' => $config->get('am_shared_path'),
		];

		$form['processing_config'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Processing config to use'),
			'#description' => $this->t("e.g. 'automated'"),
			'#default_value' => $config->get('processing_config'),
		];

		$form['transfer_field'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Transfer uuid field'),
			'#description' => $this->t('Holds uuid of last transfer'),
			'#default_value' => $config->get('transfer_field'),
		];

		$form['sip_field'] = [
			'#type' => 'textfield',
			'#title' => $this->t('SIP uuid field'),
			'#description' => $this->t('Holds uuid of last sip'),
			'#default_value' => $config->get('sip_field'),
		];

		return parent::buildForm($form, $form_state);
	}


	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('am_host', $form_state->getValue('am_host'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('api_key_username', $form_state->getValue('api_key_username'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('api_key_password', $form_state->getValue('api_key_password'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('base_url', $form_state->getValue('base_url'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('am_shared_path', $form_state->getValue('am_shared_path'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('processing_config', $form_state->getValue('processing_config'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('transfer_field', $form_state->getValue('transfer_field'));
		$this->config('digitalia_ltp_adapter_archivematica.settings')->set('sip_field', $form_state->getValue('sip_field'))->save();

		parent::submitForm($form, $form_state);
	}

}
