<?php

namespace Drupal\digitalia_ltp_adapter\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\digitalia_ltp_adapter\DigitaliaLtpUtils;
use Drupal\digitalia_ltp_adapter\FileUtils;
use Drupal\digitalia_ltp_adapter\LtpSystemArchivematica;

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
		$fileutils = new FileUtils();
		$ltp_system = new LtpSystemArchivematica($queue_item["directory"]);
		$dirpath = $ltp_system->getBaseUrl() . "/" . $queue_item["directory"];

		if (!$fileutils->checkAndLock($dirpath, 2, 120)) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("Couldn't obtain lock for directory '" . $dirpath . "', aborting.");
			return;
		}

		try {
			$entity = \Drupal::entityTypeManager()->getStorage($queue_item['entity_type'])->loadByProperties(['uuid' => $queue_item['uuid']]);
			$entity = reset($entity);

			$uuids = $ltp_system->startIngest();

			if ($queue_item['field_transfer_name'] != "") {
				$entity->set($queue_item['field_transfer_name'], "SAVE_" . $uuids['transfer_uuid']);
				$entity->save();
			}

			if ($queue_item['field_sip_name'] != "") {
				$entity->set($queue_item['field_sip_name'], "SAVE_" . $uuids['sip_uuid']);
				$entity->save();
			}

		} catch (\Exception $e) {
			// unlocking only on failure, source directory is deleted otherwise
			\Drupal::logger('digitalia_ltp_adapter')->error($e->getMessage());
			$fileutils->removeLock($dirpath);
			return;
		}

		\Drupal::logger('digitalia_ltp_adapter')->debug("Item from queue processed");
	}
}
