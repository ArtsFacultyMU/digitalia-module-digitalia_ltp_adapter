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
			'#title' => 'Arclib host URL',
			'#description' => 'Please omit trailing slash, e.g. https://arclib.example:8080',
			'#default_value' => $config->get('arc_host'),
		];

		$form['username'] = [
			'#type' => 'textfield',
			'#title' => 'Arclib username',
			'#default_value' => $config->get('username'),
		];

		$form['password'] = [
			'#type' => 'password',
			'#title' => 'Arclib password',
			'#description' => '<strong>Must be reentered on every save!</strong>',
			'#default_value' => $config->get('password'),
		];

		$form['base_url'] = [
			'#type' => 'textfield',
			'#title' => 'URL of directory where SIPs are temporarily stored',
			'#description' => 'Please omit trailing slash, e.g. public://arclib',
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


		return parent::buildForm($form, $form_state);

	}


	/**
	 * {@inheritdoc}
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('arc_host', $form_state->getValue('arc_host'))->save();
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('username', $form_state->getValue('username'))->save();
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('password', $form_state->getValue('password'))->save();
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('base_url', $form_state->getValue('base_url'))->save();
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('transfer_field', $form_state->getValue('transfer_field'))->save();
		$this->config('digitalia_ltp_adapter_arclib.settings')->set('sip_field', $form_state->getValue('sip_field'))->save();

		parent::submitForm($form, $form_state);
	}

}
