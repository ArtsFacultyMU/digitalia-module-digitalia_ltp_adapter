<?php

namespace Drupal\digitalia_ltp_adapter_arclib\Form;

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
		return 'digitalia_ltp_adapter_arclib_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function getEditableConfigNames()
	{
		return [
			'digitalia_ltp_adapter_arclib.settings',
		];
	}


	/**
	 * {@inheritdoc}
	 */
	public function buildForm(array $form, FormStateInterface $form_state)
	{
		$config = $this->config('digitalia_ltp_adapter_arclib.settings');

		$form['arc_host'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Host URL'),
			'#description' => $this->t('Please omit trailing slash, e.g. https://arclib.example:8080'),
			'#default_value' => $config->get('arc_host'),
		];

		$form['username'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Username'),
			'#default_value' => $config->get('username'),
		];

		$form['password'] = [
			'#type' => 'password',
			'#title' => $this->t('Password'),
			'#description' => $this->t('<strong>Must be reentered on every save!</strong>'),
			'#default_value' => $config->get('password'),
		];

		$form['base_url'] = [
			'#type' => 'textfield',
			'#title' => $this->t('URL of directory where SIPs are temporarily stored'),
			'#description' => $this->t('Please omit trailing slash, e.g. public://arclib'),
			'#default_value' => $config->get('base_url'),
		];

		$form['workflow'] = [
			'#type' => 'textarea',
			'#title' => $this->t('Workflow modification for SIP ingestion'),
			'#description' => '',
			'#default_value' => $config->get('workflow'),
		];

		$form['producer_profile_id'] = [
			'#type' => 'textfield',
			'#title' => $this->t('Producer profile external ID'),
			'#description' => '',
			'#default_value' => $config->get('producer_profile_id'),
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
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('arc_host', $form_state->getValue('arc_host'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('username', $form_state->getValue('username'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('password', $form_state->getValue('password'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('base_url', $form_state->getValue('base_url'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('workflow', $form_state->getValue('workflow'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('producer_profile_id', $form_state->getValue('producer_profile_id'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('transfer_field', $form_state->getValue('transfer_field'));
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('sip_field', $form_state->getValue('sip_field'))->save();

		parent::submitForm($form, $form_state);
	}

}
