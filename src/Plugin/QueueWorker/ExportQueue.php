<?php

namespace Drupal\digitalia_ltp_adapter\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\digitalia_ltp_adapter\DigitaliaLtpUtils;

/**
 * Export Queue Worker
 *
 * @QueueWorker(
 *   id = "digitalia_ltp_adapter_export_queue",
 *   title = @Translation("Export Queue"),
 *   cron = {"time" = 86400}
 *   )
 */
class ExportQueue extends QueueWorkerBase
{
	public function __construct(array $configuration, $plugin_id, $plugin_definition)
	{
		parent::__construct($configuration, $plugin_id, $plugin_definition);
	}

	/**
	* Processes an item in the queue.
	*
	* @param mixed $data
	*   The queue item data.
	*
	* @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
	* @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
	* @throws \Drupal\Core\Entity\EntityStorageException
	* @throws \Exception
	*/
	public function processItem($data)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("Processing item from queue");

		$utils = new DigitaliaLtpUtils();
		$utils->startIngest(array($data->directory));

		\Drupal::logger('digitalia_ltp_adapter')->debug("Item from queue processed");
	}

}
