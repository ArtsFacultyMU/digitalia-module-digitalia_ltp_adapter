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
	* @param mixed $queue_item
	*   The queue item queue_item.
	*
	* @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
	* @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
	* @throws \Drupal\Core\Entity\EntityStorageException
	* @throws \Exception
	*/
	public function processItem($queue_item)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("Processing item from queue");

		$utils = new DigitaliaLtpUtils();

		if (!$utils->checkAndLock($queue_item, 2, 120)) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("Couldn't obtain lock for directory '$directory', queue processing");
			return;
		}

		dpm(print_r($queue_item, TRUE));

		$entity = \Drupal::entityTypeManager()->getStorage($queue_item['entity_type'])->loadByProperties(['uuid' => $queue_item['uuid']]);
		$entity = reset($entity);

		dpm("entity id: " . $entity->id());
		dpm("entity uuid: " . $entity->uuid());

		$transfer_uuid = $utils->startIngestArchivematica($queue_item['directory']);

		if ($queue_item['field_transfer_name']) {
			$entity->set($queue_item['field_transfer_name'], "SAVE_" . $transfer_uuid);
			$entity->save();
		}

		// unlocking not necessary, source directory is deleted

		\Drupal::logger('digitalia_ltp_adapter')->debug("Item from queue processed");
	}
}
